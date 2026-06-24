<?php
/**
 * Integration tests for the `wc-reports/list-products-analytics` ability.
 *
 * @package AbilitiesCatalogWoo\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogWoo\Tests\Integration\Abilities\Analytics;

use GalatanOvidiu\AbilitiesCatalogWoo\Support\WooPlugin;
use GalatanOvidiu\AbilitiesCatalogWoo\Tests\TestCase;
use WC_Order;
use WC_Product_Simple;

/**
 * @covers \GalatanOvidiu\AbilitiesCatalogWoo\Abilities\Woo\Analytics\ListProductsAnalytics
 */
final class ListProductsAnalyticsTest extends TestCase {

	private const ABILITY = 'wc-reports/list-products-analytics';

	/**
	 * The closed set of row keys the ability returns.
	 *
	 * @var array<int,string>
	 */
	private const ROW_KEYS = array( 'product_id', 'items_sold', 'net_revenue', 'orders_count', 'name', 'sku' );

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
	 * Creates a completed order containing one product and syncs the analytics lookup tables.
	 *
	 * `OrdersScheduler::import()` runs the same data-store syncs the async scheduler
	 * would (`OrdersStatsDataStore::sync_order` + `ProductsDataStore::sync_order_products`),
	 * synchronously, so the order's product appears in the report immediately.
	 *
	 * WooCommerce rejects a duplicate SKU (`WC_Data_Exception: Invalid or duplicated SKU`),
	 * so callers that seed more than one product must pass distinct SKUs. The default
	 * keeps the happy-path SKU assertion stable for single-product tests.
	 *
	 * @param string $sku The product SKU. Must be unique across products seeded in one test.
	 * @return int The seeded product ID.
	 */
	private function seedCompletedOrderWithProduct( string $sku = 'SEED-ANALYTICS-1' ): int {
		$product = new WC_Product_Simple();
		$product->set_name( 'Seeded Analytics Product' );
		$product->set_sku( $sku );
		$product->set_status( 'publish' );
		$product->set_regular_price( '10.00' );
		$product->save();

		$order = new WC_Order();
		$order->add_product( wc_get_product( $product->get_id() ), 2 );
		$order->set_status( 'completed' );
		$order->calculate_totals();
		$order->save();

		\Automattic\WooCommerce\Internal\Admin\Schedulers\OrdersScheduler::import( $order->get_id() );

		return (int) $product->get_id();
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
	 * Happy path: a seeded product appears with shaped fields and a sane total.
	 */
	public function test_execute_returns_shaped_rows(): void {
		$product_id = $this->seedCompletedOrderWithProduct();

		$result = wp_get_ability( self::ABILITY )->execute( array() );

		$this->assertIsArray( $result );
		$this->assertSame( array( 'items', 'total' ), array_keys( $result ) );
		$this->assertIsArray( $result['items'] );
		$this->assertIsInt( $result['total'] );

		$match = null;
		foreach ( $result['items'] as $row ) {
			if ( (int) $row['product_id'] === $product_id ) {
				$match = $row;
				break;
			}
		}

		$this->assertNotNull( $match, 'Seeded product did not appear in the analytics report.' );
		$this->assertSame( self::ROW_KEYS, array_keys( $match ) );
		$this->assertIsInt( $match['items_sold'] );
		$this->assertIsFloat( $match['net_revenue'] );
		$this->assertIsInt( $match['orders_count'] );
		$this->assertSame( 'Seeded Analytics Product', $match['name'] );
		$this->assertSame( 'SEED-ANALYTICS-1', $match['sku'] );
		$this->assertSame( 2, $match['items_sold'] );
	}

	/**
	 * The output never leaks the raw extended_info block or other raw row fields.
	 */
	public function test_output_drops_extended_info(): void {
		$this->seedCompletedOrderWithProduct();

		$result = wp_get_ability( self::ABILITY )->execute( array() );

		$this->assertNotEmpty( $result['items'] );
		foreach ( $result['items'] as $row ) {
			$this->assertSame( self::ROW_KEYS, array_keys( $row ) );
			$this->assertArrayNotHasKey( 'extended_info', $row );
			$this->assertArrayNotHasKey( '_links', $row );
			$this->assertArrayNotHasKey( 'segments', $row );
		}
	}

	/**
	 * A per_page filter narrows the returned rows without erroring.
	 */
	public function test_per_page_filter_narrows_results(): void {
		$this->seedCompletedOrderWithProduct( 'SEED-ANALYTICS-1' );
		$this->seedCompletedOrderWithProduct( 'SEED-ANALYTICS-2' );

		$result = wp_get_ability( self::ABILITY )->execute( array( 'per_page' => 1 ) );

		$this->assertIsArray( $result );
		$this->assertLessThanOrEqual( 1, count( $result['items'] ) );
	}

	/**
	 * A future date range narrows out all rows (range filter is applied).
	 */
	public function test_after_filter_narrows_results(): void {
		$this->seedCompletedOrderWithProduct();

		$result = wp_get_ability( self::ABILITY )->execute(
			array( 'after' => gmdate( 'Y-m-d\TH:i:s', time() + DAY_IN_SECONDS ) )
		);

		$this->assertIsArray( $result );
		$this->assertSame( array( 'items', 'total' ), array_keys( $result ) );
		$this->assertSame( array(), $result['items'] );
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
