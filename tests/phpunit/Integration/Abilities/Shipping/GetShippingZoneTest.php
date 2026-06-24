<?php
/**
 * Integration tests for the wc-shipping/get-shipping-zone ability.
 *
 * @package AbilitiesCatalogWoo\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogWoo\Tests\Integration\Abilities\Shipping;

use GalatanOvidiu\AbilitiesCatalogWoo\Tests\TestCase;
use WC_Shipping_Zone;
use WP_Error;

/**
 * Exercises wc-shipping/get-shipping-zone: the shaped single zone record (seeded
 * zone and the always-present zone 0), the missing-zone 404 that must not collapse
 * to a permission error, the wrong-capability denial, and the exact closed output
 * shape (flat: id/name/order only).
 */
final class GetShippingZoneTest extends TestCase {

	/**
	 * The full closed key set the ability returns for one shipping zone.
	 *
	 * @var list<string>
	 */
	private const EXPECTED_KEYS = array(
		'id',
		'name',
		'order',
	);

	public function test_ability_is_registered(): void {
		$ability = wp_get_ability( 'wc-shipping/get-shipping-zone' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'wc-shipping/get-shipping-zone', $ability->get_name() );
	}

	public function test_admin_reads_seeded_zone_detail(): void {
		$this->actingAs( 'administrator' );

		$zone = new WC_Shipping_Zone();
		$zone->set_zone_name( 'Domestic' );
		$zone->save();
		$zone_id = $zone->get_id();

		$result = wp_get_ability( 'wc-shipping/get-shipping-zone' )->execute( array( 'id' => $zone_id ) );

		$this->assertIsArray( $result );
		$this->assertSame( $zone_id, $result['id'] );
		$this->assertSame( 'Domestic', $result['name'] );
		$this->assertIsInt( $result['order'] );
	}

	public function test_admin_reads_rest_of_the_world_zone_zero(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'wc-shipping/get-shipping-zone' )->execute( array( 'id' => 0 ) );

		$this->assertIsArray( $result );
		$this->assertSame( 0, $result['id'] );
		$this->assertSame( self::EXPECTED_KEYS, array_keys( $result ) );
	}

	public function test_output_shape_is_exact_and_closed(): void {
		$this->actingAs( 'administrator' );

		$zone = new WC_Shipping_Zone();
		$zone->set_zone_name( 'Europe' );
		$zone->save();
		$zone_id = $zone->get_id();

		$result = wp_get_ability( 'wc-shipping/get-shipping-zone' )->execute( array( 'id' => $zone_id ) );

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

	public function test_missing_zone_returns_404_not_permission_error(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'wc-shipping/get-shipping-zone' )->execute( array( 'id' => 99999999 ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'woocommerce_rest_shipping_zone_invalid', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] );
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_subscriber_is_denied(): void {
		$zone = new WC_Shipping_Zone();
		$zone->set_zone_name( 'Denied' );
		$zone->save();
		$zone_id = $zone->get_id();

		$this->actingAs( 'subscriber' );

		$ability = wp_get_ability( 'wc-shipping/get-shipping-zone' );

		$this->assertFalse( $ability->check_permissions( array( 'id' => $zone_id ) ) );

		$result = $ability->execute( array( 'id' => $zone_id ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}
}
