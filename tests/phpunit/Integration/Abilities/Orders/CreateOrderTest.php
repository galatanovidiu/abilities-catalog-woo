<?php
/**
 * Integration tests for the wc-orders/create-order ability.
 *
 * @package AbilitiesCatalogWoo\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogWoo\Tests\Integration\Abilities\Orders;

use GalatanOvidiu\AbilitiesCatalogWoo\Tests\TestCase;
use WP_Error;
use WC_Product_Simple;

/**
 * Exercises wc-orders/create-order: the happy-path create with a line item
 * returning a shaped order with id > 0 and non-empty line_items, the documented
 * set_paid side effect (a paid create comes back processing/completed), the
 * wrong-cap denial, and the exact closed get-order detail output shape.
 */
final class CreateOrderTest extends TestCase {

	/**
	 * The closed key set the ability returns: the OrderListShaper detail shape
	 * (summary fields plus line_items, billing, shipping, edit_link).
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
	 * Seeds a saved, publishable simple product and returns its ID.
	 *
	 * Uses WooCommerce's runtime object API (always loaded with the plugin); the
	 * distributed woocommerce.zip ships no WC_Helper_* factories.
	 *
	 * @return int The seeded product ID.
	 */
	private function seedProduct(): int {
		$product = new WC_Product_Simple();
		$product->set_name( 'Seeded Product' );
		$product->set_status( 'publish' );
		$product->set_regular_price( '10.00' );

		return (int) $product->save();
	}

	public function test_ability_is_registered(): void {
		$ability = wp_get_ability( 'wc-orders/create-order' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'wc-orders/create-order', $ability->get_name() );
	}

	public function test_admin_creates_order_with_line_item(): void {
		$this->actingAs( 'administrator' );

		$product_id = $this->seedProduct();

		$result = wp_get_ability( 'wc-orders/create-order' )->execute(
			array(
				'line_items' => array(
					array(
						'product_id' => $product_id,
						'quantity'   => 2,
					),
				),
			)
		);

		$this->assertIsArray( $result );
		$this->assertGreaterThan( 0, $result['id'] );
		$this->assertNotEmpty( $result['line_items'] );
		$this->assertSame( $product_id, $result['line_items'][0]['product_id'] );
		$this->assertSame( 2, $result['line_items'][0]['quantity'] );

		// The order was actually persisted: re-read it from the store.
		$order = wc_get_order( $result['id'] );
		$this->assertNotFalse( $order );
	}

	public function test_set_paid_fires_payment_complete_side_effect(): void {
		$this->actingAs( 'administrator' );

		$product_id = $this->seedProduct();

		$result = wp_get_ability( 'wc-orders/create-order' )->execute(
			array(
				'line_items' => array(
					array(
						'product_id' => $product_id,
						'quantity'   => 1,
					),
				),
				'set_paid'   => true,
			)
		);

		$this->assertIsArray( $result );
		$this->assertGreaterThan( 0, $result['id'] );

		// set_paid=true fires payment_complete, which moves the order into a paid
		// status (processing, or completed for fully-downloadable/virtual orders).
		$this->assertContains( $result['status'], array( 'processing', 'completed' ) );
	}

	public function test_billing_block_is_forwarded(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'wc-orders/create-order' )->execute(
			array(
				'billing' => array(
					'first_name' => 'Ada',
					'email'      => 'ada@example.org',
				),
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'Ada', $result['billing']['first_name'] );
		$this->assertSame( 'ada@example.org', $result['billing']['email'] );
	}

	public function test_output_shape_is_exact_and_closed(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'wc-orders/create-order' )->execute( array() );

		$this->assertIsArray( $result );
		$this->assertSame( self::EXPECTED_KEYS, array_keys( $result ) );

		// No raw ~100-field order fields leak through.
		$this->assertArrayNotHasKey( 'meta_data', $result );
		$this->assertArrayNotHasKey( '_links', $result );
		$this->assertArrayNotHasKey( 'customer_ip_address', $result );
		$this->assertArrayNotHasKey( 'tax_lines', $result );
	}

	public function test_subscriber_is_denied(): void {
		$this->actingAs( 'subscriber' );

		$ability = wp_get_ability( 'wc-orders/create-order' );

		$this->assertFalse( $ability->check_permissions( array() ) );

		$result = $ability->execute( array() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}
}
