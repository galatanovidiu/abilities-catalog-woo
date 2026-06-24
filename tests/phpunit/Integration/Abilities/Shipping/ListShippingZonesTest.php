<?php
/**
 * Integration tests for the `wc-shipping/list-shipping-zones` ability.
 *
 * @package AbilitiesCatalogWoo\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogWoo\Tests\Integration\Abilities\Shipping;

use GalatanOvidiu\AbilitiesCatalogWoo\Tests\TestCase;
use WC_Shipping_Zone;
use WP_Error;

/**
 * @covers \GalatanOvidiu\AbilitiesCatalogWoo\Abilities\Woo\Shipping\ListShippingZones
 */
final class ListShippingZonesTest extends TestCase {

	private const ABILITY = 'wc-shipping/list-shipping-zones';

	/**
	 * The exact keys a shaped shipping-zone summary row exposes.
	 *
	 * Asserting against this fixed set proves the raw zone body is never leaked:
	 * only these projected fields reach the consumer.
	 *
	 * @var list<string>
	 */
	private const ROW_KEYS = array(
		'id',
		'name',
		'order',
	);

	public function test_registered(): void {
		$ability = wp_get_ability( self::ABILITY );

		$this->assertNotNull( $ability );
		$this->assertSame( self::ABILITY, $ability->get_name() );
	}

	public function test_happy_path_always_includes_rest_of_the_world(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( self::ABILITY )->execute( array() );

		$this->assertIsArray( $result );
		$this->assertSame( array( 'items', 'total' ), array_keys( $result ) );
		$this->assertIsArray( $result['items'] );
		$this->assertIsInt( $result['total'] );
		$this->assertNotEmpty( $result['items'] );
		$this->assertGreaterThanOrEqual( 1, $result['total'] );

		$ids = wp_list_pluck( $result['items'], 'id' );
		$this->assertContains( 0, $ids, 'Zone 0 "Rest of the World" must always be present.' );
	}

	public function test_seeded_zone_appears(): void {
		$this->actingAs( 'administrator' );
		$zone_id = $this->seedZone( 'Domestic' );

		$result = wp_get_ability( self::ABILITY )->execute( array() );

		$names = wp_list_pluck( $result['items'], 'name' );
		$this->assertContains( 'Domestic', $names );

		$ids = wp_list_pluck( $result['items'], 'id' );
		$this->assertContains( $zone_id, $ids );
		$this->assertContains( 0, $ids );
	}

	public function test_output_shape_has_no_raw_zone_fields(): void {
		$this->actingAs( 'administrator' );
		$this->seedZone( 'Europe' );

		$result = wp_get_ability( self::ABILITY )->execute( array() );

		$this->assertSame( array( 'items', 'total' ), array_keys( $result ) );

		$row = $result['items'][0];
		$this->assertSame( self::ROW_KEYS, array_keys( $row ) );
		$this->assertIsInt( $row['id'] );
		$this->assertIsString( $row['name'] );
		$this->assertIsInt( $row['order'] );
		$this->assertArrayNotHasKey( '_links', $row );
		$this->assertArrayNotHasKey( 'zone_name', $row );
		$this->assertArrayNotHasKey( 'zone_order', $row );
	}

	public function test_wrong_capability_is_denied(): void {
		$this->seedZone( 'Domestic' );
		$this->actingAs( 'subscriber' );

		$ability = wp_get_ability( self::ABILITY );
		$this->assertFalse( $ability->check_permissions( array() ) );

		$result = $ability->execute( array() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_logged_out_user_is_denied(): void {
		$this->seedZone( 'Domestic' );
		wp_set_current_user( 0 );

		$result = wp_get_ability( self::ABILITY )->execute( array() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	/**
	 * Seeds a WooCommerce shipping zone and returns its ID.
	 *
	 * Uses the runtime `WC_Shipping_Zone` object API, which loads with the plugin,
	 * so no WC_Helper_* factory is needed.
	 *
	 * @param string $name The zone name.
	 * @return int The created zone ID.
	 */
	private function seedZone( string $name ): int {
		$zone = new WC_Shipping_Zone();
		$zone->set_zone_name( $name );
		$zone->save();

		return (int) $zone->get_id();
	}
}
