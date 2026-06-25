<?php
/**
 * Integration tests for the og-wc-orders/delete-order ability.
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
 * Exercises og-wc-orders/delete-order: the default force=false Trash path (recoverable,
 * permanent:false), the force=true permanent path (gone, permanent:true), the
 * missing-order 404 that must not collapse to a permission error, the wrong-capability
 * denial, and the exact closed output shape with no edit_link or raw order fields.
 */
final class DeleteOrderTest extends TestCase {

	/**
	 * The full closed key set the ability returns, in order.
	 *
	 * @var list<string>
	 */
	private const EXPECTED_KEYS = array(
		'deleted',
		'id',
		'number',
		'force_used',
		'permanent',
	);

	public function test_ability_is_registered(): void {
		$ability = wp_get_ability( 'og-wc-orders/delete-order' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'og-wc-orders/delete-order', $ability->get_name() );
	}

	public function test_default_force_trashes_order_recoverably(): void {
		if ( ! ( defined( 'EMPTY_TRASH_DAYS' ) && EMPTY_TRASH_DAYS > 0 ) ) {
			$this->markTestSkipped( 'Trash is disabled (EMPTY_TRASH_DAYS = 0); the force=false path is unavailable.' );
		}

		$this->actingAs( 'administrator' );

		$order_id = $this->seedOrder();

		$result = wp_get_ability( 'og-wc-orders/delete-order' )->execute( array( 'id' => $order_id ) );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['deleted'] );
		$this->assertSame( $order_id, $result['id'] );
		$this->assertIsString( $result['number'] );
		$this->assertNotSame( '', $result['number'] );
		$this->assertFalse( $result['force_used'] );
		$this->assertFalse( $result['permanent'] );

		// A trashed order still exists as a post row, with status trash.
		clean_post_cache( $order_id );
		$post = get_post( $order_id );
		$this->assertNotNull( $post );
		$this->assertSame( 'trash', $post->post_status );
	}

	public function test_force_true_deletes_order_permanently(): void {
		$this->actingAs( 'administrator' );

		$order_id = $this->seedOrder();

		$result = wp_get_ability( 'og-wc-orders/delete-order' )->execute(
			array(
				'id'    => $order_id,
				'force' => true,
			)
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['deleted'] );
		$this->assertSame( $order_id, $result['id'] );
		$this->assertTrue( $result['force_used'] );
		$this->assertTrue( $result['permanent'] );

		// A permanently deleted order leaves no post row.
		clean_post_cache( $order_id );
		$this->assertNull( get_post( $order_id ) );
	}

	public function test_output_shape_is_exact_and_closed(): void {
		$this->actingAs( 'administrator' );

		$order_id = $this->seedOrder();

		$result = wp_get_ability( 'og-wc-orders/delete-order' )->execute(
			array(
				'id'    => $order_id,
				'force' => true,
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( self::EXPECTED_KEYS, array_keys( $result ) );

		// A delete must not return a dead-end edit link, and no raw order fields leak.
		$this->assertArrayNotHasKey( 'edit_link', $result );
		$this->assertArrayNotHasKey( 'meta_data', $result );
		$this->assertArrayNotHasKey( '_links', $result );
		$this->assertArrayNotHasKey( 'billing', $result );
	}

	public function test_missing_order_returns_404_not_permission_error(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'og-wc-orders/delete-order' )->execute( array( 'id' => 99999999 ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'woocommerce_rest_shop_order_invalid_id', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] );
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_subscriber_is_denied(): void {
		$order_id = $this->seedOrder();
		$this->actingAs( 'subscriber' );

		$ability = wp_get_ability( 'og-wc-orders/delete-order' );

		$this->assertFalse( $ability->check_permissions( array( 'id' => $order_id ) ) );

		$result = $ability->execute( array( 'id' => $order_id ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );

		// The denied caller did not delete the order.
		clean_post_cache( $order_id );
		$this->assertNotNull( get_post( $order_id ) );
	}

	/**
	 * Seeds a saved order with one line item and a billing address.
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
		$order->set_status( 'processing' );
		$order->calculate_totals();

		return (int) $order->save();
	}
}
