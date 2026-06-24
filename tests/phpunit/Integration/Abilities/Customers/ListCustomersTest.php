<?php
/**
 * Integration tests for the `wc-customers/list-customers` ability.
 *
 * @package AbilitiesCatalogWoo\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogWoo\Tests\Integration\Abilities\Customers;

use GalatanOvidiu\AbilitiesCatalogWoo\Tests\TestCase;
use WP_Error;

/**
 * @covers \GalatanOvidiu\AbilitiesCatalogWoo\Abilities\Woo\Customers\ListCustomers
 */
final class ListCustomersTest extends TestCase {

	private const ABILITY = 'wc-customers/list-customers';

	/**
	 * The exact keys a shaped customer summary row exposes.
	 *
	 * Asserting against this fixed set proves the raw customer body is never
	 * leaked: only these projected fields reach the consumer.
	 *
	 * @var list<string>
	 */
	private const ROW_KEYS = array(
		'id',
		'email',
		'first_name',
		'last_name',
		'username',
		'role',
		'date_created',
		'orders_count',
		'total_spent',
	);

	public function test_registered(): void {
		$ability = wp_get_ability( self::ABILITY );

		$this->assertNotNull( $ability );
		$this->assertSame( self::ABILITY, $ability->get_name() );
	}

	public function test_happy_path_returns_shaped_rows(): void {
		$this->seedCustomer( 'buyer@example.org', 'Ada', 'Lovelace' );
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( self::ABILITY )->execute( array() );

		$this->assertIsArray( $result );
		$this->assertSame( array( 'items', 'total' ), array_keys( $result ) );
		$this->assertIsArray( $result['items'] );
		$this->assertIsInt( $result['total'] );
		$this->assertGreaterThanOrEqual( 1, $result['total'] );
		$this->assertNotEmpty( $result['items'] );

		$row = $result['items'][0];
		$this->assertSame( self::ROW_KEYS, array_keys( $row ) );
		$this->assertIsInt( $row['id'] );
		$this->assertIsString( $row['email'] );
		$this->assertIsString( $row['first_name'] );
		$this->assertIsInt( $row['orders_count'] );
		$this->assertIsString( $row['total_spent'] );
	}

	public function test_email_filter_narrows_results(): void {
		$wanted = $this->seedCustomer( 'wanted@example.org', 'Grace', 'Hopper' );
		$this->seedCustomer( 'other@example.org', 'Alan', 'Turing' );
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( self::ABILITY )->execute( array( 'email' => 'wanted@example.org' ) );

		$emails = wp_list_pluck( $result['items'], 'email' );
		$this->assertContains( 'wanted@example.org', $emails );
		$this->assertNotContains( 'other@example.org', $emails );

		$ids = wp_list_pluck( $result['items'], 'id' );
		$this->assertContains( $wanted, $ids );
	}

	public function test_output_does_not_leak_raw_customer_fields(): void {
		$this->seedCustomer( 'leak@example.org', 'Pii', 'Test' );
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( self::ABILITY )->execute( array() );

		$row = $result['items'][0];
		$this->assertSame( self::ROW_KEYS, array_keys( $row ) );
		$this->assertArrayNotHasKey( 'meta_data', $row );
		$this->assertArrayNotHasKey( 'avatar_url', $row );
		$this->assertArrayNotHasKey( 'is_paying_customer', $row );
		$this->assertArrayNotHasKey( 'billing', $row );
		$this->assertArrayNotHasKey( 'shipping', $row );
		$this->assertArrayNotHasKey( '_links', $row );
	}

	public function test_wrong_capability_is_denied(): void {
		$this->seedCustomer( 'buyer@example.org', 'Ada', 'Lovelace' );
		$this->actingAs( 'subscriber' );

		$ability = wp_get_ability( self::ABILITY );

		$this->assertFalse( $ability->check_permissions( array() ) );

		$result = $ability->execute( array() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_logged_out_user_is_denied(): void {
		$this->seedCustomer( 'buyer@example.org', 'Ada', 'Lovelace' );
		wp_set_current_user( 0 );

		$result = wp_get_ability( self::ABILITY )->execute( array() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	/**
	 * Seeds a customer-role user and returns its user ID.
	 *
	 * Uses the core WP user factory rather than the WC_Helper_Customer test
	 * factory, which does not exist in the distributed WooCommerce build mounted
	 * by the test environment (it ships no tests/ helper framework).
	 *
	 * @param string $email      The user email.
	 * @param string $first_name The first name.
	 * @param string $last_name  The last name.
	 * @return int The new user ID.
	 */
	private function seedCustomer( string $email, string $first_name, string $last_name ): int {
		return $this->factory()->user->create(
			array(
				'role'       => 'customer',
				'user_email' => $email,
				'first_name' => $first_name,
				'last_name'  => $last_name,
			)
		);
	}
}
