<?php
/**
 * Integration tests for the wc-data/get-country ability.
 *
 * @package AbilitiesCatalogWoo\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogWoo\Tests\Integration\Abilities\Data;

use GalatanOvidiu\AbilitiesCatalogWoo\Tests\TestCase;
use WP_Error;

/**
 * Exercises wc-data/get-country: the shaped single-country row for a known code,
 * the unknown-code 404 that must not collapse to a permission error, the
 * wrong-capability denial, and the exact closed output shape with no locale-detail
 * or HAL link leak. WooCommerce ships the country/state reference tables, so the
 * data is always present; no seeding is required and the test asserts known fixed
 * values (US and its states).
 */
final class GetCountryTest extends TestCase {

	/**
	 * The closed key set the ability returns, in order.
	 *
	 * @var list<string>
	 */
	private const EXPECTED_KEYS = array(
		'code',
		'name',
		'states',
	);

	public function test_ability_is_registered(): void {
		$ability = wp_get_ability( 'wc-data/get-country' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'wc-data/get-country', $ability->get_name() );
	}

	public function test_admin_reads_known_country(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'wc-data/get-country' )->execute( array( 'code' => 'US' ) );

		$this->assertIsArray( $result );
		$this->assertSame( 'US', $result['code'] );
		$this->assertIsString( $result['name'] );
		$this->assertNotSame( '', $result['name'] );

		// The US has states; each is a closed {code,name} string pair.
		$this->assertIsArray( $result['states'] );
		$this->assertNotEmpty( $result['states'] );
		$first = $result['states'][0];
		$this->assertSame( array( 'code', 'name' ), array_keys( $first ) );
		$this->assertIsString( $first['code'] );
		$this->assertIsString( $first['name'] );
	}

	public function test_lowercase_code_is_accepted(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'wc-data/get-country' )->execute( array( 'code' => 'us' ) );

		$this->assertIsArray( $result );
		$this->assertSame( 'US', $result['code'] );
	}

	public function test_output_shape_is_exact_and_closed(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'wc-data/get-country' )->execute( array( 'code' => 'US' ) );

		$this->assertIsArray( $result );
		$this->assertSame( self::EXPECTED_KEYS, array_keys( $result ) );

		// No HAL links or locale-detail fields leak through.
		$this->assertArrayNotHasKey( '_links', $result );
		$this->assertArrayNotHasKey( 'currency_code', $result );
	}

	public function test_unknown_code_returns_404_not_permission_error(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'wc-data/get-country' )->execute( array( 'code' => 'ZZ' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'woocommerce_rest_data_invalid_location', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] );
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_subscriber_is_denied(): void {
		$this->actingAs( 'subscriber' );

		$ability = wp_get_ability( 'wc-data/get-country' );

		$this->assertFalse( $ability->check_permissions( array( 'code' => 'US' ) ) );

		$result = $ability->execute( array( 'code' => 'US' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}
}
