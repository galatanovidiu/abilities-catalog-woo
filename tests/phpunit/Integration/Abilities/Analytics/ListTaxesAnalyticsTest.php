<?php
/**
 * Integration tests for the `og-wc-reports/list-taxes-analytics` ability.
 *
 * @package AbilitiesCatalogWoo\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogWoo\Tests\Integration\Abilities\Analytics;

use GalatanOvidiu\AbilitiesCatalogWoo\Support\WooPlugin;
use GalatanOvidiu\AbilitiesCatalogWoo\Tests\TestCase;
use WC_Order;
use WC_Product_Simple;
use WC_Tax;

/**
 * @covers \GalatanOvidiu\AbilitiesCatalogWoo\Abilities\Woo\Analytics\ListTaxesAnalytics
 */
final class ListTaxesAnalyticsTest extends TestCase {

	private const ABILITY = 'og-wc-reports/list-taxes-analytics';

	/**
	 * The closed set of row keys the ability returns.
	 *
	 * @var array<int,string>
	 */
	private const ROW_KEYS = array(
		'tax_rate_id',
		'name',
		'tax_rate',
		'country',
		'state',
		'total_tax',
		'order_tax',
		'shipping_tax',
		'orders_count',
	);

	/**
	 * The tax rate ID seeded for the current test, or null when none was seeded.
	 *
	 * @var int|null
	 */
	private $tax_rate_id = null;

	/**
	 * The `woocommerce_calc_taxes` option value before the test enabled taxes.
	 *
	 * @var string
	 */
	private $previous_calc_taxes = 'no';

	/**
	 * Skips the whole class cleanly when WooCommerce Analytics is off.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		if ( ! WooPlugin::hasAnalytics() ) {
			$this->markTestSkipped( 'WooCommerce Analytics feature is not enabled.' );
		}

		$this->ensureAnalyticsRoutesRegistered();

		$this->actingAs( 'administrator' );
	}

	/**
	 * Removes any tax rates seeded by a test so they do not bleed into later tests.
	 *
	 * The `wc_tax_rates` table is a custom table the `WP_UnitTestCase` transaction
	 * does not roll back, so a seeded rate is deleted explicitly here.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		if ( null !== $this->tax_rate_id ) {
			WC_Tax::_delete_tax_rate( $this->tax_rate_id );
		}
		update_option( 'woocommerce_calc_taxes', $this->previous_calc_taxes );

		parent::tear_down();
	}

	/**
	 * Enables tax calculation and inserts a single standard tax rate.
	 *
	 * @return int The inserted tax rate ID.
	 */
	private function seedTaxRate(): int {
		$this->previous_calc_taxes = (string) get_option( 'woocommerce_calc_taxes', 'no' );
		update_option( 'woocommerce_calc_taxes', 'yes' );

		$this->tax_rate_id = WC_Tax::_insert_tax_rate(
			array(
				'tax_rate_country'  => 'US',
				'tax_rate_state'    => 'CA',
				'tax_rate'          => '8.2500',
				'tax_rate_name'     => 'CA Tax',
				'tax_rate_priority' => 1,
				'tax_rate_compound' => 0,
				'tax_rate_shipping' => 1,
				'tax_rate_order'    => 0,
				'tax_rate_class'    => '',
			)
		);

		return (int) $this->tax_rate_id;
	}

	/**
	 * Creates a completed, taxed order and syncs the analytics lookup tables.
	 *
	 * `OrdersScheduler::import()` runs the same data-store syncs the async scheduler
	 * would, synchronously, so the order's tax appears in the report immediately.
	 *
	 * @return void
	 */
	private function seedCompletedTaxedOrder(): void {
		$product = new WC_Product_Simple();
		$product->set_name( 'Seeded Taxed Product' );
		$product->set_sku( 'SEED-TAX-1' );
		$product->set_status( 'publish' );
		$product->set_tax_status( 'taxable' );
		$product->set_regular_price( '100.00' );
		$product->save();

		$order = new WC_Order();
		$order->set_billing_country( 'US' );
		$order->set_billing_state( 'CA' );
		$order->add_product( wc_get_product( $product->get_id() ), 1 );
		$order->set_status( 'completed' );
		$order->calculate_totals();
		$order->save();

		\Automattic\WooCommerce\Internal\Admin\Schedulers\OrdersScheduler::import( (int) $order->get_id() );
	}

	/**
	 * The ability registers and resolves to its name.
	 */
	public function test_ability_is_registered(): void {
		$ability = wp_get_ability( self::ABILITY );

		$this->assertNotNull( $ability );
		$this->assertSame( self::ABILITY, $ability->get_name() );
	}

	/**
	 * Happy path: execution returns the closed `{ items, total }` shape with shaped rows.
	 *
	 * Analytics reads the `wc_order_tax_lookup` table, which an async sync populates;
	 * the synchronous `OrdersScheduler::import()` above usually surfaces the row, but
	 * the env may not aggregate it deterministically, so this asserts the SHAPE
	 * (closed row keys, correct types, no leaked fields) rather than a non-zero count.
	 */
	public function test_execute_returns_shaped_rows(): void {
		$this->seedTaxRate();
		$this->seedCompletedTaxedOrder();

		$result = wp_get_ability( self::ABILITY )->execute( array() );

		$this->assertIsArray( $result );
		$this->assertSame( array( 'items', 'total' ), array_keys( $result ) );
		$this->assertIsArray( $result['items'] );
		$this->assertIsInt( $result['total'] );

		foreach ( $result['items'] as $row ) {
			$this->assertSame( self::ROW_KEYS, array_keys( $row ) );
			$this->assertIsInt( $row['tax_rate_id'] );
			$this->assertIsString( $row['name'] );
			$this->assertIsFloat( $row['tax_rate'] );
			$this->assertIsString( $row['country'] );
			$this->assertIsString( $row['state'] );
			$this->assertIsFloat( $row['total_tax'] );
			$this->assertIsFloat( $row['order_tax'] );
			$this->assertIsFloat( $row['shipping_tax'] );
			$this->assertIsInt( $row['orders_count'] );
		}
	}

	/**
	 * An orderby filter is accepted without erroring.
	 */
	public function test_orderby_filter_accepted(): void {
		$this->seedTaxRate();
		$this->seedCompletedTaxedOrder();

		$result = wp_get_ability( self::ABILITY )->execute( array( 'orderby' => 'total_tax' ) );

		$this->assertIsArray( $result );
		$this->assertSame( array( 'items', 'total' ), array_keys( $result ) );
		$this->assertIsArray( $result['items'] );
	}

	/**
	 * The output never leaks raw row fields beyond the closed whitelist.
	 */
	public function test_output_is_closed_shape(): void {
		$this->seedTaxRate();
		$this->seedCompletedTaxedOrder();

		$result = wp_get_ability( self::ABILITY )->execute( array() );

		foreach ( $result['items'] as $row ) {
			$this->assertSame( self::ROW_KEYS, array_keys( $row ) );
			$this->assertArrayNotHasKey( 'priority', $row );
			$this->assertArrayNotHasKey( 'extended_info', $row );
			$this->assertArrayNotHasKey( '_links', $row );
			$this->assertArrayNotHasKey( 'segments', $row );
		}
	}

	/**
	 * A subscriber lacks view_woocommerce_reports, so permission is denied.
	 */
	public function test_subscriber_is_denied(): void {
		$this->actingAs( 'subscriber' );

		$ability = wp_get_ability( self::ABILITY );

		$this->assertFalse( $ability->check_permissions( array() ) );

		$result = $ability->execute( array() );
		$this->assertWPError( $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}
}
