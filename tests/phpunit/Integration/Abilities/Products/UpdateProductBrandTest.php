<?php
/**
 * Integration tests for the wc-products/update-product-brand ability.
 *
 * @package AbilitiesCatalogWoo\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogWoo\Tests\Integration\Abilities\Products;

use GalatanOvidiu\AbilitiesCatalogWoo\Support\WooPlugin;
use GalatanOvidiu\AbilitiesCatalogWoo\Tests\TestCase;
use WP_Error;

/**
 * Exercises wc-products/update-product-brand: a name/description change on a
 * seeded brand, the missing-brand 404 that must not collapse to a permission
 * error, the wrong-capability denial (with the brand unchanged), and the exact
 * closed output shape.
 *
 * The brand abilities only register when the WooCommerce Brands feature has
 * registered its `/wc/v3/products/brands` route, so every behavioral test guards
 * on {@see WooPlugin::hasBrandsSupport()} and skips cleanly when the test env's
 * WooCommerce build does not ship Brands.
 */
final class UpdateProductBrandTest extends TestCase {

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
			$this->markTestSkipped( 'WooCommerce Brands feature is not active in this environment.' );
		}

		$ability = wp_get_ability( 'wc-products/update-product-brand' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'wc-products/update-product-brand', $ability->get_name() );
	}

	public function test_admin_updates_brand_name_and_description(): void {
		if ( ! WooPlugin::hasBrandsSupport() ) {
			$this->markTestSkipped( 'WooCommerce Brands feature is not active in this environment.' );
		}

		$this->actingAs( 'administrator' );

		$id = $this->seedBrand( 'Acme', 0, '' );

		$result = wp_get_ability( 'wc-products/update-product-brand' )->execute(
			array(
				'id'          => $id,
				'name'        => 'Acme Corp',
				'description' => 'Quality goods.',
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( $id, $result['id'] );
		$this->assertSame( 'Acme Corp', $result['name'] );
		$this->assertSame( 'Quality goods.', $result['description'] );

		// The change persisted to the live term.
		$this->assertSame( 'Acme Corp', get_term( $id, 'product_brand' )->name );
		$this->assertSame( 'Quality goods.', get_term( $id, 'product_brand' )->description );
	}

	public function test_output_shape_is_exact_and_closed(): void {
		if ( ! WooPlugin::hasBrandsSupport() ) {
			$this->markTestSkipped( 'WooCommerce Brands feature is not active in this environment.' );
		}

		$this->actingAs( 'administrator' );

		$id = $this->seedBrand( 'Globex', 0, '' );

		$result = wp_get_ability( 'wc-products/update-product-brand' )->execute(
			array(
				'id'   => $id,
				'name' => 'Globex Inc',
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( self::EXPECTED_KEYS, array_keys( $result ) );

		// No raw brand fields leak through.
		$this->assertArrayNotHasKey( 'display', $result );
		$this->assertArrayNotHasKey( 'image', $result );
		$this->assertArrayNotHasKey( 'menu_order', $result );
		$this->assertArrayNotHasKey( '_links', $result );
	}

	public function test_missing_brand_returns_404_not_permission_error(): void {
		if ( ! WooPlugin::hasBrandsSupport() ) {
			$this->markTestSkipped( 'WooCommerce Brands feature is not active in this environment.' );
		}

		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'wc-products/update-product-brand' )->execute(
			array(
				'id'   => 99999999,
				'name' => 'Ghost',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'woocommerce_rest_term_invalid', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] );
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_subscriber_is_denied_and_brand_unchanged(): void {
		if ( ! WooPlugin::hasBrandsSupport() ) {
			$this->markTestSkipped( 'WooCommerce Brands feature is not active in this environment.' );
		}

		$id = $this->seedBrand( 'Acme', 0, '' );

		$this->actingAs( 'subscriber' );

		$ability = wp_get_ability( 'wc-products/update-product-brand' );

		$this->assertFalse(
			$ability->check_permissions(
				array(
					'id'   => $id,
					'name' => 'Acme Corp',
				)
			)
		);

		$result = $ability->execute(
			array(
				'id'   => $id,
				'name' => 'Acme Corp',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );

		// The denied write did not change the brand.
		$this->assertSame( 'Acme', get_term( $id, 'product_brand' )->name );
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
