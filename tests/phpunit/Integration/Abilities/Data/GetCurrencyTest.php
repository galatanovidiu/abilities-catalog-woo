<?php
/**
 * Integration tests for the wc-data/get-currency ability.
 *
 * @package AbilitiesCatalogWoo\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogWoo\Tests\Integration\Abilities\Data;

use GalatanOvidiu\AbilitiesCatalogWoo\Tests\TestCase;
use WP_Error;

/**
 * Exercises wc-data/get-currency: a known code returns the flat shaped currency,
 * an unknown code surfaces the route's specific 404 (not a permission collapse),
 * and a non-manager is denied.
 *
 * The ISO-4217 currency table ships with WooCommerce, so USD is always present —
 * no seeding is required.
 */
final class GetCurrencyTest extends TestCase {

	public function test_ability_is_registered(): void {
		$ability = wp_get_ability( 'wc-data/get-currency' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'wc-data/get-currency', $ability->get_name() );
	}

	public function test_manager_reads_known_currency(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'wc-data/get-currency' )->execute( array( 'code' => 'USD' ) );

		$this->assertIsArray( $result );
		$this->assertSame( 'USD', $result['code'] );
		$this->assertIsString( $result['name'] );
		$this->assertNotSame( '', $result['name'] );
		$this->assertIsString( $result['symbol'] );
		$this->assertNotSame( '', $result['symbol'] );
	}

	public function test_lowercase_code_is_accepted(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'wc-data/get-currency' )->execute( array( 'code' => 'usd' ) );

		$this->assertIsArray( $result );
		$this->assertSame( 'USD', $result['code'] );
	}

	public function test_unknown_code_returns_404_not_permission_error(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'wc-data/get-currency' )->execute( array( 'code' => 'ZZZ' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'woocommerce_rest_data_invalid_currency', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] );
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_subscriber_is_denied(): void {
		$this->actingAs( 'subscriber' );

		$ability = wp_get_ability( 'wc-data/get-currency' );

		$this->assertFalse( $ability->check_permissions( array( 'code' => 'USD' ) ) );
	}

	public function test_output_shape_is_flat_and_closed(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'wc-data/get-currency' )->execute( array( 'code' => 'USD' ) );

		$this->assertIsArray( $result );
		$this->assertSame( array( 'code', 'name', 'symbol' ), array_keys( $result ) );
		$this->assertArrayNotHasKey( '_links', $result );
	}
}
