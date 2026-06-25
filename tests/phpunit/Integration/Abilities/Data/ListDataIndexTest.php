<?php
/**
 * Integration tests for the `og-wc-data/list-data-index` ability.
 *
 * @package AbilitiesCatalogWoo\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogWoo\Tests\Integration\Abilities\Data;

use GalatanOvidiu\AbilitiesCatalogWoo\Tests\TestCase;
use WP_Error;

/**
 * @covers \GalatanOvidiu\AbilitiesCatalogWoo\Abilities\Woo\Data\ListDataIndex
 */
final class ListDataIndexTest extends TestCase {

	private const ABILITY = 'og-wc-data/list-data-index';

	/**
	 * The exact keys a shaped data-index row exposes.
	 *
	 * Asserting against this fixed set proves the raw REST row is never leaked:
	 * the HAL `_links` envelope is lifted into a flat `endpoint` and never appears
	 * as a row key.
	 *
	 * @var list<string>
	 */
	private const ROW_KEYS = array(
		'slug',
		'description',
		'endpoint',
	);

	public function test_registered(): void {
		$ability = wp_get_ability( self::ABILITY );

		$this->assertNotNull( $ability );
		$this->assertSame( self::ABILITY, $ability->get_name() );
	}

	public function test_happy_path_returns_three_shaped_rows(): void {
		$this->actingAs( 'administrator' );

		// The wc/v3/data index always returns the three reference resources that
		// ship with WooCommerce, so no seeding is needed.
		$result = wp_get_ability( self::ABILITY )->execute( array() );

		$this->assertIsArray( $result );
		$this->assertSame( array( 'items', 'total' ), array_keys( $result ) );
		$this->assertIsArray( $result['items'] );
		$this->assertIsInt( $result['total'] );
		$this->assertSame( 3, $result['total'] );
		$this->assertCount( $result['total'], $result['items'] );

		$row = $result['items'][0];
		$this->assertSame( self::ROW_KEYS, array_keys( $row ) );
		$this->assertIsString( $row['slug'] );
		$this->assertIsString( $row['description'] );
		$this->assertIsString( $row['endpoint'] );

		$slugs = wp_list_pluck( $result['items'], 'slug' );
		$this->assertContains( 'continents', $slugs );
		$this->assertContains( 'countries', $slugs );
		$this->assertContains( 'currencies', $slugs );
	}

	public function test_endpoint_is_lifted_from_links(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( self::ABILITY )->execute( array() );

		$rows = array();
		foreach ( $result['items'] as $item ) {
			$rows[ $item['slug'] ] = $item;
		}

		// The endpoint is the resource's REST URL, ending in the resource slug —
		// proving the HAL self-link href was lifted into a flat string.
		$this->assertArrayHasKey( 'currencies', $rows );
		$this->assertNotEmpty( $rows['currencies']['endpoint'] );
		$this->assertStringContainsString( 'wc/v3/data/currencies', $rows['currencies']['endpoint'] );
	}

	public function test_output_shape_has_no_links_leak(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( self::ABILITY )->execute( array() );

		$this->assertSame( array( 'items', 'total' ), array_keys( $result ) );

		foreach ( $result['items'] as $row ) {
			$this->assertSame( self::ROW_KEYS, array_keys( $row ) );
			$this->assertArrayNotHasKey( '_links', $row );
		}

		// The raw HAL envelope must not leak anywhere in the projected result.
		$this->assertStringNotContainsString( '_links', (string) wp_json_encode( $result ) );
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
