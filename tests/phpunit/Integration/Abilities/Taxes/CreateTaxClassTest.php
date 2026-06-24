<?php
/**
 * Integration tests for the `wc-taxes/create-tax-class` ability.
 *
 * @package AbilitiesCatalogWoo\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogWoo\Tests\Integration\Abilities\Taxes;

use GalatanOvidiu\AbilitiesCatalogWoo\Tests\TestCase;
use WC_Tax;
use WP_Error;

/**
 * @covers \GalatanOvidiu\AbilitiesCatalogWoo\Abilities\Woo\Taxes\CreateTaxClass
 */
final class CreateTaxClassTest extends TestCase {

	private const ABILITY = 'wc-taxes/create-tax-class';

	/**
	 * The exact keys a shaped tax-class row exposes.
	 *
	 * Asserting against this fixed set proves the raw tax-class body is never
	 * leaked: only these projected fields reach the consumer.
	 *
	 * @var list<string>
	 */
	private const ROW_KEYS = array(
		'slug',
		'name',
	);

	public function test_registered(): void {
		$ability = wp_get_ability( self::ABILITY );

		$this->assertNotNull( $ability );
		$this->assertSame( self::ABILITY, $ability->get_name() );
	}

	public function test_happy_path_creates_class_with_derived_slug(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( self::ABILITY )->execute( array( 'name' => 'Wholesale' ) );

		$this->assertIsArray( $result );
		$this->assertSame( self::ROW_KEYS, array_keys( $result ) );
		$this->assertSame( 'Wholesale', $result['name'] );
		$this->assertSame( 'wholesale', $result['slug'], 'WooCommerce derives the slug from the name.' );

		// Side-effect read-back: the class is now in WooCommerce's class list.
		$this->assertContains( 'Wholesale', WC_Tax::get_tax_classes() );
	}

	public function test_output_shape_does_not_leak_raw_fields(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( self::ABILITY )->execute( array( 'name' => 'Luxury' ) );

		$this->assertSame( self::ROW_KEYS, array_keys( $result ) );
		$this->assertIsString( $result['slug'] );
		$this->assertIsString( $result['name'] );
		$this->assertArrayNotHasKey( '_links', $result );
	}

	public function test_duplicate_name_surfaces_route_error(): void {
		WC_Tax::create_tax_class( 'Reduced rate' );
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( self::ABILITY )->execute( array( 'name' => 'Reduced rate' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'woocommerce_rest_tax_class_exists', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] );
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_missing_required_name_is_rejected(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( self::ABILITY )->execute( array() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_input', $result->get_error_code() );
	}

	public function test_wrong_capability_is_denied(): void {
		$this->actingAs( 'subscriber' );

		$ability = wp_get_ability( self::ABILITY );

		$this->assertFalse( $ability->check_permissions( array( 'name' => 'Forbidden Rate' ) ) );

		$result = $ability->execute( array( 'name' => 'Forbidden Rate' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );

		// The class was never created.
		$this->assertNotContains( 'Forbidden Rate', WC_Tax::get_tax_classes() );
	}

	public function test_logged_out_user_is_denied(): void {
		wp_set_current_user( 0 );

		$result = wp_get_ability( self::ABILITY )->execute( array( 'name' => 'Anon Rate' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
		$this->assertNotContains( 'Anon Rate', WC_Tax::get_tax_classes() );
	}
}
