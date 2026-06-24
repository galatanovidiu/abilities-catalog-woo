<?php
/**
 * Integration tests for the wc-shipping/get-shipping-zone-method ability.
 *
 * @package AbilitiesCatalogWoo\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogWoo\Tests\Integration\Abilities\Shipping;

use GalatanOvidiu\AbilitiesCatalogWoo\Tests\TestCase;
use WC_Shipping_Zone;
use WP_Error;

/**
 * Exercises wc-shipping/get-shipping-zone-method: the shaped single method-instance
 * detail row on a seeded zone, the missing-instance and missing-zone 404s that must
 * not collapse to a permission error, the wrong-capability denial, and the exact
 * closed output shape (no raw per-setting object leaks through settings_summary).
 */
final class GetShippingZoneMethodTest extends TestCase {

	/**
	 * The full closed key set the ability returns for one method instance.
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
		'method_title',
		'method_description',
	);

	/**
	 * Seeds a shipping zone with one flat_rate method instance.
	 *
	 * @return array{zone_id:int, instance_id:int} The new zone id and the instance
	 *                                              id returned by add_shipping_method.
	 */
	private function seedZoneWithMethod(): array {
		$zone = new WC_Shipping_Zone();
		$zone->set_zone_name( 'Test Zone' );
		$zone->save();

		$instance_id = $zone->add_shipping_method( 'flat_rate' );
		$zone->save();

		return array(
			'zone_id'     => (int) $zone->get_id(),
			'instance_id' => (int) $instance_id,
		);
	}

	public function test_ability_is_registered(): void {
		$ability = wp_get_ability( 'wc-shipping/get-shipping-zone-method' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'wc-shipping/get-shipping-zone-method', $ability->get_name() );
	}

	public function test_admin_reads_zone_method_detail(): void {
		$this->actingAs( 'administrator' );

		$seed = $this->seedZoneWithMethod();

		$result = wp_get_ability( 'wc-shipping/get-shipping-zone-method' )->execute(
			array(
				'zone_id'     => $seed['zone_id'],
				'instance_id' => $seed['instance_id'],
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( $seed['instance_id'], $result['instance_id'] );
		$this->assertSame( 'flat_rate', $result['method_id'] );
		$this->assertIsString( $result['title'] );
		$this->assertIsBool( $result['enabled'] );
		$this->assertIsInt( $result['order'] );
		$this->assertIsObject( $result['settings_summary'] );
		$this->assertSame( 'Flat rate', $result['method_title'] );
		$this->assertIsString( $result['method_description'] );
	}

	public function test_output_shape_is_exact_and_closed(): void {
		$this->actingAs( 'administrator' );

		$seed = $this->seedZoneWithMethod();

		$result = wp_get_ability( 'wc-shipping/get-shipping-zone-method' )->execute(
			array(
				'zone_id'     => $seed['zone_id'],
				'instance_id' => $seed['instance_id'],
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( self::EXPECTED_KEYS, array_keys( $result ) );

		// No raw REST fields or the full per-setting object leak through.
		$this->assertArrayNotHasKey( 'id', $result );
		$this->assertArrayNotHasKey( 'settings', $result );
		$this->assertArrayNotHasKey( '_links', $result );

		// settings_summary collapses each setting to its scalar value, never the
		// full {label,description,type,value,...} descriptor.
		foreach ( (array) $result['settings_summary'] as $value ) {
			$this->assertFalse( is_array( $value ) && isset( $value['label'] ) );
		}
	}

	public function test_missing_instance_returns_404_not_permission_error(): void {
		$this->actingAs( 'administrator' );

		$seed = $this->seedZoneWithMethod();

		$result = wp_get_ability( 'wc-shipping/get-shipping-zone-method' )->execute(
			array(
				'zone_id'     => $seed['zone_id'],
				'instance_id' => 99999999,
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'woocommerce_rest_shipping_zone_method_invalid', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] );
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_missing_zone_returns_404_not_permission_error(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'wc-shipping/get-shipping-zone-method' )->execute(
			array(
				'zone_id'     => 99999999,
				'instance_id' => 1,
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'woocommerce_rest_shipping_zone_invalid', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] );
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_subscriber_is_denied(): void {
		$seed = $this->seedZoneWithMethod();

		$this->actingAs( 'subscriber' );

		$ability = wp_get_ability( 'wc-shipping/get-shipping-zone-method' );

		$this->assertFalse(
			$ability->check_permissions(
				array(
					'zone_id'     => $seed['zone_id'],
					'instance_id' => $seed['instance_id'],
				)
			)
		);

		$result = $ability->execute(
			array(
				'zone_id'     => $seed['zone_id'],
				'instance_id' => $seed['instance_id'],
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}
}
