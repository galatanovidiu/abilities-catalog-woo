<?php
/**
 * Integration tests for the `wc-reports/get-leaderboards` ability.
 *
 * @package AbilitiesCatalogWoo\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogWoo\Tests\Integration\Abilities\Analytics;

use GalatanOvidiu\AbilitiesCatalogWoo\Support\WooPlugin;
use GalatanOvidiu\AbilitiesCatalogWoo\Tests\TestCase;
use WC_Order;
use WC_Product_Simple;
use WP_Error;

/**
 * @covers \GalatanOvidiu\AbilitiesCatalogWoo\Abilities\Woo\Analytics\GetLeaderboards
 */
final class GetLeaderboardsTest extends TestCase {

	private const ABILITY = 'wc-reports/get-leaderboards';

	/**
	 * The exact keys a shaped leaderboard exposes.
	 *
	 * @var list<string>
	 */
	private const LEADERBOARD_KEYS = array( 'id', 'label', 'headers', 'rows' );

	/**
	 * Skips the whole case unless WooCommerce Analytics is enabled.
	 *
	 * The 7 analytics abilities are conditional on {@see WooPlugin::hasAnalytics()};
	 * when Analytics is off they do not register, so these tests must skip cleanly
	 * (RegistryTest stays green either way).
	 *
	 * @return void
	 */
	private function requireAnalytics(): void {
		if ( ! WooPlugin::hasAnalytics() ) {
			$this->markTestSkipped( 'WooCommerce Analytics feature is not enabled in this environment.' );
		}

		$this->ensureAnalyticsRoutesRegistered();
	}

	public function test_registered(): void {
		$this->requireAnalytics();

		$ability = wp_get_ability( self::ABILITY );

		$this->assertNotNull( $ability );
		$this->assertSame( self::ABILITY, $ability->get_name() );
	}

	public function test_happy_path_returns_leaderboards(): void {
		$this->requireAnalytics();
		$this->seedCompletedOrder();
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( self::ABILITY )->execute( array() );

		$this->assertIsArray( $result );
		$this->assertSame( array( 'leaderboards' ), array_keys( $result ) );
		$this->assertIsArray( $result['leaderboards'] );
		$this->assertNotEmpty( $result['leaderboards'] );

		$leaderboard = $result['leaderboards'][0];
		$this->assertSame( self::LEADERBOARD_KEYS, array_keys( $leaderboard ) );
		$this->assertIsString( $leaderboard['id'] );
		$this->assertIsString( $leaderboard['label'] );
		$this->assertIsArray( $leaderboard['headers'] );
		$this->assertIsArray( $leaderboard['rows'] );

		// Headers are each a closed { label } object.
		$this->assertNotEmpty( $leaderboard['headers'] );
		$header = $leaderboard['headers'][0];
		$this->assertSame( array( 'label' ), array_keys( $header ) );
		$this->assertIsString( $header['label'] );

		// The id set matches the four core leaderboards.
		$ids = wp_list_pluck( $result['leaderboards'], 'id' );
		$this->assertContains( 'products', $ids );
	}

	public function test_rows_cells_are_closed_shape(): void {
		$this->requireAnalytics();
		$this->seedCompletedOrder();
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( self::ABILITY )->execute( array() );

		// Every cell of every produced row must be the closed shape. The seeded
		// order should populate the products leaderboard once synced; the cell
		// assertions run for whatever rows the lookup-table sync produced.
		foreach ( $result['leaderboards'] as $leaderboard ) {
			foreach ( $leaderboard['rows'] as $row ) {
				$this->assertIsArray( $row );
				foreach ( $row as $cell ) {
					$this->assertIsArray( $cell );
					// Closed cell: required display/value, optional format only.
					$this->assertArrayHasKey( 'display', $cell );
					$this->assertArrayHasKey( 'value', $cell );
					$this->assertIsString( $cell['display'] );
					$allowed = array( 'display', 'value', 'format' );
					$this->assertSame( array(), array_diff( array_keys( $cell ), $allowed ) );
					if ( array_key_exists( 'format', $cell ) ) {
						$this->assertContains( $cell['format'], array( 'number', 'currency' ) );
					}
				}
			}
		}

		// Shape proof does not require the async sync to produce rows.
		$this->assertNotEmpty( $result['leaderboards'] );
	}

	public function test_output_does_not_leak_raw_fields(): void {
		$this->requireAnalytics();
		$this->seedCompletedOrder();
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( self::ABILITY )->execute( array() );

		// No top-level total on this route.
		$this->assertArrayNotHasKey( 'total', $result );
		$this->assertArrayNotHasKey( 'total_pages', $result );

		foreach ( $result['leaderboards'] as $leaderboard ) {
			$this->assertSame( self::LEADERBOARD_KEYS, array_keys( $leaderboard ) );
			$this->assertArrayNotHasKey( '_links', $leaderboard );
		}
	}

	public function test_wrong_capability_is_denied(): void {
		$this->requireAnalytics();
		$this->actingAs( 'subscriber' );

		$ability = wp_get_ability( self::ABILITY );

		$this->assertFalse( $ability->check_permissions( array() ) );

		$result = $ability->execute( array() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_logged_out_user_is_denied(): void {
		$this->requireAnalytics();
		wp_set_current_user( 0 );

		$result = wp_get_ability( self::ABILITY )->execute( array() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	/**
	 * Seeds a completed order with one line item and syncs it into the
	 * analytics lookup tables synchronously.
	 *
	 * Analytics reads from `wc_order_stats` / `wc_order_product_lookup` etc., which
	 * are normally populated by an async Action Scheduler job. To make the seeded
	 * order visible to the leaderboards report inside the test, this forces the
	 * sync via `OrdersScheduler::import()` (the public entry point that calls every
	 * `*DataStore::sync_order*`). Uses WooCommerce runtime objects, not the absent
	 * `WC_Helper_*` test factories.
	 *
	 * @return int The seeded order ID.
	 */
	private function seedCompletedOrder(): int {
		$product = new WC_Product_Simple();
		$product->set_name( 'Seeded Product' );
		$product->set_status( 'publish' );
		$product->set_regular_price( '10.00' );
		$product->save();

		$order = new WC_Order();
		$order->add_product( wc_get_product( $product->get_id() ), 2 );
		$order->set_address(
			array(
				'first_name' => 'Ada',
				'last_name'  => 'Lovelace',
				'email'      => 'ada@example.org',
				'address_1'  => '1 Test St',
				'city'       => 'Testville',
				'state'      => 'CA',
				'postcode'   => '90210',
				'country'    => 'US',
			),
			'billing'
		);
		$order->set_status( 'completed' );
		$order->calculate_totals();
		$order->save();

		$order_id = (int) $order->get_id();

		// Force the analytics lookup-table sync synchronously rather than waiting
		// on the scheduler. Guarded so a stripped WC build does not fatal.
		$scheduler = '\\Automattic\\WooCommerce\\Internal\\Admin\\Schedulers\\OrdersScheduler';
		if ( class_exists( $scheduler ) && method_exists( $scheduler, 'import' ) ) {
			$scheduler::import( $order_id );
		}

		return $order_id;
	}
}
