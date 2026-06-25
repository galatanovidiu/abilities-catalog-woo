<?php
/**
 * Integration tests for the `og-wc-taxes/list-tax-classes` ability.
 *
 * @package AbilitiesCatalogWoo\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogWoo\Tests\Integration\Abilities\Taxes;

use GalatanOvidiu\AbilitiesCatalogWoo\Tests\TestCase;
use WC_Tax;
use WP_Error;

/**
 * @covers \GalatanOvidiu\AbilitiesCatalogWoo\Abilities\Woo\Taxes\ListTaxClasses
 */
final class ListTaxClassesTest extends TestCase {

	private const ABILITY = 'og-wc-taxes/list-tax-classes';

	/**
	 * The exact keys a shaped tax-class summary row exposes.
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

	public function test_happy_path_returns_shaped_rows(): void {
		WC_Tax::create_tax_class( 'Reduced' );
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( self::ABILITY )->execute( array() );

		$this->assertIsArray( $result );
		$this->assertSame( array( 'items', 'total' ), array_keys( $result ) );
		$this->assertIsArray( $result['items'] );
		$this->assertIsInt( $result['total'] );
		$this->assertNotEmpty( $result['items'] );
		$this->assertSame( $result['total'], count( $result['items'] ) );

		$row = $result['items'][0];
		$this->assertSame( self::ROW_KEYS, array_keys( $row ) );
		$this->assertIsString( $row['slug'] );
		$this->assertIsString( $row['name'] );

		$slugs = wp_list_pluck( $result['items'], 'slug' );
		$this->assertContains( 'standard', $slugs, 'The built-in "standard" class is always present.' );
		$this->assertContains( 'reduced', $slugs, 'The seeded "Reduced" class is listed.' );
	}

	public function test_output_does_not_leak_raw_tax_class_fields(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( self::ABILITY )->execute( array() );

		$row = $result['items'][0];
		$this->assertSame( self::ROW_KEYS, array_keys( $row ) );
		$this->assertArrayNotHasKey( '_links', $row );
	}

	public function test_wrong_capability_is_denied(): void {
		$this->actingAs( 'subscriber' );

		$ability = wp_get_ability( self::ABILITY );

		$this->assertFalse( $ability->check_permissions( array() ) );

		$result = $ability->execute( array() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_logged_out_user_is_denied(): void {
		wp_set_current_user( 0 );

		$result = wp_get_ability( self::ABILITY )->execute( array() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}
}
