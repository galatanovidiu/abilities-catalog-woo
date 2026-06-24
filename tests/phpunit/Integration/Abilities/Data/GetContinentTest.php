<?php
/**
 * Integration tests for the wc-data/get-continent ability.
 *
 * @package AbilitiesCatalogWoo\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogWoo\Tests\Integration\Abilities\Data;

use GalatanOvidiu\AbilitiesCatalogWoo\Tests\TestCase;
use WP_Error;

/**
 * Exercises wc-data/get-continent: the shaped single continent (a known code
 * resolves to its name and countries), the unknown-code 404 that must not collapse
 * to a permission error, the wrong-capability denial, and the exact closed output
 * shape (flat, no locale-detail country fields or _links leak).
 *
 * This reference data ships with WooCommerce and is always present, so no seeding
 * is required; the tests assert known fixed values (continent NA exists).
 */
final class GetContinentTest extends TestCase {

	/**
	 * The full closed key set the ability returns for one continent.
	 *
	 * @var list<string>
	 */
	private const EXPECTED_KEYS = array(
		'code',
		'name',
		'countries',
	);

	public function test_ability_is_registered(): void {
		$ability = wp_get_ability( 'wc-data/get-continent' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'wc-data/get-continent', $ability->get_name() );
	}

	public function test_admin_reads_known_continent(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'wc-data/get-continent' )->execute( array( 'code' => 'NA' ) );

		$this->assertIsArray( $result );
		$this->assertSame( 'NA', $result['code'] );
		$this->assertNotEmpty( $result['name'] );
		$this->assertNotEmpty( $result['countries'] );

		// Each country is summarized to a closed {code,name} pair only.
		$first = $result['countries'][0];
		$this->assertSame( array( 'code', 'name' ), array_keys( $first ) );
		$this->assertIsString( $first['code'] );
		$this->assertIsString( $first['name'] );
	}

	public function test_lowercase_code_resolves(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'wc-data/get-continent' )->execute( array( 'code' => 'na' ) );

		$this->assertIsArray( $result );
		$this->assertSame( 'NA', $result['code'] );
	}

	public function test_output_shape_is_exact_and_closed(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'wc-data/get-continent' )->execute( array( 'code' => 'NA' ) );

		$this->assertIsArray( $result );
		$this->assertSame( self::EXPECTED_KEYS, array_keys( $result ) );

		$this->assertIsString( $result['code'] );
		$this->assertIsString( $result['name'] );
		$this->assertIsArray( $result['countries'] );

		// No raw continent-row or HAL fields leak through.
		$this->assertArrayNotHasKey( '_links', $result );

		// No per-country locale-detail fields leak into the summarized countries.
		foreach ( $result['countries'] as $country ) {
			$this->assertSame( array( 'code', 'name' ), array_keys( $country ) );
			$this->assertArrayNotHasKey( 'currency_code', $country );
			$this->assertArrayNotHasKey( 'states', $country );
			$this->assertArrayNotHasKey( 'decimal_sep', $country );
		}
	}

	public function test_unknown_code_returns_404_not_permission_error(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'wc-data/get-continent' )->execute( array( 'code' => 'ZZ' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'woocommerce_rest_data_invalid_location', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] );
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_subscriber_is_denied(): void {
		$this->actingAs( 'subscriber' );

		$ability = wp_get_ability( 'wc-data/get-continent' );

		$this->assertFalse( $ability->check_permissions( array( 'code' => 'NA' ) ) );

		$result = $ability->execute( array( 'code' => 'NA' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}
}
