<?php
/**
 * Integration tests for the wc-customers/update-customer ability.
 *
 * @package AbilitiesCatalogWoo\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogWoo\Tests\Integration\Abilities\Customers;

use GalatanOvidiu\AbilitiesCatalogWoo\Tests\TestCase;
use WP_Error;

/**
 * Exercises wc-customers/update-customer: the happy-path field change, the
 * load-bearing password redaction (a password sent on input never appears in the
 * result), the missing-customer 400 that must not collapse to a permission error,
 * the wrong-capability denial, and the exact closed output shape with no raw
 * customer fields or password leaking through.
 */
final class UpdateCustomerTest extends TestCase {

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
		$ability = wp_get_ability( 'wc-customers/update-customer' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'wc-customers/update-customer', $ability->get_name() );
	}

	public function test_admin_updates_customer_field(): void {
		$this->actingAs( 'administrator' );

		$customer_id = $this->seedCustomer();

		$result = wp_get_ability( 'wc-customers/update-customer' )->execute(
			array(
				'id'         => $customer_id,
				'first_name' => 'Updated',
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( $customer_id, $result['id'] );
		$this->assertSame( 'Updated', $result['first_name'] );
		$this->assertStringContainsString( 'user-edit.php?user_id=' . $customer_id, $result['edit_link'] );

		// The change is persisted, not just echoed.
		$reread = wp_get_ability( 'wc-customers/get-customer' )->execute( array( 'id' => $customer_id ) );
		$this->assertIsArray( $reread );
		$this->assertSame( 'Updated', $reread['first_name'] );
	}

	public function test_password_on_input_never_appears_in_output(): void {
		$this->actingAs( 'administrator' );

		$customer_id = $this->seedCustomer();

		$result = wp_get_ability( 'wc-customers/update-customer' )->execute(
			array(
				'id'         => $customer_id,
				'first_name' => 'Secret',
				'password'   => 'super-secret-passphrase',
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'Secret', $result['first_name'] );

		// The redaction is load-bearing: no password key, no value, anywhere.
		$this->assertArrayNotHasKey( 'password', $result );
		$this->assertArrayNotHasKey( 'password', $result['billing'] );
		$this->assertArrayNotHasKey( 'password', $result['shipping'] );
		$this->assertStringNotContainsString( 'super-secret-passphrase', wp_json_encode( $result ) );
	}

	public function test_output_shape_is_exact_and_closed(): void {
		$this->actingAs( 'administrator' );

		$customer_id = $this->seedCustomer();

		$result = wp_get_ability( 'wc-customers/update-customer' )->execute(
			array(
				'id'         => $customer_id,
				'first_name' => 'Shape',
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( self::EXPECTED_KEYS, array_keys( $result ) );

		// Billing/shipping are closed to the documented PII subset.
		$this->assertSame( self::BILLING_KEYS, array_keys( $result['billing'] ) );
		$this->assertSame( self::SHIPPING_KEYS, array_keys( $result['shipping'] ) );

		// No raw customer fields or PII beyond the documented subset leak through.
		$this->assertArrayNotHasKey( 'meta_data', $result );
		$this->assertArrayNotHasKey( 'avatar_url', $result );
		$this->assertArrayNotHasKey( 'is_paying_customer', $result );
		$this->assertArrayNotHasKey( 'password', $result );
		$this->assertArrayNotHasKey( '_links', $result );

		// The shipping block carries no email (the WC shipping address has none).
		$this->assertArrayNotHasKey( 'email', $result['shipping'] );
	}

	public function test_missing_customer_returns_404_not_permission_error(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'wc-customers/update-customer' )->execute(
			array(
				'id'         => 99999999,
				'first_name' => 'Nobody',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wc_user_invalid_id', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] );
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_subscriber_is_denied(): void {
		$customer_id = $this->seedCustomer();
		$this->actingAs( 'subscriber' );

		$ability = wp_get_ability( 'wc-customers/update-customer' );

		$this->assertFalse( $ability->check_permissions( array( 'id' => $customer_id ) ) );

		$result = $ability->execute(
			array(
				'id'         => $customer_id,
				'first_name' => 'Denied',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );

		// The customer survived the denied write unchanged.
		$reread = get_userdata( $customer_id );
		$this->assertSame( 'Ada', $reread->first_name );
	}

	/**
	 * Seeds a customer with the core WP user factory.
	 *
	 * Uses the core factory (which exists in the test env) rather than the
	 * WC_Helper_Customer test factory, because the distributed WooCommerce build
	 * the env mounts ships no tests/ helper framework.
	 *
	 * @return int The created customer (user) ID.
	 */
	private function seedCustomer(): int {
		return (int) $this->factory()->user->create(
			array(
				'role'       => 'customer',
				'user_email' => 'buyer@example.org',
				'first_name' => 'Ada',
				'last_name'  => 'Lovelace',
			)
		);
	}
}
