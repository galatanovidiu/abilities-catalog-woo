<?php
/**
 * Integration tests for the `wc-reports/get-revenue-stats` ability.
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
 * @covers \GalatanOvidiu\AbilitiesCatalogWoo\Abilities\Woo\Analytics\GetRevenueStats
 */
final class GetRevenueStatsTest extends TestCase {

	private const ABILITY = 'wc-reports/get-revenue-stats';

	/**
	 * The exact keys the shaped revenue `totals` object exposes.
	 *
	 * Asserting against this fixed set proves the raw stats body — and the huge
	 * per-interval `intervals` array — never leak: only these KPI fields reach the
	 * consumer.
	 *
	 * @var list<string>
	 */
	private const TOTALS_KEYS = array(
		'total_sales',
		'net_revenue',
		'gross_sales',
		'coupons',
		'shipping',
		'taxes',
		'refunds',
		'coupons_count',
		'orders_count',
		'num_items_sold',
	);

	/**
	 * Skips the whole case when WooCommerce Analytics is off.
	 *
	 * The ability is conditional on the lazy, feature-gated `wc-analytics`
	 * namespace; when Analytics is disabled it does not register, so a test that
	 * exercised it would fail on a null ability. Skipping keeps the suite (and
	 * RegistryTest) green either way.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		if ( ! WooPlugin::hasAnalytics() ) {
			$this->markTestSkipped( 'WooCommerce Analytics feature is not enabled.' );
		}

		$this->ensureAnalyticsRoutesRegistered();
	}

	public function test_registered(): void {
		$ability = wp_get_ability( self::ABILITY );

		$this->assertNotNull( $ability );
		$this->assertSame( self::ABILITY, $ability->get_name() );
	}

	public function test_happy_path_returns_shaped_totals_without_intervals(): void {
		$this->seedCompletedOrder();
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( self::ABILITY )->execute( array() );

		$this->assertIsArray( $result );
		$this->assertSame( array( 'totals', 'intervals_count', 'period' ), array_keys( $result ) );

		// The raw `intervals` array must never be passed through.
		$this->assertArrayNotHasKey( 'intervals', $result );

		$this->assertIsInt( $result['intervals_count'] );

		$this->assertIsArray( $result['totals'] );
		$this->assertSame( self::TOTALS_KEYS, array_keys( $result['totals'] ) );
		$this->assertIsFloat( $result['totals']['total_sales'] );
		$this->assertIsFloat( $result['totals']['net_revenue'] );
		$this->assertIsInt( $result['totals']['orders_count'] );
		$this->assertIsInt( $result['totals']['num_items_sold'] );

		$this->assertIsArray( $result['period'] );
		$this->assertSame( array( 'after', 'before', 'interval' ), array_keys( $result['period'] ) );
		$this->assertSame( 'week', $result['period']['interval'] );
	}

	public function test_range_filter_is_echoed_back_in_period(): void {
		$this->seedCompletedOrder();
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( self::ABILITY )->execute(
			array(
				'after'    => '2020-01-01T00:00:00',
				'before'   => '2020-01-31T23:59:59',
				'interval' => 'day',
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( '2020-01-01T00:00:00', $result['period']['after'] );
		$this->assertSame( '2020-01-31T23:59:59', $result['period']['before'] );
		$this->assertSame( 'day', $result['period']['interval'] );
		$this->assertArrayNotHasKey( 'intervals', $result );
	}

	public function test_wrong_capability_is_denied(): void {
		$this->actingAs( 'subscriber' );

		$ability = wp_get_ability( self::ABILITY );

		// subscriber lacks view_woocommerce_reports.
		$this->assertFalse( $ability->check_permissions( array() ) );

		$result = $ability->execute( array() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	/**
	 * Seeds a completed order with one line item and syncs it into the analytics
	 * lookup tables synchronously.
	 *
	 * Analytics reports read from the `wc_order_stats` lookup table, populated by an
	 * async Action Scheduler job that does not run in the test request. Calling
	 * {@see OrdersScheduler::import()} synchronously syncs the order (and its
	 * products/coupons/taxes/customer) into every analytics lookup table, so the
	 * revenue report sees the data immediately. Uses the WooCommerce runtime object
	 * API, not the WC_Helper_* factories (absent from the distributed build).
	 *
	 * @return int The new order ID.
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

		OrdersScheduler::import( $order->get_id() );

		return $order->get_id();
	}
}
