<?php
/**
 * Integration tests for the og-wc-reports/get-variations-stats ability.
 *
 * @package AbilitiesCatalogWoo\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogWoo\Tests\Integration\Abilities\Analytics;

use Automattic\WooCommerce\Internal\Admin\Schedulers\OrdersScheduler;
use GalatanOvidiu\AbilitiesCatalogWoo\Support\WooPlugin;
use GalatanOvidiu\AbilitiesCatalogWoo\Tests\TestCase;
use WC_Order;
use WC_Product_Attribute;
use WC_Product_Variable;
use WC_Product_Variation;
use WP_Error;

/**
 * Exercises og-wc-reports/get-variations-stats: the shaped variation-sales totals over
 * a date range, the intentional omission of the raw `intervals` array (only
 * `intervals_count` is reported), an interval filter echoed in `period`, the
 * wrong-capability denial, and the exact closed output shape.
 *
 * Every data-dependent test is guarded on WooPlugin::hasAnalytics() — when the
 * store's Analytics feature is off the ability does not register, so the test skips
 * cleanly and RegistryTest stays green. The `wc-analytics` routes are registered by
 * a one-shot lazy-load filter that the harness's per-test REST-server reset wipes,
 * so each test re-arms them via the base TestCase's ensureAnalyticsRoutesRegistered().
 *
 * @covers \GalatanOvidiu\AbilitiesCatalogWoo\Abilities\Woo\Analytics\GetVariationsStats
 */
final class GetVariationsStatsTest extends TestCase {

	private const ABILITY = 'og-wc-reports/get-variations-stats';

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
	 * Skips the whole test when WooCommerce Analytics is not available, then re-arms
	 * the lazy `wc-analytics` route registration the per-test REST-server reset wiped.
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

		$ability = wp_get_ability( self::ABILITY );

		$this->assertNotNull( $ability );
		$this->assertSame( self::ABILITY, $ability->get_name() );
	}

	public function test_admin_reads_shaped_totals(): void {
		$this->requireAnalytics();
		$this->actingAs( 'administrator' );

		$this->seedCompletedVariationOrder();

		$result = wp_get_ability( self::ABILITY )->execute( array() );

		$this->assertIsArray( $result );
		$this->assertSame( self::ENVELOPE_KEYS, array_keys( $result ) );

		// Totals are shaped to exactly the three variation KPIs, correctly typed.
		$this->assertSame( self::TOTALS_KEYS, array_keys( $result['totals'] ) );
		$this->assertIsFloat( $result['totals']['net_revenue'] );
		$this->assertIsInt( $result['totals']['items_sold'] );
		$this->assertIsInt( $result['totals']['orders_count'] );

		// intervals_count is reported; the huge raw intervals array is NOT.
		$this->assertIsInt( $result['intervals_count'] );
		$this->assertArrayNotHasKey( 'intervals', $result );
		$this->assertArrayNotHasKey( 'intervals', $result['totals'] );

		// The period envelope echoes the request back.
		$this->assertSame( array( 'after', 'before', 'interval' ), array_keys( $result['period'] ) );
		$this->assertSame( 'week', $result['period']['interval'] );
	}

	public function test_interval_filter_is_echoed(): void {
		$this->requireAnalytics();
		$this->actingAs( 'administrator' );

		$this->seedCompletedVariationOrder();

		$result = wp_get_ability( self::ABILITY )->execute(
			array(
				'after'    => gmdate( 'Y-m-d\TH:i:s', strtotime( '-1 month' ) ),
				'before'   => gmdate( 'Y-m-d\TH:i:s', strtotime( '+1 day' ) ),
				'interval' => 'month',
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'month', $result['period']['interval'] );
		$this->assertNotSame( '', $result['period']['after'] );
		$this->assertNotSame( '', $result['period']['before'] );
		$this->assertArrayNotHasKey( 'intervals', $result );
	}

	public function test_output_shape_is_exact_and_closed(): void {
		$this->requireAnalytics();
		$this->actingAs( 'administrator' );

		$this->seedCompletedVariationOrder();

		$result = wp_get_ability( self::ABILITY )->execute( array() );

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
	}

	public function test_subscriber_is_denied(): void {
		$this->requireAnalytics();
		$this->actingAs( 'subscriber' );

		$ability = wp_get_ability( self::ABILITY );

		// subscriber lacks view_woocommerce_reports.
		$this->assertFalse( $ability->check_permissions( array() ) );

		$result = $ability->execute( array() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	/**
	 * Seeds a completed order containing one product variation and syncs the analytics
	 * lookup tables synchronously, so the variation appears in the variations report.
	 *
	 * Analytics reads from the `wc_order_stats` / `wc_order_product_lookup` tables,
	 * populated by an async Action Scheduler job that does not run in the test request.
	 * `OrdersScheduler::import()` forces that sync inline. Built with the WooCommerce
	 * runtime object API (no `WC_Helper_*` factory exists in the distributed build);
	 * the happy path asserts envelope STRUCTURE/TYPE only — counts may be 0 on a fresh
	 * store, so magnitude is never asserted.
	 *
	 * @return int The created order ID.
	 */
	private function seedCompletedVariationOrder(): int {
		$attribute = new WC_Product_Attribute();
		$attribute->set_name( 'size' );
		$attribute->set_options( array( 'small', 'large' ) );
		$attribute->set_visible( true );
		$attribute->set_variation( true );

		$parent = new WC_Product_Variable();
		$parent->set_name( 'Seeded Variable Product' );
		$parent->set_status( 'publish' );
		$parent->set_attributes( array( $attribute ) );
		$parent->save();

		$parent_id = (int) $parent->get_id();

		$variation = new WC_Product_Variation();
		$variation->set_parent_id( $parent_id );
		$variation->set_attributes( array( 'size' => 'small' ) );
		$variation->set_regular_price( '10.00' );
		$variation->save();

		$variation_id = (int) $variation->get_id();

		// Re-load so get_children() is populated before ordering the variation.
		$parent = new WC_Product_Variable( $parent_id );
		$parent->save();

		$order = new WC_Order();
		$order->add_product( wc_get_product( $variation_id ), 2 );
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

		if ( class_exists( OrdersScheduler::class ) && method_exists( OrdersScheduler::class, 'import' ) ) {
			OrdersScheduler::import( $order_id );
		}

		return $order_id;
	}
}
