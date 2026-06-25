<?php
/**
 * Integration tests for the og-wc-shipping/update-shipping-zone ability.
 *
 * @package AbilitiesCatalogWoo\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogWoo\Tests\Integration\Abilities\Shipping;

use GalatanOvidiu\AbilitiesCatalogWoo\Tests\TestCase;
use WC_Shipping_Zone;
use WP_Error;

/**
 * Exercises og-wc-shipping/update-shipping-zone: renaming a seeded zone, the order
 * field being honored, the read-only zone 0 surfacing the route's 403 (not a
 * permission collapse, not a success), the missing-zone 404 that must not collapse
 * to a permission error, the wrong-capability denial (with the zone unchanged), and
 * the exact closed output shape (flat: id/name/order only).
 */
final class UpdateShippingZoneTest extends TestCase {

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
		$ability = wp_get_ability( 'og-wc-shipping/update-shipping-zone' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'og-wc-shipping/update-shipping-zone', $ability->get_name() );
	}

	public function test_admin_renames_seeded_zone(): void {
		$this->actingAs( 'administrator' );

		$zone = new WC_Shipping_Zone();
		$zone->set_zone_name( 'Old' );
		$zone->save();
		$zone_id = $zone->get_id();

		$result = wp_get_ability( 'og-wc-shipping/update-shipping-zone' )->execute(
			array(
				'id'   => $zone_id,
				'name' => 'New',
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( $zone_id, $result['id'] );
		$this->assertSame( 'New', $result['name'] );

		// The change persisted to the live zone.
		$reloaded = new WC_Shipping_Zone( $zone_id );
		$this->assertSame( 'New', $reloaded->get_zone_name() );
	}

	public function test_order_field_is_honored(): void {
		$this->actingAs( 'administrator' );

		$zone = new WC_Shipping_Zone();
		$zone->set_zone_name( 'Sortable' );
		$zone->save();
		$zone_id = $zone->get_id();

		$result = wp_get_ability( 'og-wc-shipping/update-shipping-zone' )->execute(
			array(
				'id'    => $zone_id,
				'order' => 7,
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( 7, $result['order'] );
		$this->assertSame( 7, ( new WC_Shipping_Zone( $zone_id ) )->get_zone_order() );
	}

	public function test_output_shape_is_exact_and_closed(): void {
		$this->actingAs( 'administrator' );

		$zone = new WC_Shipping_Zone();
		$zone->set_zone_name( 'Europe' );
		$zone->save();
		$zone_id = $zone->get_id();

		$result = wp_get_ability( 'og-wc-shipping/update-shipping-zone' )->execute(
			array(
				'id'   => $zone_id,
				'name' => 'Europe (EU)',
			)
		);

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

	public function test_zone_zero_is_read_only_and_surfaces_route_403(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'og-wc-shipping/update-shipping-zone' )->execute(
			array(
				'id'   => 0,
				'name' => 'Should Not Apply',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'woocommerce_rest_shipping_zone_invalid_zone', $result->get_error_code() );
		$this->assertSame( 403, $result->get_error_data()['status'] );

		// Not a permission collapse and not a silent success.
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code() );

		// Zone 0 keeps its read-only catch-all name.
		$this->assertSame( 'Locations not covered by your other zones', ( new WC_Shipping_Zone( 0 ) )->get_zone_name() );
	}

	public function test_missing_zone_returns_404_not_permission_error(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'og-wc-shipping/update-shipping-zone' )->execute(
			array(
				'id'   => 99999999,
				'name' => 'Nowhere',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'woocommerce_rest_shipping_zone_invalid', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] );
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_subscriber_is_denied_and_zone_unchanged(): void {
		$zone = new WC_Shipping_Zone();
		$zone->set_zone_name( 'Protected' );
		$zone->save();
		$zone_id = $zone->get_id();

		$this->actingAs( 'subscriber' );

		$ability = wp_get_ability( 'og-wc-shipping/update-shipping-zone' );

		$this->assertFalse(
			$ability->check_permissions(
				array(
					'id'   => $zone_id,
					'name' => 'Hijacked',
				)
			)
		);

		$result = $ability->execute(
			array(
				'id'   => $zone_id,
				'name' => 'Hijacked',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );

		// The denied write did not change the zone.
		$this->assertSame( 'Protected', ( new WC_Shipping_Zone( $zone_id ) )->get_zone_name() );
	}
}
