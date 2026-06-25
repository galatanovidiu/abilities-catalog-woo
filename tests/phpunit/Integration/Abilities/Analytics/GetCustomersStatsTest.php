<?php
/**
 * Integration tests for the `og-wc-reports/get-customers-stats` ability.
 *
 * @package AbilitiesCatalogWoo\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogWoo\Tests\Integration\Abilities\Analytics;

use Automattic\WooCommerce\Internal\Admin\Schedulers\OrdersScheduler;
use GalatanOvidiu\AbilitiesCatalogWoo\Support\WooPlugin;
use GalatanOvidiu\AbilitiesCatalogWoo\Tests\TestCase;
use WC_Order;
use WC_Product_Simple;
use WP_Error;

/**
 * @covers \GalatanOvidiu\AbilitiesCatalogWoo\Abilities\Woo\Analytics\GetCustomersStats
 */
final class GetCustomersStatsTest extends TestCase {

	private const ABILITY = 'og-wc-reports/get-customers-stats';

	/**
	 * The exact closed key set of the shaped `totals` object.
	 *
	 * Asserting against this fixed set proves the raw customers/stats body never
	 * leaks: only these four aggregate KPI fields reach the consumer.
	 *
	 * @var list<string>
	 */
	private const TOTALS_KEYS = array(
		'customers_count',
		'avg_orders_count',
		'avg_total_spend',
		'avg_avg_order_value',
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
	 * Seeds a customer with a completed order and syncs both into the analytics
	 * lookup tables synchronously.
	 *
	 * The customers/stats report reads the `wc_customer_lookup` and `wc_order_stats`
	 * lookup tables, populated by async Action Scheduler jobs that do not run in the
	 * test request. {@see OrdersScheduler::import()} syncs the order and its
	 * customer synchronously, so the customer aggregate sees the data. Uses
	 * WooCommerce's runtime object API (the distributed wp-env zip ships no
	 * `WC_Helper_*` factories), with a unique email per seed.
	 *
	 * @param string $email A unique billing email for the seeded customer.
	 * @return void
	 */
	private function seedSyncedCustomerOrder( string $email ): void {
		$product = new WC_Product_Simple();
		$product->set_name( 'Seeded Customer Product ' . $email );
		$product->set_status( 'publish' );
		$product->set_regular_price( '10.00' );
		$product->save();

		$order = new WC_Order();
		$order->add_product( wc_get_product( $product->get_id() ), 2 );
		$order->set_address(
			array(
				'first_name' => 'Ada',
				'last_name'  => 'Lovelace',
				'email'      => $email,
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

		if ( class_exists( OrdersScheduler::class ) && method_exists( OrdersScheduler::class, 'import' ) ) {
			OrdersScheduler::import( (int) $order->get_id() );
		}
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
		$this->seedSyncedCustomerOrder( 'ada@example.org' );
		$this->seedSyncedCustomerOrder( 'grace@example.org' );

		$result = wp_get_ability( self::ABILITY )->execute( array() );

		$this->assertIsArray( $result );
		$this->assertNotInstanceOf( WP_Error::class, $result );

		// Top level is `totals` only — the no-intervals exception.
		$this->assertSame( array( 'totals' ), array_keys( $result ) );
		$this->assertArrayNotHasKey( 'intervals', $result );
		$this->assertArrayNotHasKey( 'intervals_count', $result );
		$this->assertArrayNotHasKey( 'period', $result );

		// The shaped totals carry exactly the closed schema key set, cast to numbers.
		$this->assertIsArray( $result['totals'] );
		$this->assertSame( self::TOTALS_KEYS, array_keys( $result['totals'] ) );
		$this->assertIsInt( $result['totals']['customers_count'] );
		$this->assertIsFloat( $result['totals']['avg_orders_count'] );
		$this->assertIsFloat( $result['totals']['avg_total_spend'] );
		$this->assertIsFloat( $result['totals']['avg_avg_order_value'] );
	}

	public function test_date_filter_is_accepted(): void {
		$this->requireAnalytics();
		$this->actingAs( 'administrator' );
		$this->seedSyncedCustomerOrder( 'lovelace@example.org' );

		$result = wp_get_ability( self::ABILITY )->execute(
			array(
				'registered_after'  => '2020-01-01T00:00:00',
				'registered_before' => '2035-01-01T00:00:00',
				'last_order_after'  => '2020-01-01T00:00:00',
				'last_order_before' => '2035-01-01T00:00:00',
			)
		);

		$this->assertIsArray( $result );
		$this->assertNotInstanceOf( WP_Error::class, $result );
		$this->assertSame( array( 'totals' ), array_keys( $result ) );
		$this->assertSame( self::TOTALS_KEYS, array_keys( $result['totals'] ) );
	}

	public function test_output_shape_is_exactly_the_closed_keys(): void {
		$this->requireAnalytics();
		$this->actingAs( 'administrator' );
		$this->seedSyncedCustomerOrder( 'shape@example.org' );

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
