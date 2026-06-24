<?php
/**
 * Integration tests for the `wc-data/list-currencies` ability.
 *
 * @package AbilitiesCatalogWoo\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogWoo\Tests\Integration\Abilities\Data;

use GalatanOvidiu\AbilitiesCatalogWoo\Tests\TestCase;
use WP_Error;

/**
 * @covers \GalatanOvidiu\AbilitiesCatalogWoo\Abilities\Woo\Data\ListCurrencies
 */
final class ListCurrenciesTest extends TestCase {

	private const ABILITY = 'wc-data/list-currencies';

	/**
	 * The keys a shaped currency row exposes — and nothing more.
	 *
	 * @var list<string>
	 */
	private const ROW_KEYS = array( 'code', 'name', 'symbol' );

	public function test_registered(): void {
		$ability = wp_get_ability( self::ABILITY );

		$this->assertNotNull( $ability );
		$this->assertSame( self::ABILITY, $ability->get_name() );
	}

	public function test_happy_path_returns_known_currency(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( self::ABILITY )->execute( array() );

		$this->assertIsArray( $result );
		$this->assertIsArray( $result['items'] );
		$this->assertNotEmpty( $result['items'] );
		$this->assertIsInt( $result['total'] );
		$this->assertSame( count( $result['items'] ), $result['total'] );

		// WooCommerce ships this static currency table, so USD is always present.
		$by_code = array();
		foreach ( $result['items'] as $row ) {
			$by_code[ $row['code'] ] = $row;
		}

		$this->assertArrayHasKey( 'USD', $by_code );
		$this->assertSame( 'USD', $by_code['USD']['code'] );
		$this->assertNotEmpty( $by_code['USD']['name'] );
		$this->assertNotEmpty( $by_code['USD']['symbol'] );
	}

	public function test_each_row_is_exactly_the_closed_schema(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( self::ABILITY )->execute( array() );

		$this->assertNotEmpty( $result['items'] );
		foreach ( $result['items'] as $row ) {
			$this->assertSame( self::ROW_KEYS, array_keys( $row ) );
			$this->assertIsString( $row['code'] );
			$this->assertIsString( $row['name'] );
			$this->assertIsString( $row['symbol'] );

			// No raw REST fields leak (the controller emits _links per row).
			$this->assertArrayNotHasKey( '_links', $row );
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
