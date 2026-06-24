<?php
/**
 * Integration tests for the `wc-reports/get-products-totals` ability.
 *
 * @package AbilitiesCatalogWoo\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogWoo\Tests\Integration\Abilities\Reports;

use GalatanOvidiu\AbilitiesCatalogWoo\Tests\TestCase;
use WC_Product_Simple;
use WP_Error;

/**
 * @covers \GalatanOvidiu\AbilitiesCatalogWoo\Abilities\Woo\Reports\GetProductsTotals
 */
final class GetProductsTotalsTest extends TestCase {

	private const ABILITY = 'wc-reports/get-products-totals';

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

	public function test_happy_path_returns_simple_type_row(): void {
		$this->actingAs( 'administrator' );
		$this->seedSimpleProduct();

		$result = wp_get_ability( self::ABILITY )->execute( array() );

		$this->assertIsArray( $result );
		$this->assertSame( array( 'items', 'total' ), array_keys( $result ) );
		$this->assertIsArray( $result['items'] );
		$this->assertIsInt( $result['total'] );
		$this->assertSame( count( $result['items'] ), $result['total'] );
		$this->assertNotEmpty( $result['items'] );

		$simple = $this->rowForSlug( $result['items'], 'simple' );
		$this->assertNotNull( $simple, 'Expected a "simple" product-type row.' );
		$this->assertIsInt( $simple['total'] );
		$this->assertGreaterThanOrEqual( 1, $simple['total'] );
	}

	public function test_output_shape_has_no_raw_report_fields(): void {
		$this->actingAs( 'administrator' );
		$this->seedSimpleProduct();

		$result = wp_get_ability( self::ABILITY )->execute( array() );

		$this->assertSame( array( 'items', 'total' ), array_keys( $result ) );

		foreach ( $result['items'] as $row ) {
			$this->assertSame( self::ROW_KEYS, array_keys( $row ) );
			$this->assertIsString( $row['slug'] );
			$this->assertIsString( $row['name'] );
			$this->assertIsInt( $row['total'] );
			$this->assertArrayNotHasKey( '_links', $row );
		}
	}

	public function test_wrong_capability_is_denied(): void {
		$this->seedSimpleProduct();
		$this->actingAs( 'subscriber' );

		$ability = wp_get_ability( self::ABILITY );

		$this->assertFalse( $ability->check_permissions( array() ) );

		$result = $ability->execute( array() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	/**
	 * Returns the row whose `slug` matches, or null.
	 *
	 * @param array<int,array<string,mixed>> $rows The shaped totals rows.
	 * @param string                         $slug The product-type slug to find.
	 * @return array<string,mixed>|null The matching row, or null.
	 */
	private function rowForSlug( array $rows, string $slug ): ?array {
		foreach ( $rows as $row ) {
			if ( $slug === $row['slug'] ) {
				return $row;
			}
		}

		return null;
	}

	/**
	 * Seeds a published simple product.
	 *
	 * Built with WooCommerce's runtime object API (WC_Product_Simple), not the
	 * WC_Helper_Product test factory, because the test environment mounts the
	 * distributed WooCommerce build, which ships no tests/ helper framework.
	 *
	 * @return WC_Product_Simple The created simple product.
	 */
	private function seedSimpleProduct(): WC_Product_Simple {
		$product = new WC_Product_Simple();
		$product->set_name( 'Seeded Product' );
		$product->set_status( 'publish' );
		$product->set_regular_price( '10.00' );
		$product->save();

		return $product;
	}
}
