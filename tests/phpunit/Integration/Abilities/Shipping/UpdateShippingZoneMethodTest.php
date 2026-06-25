<?php
/**
 * Integration tests for the og-wc-shipping/update-shipping-zone-method ability.
 *
 * @package AbilitiesCatalogWoo\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogWoo\Tests\Integration\Abilities\Shipping;

use GalatanOvidiu\AbilitiesCatalogWoo\Tests\TestCase;
use WC_Shipping_Zone;
use WP_Error;

/**
 * Exercises og-wc-shipping/update-shipping-zone-method: disabling a seeded flat_rate
 * instance, a partial settings change round-tripping into settings_summary, the
 * missing-instance and missing-zone 404s that must not collapse to a permission
 * error, missing required ids, the wrong-capability denial, and the exact closed
 * output shape.
 */
final class UpdateShippingZoneMethodTest extends TestCase {

	/**
	 * The full closed key set the ability returns for one updated method instance.
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
		$ability = wp_get_ability( 'og-wc-shipping/update-shipping-zone-method' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'og-wc-shipping/update-shipping-zone-method', $ability->get_name() );
	}

	public function test_admin_disables_method_instance(): void {
		$this->actingAs( 'administrator' );

		$seed = $this->seedZoneWithMethod();

		$result = wp_get_ability( 'og-wc-shipping/update-shipping-zone-method' )->execute(
			array(
				'zone_id'     => $seed['zone_id'],
				'instance_id' => $seed['instance_id'],
				'enabled'     => false,
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( $seed['instance_id'], $result['instance_id'] );
		$this->assertSame( 'flat_rate', $result['method_id'] );
		$this->assertFalse( $result['enabled'] );
	}

	public function test_settings_key_change_round_trips(): void {
		$this->actingAs( 'administrator' );

		$seed = $this->seedZoneWithMethod();

		$result = wp_get_ability( 'og-wc-shipping/update-shipping-zone-method' )->execute(
			array(
				'zone_id'     => $seed['zone_id'],
				'instance_id' => $seed['instance_id'],
				'settings'    => array( 'cost' => '99' ),
			)
		);

		$this->assertIsArray( $result );
		$this->assertIsObject( $result['settings_summary'] );

		$summary = (array) $result['settings_summary'];
		$this->assertArrayHasKey( 'cost', $summary );
		$this->assertSame( '99', $summary['cost'] );
	}

	public function test_output_shape_is_exact_and_closed(): void {
		$this->actingAs( 'administrator' );

		$seed = $this->seedZoneWithMethod();

		$result = wp_get_ability( 'og-wc-shipping/update-shipping-zone-method' )->execute(
			array(
				'zone_id'     => $seed['zone_id'],
				'instance_id' => $seed['instance_id'],
				'enabled'     => true,
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( self::EXPECTED_KEYS, array_keys( $result ) );

		// No raw REST fields leak through.
		$this->assertArrayNotHasKey( 'id', $result );
		$this->assertArrayNotHasKey( 'settings', $result );
		$this->assertArrayNotHasKey( '_links', $result );
	}

	public function test_missing_instance_returns_404_not_permission_error(): void {
		$this->actingAs( 'administrator' );

		$seed = $this->seedZoneWithMethod();

		$result = wp_get_ability( 'og-wc-shipping/update-shipping-zone-method' )->execute(
			array(
				'zone_id'     => $seed['zone_id'],
				'instance_id' => 99999999,
				'enabled'     => false,
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'woocommerce_rest_shipping_zone_method_invalid', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] );
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_missing_zone_returns_404_not_permission_error(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'og-wc-shipping/update-shipping-zone-method' )->execute(
			array(
				'zone_id'     => 99999999,
				'instance_id' => 1,
				'enabled'     => false,
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'woocommerce_rest_shipping_zone_invalid', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] );
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_missing_required_ids_are_rejected(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'og-wc-shipping/update-shipping-zone-method' )->execute(
			array( 'enabled' => false )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_input', $result->get_error_code() );
	}

	public function test_subscriber_is_denied(): void {
		$seed = $this->seedZoneWithMethod();

		$this->actingAs( 'subscriber' );

		$ability = wp_get_ability( 'og-wc-shipping/update-shipping-zone-method' );

		$this->assertFalse(
			$ability->check_permissions(
				array(
					'zone_id'     => $seed['zone_id'],
					'instance_id' => $seed['instance_id'],
					'enabled'     => false,
				)
			)
		);

		$result = $ability->execute(
			array(
				'zone_id'     => $seed['zone_id'],
				'instance_id' => $seed['instance_id'],
				'enabled'     => false,
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );

		// The method instance survived the denied write unchanged (still enabled).
		$zone    = new WC_Shipping_Zone( $seed['zone_id'] );
		$methods = $zone->get_shipping_methods();
		$this->assertArrayHasKey( $seed['instance_id'], $methods );
		$this->assertTrue( $methods[ $seed['instance_id'] ]->is_enabled() );
	}
}
