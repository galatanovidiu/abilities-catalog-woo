<?php
/**
 * Integration tests for the og-wc-reports/get-products-stats ability.
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
 * Exercises og-wc-reports/get-products-stats: the shaped totals over a date range, the
 * intentional omission of the raw `intervals` array (only `intervals_count` is
 * reported), a date-range filter, the wrong-capability denial, and the exact closed
 * output shape.
 *
 * Every data-dependent test is guarded on WooPlugin::hasAnalytics() — when the
 * store's Analytics feature is off the ability does not register, so the test skips
 * cleanly and RegistryTest stays green.
 */
final class GetProductsStatsTest extends TestCase {

	/**
	 * The closed key set the ability's `totals` object returns, in emit order.
	 *
	 * `AnalyticsReportShaper::statsTotals()` copies the float KPI (`net_revenue`)
	 * before the int KPIs (`items_sold`, `orders_count`), so the returned `totals`
	 * array is ordered float-then-ints — not the order the output schema's `required`
	 * list documents. `test_admin_reads_shaped_totals` asserts this exact order;
	 * `test_output_shape_is_exact_and_closed` sorts both sides, so it is order-agnostic.
	 *
	 * @var list<string>
	 */
	private const TOTALS_KEYS = array(
		'net_revenue',
		'items_sold',
		'orders_count',
	);

	/**
	 * The closed key set the ability's top-level envelope returns.
	 *
	 * @var list<string>
	 */
	private const ENVELOPE_KEYS = array(
		'totals',
		'intervals_count',
		'period',
	);

	/**
	 * Skips the whole test when WooCommerce Analytics is not available.
	 *
	 * @return void
	 */
	private function requireAnalytics(): void {
		if ( ! WooPlugin::hasAnalytics() ) {
			$this->markTestSkipped( 'WooCommerce Analytics feature is not enabled.' );
		}

		$this->ensureAnalyticsRoutesRegistered();
	}

	public function test_ability_is_registered(): void {
		$this->requireAnalytics();

		$ability = wp_get_ability( 'og-wc-reports/get-products-stats' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'og-wc-reports/get-products-stats', $ability->get_name() );
	}

	public function test_admin_reads_shaped_totals(): void {
		$this->requireAnalytics();
		$this->actingAs( 'administrator' );

		$this->seedCompletedOrder();

		$result = wp_get_ability( 'og-wc-reports/get-products-stats' )->execute( array() );

		$this->assertIsArray( $result );
		$this->assertSame( self::ENVELOPE_KEYS, array_keys( $result ) );

		// Totals are shaped to exactly the three product KPIs, correctly typed.
		$this->assertSame( self::TOTALS_KEYS, array_keys( $result['totals'] ) );
		$this->assertIsInt( $result['totals']['items_sold'] );
		$this->assertIsFloat( $result['totals']['net_revenue'] );
		$this->assertIsInt( $result['totals']['orders_count'] );

		// intervals_count is reported; the huge raw intervals array is NOT.
		$this->assertIsInt( $result['intervals_count'] );
		$this->assertArrayNotHasKey( 'intervals', $result );
		$this->assertArrayNotHasKey( 'intervals', $result['totals'] );

		// The period envelope echoes the request back.
		$this->assertSame( array( 'after', 'before', 'interval' ), array_keys( $result['period'] ) );
		$this->assertSame( 'week', $result['period']['interval'] );
	}

	public function test_output_shape_is_exact_and_closed(): void {
		$this->requireAnalytics();
		$this->actingAs( 'administrator' );

		$this->seedCompletedOrder();

		$result = wp_get_ability( 'og-wc-reports/get-products-stats' )->execute( array() );

		$this->assertIsArray( $result );

		$keys = array_keys( $result );
		sort( $keys );
		$expected = self::ENVELOPE_KEYS;
		sort( $expected );
		$this->assertSame( $expected, $keys );

		$totals_keys = array_keys( $result['totals'] );
		sort( $totals_keys );
		$expected_totals = self::TOTALS_KEYS;
		sort( $expected_totals );
		$this->assertSame( $expected_totals, $totals_keys );

		// No raw stats payload leaks through.
		$this->assertArrayNotHasKey( 'intervals', $result );
		$this->assertArrayNotHasKey( 'segments', $result['totals'] );
		$this->assertArrayNotHasKey( 'products', $result['totals'] );
	}

	public function test_interval_filter_is_echoed(): void {
		$this->requireAnalytics();
		$this->actingAs( 'administrator' );

		$this->seedCompletedOrder();

		$result = wp_get_ability( 'og-wc-reports/get-products-stats' )->execute(
			array(
				'after'    => gmdate( 'Y-m-d\TH:i:s', strtotime( '-1 day' ) ),
				'before'   => gmdate( 'Y-m-d\TH:i:s', strtotime( '+1 day' ) ),
				'interval' => 'day',
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'day', $result['period']['interval'] );
		$this->assertNotSame( '', $result['period']['after'] );
		$this->assertNotSame( '', $result['period']['before'] );
		$this->assertArrayNotHasKey( 'intervals', $result );
	}

	public function test_subscriber_is_denied(): void {
		$this->requireAnalytics();
		$this->actingAs( 'subscriber' );

		$ability = wp_get_ability( 'og-wc-reports/get-products-stats' );

		$this->assertFalse( $ability->check_permissions( array() ) );

		$result = $ability->execute( array() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	/**
	 * Seeds a completed order with one line item and syncs the analytics lookup
	 * tables synchronously, so the order appears in the products report immediately.
	 *
	 * Analytics reads from the `wc_order_stats` / `wc_order_product_lookup` tables,
	 * which are populated by an async Action Scheduler job. `OrdersScheduler::import()`
	 * is the entry point that job ultimately runs (it calls the per-report
	 * `DataStore::sync_order` / `sync_order_products`); calling it here forces the
	 * sync inline rather than waiting on the scheduler.
	 *
	 * @return int The created order ID.
	 */
	private function seedCompletedOrder(): int {
		$product = new WC_Product_Simple();
		$product->set_name( 'Seeded Product' );
		$product->set_status( 'publish' );
		$product->set_regular_price( '10.00' );
		$product->save();

		$order = new WC_Order();
		$order->add_product( wc_get_product( $product->get_id() ), 2 );
		$order->set_status( 'completed' );
		$order->calculate_totals();
		$order->save();

		$order_id = (int) $order->get_id();

		if ( class_exists( OrdersScheduler::class ) ) {
			OrdersScheduler::import( $order_id );
		}

		return $order_id;
	}
}
