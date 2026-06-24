<?php
/**
 * Integration tests for the wc-orders/get-order-note ability.
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
 * Exercises wc-orders/get-order-note: the shaped single-note row, the missing-note
 * 404 that must not collapse to a permission error, the wrong-capability denial,
 * and the exact closed output shape (no raw comment fields leak).
 */
final class GetOrderNoteTest extends TestCase {

	/**
	 * The closed key set the ability returns for one note row.
	 *
	 * @var list<string>
	 */
	private const EXPECTED_KEYS = array(
		'id',
		'author',
		'note',
		'customer_note',
		'date_created',
	);

	public function test_ability_is_registered(): void {
		$ability = wp_get_ability( 'wc-orders/get-order-note' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'wc-orders/get-order-note', $ability->get_name() );
	}

	public function test_admin_reads_note_detail(): void {
		$this->actingAs( 'administrator' );

		$order_id = $this->seedOrder();
		$note_id  = $this->seedNote( $order_id, 'Test note' );

		$result = wp_get_ability( 'wc-orders/get-order-note' )->execute(
			array(
				'order_id' => $order_id,
				'id'       => $note_id,
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( $note_id, $result['id'] );
		$this->assertSame( 'Test note', $result['note'] );
		$this->assertIsString( $result['author'] );
		$this->assertIsBool( $result['customer_note'] );
		$this->assertIsString( $result['date_created'] );
	}

	public function test_output_shape_is_exact_and_closed(): void {
		$this->actingAs( 'administrator' );

		$order_id = $this->seedOrder();
		$note_id  = $this->seedNote( $order_id, 'Another note' );

		$result = wp_get_ability( 'wc-orders/get-order-note' )->execute(
			array(
				'order_id' => $order_id,
				'id'       => $note_id,
			)
		);

		$this->assertIsArray( $result );

		$keys = array_keys( $result );
		sort( $keys );
		$expected = self::EXPECTED_KEYS;
		sort( $expected );
		$this->assertSame( $expected, $keys );

		// No raw comment fields leak through.
		$this->assertArrayNotHasKey( 'date_created_gmt', $result );
		$this->assertArrayNotHasKey( 'added_by_user', $result );
		$this->assertArrayNotHasKey( '_links', $result );
		$this->assertArrayNotHasKey( 'meta_data', $result );
	}

	public function test_missing_note_returns_404_not_permission_error(): void {
		$this->actingAs( 'administrator' );

		$order_id = $this->seedOrder();

		$result = wp_get_ability( 'wc-orders/get-order-note' )->execute(
			array(
				'order_id' => $order_id,
				'id'       => 99999999,
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'woocommerce_rest_invalid_id', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] );
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_missing_order_returns_404_not_permission_error(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'wc-orders/get-order-note' )->execute(
			array(
				'order_id' => 99999999,
				'id'       => 1,
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'woocommerce_rest_order_invalid_id', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] );
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_subscriber_is_denied(): void {
		$order_id = $this->seedOrder();
		$note_id  = $this->seedNote( $order_id, 'Test note' );

		$this->actingAs( 'subscriber' );

		$ability = wp_get_ability( 'wc-orders/get-order-note' );

		$this->assertFalse(
			$ability->check_permissions(
				array(
					'order_id' => $order_id,
					'id'       => $note_id,
				)
			)
		);

		$result = $ability->execute(
			array(
				'order_id' => $order_id,
				'id'       => $note_id,
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	/**
	 * Seeds a saved order with one line item via the WooCommerce runtime object API
	 * and returns its ID. The distributed woocommerce.zip ships no test framework, so
	 * the WC_Helper_* factories do not exist; the runtime classes always load with
	 * the plugin.
	 *
	 * @return int The created order ID.
	 */
	private function seedOrder(): int {
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

		return (int) $order->get_id();
	}

	/**
	 * Adds an order note to the order and returns the note (comment) ID.
	 *
	 * @param int    $order_id The order to attach the note to.
	 * @param string $note     The note content.
	 * @return int The created note ID.
	 */
	private function seedNote( int $order_id, string $note ): int {
		$order   = wc_get_order( $order_id );
		$note_id = $order->add_order_note( $note );

		return (int) $note_id;
	}
}
