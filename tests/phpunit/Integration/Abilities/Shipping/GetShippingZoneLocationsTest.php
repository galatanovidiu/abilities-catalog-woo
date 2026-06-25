<?php
/**
 * Integration tests for the og-wc-shipping/get-shipping-zone-locations ability.
 *
 * @package AbilitiesCatalogWoo\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogWoo\Tests\Integration\Abilities\Shipping;

use GalatanOvidiu\AbilitiesCatalogWoo\Tests\TestCase;
use WC_Shipping_Zone;
use WP_Error;

/**
 * Exercises og-wc-shipping/get-shipping-zone-locations: the shaped location rules of a
 * seeded zone, the empty-locations case for an unconfigured zone, the missing-zone
 * 404 that must not collapse to a permission error, the wrong-capability denial, and
 * the exact closed output shape (each row { code, type } only).
 */
final class GetShippingZoneLocationsTest extends TestCase {

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
		$ability = wp_get_ability( 'og-wc-shipping/get-shipping-zone-locations' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'og-wc-shipping/get-shipping-zone-locations', $ability->get_name() );
	}

	public function test_admin_reads_seeded_zone_locations(): void {
		$this->actingAs( 'administrator' );

		$zone_id = $this->seedZone( array( array( 'code' => 'US', 'type' => 'country' ) ) );

		$result = wp_get_ability( 'og-wc-shipping/get-shipping-zone-locations' )->execute( array( 'id' => $zone_id ) );

		$this->assertIsArray( $result );
		$this->assertSame( $zone_id, $result['id'] );
		$this->assertIsArray( $result['locations'] );
		$this->assertCount( 1, $result['locations'] );
		$this->assertSame( 'US', $result['locations'][0]['code'] );
		$this->assertSame( 'country', $result['locations'][0]['type'] );
		$this->assertSame( 1, $result['total'] );
	}

	public function test_unconfigured_zone_returns_empty_locations_not_error(): void {
		$this->actingAs( 'administrator' );

		$zone_id = $this->seedZone();

		$result = wp_get_ability( 'og-wc-shipping/get-shipping-zone-locations' )->execute( array( 'id' => $zone_id ) );

		$this->assertIsArray( $result );
		$this->assertSame( $zone_id, $result['id'] );
		$this->assertSame( array(), $result['locations'] );
		$this->assertSame( 0, $result['total'] );
	}

	public function test_output_shape_is_exact_and_closed(): void {
		$this->actingAs( 'administrator' );

		$zone_id = $this->seedZone( array( array( 'code' => 'GB', 'type' => 'country' ) ) );

		$result = wp_get_ability( 'og-wc-shipping/get-shipping-zone-locations' )->execute( array( 'id' => $zone_id ) );

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

		$result = wp_get_ability( 'og-wc-shipping/get-shipping-zone-locations' )->execute( array( 'id' => 99999999 ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'woocommerce_rest_shipping_zone_invalid', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] );
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_subscriber_is_denied(): void {
		$zone_id = $this->seedZone( array( array( 'code' => 'US', 'type' => 'country' ) ) );

		$this->actingAs( 'subscriber' );

		$ability = wp_get_ability( 'og-wc-shipping/get-shipping-zone-locations' );

		$this->assertFalse( $ability->check_permissions( array( 'id' => $zone_id ) ) );

		$result = $ability->execute( array( 'id' => $zone_id ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}
}
