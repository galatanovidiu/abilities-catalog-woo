<?php
/**
 * Integration tests for the `wc-orders/list-order-notes` ability.
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
 * @covers \GalatanOvidiu\AbilitiesCatalogWoo\Abilities\Woo\Orders\ListOrderNotes
 */
final class ListOrderNotesTest extends TestCase {

	private const ABILITY = 'wc-orders/list-order-notes';

	/**
	 * The exact keys a shaped order-note summary row exposes.
	 *
	 * Asserting against this fixed set proves the raw note body (including
	 * date_created_gmt and _links) is never leaked: only these projected fields
	 * reach the consumer.
	 *
	 * @var list<string>
	 */
	private const ROW_KEYS = array(
		'id',
		'author',
		'note',
		'customer_note',
		'date_created',
	);

	public function test_registered(): void {
		$ability = wp_get_ability( self::ABILITY );

		$this->assertNotNull( $ability );
		$this->assertSame( self::ABILITY, $ability->get_name() );
	}

	public function test_happy_path_returns_shaped_rows(): void {
		$this->actingAs( 'administrator' );
		$order = $this->seedOrder();
		$order->add_order_note( 'Test note' );

		$result = wp_get_ability( self::ABILITY )->execute( array( 'order_id' => $order->get_id() ) );

		$this->assertIsArray( $result );
		$this->assertSame( array( 'order_id', 'items', 'total' ), array_keys( $result ) );
		$this->assertSame( $order->get_id(), $result['order_id'] );
		$this->assertIsArray( $result['items'] );
		$this->assertIsInt( $result['total'] );
		$this->assertGreaterThanOrEqual( 1, $result['total'] );
		$this->assertNotEmpty( $result['items'] );

		$notes = wp_list_pluck( $result['items'], 'note' );
		$this->assertContains( 'Test note', $notes );

		$row = $result['items'][0];
		$this->assertSame( self::ROW_KEYS, array_keys( $row ) );
		$this->assertIsInt( $row['id'] );
		$this->assertIsString( $row['author'] );
		$this->assertIsString( $row['note'] );
		$this->assertIsBool( $row['customer_note'] );
		$this->assertIsString( $row['date_created'] );
	}

	public function test_total_counts_returned_rows(): void {
		$this->actingAs( 'administrator' );
		$order = $this->seedOrder();
		$order->add_order_note( 'First note' );
		$order->add_order_note( 'Second note' );

		$result = wp_get_ability( self::ABILITY )->execute( array( 'order_id' => $order->get_id() ) );

		$this->assertSame( count( $result['items'] ), $result['total'] );
		$this->assertGreaterThanOrEqual( 2, $result['total'] );
	}

	public function test_missing_order_returns_404_not_permission_collapse(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( self::ABILITY )->execute( array( 'order_id' => 99999999 ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'woocommerce_rest_shop_order_invalid_id', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] );
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_missing_required_order_id_is_rejected(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( self::ABILITY )->execute( array() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_input', $result->get_error_code() );
	}

	public function test_output_shape_has_no_raw_note_fields(): void {
		$this->actingAs( 'administrator' );
		$order = $this->seedOrder();
		$order->add_order_note( 'Test note' );

		$result = wp_get_ability( self::ABILITY )->execute( array( 'order_id' => $order->get_id() ) );

		$this->assertSame( array( 'order_id', 'items', 'total' ), array_keys( $result ) );
		$row = $result['items'][0];
		$this->assertSame( self::ROW_KEYS, array_keys( $row ) );
		$this->assertArrayNotHasKey( 'date_created_gmt', $row );
		$this->assertArrayNotHasKey( '_links', $row );
	}

	public function test_wrong_capability_is_denied(): void {
		$order = $this->seedOrder();
		$order->add_order_note( 'Test note' );
		$this->actingAs( 'subscriber' );

		$result = wp_get_ability( self::ABILITY )->execute( array( 'order_id' => $order->get_id() ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_logged_out_user_is_denied(): void {
		$order = $this->seedOrder();
		$order->add_order_note( 'Test note' );
		wp_set_current_user( 0 );

		$result = wp_get_ability( self::ABILITY )->execute( array( 'order_id' => $order->get_id() ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	/**
	 * Seeds a saved order with one line item and returns the order object.
	 *
	 * Builds the order with WooCommerce's runtime object API (WC_Order /
	 * WC_Product_Simple) rather than the WC_Helper_Order test factory, because the
	 * test environment mounts the distributed WooCommerce build, which ships no
	 * tests/ helper framework.
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
