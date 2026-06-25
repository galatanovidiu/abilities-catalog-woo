<?php
/**
 * Integration tests for the `og-wc-system/list-system-tools` ability.
 *
 * @package AbilitiesCatalogWoo\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogWoo\Tests\Integration\Abilities\System;

use GalatanOvidiu\AbilitiesCatalogWoo\Tests\TestCase;
use WP_Error;

/**
 * @covers \GalatanOvidiu\AbilitiesCatalogWoo\Abilities\Woo\System\ListSystemTools
 */
final class ListSystemToolsTest extends TestCase {

	private const ABILITY = 'og-wc-system/list-system-tools';

	/**
	 * Tool ids WooCommerce always ships with.
	 */
	private const CORE_TOOL_IDS = array(
		'clear_transients',
		'clear_expired_transients',
		'regenerate_thumbnails',
	);

	public function test_registered(): void {
		$ability = wp_get_ability( self::ABILITY );

		$this->assertNotNull( $ability );
		$this->assertSame( self::ABILITY, $ability->get_name() );
	}

	public function test_happy_path_returns_core_tools(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( self::ABILITY )->execute( array() );

		$this->assertIsArray( $result );
		$this->assertIsArray( $result['items'] );
		$this->assertNotEmpty( $result['items'] );
		$this->assertIsInt( $result['total'] );
		$this->assertSame( count( $result['items'] ), $result['total'] );

		$ids = array_column( $result['items'], 'id' );
		foreach ( self::CORE_TOOL_IDS as $expected ) {
			$this->assertContains( $expected, $ids );
		}
	}

	public function test_each_row_is_the_closed_tool_shape(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( self::ABILITY )->execute( array() );

		$this->assertNotEmpty( $result['items'] );
		foreach ( $result['items'] as $row ) {
			$this->assertSame( array( 'id', 'name', 'action', 'description' ), array_keys( $row ) );
			$this->assertIsString( $row['id'] );
			$this->assertIsString( $row['name'] );
			$this->assertIsString( $row['action'] );
			$this->assertIsString( $row['description'] );
		}
	}

	public function test_output_shape_is_exactly_items_and_total(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( self::ABILITY )->execute( array() );

		$this->assertSame( array( 'items', 'total' ), array_keys( $result ) );
	}

	public function test_wrong_capability_is_denied(): void {
		$this->actingAs( 'subscriber' );

		$result = wp_get_ability( self::ABILITY )->execute( array() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}
}
