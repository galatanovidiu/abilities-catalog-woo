<?php
/**
 * Integration tests for the `wc-orders/list-orders` ability.
 *
 * @package AbilitiesCatalogWoo\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogWoo\Tests\Integration\Abilities\Orders;

use GalatanOvidiu\AbilitiesCatalogWoo\Tests\TestCase;
use WC_Order;
use WC_Product_Simple;
use WP_Error;

/**
 * @covers \GalatanOvidiu\AbilitiesCatalogWoo\Abilities\Woo\Orders\ListOrders
 */
final class ListOrdersTest extends TestCase {

	private const ABILITY = 'wc-orders/list-orders';

	/**
	 * The exact keys a shaped order summary row exposes.
	 *
	 * Asserting against this fixed set proves the raw ~100-field order body
	 * (including meta_data, the customer IP, and _links) is never leaked: only
	 * these projected fields reach the consumer.
	 *
	 * @var list<string>
	 */
	private const ROW_KEYS = array(
		'id',
		'number',
		'status',
		'currency',
		'total',
		'total_tax',
		'date_created',
		'customer_id',
		'billing_first_name',
		'billing_last_name',
		'billing_email',
		'payment_method_title',
		'line_items_count',
	);

	public function test_registered(): void {
		$ability = wp_get_ability( self::ABILITY );

		$this->assertNotNull( $ability );
		$this->assertSame( self::ABILITY, $ability->get_name() );
	}

	public function test_happy_path_returns_shaped_rows(): void {
		$this->actingAs( 'administrator' );
		$this->seedOrder();
		$this->seedOrder();

		$result = wp_get_ability( self::ABILITY )->execute( array() );

		$this->assertIsArray( $result );
		$this->assertSame( array( 'items', 'total' ), array_keys( $result ) );
		$this->assertIsArray( $result['items'] );
		$this->assertIsInt( $result['total'] );
		$this->assertGreaterThanOrEqual( 2, $result['total'] );
		$this->assertNotEmpty( $result['items'] );

		$row = $result['items'][0];
		$this->assertSame( self::ROW_KEYS, array_keys( $row ) );
		$this->assertIsInt( $row['id'] );
		$this->assertIsString( $row['number'] );
		$this->assertIsString( $row['total'] );
		$this->assertIsInt( $row['customer_id'] );
		$this->assertIsInt( $row['line_items_count'] );
	}

	public function test_status_filter_narrows_results(): void {
		$this->actingAs( 'administrator' );

		$processing = $this->seedOrder();
		$processing->set_status( 'processing' );
		$processing->save();

		$completed = $this->seedOrder();
		$completed->set_status( 'completed' );
		$completed->save();

		$result = wp_get_ability( self::ABILITY )->execute( array( 'status' => 'completed' ) );

		$ids = wp_list_pluck( $result['items'], 'id' );
		$this->assertContains( $completed->get_id(), $ids );
		$this->assertNotContains( $processing->get_id(), $ids );

		foreach ( $result['items'] as $row ) {
			$this->assertSame( 'completed', $row['status'] );
		}
	}

	public function test_total_comes_from_the_pagination_header(): void {
		$this->actingAs( 'administrator' );

		for ( $i = 0; $i < 3; $i++ ) {
			$this->seedOrder();
		}

		$result = wp_get_ability( self::ABILITY )->execute( array( 'per_page' => 1 ) );

		// One row on the page, but total reflects the full matching set via X-WP-Total.
		$this->assertCount( 1, $result['items'] );
		$this->assertGreaterThanOrEqual( 3, $result['total'] );
	}

	public function test_output_shape_has_no_raw_order_fields(): void {
		$this->actingAs( 'administrator' );
		$this->seedOrder();

		$result = wp_get_ability( self::ABILITY )->execute( array() );

		$this->assertSame( array( 'items', 'total' ), array_keys( $result ) );
		$row = $result['items'][0];
		$this->assertSame( self::ROW_KEYS, array_keys( $row ) );
		$this->assertArrayNotHasKey( 'meta_data', $row );
		$this->assertArrayNotHasKey( 'billing', $row );
		$this->assertArrayNotHasKey( 'shipping', $row );
		$this->assertArrayNotHasKey( 'line_items', $row );
		$this->assertArrayNotHasKey( '_links', $row );
	}

	public function test_wrong_capability_is_denied(): void {
		$this->seedOrder();
		$this->actingAs( 'subscriber' );

		$result = wp_get_ability( self::ABILITY )->execute( array() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_logged_out_user_is_denied(): void {
		$this->seedOrder();
		wp_set_current_user( 0 );

		$result = wp_get_ability( self::ABILITY )->execute( array() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	/**
	 * Seeds a saved order with one line item and returns the order object.
	 *
	 * Builds the order with WooCommerce's runtime object API (WC_Order /
	 * WC_Product_Simple) rather than the WC_Helper_Order test factory, because the
	 * test environment mounts the distributed WooCommerce build, which ships no
	 * tests/ helper framework. The object is returned so callers can mutate it
	 * (set_status) and re-save.
	 *
	 * @return WC_Order The created order.
	 */
	private function seedOrder(): WC_Order {
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
		$order->set_status( 'processing' );
		$order->calculate_totals();
		$order->save();

		return $order;
	}
}
