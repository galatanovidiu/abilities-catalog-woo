<?php
/**
 * Integration tests for the wc-products/get-product ability.
 *
 * @package AbilitiesCatalogWoo\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogWoo\Tests\Integration\Abilities\Products;

use GalatanOvidiu\AbilitiesCatalogWoo\Tests\TestCase;
use WP_Error;
use WC_Product_Simple;

/**
 * Exercises wc-products/get-product: the shaped single-product record, the
 * detail fields (descriptions, categories, tags, images, attributes), the
 * missing-product 404 that must not collapse to a permission error, the
 * wrong-capability denial, and the exact closed output shape.
 */
final class GetProductTest extends TestCase {

	/**
	 * The full closed key set the ability returns: the summary fields plus the
	 * single-product detail fields.
	 *
	 * @var list<string>
	 */
	private const EXPECTED_KEYS = array(
		'id',
		'name',
		'type',
		'status',
		'sku',
		'price',
		'regular_price',
		'sale_price',
		'stock_status',
		'stock_quantity',
		'catalog_visibility',
		'permalink',
		'date_created',
		'edit_link',
		'description',
		'short_description',
		'categories',
		'tags',
		'images',
		'attributes',
	);

	public function test_ability_is_registered(): void {
		$ability = wp_get_ability( 'wc-products/get-product' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'wc-products/get-product', $ability->get_name() );
	}

	public function test_admin_reads_product_detail(): void {
		$this->actingAs( 'administrator' );

		$category_id = self::factory()->term->create(
			array(
				'taxonomy' => 'product_cat',
				'name'     => 'Gadgets',
			)
		);

		$id = $this->seedSimpleProduct(
			array(
				'name'              => 'Widget Pro',
				'description'       => 'The full description.',
				'short_description' => 'The short one.',
				'category_ids'      => array( $category_id ),
			)
		);

		$result = wp_get_ability( 'wc-products/get-product' )->execute( array( 'id' => $id ) );

		$this->assertIsArray( $result );
		$this->assertSame( $id, $result['id'] );
		$this->assertSame( 'Widget Pro', $result['name'] );
		$this->assertSame( 'simple', $result['type'] );
		$this->assertStringContainsString( 'The full description.', $result['description'] );
		$this->assertStringContainsString( 'The short one.', $result['short_description'] );

		// Categories project to flat { id, name, slug } rows.
		$this->assertIsArray( $result['categories'] );
		$this->assertCount( 1, $result['categories'] );
		$this->assertSame(
			array( 'id', 'name', 'slug' ),
			array_keys( $result['categories'][0] )
		);
		$this->assertSame( $category_id, $result['categories'][0]['id'] );
		$this->assertSame( 'Gadgets', $result['categories'][0]['name'] );

		$this->assertIsArray( $result['tags'] );
		$this->assertIsArray( $result['images'] );
		$this->assertIsArray( $result['attributes'] );
		$this->assertStringContainsString( 'post.php?post=' . $id, $result['edit_link'] );
	}

	public function test_output_shape_is_exact_and_closed(): void {
		$this->actingAs( 'administrator' );

		$id = $this->seedSimpleProduct();

		$result = wp_get_ability( 'wc-products/get-product' )->execute( array( 'id' => $id ) );

		$this->assertIsArray( $result );
		$this->assertSame( self::EXPECTED_KEYS, array_keys( $result ) );

		// No raw ~120-field product fields leak through.
		$this->assertArrayNotHasKey( 'meta_data', $result );
		$this->assertArrayNotHasKey( '_links', $result );
		$this->assertArrayNotHasKey( 'dimensions', $result );
		$this->assertArrayNotHasKey( 'description_gmt', $result );
	}

	public function test_missing_product_returns_404_not_permission_error(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'wc-products/get-product' )->execute( array( 'id' => 99999999 ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'woocommerce_rest_product_invalid_id', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] );
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_subscriber_is_denied(): void {
		$id = $this->seedSimpleProduct();
		$this->actingAs( 'subscriber' );

		$ability = wp_get_ability( 'wc-products/get-product' );

		$this->assertFalse( $ability->check_permissions( array( 'id' => $id ) ) );

		$result = $ability->execute( array( 'id' => $id ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	/**
	 * Seeds a published simple product and returns its ID.
	 *
	 * Builds the product with WooCommerce's runtime object API (WC_Product_Simple)
	 * rather than the WC_Helper_Product test factory, because the test environment
	 * mounts the distributed WooCommerce build, which ships no tests/ helper
	 * framework. Matches the seeding idiom of the sibling product tests.
	 *
	 * @param array{name?:string,description?:string,short_description?:string,category_ids?:list<int>} $args Optional product fields to set.
	 * @return int The created product ID.
	 */
	private function seedSimpleProduct( array $args = array() ): int {
		$product = new WC_Product_Simple();
		$product->set_status( 'publish' );
		$product->set_regular_price( '10.00' );

		if ( isset( $args['name'] ) ) {
			$product->set_name( $args['name'] );
		}
		if ( isset( $args['description'] ) ) {
			$product->set_description( $args['description'] );
		}
		if ( isset( $args['short_description'] ) ) {
			$product->set_short_description( $args['short_description'] );
		}
		if ( isset( $args['category_ids'] ) ) {
			$product->set_category_ids( $args['category_ids'] );
		}

		return (int) $product->save();
	}
}
