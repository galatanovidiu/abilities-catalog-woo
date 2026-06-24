<?php
/**
 * Integration tests for the wc-orders/update-order-status ability.
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
 * Exercises wc-orders/update-order-status: the happy-path pending->processing
 * transition returning the shaped order with status and previous_status, the real
 * status-transition side effect firing (the woocommerce_order_status_changed hook),
 * the invalid-status 400 surfaced via RestError (not a permission collapse), the
 * missing-order invalid-id 400, the wrong-capability denial (order unchanged), and
 * the exact closed output shape (no raw order / meta_data leak).
 */
final class UpdateOrderStatusTest extends TestCase {

	/**
	 * The closed key set the ability returns: the OrderListShaper summary row plus
	 * previous_status.
	 *
	 * @var list<string>
	 */
	private const EXPECTED_KEYS = array(
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
		'previous_status',
	);

	public function test_ability_is_registered(): void {
		$ability = wp_get_ability( 'wc-orders/update-order-status' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'wc-orders/update-order-status', $ability->get_name() );
	}

	public function test_admin_changes_status_and_sees_transition(): void {
		$this->actingAs( 'administrator' );

		$id = $this->seedOrder();

		$result = wp_get_ability( 'wc-orders/update-order-status' )->execute(
			array(
				'id'     => $id,
				'status' => 'processing',
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( $id, $result['id'] );
		$this->assertSame( 'processing', $result['status'] );
		$this->assertSame( 'pending', $result['previous_status'] );

		// The change persisted to the live order.
		$this->assertSame( 'processing', wc_get_order( $id )->get_status() );
	}

	public function test_paid_status_transition_fires_status_change_side_effect(): void {
		$this->actingAs( 'administrator' );

		$id = $this->seedOrder();

		// Capture the real status-transition handler that WC_Order::save() runs;
		// this is the same hook that drives stock reduction, payment_complete, and
		// the paid-order customer emails for a paid status.
		$transition = array();
		add_action(
			'woocommerce_order_status_changed',
			static function ( $order_id, $from, $to ) use ( &$transition ): void {
				$transition[] = array(
					'order_id' => (int) $order_id,
					'from'     => (string) $from,
					'to'       => (string) $to,
				);
			},
			10,
			3
		);

		wp_get_ability( 'wc-orders/update-order-status' )->execute(
			array(
				'id'     => $id,
				'status' => 'processing',
			)
		);

		$this->assertNotEmpty( $transition, 'A paid status transition must fire woocommerce_order_status_changed.' );
		$this->assertSame( $id, $transition[0]['order_id'] );
		$this->assertSame( 'pending', $transition[0]['from'] );
		$this->assertSame( 'processing', $transition[0]['to'] );
	}

	public function test_output_shape_is_exact_and_closed(): void {
		$this->actingAs( 'administrator' );

		$id = $this->seedOrder();

		$result = wp_get_ability( 'wc-orders/update-order-status' )->execute(
			array(
				'id'     => $id,
				'status' => 'processing',
			)
		);

		$this->assertIsArray( $result );

		$keys = array_keys( $result );
		sort( $keys );
		$expected = self::EXPECTED_KEYS;
		sort( $expected );
		$this->assertSame( $expected, $keys );

		// No raw ~100-field order fields leak through.
		$this->assertArrayNotHasKey( 'meta_data', $result );
		$this->assertArrayNotHasKey( '_links', $result );
		$this->assertArrayNotHasKey( 'customer_ip_address', $result );
		$this->assertArrayNotHasKey( 'billing', $result );
	}

	public function test_invalid_status_is_rejected_at_input_validation(): void {
		// A status not in the enum is killed by schema validation in the wrapper,
		// before execute() runs — so it surfaces as ability_invalid_input, never a
		// permission collapse, and the order is untouched.
		$this->actingAs( 'administrator' );

		$id = $this->seedOrder();

		$result = wp_get_ability( 'wc-orders/update-order-status' )->execute(
			array(
				'id'     => $id,
				'status' => 'not-a-real-status',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_input', $result->get_error_code() );
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code() );

		// The denied write did not change the order.
		$this->assertSame( 'pending', wc_get_order( $id )->get_status() );
	}

	public function test_missing_order_returns_invalid_id_not_permission_error(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'wc-orders/update-order-status' )->execute(
			array(
				'id'     => 99999999,
				'status' => 'processing',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'woocommerce_rest_shop_order_invalid_id', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] );
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_subscriber_is_denied_and_order_unchanged(): void {
		$id = $this->seedOrder();

		$this->actingAs( 'subscriber' );

		$ability = wp_get_ability( 'wc-orders/update-order-status' );

		$this->assertFalse(
			$ability->check_permissions(
				array(
					'id'     => $id,
					'status' => 'processing',
				)
			)
		);

		$result = $ability->execute(
			array(
				'id'     => $id,
				'status' => 'processing',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );

		// The denied write did not change the order's status.
		$this->assertSame( 'pending', wc_get_order( $id )->get_status() );
	}

	/**
	 * Seeds a saved pending order with one line item via the WooCommerce runtime
	 * object API and returns its ID. The distributed woocommerce.zip ships no test
	 * framework, so the WC_Helper_* factories do not exist; the runtime classes
	 * always load with the plugin.
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
		$order->set_status( 'pending' );
		$order->calculate_totals();
		$order->save();

		return (int) $order->get_id();
	}
}
