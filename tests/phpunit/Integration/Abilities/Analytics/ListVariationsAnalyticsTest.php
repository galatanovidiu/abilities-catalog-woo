<?php
/**
 * Integration tests for the `og-wc-reports/list-variations-analytics` ability.
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
 * @covers \GalatanOvidiu\AbilitiesCatalogWoo\Abilities\Woo\Analytics\ListVariationsAnalytics
 */
final class ListVariationsAnalyticsTest extends TestCase {

	private const ABILITY = 'og-wc-reports/list-variations-analytics';

	/**
	 * The closed set of row keys the ability returns.
	 *
	 * Asserting against this fixed set proves the raw row — which nests the fat
	 * `extended_info` object (image, permalink, attributes, low_stock_amount) — is
	 * never leaked.
	 *
	 * @var array<int,string>
	 */
	private const ROW_KEYS = array(
		'product_id',
		'variation_id',
		'items_sold',
		'net_revenue',
		'orders_count',
		'name',
		'price',
		'stock_status',
		'stock_quantity',
	);

	/**
	 * Skips the whole class cleanly when WooCommerce Analytics is off.
	 *
	 * The Analytics abilities are conditional on {@see WooPlugin::hasAnalytics()};
	 * when the feature is off they do not register and the `wc-analytics` routes are
	 * absent, so there is nothing to exercise.
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
	 * The ability registers and resolves to its name.
	 */
	public function test_ability_is_registered(): void {
		$ability = wp_get_ability( self::ABILITY );

		$this->assertNotNull( $ability );
		$this->assertSame( self::ABILITY, $ability->get_name() );
	}

	/**
	 * Happy path: the envelope is shaped, and any returned row matches the closed schema.
	 *
	 * A fully-synced variation row is hard to seed deterministically (the variation
	 * lookup tables depend on the async sync pipeline), so this asserts envelope
	 * STRUCTURE and TYPE, and the row shape only if the report carries one. It never
	 * asserts a magnitude.
	 */
	public function test_execute_returns_shaped_envelope(): void {
		$this->seedCompletedVariationOrder();

		$result = wp_get_ability( self::ABILITY )->execute( array() );

		$this->assertIsArray( $result );
		$this->assertSame( array( 'items', 'total' ), array_keys( $result ) );
		$this->assertIsArray( $result['items'] );
		$this->assertIsInt( $result['total'] );

		foreach ( $result['items'] as $row ) {
			$this->assertSame( self::ROW_KEYS, array_keys( $row ) );
			$this->assertIsInt( $row['product_id'] );
			$this->assertIsInt( $row['variation_id'] );
			$this->assertIsInt( $row['items_sold'] );
			$this->assertIsFloat( $row['net_revenue'] );
			$this->assertIsInt( $row['orders_count'] );
			$this->assertIsString( $row['name'] );
			$this->assertIsFloat( $row['price'] );
			$this->assertIsString( $row['stock_status'] );
			$this->assertIsInt( $row['stock_quantity'] );
		}
	}

	/**
	 * The output never leaks the raw extended_info block or other raw row fields.
	 */
	public function test_output_drops_extended_info(): void {
		$this->seedCompletedVariationOrder();

		$result = wp_get_ability( self::ABILITY )->execute( array( 'extended_info' => true ) );

		$this->assertSame( array( 'items', 'total' ), array_keys( $result ) );
		foreach ( $result['items'] as $row ) {
			$this->assertSame( self::ROW_KEYS, array_keys( $row ) );
			$this->assertArrayNotHasKey( 'extended_info', $row );
			$this->assertArrayNotHasKey( '_links', $row );
			$this->assertArrayNotHasKey( 'image', $row );
			$this->assertArrayNotHasKey( 'permalink', $row );
			$this->assertArrayNotHasKey( 'attributes', $row );
		}
	}

	/**
	 * An after filter is accepted and applied (a future window narrows out all rows).
	 */
	public function test_after_filter_is_accepted(): void {
		$this->seedCompletedVariationOrder();

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
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	/**
	 * Seeds a variable product with one variation, a completed order containing it,
	 * and syncs the Analytics lookup tables synchronously.
	 *
	 * WooCommerce runtime objects are used instead of the `WC_Helper_*` factories,
	 * which the distributed `woocommerce.zip` does not ship. `OrdersScheduler::import()`
	 * runs the same lookup-table syncs the async Action Scheduler would, so the order
	 * is visible to the report within the test (when the variation lookup is wired in
	 * this env). The test asserts envelope shape regardless, so an unsynced variation
	 * does not cause a false failure.
	 *
	 * @return int The seeded variation ID.
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

		$variation = new WC_Product_Variation();
		$variation->set_parent_id( $parent->get_id() );
		$variation->set_attributes( array( 'size' => 'small' ) );
		$variation->set_regular_price( '12.00' );
		$variation->set_manage_stock( true );
		$variation->set_stock_quantity( 5 );
		$variation->save();

		// Re-load so the parent picks up its child variation.
		$parent = new WC_Product_Variable( $parent->get_id() );
		$parent->save();

		$order = new WC_Order();
		$order->add_product( wc_get_product( $variation->get_id() ), 2 );
		$order->set_status( 'completed' );
		$order->calculate_totals();
		$order->save();

		OrdersScheduler::import( $order->get_id() );

		return (int) $variation->get_id();
	}
}
