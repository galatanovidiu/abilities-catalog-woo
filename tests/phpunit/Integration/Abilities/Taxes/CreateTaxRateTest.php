<?php
/**
 * Integration tests for the og-wc-taxes/create-tax-rate ability.
 *
 * @package AbilitiesCatalogWoo\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogWoo\Tests\Integration\Abilities\Taxes;

use GalatanOvidiu\AbilitiesCatalogWoo\Tests\TestCase;
use WC_Tax;
use WP_Error;

/**
 * Exercises og-wc-taxes/create-tax-rate: the happy-path create returning a shaped rate
 * with id > 0, forwarded fields honored on the created rate, the wrong-capability
 * denial, and the exact closed output shape (flat, no raw postcodes/cities/order
 * leak).
 */
final class CreateTaxRateTest extends TestCase {

	/**
	 * The closed key set the ability returns: the TaxRateListShaper summary fields.
	 *
	 * @var list<string>
	 */
	private const EXPECTED_KEYS = array(
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

	public function test_ability_is_registered(): void {
		$ability = wp_get_ability( 'og-wc-taxes/create-tax-rate' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'og-wc-taxes/create-tax-rate', $ability->get_name() );
	}

	public function test_admin_creates_tax_rate(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'og-wc-taxes/create-tax-rate' )->execute(
			array(
				'country' => 'US',
				'state'   => 'CA',
				'rate'    => '8.25',
				'name'    => 'CA Tax',
			)
		);

		$this->assertIsArray( $result );
		$this->assertGreaterThan( 0, $result['id'] );
		$this->assertSame( 'US', $result['country'] );
		$this->assertSame( 'CA', $result['state'] );
		$this->assertSame( 'CA Tax', $result['name'] );
		// WooCommerce stores the rate as a decimal string and normalizes it to four
		// decimal places.
		$this->assertSame( '8.2500', $result['rate'] );

		// The rate was actually persisted: re-read it from the store.
		$stored = WC_Tax::_get_tax_rate( $result['id'], OBJECT );
		$this->assertNotEmpty( $stored );
		$this->assertSame( 'US', $stored->tax_rate_country );
	}

	public function test_forwarded_fields_are_honored(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'og-wc-taxes/create-tax-rate' )->execute(
			array(
				'country'  => 'GB',
				'rate'     => '20.0',
				'name'     => 'VAT',
				'priority' => 2,
				'compound' => true,
				'shipping' => true,
				'class'    => 'reduced-rate',
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'GB', $result['country'] );
		$this->assertSame( 2, $result['priority'] );
		$this->assertTrue( $result['compound'] );
		$this->assertTrue( $result['shipping'] );
		$this->assertSame( 'reduced-rate', $result['class'] );
	}

	public function test_output_shape_is_exact_and_closed(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'og-wc-taxes/create-tax-rate' )->execute(
			array(
				'country'  => 'US',
				'postcode' => '90210',
				'city'     => 'Beverly Hills',
				'rate'     => '5.0',
				'name'     => 'Shape Tax',
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( self::EXPECTED_KEYS, array_keys( $result ) );

		// No raw tax-rate fields leak through, including the singular postcode/city
		// inputs and the V3 postcodes/cities arrays.
		$this->assertArrayNotHasKey( 'postcodes', $result );
		$this->assertArrayNotHasKey( 'cities', $result );
		$this->assertArrayNotHasKey( 'postcode', $result );
		$this->assertArrayNotHasKey( 'city', $result );
		$this->assertArrayNotHasKey( 'order', $result );
		$this->assertArrayNotHasKey( '_links', $result );

		$this->assertIsInt( $result['id'] );
		$this->assertIsString( $result['country'] );
		$this->assertIsString( $result['state'] );
		$this->assertIsString( $result['rate'] );
		$this->assertIsString( $result['name'] );
		$this->assertIsInt( $result['priority'] );
		$this->assertIsBool( $result['compound'] );
		$this->assertIsBool( $result['shipping'] );
		$this->assertIsString( $result['class'] );
	}

	public function test_subscriber_is_denied(): void {
		$this->actingAs( 'subscriber' );

		$ability = wp_get_ability( 'og-wc-taxes/create-tax-rate' );

		$this->assertFalse( $ability->check_permissions( array( 'country' => 'US' ) ) );

		$result = $ability->execute( array( 'country' => 'US' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}
}
