<?php
/**
 * Integration tests for the og-wc-products/create-product-category ability.
 *
 * @package AbilitiesCatalogWoo\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogWoo\Tests\Integration\Abilities\Products;

use GalatanOvidiu\AbilitiesCatalogWoo\Tests\TestCase;
use WP_Error;

/**
 * Exercises og-wc-products/create-product-category: the top-level happy path, a
 * nested category whose returned parent matches a seeded category, the duplicate
 * slug that surfaces WordPress core's term_exists 400 (not a permission collapse),
 * the wrong-capability denial, and the exact closed output shape (no display,
 * image, or menu_order leak).
 */
final class CreateProductCategoryTest extends TestCase {

	/**
	 * The full closed key set the ability returns: the shaped term summary fields.
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
		$ability = wp_get_ability( 'og-wc-products/create-product-category' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'og-wc-products/create-product-category', $ability->get_name() );
	}

	public function test_admin_creates_a_top_level_category(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'og-wc-products/create-product-category' )->execute(
			array( 'name' => 'Hats' )
		);

		$this->assertIsArray( $result );
		$this->assertGreaterThan( 0, $result['id'] );
		$this->assertSame( 'Hats', $result['name'] );
		$this->assertSame( 0, $result['parent'] );

		// The category persisted to the product_cat taxonomy.
		$term = get_term( $result['id'], 'product_cat' );
		$this->assertInstanceOf( \WP_Term::class, $term );
		$this->assertSame( 'Hats', $term->name );
	}

	public function test_admin_creates_a_nested_category(): void {
		$this->actingAs( 'administrator' );

		$parent = wp_insert_term( 'Apparel', 'product_cat' );
		$this->assertIsArray( $parent );
		$parent_id = (int) $parent['term_id'];

		$result = wp_get_ability( 'og-wc-products/create-product-category' )->execute(
			array(
				'name'   => 'Hats',
				'parent' => $parent_id,
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( $parent_id, $result['parent'] );
	}

	public function test_output_shape_is_exact_and_closed(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'og-wc-products/create-product-category' )->execute(
			array(
				'name'    => 'Hats',
				'display' => 'products',
				'image'   => array( 'alt' => 'A hat' ),
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( self::EXPECTED_KEYS, array_keys( $result ) );

		// The write-only inputs and raw category fields do not leak into the row.
		$this->assertArrayNotHasKey( 'display', $result );
		$this->assertArrayNotHasKey( 'image', $result );
		$this->assertArrayNotHasKey( 'menu_order', $result );
		$this->assertArrayNotHasKey( '_links', $result );
	}

	public function test_duplicate_name_returns_term_exists_400_not_permission_error(): void {
		$this->actingAs( 'administrator' );

		wp_insert_term( 'Hats', 'product_cat', array( 'slug' => 'hats' ) );

		// A create with the SAME NAME at the same level is the real rejection path:
		// wp_insert_term() finds a name match among the siblings at parent 0 and
		// returns term_exists, which the wrapped wc/v3 route forwards with status
		// 400. A duplicate *slug* with a different name would instead succeed with
		// an auto-suffixed slug, so it is not a rejection path.
		$result = wp_get_ability( 'og-wc-products/create-product-category' )->execute(
			array(
				'name' => 'Hats',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'term_exists', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] );
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_subscriber_is_denied_and_no_category_created(): void {
		$this->actingAs( 'subscriber' );

		$ability = wp_get_ability( 'og-wc-products/create-product-category' );

		$this->assertFalse( $ability->check_permissions( array( 'name' => 'Hats' ) ) );

		$result = $ability->execute( array( 'name' => 'Hats' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );

		// The denied write created nothing.
		$this->assertFalse( get_term_by( 'name', 'Hats', 'product_cat' ) );
	}
}
