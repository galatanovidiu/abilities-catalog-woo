<?php
/**
 * Integration tests for the `og-wc-reports/get-performance-indicators` ability.
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
 * @covers \GalatanOvidiu\AbilitiesCatalogWoo\Abilities\Woo\Analytics\GetPerformanceIndicators
 */
final class GetPerformanceIndicatorsTest extends TestCase {

	/**
	 * The closed indicator-row key set the ability projects.
	 *
	 * @var array<int,string>
	 */
	private const INDICATOR_KEYS = array( 'stat', 'label', 'value', 'format' );

	/**
	 * An explicit `stats` list, used to exercise the filtering path.
	 *
	 * Omitting `stats` makes the ability resolve and forward the full allowed set (see
	 * {@see \GalatanOvidiu\AbilitiesCatalogWoo\Abilities\Woo\Analytics\GetPerformanceIndicators::allowedStats()}),
	 * so the no-stats happy path returns all indicators. This fixture is the explicit
	 * subset {@see self::test_stats_filter_narrows_results()} passes to prove a caller
	 * list narrows the response.
	 *
	 * @var array<int,string>
	 */
	private const STATS = array(
		'revenue/total_sales',
		'revenue/net_revenue',
		'orders/orders_count',
		'orders/avg_order_value',
		'products/items_sold',
	);

	/**
	 * Skips the whole case when the Analytics feature is off.
	 *
	 * The 7 analytics abilities are conditional on `WooPlugin::hasAnalytics()`; when
	 * Analytics is disabled they do not register, so every assertion here would be
	 * meaningless. Skipping keeps the suite green on a build without Analytics.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		if ( ! WooPlugin::hasAnalytics() ) {
			$this->markTestSkipped( 'WooCommerce Analytics is not enabled in this environment.' );
		}

		$this->ensureAnalyticsRoutesRegistered();
	}

	/**
	 * Seeds one completed order and syncs the analytics lookup tables synchronously.
	 *
	 * Analytics reads from the `wc_order_stats` / product-lookup tables, populated by
	 * an async Action Scheduler job — a freshly-created order does not appear until
	 * synced. `OrdersScheduler::import()` runs every lookup-table sync inline (it is
	 * the body the scheduled `import` action calls), so the order is visible to the
	 * report immediately.
	 *
	 * @return void
	 */
	private function seedSyncedOrder(): void {
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

		OrdersScheduler::import( $order->get_id() );
	}

	/**
	 * The ability resolves from the registry and is named.
	 */
	public function test_registered(): void {
		$ability = wp_get_ability( 'og-wc-reports/get-performance-indicators' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'og-wc-reports/get-performance-indicators', $ability->get_name() );
	}

	/**
	 * Happy path: omitting stats returns all available indicators.
	 */
	public function test_returns_indicators(): void {
		$this->actingAs( 'administrator' );
		$this->seedSyncedOrder();

		$result = wp_get_ability( 'og-wc-reports/get-performance-indicators' )->execute( array() );

		$this->assertIsArray( $result );
		$this->assertSame( array( 'indicators', 'total' ), array_keys( $result ) );
		$this->assertIsArray( $result['indicators'] );
		$this->assertIsInt( $result['total'] );
		$this->assertSame( count( $result['indicators'] ), $result['total'] );
		$this->assertNotEmpty( $result['indicators'], 'Omitting stats must return all available indicators.' );
	}

	/**
	 * Output shape: every indicator row has the closed key set and value may be null.
	 */
	public function test_output_shape(): void {
		$this->actingAs( 'administrator' );
		$this->seedSyncedOrder();

		$result = wp_get_ability( 'og-wc-reports/get-performance-indicators' )->execute( array( 'stats' => self::STATS ) );

		$this->assertIsArray( $result );
		$this->assertNotEmpty( $result['indicators'] );

		$saw_null_or_number = false;
		foreach ( $result['indicators'] as $indicator ) {
			$this->assertSame( self::INDICATOR_KEYS, array_keys( $indicator ) );
			$this->assertIsString( $indicator['stat'] );
			$this->assertIsString( $indicator['label'] );
			$this->assertIsString( $indicator['format'] );
			$this->assertContains( $indicator['format'], array( 'number', 'currency' ) );

			// value is either null (no data for range) or a float — never coerced to 0.
			$this->assertTrue(
				null === $indicator['value'] || is_float( $indicator['value'] ),
				'Indicator value must be null or a float.'
			);
			$saw_null_or_number = true;
		}

		$this->assertTrue( $saw_null_or_number );
	}

	/**
	 * A stats filter narrows the response to the requested indicators.
	 */
	public function test_stats_filter_narrows_results(): void {
		$this->actingAs( 'administrator' );
		$this->seedSyncedOrder();

		$all = wp_get_ability( 'og-wc-reports/get-performance-indicators' )->execute( array( 'stats' => self::STATS ) );
		$this->assertIsArray( $all );
		$this->assertGreaterThan( 1, $all['total'], 'Expected more than one indicator for a multi-stat list.' );

		$result = wp_get_ability( 'og-wc-reports/get-performance-indicators' )->execute(
			array( 'stats' => array( 'revenue/total_sales' ) )
		);

		$this->assertIsArray( $result );
		$this->assertSame( 1, $result['total'] );
		$this->assertCount( 1, $result['indicators'] );
		$this->assertSame( 'revenue/total_sales', $result['indicators'][0]['stat'] );
	}

	/**
	 * Wrong capability: a subscriber lacks `view_woocommerce_reports` and is denied.
	 */
	public function test_wrong_cap_denied(): void {
		$this->actingAs( 'subscriber' );

		$ability = wp_get_ability( 'og-wc-reports/get-performance-indicators' );

		$this->assertFalse( $ability->check_permissions( array() ) );

		$result = $ability->execute( array() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}
}
