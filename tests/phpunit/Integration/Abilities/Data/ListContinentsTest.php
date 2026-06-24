<?php
/**
 * Integration tests for the `wc-data/list-continents` ability.
 *
 * @package AbilitiesCatalogWoo\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogWoo\Tests\Integration\Abilities\Data;

use GalatanOvidiu\AbilitiesCatalogWoo\Tests\TestCase;
use WP_Error;

/**
 * @covers \GalatanOvidiu\AbilitiesCatalogWoo\Abilities\Woo\Data\ListContinents
 */
final class ListContinentsTest extends TestCase {

	private const ABILITY = 'wc-data/list-continents';

	/**
	 * The keys a shaped continent row exposes — and nothing more.
	 *
	 * @var list<string>
	 */
	private const ROW_KEYS = array( 'code', 'name', 'countries' );

	public function test_registered(): void {
		$ability = wp_get_ability( self::ABILITY );

		$this->assertNotNull( $ability );
		$this->assertSame( self::ABILITY, $ability->get_name() );
	}

	public function test_happy_path_returns_shaped_continents(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( self::ABILITY )->execute( array() );

		$this->assertIsArray( $result );
		$this->assertIsArray( $result['items'] );
		$this->assertNotEmpty( $result['items'] );
		$this->assertIsInt( $result['total'] );
		$this->assertSame( count( $result['items'] ), $result['total'] );
	}

	public function test_north_america_row_exists_with_countries(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( self::ABILITY )->execute( array() );

		$row = null;
		foreach ( $result['items'] as $item ) {
			if ( 'NA' === $item['code'] ) {
				$row = $item;
			}
		}

		$this->assertNotNull( $row, 'The North America (NA) continent should be present.' );
		$this->assertSame( 'NA', $row['code'] );
		$this->assertNotEmpty( $row['name'] );
		$this->assertNotEmpty( $row['countries'] );
	}

	public function test_each_row_is_exactly_the_closed_schema(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( self::ABILITY )->execute( array() );

		$this->assertNotEmpty( $result['items'] );
		foreach ( $result['items'] as $row ) {
			$this->assertSame( self::ROW_KEYS, array_keys( $row ) );
			$this->assertIsString( $row['code'] );
			$this->assertIsString( $row['name'] );
			$this->assertIsArray( $row['countries'] );

			// No raw REST fields leak (the route nests _links and per-country locale detail).
			$this->assertArrayNotHasKey( '_links', $row );

			foreach ( $row['countries'] as $country ) {
				$this->assertSame( array( 'code', 'name' ), array_keys( $country ) );
				$this->assertIsString( $country['code'] );
				$this->assertIsString( $country['name'] );

				// The fat locale-detail fields the raw route nests are dropped.
				$this->assertArrayNotHasKey( 'currency_code', $country );
				$this->assertArrayNotHasKey( 'states', $country );
			}
		}
	}

	public function test_output_shape_is_exactly_items_and_total(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( self::ABILITY )->execute( array() );

		$this->assertSame( array( 'items', 'total' ), array_keys( $result ) );
	}

	public function test_wrong_capability_is_denied(): void {
		$this->actingAs( 'subscriber' );

		$ability = wp_get_ability( self::ABILITY );

		$this->assertFalse( $ability->check_permissions( array() ) );

		$result = $ability->execute( array() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}
}
