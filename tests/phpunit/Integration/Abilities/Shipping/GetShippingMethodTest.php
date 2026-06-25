<?php
/**
 * Integration tests for the og-wc-shipping/get-shipping-method ability.
 *
 * @package AbilitiesCatalogWoo\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogWoo\Tests\Integration\Abilities\Shipping;

use GalatanOvidiu\AbilitiesCatalogWoo\Tests\TestCase;
use WP_Error;

/**
 * Exercises og-wc-shipping/get-shipping-method: the shaped single method-type record
 * for a registry id, the unknown-id 404 that must not collapse to a permission
 * error, the wrong-capability denial, and the exact closed output shape.
 *
 * The default method types (flat_rate/free_shipping/local_pickup) are registered
 * by the WooCommerce test env, so the happy path needs no seeding.
 */
final class GetShippingMethodTest extends TestCase {

	/**
	 * The full closed key set the ability returns for one method type.
	 *
	 * @var list<string>
	 */
	private const EXPECTED_KEYS = array(
		'id',
		'title',
		'description',
	);

	public function test_ability_is_registered(): void {
		$ability = wp_get_ability( 'og-wc-shipping/get-shipping-method' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'og-wc-shipping/get-shipping-method', $ability->get_name() );
	}

	public function test_admin_reads_method_type_detail(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'og-wc-shipping/get-shipping-method' )->execute( array( 'id' => 'flat_rate' ) );

		$this->assertIsArray( $result );
		$this->assertSame( 'flat_rate', $result['id'] );
		$this->assertIsString( $result['title'] );
		$this->assertNotSame( '', $result['title'] );
		$this->assertIsString( $result['description'] );
	}

	public function test_output_shape_is_exact_and_closed(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'og-wc-shipping/get-shipping-method' )->execute( array( 'id' => 'flat_rate' ) );

		$this->assertIsArray( $result );
		$this->assertSame( self::EXPECTED_KEYS, array_keys( $result ) );

		// No raw REST fields leak through.
		$this->assertArrayNotHasKey( '_links', $result );
		$this->assertArrayNotHasKey( 'method_title', $result );

		$this->assertIsString( $result['id'] );
		$this->assertIsString( $result['title'] );
		$this->assertIsString( $result['description'] );
	}

	public function test_unknown_method_returns_404_not_permission_error(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'og-wc-shipping/get-shipping-method' )->execute( array( 'id' => 'no_such_method' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'woocommerce_rest_shipping_method_invalid', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] );
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_subscriber_is_denied(): void {
		$this->actingAs( 'subscriber' );

		$ability = wp_get_ability( 'og-wc-shipping/get-shipping-method' );

		$this->assertFalse( $ability->check_permissions( array( 'id' => 'flat_rate' ) ) );

		$result = $ability->execute( array( 'id' => 'flat_rate' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}
}
