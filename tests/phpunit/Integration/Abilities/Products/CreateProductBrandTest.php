<?php
/**
 * Integration tests for the og-wc-products/create-product-brand ability.
 *
 * @package AbilitiesCatalogWoo\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogWoo\Tests\Integration\Abilities\Products;

use GalatanOvidiu\AbilitiesCatalogWoo\Support\WooPlugin;
use GalatanOvidiu\AbilitiesCatalogWoo\Tests\TestCase;
use WP_Error;
use WP_Term;

/**
 * Exercises og-wc-products/create-product-brand: the top-level happy path, a nested
 * brand whose returned parent matches a seeded brand, the wrong-capability denial,
 * and the exact closed output shape (no display, image, or menu_order leak).
 *
 * The Brands feature registers `/wc/v3/products/brands` on `rest_api_init`; the
 * distributed wp-env WooCommerce build may not include it, so every test that
 * needs the route guards on {@see WooPlugin::hasBrandsSupport()} and skips cleanly
 * when it is false.
 */
final class CreateProductBrandTest extends TestCase {

	/**
	 * The full closed key set the ability returns: the shaped term row.
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

		$ability = wp_get_ability( 'og-wc-products/create-product-brand' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'og-wc-products/create-product-brand', $ability->get_name() );
	}

	public function test_admin_creates_a_top_level_brand(): void {
		if ( ! WooPlugin::hasBrandsSupport() ) {
			$this->markTestSkipped( 'The WooCommerce Brands feature is not active in this environment.' );
		}

		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'og-wc-products/create-product-brand' )->execute(
			array( 'name' => 'Acme' )
		);

		$this->assertIsArray( $result );
		$this->assertGreaterThan( 0, $result['id'] );
		$this->assertSame( 'Acme', $result['name'] );
		$this->assertSame( 0, $result['parent'] );

		// The brand persisted to the product_brand taxonomy.
		$term = get_term( $result['id'], 'product_brand' );
		$this->assertInstanceOf( WP_Term::class, $term );
		$this->assertSame( 'Acme', $term->name );
	}

	public function test_admin_creates_a_child_brand(): void {
		if ( ! WooPlugin::hasBrandsSupport() ) {
			$this->markTestSkipped( 'The WooCommerce Brands feature is not active in this environment.' );
		}

		$this->actingAs( 'administrator' );

		$parent = wp_get_ability( 'og-wc-products/create-product-brand' )->execute(
			array( 'name' => 'Footwear' )
		);
		$this->assertIsArray( $parent );
		$parent_id = (int) $parent['id'];

		$child = wp_get_ability( 'og-wc-products/create-product-brand' )->execute(
			array(
				'name'   => 'Acme',
				'parent' => $parent_id,
			)
		);

		$this->assertIsArray( $child );
		$this->assertSame( $parent_id, $child['parent'] );
	}

	public function test_output_shape_is_exact_and_closed(): void {
		if ( ! WooPlugin::hasBrandsSupport() ) {
			$this->markTestSkipped( 'The WooCommerce Brands feature is not active in this environment.' );
		}

		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'og-wc-products/create-product-brand' )->execute(
			array(
				'name'        => 'Globex',
				'description' => 'A maker of fine things.',
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( self::EXPECTED_KEYS, array_keys( $result ) );

		$this->assertIsInt( $result['count'] );
		$this->assertSame( 'A maker of fine things.', $result['description'] );

		// The raw brand/category fields do not leak into the row.
		$this->assertArrayNotHasKey( 'display', $result );
		$this->assertArrayNotHasKey( 'image', $result );
		$this->assertArrayNotHasKey( 'menu_order', $result );
		$this->assertArrayNotHasKey( '_links', $result );
	}

	public function test_subscriber_is_denied_and_no_brand_created(): void {
		if ( ! WooPlugin::hasBrandsSupport() ) {
			$this->markTestSkipped( 'The WooCommerce Brands feature is not active in this environment.' );
		}

		$this->actingAs( 'subscriber' );

		$ability = wp_get_ability( 'og-wc-products/create-product-brand' );

		$this->assertFalse( $ability->check_permissions( array( 'name' => 'Acme' ) ) );

		$result = $ability->execute( array( 'name' => 'Acme' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );

		// The denied write created nothing.
		$this->assertFalse( get_term_by( 'name', 'Acme', 'product_brand' ) );
	}
}
