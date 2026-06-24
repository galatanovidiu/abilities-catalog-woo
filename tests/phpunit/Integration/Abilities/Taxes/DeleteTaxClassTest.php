<?php
/**
 * Integration tests for the `wc-taxes/delete-tax-class` ability.
 *
 * @package AbilitiesCatalogWoo\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogWoo\Tests\Integration\Abilities\Taxes;

use GalatanOvidiu\AbilitiesCatalogWoo\Tests\TestCase;
use WC_Tax;
use WP_Error;

/**
 * @covers \GalatanOvidiu\AbilitiesCatalogWoo\Abilities\Woo\Taxes\DeleteTaxClass
 */
final class DeleteTaxClassTest extends TestCase {

	private const ABILITY = 'wc-taxes/delete-tax-class';

	/**
	 * The exact keys the delete confirmation envelope exposes.
	 *
	 * Asserting against this fixed set proves the raw tax-class body is never
	 * leaked and that no dead-end edit_link is returned.
	 *
	 * @var list<string>
	 */
	private const RESULT_KEYS = array(
		'deleted',
		'slug',
		'name',
		'permanent',
	);

	public function test_registered(): void {
		$ability = wp_get_ability( self::ABILITY );

		$this->assertNotNull( $ability );
		$this->assertSame( self::ABILITY, $ability->get_name() );
	}

	public function test_happy_path_deletes_class_and_it_is_gone(): void {
		WC_Tax::create_tax_class( 'Reduced rate' );
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( self::ABILITY )->execute( array( 'slug' => 'reduced-rate' ) );

		$this->assertIsArray( $result );
		$this->assertSame( self::RESULT_KEYS, array_keys( $result ) );
		$this->assertTrue( $result['deleted'] );
		$this->assertSame( 'reduced-rate', $result['slug'] );
		$this->assertSame( 'Reduced rate', $result['name'] );
		$this->assertTrue( $result['permanent'] );

		// Side-effect read-back: the class is actually gone, not just reported deleted.
		$this->assertNotContains( 'Reduced rate', WC_Tax::get_tax_classes() );
	}

	public function test_output_shape_has_no_edit_link_or_raw_fields(): void {
		WC_Tax::create_tax_class( 'Wholesale' );
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( self::ABILITY )->execute( array( 'slug' => 'wholesale' ) );

		$this->assertSame( self::RESULT_KEYS, array_keys( $result ) );
		$this->assertIsBool( $result['deleted'] );
		$this->assertIsString( $result['slug'] );
		$this->assertIsString( $result['name'] );
		$this->assertIsBool( $result['permanent'] );
		$this->assertArrayNotHasKey( 'edit_link', $result );
		$this->assertArrayNotHasKey( '_links', $result );
	}

	public function test_builtin_standard_class_cannot_be_deleted(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( self::ABILITY )->execute( array( 'slug' => 'standard' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'woocommerce_rest_tax_class_invalid_slug', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] );
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_unknown_slug_surfaces_route_404(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( self::ABILITY )->execute( array( 'slug' => 'no-such-class' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'woocommerce_rest_tax_class_invalid_slug', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] );
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_missing_required_slug_is_rejected(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( self::ABILITY )->execute( array() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_input', $result->get_error_code() );
	}

	public function test_wrong_capability_is_denied(): void {
		WC_Tax::create_tax_class( 'Reduced rate' );
		$this->actingAs( 'subscriber' );

		$ability = wp_get_ability( self::ABILITY );

		$this->assertFalse( $ability->check_permissions( array( 'slug' => 'reduced-rate' ) ) );

		$result = $ability->execute( array( 'slug' => 'reduced-rate' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );

		// The class survived the denied call unchanged.
		$this->assertContains( 'Reduced rate', WC_Tax::get_tax_classes() );
	}

	public function test_logged_out_user_is_denied(): void {
		WC_Tax::create_tax_class( 'Reduced rate' );
		wp_set_current_user( 0 );

		$result = wp_get_ability( self::ABILITY )->execute( array( 'slug' => 'reduced-rate' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
		$this->assertContains( 'Reduced rate', WC_Tax::get_tax_classes() );
	}
}
