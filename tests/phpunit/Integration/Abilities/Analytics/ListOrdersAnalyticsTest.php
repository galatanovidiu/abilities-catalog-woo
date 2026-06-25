<?php
/**
 * Integration tests for the `og-wc-reports/list-orders-analytics` ability.
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
 * @covers \GalatanOvidiu\AbilitiesCatalogWoo\Abilities\Woo\Analytics\ListOrdersAnalytics
 */
final class ListOrdersAnalyticsTest extends TestCase {

	private const ABILITY = 'og-wc-reports/list-orders-analytics';

	/**
	 * The exact keys a shaped order-analytics row exposes.
	 *
	 * Asserting against this fixed set proves the raw row — which carries
	 * `date_created_gmt` and the fat `extended_info` object — is never leaked.
	 *
	 * @var list<string>
	 */
	private const ROW_KEYS = array(
		'order_id',
		'order_number',
		'date_created',
		'status',
		'customer_id',
		'customer_type',
		'num_items_sold',
		'net_total',
		'total_formatted',
	);

	/**
	 * Skips the suite when the WooCommerce Analytics feature is off.
	 *
	 * The Analytics abilities are conditional on {@see WooPlugin::hasAnalytics()};
	 * when the feature is off they do not register, so there is nothing to exercise
	 * and `RegistryTest` expects them absent.
	 */
	public function set_up(): void {
		parent::set_up();

		if ( ! WooPlugin::hasAnalytics() ) {
			$this->markTestSkipped( 'WooCommerce Analytics feature is not active; the wc-analytics route is not registered.' );
		}

		$this->ensureAnalyticsRoutesRegistered();
	}

	public function test_registered(): void {
		$ability = wp_get_ability( self::ABILITY );

		$this->assertNotNull( $ability );
		$this->assertSame( self::ABILITY, $ability->get_name() );
	}

	public function test_happy_path_returns_shaped_rows(): void {
		$this->actingAs( 'administrator' );
		$this->seedCompletedOrder();

		$result = wp_get_ability( self::ABILITY )->execute( array() );

		$this->assertIsArray( $result );
		$this->assertSame( array( 'items', 'total' ), array_keys( $result ) );
		$this->assertIsArray( $result['items'] );
		$this->assertIsInt( $result['total'] );
		$this->assertNotEmpty( $result['items'] );

		$row = $result['items'][0];
		$this->assertSame( self::ROW_KEYS, array_keys( $row ) );
		$this->assertIsInt( $row['order_id'] );
		$this->assertIsString( $row['order_number'] );
		$this->assertIsString( $row['date_created'] );
		$this->assertIsString( $row['status'] );
		$this->assertIsInt( $row['customer_id'] );
		$this->assertIsString( $row['customer_type'] );
		$this->assertIsInt( $row['num_items_sold'] );
		$this->assertIsFloat( $row['net_total'] );
		$this->assertIsString( $row['total_formatted'] );
	}

	public function test_per_page_filter_narrows_results(): void {
		$this->actingAs( 'administrator' );
		$this->seedCompletedOrder();
		$this->seedCompletedOrder();
		$this->seedCompletedOrder();

		$result = wp_get_ability( self::ABILITY )->execute( array( 'per_page' => 1 ) );

		$this->assertCount( 1, $result['items'] );
		// total reflects the full matching set via X-WP-Total, not the one returned row.
		$this->assertGreaterThanOrEqual( 1, $result['total'] );
	}

	public function test_output_shape_has_no_extended_info_or_raw_fields(): void {
		$this->actingAs( 'administrator' );
		$this->seedCompletedOrder();

		$result = wp_get_ability( self::ABILITY )->execute( array() );

		$this->assertSame( array( 'items', 'total' ), array_keys( $result ) );
		$row = $result['items'][0];
		$this->assertSame( self::ROW_KEYS, array_keys( $row ) );
		$this->assertArrayNotHasKey( 'extended_info', $row );
		$this->assertArrayNotHasKey( 'date_created_gmt', $row );
		$this->assertArrayNotHasKey( '_links', $row );
	}

	public function test_wrong_capability_is_denied(): void {
		$this->seedCompletedOrder();
		$this->actingAs( 'subscriber' );

		$ability = wp_get_ability( self::ABILITY );
		$this->assertFalse( $ability->check_permissions( array() ) );

		$result = $ability->execute( array() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	/**
	 * Seeds a completed order with one line item and syncs the Analytics lookup tables.
	 *
	 * Analytics reports read from the `wc_order_stats` / `wc_order_product_lookup`
	 * lookup tables, populated by an asynchronous Action Scheduler sync, NOT on order
	 * save. `OrdersScheduler::import()` runs the same lookup-table syncs synchronously
	 * (`OrdersStatsDataStore::sync_order()` et al.), so the seeded order appears in
	 * the report within the test. WooCommerce runtime objects are used instead of the
	 * `WC_Helper_*` factories, which the distributed `woocommerce.zip` does not ship.
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
