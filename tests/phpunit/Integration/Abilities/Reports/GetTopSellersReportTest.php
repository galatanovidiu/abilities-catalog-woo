<?php
/**
 * Integration tests for the `wc-reports/get-top-sellers-report` ability.
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
 * @covers \GalatanOvidiu\AbilitiesCatalogWoo\Abilities\Woo\Reports\GetTopSellersReport
 */
final class GetTopSellersReportTest extends TestCase {

	private const ABILITY = 'wc-reports/get-top-sellers-report';

	/**
	 * The exact keys a shaped top-sellers row exposes.
	 *
	 * Asserting against this fixed set proves the raw report row (and any _links)
	 * never leaks: only these projected fields reach the consumer.
	 *
	 * @var list<string>
	 */
	private const ROW_KEYS = array(
		'product_id',
		'name',
		'quantity',
	);

	public function test_registered(): void {
		$ability = wp_get_ability( self::ABILITY );

		$this->assertNotNull( $ability );
		$this->assertSame( self::ABILITY, $ability->get_name() );
	}

	public function test_happy_path_returns_shaped_rows(): void {
		$this->actingAs( 'administrator' );
		$this->seedCompletedOrder();

		$result = $this->executeWithFreshReportCache( array() );

		$this->assertIsArray( $result );
		$this->assertSame( array( 'items', 'total' ), array_keys( $result ) );
		$this->assertIsArray( $result['items'] );
		$this->assertIsInt( $result['total'] );
		$this->assertNotEmpty( $result['items'] );
		$this->assertSame( count( $result['items'] ), $result['total'] );

		$row = $result['items'][0];
		$this->assertSame( self::ROW_KEYS, array_keys( $row ) );
		$this->assertIsInt( $row['product_id'] );
		$this->assertIsString( $row['name'] );
		$this->assertIsInt( $row['quantity'] );
		$this->assertGreaterThanOrEqual( 1, $row['quantity'] );
	}

	public function test_optional_period_filter_is_accepted(): void {
		$this->actingAs( 'administrator' );
		$this->seedCompletedOrder();

		$result = $this->executeWithFreshReportCache( array( 'period' => 'year' ) );

		$this->assertIsArray( $result );
		$this->assertSame( array( 'items', 'total' ), array_keys( $result ) );
		$this->assertIsInt( $result['total'] );
	}

	public function test_output_shape_has_no_raw_report_fields(): void {
		$this->actingAs( 'administrator' );
		$this->seedCompletedOrder();

		$result = $this->executeWithFreshReportCache( array() );

		$this->assertSame( array( 'items', 'total' ), array_keys( $result ) );
		$this->assertNotEmpty( $result['items'] );

		$row = $result['items'][0];
		$this->assertSame( self::ROW_KEYS, array_keys( $row ) );
		$this->assertArrayNotHasKey( '_links', $row );
		$this->assertArrayNotHasKey( 'title', $row );
	}

	public function test_wrong_capability_is_denied(): void {
		$this->actingAs( 'subscriber' );

		$ability = wp_get_ability( self::ABILITY );

		$this->assertFalse( $ability->check_permissions( array() ) );

		$result = $ability->execute( array() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	/**
	 * Executes the ability against a freshly computed (uncached) legacy report.
	 *
	 * The legacy `top_sellers` report runs through `WC_Report_Sales_By_Date`,
	 * whose `WC_Admin_Report::get_order_report_data()` memoizes each query in a
	 * `protected static` map (`WC_Admin_Report::$cached_results`) and a
	 * `wc_report_sales_by_date` transient, both keyed only by the SQL query hash
	 * (which depends on the period, not on the seeded rows). The WP test fixture
	 * rolls back the database per test, but neither that rollback nor any WC
	 * helper clears that static map, so a later test reads the prior test's
	 * now-stale (empty) result and `items` comes back empty.
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
	 * Seeds a saved COMPLETED order with one product line item.
	 *
	 * Built with WooCommerce's runtime object API (WC_Order / WC_Product_Simple)
	 * rather than the WC_Helper_Order test factory, because the test environment
	 * mounts the distributed WooCommerce build, which ships no tests/ helper
	 * framework. The order must be completed (not pending) to count toward the
	 * top-sellers report.
	 *
	 * @return WC_Order The created order.
	 */
	private function seedCompletedOrder(): WC_Order {
		$product = new WC_Product_Simple();
		$product->set_name( 'Seeded Product' );
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

		return $order;
	}
}
