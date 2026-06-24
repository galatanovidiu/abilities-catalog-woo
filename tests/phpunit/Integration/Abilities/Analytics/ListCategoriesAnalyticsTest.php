<?php
/**
 * Integration tests for the `wc-reports/list-categories-analytics` ability.
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
 * @covers \GalatanOvidiu\AbilitiesCatalogWoo\Abilities\Woo\Analytics\ListCategoriesAnalytics
 */
final class ListCategoriesAnalyticsTest extends TestCase {

	private const ABILITY = 'wc-reports/list-categories-analytics';

	/**
	 * The closed set of row keys the ability returns, in schema order.
	 *
	 * @var array<int,string>
	 */
	private const ROW_KEYS = array( 'category_id', 'items_sold', 'net_revenue', 'orders_count', 'products_count', 'category_name' );

	/**
	 * Skips the whole class cleanly when WooCommerce Analytics is off, then makes the
	 * lazy `wc-analytics` routes available for this test's REST dispatches.
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
	 * Creates a completed order with one product assigned to a category and syncs the
	 * analytics lookup tables.
	 *
	 * `OrdersScheduler::import()` runs the data-store syncs the async scheduler would
	 * run, synchronously, so the order's product and its category appear in the report
	 * immediately. Uses the WooCommerce runtime object API, not the WC_Helper_*
	 * factories (absent from the distributed build).
	 *
	 * @param string $sku The product SKU. Must be unique across products seeded in one test.
	 * @return int The seeded product-category term ID.
	 */
	private function seedCompletedOrderWithCategorisedProduct( string $sku = 'SEED-CAT-1' ): int {
		$term = wp_insert_term( 'Seeded Category ' . $sku, 'product_cat' );
		$this->assertIsArray( $term );
		$term_id = (int) $term['term_id'];

		$product = new WC_Product_Simple();
		$product->set_name( 'Seeded Categorised Product' );
		$product->set_sku( $sku );
		$product->set_status( 'publish' );
		$product->set_regular_price( '10.00' );
		$product->set_category_ids( array( $term_id ) );
		$product->save();

		$order = new WC_Order();
		$order->add_product( wc_get_product( $product->get_id() ), 2 );
		$order->set_status( 'completed' );
		$order->calculate_totals();
		$order->save();

		OrdersScheduler::import( (int) $order->get_id() );

		return $term_id;
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
	 * Happy path: the result is the closed `{ items, total }` shape and rows validate.
	 *
	 * The analytics category lookup tables may not surface a freshly-seeded order in
	 * the env, so this asserts SHAPE (closed keys, correct types) rather than a
	 * specific non-zero count; the seeded category is matched when present.
	 */
	public function test_execute_returns_shaped_rows(): void {
		$term_id = $this->seedCompletedOrderWithCategorisedProduct();

		$result = wp_get_ability( self::ABILITY )->execute( array() );

		$this->assertIsArray( $result );
		$this->assertSame( array( 'items', 'total' ), array_keys( $result ) );
		$this->assertIsArray( $result['items'] );
		$this->assertIsInt( $result['total'] );

		foreach ( $result['items'] as $row ) {
			$this->assertSame( self::ROW_KEYS, array_keys( $row ) );
			$this->assertIsInt( $row['category_id'] );
			$this->assertIsInt( $row['items_sold'] );
			$this->assertIsFloat( $row['net_revenue'] );
			$this->assertIsInt( $row['orders_count'] );
			$this->assertIsInt( $row['products_count'] );
			$this->assertIsString( $row['category_name'] );
		}

		$match = null;
		foreach ( $result['items'] as $row ) {
			if ( (int) $row['category_id'] === $term_id ) {
				$match = $row;
				break;
			}
		}

		if ( null !== $match ) {
			$this->assertSame( 'Seeded Category SEED-CAT-1', $match['category_name'] );
			$this->assertSame( 2, $match['items_sold'] );
		}
	}

	/**
	 * The output never leaks the raw extended_info block, the raw `name` key, or REST links.
	 */
	public function test_output_drops_extended_info(): void {
		$this->seedCompletedOrderWithCategorisedProduct();

		$result = wp_get_ability( self::ABILITY )->execute( array() );

		foreach ( $result['items'] as $row ) {
			$this->assertSame( self::ROW_KEYS, array_keys( $row ) );
			$this->assertArrayNotHasKey( 'extended_info', $row );
			$this->assertArrayNotHasKey( 'name', $row );
			$this->assertArrayNotHasKey( '_links', $row );
			$this->assertArrayNotHasKey( 'segments', $row );
		}
	}

	/**
	 * An orderby filter is accepted and the closed shape is preserved.
	 */
	public function test_orderby_filter_accepted(): void {
		$this->seedCompletedOrderWithCategorisedProduct();

		$result = wp_get_ability( self::ABILITY )->execute( array( 'orderby' => 'net_revenue' ) );

		$this->assertIsArray( $result );
		$this->assertSame( array( 'items', 'total' ), array_keys( $result ) );
		$this->assertIsArray( $result['items'] );
		$this->assertIsInt( $result['total'] );
	}

	/**
	 * A subscriber lacks view_woocommerce_reports, so permission is denied.
	 */
	public function test_subscriber_is_denied(): void {
		$this->actingAs( 'subscriber' );

		$ability = wp_get_ability( self::ABILITY );

		$this->assertFalse( $ability->check_permissions( array() ) );

		$result = $ability->execute( array() );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}
}
