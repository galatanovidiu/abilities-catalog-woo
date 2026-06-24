<?php
/**
 * Integration tests for the wc-products/generate-product-variations ability.
 *
 * @package AbilitiesCatalogWoo\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogWoo\Tests\Integration\Abilities\Products;

use GalatanOvidiu\AbilitiesCatalogWoo\Tests\TestCase;
use WC_Product_Attribute;
use WC_Product_Simple;
use WC_Product_Variable;
use WP_Error;

/**
 * Exercises wc-products/generate-product-variations: the bulk cartesian-product
 * generation of missing variation combinations, the shaped result rows, the
 * created_count signal, the non-variable parent error, input validation, and the
 * cap guard.
 */
final class GenerateProductVariationsTest extends TestCase {

	public function test_ability_is_registered(): void {
		$ability = wp_get_ability( 'wc-products/generate-product-variations' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'wc-products/generate-product-variations', $ability->get_name() );
	}

	public function test_admin_generates_missing_variations(): void {
		$this->actingAs( 'administrator' );
		$product = $this->seedVariableProductWithoutVariations();

		$result = wp_get_ability( 'wc-products/generate-product-variations' )->execute(
			array( 'product_id' => $product->get_id() )
		);

		$this->assertIsArray( $result );
		$this->assertSame( array( 'product_id', 'items', 'created_count' ), array_keys( $result ) );
		$this->assertSame( $product->get_id(), $result['product_id'] );
		// size {large, small} x color {red, blue} = 4 missing combinations.
		$this->assertSame( 4, $result['created_count'] );
		$this->assertNotEmpty( $result['items'] );
		$this->assertCount( 4, $result['items'] );

		// The parent now actually owns the generated variations.
		$reloaded = new WC_Product_Variable( $product->get_id() );
		$this->assertCount( 4, $reloaded->get_children() );
	}

	public function test_result_rows_are_shaped(): void {
		$this->actingAs( 'administrator' );
		$product = $this->seedVariableProductWithoutVariations();

		$result = wp_get_ability( 'wc-products/generate-product-variations' )->execute(
			array( 'product_id' => $product->get_id() )
		);

		$this->assertIsArray( $result );
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
		$this->assertIsInt( $row['id'] );
		$this->assertGreaterThan( 0, $row['id'] );
		$this->assertIsString( $row['status'] );
		$this->assertIsArray( $row['attributes'] );
		$this->assertStringContainsString( 'post.php?post=', $row['edit_link'] );

		// Raw wc/v3 variation fields the shaper must strip.
		foreach ( array( 'meta_data', 'dimensions', 'downloads', '_links', 'date_created_gmt', 'parent_id', 'image' ) as $leaked ) {
			$this->assertArrayNotHasKey( $leaked, $row, $leaked . ' must not leak into a shaped row.' );
		}
	}

	public function test_non_variable_parent_generates_nothing(): void {
		$this->actingAs( 'administrator' );
		$simple = $this->seedSimpleProduct();

		$result = wp_get_ability( 'wc-products/generate-product-variations' )->execute(
			array( 'product_id' => $simple->get_id() )
		);

		// A simple product has post_type 'product', so the route's 404 branch
		// (which only fires for a non-product id) is skipped. The simple product
		// has no variation attributes, so generate creates nothing and the route
		// returns HTTP 200 {count:0}. The ability shapes that into a plain array,
		// not a WP_Error. The genuine route 404 is covered by
		// test_missing_product_returns_route_404.
		$this->assertIsArray( $result );
		$this->assertSame( $simple->get_id(), $result['product_id'] );
		$this->assertSame( 0, $result['created_count'] );
		$this->assertSame( array(), $result['items'] );
	}

	public function test_missing_product_returns_route_404(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'wc-products/generate-product-variations' )->execute(
			array( 'product_id' => 99999999 )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'woocommerce_rest_product_invalid_id', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] );
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_missing_product_id_is_rejected_at_validation(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'wc-products/generate-product-variations' )->execute( array() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_input', $result->get_error_code() );
	}

	public function test_subscriber_is_denied(): void {
		$product = $this->seedVariableProductWithoutVariations();
		$this->actingAs( 'subscriber' );

		$ability = wp_get_ability( 'wc-products/generate-product-variations' );
		$this->assertNotTrue( $ability->check_permissions( array( 'product_id' => $product->get_id() ) ) );

		$result = $ability->execute( array( 'product_id' => $product->get_id() ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );

		// The denied write created nothing.
		$reloaded = new WC_Product_Variable( $product->get_id() );
		$this->assertSame( array(), $reloaded->get_children() );
	}

	/**
	 * Seeds a variable product with two used-for-variations attributes but NO
	 * variations yet, so generate has missing combinations to fill in.
	 *
	 * Built with WooCommerce's runtime object API (WC_Product_Variable,
	 * WC_Product_Attribute) rather than the WC_Helper_Product test factory, because
	 * the test environment mounts the distributed WooCommerce build, which ships no
	 * tests/ helper framework. The two custom (non-taxonomy) attributes "size" and
	 * "color" are marked used-for-variations, giving a 2x2 cartesian product of four
	 * combinations none of which has a variation yet.
	 *
	 * @return WC_Product_Variable The created variable product, freshly loaded.
	 */
	private function seedVariableProductWithoutVariations(): WC_Product_Variable {
		$size = new WC_Product_Attribute();
		$size->set_name( 'size' );
		$size->set_options( array( 'large', 'small' ) );
		$size->set_visible( true );
		$size->set_variation( true );

		$color = new WC_Product_Attribute();
		$color->set_name( 'color' );
		$color->set_options( array( 'red', 'blue' ) );
		$color->set_visible( true );
		$color->set_variation( true );

		$product = new WC_Product_Variable();
		$product->set_name( 'Variable Product' );
		$product->set_attributes( array( $size, $color ) );
		$product_id = (int) $product->save();

		return new WC_Product_Variable( $product_id );
	}

	/**
	 * Seeds a published simple product (a non-variable parent for the 404 case).
	 *
	 * Built with WC_Product_Simple for the same reason the variable seed avoids the
	 * test factory: the env runs the distributed WooCommerce build.
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
