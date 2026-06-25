<?php
/**
 * Integration tests for the og-wc-products/delete-product-brand ability.
 *
 * @package AbilitiesCatalogWoo\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogWoo\Tests\Integration\Abilities\Products;

use GalatanOvidiu\AbilitiesCatalogWoo\Support\WooPlugin;
use GalatanOvidiu\AbilitiesCatalogWoo\Tests\TestCase;
use WP_Error;

/**
 * Exercises og-wc-products/delete-product-brand: the permanent force delete of a
 * product_brand term, the captured name, the missing-brand 404 that must not
 * collapse to a permission error, the wrong-capability denial (the brand
 * survives), and the exact closed output shape with no edit_link.
 *
 * The Brands feature registers `/wc/v3/products/brands` on `rest_api_init`; the
 * distributed wp-env WooCommerce build may not include it, so every test that
 * needs the route guards on {@see WooPlugin::hasBrandsSupport()} and skips
 * cleanly when it is false.
 */
final class DeleteProductBrandTest extends TestCase {

	/**
	 * The closed key set the ability returns for a deleted brand.
	 *
	 * @var list<string>
	 */
	private const EXPECTED_KEYS = array(
		'deleted',
		'id',
		'name',
		'force_used',
		'permanent',
	);

	public function test_ability_is_registered(): void {
		if ( ! WooPlugin::hasBrandsSupport() ) {
			$this->markTestSkipped( 'The WooCommerce Brands feature is not active in this environment.' );
		}

		$ability = wp_get_ability( 'og-wc-products/delete-product-brand' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'og-wc-products/delete-product-brand', $ability->get_name() );
	}

	public function test_admin_deletes_brand_permanently(): void {
		if ( ! WooPlugin::hasBrandsSupport() ) {
			$this->markTestSkipped( 'The WooCommerce Brands feature is not active in this environment.' );
		}

		$this->actingAs( 'administrator' );

		$id = $this->seedBrand( 'Acme' );

		$result = wp_get_ability( 'og-wc-products/delete-product-brand' )->execute( array( 'id' => $id ) );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['deleted'] );
		$this->assertSame( $id, $result['id'] );
		$this->assertSame( 'Acme', $result['name'] );
		$this->assertTrue( $result['force_used'] );
		$this->assertTrue( $result['permanent'] );

		// The brand is gone.
		$term = get_term( $id, 'product_brand' );
		$this->assertNull( $term );
	}

	public function test_output_shape_is_exact_and_closed(): void {
		if ( ! WooPlugin::hasBrandsSupport() ) {
			$this->markTestSkipped( 'The WooCommerce Brands feature is not active in this environment.' );
		}

		$this->actingAs( 'administrator' );

		$id = $this->seedBrand( 'Initech' );

		$result = wp_get_ability( 'og-wc-products/delete-product-brand' )->execute( array( 'id' => $id ) );

		$this->assertIsArray( $result );
		$this->assertSame( self::EXPECTED_KEYS, array_keys( $result ) );
		$this->assertArrayNotHasKey( 'edit_link', $result );
		$this->assertArrayNotHasKey( 'slug', $result );
		$this->assertArrayNotHasKey( '_links', $result );
	}

	public function test_missing_brand_returns_404_not_permission_error(): void {
		if ( ! WooPlugin::hasBrandsSupport() ) {
			$this->markTestSkipped( 'The WooCommerce Brands feature is not active in this environment.' );
		}

		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'og-wc-products/delete-product-brand' )->execute( array( 'id' => 99999999 ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'woocommerce_rest_term_invalid', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] );
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_subscriber_is_denied_and_brand_survives(): void {
		if ( ! WooPlugin::hasBrandsSupport() ) {
			$this->markTestSkipped( 'The WooCommerce Brands feature is not active in this environment.' );
		}

		$id = $this->seedBrand( 'Umbrella' );
		$this->actingAs( 'subscriber' );

		$ability = wp_get_ability( 'og-wc-products/delete-product-brand' );

		$this->assertFalse( $ability->check_permissions( array( 'id' => $id ) ) );

		$result = $ability->execute( array( 'id' => $id ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );

		// The brand survived the denied delete.
		$term = get_term( $id, 'product_brand' );
		$this->assertNotNull( $term );
		$this->assertNotInstanceOf( WP_Error::class, $term );
	}

	/**
	 * Seeds a product_brand term and returns its ID.
	 *
	 * @param string $name The brand name.
	 * @return int The created term ID.
	 */
	private function seedBrand( string $name ): int {
		$term = wp_insert_term( $name, 'product_brand' );

		$this->assertIsArray( $term );

		return (int) $term['term_id'];
	}
}
