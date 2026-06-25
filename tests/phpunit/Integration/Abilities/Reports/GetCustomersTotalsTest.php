<?php
/**
 * Integration tests for the `og-wc-reports/get-customers-totals` ability.
 *
 * @package AbilitiesCatalogWoo\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogWoo\Tests\Integration\Abilities\Reports;

use GalatanOvidiu\AbilitiesCatalogWoo\Tests\TestCase;
use WP_Error;

/**
 * @covers \GalatanOvidiu\AbilitiesCatalogWoo\Abilities\Woo\Reports\GetCustomersTotals
 */
final class GetCustomersTotalsTest extends TestCase {

	private const ABILITY = 'og-wc-reports/get-customers-totals';

	/**
	 * The ability registers and resolves under its name.
	 */
	public function test_ability_is_registered(): void {
		$ability = wp_get_ability( self::ABILITY );

		$this->assertNotNull( $ability );
		$this->assertSame( self::ABILITY, $ability->get_name() );
	}

	/**
	 * Happy path: returns the paying / non_paying rows with integer totals.
	 */
	public function test_execute_returns_paying_and_non_paying_rows(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( self::ABILITY )->execute( array() );

		$this->assertIsArray( $result );
		$this->assertSame( array( 'items', 'total' ), array_keys( $result ) );
		$this->assertIsArray( $result['items'] );
		$this->assertSame( 2, $result['total'] );
		$this->assertCount( 2, $result['items'] );

		$slugs = array_column( $result['items'], 'slug' );
		sort( $slugs );
		$this->assertSame( array( 'non_paying', 'paying' ), $slugs );

		foreach ( $result['items'] as $row ) {
			$this->assertIsInt( $row['total'] );
		}
	}

	/**
	 * A user without `view_woocommerce_reports` is denied at the permission gate.
	 */
	public function test_wrong_capability_is_denied(): void {
		$this->actingAs( 'subscriber' );

		$ability = wp_get_ability( self::ABILITY );

		$this->assertFalse( $ability->check_permissions( array() ) );

		$result = $ability->execute( array() );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	/**
	 * Output shape: every row carries exactly {slug, name, total}, nothing leaks.
	 */
	public function test_output_shape_is_closed(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( self::ABILITY )->execute( array() );

		$this->assertIsArray( $result );

		foreach ( $result['items'] as $row ) {
			$keys = array_keys( $row );
			sort( $keys );
			$this->assertSame( array( 'name', 'slug', 'total' ), $keys );
			$this->assertIsString( $row['slug'] );
			$this->assertIsString( $row['name'] );
			$this->assertIsInt( $row['total'] );
		}

		$this->assertIsInt( $result['total'] );
	}
}
