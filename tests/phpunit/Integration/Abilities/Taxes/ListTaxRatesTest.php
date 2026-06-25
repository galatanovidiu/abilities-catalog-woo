<?php
/**
 * Integration tests for the `og-wc-taxes/list-tax-rates` ability.
 *
 * @package AbilitiesCatalogWoo\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogWoo\Tests\Integration\Abilities\Taxes;

use GalatanOvidiu\AbilitiesCatalogWoo\Tests\TestCase;
use WC_Tax;
use WP_Error;

/**
 * @covers \GalatanOvidiu\AbilitiesCatalogWoo\Abilities\Woo\Taxes\ListTaxRates
 */
final class ListTaxRatesTest extends TestCase {

	private const ABILITY = 'og-wc-taxes/list-tax-rates';

	/**
	 * The exact keys a shaped tax-rate summary row exposes.
	 *
	 * Asserting against this fixed set proves the raw tax body is never leaked:
	 * only these projected fields reach the consumer (no `postcodes`, `cities`, or
	 * `order`).
	 *
	 * @var list<string>
	 */
	private const ROW_KEYS = array(
		'id',
		'country',
		'state',
		'rate',
		'name',
		'priority',
		'compound',
		'shipping',
		'class',
	);

	public function test_registered(): void {
		$ability = wp_get_ability( self::ABILITY );

		$this->assertNotNull( $ability );
		$this->assertSame( self::ABILITY, $ability->get_name() );
	}

	public function test_happy_path_returns_shaped_rows(): void {
		$this->seedRate( 'US', '7.0000', 'Sales Tax', '' );
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( self::ABILITY )->execute( array() );

		$this->assertIsArray( $result );
		$this->assertSame( array( 'items', 'total' ), array_keys( $result ) );
		$this->assertIsArray( $result['items'] );
		$this->assertIsInt( $result['total'] );
		$this->assertGreaterThanOrEqual( 1, $result['total'] );
		$this->assertNotEmpty( $result['items'] );

		$row = $result['items'][0];
		$this->assertSame( self::ROW_KEYS, array_keys( $row ) );
		$this->assertIsInt( $row['id'] );
		$this->assertIsString( $row['country'] );
		$this->assertIsString( $row['rate'] );
		$this->assertIsString( $row['name'] );
		$this->assertIsInt( $row['priority'] );
		$this->assertIsBool( $row['compound'] );
		$this->assertIsBool( $row['shipping'] );
		$this->assertIsString( $row['class'] );
	}

	public function test_class_filter_narrows_results(): void {
		WC_Tax::create_tax_class( 'Reduced' );
		$standard = $this->seedRate( 'US', '7.0000', 'Standard Tax', '' );
		$reduced  = $this->seedRate( 'US', '3.0000', 'Reduced Tax', 'reduced-rate' );
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( self::ABILITY )->execute( array( 'class' => 'reduced-rate' ) );

		$ids = wp_list_pluck( $result['items'], 'id' );
		$this->assertContains( $reduced, $ids );
		$this->assertNotContains( $standard, $ids );

		foreach ( $result['items'] as $row ) {
			$this->assertSame( 'reduced-rate', $row['class'] );
		}
	}

	public function test_output_does_not_leak_raw_tax_fields(): void {
		$this->seedRate( 'US', '7.0000', 'Sales Tax', '' );
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( self::ABILITY )->execute( array() );

		$row = $result['items'][0];
		$this->assertSame( self::ROW_KEYS, array_keys( $row ) );
		$this->assertArrayNotHasKey( 'postcodes', $row );
		$this->assertArrayNotHasKey( 'cities', $row );
		$this->assertArrayNotHasKey( 'postcode', $row );
		$this->assertArrayNotHasKey( 'city', $row );
		$this->assertArrayNotHasKey( 'order', $row );
		$this->assertArrayNotHasKey( '_links', $row );
	}

	public function test_wrong_capability_is_denied(): void {
		$this->seedRate( 'US', '7.0000', 'Sales Tax', '' );
		$this->actingAs( 'subscriber' );

		$ability = wp_get_ability( self::ABILITY );

		$this->assertFalse( $ability->check_permissions( array() ) );

		$result = $ability->execute( array() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_logged_out_user_is_denied(): void {
		$this->seedRate( 'US', '7.0000', 'Sales Tax', '' );
		wp_set_current_user( 0 );

		$result = wp_get_ability( self::ABILITY )->execute( array() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	/**
	 * Seeds a tax rate via the WooCommerce runtime API and returns its ID.
	 *
	 * Uses `WC_Tax::_insert_tax_rate()` (loaded with the plugin) rather than a test
	 * factory, which the distributed WooCommerce build ships no framework for.
	 *
	 * @param string $country The two-letter country code.
	 * @param string $rate    The rate as a decimal string, e.g. 7.0000.
	 * @param string $name    The rate name shown to staff.
	 * @param string $class   The tax-class slug, or an empty string for standard.
	 * @return int The new tax-rate ID.
	 */
	private function seedRate( string $country, string $rate, string $name, string $class ): int {
		return (int) WC_Tax::_insert_tax_rate(
			array(
				'tax_rate_country' => $country,
				'tax_rate'         => $rate,
				'tax_rate_name'    => $name,
				'tax_rate_class'   => $class,
			)
		);
	}
}
