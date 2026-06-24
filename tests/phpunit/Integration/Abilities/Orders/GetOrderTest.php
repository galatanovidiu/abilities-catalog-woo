<?php
/**
 * Integration tests for the wc-orders/get-order ability.
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
 * Exercises wc-orders/get-order: the shaped single-order record, the detail
 * fields (line items, billing/shipping blocks, edit_link), the missing-order 404
 * that must not collapse to a permission error, the wrong-capability denial, and
 * the exact closed output shape with no PII leak beyond the documented subset.
 */
final class GetOrderTest extends TestCase {

	/**
	 * The full closed key set the ability returns: the summary fields plus the
	 * single-order detail fields, in order.
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

	/**
	 * The closed key set of the billing block.
	 *
	 * @var list<string>
	 */
	private const BILLING_KEYS = array(
		'first_name',
		'last_name',
		'email',
		'address_1',
		'city',
		'state',
		'postcode',
		'country',
	);

	/**
	 * The closed key set of the shipping block (no email).
	 *
	 * @var list<string>
	 */
	private const SHIPPING_KEYS = array(
		'first_name',
		'last_name',
		'address_1',
		'city',
		'state',
		'postcode',
		'country',
	);

	public function test_ability_is_registered(): void {
		$ability = wp_get_ability( 'wc-orders/get-order' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'wc-orders/get-order', $ability->get_name() );
	}

	public function test_admin_reads_order_detail(): void {
		$this->actingAs( 'administrator' );

		$order_id = $this->seedOrder();

		$result = wp_get_ability( 'wc-orders/get-order' )->execute( array( 'id' => $order_id ) );

		$this->assertIsArray( $result );
		$this->assertSame( $order_id, $result['id'] );
		$this->assertSame( 'processing', $result['status'] );
		$this->assertIsString( $result['total'] );
		$this->assertSame( 'ada@example.org', $result['billing_email'] );

		// Line items project to flat { id, name, product_id, quantity, total } rows.
		$this->assertIsArray( $result['line_items'] );
		$this->assertCount( 1, $result['line_items'] );
		$this->assertSame(
			array( 'id', 'name', 'product_id', 'quantity', 'total' ),
			array_keys( $result['line_items'][0] )
		);
		$this->assertSame( 'Seeded Product', $result['line_items'][0]['name'] );
		$this->assertSame( 2, $result['line_items'][0]['quantity'] );

		// Billing/shipping blocks are closed to the documented PII subset.
		$this->assertSame( self::BILLING_KEYS, array_keys( $result['billing'] ) );
		$this->assertSame( self::SHIPPING_KEYS, array_keys( $result['shipping'] ) );
		$this->assertSame( 'Ada', $result['billing']['first_name'] );
		$this->assertSame( 'ada@example.org', $result['billing']['email'] );

		$this->assertStringContainsString( 'post.php?post=' . $order_id, $result['edit_link'] );
	}

	public function test_output_shape_is_exact_and_closed(): void {
		$this->actingAs( 'administrator' );

		$order_id = $this->seedOrder();

		$result = wp_get_ability( 'wc-orders/get-order' )->execute( array( 'id' => $order_id ) );

		$this->assertIsArray( $result );
		$this->assertSame( self::EXPECTED_KEYS, array_keys( $result ) );

		// No raw ~100-field order fields or PII beyond the subset leak through.
		$this->assertArrayNotHasKey( 'meta_data', $result );
		$this->assertArrayNotHasKey( '_links', $result );
		$this->assertArrayNotHasKey( 'customer_ip_address', $result );

		// The shipping block carries no email (the WC shipping address has none).
		$this->assertArrayNotHasKey( 'email', $result['shipping'] );
	}

	public function test_missing_order_returns_404_not_permission_error(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'wc-orders/get-order' )->execute( array( 'id' => 99999999 ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'woocommerce_rest_shop_order_invalid_id', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] );
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_subscriber_is_denied(): void {
		$order_id = $this->seedOrder();
		$this->actingAs( 'subscriber' );

		$ability = wp_get_ability( 'wc-orders/get-order' );

		$this->assertFalse( $ability->check_permissions( array( 'id' => $order_id ) ) );

		$result = $ability->execute( array( 'id' => $order_id ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	/**
	 * Seeds a saved order with one line item and a billing/shipping address.
	 *
	 * Builds the order with WooCommerce's runtime object API (WC_Order,
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
		$order->set_address(
			array(
				'first_name' => 'Ada',
				'last_name'  => 'Lovelace',
				'address_1'  => '1 Test St',
				'city'       => 'Testville',
				'state'      => 'CA',
				'postcode'   => '90210',
				'country'    => 'US',
			),
			'shipping'
		);
		$order->set_status( 'processing' );
		$order->calculate_totals();

		return (int) $order->save();
	}
}
