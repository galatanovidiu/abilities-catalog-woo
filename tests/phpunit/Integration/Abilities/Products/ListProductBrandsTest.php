<?php
/**
 * Integration tests for the `og-wc-products/list-product-brands` ability.
 *
 * @package AbilitiesCatalogWoo\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogWoo\Tests\Integration\Abilities\Products;

use GalatanOvidiu\AbilitiesCatalogWoo\Support\WooPlugin;
use GalatanOvidiu\AbilitiesCatalogWoo\Tests\TestCase;
use WP_Error;

/**
 * @covers \GalatanOvidiu\AbilitiesCatalogWoo\Abilities\Woo\Products\ListProductBrands
 */
final class ListProductBrandsTest extends TestCase {

	private const ABILITY = 'og-wc-products/list-product-brands';

	/**
	 * The exact keys a shaped brand summary row exposes.
	 *
	 * Asserting against this fixed set proves the raw brand body — which carries
	 * `display`, `image`, and `menu_order` — is never leaked.
	 *
	 * @var list<string>
	 */
	private const ROW_KEYS = array(
		'id',
		'name',
		'slug',
		'parent',
		'count',
		'description',
	);

	/**
	 * Skips the test when the WooCommerce Brands feature is not present.
	 *
	 * The two brand abilities are conditional on the `/wc/v3/products/brands`
	 * route, which the Brands feature registers on `rest_api_init`. The distributed
	 * test build may not register it, in which case `og-wc-products/list-product-brands`
	 * does not register and there is nothing to exercise.
	 */
	public function set_up(): void {
		parent::set_up();

		if ( ! WooPlugin::hasBrandsSupport() ) {
			$this->markTestSkipped( 'WooCommerce Brands feature is not active; the brand route is not registered.' );
		}
	}

	public function test_registered(): void {
		$ability = wp_get_ability( self::ABILITY );

		$this->assertNotNull( $ability );
		$this->assertSame( self::ABILITY, $ability->get_name() );
	}

	public function test_happy_path_returns_shaped_rows(): void {
		$this->actingAs( 'administrator' );
		$this->seedBrand( 'Acme' );
		$this->seedBrand( 'Globex' );

		$result = wp_get_ability( self::ABILITY )->execute( array() );

		$this->assertIsArray( $result );
		$this->assertSame( array( 'items', 'total' ), array_keys( $result ) );
		$this->assertIsArray( $result['items'] );
		$this->assertIsInt( $result['total'] );
		$this->assertGreaterThanOrEqual( 2, $result['total'] );
		$this->assertNotEmpty( $result['items'] );

		$row = $result['items'][0];
		$this->assertSame( self::ROW_KEYS, array_keys( $row ) );
		$this->assertIsInt( $row['id'] );
		$this->assertIsString( $row['name'] );
		$this->assertIsString( $row['slug'] );
		$this->assertIsInt( $row['parent'] );
		$this->assertIsInt( $row['count'] );
		$this->assertIsString( $row['description'] );
	}

	public function test_search_narrows_results(): void {
		$this->actingAs( 'administrator' );
		$this->seedBrand( 'Galatan Outdoors' );
		$this->seedBrand( 'Unrelated Imports' );

		$result = wp_get_ability( self::ABILITY )->execute( array( 'search' => 'Galatan Outdoors' ) );

		$names = wp_list_pluck( $result['items'], 'name' );
		$this->assertContains( 'Galatan Outdoors', $names );
		$this->assertNotContains( 'Unrelated Imports', $names );
	}

	public function test_slug_filter_narrows_to_one_brand(): void {
		$this->actingAs( 'administrator' );
		$this->seedBrand( 'Northwind' );
		$this->seedBrand( 'Contoso' );

		$result = wp_get_ability( self::ABILITY )->execute( array( 'slug' => 'northwind' ) );

		$slugs = wp_list_pluck( $result['items'], 'slug' );
		$this->assertContains( 'northwind', $slugs );
		$this->assertNotContains( 'contoso', $slugs );
	}

	public function test_parent_filter_narrows_to_children(): void {
		$this->actingAs( 'administrator' );

		$parent_id = $this->seedBrand( 'Parent Group' );
		$child_id  = $this->seedBrand( 'Child Label', $parent_id );
		$top_level = $this->seedBrand( 'Standalone Label' );

		$result = wp_get_ability( self::ABILITY )->execute( array( 'parent' => $parent_id ) );

		$ids = wp_list_pluck( $result['items'], 'id' );
		$this->assertContains( $child_id, $ids );
		$this->assertNotContains( $top_level, $ids );
		$this->assertNotContains( $parent_id, $ids );

		foreach ( $result['items'] as $row ) {
			$this->assertSame( $parent_id, $row['parent'] );
		}
	}

	public function test_total_comes_from_the_pagination_header(): void {
		$this->actingAs( 'administrator' );

		$this->seedBrand( 'Brand One' );
		$this->seedBrand( 'Brand Two' );
		$this->seedBrand( 'Brand Three' );

		$result = wp_get_ability( self::ABILITY )->execute( array( 'per_page' => 1 ) );

		// One row on the page, but total reflects the full matching set via X-WP-Total.
		$this->assertCount( 1, $result['items'] );
		$this->assertGreaterThanOrEqual( 3, $result['total'] );
	}

	public function test_output_shape_has_no_raw_brand_fields(): void {
		$this->actingAs( 'administrator' );
		$this->seedBrand( 'Initech' );

		$result = wp_get_ability( self::ABILITY )->execute( array() );

		$this->assertSame( array( 'items', 'total' ), array_keys( $result ) );
		$row = $result['items'][0];
		$this->assertSame( self::ROW_KEYS, array_keys( $row ) );
		$this->assertArrayNotHasKey( 'display', $row );
		$this->assertArrayNotHasKey( 'image', $row );
		$this->assertArrayNotHasKey( 'menu_order', $row );
		$this->assertArrayNotHasKey( '_links', $row );
	}

	public function test_wrong_capability_is_denied(): void {
		$this->seedBrand( 'Acme' );
		$this->actingAs( 'subscriber' );

		$result = wp_get_ability( self::ABILITY )->execute( array() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	/**
	 * Seeds a product brand term and returns its term ID.
	 *
	 * Uses `wp_insert_term()` against the `product_brand` taxonomy (registered by
	 * the WooCommerce Brands feature on load), which is the standard way to seed
	 * brand terms in the distributed WooCommerce build that ships no tests/ helper
	 * framework.
	 *
	 * @param string $name      The brand name.
	 * @param int    $parent_id Optional parent brand term ID (0 for top-level).
	 * @return int The created term ID.
	 */
	private function seedBrand( string $name, int $parent_id = 0 ): int {
		$term = wp_insert_term( $name, 'product_brand', array( 'parent' => $parent_id ) );

		$this->assertIsArray( $term );

		return (int) $term['term_id'];
	}
}
