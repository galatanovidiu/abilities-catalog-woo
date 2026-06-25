<?php
/**
 * Integration tests for the og-wc-products/update-product-category ability.
 *
 * @package AbilitiesCatalogWoo\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogWoo\Tests\Integration\Abilities\Products;

use GalatanOvidiu\AbilitiesCatalogWoo\Tests\TestCase;
use WP_Error;

/**
 * Exercises og-wc-products/update-product-category: a name change on a seeded
 * category, a parent re-nesting, the missing-category 404 that must not collapse
 * to a permission error, the wrong-capability denial (with the category
 * unchanged), and the exact closed output shape (no display/image/menu_order
 * leak).
 */
final class UpdateProductCategoryTest extends TestCase {

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
		$ability = wp_get_ability( 'og-wc-products/update-product-category' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'og-wc-products/update-product-category', $ability->get_name() );
	}

	public function test_admin_updates_category_name(): void {
		$this->actingAs( 'administrator' );

		$id = $this->seedCategory( 'Hats', 0, '' );

		$result = wp_get_ability( 'og-wc-products/update-product-category' )->execute(
			array(
				'id'   => $id,
				'name' => 'Headwear',
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( $id, $result['id'] );
		$this->assertSame( 'Headwear', $result['name'] );

		// The change persisted to the live term.
		$this->assertSame( 'Headwear', get_term( $id, 'product_cat' )->name );
	}

	public function test_admin_renests_category_under_a_parent(): void {
		$this->actingAs( 'administrator' );

		$parent_id = $this->seedCategory( 'Apparel', 0, '' );
		$id        = $this->seedCategory( 'Beanies', 0, '' );

		$result = wp_get_ability( 'og-wc-products/update-product-category' )->execute(
			array(
				'id'     => $id,
				'parent' => $parent_id,
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( $parent_id, $result['parent'] );
		$this->assertSame( $parent_id, (int) get_term( $id, 'product_cat' )->parent );
	}

	public function test_output_shape_is_exact_and_closed(): void {
		$this->actingAs( 'administrator' );

		$id = $this->seedCategory( 'Gadgets', 0, '' );

		$result = wp_get_ability( 'og-wc-products/update-product-category' )->execute(
			array(
				'id'         => $id,
				'name'       => 'Devices',
				'display'    => 'products',
				'menu_order' => 3,
			)
		);

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

		$result = wp_get_ability( 'og-wc-products/update-product-category' )->execute(
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

	public function test_subscriber_is_denied_and_category_unchanged(): void {
		$id = $this->seedCategory( 'Hats', 0, '' );

		$this->actingAs( 'subscriber' );

		$ability = wp_get_ability( 'og-wc-products/update-product-category' );

		$this->assertFalse(
			$ability->check_permissions(
				array(
					'id'   => $id,
					'name' => 'Headwear',
				)
			)
		);

		$result = $ability->execute(
			array(
				'id'   => $id,
				'name' => 'Headwear',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );

		// The denied write did not change the category.
		$this->assertSame( 'Hats', get_term( $id, 'product_cat' )->name );
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
