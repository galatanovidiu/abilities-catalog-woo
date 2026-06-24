<?php
/**
 * Integration tests for the `wc-reports/get-orders-stats` ability.
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
 * @covers \GalatanOvidiu\AbilitiesCatalogWoo\Abilities\Woo\Analytics\GetOrdersStats
 */
final class GetOrdersStatsTest extends TestCase {

	private const ABILITY = 'wc-reports/get-orders-stats';

	/**
	 * The exact closed top-level key set the ability returns.
	 */
	private const TOP_LEVEL_KEYS = array( 'totals', 'intervals_count', 'period' );

	/**
	 * The exact closed key set of the shaped `totals` object, in emit order.
	 *
	 * `AnalyticsReportShaper::statsTotals()` copies the float KPIs first
	 * (`net_revenue, avg_order_value, avg_items_per_order, coupons`) then the int
	 * KPIs (`orders_count, num_items_sold, coupons_count, total_customers`), so the
	 * returned `totals` array is ordered floats-then-ints — not the order the output
	 * schema's `required` list documents. Asserting against this fixed, ordered set
	 * still proves the shape is closed (no `intervals`, no raw leak).
	 */
	private const TOTALS_KEYS = array(
		'net_revenue',
		'avg_order_value',
		'avg_items_per_order',
		'coupons',
		'orders_count',
		'num_items_sold',
		'coupons_count',
		'total_customers',
	);

	/**
	 * Skips the test cleanly when the Analytics feature is off.
	 *
	 * The ability is conditional on {@see WooPlugin::hasAnalytics()}; when the
	 * feature is off it does not register, so every behavioral assertion here is
	 * moot. Skipping keeps the suite (and RegistryTest) green either way.
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
	 * Seeds a completed order and syncs it into the analytics lookup tables.
	 *
	 * Analytics reads from the `wc_order_stats` lookup table, populated by an async
	 * Action Scheduler job — a freshly created order does not appear until synced.
	 * `OrdersScheduler::import()` is WooCommerce's synchronous sync entry point: it
	 * runs `OrdersStatsDataStore::sync_order()` (and the product/coupon/customer
	 * data stores) and invalidates the reports cache, so the order is visible to the
	 * stats report immediately. Uses WooCommerce's runtime object API (the
	 * distributed wp-env zip ships no `WC_Helper_*` factories).
	 *
	 * @return void
	 */
	private function seedSyncedCompletedOrder(): void {
		$product = new WC_Product_Simple();
		$product->set_name( 'Seeded Analytics Product' );
		$product->set_status( 'publish' );
		$product->set_regular_price( '10.00' );
		$product->save();

		$order = new WC_Order();
		$order->add_product( wc_get_product( $product->get_id() ), 2 );
		$order->set_status( 'completed' );
		$order->calculate_totals();
		$order->save();

		\Automattic\WooCommerce\Internal\Admin\Schedulers\OrdersScheduler::import( $order->get_id() );
	}

	public function test_registered(): void {
		$this->requireAnalytics();

		$ability = wp_get_ability( self::ABILITY );

		$this->assertNotNull( $ability );
		$this->assertSame( self::ABILITY, $ability->get_name() );
	}

	public function test_happy_path_returns_shaped_totals_without_intervals(): void {
		$this->requireAnalytics();
		$this->actingAs( 'administrator' );
		$this->seedSyncedCompletedOrder();

		$result = wp_get_ability( self::ABILITY )->execute( array() );

		$this->assertIsArray( $result );
		$this->assertNotInstanceOf( WP_Error::class, $result );

		// The raw, huge `intervals` array must never be returned.
		$this->assertArrayNotHasKey( 'intervals', $result );
		$this->assertSame( self::TOP_LEVEL_KEYS, array_keys( $result ) );

		// The shaped totals carry exactly the closed schema key set, cast to numbers.
		$this->assertSame( self::TOTALS_KEYS, array_keys( $result['totals'] ) );
		$this->assertIsFloat( $result['totals']['net_revenue'] );
		$this->assertIsInt( $result['totals']['orders_count'] );
		$this->assertIsFloat( $result['totals']['avg_order_value'] );
		$this->assertIsInt( $result['totals']['num_items_sold'] );

		// `intervals_count` is the bucket count, an integer.
		$this->assertIsInt( $result['intervals_count'] );

		// A synced completed order makes the order KPIs non-trivial.
		$this->assertGreaterThanOrEqual( 1, $result['totals']['orders_count'] );
		$this->assertGreaterThan( 0.0, $result['totals']['net_revenue'] );
	}

	public function test_period_envelope_echoes_the_request(): void {
		$this->requireAnalytics();
		$this->actingAs( 'administrator' );
		$this->seedSyncedCompletedOrder();

		$result = wp_get_ability( self::ABILITY )->execute(
			array(
				'after'    => '2020-01-01T00:00:00',
				'before'   => '2035-01-01T00:00:00',
				'interval' => 'month',
			)
		);

		$this->assertIsArray( $result );
		$this->assertNotInstanceOf( WP_Error::class, $result );
		$this->assertSame( self::TOP_LEVEL_KEYS, array_keys( $result ) );
		$this->assertSame( '2020-01-01T00:00:00', $result['period']['after'] );
		$this->assertSame( '2035-01-01T00:00:00', $result['period']['before'] );
		$this->assertSame( 'month', $result['period']['interval'] );

		// A monthly interval over a multi-year range yields many buckets.
		$this->assertGreaterThan( 1, $result['intervals_count'] );
	}

	public function test_range_filter_is_accepted(): void {
		$this->requireAnalytics();
		$this->actingAs( 'administrator' );
		$this->seedSyncedCompletedOrder();

		$result = wp_get_ability( self::ABILITY )->execute(
			array(
				'after'  => '2020-01-01T00:00:00',
				'before' => '2035-01-01T00:00:00',
			)
		);

		$this->assertIsArray( $result );
		$this->assertNotInstanceOf( WP_Error::class, $result );
		$this->assertSame( self::TOTALS_KEYS, array_keys( $result['totals'] ) );
	}

	public function test_output_shape_is_exactly_the_closed_keys(): void {
		$this->requireAnalytics();
		$this->actingAs( 'administrator' );
		$this->seedSyncedCompletedOrder();

		$result = wp_get_ability( self::ABILITY )->execute( array() );

		$this->assertSame( self::TOP_LEVEL_KEYS, array_keys( $result ) );
		$this->assertSame( self::TOTALS_KEYS, array_keys( $result['totals'] ) );
		$this->assertSame( array( 'after', 'before', 'interval' ), array_keys( $result['period'] ) );
		$this->assertArrayNotHasKey( 'intervals', $result );
		$this->assertArrayNotHasKey( 'products', $result['totals'] );
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
