<?php
/**
 * Integration tests for the og-wc-orders/add-order-note ability.
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
 * Exercises og-wc-orders/add-order-note: the happy-path private note returning a shaped
 * note row (id > 0, the note text, customer_note false) plus the parent order_id,
 * the customer_note=true path proving the documented flag, the missing-order 404
 * that must not collapse to a permission error, the wrong-capability denial, and the
 * exact closed output shape (no raw comment fields leak).
 */
final class AddOrderNoteTest extends TestCase {

	/**
	 * The closed key set the ability returns: order_id plus the OrderNoteListShaper row.
	 *
	 * @var list<string>
	 */
	private const EXPECTED_KEYS = array(
		'order_id',
		'id',
		'author',
		'note',
		'customer_note',
		'date_created',
	);

	public function test_ability_is_registered(): void {
		$ability = wp_get_ability( 'og-wc-orders/add-order-note' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'og-wc-orders/add-order-note', $ability->get_name() );
	}

	public function test_admin_adds_private_note(): void {
		$this->actingAs( 'administrator' );

		$order_id = $this->seedOrder();

		$result = wp_get_ability( 'og-wc-orders/add-order-note' )->execute(
			array(
				'order_id' => $order_id,
				'note'     => 'Test note',
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( $order_id, $result['order_id'] );
		$this->assertGreaterThan( 0, $result['id'] );
		$this->assertSame( 'Test note', $result['note'] );
		$this->assertFalse( $result['customer_note'] );
		$this->assertIsString( $result['author'] );
		$this->assertIsString( $result['date_created'] );

		// The note was actually persisted on the order.
		$notes = wc_get_order_notes( array( 'order_id' => $order_id ) );
		$ids   = array_map( static fn( $n ) => (int) $n->id, $notes );
		$this->assertContains( $result['id'], $ids );
	}

	public function test_customer_note_flag_is_honored(): void {
		$this->actingAs( 'administrator' );

		$order_id = $this->seedOrder();

		// customer_note=true would email the customer on a real site; here we only
		// assert the resulting flag, not any actual mail.
		$result = wp_get_ability( 'og-wc-orders/add-order-note' )->execute(
			array(
				'order_id'      => $order_id,
				'note'          => 'Your order has shipped.',
				'customer_note' => true,
			)
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['customer_note'] );
	}

	public function test_output_shape_is_exact_and_closed(): void {
		$this->actingAs( 'administrator' );

		$order_id = $this->seedOrder();

		$result = wp_get_ability( 'og-wc-orders/add-order-note' )->execute(
			array(
				'order_id' => $order_id,
				'note'     => 'Another note',
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

	public function test_missing_order_returns_404_not_permission_error(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'og-wc-orders/add-order-note' )->execute(
			array(
				'order_id' => 99999999,
				'note'     => 'Orphan note',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'woocommerce_rest_order_invalid_id', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] );
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_missing_required_note_is_rejected_at_input_validation(): void {
		$this->actingAs( 'administrator' );

		$order_id = $this->seedOrder();

		$result = wp_get_ability( 'og-wc-orders/add-order-note' )->execute(
			array( 'order_id' => $order_id )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_input', $result->get_error_code() );
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_subscriber_is_denied(): void {
		$order_id = $this->seedOrder();

		$this->actingAs( 'subscriber' );

		$ability = wp_get_ability( 'og-wc-orders/add-order-note' );

		$this->assertFalse(
			$ability->check_permissions(
				array(
					'order_id' => $order_id,
					'note'     => 'Nope',
				)
			)
		);

		$result = $ability->execute(
			array(
				'order_id' => $order_id,
				'note'     => 'Nope',
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
}
