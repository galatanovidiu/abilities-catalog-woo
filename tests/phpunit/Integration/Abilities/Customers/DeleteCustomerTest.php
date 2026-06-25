<?php
/**
 * Integration tests for the og-wc-customers/delete-customer ability.
 *
 * @package AbilitiesCatalogWoo\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogWoo\Tests\Integration\Abilities\Customers;

use GalatanOvidiu\AbilitiesCatalogWoo\Tests\TestCase;
use WP_Error;

/**
 * Exercises og-wc-customers/delete-customer: the permanent force delete of a customer
 * (WordPress user) with the email/username captured and the user actually gone, the
 * reassign path that preserves content under another account, the route's specific
 * 404 for a missing customer surfaced as a real error rather than a permission
 * collapse, the wrong-capability denial that leaves the user intact, and the exact
 * closed output shape with no edit_link and no raw customer fields.
 */
final class DeleteCustomerTest extends TestCase {

	/**
	 * The full closed key set the ability returns, in order.
	 *
	 * @var list<string>
	 */
	private const EXPECTED_KEYS = array(
		'deleted',
		'id',
		'email',
		'username',
		'reassigned_to',
		'force_used',
		'permanent',
	);

	/**
	 * Seeds a customer (WordPress user) and returns its ID.
	 *
	 * @param string $email    The user email.
	 * @param string $login    The user login (username).
	 * @return int The new user ID.
	 */
	private function seedCustomer( string $email = 'buyer@example.test', string $login = 'buyer_user' ): int {
		return (int) $this->factory()->user->create(
			array(
				'role'       => 'customer',
				'user_email' => $email,
				'user_login' => $login,
			)
		);
	}

	public function test_ability_is_registered(): void {
		$ability = wp_get_ability( 'og-wc-customers/delete-customer' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'og-wc-customers/delete-customer', $ability->get_name() );
	}

	public function test_admin_deletes_customer_permanently_and_user_is_gone(): void {
		$this->actingAs( 'administrator' );

		$id = $this->seedCustomer();

		$result = wp_get_ability( 'og-wc-customers/delete-customer' )->execute( array( 'id' => $id ) );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['deleted'] );
		$this->assertSame( $id, $result['id'] );
		$this->assertSame( 'buyer@example.test', $result['email'] );
		$this->assertSame( 'buyer_user', $result['username'] );
		$this->assertNull( $result['reassigned_to'] );
		$this->assertTrue( $result['force_used'] );
		$this->assertTrue( $result['permanent'] );

		// The WordPress user is gone (the route called wp_delete_user()).
		$this->assertFalse( get_userdata( $id ) );
	}

	public function test_reassign_echoes_target_and_deletes_original_user(): void {
		$this->actingAs( 'administrator' );

		$customer_id = $this->seedCustomer( 'author@example.test', 'author_user' );
		$reassign_id = $this->seedCustomer( 'keeper@example.test', 'keeper_user' );

		$result = wp_get_ability( 'og-wc-customers/delete-customer' )->execute(
			array(
				'id'       => $customer_id,
				'reassign' => $reassign_id,
			)
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['deleted'] );
		$this->assertSame( $reassign_id, $result['reassigned_to'] );

		// The deleted customer is gone; the reassign target survives.
		$this->assertFalse( get_userdata( $customer_id ) );
		$this->assertNotFalse( get_userdata( $reassign_id ) );
	}

	public function test_output_shape_is_exact_and_has_no_edit_link(): void {
		$this->actingAs( 'administrator' );

		$id = $this->seedCustomer( 'shape@example.test', 'shape_user' );

		$result = wp_get_ability( 'og-wc-customers/delete-customer' )->execute( array( 'id' => $id ) );

		$this->assertIsArray( $result );
		$this->assertSame( self::EXPECTED_KEYS, array_keys( $result ) );

		// A delete must not return a dead-end edit link, nor leak raw customer fields.
		$this->assertArrayNotHasKey( 'edit_link', $result );
		$this->assertArrayNotHasKey( 'meta_data', $result );
		$this->assertArrayNotHasKey( '_links', $result );
		$this->assertArrayNotHasKey( 'billing', $result );

		$this->assertIsBool( $result['deleted'] );
		$this->assertIsInt( $result['id'] );
		$this->assertIsString( $result['email'] );
		$this->assertIsString( $result['username'] );
		$this->assertIsBool( $result['force_used'] );
		$this->assertIsBool( $result['permanent'] );
	}

	public function test_missing_customer_returns_404_not_permission_error(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'og-wc-customers/delete-customer' )->execute( array( 'id' => 99999999 ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wc_user_invalid_id', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] );
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_invalid_reassign_returns_400_not_permission_error(): void {
		$this->actingAs( 'administrator' );

		$id = $this->seedCustomer( 'selfreassign@example.test', 'self_user' );

		// reassign equal to the deleted id is rejected by the route.
		$result = wp_get_ability( 'og-wc-customers/delete-customer' )->execute(
			array(
				'id'       => $id,
				'reassign' => $id,
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'woocommerce_rest_customer_invalid_reassign', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] );

		// The rejected delete left the user intact.
		$this->assertNotFalse( get_userdata( $id ) );
	}

	public function test_subscriber_is_denied_and_user_survives(): void {
		$this->actingAs( 'subscriber' );

		$id = $this->seedCustomer( 'survivor@example.test', 'survivor_user' );

		$ability = wp_get_ability( 'og-wc-customers/delete-customer' );

		$this->assertFalse( $ability->check_permissions( array( 'id' => $id ) ) );

		$result = $ability->execute( array( 'id' => $id ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );

		// The denied caller deleted nothing.
		$this->assertNotFalse( get_userdata( $id ) );
	}
}
