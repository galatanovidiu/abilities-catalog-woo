<?php
/**
 * Integration tests for the `og-wc-reports/get-sales-report` ability.
 *
 * @package AbilitiesCatalogWoo\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogWoo\Tests\Integration\Abilities\Reports;

use GalatanOvidiu\AbilitiesCatalogWoo\Tests\TestCase;
use WC_Order;
use WC_Product_Simple;
use WP_Error;

/**
 * @covers \GalatanOvidiu\AbilitiesCatalogWoo\Abilities\Woo\Reports\GetSalesReport
 */
final class GetSalesReportTest extends TestCase {

	private const ABILITY = 'og-wc-reports/get-sales-report';

	/**
	 * The exact closed top-level key set the ability returns.
	 */
	private const TOP_LEVEL_KEYS = array(
		'total_sales',
		'net_sales',
		'average_sales',
		'total_orders',
		'total_items',
		'total_tax',
		'total_shipping',
		'total_refunds',
		'total_discount',
		'total_customers',
		'totals_grouped_by',
		'totals',
	);

	/**
	 * The exact eight keys every per-period bucket carries.
	 */
	private const BUCKET_KEYS = array(
		'sales',
		'orders',
		'items',
		'tax',
		'shipping',
		'discount',
		'refunds',
		'customers',
	);

	/**
	 * Executes the ability against a freshly computed (uncached) legacy report.
	 *
	 * The legacy `sales` report runs through `WC_Report_Sales_By_Date`, whose
	 * `WC_Admin_Report::get_order_report_data()` memoizes each query in a
	 * `protected static` map (`WC_Admin_Report::$cached_results`) and a
	 * `wc_report_sales_by_date` transient, both keyed only by the SQL query hash
	 * (which depends on the period, not on the seeded rows). The WP test fixture
	 * rolls back the database per test, but neither that rollback nor any WC
	 * helper clears that static map, so a later test reads the prior test's
	 * stale result instead of recomputing against its own seeded order.
	 *
	 * WooCommerce exposes `nocache` as a recognized report argument
	 * (`WC_Admin_Report::get_order_report_data()` default args; the `$debug ||
	 * $nocache` branch bypasses both reading and writing the cache). Forcing it
	 * on for the duration of the call makes each test compute against its own
	 * seeded order, independent of run order. The filter is removed in `finally`
	 * so it never leaks into another test.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return mixed The ability result.
	 */
	private function executeWithFreshReportCache( array $input ) {
		$force_nocache = static function ( array $args ): array {
			$args['nocache'] = true;

			return $args;
		};

		add_filter( 'woocommerce_reports_get_order_report_data_args', $force_nocache );

		try {
			return wp_get_ability( self::ABILITY )->execute( $input );
		} finally {
			remove_filter( 'woocommerce_reports_get_order_report_data_args', $force_nocache );
		}
	}

	/**
	 * Seeds a completed order with one line item so the report is non-trivial.
	 *
	 * Uses WooCommerce's runtime object API (the distributed wp-env zip ships no
	 * `WC_Helper_*` factories). A completed order is what appears in the legacy
	 * sales totals; a pending order would not.
	 *
	 * @return void
	 */
	private function seedCompletedOrder(): void {
		$product = new WC_Product_Simple();
		$product->set_name( 'Seeded Report Product' );
		$product->set_status( 'publish' );
		$product->set_regular_price( '10.00' );
		$product->save();

		$order = new WC_Order();
		$order->add_product( wc_get_product( $product->get_id() ), 2 );
		$order->set_status( 'completed' );
		$order->calculate_totals();
		$order->save();
	}

	public function test_registered(): void {
		$ability = wp_get_ability( self::ABILITY );

		$this->assertNotNull( $ability );
		$this->assertSame( self::ABILITY, $ability->get_name() );
	}

	public function test_happy_path_returns_shaped_object_with_totals_map(): void {
		$this->actingAs( 'administrator' );
		$this->seedCompletedOrder();

		$result = $this->executeWithFreshReportCache( array() );

		$this->assertIsArray( $result );
		$this->assertIsString( $result['total_sales'] );
		$this->assertIsInt( $result['total_orders'] );
		$this->assertIsInt( $result['total_items'] );
		$this->assertContains( $result['totals_grouped_by'], array( 'day', 'month' ) );

		// A completed order in the period makes the top-level totals non-trivial.
		$this->assertGreaterThanOrEqual( 1, $result['total_orders'] );
		$this->assertGreaterThan( 0.0, (float) $result['total_sales'] );

		// totals is an object map (so an empty map serializes as {}); cast for inspection.
		$buckets = (array) $result['totals'];
		$this->assertNotEmpty( $buckets );
	}

	public function test_optional_period_filter_is_accepted(): void {
		$this->actingAs( 'administrator' );
		$this->seedCompletedOrder();

		$result = $this->executeWithFreshReportCache( array( 'period' => 'year' ) );

		$this->assertIsArray( $result );
		$this->assertNotInstanceOf( WP_Error::class, $result );
		$this->assertSame( self::TOP_LEVEL_KEYS, array_keys( $result ) );
	}

	public function test_output_shape_is_exactly_the_closed_top_level_keys(): void {
		$this->actingAs( 'administrator' );
		$this->seedCompletedOrder();

		$result = $this->executeWithFreshReportCache( array() );

		$this->assertSame( self::TOP_LEVEL_KEYS, array_keys( $result ) );
	}

	public function test_each_period_bucket_has_exactly_the_eight_closed_keys(): void {
		$this->actingAs( 'administrator' );
		$this->seedCompletedOrder();

		$result  = $this->executeWithFreshReportCache( array() );
		$buckets = (array) $result['totals'];

		$this->assertNotEmpty( $buckets );
		foreach ( $buckets as $bucket ) {
			$bucket = (array) $bucket;
			$this->assertSame( self::BUCKET_KEYS, array_keys( $bucket ) );
			$this->assertIsString( $bucket['sales'] );
			$this->assertIsInt( $bucket['orders'] );
			$this->assertIsInt( $bucket['items'] );
			$this->assertIsString( $bucket['tax'] );
			$this->assertIsString( $bucket['shipping'] );
			$this->assertIsString( $bucket['discount'] );
			$this->assertIsString( $bucket['refunds'] );
			$this->assertIsInt( $bucket['customers'] );
		}
	}

	public function test_wrong_capability_is_denied(): void {
		$this->actingAs( 'subscriber' );

		$ability = wp_get_ability( self::ABILITY );

		$this->assertFalse( $ability->check_permissions( array() ) );

		$result = $ability->execute( array() );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}
}
