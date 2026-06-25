<?php
/**
 * Integration tests for the og-wc-products/get-product-category ability.
 *
 * @package AbilitiesCatalogWoo\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogWoo\Tests\Integration\Abilities\Products;

use GalatanOvidiu\AbilitiesCatalogWoo\Tests\TestCase;
use WP_Error;

/**
 * Exercises og-wc-products/get-product-category: the shaped single-category term
 * row, the parent linkage, the missing-category 404 that must not collapse to a
 * permission error, the wrong-capability denial, and the exact closed output
 * shape (no display/image/menu_order leak).
 */
final class GetProductCategoryTest extends TestCase {

	/**
	 * The closed key set the ability returns for one category term row.
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
		$ability = wp_get_ability( 'og-wc-products/get-product-category' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'og-wc-products/get-product-category', $ability->get_name() );
	}

	public function test_admin_reads_category_detail(): void {
		$this->actingAs( 'administrator' );

		$parent_id = $this->seedCategory( 'Hats', 0, '' );
		$id        = $this->seedCategory( 'Beanies', $parent_id, 'Warm winter beanies.' );

		$result = wp_get_ability( 'og-wc-products/get-product-category' )->execute( array( 'id' => $id ) );

		$this->assertIsArray( $result );
		$this->assertSame( $id, $result['id'] );
		$this->assertSame( 'Beanies', $result['name'] );
		$this->assertSame( 'beanies', $result['slug'] );
		$this->assertSame( $parent_id, $result['parent'] );
		$this->assertSame( 'Warm winter beanies.', $result['description'] );
		$this->assertIsInt( $result['count'] );
	}

	public function test_top_level_category_has_zero_parent(): void {
		$this->actingAs( 'administrator' );

		$id = $this->seedCategory( 'Apparel', 0, '' );

		$result = wp_get_ability( 'og-wc-products/get-product-category' )->execute( array( 'id' => $id ) );

		$this->assertIsArray( $result );
		$this->assertSame( 0, $result['parent'] );
	}

	public function test_output_shape_is_exact_and_closed(): void {
		$this->actingAs( 'administrator' );

		$id = $this->seedCategory( 'Gadgets', 0, '' );

		$result = wp_get_ability( 'og-wc-products/get-product-category' )->execute( array( 'id' => $id ) );

		$this->assertIsArray( $result );
		$this->assertSame( self::EXPECTED_KEYS, array_keys( $result ) );

		// No raw category fields leak through.
		$this->assertArrayNotHasKey( 'display', $result );
		$this->assertArrayNotHasKey( 'image', $result );
		$this->assertArrayNotHasKey( 'menu_order', $result );
		$this->assertArrayNotHasKey( '_links', $result );
	}

	public function test_missing_category_returns_404_not_permission_error(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'og-wc-products/get-product-category' )->execute( array( 'id' => 99999999 ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'woocommerce_rest_term_invalid', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] );
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_subscriber_is_denied(): void {
		$id = $this->seedCategory( 'Gadgets', 0, '' );
		$this->actingAs( 'subscriber' );

		$ability = wp_get_ability( 'og-wc-products/get-product-category' );

		$this->assertFalse( $ability->check_permissions( array( 'id' => $id ) ) );

		$result = $ability->execute( array( 'id' => $id ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	/**
	 * Seeds a product_cat term and returns its ID.
	 *
	 * @param string $name        The category name.
	 * @param int    $parent      The parent term ID, or 0 for a top-level category.
	 * @param string $description The category description.
	 * @return int The created term ID.
	 */
	private function seedCategory( string $name, int $parent, string $description ): int {
		$term = wp_insert_term(
			$name,
			'product_cat',
			array(
				'parent'      => $parent,
				'description' => $description,
			)
		);

		$this->assertIsArray( $term );

		return (int) $term['term_id'];
	}
}
