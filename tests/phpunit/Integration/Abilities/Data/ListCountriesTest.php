<?php
/**
 * Integration tests for the `og-wc-data/list-countries` ability.
 *
 * @package AbilitiesCatalogWoo\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogWoo\Tests\Integration\Abilities\Data;

use GalatanOvidiu\AbilitiesCatalogWoo\Tests\TestCase;
use WP_Error;

/**
 * @covers \GalatanOvidiu\AbilitiesCatalogWoo\Abilities\Woo\Data\ListCountries
 */
final class ListCountriesTest extends TestCase {

	private const ABILITY = 'og-wc-data/list-countries';

	/**
	 * The keys a shaped country row exposes — and nothing more.
	 *
	 * @var list<string>
	 */
	private const ROW_KEYS = array( 'code', 'name', 'states' );

	public function test_registered(): void {
		$ability = wp_get_ability( self::ABILITY );

		$this->assertNotNull( $ability );
		$this->assertSame( self::ABILITY, $ability->get_name() );
	}

	public function test_happy_path_returns_shaped_rows_including_united_states(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( self::ABILITY )->execute( array() );

		$this->assertIsArray( $result );
		$this->assertIsArray( $result['items'] );
		$this->assertNotEmpty( $result['items'] );
		$this->assertIsInt( $result['total'] );
		$this->assertSame( count( $result['items'] ), $result['total'] );

		// The fixed WooCommerce country table always carries the United States.
		$by_code = array_column( $result['items'], null, 'code' );
		$this->assertArrayHasKey( 'US', $by_code );
		$this->assertNotEmpty( $by_code['US']['name'] );
		$this->assertIsArray( $by_code['US']['states'] );
		// The US has states, each shaped to a {code,name} pair.
		$this->assertNotEmpty( $by_code['US']['states'] );
		$this->assertSame( array( 'code', 'name' ), array_keys( $by_code['US']['states'][0] ) );
	}

	public function test_each_row_is_exactly_the_closed_schema(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( self::ABILITY )->execute( array() );

		$this->assertNotEmpty( $result['items'] );
		foreach ( $result['items'] as $row ) {
			$this->assertSame( self::ROW_KEYS, array_keys( $row ) );
			$this->assertIsString( $row['code'] );
			$this->assertIsString( $row['name'] );
			$this->assertIsArray( $row['states'] );

			// No raw REST fields leak (the controller emits _links per row).
			$this->assertArrayNotHasKey( '_links', $row );

			foreach ( $row['states'] as $state ) {
				$this->assertSame( array( 'code', 'name' ), array_keys( $state ) );
				$this->assertIsString( $state['code'] );
				$this->assertIsString( $state['name'] );
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

		$result = wp_get_ability( self::ABILITY )->execute( array() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}
}
