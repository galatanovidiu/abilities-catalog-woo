<?php
/**
 * Integration tests for the wc-shipping/create-shipping-zone ability.
 *
 * @package AbilitiesCatalogWoo\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogWoo\Tests\Integration\Abilities\Shipping;

use GalatanOvidiu\AbilitiesCatalogWoo\Tests\TestCase;
use WP_Error;

/**
 * Exercises wc-shipping/create-shipping-zone: the happy-path shaped zone with a
 * real id, the honored order field, the wrong-capability denial, and the exact
 * closed output shape (flat: id/name/order only, no raw zone fields).
 */
final class CreateShippingZoneTest extends TestCase {

	/**
	 * The full closed key set the ability returns for a created shipping zone.
	 *
	 * @var list<string>
	 */
	private const EXPECTED_KEYS = array(
		'id',
		'name',
		'order',
	);

	public function test_ability_is_registered(): void {
		$ability = wp_get_ability( 'wc-shipping/create-shipping-zone' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'wc-shipping/create-shipping-zone', $ability->get_name() );
	}

	public function test_admin_creates_zone(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'wc-shipping/create-shipping-zone' )->execute( array( 'name' => 'Europe' ) );

		$this->assertIsArray( $result );
		$this->assertGreaterThan( 0, $result['id'] );
		$this->assertSame( 'Europe', $result['name'] );
	}

	public function test_order_is_honored(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'wc-shipping/create-shipping-zone' )->execute(
			array(
				'name'  => 'Priority Zone',
				'order' => 5,
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( 5, $result['order'] );
	}

	public function test_output_shape_is_exact_and_closed(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'wc-shipping/create-shipping-zone' )->execute( array( 'name' => 'Shaped Zone' ) );

		$this->assertIsArray( $result );
		$this->assertSame( self::EXPECTED_KEYS, array_keys( $result ) );

		// No raw zone fields leak through.
		$this->assertArrayNotHasKey( '_links', $result );
		$this->assertArrayNotHasKey( 'zone_name', $result );
		$this->assertArrayNotHasKey( 'zone_order', $result );

		$this->assertIsInt( $result['id'] );
		$this->assertIsString( $result['name'] );
		$this->assertIsInt( $result['order'] );
	}

	public function test_subscriber_is_denied(): void {
		$this->actingAs( 'subscriber' );

		$ability = wp_get_ability( 'wc-shipping/create-shipping-zone' );

		$this->assertFalse( $ability->check_permissions( array( 'name' => 'Denied' ) ) );

		$result = $ability->execute( array( 'name' => 'Denied' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}
}
