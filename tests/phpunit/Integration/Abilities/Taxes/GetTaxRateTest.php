<?php
/**
 * Integration tests for the og-wc-taxes/get-tax-rate ability.
 *
 * @package AbilitiesCatalogWoo\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogWoo\Tests\Integration\Abilities\Taxes;

use GalatanOvidiu\AbilitiesCatalogWoo\Tests\TestCase;
use WC_Tax;
use WP_Error;

/**
 * Exercises og-wc-taxes/get-tax-rate: the shaped single tax-rate row, the missing-rate
 * 404 that must not collapse to a permission error, the wrong-capability denial, and
 * the exact closed output shape (flat, no raw postcodes/cities/order leak).
 */
final class GetTaxRateTest extends TestCase {

	/**
	 * The full closed key set the ability returns for one tax rate.
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

	/**
	 * Seeds a single tax rate and returns its new ID.
	 *
	 * @param array<string,mixed> $overrides Fields to override on the default rate.
	 * @return int The new tax rate ID.
	 */
	private function seedRate( array $overrides = array() ): int {
		$rate = array_merge(
			array(
				'tax_rate_country'  => 'US',
				'tax_rate_state'    => 'CA',
				'tax_rate'          => '7.0000',
				'tax_rate_name'     => 'Tax',
				'tax_rate_priority' => 1,
				'tax_rate_compound' => 0,
				'tax_rate_shipping' => 1,
				'tax_rate_class'    => '',
			),
			$overrides
		);

		return (int) WC_Tax::_insert_tax_rate( $rate );
	}

	public function test_ability_is_registered(): void {
		$ability = wp_get_ability( 'og-wc-taxes/get-tax-rate' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'og-wc-taxes/get-tax-rate', $ability->get_name() );
	}

	public function test_admin_reads_tax_rate_detail(): void {
		$this->actingAs( 'administrator' );

		$id = $this->seedRate();

		$result = wp_get_ability( 'og-wc-taxes/get-tax-rate' )->execute( array( 'id' => $id ) );

		$this->assertIsArray( $result );
		$this->assertSame( $id, $result['id'] );
		$this->assertSame( 'US', $result['country'] );
		$this->assertSame( 'CA', $result['state'] );
		$this->assertSame( '7.0000', $result['rate'] );
		$this->assertSame( 'Tax', $result['name'] );
		$this->assertSame( 1, $result['priority'] );
		$this->assertFalse( $result['compound'] );
		$this->assertTrue( $result['shipping'] );
		// The wc/v3 taxes controller surfaces the empty (default) stored class as
		// the slug 'standard' (taxes-v1-controller.php:564), not an empty string.
		$this->assertSame( 'standard', $result['class'] );
	}

	public function test_output_shape_is_exact_and_closed(): void {
		$this->actingAs( 'administrator' );

		$id = $this->seedRate(
			array(
				'tax_rate_class' => 'reduced-rate',
			)
		);

		$result = wp_get_ability( 'og-wc-taxes/get-tax-rate' )->execute( array( 'id' => $id ) );

		$this->assertIsArray( $result );
		$this->assertSame( self::EXPECTED_KEYS, array_keys( $result ) );

		// No raw tax-rate fields leak through.
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
		$this->assertSame( 'reduced-rate', $result['class'] );
	}

	public function test_missing_tax_rate_returns_404_not_permission_error(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'og-wc-taxes/get-tax-rate' )->execute( array( 'id' => 99999999 ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'woocommerce_rest_invalid_id', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] );
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_subscriber_is_denied(): void {
		$id = $this->seedRate();

		$this->actingAs( 'subscriber' );

		$ability = wp_get_ability( 'og-wc-taxes/get-tax-rate' );

		$this->assertFalse( $ability->check_permissions( array( 'id' => $id ) ) );

		$result = $ability->execute( array( 'id' => $id ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}
}
