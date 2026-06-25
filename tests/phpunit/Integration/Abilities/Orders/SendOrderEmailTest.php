<?php
/**
 * Integration tests for the og-wc-orders/send-order-email ability.
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
 * Exercises og-wc-orders/send-order-email: the happy-path send returning sent=true and
 * echoing the template_id plus the billing email (with the real email asserted via
 * the WP mock mailer), the invalid-template-for-status 400, the missing-email 400,
 * the missing-order 404 that must not collapse to a permission error, the
 * wrong-capability denial, and the exact closed output shape (no raw order fields
 * leak).
 */
final class SendOrderEmailTest extends TestCase {

	/**
	 * The closed key set the ability returns on a successful send to an order that
	 * has a billing email.
	 *
	 * @var list<string>
	 */
	private const EXPECTED_KEYS = array(
		'sent',
		'id',
		'template_id',
		'customer_email',
	);

	public function set_up(): void {
		parent::set_up();

		// Use the WP mock mailer so the test asserts a real send without delivering,
		// and give wp_mail a valid From so a localhost host does not reject the send.
		reset_phpmailer_instance();
		add_filter( 'wp_mail_from', static fn() => 'wordpress@example.org' );
	}

	public function tear_down(): void {
		reset_phpmailer_instance();

		parent::tear_down();
	}

	public function test_ability_is_registered(): void {
		$ability = wp_get_ability( 'og-wc-orders/send-order-email' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'og-wc-orders/send-order-email', $ability->get_name() );
	}

	public function test_admin_sends_customer_invoice(): void {
		$this->actingAs( 'administrator' );

		$order_id = $this->seedOrder( 'processing' );

		// Reset the mock mailer so mock_sent contains only the email the ability sends,
		// not the automatic transactional emails WC fires when set_status() is called
		// during seedOrder() (e.g. the admin new-order notification to admin@example.org).
		reset_phpmailer_instance();

		$result = wp_get_ability( 'og-wc-orders/send-order-email' )->execute(
			array(
				'id'          => $order_id,
				'template_id' => 'customer_invoice',
			)
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['sent'] );
		$this->assertSame( $order_id, $result['id'] );
		$this->assertSame( 'customer_invoice', $result['template_id'] );
		$this->assertSame( 'ada@example.org', $result['customer_email'] );

		// The email actually went out to the order's billing address.
		$mailer = tests_retrieve_phpmailer_instance();
		$this->assertNotEmpty( $mailer->mock_sent );
		$this->assertSame( 'ada@example.org', $mailer->get_recipient( 'to' )->address );
	}

	public function test_output_shape_is_exact_and_closed(): void {
		$this->actingAs( 'administrator' );

		$order_id = $this->seedOrder( 'processing' );

		$result = wp_get_ability( 'og-wc-orders/send-order-email' )->execute(
			array(
				'id'          => $order_id,
				'template_id' => 'customer_invoice',
			)
		);

		$this->assertIsArray( $result );

		$keys = array_keys( $result );
		sort( $keys );
		$expected = self::EXPECTED_KEYS;
		sort( $expected );
		$this->assertSame( $expected, $keys );

		// No raw order fields leak through.
		$this->assertArrayNotHasKey( 'billing', $result );
		$this->assertArrayNotHasKey( 'meta_data', $result );
		$this->assertArrayNotHasKey( 'message', $result );
		$this->assertArrayNotHasKey( '_links', $result );
	}

	public function test_invalid_template_for_status_returns_400(): void {
		$this->actingAs( 'administrator' );

		// A pending order: customer_completed_order is not a valid template for it.
		$order_id = $this->seedOrder( 'pending' );

		$result = wp_get_ability( 'og-wc-orders/send-order-email' )->execute(
			array(
				'id'          => $order_id,
				'template_id' => 'customer_completed_order',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'woocommerce_rest_invalid_email_template', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] );
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_order_without_billing_email_returns_400(): void {
		$this->actingAs( 'administrator' );

		$order_id = $this->seedOrder( 'processing', false );

		$result = wp_get_ability( 'og-wc-orders/send-order-email' )->execute(
			array(
				'id'          => $order_id,
				'template_id' => 'customer_invoice',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'woocommerce_rest_missing_email', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] );
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_missing_order_returns_404_not_permission_error(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'og-wc-orders/send-order-email' )->execute(
			array(
				'id'          => 99999999,
				'template_id' => 'customer_invoice',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'woocommerce_rest_shop_order_invalid_id', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] );
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_subscriber_is_denied(): void {
		$order_id = $this->seedOrder( 'processing' );

		$this->actingAs( 'subscriber' );

		$ability = wp_get_ability( 'og-wc-orders/send-order-email' );

		$this->assertFalse(
			$ability->check_permissions(
				array(
					'id'          => $order_id,
					'template_id' => 'customer_invoice',
				)
			)
		);

		$result = $ability->execute(
			array(
				'id'          => $order_id,
				'template_id' => 'customer_invoice',
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
	 * @param string $status        The order status to set.
	 * @param bool   $with_email    Whether to set a billing email on the order.
	 * @return int The created order ID.
	 */
	private function seedOrder( string $status, bool $with_email = true ): int {
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
				'email'      => $with_email ? 'ada@example.org' : '',
				'address_1'  => '1 Test St',
				'city'       => 'Testville',
				'state'      => 'CA',
				'postcode'   => '90210',
				'country'    => 'US',
			),
			'billing'
		);
		$order->set_status( $status );
		$order->calculate_totals();
		$order->save();

		return (int) $order->get_id();
	}
}
