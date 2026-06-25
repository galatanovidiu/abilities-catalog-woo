<?php
/**
 * Integration tests for the `og-wc-reports/get-coupons-totals` ability.
 *
 * @package AbilitiesCatalogWoo\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogWoo\Tests\Integration\Abilities\Reports;

use GalatanOvidiu\AbilitiesCatalogWoo\Tests\TestCase;
use WP_Error;

/**
 * @covers \GalatanOvidiu\AbilitiesCatalogWoo\Abilities\Woo\Reports\GetCouponsTotals
 */
final class GetCouponsTotalsTest extends TestCase {

	private const ABILITY = 'og-wc-reports/get-coupons-totals';

	/**
	 * The exact keys a shaped totals row exposes.
	 *
	 * @var list<string>
	 */
	private const ROW_KEYS = array( 'slug', 'name', 'total' );

	public function test_registered(): void {
		$ability = wp_get_ability( self::ABILITY );

		$this->assertNotNull( $ability );
		$this->assertSame( self::ABILITY, $ability->get_name() );
	}

	public function test_happy_path_returns_coupon_type_rows(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( self::ABILITY )->execute( array() );

		$this->assertIsArray( $result );
		$this->assertSame( array( 'items', 'total' ), array_keys( $result ) );
		$this->assertIsArray( $result['items'] );
		$this->assertIsInt( $result['total'] );
		$this->assertNotEmpty( $result['items'] );
		$this->assertSame( count( $result['items'] ), $result['total'] );

		$slugs = wp_list_pluck( $result['items'], 'slug' );
		$this->assertContains( 'percent', $slugs );
		$this->assertContains( 'fixed_cart', $slugs );
		$this->assertContains( 'fixed_product', $slugs );
	}

	public function test_output_shape_is_closed_totals_rows(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( self::ABILITY )->execute( array() );

		$row = $result['items'][0];
		$this->assertSame( self::ROW_KEYS, array_keys( $row ) );
		$this->assertIsString( $row['slug'] );
		$this->assertIsString( $row['name'] );
		$this->assertIsInt( $row['total'] );

		$this->assertArrayNotHasKey( '_links', $row );
		$this->assertArrayNotHasKey( 'description', $row );
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
