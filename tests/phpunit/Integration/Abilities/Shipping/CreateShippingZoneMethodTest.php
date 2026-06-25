<?php
/**
 * Integration tests for the og-wc-shipping/create-shipping-zone-method ability.
 *
 * @package AbilitiesCatalogWoo\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogWoo\Tests\Integration\Abilities\Shipping;

use GalatanOvidiu\AbilitiesCatalogWoo\Tests\TestCase;
use WP_Error;
use WC_Shipping_Zone;

/**
 * Exercises og-wc-shipping/create-shipping-zone-method: the happy-path create
 * returning a shaped instance with instance_id > 0, a settings value round-tripping
 * into settings_summary, the route's 404 for an unknown zone surfaced via RestError
 * (not a permission collapse), the missing-required-field rejection, the wrong-cap
 * denial, and the exact closed output shape.
 */
final class CreateShippingZoneMethodTest extends TestCase {

	/**
	 * The closed key set the ability returns: the ShippingZoneMethodListShaper
	 * summary fields.
	 *
	 * @var list<string>
	 */
	private const EXPECTED_KEYS = array(
		'instance_id',
		'method_id',
		'title',
		'enabled',
		'order',
		'settings_summary',
	);

	/**
	 * Seeds a saved shipping zone and returns its id.
	 *
	 * @param string $name The zone name.
	 * @return int The new zone id.
	 */
	private function seedZone( string $name = 'Test Zone' ): int {
		$zone = new WC_Shipping_Zone();
		$zone->set_zone_name( $name );

		return (int) $zone->save();
	}

	public function test_ability_is_registered(): void {
		$ability = wp_get_ability( 'og-wc-shipping/create-shipping-zone-method' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'og-wc-shipping/create-shipping-zone-method', $ability->get_name() );
	}

	public function test_admin_adds_flat_rate_method(): void {
		$this->actingAs( 'administrator' );

		$zone_id = $this->seedZone();

		$result = wp_get_ability( 'og-wc-shipping/create-shipping-zone-method' )->execute(
			array(
				'zone_id'   => $zone_id,
				'method_id' => 'flat_rate',
			)
		);

		$this->assertIsArray( $result );
		$this->assertGreaterThan( 0, $result['instance_id'] );
		$this->assertSame( 'flat_rate', $result['method_id'] );

		// The method was actually attached to the zone.
		$zone    = new WC_Shipping_Zone( $zone_id );
		$methods = $zone->get_shipping_methods();
		$this->assertArrayHasKey( $result['instance_id'], $methods );
	}

	public function test_settings_round_trip_into_settings_summary(): void {
		$this->actingAs( 'administrator' );

		$zone_id = $this->seedZone();

		$result = wp_get_ability( 'og-wc-shipping/create-shipping-zone-method' )->execute(
			array(
				'zone_id'   => $zone_id,
				'method_id' => 'flat_rate',
				'settings'  => array( 'cost' => '5' ),
			)
		);

		$this->assertIsArray( $result );
		$summary = (array) $result['settings_summary'];
		$this->assertArrayHasKey( 'cost', $summary );
		$this->assertSame( '5', $summary['cost'] );
	}

	public function test_output_shape_is_exact_and_closed(): void {
		$this->actingAs( 'administrator' );

		$zone_id = $this->seedZone();

		$result = wp_get_ability( 'og-wc-shipping/create-shipping-zone-method' )->execute(
			array(
				'zone_id'   => $zone_id,
				'method_id' => 'flat_rate',
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( self::EXPECTED_KEYS, array_keys( $result ) );

		// No raw per-setting field descriptors or links leak through.
		$this->assertArrayNotHasKey( 'settings', $result );
		$this->assertArrayNotHasKey( '_links', $result );
		$this->assertArrayNotHasKey( 'method_description', $result );
	}

	public function test_unknown_zone_returns_route_404_not_permission_error(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'og-wc-shipping/create-shipping-zone-method' )->execute(
			array(
				'zone_id'   => 99999999,
				'method_id' => 'flat_rate',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'woocommerce_rest_shipping_zone_invalid', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] );
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_missing_required_method_id_is_rejected_at_input_validation(): void {
		$this->actingAs( 'administrator' );

		$zone_id = $this->seedZone();

		$result = wp_get_ability( 'og-wc-shipping/create-shipping-zone-method' )->execute(
			array( 'zone_id' => $zone_id )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_input', $result->get_error_code() );
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_subscriber_is_denied(): void {
		$this->actingAs( 'subscriber' );

		$zone_id = $this->seedZone();

		$ability = wp_get_ability( 'og-wc-shipping/create-shipping-zone-method' );

		$this->assertFalse(
			$ability->check_permissions(
				array(
					'zone_id'   => $zone_id,
					'method_id' => 'flat_rate',
				)
			)
		);

		$result = $ability->execute(
			array(
				'zone_id'   => $zone_id,
				'method_id' => 'flat_rate',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}
}
