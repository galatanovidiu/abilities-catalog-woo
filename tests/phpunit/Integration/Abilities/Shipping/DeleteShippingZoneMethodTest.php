<?php
/**
 * Integration tests for the wc-shipping/delete-shipping-zone-method ability.
 *
 * @package AbilitiesCatalogWoo\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogWoo\Tests\Integration\Abilities\Shipping;

use GalatanOvidiu\AbilitiesCatalogWoo\Tests\TestCase;
use WC_Shipping_Zone;
use WP_Error;

/**
 * Exercises wc-shipping/delete-shipping-zone-method: permanently deleting a seeded
 * flat_rate instance and confirming it is actually gone from the zone, the
 * missing-instance and missing-zone 404s that must not collapse to a permission
 * error, missing required ids, the wrong-capability denial, and the exact closed
 * output shape with no edit_link.
 */
final class DeleteShippingZoneMethodTest extends TestCase {

	/**
	 * The full closed key set the ability returns for one deleted method instance.
	 *
	 * @var list<string>
	 */
	private const EXPECTED_KEYS = array(
		'deleted',
		'id',
		'name',
		'permanent',
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
		$ability = wp_get_ability( 'wc-shipping/delete-shipping-zone-method' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'wc-shipping/delete-shipping-zone-method', $ability->get_name() );
	}

	public function test_admin_deletes_method_instance(): void {
		$this->actingAs( 'administrator' );

		$seed = $this->seedZoneWithMethod();

		$result = wp_get_ability( 'wc-shipping/delete-shipping-zone-method' )->execute(
			array(
				'zone_id'     => $seed['zone_id'],
				'instance_id' => $seed['instance_id'],
			)
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['deleted'] );
		$this->assertSame( $seed['instance_id'], $result['id'] );
		$this->assertSame( 'Flat rate', $result['name'] );
		$this->assertTrue( $result['permanent'] );

		// The method instance is actually gone from the zone — never trust 'deleted' alone.
		$zone    = new WC_Shipping_Zone( $seed['zone_id'] );
		$methods = $zone->get_shipping_methods();
		$this->assertArrayNotHasKey( $seed['instance_id'], $methods );
	}

	public function test_output_shape_is_exact_and_closed(): void {
		$this->actingAs( 'administrator' );

		$seed = $this->seedZoneWithMethod();

		$result = wp_get_ability( 'wc-shipping/delete-shipping-zone-method' )->execute(
			array(
				'zone_id'     => $seed['zone_id'],
				'instance_id' => $seed['instance_id'],
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( self::EXPECTED_KEYS, array_keys( $result ) );

		// No dead-end edit link and no raw REST instance fields leak through.
		$this->assertArrayNotHasKey( 'edit_link', $result );
		$this->assertArrayNotHasKey( 'instance_id', $result );
		$this->assertArrayNotHasKey( 'method_id', $result );
		$this->assertArrayNotHasKey( 'settings', $result );
		$this->assertArrayNotHasKey( '_links', $result );
	}

	public function test_missing_instance_returns_404_not_permission_error(): void {
		$this->actingAs( 'administrator' );

		$seed = $this->seedZoneWithMethod();

		$result = wp_get_ability( 'wc-shipping/delete-shipping-zone-method' )->execute(
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

		$result = wp_get_ability( 'wc-shipping/delete-shipping-zone-method' )->execute(
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

	public function test_missing_required_ids_are_rejected(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'wc-shipping/delete-shipping-zone-method' )->execute(
			array( 'zone_id' => 1 )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_input', $result->get_error_code() );
	}

	public function test_subscriber_is_denied(): void {
		$seed = $this->seedZoneWithMethod();

		$this->actingAs( 'subscriber' );

		$ability = wp_get_ability( 'wc-shipping/delete-shipping-zone-method' );

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

		// The method instance survived the denied delete.
		$zone    = new WC_Shipping_Zone( $seed['zone_id'] );
		$methods = $zone->get_shipping_methods();
		$this->assertArrayHasKey( $seed['instance_id'], $methods );
	}
}
