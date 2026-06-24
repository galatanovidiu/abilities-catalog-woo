<?php
/**
 * Integration tests for the wc-system/get-system-tool ability.
 *
 * @package AbilitiesCatalogWoo\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogWoo\Tests\Integration\Abilities\System;

use GalatanOvidiu\AbilitiesCatalogWoo\Tests\TestCase;
use WP_Error;

/**
 * Exercises wc-system/get-system-tool: the shaped single-tool row for a known
 * tool id (e.g. clear_transients), the unknown-tool 404 that must not collapse to
 * a permission error, the wrong-capability denial, and the exact closed output
 * shape with no raw REST fields.
 */
final class GetSystemToolTest extends TestCase {

	/**
	 * The full closed key set the ability returns, in order.
	 *
	 * @var list<string>
	 */
	private const EXPECTED_KEYS = array(
		'id',
		'name',
		'action',
		'description',
	);

	public function test_ability_is_registered(): void {
		$ability = wp_get_ability( 'wc-system/get-system-tool' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'wc-system/get-system-tool', $ability->get_name() );
	}

	public function test_admin_reads_known_tool(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'wc-system/get-system-tool' )->execute( array( 'id' => 'clear_transients' ) );

		$this->assertIsArray( $result );
		$this->assertSame( 'clear_transients', $result['id'] );
		$this->assertIsString( $result['name'] );
		$this->assertNotSame( '', $result['name'] );
		$this->assertIsString( $result['action'] );
		$this->assertNotSame( '', $result['action'] );
		$this->assertIsString( $result['description'] );
		$this->assertNotSame( '', $result['description'] );
	}

	public function test_output_shape_is_exact_and_closed(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'wc-system/get-system-tool' )->execute( array( 'id' => 'clear_transients' ) );

		$this->assertIsArray( $result );
		$this->assertSame( self::EXPECTED_KEYS, array_keys( $result ) );

		// No raw REST fields leak through.
		$this->assertArrayNotHasKey( '_links', $result );
		$this->assertArrayNotHasKey( 'success', $result );
		$this->assertArrayNotHasKey( 'message', $result );
		$this->assertArrayNotHasKey( 'confirm', $result );
	}

	public function test_missing_tool_returns_404_not_permission_error(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'wc-system/get-system-tool' )->execute( array( 'id' => 'no_such_tool_xyz' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'woocommerce_rest_system_status_tool_invalid_id', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] );
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_subscriber_is_denied(): void {
		$this->actingAs( 'subscriber' );

		$ability = wp_get_ability( 'wc-system/get-system-tool' );

		$this->assertFalse( $ability->check_permissions( array( 'id' => 'clear_transients' ) ) );

		$result = $ability->execute( array( 'id' => 'clear_transients' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}
}
