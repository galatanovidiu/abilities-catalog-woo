<?php
/**
 * Integration tests for the og-wc-products/get-product-brand ability.
 *
 * @package AbilitiesCatalogWoo\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogWoo\Tests\Integration\Abilities\Products;

use GalatanOvidiu\AbilitiesCatalogWoo\Support\WooPlugin;
use GalatanOvidiu\AbilitiesCatalogWoo\Tests\TestCase;
use WP_Error;

/**
 * Exercises og-wc-products/get-product-brand: the shaped single-brand term row, the
 * parent linkage, the missing-brand 404 that must not collapse to a permission
 * error, the wrong-capability denial, and the exact closed output shape (no
 * display/image/menu_order leak).
 *
 * The Brands feature registers `/wc/v3/products/brands` on `rest_api_init`; the
 * distributed wp-env WooCommerce build may not include it, so every test that
 * needs the route guards on {@see WooPlugin::hasBrandsSupport()} and skips
 * cleanly when it is false.
 */
final class GetProductBrandTest extends TestCase {

	/**
	 * The closed key set the ability returns for one brand term row.
	 *
	 * @var list<string>
	 */
	private const EXPECTED_KEYS = array(
		'id',
		'name',
		'slug',
		'parent',
		'count',
		'description',
	);

	public function test_ability_is_registered(): void {
		if ( ! WooPlugin::hasBrandsSupport() ) {
			$this->markTestSkipped( 'The WooCommerce Brands feature is not active in this environment.' );
		}

		$ability = wp_get_ability( 'og-wc-products/get-product-brand' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'og-wc-products/get-product-brand', $ability->get_name() );
	}

	public function test_admin_reads_brand_detail(): void {
		if ( ! WooPlugin::hasBrandsSupport() ) {
			$this->markTestSkipped( 'The WooCommerce Brands feature is not active in this environment.' );
		}

		$this->actingAs( 'administrator' );

		$parent_id = $this->seedBrand( 'Footwear', 0, '' );
		$id        = $this->seedBrand( 'Acme', $parent_id, 'Premium running shoes.' );

		$result = wp_get_ability( 'og-wc-products/get-product-brand' )->execute( array( 'id' => $id ) );

		$this->assertIsArray( $result );
		$this->assertSame( $id, $result['id'] );
		$this->assertSame( 'Acme', $result['name'] );
		$this->assertSame( 'acme', $result['slug'] );
		$this->assertSame( $parent_id, $result['parent'] );
		$this->assertSame( 'Premium running shoes.', $result['description'] );
		$this->assertIsInt( $result['count'] );
	}

	public function test_top_level_brand_has_zero_parent(): void {
		if ( ! WooPlugin::hasBrandsSupport() ) {
			$this->markTestSkipped( 'The WooCommerce Brands feature is not active in this environment.' );
		}

		$this->actingAs( 'administrator' );

		$id = $this->seedBrand( 'Globex', 0, '' );

		$result = wp_get_ability( 'og-wc-products/get-product-brand' )->execute( array( 'id' => $id ) );

		$this->assertIsArray( $result );
		$this->assertSame( 0, $result['parent'] );
	}

	public function test_output_shape_is_exact_and_closed(): void {
		if ( ! WooPlugin::hasBrandsSupport() ) {
			$this->markTestSkipped( 'The WooCommerce Brands feature is not active in this environment.' );
		}

		$this->actingAs( 'administrator' );

		$id = $this->seedBrand( 'Initech', 0, '' );

		$result = wp_get_ability( 'og-wc-products/get-product-brand' )->execute( array( 'id' => $id ) );

		$this->assertIsArray( $result );
		$this->assertSame( self::EXPECTED_KEYS, array_keys( $result ) );

		// No raw brand/category fields leak through.
		$this->assertArrayNotHasKey( 'display', $result );
		$this->assertArrayNotHasKey( 'image', $result );
		$this->assertArrayNotHasKey( 'menu_order', $result );
		$this->assertArrayNotHasKey( '_links', $result );
	}

	public function test_missing_brand_returns_404_not_permission_error(): void {
		if ( ! WooPlugin::hasBrandsSupport() ) {
			$this->markTestSkipped( 'The WooCommerce Brands feature is not active in this environment.' );
		}

		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'og-wc-products/get-product-brand' )->execute( array( 'id' => 99999999 ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'woocommerce_rest_term_invalid', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] );
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_subscriber_is_denied(): void {
		if ( ! WooPlugin::hasBrandsSupport() ) {
			$this->markTestSkipped( 'The WooCommerce Brands feature is not active in this environment.' );
		}

		$id = $this->seedBrand( 'Umbrella', 0, '' );
		$this->actingAs( 'subscriber' );

		$ability = wp_get_ability( 'og-wc-products/get-product-brand' );

		$this->assertFalse( $ability->check_permissions( array( 'id' => $id ) ) );

		$result = $ability->execute( array( 'id' => $id ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	/**
	 * Seeds a product_brand term and returns its ID.
	 *
	 * @param string $name        The brand name.
	 * @param int    $parent      The parent term ID, or 0 for a top-level brand.
	 * @param string $description The brand description.
	 * @return int The created term ID.
	 */
	private function seedBrand( string $name, int $parent, string $description ): int {
		$term = wp_insert_term(
			$name,
			'product_brand',
			array(
				'parent'      => $parent,
				'description' => $description,
			)
		);

		$this->assertIsArray( $term );

		return (int) $term['term_id'];
	}
}
