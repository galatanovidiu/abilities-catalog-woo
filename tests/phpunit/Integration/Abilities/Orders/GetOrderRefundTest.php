<?php
/**
 * Integration tests for the wc-orders/get-order-refund ability.
 *
 * @package AbilitiesCatalogWoo\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogWoo\Tests\Integration\Abilities\Orders;

use GalatanOvidiu\AbilitiesCatalogWoo\Abilities\Woo\Orders\GetOrderRefund;
use GalatanOvidiu\AbilitiesCatalogWoo\Tests\TestCase;
use WP_Error;

/**
 * Exercises wc-orders/get-order-refund: the shaped single-refund read, the
 * missing-refund 404 that must not collapse to a permission error, the wrong-cap
 * denial, and the closed output shape (no raw refund fields or meta_data leak).
 */
final class GetOrderRefundTest extends TestCase {

	/**
	 * The exact, closed output key set: the refund summary fields.
	 *
	 * @var array<int,string>
	 */
	private const OUTPUT_KEYS = array(
		'id',
		'amount',
		'reason',
		'date_created',
		'refunded_by',
	);

	public function test_ability_is_registered(): void {
		$ability = wp_get_ability( 'wc-orders/get-order-refund' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'wc-orders/get-order-refund', $ability->get_name() );
	}

	public function test_admin_reads_a_single_refund(): void {
		$this->actingAs( 'administrator' );
		[ $order_id, $refund_id ] = $this->seedOrderWithRefund();

		$result = wp_get_ability( 'wc-orders/get-order-refund' )->execute(
			array(
				'order_id' => $order_id,
				'id'       => $refund_id,
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( self::OUTPUT_KEYS, array_keys( $result ) );
		$this->assertSame( $refund_id, $result['id'] );
		$this->assertIsString( $result['amount'] );
		$this->assertIsString( $result['reason'] );
		$this->assertIsString( $result['date_created'] );
		$this->assertIsInt( $result['refunded_by'] );
	}

	public function test_output_does_not_leak_raw_refund_fields(): void {
		$this->actingAs( 'administrator' );
		[ $order_id, $refund_id ] = $this->seedOrderWithRefund();

		$result = wp_get_ability( 'wc-orders/get-order-refund' )->execute(
			array(
				'order_id' => $order_id,
				'id'       => $refund_id,
			)
		);

		// Raw wc/v3 fields the shaper deliberately strips must not appear.
		$this->assertArrayNotHasKey( '_links', $result );
		$this->assertArrayNotHasKey( 'meta_data', $result );
		$this->assertArrayNotHasKey( 'line_items', $result );
		$this->assertArrayNotHasKey( 'refunded_payment', $result );
		$this->assertArrayNotHasKey( 'date_created_gmt', $result );
	}

	public function test_missing_refund_returns_404_not_permission_error(): void {
		$this->actingAs( 'administrator' );
		[ $order_id ] = $this->seedOrderWithRefund();

		$result = wp_get_ability( 'wc-orders/get-order-refund' )->execute(
			array(
				'order_id' => $order_id,
				'id'       => 99999999,
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'woocommerce_rest_shop_order_refund_invalid_id', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] );
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_refund_under_wrong_order_returns_404(): void {
		$this->actingAs( 'administrator' );
		[ , $refund_id ]  = $this->seedOrderWithRefund();
		[ $other_order ]  = $this->seedOrderWithRefund();

		$result = wp_get_ability( 'wc-orders/get-order-refund' )->execute(
			array(
				'order_id' => $other_order,
				'id'       => $refund_id,
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'woocommerce_rest_invalid_order_refund_id', $result->get_error_code() );
		// WC builds this error with a bare int as the third WP_Error arg, so
		// get_error_data() returns the int 404 itself, not array( 'status' => 404 ).
		$this->assertSame( 404, $result->get_error_data() );
	}

	public function test_subscriber_is_denied(): void {
		[ $order_id, $refund_id ] = $this->seedOrderWithRefund();
		$this->actingAs( 'subscriber' );

		$this->assertFalse( ( new GetOrderRefund() )->hasPermission( array() ) );

		$result = wp_get_ability( 'wc-orders/get-order-refund' )->execute(
			array(
				'order_id' => $order_id,
				'id'       => $refund_id,
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	/**
	 * Seeds a processing order with one line item and one refund.
	 *
	 * Built with WooCommerce's runtime object API (WC_Product_Simple, WC_Order)
	 * and wc_create_refund() rather than the WC_Helper_* test factories, because
	 * the test environment mounts the distributed WooCommerce build, which ships
	 * no tests/ helper framework. The refund carries a reason so the reason field
	 * under test is non-empty.
	 *
	 * @return array{0:int,1:int} The [order_id, refund_id] pair.
	 */
	private function seedOrderWithRefund(): array {
		$product = new \WC_Product_Simple();
		$product->set_name( 'Seeded Product' );
		$product->set_regular_price( '10.00' );
		$product->save();

		$order = new \WC_Order();
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

		$refund = wc_create_refund(
			array(
				'order_id' => $order->get_id(),
				'amount'   => 5,
				'reason'   => 'Test refund',
			)
		);

		return array( (int) $order->get_id(), (int) $refund->get_id() );
	}
}
