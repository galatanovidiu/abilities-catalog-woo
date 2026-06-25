<?php
/**
 * Integration tests for the `og-wc-products/list-product-categories` ability.
 *
 * @package AbilitiesCatalogWoo\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogWoo\Tests\Integration\Abilities\Products;

use GalatanOvidiu\AbilitiesCatalogWoo\Tests\TestCase;
use WP_Error;

/**
 * @covers \GalatanOvidiu\AbilitiesCatalogWoo\Abilities\Woo\Products\ListProductCategories
 */
final class ListProductCategoriesTest extends TestCase {

	private const ABILITY = 'og-wc-products/list-product-categories';

	/**
	 * The exact keys a shaped category summary row exposes.
	 *
	 * Asserting against this fixed set proves the raw category body — which carries
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

	public function test_registered(): void {
		$ability = wp_get_ability( self::ABILITY );

		$this->assertNotNull( $ability );
		$this->assertSame( self::ABILITY, $ability->get_name() );
	}

	public function test_happy_path_returns_shaped_rows(): void {
		$this->actingAs( 'administrator' );
		$this->seedCategory( 'Hats' );
		$this->seedCategory( 'Shirts' );

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
		$this->seedCategory( 'Galatan Beanies' );
		$this->seedCategory( 'Unrelated Gloves' );

		$result = wp_get_ability( self::ABILITY )->execute( array( 'search' => 'Galatan Beanies' ) );

		$names = wp_list_pluck( $result['items'], 'name' );
		$this->assertContains( 'Galatan Beanies', $names );
		$this->assertNotContains( 'Unrelated Gloves', $names );
	}

	public function test_parent_filter_narrows_to_children(): void {
		$this->actingAs( 'administrator' );

		$parent_id = $this->seedCategory( 'Apparel' );
		$child_id   = $this->seedCategory( 'Jackets', $parent_id );
		$top_level  = $this->seedCategory( 'Standalone' );

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

		$this->seedCategory( 'Cat One' );
		$this->seedCategory( 'Cat Two' );
		$this->seedCategory( 'Cat Three' );

		$result = wp_get_ability( self::ABILITY )->execute( array( 'per_page' => 1 ) );

		// One row on the page, but total reflects the full matching set via X-WP-Total.
		$this->assertCount( 1, $result['items'] );
		$this->assertGreaterThanOrEqual( 3, $result['total'] );
	}

	public function test_output_shape_has_no_raw_category_fields(): void {
		$this->actingAs( 'administrator' );
		$this->seedCategory( 'Boots' );

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
		$this->seedCategory( 'Hats' );
		$this->actingAs( 'subscriber' );

		$result = wp_get_ability( self::ABILITY )->execute( array() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_logged_out_user_is_denied(): void {
		$this->seedCategory( 'Hats' );
		wp_set_current_user( 0 );

		$result = wp_get_ability( self::ABILITY )->execute( array() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	/**
	 * Seeds a product category term and returns its term ID.
	 *
	 * Uses `wp_insert_term()` against the `product_cat` taxonomy (registered by
	 * WooCommerce on load), which is the standard way to seed category terms in the
	 * distributed WooCommerce build that ships no tests/ helper framework.
	 *
	 * @param string $name      The category name.
	 * @param int    $parent_id Optional parent category term ID (0 for top-level).
	 * @return int The created term ID.
	 */
	private function seedCategory( string $name, int $parent_id = 0 ): int {
		$term = wp_insert_term( $name, 'product_cat', array( 'parent' => $parent_id ) );

		$this->assertIsArray( $term );

		return (int) $term['term_id'];
	}
}
