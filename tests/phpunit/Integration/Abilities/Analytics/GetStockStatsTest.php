<?php
/**
 * Integration tests for the `og-wc-reports/get-stock-stats` ability.
 *
 * @package AbilitiesCatalogWoo\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogWoo\Tests\Integration\Abilities\Analytics;

use GalatanOvidiu\AbilitiesCatalogWoo\Support\WooPlugin;
use GalatanOvidiu\AbilitiesCatalogWoo\Tests\TestCase;
use WC_Product_Simple;
use WP_Error;

/**
 * @covers \GalatanOvidiu\AbilitiesCatalogWoo\Abilities\Woo\Analytics\GetStockStats
 */
final class GetStockStatsTest extends TestCase {

	private const ABILITY = 'og-wc-reports/get-stock-stats';

	/**
	 * The exact closed key set of the shaped `totals` object.
	 *
	 * Asserting against this fixed set proves the raw stock/stats body never
	 * leaks: only these five count fields reach the consumer.
	 *
	 * @var list<string>
	 */
	private const TOTALS_KEYS = array(
		'products',
		'lowstock',
		'instock',
		'outofstock',
		'onbackorder',
	);

	/**
	 * Skips the test cleanly when the Analytics feature is off, then ensures the
	 * lazy `wc-analytics` routes are registered for this request.
	 *
	 * The ability is conditional on {@see WooPlugin::hasAnalytics()}; when the
	 * feature is off it does not register, so every behavioral assertion is moot.
	 * The `wc-analytics` namespace is a lazy one-shot that the test harness's
	 * per-test REST-server reset wipes, so {@see TestCase::ensureAnalyticsRoutesRegistered()}
	 * must run before any dispatch or the 2nd+ test would 404 with `rest_no_route`.
	 *
	 * @return void
	 */
	private function requireAnalytics(): void {
		if ( ! WooPlugin::hasAnalytics() ) {
			$this->markTestSkipped( 'WooCommerce Analytics feature is not enabled.' );
		}

		$this->ensureAnalyticsRoutesRegistered();
	}

	/**
	 * Seeds a published simple product with managed stock.
	 *
	 * The stock/stats report counts products by stock status from the product
	 * tables (no order lookup-table sync is needed). Uses WooCommerce's runtime
	 * object API (the distributed wp-env zip ships no `WC_Helper_*` factories).
	 *
	 * @param int $quantity The managed stock quantity to set.
	 * @return void
	 */
	private function seedManagedStockProduct( int $quantity ): void {
		$product = new WC_Product_Simple();
		$product->set_name( 'Seeded Stock Product ' . $quantity );
		$product->set_status( 'publish' );
		$product->set_regular_price( '10.00' );
		$product->set_manage_stock( true );
		$product->set_stock_quantity( $quantity );
		$product->save();
	}

	public function test_registered(): void {
		$this->requireAnalytics();

		$ability = wp_get_ability( self::ABILITY );

		$this->assertNotNull( $ability );
		$this->assertSame( self::ABILITY, $ability->get_name() );
	}

	public function test_happy_path_returns_totals_only_no_intervals(): void {
		$this->requireAnalytics();
		$this->actingAs( 'administrator' );
		$this->seedManagedStockProduct( 5 );

		$result = wp_get_ability( self::ABILITY )->execute( array() );

		$this->assertIsArray( $result );
		$this->assertNotInstanceOf( WP_Error::class, $result );

		// Top level is `totals` only — the no-intervals exception.
		$this->assertSame( array( 'totals' ), array_keys( $result ) );
		$this->assertArrayNotHasKey( 'intervals', $result );
		$this->assertArrayNotHasKey( 'intervals_count', $result );
		$this->assertArrayNotHasKey( 'period', $result );

		// The shaped totals carry exactly the five closed count keys, cast to int.
		$this->assertIsArray( $result['totals'] );
		$this->assertSame( self::TOTALS_KEYS, array_keys( $result['totals'] ) );
		foreach ( self::TOTALS_KEYS as $key ) {
			$this->assertIsInt( $result['totals'][ $key ] );
			$this->assertGreaterThanOrEqual( 0, $result['totals'][ $key ] );
		}
	}

	public function test_output_shape_is_exactly_the_closed_keys(): void {
		$this->requireAnalytics();
		$this->actingAs( 'administrator' );
		$this->seedManagedStockProduct( 3 );

		$result = wp_get_ability( self::ABILITY )->execute( array() );

		$this->assertSame( array( 'totals' ), array_keys( $result ) );
		$this->assertSame( self::TOTALS_KEYS, array_keys( $result['totals'] ) );
		$this->assertArrayNotHasKey( 'intervals', $result );
		$this->assertArrayNotHasKey( 'intervals_count', $result );
		$this->assertArrayNotHasKey( 'period', $result );
		$this->assertArrayNotHasKey( '_links', $result['totals'] );
	}

	public function test_wrong_capability_is_denied(): void {
		$this->requireAnalytics();
		$this->actingAs( 'subscriber' );

		$ability = wp_get_ability( self::ABILITY );

		// A subscriber lacks `view_woocommerce_reports`.
		$this->assertFalse( $ability->check_permissions( array() ) );

		$result = $ability->execute( array() );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}
}
