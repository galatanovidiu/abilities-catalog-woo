<?php
/**
 * Integration tests for the `wc-shipping/list-shipping-zone-methods` ability.
 *
 * @package AbilitiesCatalogWoo\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogWoo\Tests\Integration\Abilities\Shipping;

use GalatanOvidiu\AbilitiesCatalogWoo\Tests\TestCase;
use WC_Shipping_Zone;
use WP_Error;

/**
 * @covers \GalatanOvidiu\AbilitiesCatalogWoo\Abilities\Woo\Shipping\ListShippingZoneMethods
 */
final class ListShippingZoneMethodsTest extends TestCase {

	private const ABILITY = 'wc-shipping/list-shipping-zone-methods';

	/**
	 * The exact keys a shaped zone-method summary row exposes.
	 *
	 * Asserting against this fixed set proves the raw per-setting field descriptors
	 * (label/description/type/default/tip/placeholder) and `_links` are never
	 * leaked: only these projected fields, with `settings` collapsed to
	 * `settings_summary`, reach the consumer.
	 *
	 * @var list<string>
	 */
	private const ROW_KEYS = array(
		'instance_id',
		'method_id',
		'title',
		'enabled',
		'order',
		'settings_summary',
	);

	public function test_registered(): void {
		$ability = wp_get_ability( self::ABILITY );

		$this->assertNotNull( $ability );
		$this->assertSame( self::ABILITY, $ability->get_name() );
	}

	public function test_happy_path_returns_shaped_rows(): void {
		$this->actingAs( 'administrator' );
		$zone_id = $this->seedZoneWithMethod();

		$result = wp_get_ability( self::ABILITY )->execute( array( 'zone_id' => $zone_id ) );

		$this->assertIsArray( $result );
		$this->assertSame( array( 'zone_id', 'items', 'total' ), array_keys( $result ) );
		$this->assertSame( $zone_id, $result['zone_id'] );
		$this->assertIsArray( $result['items'] );
		$this->assertIsInt( $result['total'] );
		$this->assertSame( count( $result['items'] ), $result['total'] );
		$this->assertGreaterThanOrEqual( 1, $result['total'] );
		$this->assertNotEmpty( $result['items'] );

		$method_ids = wp_list_pluck( $result['items'], 'method_id' );
		$this->assertContains( 'flat_rate', $method_ids );

		$row = $result['items'][0];
		$this->assertSame( self::ROW_KEYS, array_keys( $row ) );
		$this->assertIsInt( $row['instance_id'] );
		$this->assertIsString( $row['method_id'] );
		$this->assertIsString( $row['title'] );
		$this->assertIsBool( $row['enabled'] );
		$this->assertIsInt( $row['order'] );
		$this->assertIsObject( $row['settings_summary'] );
	}

	public function test_unconfigured_zone_returns_empty_items(): void {
		$this->actingAs( 'administrator' );

		$zone    = new WC_Shipping_Zone();
		$zone->set_zone_name( 'Empty Zone' );
		$zone_id = $zone->save();

		$result = wp_get_ability( self::ABILITY )->execute( array( 'zone_id' => $zone_id ) );

		$this->assertIsArray( $result );
		$this->assertSame( $zone_id, $result['zone_id'] );
		$this->assertSame( array(), $result['items'] );
		$this->assertSame( 0, $result['total'] );
	}

	public function test_missing_zone_returns_404_not_permission_collapse(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( self::ABILITY )->execute( array( 'zone_id' => 99999999 ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'woocommerce_rest_shipping_zone_invalid', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] );
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_missing_required_zone_id_is_rejected(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( self::ABILITY )->execute( array() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_input', $result->get_error_code() );
	}

	public function test_output_shape_has_no_raw_settings_fields(): void {
		$this->actingAs( 'administrator' );
		$zone_id = $this->seedZoneWithMethod();

		$result = wp_get_ability( self::ABILITY )->execute( array( 'zone_id' => $zone_id ) );

		$this->assertSame( array( 'zone_id', 'items', 'total' ), array_keys( $result ) );
		$row = $result['items'][0];
		$this->assertSame( self::ROW_KEYS, array_keys( $row ) );
		$this->assertArrayNotHasKey( 'settings', $row );
		$this->assertArrayNotHasKey( 'method_title', $row );
		$this->assertArrayNotHasKey( 'method_description', $row );
		$this->assertArrayNotHasKey( '_links', $row );
	}

	public function test_wrong_capability_is_denied(): void {
		$zone_id = $this->seedZoneWithMethod();
		$this->actingAs( 'subscriber' );

		$result = wp_get_ability( self::ABILITY )->execute( array( 'zone_id' => $zone_id ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_logged_out_user_is_denied(): void {
		$zone_id = $this->seedZoneWithMethod();
		wp_set_current_user( 0 );

		$result = wp_get_ability( self::ABILITY )->execute( array( 'zone_id' => $zone_id ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	/**
	 * Seeds a shipping zone with one flat-rate method instance and returns its ID.
	 *
	 * Builds the zone with WooCommerce's runtime object API (WC_Shipping_Zone),
	 * which loads with the plugin, rather than a WC_Helper_* test factory the
	 * distributed WooCommerce build does not ship.
	 *
	 * @return int The created zone's ID.
	 */
	private function seedZoneWithMethod(): int {
		$zone = new WC_Shipping_Zone();
		$zone->set_zone_name( 'Test Zone' );
		$zone->add_shipping_method( 'flat_rate' );
		$zone->save();

		return (int) $zone->get_id();
	}
}
