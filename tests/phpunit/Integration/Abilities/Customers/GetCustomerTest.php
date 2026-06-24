<?php
/**
 * Integration tests for the wc-customers/get-customer ability.
 *
 * @package AbilitiesCatalogWoo\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogWoo\Tests\Integration\Abilities\Customers;

use GalatanOvidiu\AbilitiesCatalogWoo\Tests\TestCase;
use WP_Error;
use WC_Customer;

/**
 * Exercises wc-customers/get-customer: the shaped single-customer record, the
 * billing/shipping detail blocks and edit_link, the missing-customer 404 that
 * must not collapse to a permission error, the wrong-capability denial, and the
 * exact closed output shape with no PII leak beyond the documented subset.
 */
final class GetCustomerTest extends TestCase {

	/**
	 * The full closed key set the ability returns: the summary fields plus the
	 * billing/shipping detail blocks and the edit_link, in order.
	 *
	 * @var list<string>
	 */
	private const EXPECTED_KEYS = array(
		'id',
		'email',
		'first_name',
		'last_name',
		'username',
		'role',
		'date_created',
		'orders_count',
		'total_spent',
		'billing',
		'shipping',
		'edit_link',
	);

	/**
	 * The closed key set of the billing block (has email).
	 *
	 * @var list<string>
	 */
	private const BILLING_KEYS = array(
		'first_name',
		'last_name',
		'company',
		'address_1',
		'address_2',
		'city',
		'state',
		'postcode',
		'country',
		'email',
		'phone',
	);

	/**
	 * The closed key set of the shipping block (no email).
	 *
	 * @var list<string>
	 */
	private const SHIPPING_KEYS = array(
		'first_name',
		'last_name',
		'company',
		'address_1',
		'address_2',
		'city',
		'state',
		'postcode',
		'country',
		'phone',
	);

	public function test_ability_is_registered(): void {
		$ability = wp_get_ability( 'wc-customers/get-customer' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'wc-customers/get-customer', $ability->get_name() );
	}

	public function test_admin_reads_customer_detail(): void {
		$this->actingAs( 'administrator' );

		$customer_id = $this->seedCustomer();

		$result = wp_get_ability( 'wc-customers/get-customer' )->execute( array( 'id' => $customer_id ) );

		$this->assertIsArray( $result );
		$this->assertSame( $customer_id, $result['id'] );
		$this->assertSame( 'buyer@example.org', $result['email'] );
		$this->assertSame( 'Ada', $result['first_name'] );
		$this->assertSame( 'Lovelace', $result['last_name'] );
		$this->assertIsInt( $result['orders_count'] );
		$this->assertIsString( $result['total_spent'] );

		// Billing/shipping blocks are closed to the documented PII subset.
		$this->assertSame( self::BILLING_KEYS, array_keys( $result['billing'] ) );
		$this->assertSame( self::SHIPPING_KEYS, array_keys( $result['shipping'] ) );
		$this->assertSame( 'buyer@example.org', $result['billing']['email'] );
		$this->assertSame( '1 Test St', $result['billing']['address_1'] );

		$this->assertStringContainsString( 'user-edit.php?user_id=' . $customer_id, $result['edit_link'] );
	}

	public function test_output_shape_is_exact_and_closed(): void {
		$this->actingAs( 'administrator' );

		$customer_id = $this->seedCustomer();

		$result = wp_get_ability( 'wc-customers/get-customer' )->execute( array( 'id' => $customer_id ) );

		$this->assertIsArray( $result );
		$this->assertSame( self::EXPECTED_KEYS, array_keys( $result ) );

		// No raw customer fields or PII beyond the documented subset leak through.
		$this->assertArrayNotHasKey( 'meta_data', $result );
		$this->assertArrayNotHasKey( 'avatar_url', $result );
		$this->assertArrayNotHasKey( 'is_paying_customer', $result );
		$this->assertArrayNotHasKey( '_links', $result );

		// The shipping block carries no email (the WC shipping address has none).
		$this->assertArrayNotHasKey( 'email', $result['shipping'] );
	}

	public function test_missing_customer_returns_404_not_permission_error(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'wc-customers/get-customer' )->execute( array( 'id' => 99999999 ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wc_user_invalid_id', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] );
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_subscriber_is_denied(): void {
		$customer_id = $this->seedCustomer();
		$this->actingAs( 'subscriber' );

		$ability = wp_get_ability( 'wc-customers/get-customer' );

		$this->assertFalse( $ability->check_permissions( array( 'id' => $customer_id ) ) );

		$result = $ability->execute( array( 'id' => $customer_id ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	/**
	 * Seeds a customer with WooCommerce billing/shipping address data.
	 *
	 * Creates the user with the core WP factory (which exists) and sets the WC
	 * billing/shipping via the WC_Customer runtime object, rather than the
	 * WC_Helper_Customer test factory, because the test environment mounts the
	 * distributed WooCommerce build, which ships no tests/ helper framework.
	 *
	 * @return int The created customer (user) ID.
	 */
	private function seedCustomer(): int {
		$customer_id = $this->factory()->user->create(
			array(
				'role'       => 'customer',
				'user_email' => 'buyer@example.org',
				'first_name' => 'Ada',
				'last_name'  => 'Lovelace',
			)
		);

		$customer = new WC_Customer( $customer_id );
		$customer->set_billing_email( 'buyer@example.org' );
		$customer->set_billing_first_name( 'Ada' );
		$customer->set_billing_last_name( 'Lovelace' );
		$customer->set_billing_address_1( '1 Test St' );
		$customer->set_billing_city( 'Testville' );
		$customer->set_billing_state( 'CA' );
		$customer->set_billing_postcode( '90210' );
		$customer->set_billing_country( 'US' );
		$customer->set_shipping_first_name( 'Ada' );
		$customer->set_shipping_last_name( 'Lovelace' );
		$customer->set_shipping_address_1( '1 Test St' );
		$customer->set_shipping_city( 'Testville' );
		$customer->set_shipping_state( 'CA' );
		$customer->set_shipping_postcode( '90210' );
		$customer->set_shipping_country( 'US' );
		$customer->save();

		return (int) $customer_id;
	}
}
