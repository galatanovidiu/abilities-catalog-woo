<?php
/**
 * Integration tests for the `wc-shipping/list-shipping-methods` ability.
 *
 * @package AbilitiesCatalogWoo\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogWoo\Tests\Integration\Abilities\Shipping;

use GalatanOvidiu\AbilitiesCatalogWoo\Tests\TestCase;
use WP_Error;

/**
 * @covers \GalatanOvidiu\AbilitiesCatalogWoo\Abilities\Woo\Shipping\ListShippingMethods
 */
final class ListShippingMethodsTest extends TestCase {

	private const ABILITY = 'wc-shipping/list-shipping-methods';

	/**
	 * The exact keys a shaped shipping-method-type row exposes.
	 *
	 * Asserting against this fixed set proves the raw registry body is never leaked:
	 * only these projected fields reach the consumer. No `_links` or other raw field
	 * leaks.
	 *
	 * @var list<string>
	 */
	private const ROW_KEYS = array(
		'id',
		'title',
		'description',
	);

	public function test_registered(): void {
		$ability = wp_get_ability( self::ABILITY );

		$this->assertNotNull( $ability );
		$this->assertSame( self::ABILITY, $ability->get_name() );
	}

	public function test_happy_path_returns_shaped_rows(): void {
		$this->actingAs( 'administrator' );

		// flat_rate / free_shipping / local_pickup are registered by default in the
		// WC test env, so no seeding is needed.
		$result = wp_get_ability( self::ABILITY )->execute( array() );

		$this->assertIsArray( $result );
		$this->assertSame( array( 'items', 'total' ), array_keys( $result ) );
		$this->assertIsArray( $result['items'] );
		$this->assertIsInt( $result['total'] );
		$this->assertNotEmpty( $result['items'] );
		$this->assertGreaterThanOrEqual( 3, $result['total'] );
		$this->assertCount( $result['total'], $result['items'] );

		$row = $result['items'][0];
		$this->assertSame( self::ROW_KEYS, array_keys( $row ) );
		$this->assertIsString( $row['id'] );
		$this->assertIsString( $row['title'] );
		$this->assertIsString( $row['description'] );

		$ids = wp_list_pluck( $result['items'], 'id' );
		$this->assertContains( 'flat_rate', $ids );
		$this->assertContains( 'free_shipping', $ids );
		$this->assertContains( 'local_pickup', $ids );
	}

	public function test_output_shape_has_no_raw_fields(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( self::ABILITY )->execute( array() );

		$this->assertSame( array( 'items', 'total' ), array_keys( $result ) );
		$row = $result['items'][0];
		$this->assertSame( self::ROW_KEYS, array_keys( $row ) );
		$this->assertArrayNotHasKey( '_links', $row );
		$this->assertArrayNotHasKey( 'instance_id', $row );
		$this->assertArrayNotHasKey( 'settings', $row );
		$this->assertArrayNotHasKey( 'enabled', $row );
	}

	public function test_wrong_capability_is_denied(): void {
		$this->actingAs( 'subscriber' );

		$ability = wp_get_ability( self::ABILITY );
		$this->assertFalse( $ability->check_permissions( array() ) );

		$result = $ability->execute( array() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_logged_out_user_is_denied(): void {
		wp_set_current_user( 0 );

		$result = wp_get_ability( self::ABILITY )->execute( array() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}
}
