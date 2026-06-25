<?php
/**
 * Integration tests for the `og-wc-orders/list-order-refunds` ability.
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
 * @covers \GalatanOvidiu\AbilitiesCatalogWoo\Abilities\Woo\Orders\ListOrderRefunds
 */
final class ListOrderRefundsTest extends TestCase {

	private const ABILITY = 'og-wc-orders/list-order-refunds';

	/**
	 * The exact keys a shaped refund summary row exposes.
	 *
	 * Asserting against this fixed set proves the raw refund body — which carries
	 * `total`, `line_items`, `meta_data`, and `_links` — is never leaked, and that
	 * the refund value is exposed as `amount`, not a top-level `total`.
	 *
	 * @var list<string>
	 */
	private const ROW_KEYS = array(
		'id',
		'amount',
		'reason',
		'date_created',
		'refunded_by',
	);

	public function test_registered(): void {
		$ability = wp_get_ability( self::ABILITY );

		$this->assertNotNull( $ability );
		$this->assertSame( self::ABILITY, $ability->get_name() );
	}

	public function test_happy_path_returns_shaped_rows(): void {
		$this->actingAs( 'administrator' );
		$order_id = $this->seedOrder();
		$this->seedRefund( $order_id, '5.00', 'Customer changed mind' );

		$result = wp_get_ability( self::ABILITY )->execute( array( 'order_id' => $order_id ) );

		$this->assertIsArray( $result );
		$this->assertSame( array( 'order_id', 'items', 'total' ), array_keys( $result ) );
		$this->assertSame( $order_id, $result['order_id'] );
		$this->assertIsInt( $result['order_id'] );
		$this->assertIsArray( $result['items'] );
		$this->assertIsInt( $result['total'] );
		$this->assertGreaterThanOrEqual( 1, $result['total'] );
		$this->assertNotEmpty( $result['items'] );

		$row = $result['items'][0];
		$this->assertSame( self::ROW_KEYS, array_keys( $row ) );
		$this->assertIsInt( $row['id'] );
		$this->assertIsString( $row['amount'] );
		$this->assertIsString( $row['reason'] );
		$this->assertIsString( $row['date_created'] );
		$this->assertIsInt( $row['refunded_by'] );

		// `amount` is the refund value as a decimal string; it must reflect the seed.
		$this->assertSame( '5.00', $row['amount'] );
		$this->assertSame( 'Customer changed mind', $row['reason'] );
	}

	public function test_unknown_order_id_returns_empty_list(): void {
		// The refunds LIST route filters by post_parent__in, so an unknown order_id
		// returns an empty collection (HTTP 200), NOT a 404. The 404 path lives only
		// on the single-refund route. This is the benign-no-op case (BUILDING.md §10).
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( self::ABILITY )->execute( array( 'order_id' => 99999999 ) );

		$this->assertIsArray( $result );
		$this->assertSame( array( 'order_id', 'items', 'total' ), array_keys( $result ) );
		$this->assertSame( 99999999, $result['order_id'] );
		$this->assertSame( array(), $result['items'] );
		$this->assertSame( 0, $result['total'] );
	}

	public function test_missing_required_order_id_is_rejected(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( self::ABILITY )->execute( array() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_input', $result->get_error_code() );
	}

	public function test_output_shape_has_no_raw_refund_fields(): void {
		$this->actingAs( 'administrator' );
		$order_id = $this->seedOrder();
		$this->seedRefund( $order_id, '5.00', 'Shape refund' );

		$result = wp_get_ability( self::ABILITY )->execute( array( 'order_id' => $order_id ) );

		$this->assertSame( array( 'order_id', 'items', 'total' ), array_keys( $result ) );
		$row = $result['items'][0];
		$this->assertSame( self::ROW_KEYS, array_keys( $row ) );
		// A refund has no top-level `total`; the value is `amount`.
		$this->assertArrayNotHasKey( 'total', $row );
		$this->assertArrayNotHasKey( 'line_items', $row );
		$this->assertArrayNotHasKey( 'meta_data', $row );
		$this->assertArrayNotHasKey( 'date_created_gmt', $row );
		$this->assertArrayNotHasKey( '_links', $row );
	}

	public function test_wrong_capability_is_denied(): void {
		$order_id = $this->seedOrder();
		$this->seedRefund( $order_id, '5.00', 'Denied refund' );
		// A subscriber lacks read_private_shop_orders.
		$this->actingAs( 'subscriber' );

		$result = wp_get_ability( self::ABILITY )->execute( array( 'order_id' => $order_id ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_logged_out_user_is_denied(): void {
		$order_id = $this->seedOrder();
		$this->seedRefund( $order_id, '5.00', 'Logged out refund' );
		wp_set_current_user( 0 );

		$result = wp_get_ability( self::ABILITY )->execute( array( 'order_id' => $order_id ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	/**
	 * Seeds a saved order with one line item via the WooCommerce runtime object API.
	 *
	 * The distributed `woocommerce.zip` ships no `tests/` framework, so the
	 * `WC_Helper_*` factories do not exist; the runtime `WC_Order` / `WC_Product_Simple`
	 * classes load with the plugin and are the supported way to seed.
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

	/**
	 * Seeds a refund against an order via the WooCommerce runtime `wc_create_refund()`.
	 *
	 * @param int    $order_id The parent order ID.
	 * @param string $amount   The refund amount as a decimal string.
	 * @param string $reason   The refund reason.
	 * @return int The created refund ID.
	 */
	private function seedRefund( int $order_id, string $amount, string $reason ): int {
		$refund = wc_create_refund(
			array(
				'order_id' => $order_id,
				'amount'   => $amount,
				'reason'   => $reason,
			)
		);

		$this->assertNotInstanceOf( WP_Error::class, $refund );

		return (int) $refund->get_id();
	}
}
