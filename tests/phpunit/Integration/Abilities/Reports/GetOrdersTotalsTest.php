<?php
/**
 * Integration tests for the `og-wc-reports/get-orders-totals` ability.
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
 * @covers \GalatanOvidiu\AbilitiesCatalogWoo\Abilities\Woo\Reports\GetOrdersTotals
 */
final class GetOrdersTotalsTest extends TestCase {

	private const ABILITY = 'og-wc-reports/get-orders-totals';

	/**
	 * The keys a shaped totals row exposes — and nothing more.
	 *
	 * @var list<string>
	 */
	private const ROW_KEYS = array( 'slug', 'name', 'total' );

	public function test_registered(): void {
		$ability = wp_get_ability( self::ABILITY );

		$this->assertNotNull( $ability );
		$this->assertSame( self::ABILITY, $ability->get_name() );
	}

	public function test_happy_path_returns_completed_status_row(): void {
		$this->seedCompletedOrder();
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( self::ABILITY )->execute( array() );

		$this->assertIsArray( $result );
		$this->assertSame( array( 'items', 'total' ), array_keys( $result ) );
		$this->assertIsArray( $result['items'] );
		$this->assertNotEmpty( $result['items'] );
		$this->assertIsInt( $result['total'] );
		$this->assertSame( count( $result['items'] ), $result['total'] );

		$by_slug = array_column( $result['items'], null, 'slug' );
		$this->assertArrayHasKey( 'completed', $by_slug );
		$this->assertIsInt( $by_slug['completed']['total'] );
		$this->assertGreaterThanOrEqual( 1, $by_slug['completed']['total'] );
	}

	public function test_each_row_is_exactly_the_closed_schema(): void {
		$this->seedCompletedOrder();
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( self::ABILITY )->execute( array() );

		$this->assertNotEmpty( $result['items'] );
		foreach ( $result['items'] as $row ) {
			$this->assertSame( self::ROW_KEYS, array_keys( $row ) );
			$this->assertIsString( $row['slug'] );
			$this->assertIsString( $row['name'] );
			$this->assertIsInt( $row['total'] );

			// No raw REST fields leak.
			$this->assertArrayNotHasKey( '_links', $row );
		}
	}

	public function test_output_shape_is_exactly_items_and_total(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( self::ABILITY )->execute( array() );

		$this->assertSame( array( 'items', 'total' ), array_keys( $result ) );
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
	 * Seeds a saved order in the `completed` status, with a line item.
	 *
	 * Uses WooCommerce's runtime object API (`WC_Product_Simple` / `WC_Order`),
	 * never the `WC_Helper_*` test factories — the distributed WooCommerce build
	 * mounted by the test environment ships no tests/ helper framework, so those
	 * classes do not exist. A completed order is required because the orders-totals
	 * report counts an order under its current status.
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
		$order->set_status( 'completed' );
		$order->calculate_totals();
		$order->save();

		return $order->get_id();
	}
}
