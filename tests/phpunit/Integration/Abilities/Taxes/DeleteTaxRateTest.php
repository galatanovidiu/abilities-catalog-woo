<?php
/**
 * Integration tests for the og-wc-taxes/delete-tax-rate ability.
 *
 * @package AbilitiesCatalogWoo\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogWoo\Tests\Integration\Abilities\Taxes;

use GalatanOvidiu\AbilitiesCatalogWoo\Tests\TestCase;
use WC_Tax;
use WP_Error;

/**
 * Exercises og-wc-taxes/delete-tax-rate: the permanent (force) delete returning the
 * pre-read name, the proof the rate is actually GONE afterward, the missing-rate
 * 404 that must not collapse to a permission error, the schema-rejected missing id,
 * the wrong-capability denial, and the exact closed output shape (no edit_link).
 */
final class DeleteTaxRateTest extends TestCase {

	/**
	 * The full closed key set the ability returns.
	 *
	 * @var list<string>
	 */
	private const EXPECTED_KEYS = array(
		'deleted',
		'id',
		'name',
		'permanent',
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
				'tax_rate_country' => 'US',
				'tax_rate'         => '8.25',
				'tax_rate_name'    => 'CA Tax',
			),
			$overrides
		);

		return (int) WC_Tax::_insert_tax_rate( $rate );
	}

	public function test_ability_is_registered(): void {
		$ability = wp_get_ability( 'og-wc-taxes/delete-tax-rate' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'og-wc-taxes/delete-tax-rate', $ability->get_name() );
	}

	public function test_admin_deletes_tax_rate_permanently(): void {
		$this->actingAs( 'administrator' );

		$id = $this->seedRate();

		$result = wp_get_ability( 'og-wc-taxes/delete-tax-rate' )->execute( array( 'id' => $id ) );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['deleted'] );
		$this->assertSame( $id, $result['id'] );
		$this->assertSame( 'CA Tax', $result['name'] );
		$this->assertTrue( $result['permanent'] );

		// The rate must be ACTUALLY gone, not merely reported deleted.
		$this->assertEmpty( WC_Tax::_get_tax_rate( $id ) );
	}

	public function test_output_shape_is_exact_and_closed(): void {
		$this->actingAs( 'administrator' );

		$id = $this->seedRate();

		$result = wp_get_ability( 'og-wc-taxes/delete-tax-rate' )->execute( array( 'id' => $id ) );

		$this->assertIsArray( $result );
		$this->assertSame( self::EXPECTED_KEYS, array_keys( $result ) );

		// No dead-end edit link and no raw tax-rate fields leak through.
		$this->assertArrayNotHasKey( 'edit_link', $result );
		$this->assertArrayNotHasKey( 'country', $result );
		$this->assertArrayNotHasKey( 'rate', $result );
		$this->assertArrayNotHasKey( 'class', $result );
		$this->assertArrayNotHasKey( '_links', $result );

		$this->assertIsBool( $result['deleted'] );
		$this->assertIsInt( $result['id'] );
		$this->assertIsString( $result['name'] );
		$this->assertIsBool( $result['permanent'] );
	}

	public function test_missing_tax_rate_returns_404_not_permission_error(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'og-wc-taxes/delete-tax-rate' )->execute( array( 'id' => 99999999 ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'woocommerce_rest_invalid_id', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] );
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_missing_required_id_is_rejected(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'og-wc-taxes/delete-tax-rate' )->execute( array() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_input', $result->get_error_code() );
	}

	public function test_subscriber_is_denied(): void {
		$id = $this->seedRate();

		$this->actingAs( 'subscriber' );

		$ability = wp_get_ability( 'og-wc-taxes/delete-tax-rate' );

		$this->assertFalse( $ability->check_permissions( array( 'id' => $id ) ) );

		$result = $ability->execute( array( 'id' => $id ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );

		// The denied caller did not delete the rate.
		$this->assertNotEmpty( WC_Tax::_get_tax_rate( $id ) );
	}
}
