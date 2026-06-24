<?php
/**
 * Integration tests for the wc-shipping/update-shipping-zone-locations ability.
 *
 * @package AbilitiesCatalogWoo\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogWoo\Tests\Integration\Abilities\Shipping;

use GalatanOvidiu\AbilitiesCatalogWoo\Tests\TestCase;
use WC_Shipping_Zone;
use WP_Error;

/**
 * Exercises wc-shipping/update-shipping-zone-locations: the FULL-REPLACE behavior
 * (an omitted location is dropped), clearing a zone with an empty array, the
 * missing-zone 404 that must not collapse to a permission error, the missing-required
 * input rejection, the wrong-capability denial, and the exact closed output shape
 * (each row { code, type } only).
 */
final class UpdateShippingZoneLocationsTest extends TestCase {

	/**
	 * The full closed key set the ability returns.
	 *
	 * @var list<string>
	 */
	private const EXPECTED_KEYS = array(
		'id',
		'locations',
		'total',
	);

	/**
	 * The full closed key set of a single location row.
	 *
	 * @var list<string>
	 */
	private const EXPECTED_ROW_KEYS = array(
		'code',
		'type',
	);

	/**
	 * Seeds a shipping zone with the given locations and returns its ID.
	 *
	 * @param array<int,array{code:string,type:string}> $locations Location match rules.
	 * @return int The new zone ID.
	 */
	private function seedZone( array $locations = array() ): int {
		$zone = new WC_Shipping_Zone();
		$zone->set_zone_name( 'Test Zone' );
		if ( array() !== $locations ) {
			$zone->set_locations( $locations );
		}
		$zone->save();

		return (int) $zone->get_id();
	}

	public function test_ability_is_registered(): void {
		$ability = wp_get_ability( 'wc-shipping/update-shipping-zone-locations' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'wc-shipping/update-shipping-zone-locations', $ability->get_name() );
	}

	public function test_full_replace_drops_omitted_location(): void {
		$this->actingAs( 'administrator' );

		$zone_id = $this->seedZone(
			array(
				array( 'code' => 'US', 'type' => 'country' ),
				array( 'code' => 'CA', 'type' => 'country' ),
			)
		);

		$result = wp_get_ability( 'wc-shipping/update-shipping-zone-locations' )->execute(
			array(
				'id'        => $zone_id,
				'locations' => array(
					array( 'code' => 'US', 'type' => 'country' ),
				),
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( $zone_id, $result['id'] );
		$this->assertSame( 1, $result['total'] );
		$this->assertCount( 1, $result['locations'] );

		$codes = array_column( $result['locations'], 'code' );
		$this->assertContains( 'US', $codes );
		$this->assertNotContains( 'CA', $codes, 'The omitted CA location must be dropped by the full replace.' );

		// Confirm the drop persisted on the zone, not just in the response.
		$zone = new WC_Shipping_Zone( $zone_id );
		$persisted_codes = array_column( $zone->get_zone_locations(), 'code' );
		$this->assertContains( 'US', $persisted_codes );
		$this->assertNotContains( 'CA', $persisted_codes );
	}

	public function test_type_is_honored(): void {
		$this->actingAs( 'administrator' );

		$zone_id = $this->seedZone( array( array( 'code' => 'US', 'type' => 'country' ) ) );

		$result = wp_get_ability( 'wc-shipping/update-shipping-zone-locations' )->execute(
			array(
				'id'        => $zone_id,
				'locations' => array(
					array( 'code' => 'US:CA', 'type' => 'state' ),
				),
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'US:CA', $result['locations'][0]['code'] );
		$this->assertSame( 'state', $result['locations'][0]['type'] );
	}

	public function test_empty_locations_array_clears_the_zone(): void {
		$this->actingAs( 'administrator' );

		$zone_id = $this->seedZone( array( array( 'code' => 'US', 'type' => 'country' ) ) );

		$result = wp_get_ability( 'wc-shipping/update-shipping-zone-locations' )->execute(
			array(
				'id'        => $zone_id,
				'locations' => array(),
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( array(), $result['locations'] );
		$this->assertSame( 0, $result['total'] );

		$zone = new WC_Shipping_Zone( $zone_id );
		$this->assertSame( array(), $zone->get_zone_locations() );
	}

	public function test_output_shape_is_exact_and_closed(): void {
		$this->actingAs( 'administrator' );

		$zone_id = $this->seedZone( array( array( 'code' => 'GB', 'type' => 'country' ) ) );

		$result = wp_get_ability( 'wc-shipping/update-shipping-zone-locations' )->execute(
			array(
				'id'        => $zone_id,
				'locations' => array(
					array( 'code' => 'GB', 'type' => 'country' ),
				),
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( self::EXPECTED_KEYS, array_keys( $result ) );

		$this->assertIsInt( $result['id'] );
		$this->assertIsArray( $result['locations'] );
		$this->assertIsInt( $result['total'] );

		$row = $result['locations'][0];
		$this->assertSame( self::EXPECTED_ROW_KEYS, array_keys( $row ) );
		$this->assertIsString( $row['code'] );
		$this->assertIsString( $row['type'] );

		// No raw location fields leak through.
		$this->assertArrayNotHasKey( '_links', $row );
	}

	public function test_missing_zone_returns_404_not_permission_error(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'wc-shipping/update-shipping-zone-locations' )->execute(
			array(
				'id'        => 99999999,
				'locations' => array(
					array( 'code' => 'US', 'type' => 'country' ),
				),
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'woocommerce_rest_shipping_zone_invalid', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] );
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_missing_required_locations_is_rejected(): void {
		$this->actingAs( 'administrator' );

		$zone_id = $this->seedZone( array( array( 'code' => 'US', 'type' => 'country' ) ) );

		$result = wp_get_ability( 'wc-shipping/update-shipping-zone-locations' )->execute(
			array( 'id' => $zone_id )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_input', $result->get_error_code() );

		// The seeded location survives the rejected call.
		$zone = new WC_Shipping_Zone( $zone_id );
		$this->assertContains( 'US', array_column( $zone->get_zone_locations(), 'code' ) );
	}

	public function test_missing_required_id_is_rejected(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'wc-shipping/update-shipping-zone-locations' )->execute(
			array(
				'locations' => array(
					array( 'code' => 'US', 'type' => 'country' ),
				),
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_input', $result->get_error_code() );
	}

	public function test_subscriber_is_denied(): void {
		$zone_id = $this->seedZone( array( array( 'code' => 'US', 'type' => 'country' ) ) );

		$this->actingAs( 'subscriber' );

		$ability = wp_get_ability( 'wc-shipping/update-shipping-zone-locations' );

		$this->assertFalse( $ability->check_permissions(
			array(
				'id'        => $zone_id,
				'locations' => array( array( 'code' => 'US', 'type' => 'country' ) ),
			)
		) );

		$result = $ability->execute(
			array(
				'id'        => $zone_id,
				'locations' => array( array( 'code' => 'CA', 'type' => 'country' ) ),
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );

		// The zone's locations are untouched by the denied call.
		$zone = new WC_Shipping_Zone( $zone_id );
		$codes = array_column( $zone->get_zone_locations(), 'code' );
		$this->assertContains( 'US', $codes );
		$this->assertNotContains( 'CA', $codes );
	}
}
