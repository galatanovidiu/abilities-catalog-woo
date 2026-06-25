<?php
/**
 * Integration tests for the og-wc-orders/update-order ability.
 *
 * @package AbilitiesCatalogWoo\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogWoo\Tests\Integration\Abilities\Orders;

use GalatanOvidiu\AbilitiesCatalogWoo\Tests\TestCase;
use WP_Error;
use WC_Order;
use WC_Product_Simple;

/**
 * Exercises og-wc-orders/update-order: a field change on a seeded order, the
 * missing-order invalid-id error that must not collapse to a permission error,
 * the wrong-capability denial (with the order unchanged), the absence of a
 * `status` input param (status changes are a separate ability), and the exact
 * closed output shape (no raw order / meta_data leak).
 */
final class UpdateOrderTest extends TestCase {

	/**
	 * The full closed key set the ability returns: the get-order detail shape.
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
		'line_items',
		'billing',
		'shipping',
		'edit_link',
	);

	public function test_ability_is_registered(): void {
		$ability = wp_get_ability( 'og-wc-orders/update-order' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'og-wc-orders/update-order', $ability->get_name() );
	}

	public function test_admin_updates_a_billing_field(): void {
		$this->actingAs( 'administrator' );

		$id = $this->seedOrder();

		$result = wp_get_ability( 'og-wc-orders/update-order' )->execute(
			array(
				'id'      => $id,
				'billing' => array(
					'first_name' => 'Changed',
				),
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( $id, $result['id'] );
		$this->assertSame( 'Changed', $result['billing']['first_name'] );
		$this->assertSame( 'Changed', $result['billing_first_name'] );

		// The change persisted to the live order.
		$reloaded = wc_get_order( $id );
		$this->assertSame( 'Changed', $reloaded->get_billing_first_name() );
	}

	public function test_output_shape_is_exact_and_closed(): void {
		$this->actingAs( 'administrator' );

		$id = $this->seedOrder();

		$result = wp_get_ability( 'og-wc-orders/update-order' )->execute(
			array(
				'id'      => $id,
				'billing' => array(
					'first_name' => 'Changed',
				),
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( self::EXPECTED_KEYS, array_keys( $result ) );

		// No raw ~100-field order fields leak through.
		$this->assertArrayNotHasKey( 'meta_data', $result );
		$this->assertArrayNotHasKey( '_links', $result );
		$this->assertArrayNotHasKey( 'customer_ip_address', $result );
	}

	public function test_input_schema_has_no_status_param(): void {
		// Status changes go through og-wc-orders/update-order-status (batch 23); the
		// writable subset must structurally exclude status.
		$ability = wp_get_ability( 'og-wc-orders/update-order' );
		$schema  = $ability->get_input_schema();

		$this->assertArrayNotHasKey( 'status', $schema['properties'] );
	}

	public function test_missing_order_returns_invalid_id_not_permission_error(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'og-wc-orders/update-order' )->execute(
			array(
				'id'      => 99999999,
				'billing' => array(
					'first_name' => 'Changed',
				),
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'woocommerce_rest_shop_order_invalid_id', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] );
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_subscriber_is_denied_and_order_unchanged(): void {
		$id = $this->seedOrder();

		$this->actingAs( 'subscriber' );

		$ability = wp_get_ability( 'og-wc-orders/update-order' );

		$this->assertFalse(
			$ability->check_permissions(
				array(
					'id'      => $id,
					'billing' => array( 'first_name' => 'Changed' ),
				)
			)
		);

		$result = $ability->execute(
			array(
				'id'      => $id,
				'billing' => array( 'first_name' => 'Changed' ),
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );

		// The denied write did not change the order.
		$this->assertSame( 'Ada', wc_get_order( $id )->get_billing_first_name() );
	}

	/**
	 * Seeds a processing order with one line item and a billing/shipping address,
	 * returning its ID.
	 *
	 * Builds the order with WooCommerce's runtime object API (WC_Order /
	 * WC_Product_Simple) rather than the WC_Helper_Order test factory, because the
	 * test environment mounts the distributed WooCommerce build, which ships no
	 * tests/ helper framework.
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

		return (int) $order->save();
	}
}
