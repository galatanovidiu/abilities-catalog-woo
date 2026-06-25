<?php
/**
 * Integration tests for the og-wc-orders/delete-order-refund ability.
 *
 * @package AbilitiesCatalogWoo\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogWoo\Tests\Integration\Abilities\Orders;

use GalatanOvidiu\AbilitiesCatalogWoo\Abilities\Woo\Orders\DeleteOrderRefund;
use GalatanOvidiu\AbilitiesCatalogWoo\Tests\TestCase;
use WP_Error;

/**
 * Exercises og-wc-orders/delete-order-refund: the seed-then-delete happy path (the
 * refund record is permanently gone, the amount is captured, permanent is true),
 * the missing-refund and missing-order 404s that must not collapse to a permission
 * error, the wrong-cap denial, and the closed output shape (no edit_link, no raw
 * refund fields leak).
 */
final class DeleteOrderRefundTest extends TestCase {

	/**
	 * The exact, closed output key set.
	 *
	 * @var array<int,string>
	 */
	private const OUTPUT_KEYS = array(
		'deleted',
		'order_id',
		'id',
		'amount',
		'force_used',
		'permanent',
	);

	public function test_ability_is_registered(): void {
		$ability = wp_get_ability( 'og-wc-orders/delete-order-refund' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'og-wc-orders/delete-order-refund', $ability->get_name() );
	}

	public function test_admin_deletes_a_refund_permanently(): void {
		$this->actingAs( 'administrator' );
		[ $order_id, $refund_id ] = $this->seedOrderWithRefund();

		$result = wp_get_ability( 'og-wc-orders/delete-order-refund' )->execute(
			array(
				'order_id' => $order_id,
				'id'       => $refund_id,
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( self::OUTPUT_KEYS, array_keys( $result ) );
		$this->assertTrue( $result['deleted'] );
		$this->assertSame( $order_id, $result['order_id'] );
		$this->assertSame( $refund_id, $result['id'] );
		$this->assertIsString( $result['amount'] );
		$this->assertNotSame( '', $result['amount'] );
		$this->assertTrue( $result['force_used'] );
		$this->assertTrue( $result['permanent'] );

		// Prove the refund record is gone via the DB primitive, not the cached
		// wc_get_order() family (which can return a stale object in-request after a
		// route delete). A refund is a shop_order_refund post.
		clean_post_cache( $refund_id );
		$this->assertNull( get_post( $refund_id ) );
	}

	public function test_output_does_not_leak_raw_refund_fields_or_edit_link(): void {
		$this->actingAs( 'administrator' );
		[ $order_id, $refund_id ] = $this->seedOrderWithRefund();

		$result = wp_get_ability( 'og-wc-orders/delete-order-refund' )->execute(
			array(
				'order_id' => $order_id,
				'id'       => $refund_id,
			)
		);

		$this->assertIsArray( $result );
		$this->assertArrayNotHasKey( 'edit_link', $result );
		$this->assertArrayNotHasKey( '_links', $result );
		$this->assertArrayNotHasKey( 'meta_data', $result );
		$this->assertArrayNotHasKey( 'line_items', $result );
		$this->assertArrayNotHasKey( 'refunded_payment', $result );
	}

	public function test_missing_refund_returns_404_not_permission_error(): void {
		$this->actingAs( 'administrator' );
		[ $order_id ] = $this->seedOrderWithRefund();

		$result = wp_get_ability( 'og-wc-orders/delete-order-refund' )->execute(
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

	public function test_missing_parent_order_returns_404(): void {
		$this->actingAs( 'administrator' );
		[ , $refund_id ] = $this->seedOrderWithRefund();

		$result = wp_get_ability( 'og-wc-orders/delete-order-refund' )->execute(
			array(
				'order_id' => 99999999,
				'id'       => $refund_id,
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'woocommerce_rest_invalid_order_id', $result->get_error_code() );
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_missing_required_id_is_rejected(): void {
		$this->actingAs( 'administrator' );
		[ $order_id ] = $this->seedOrderWithRefund();

		$result = wp_get_ability( 'og-wc-orders/delete-order-refund' )->execute(
			array(
				'order_id' => $order_id,
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_input', $result->get_error_code() );
	}

	public function test_subscriber_is_denied(): void {
		[ $order_id, $refund_id ] = $this->seedOrderWithRefund();
		$this->actingAs( 'subscriber' );

		$this->assertFalse( ( new DeleteOrderRefund() )->hasPermission( array() ) );

		$result = wp_get_ability( 'og-wc-orders/delete-order-refund' )->execute(
			array(
				'order_id' => $order_id,
				'id'       => $refund_id,
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );

		// The refund must survive the denied attempt.
		clean_post_cache( $refund_id );
		$this->assertNotNull( get_post( $refund_id ) );
	}

	/**
	 * Seeds a processing order with one line item and one refund.
	 *
	 * Built with WooCommerce's runtime object API (WC_Product_Simple, WC_Order)
	 * and wc_create_refund() rather than the WC_Helper_* test factories, because
	 * the test environment mounts the distributed WooCommerce build, which ships
	 * no tests/ helper framework.
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
