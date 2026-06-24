<?php
/**
 * Integration tests for the wc-customers/create-customer ability.
 *
 * @package AbilitiesCatalogWoo\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogWoo\Tests\Integration\Abilities\Customers;

use GalatanOvidiu\AbilitiesCatalogWoo\Tests\TestCase;
use WP_Error;

/**
 * Exercises wc-customers/create-customer: the shaped created customer with a real
 * id, a honored field, the load-bearing password redaction (no `password` key in
 * the result even when one is supplied on input), the wrong-capability denial, and
 * the route's 400 for a duplicate email surfaced as a specific error rather than a
 * permission collapse.
 */
final class CreateCustomerTest extends TestCase {

	/**
	 * The full closed key set the ability returns: the detail (summary + billing +
	 * shipping) fields plus the edit_link, in order. No `password` key.
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

	public function test_ability_is_registered(): void {
		$ability = wp_get_ability( 'wc-customers/create-customer' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'wc-customers/create-customer', $ability->get_name() );
	}

	public function test_admin_creates_customer_returns_shaped_record(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'wc-customers/create-customer' )->execute(
			array(
				'email'      => 'buyer@example.test',
				'first_name' => 'Grace',
			)
		);

		$this->assertIsArray( $result );
		$this->assertGreaterThan( 0, $result['id'] );
		$this->assertSame( 'buyer@example.test', $result['email'] );
		$this->assertSame( 'Grace', $result['first_name'] );
		$this->assertStringContainsString( 'user-edit.php?user_id=' . $result['id'], $result['edit_link'] );
	}

	public function test_output_shape_is_exact_and_has_no_password_key(): void {
		$this->actingAs( 'administrator' );

		// A password IS supplied on input; it must never appear in the result.
		$result = wp_get_ability( 'wc-customers/create-customer' )->execute(
			array(
				'email'    => 'shape@example.test',
				'password' => 'super-secret-pass',
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( self::EXPECTED_KEYS, array_keys( $result ) );

		// The credential is load-bearing: it must never be echoed back.
		$this->assertArrayNotHasKey( 'password', $result );
		$this->assertArrayNotHasKey( 'meta_data', $result );
		$this->assertArrayNotHasKey( 'avatar_url', $result );
		$this->assertArrayNotHasKey( '_links', $result );

		// The shipping block carries no email (the WC shipping address has none).
		$this->assertArrayNotHasKey( 'email', $result['shipping'] );
	}

	public function test_subscriber_is_denied(): void {
		$this->actingAs( 'subscriber' );

		$ability = wp_get_ability( 'wc-customers/create-customer' );

		$this->assertFalse( $ability->check_permissions( array( 'email' => 'denied@example.test' ) ) );

		$result = $ability->execute( array( 'email' => 'denied@example.test' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );

		// The denied caller created nothing.
		$this->assertFalse( email_exists( 'denied@example.test' ) );
	}

	public function test_duplicate_email_returns_400_not_permission_error(): void {
		$this->actingAs( 'administrator' );

		// Seed an existing account with the email the create will collide with.
		$this->factory()->user->create(
			array(
				'role'       => 'customer',
				'user_email' => 'taken@example.test',
			)
		);

		$result = wp_get_ability( 'wc-customers/create-customer' )->execute(
			array( 'email' => 'taken@example.test' )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 400, $result->get_error_data()['status'] );
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code() );
	}
}
