<?php
/**
 * Integration tests for the wc-products/list-product-variations ability.
 *
 * @package AbilitiesCatalogWoo\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogWoo\Tests\Integration\Abilities\Products;

use GalatanOvidiu\AbilitiesCatalogWoo\Tests\TestCase;
use WC_Product_Simple;
use WC_Product_Variable;
use WP_Error;

/**
 * Exercises wc-products/list-product-variations: shaped variation rows, the parent
 * filter, the empty-list semantics for a non-variable parent, and the cap guard.
 */
final class ListProductVariationsTest extends TestCase {

	public function test_ability_is_registered(): void {
		$ability = wp_get_ability( 'wc-products/list-product-variations' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'wc-products/list-product-variations', $ability->get_name() );
	}

	public function test_admin_lists_variations_as_shaped_rows(): void {
		$this->actingAs( 'administrator' );
		$product       = $this->seedVariationProduct();
		$variation_ids = $product->get_children();

		$result = wp_get_ability( 'wc-products/list-product-variations' )->execute(
			array( 'product_id' => $product->get_id() )
		);

		$this->assertIsArray( $result );
		$this->assertSame( array( 'product_id', 'items', 'total' ), array_keys( $result ) );
		$this->assertSame( $product->get_id(), $result['product_id'] );
		$this->assertNotEmpty( $result['items'] );
		$this->assertSame( count( $variation_ids ), $result['total'] );

		$row = $result['items'][0];
		$this->assertSame(
			array(
				'id',
				'sku',
				'price',
				'regular_price',
				'sale_price',
				'stock_status',
				'stock_quantity',
				'status',
				'attributes',
				'permalink',
				'edit_link',
			),
			array_keys( $row )
		);
		$this->assertContains( (int) $row['id'], array_map( 'intval', $variation_ids ) );
		$this->assertIsInt( $row['id'] );
		$this->assertIsString( $row['sku'] );
		$this->assertIsString( $row['price'] );
		$this->assertIsString( $row['status'] );
		$this->assertIsArray( $row['attributes'] );
		$this->assertStringContainsString( 'post.php?post=', $row['edit_link'] );
	}

	public function test_output_has_no_raw_product_fields(): void {
		$this->actingAs( 'administrator' );
		$product = $this->seedVariationProduct();

		$result = wp_get_ability( 'wc-products/list-product-variations' )->execute(
			array( 'product_id' => $product->get_id() )
		);

		$this->assertIsArray( $result );
		$row = $result['items'][0];

		// Raw wc/v3 variation fields that the shaper must strip.
		foreach ( array( 'meta_data', 'dimensions', 'downloads', '_links', 'date_created_gmt', 'parent_id', 'image' ) as $leaked ) {
			$this->assertArrayNotHasKey( $leaked, $row, $leaked . ' must not leak into a shaped row.' );
		}
	}

	public function test_per_page_narrows_results(): void {
		$this->actingAs( 'administrator' );
		$product = $this->seedVariationProduct();

		$result = wp_get_ability( 'wc-products/list-product-variations' )->execute(
			array(
				'product_id' => $product->get_id(),
				'per_page'   => 1,
			)
		);

		$this->assertIsArray( $result );
		$this->assertCount( 1, $result['items'] );
		// total reflects the full matching count from the X-WP-Total header, not the page size.
		$this->assertSame( count( $product->get_children() ), $result['total'] );
	}

	public function test_non_variable_parent_returns_empty_list_not_error(): void {
		$this->actingAs( 'administrator' );
		$simple = $this->seedSimpleProduct();

		$result = wp_get_ability( 'wc-products/list-product-variations' )->execute(
			array( 'product_id' => $simple->get_id() )
		);

		$this->assertIsArray( $result, 'A non-variable parent has no variations; the list route returns an empty set, not a 404.' );
		$this->assertSame( array(), $result['items'] );
		$this->assertSame( 0, $result['total'] );
	}

	public function test_missing_product_id_is_rejected_at_validation(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'wc-products/list-product-variations' )->execute( array() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_input', $result->get_error_code() );
	}

	public function test_subscriber_is_denied(): void {
		$product = $this->seedVariationProduct();
		$this->actingAs( 'subscriber' );

		$ability = wp_get_ability( 'wc-products/list-product-variations' );
		$this->assertNotTrue( $ability->check_permissions( array( 'product_id' => $product->get_id() ) ) );

		$result = $ability->execute( array( 'product_id' => $product->get_id() ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	/**
	 * Seeds a variable product with two variations and returns the parent object.
	 *
	 * Builds the product with WooCommerce's runtime object API (WC_Product_Variable,
	 * WC_Product_Attribute, WC_Product_Variation) rather than the WC_Helper_Product
	 * test factory, because the test environment mounts the distributed WooCommerce
	 * build, which ships no tests/ helper framework. The custom (non-taxonomy) "size"
	 * attribute is marked used-for-variations so each variation carries a
	 * name/option attribute selection. Matches the seeding idiom of the sibling
	 * product tests; the parent object is returned so callers can read its child
	 * variation IDs via get_children().
	 *
	 * @return WC_Product_Variable The created variable product, freshly loaded so its children are populated.
	 */
	private function seedVariationProduct(): WC_Product_Variable {
		$attribute = new \WC_Product_Attribute();
		$attribute->set_name( 'size' );
		$attribute->set_options( array( 'large', 'small' ) );
		$attribute->set_visible( true );
		$attribute->set_variation( true );

		$product = new WC_Product_Variable();
		$product->set_name( 'Variable Product' );
		$product->set_attributes( array( $attribute ) );
		$product_id = (int) $product->save();

		foreach ( array( 'large', 'small' ) as $option ) {
			$variation = new \WC_Product_Variation();
			$variation->set_parent_id( $product_id );
			$variation->set_attributes( array( 'size' => $option ) );
			$variation->set_regular_price( '10.00' );
			$variation->set_description( 'Variation for ' . $option );
			$variation->save();
		}

		return new WC_Product_Variable( $product_id );
	}

	/**
	 * Seeds a published simple product and returns the product object.
	 *
	 * Used as a non-variable parent for the empty-list case. Built with
	 * WC_Product_Simple for the same reason seedVariationProduct() avoids the test
	 * factory: the env runs the distributed WooCommerce build.
	 *
	 * @return WC_Product_Simple The created simple product.
	 */
	private function seedSimpleProduct(): WC_Product_Simple {
		$product = new WC_Product_Simple();
		$product->set_name( 'Simple Product' );
		$product->set_status( 'publish' );
		$product->set_regular_price( '10.00' );
		$product->save();

		return $product;
	}
}
