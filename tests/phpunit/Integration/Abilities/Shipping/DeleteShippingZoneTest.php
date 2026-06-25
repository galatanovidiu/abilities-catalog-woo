<?php
/**
 * Integration tests for the og-wc-shipping/delete-shipping-zone ability.
 *
 * @package AbilitiesCatalogWoo\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogWoo\Tests\Integration\Abilities\Shipping;

use GalatanOvidiu\AbilitiesCatalogWoo\Tests\TestCase;
use WC_Shipping_Zone;
use WC_Shipping_Zones;
use WP_Error;

/**
 * Exercises og-wc-shipping/delete-shipping-zone: the permanent force-delete of a
 * seeded zone with a name captured before deletion AND a follow-up assertion that
 * the zone is actually gone, the missing-zone 404 that must not collapse to a
 * permission error, the wrong-capability denial, and the exact closed output shape
 * (flat: deleted/id/name/permanent only, no edit_link, no raw zone fields).
 */
final class DeleteShippingZoneTest extends TestCase {

	/**
	 * The full closed key set the ability returns for a deleted shipping zone.
	 *
	 * @var list<string>
	 */
	private const EXPECTED_KEYS = array(
		'deleted',
		'id',
		'name',
		'permanent',
	);

	public function test_ability_is_registered(): void {
		$ability = wp_get_ability( 'og-wc-shipping/delete-shipping-zone' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'og-wc-shipping/delete-shipping-zone', $ability->get_name() );
	}

	public function test_admin_deletes_zone_and_it_is_gone(): void {
		$this->actingAs( 'administrator' );

		$zone = new WC_Shipping_Zone();
		$zone->set_zone_name( 'Europe' );
		$zone->save();
		$zone_id = $zone->get_id();

		$result = wp_get_ability( 'og-wc-shipping/delete-shipping-zone' )->execute( array( 'id' => $zone_id ) );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['deleted'] );
		$this->assertSame( $zone_id, $result['id'] );
		$this->assertSame( 'Europe', $result['name'] );
		$this->assertTrue( $result['permanent'] );

		// The zone is actually gone — never trust the deleted flag alone.
		$this->assertFalse( WC_Shipping_Zones::get_zone_by( 'zone_id', $zone_id ) );
	}

	public function test_output_shape_is_exact_and_closed(): void {
		$this->actingAs( 'administrator' );

		$zone = new WC_Shipping_Zone();
		$zone->set_zone_name( 'Shaped Zone' );
		$zone->save();
		$zone_id = $zone->get_id();

		$result = wp_get_ability( 'og-wc-shipping/delete-shipping-zone' )->execute( array( 'id' => $zone_id ) );

		$this->assertIsArray( $result );
		$this->assertSame( self::EXPECTED_KEYS, array_keys( $result ) );

		// A delete returns no dead-end edit link, and no raw zone fields leak.
		$this->assertArrayNotHasKey( 'edit_link', $result );
		$this->assertArrayNotHasKey( '_links', $result );
		$this->assertArrayNotHasKey( 'zone_name', $result );
		$this->assertArrayNotHasKey( 'order', $result );

		$this->assertIsBool( $result['deleted'] );
		$this->assertIsInt( $result['id'] );
		$this->assertIsString( $result['name'] );
		$this->assertIsBool( $result['permanent'] );
	}

	public function test_missing_zone_returns_404_not_permission_error(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'og-wc-shipping/delete-shipping-zone' )->execute( array( 'id' => 99999999 ) );

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

		$ability = wp_get_ability( 'og-wc-shipping/delete-shipping-zone' );

		$this->assertFalse( $ability->check_permissions( array( 'id' => $zone_id ) ) );

		$result = $ability->execute( array( 'id' => $zone_id ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );

		// The zone survived the denied attempt.
		$this->assertNotFalse( WC_Shipping_Zones::get_zone_by( 'zone_id', $zone_id ) );
	}
}
