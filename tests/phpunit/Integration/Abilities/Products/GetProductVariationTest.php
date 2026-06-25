<?php
/**
 * Integration tests for the og-wc-products/get-product-variation ability.
 *
 * @package AbilitiesCatalogWoo\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogWoo\Tests\Integration\Abilities\Products;

use GalatanOvidiu\AbilitiesCatalogWoo\Abilities\Woo\Products\GetProductVariation;
use GalatanOvidiu\AbilitiesCatalogWoo\Tests\TestCase;
use WP_Error;

/**
 * Exercises og-wc-products/get-product-variation: the shaped single-variation read,
 * the missing/mismatched-variation 404 that must not collapse to a permission
 * error, the wrong-cap denial, and the closed output shape.
 */
final class GetProductVariationTest extends TestCase {

	/**
	 * The exact, closed output key set: the variation summary fields plus description.
	 *
	 * @var array<int,string>
	 */
	private const OUTPUT_KEYS = array(
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
		'description',
	);

	public function test_ability_is_registered(): void {
		$ability = wp_get_ability( 'og-wc-products/get-product-variation' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'og-wc-products/get-product-variation', $ability->get_name() );
	}

	public function test_admin_reads_a_single_variation(): void {
		$this->actingAs( 'administrator' );
		[ $product_id, $variation_id ] = $this->seedVariation();

		$result = wp_get_ability( 'og-wc-products/get-product-variation' )->execute(
			array(
				'product_id' => $product_id,
				'id'         => $variation_id,
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( self::OUTPUT_KEYS, array_keys( $result ) );
		$this->assertSame( $variation_id, $result['id'] );
		$this->assertIsString( $result['price'] );
		$this->assertIsString( $result['status'] );
		$this->assertIsArray( $result['attributes'] );
		$this->assertIsString( $result['description'] );
	}

	public function test_attributes_are_reduced_to_name_option_pairs(): void {
		$this->actingAs( 'administrator' );
		[ $product_id, $variation_id ] = $this->seedVariation();

		$result = wp_get_ability( 'og-wc-products/get-product-variation' )->execute(
			array(
				'product_id' => $product_id,
				'id'         => $variation_id,
			)
		);

		$this->assertNotEmpty( $result['attributes'] );
		foreach ( $result['attributes'] as $attribute ) {
			$this->assertSame( array( 'name', 'option' ), array_keys( $attribute ) );
		}
	}

	public function test_output_does_not_leak_raw_variation_fields(): void {
		$this->actingAs( 'administrator' );
		[ $product_id, $variation_id ] = $this->seedVariation();

		$result = wp_get_ability( 'og-wc-products/get-product-variation' )->execute(
			array(
				'product_id' => $product_id,
				'id'         => $variation_id,
			)
		);

		// Raw wc/v3 fields the shaper deliberately strips must not appear.
		$this->assertArrayNotHasKey( '_links', $result );
		$this->assertArrayNotHasKey( 'meta_data', $result );
		$this->assertArrayNotHasKey( 'date_modified', $result );
		$this->assertArrayNotHasKey( 'dimensions', $result );
		$this->assertArrayNotHasKey( 'parent_id', $result );
	}

	public function test_missing_variation_returns_404_not_permission_error(): void {
		$this->actingAs( 'administrator' );
		[ $product_id ] = $this->seedVariation();

		$result = wp_get_ability( 'og-wc-products/get-product-variation' )->execute(
			array(
				'product_id' => $product_id,
				'id'         => 99999999,
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'woocommerce_rest_product_variation_invalid_id', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] );
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_variation_under_wrong_parent_returns_404(): void {
		$this->actingAs( 'administrator' );
		[ , $variation_id ] = $this->seedVariation();
		$other_parent       = $this->seedSimpleProduct();

		$result = wp_get_ability( 'og-wc-products/get-product-variation' )->execute(
			array(
				'product_id' => $other_parent,
				'id'         => $variation_id,
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'woocommerce_rest_product_variation_invalid_id', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] );
	}

	public function test_subscriber_is_denied(): void {
		[ $product_id, $variation_id ] = $this->seedVariation();
		$this->actingAs( 'subscriber' );

		$this->assertFalse( ( new GetProductVariation() )->hasPermission( array() ) );

		$result = wp_get_ability( 'og-wc-products/get-product-variation' )->execute(
			array(
				'product_id' => $product_id,
				'id'         => $variation_id,
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	/**
	 * Seeds a variable product with two variations and returns the parent and a child ID.
	 *
	 * Builds the product with WooCommerce's runtime object API (WC_Product_Variable,
	 * WC_Product_Attribute, WC_Product_Variation) rather than the WC_Helper_Product
	 * test factory, because the test environment mounts the distributed WooCommerce
	 * build, which ships no tests/ helper framework. The custom (non-taxonomy) "size"
	 * attribute is marked used-for-variations so each variation carries a
	 * name/option attribute selection, and a description is set so the description
	 * field under test is non-empty.
	 *
	 * @return array{0:int,1:int} The [product_id, variation_id] pair.
	 */
	private function seedVariation(): array {
		$attribute = new \WC_Product_Attribute();
		$attribute->set_name( 'size' );
		$attribute->set_options( array( 'large', 'small' ) );
		$attribute->set_visible( true );
		$attribute->set_variation( true );

		$product = new \WC_Product_Variable();
		$product->set_name( 'Variable Product' );
		$product->set_attributes( array( $attribute ) );
		$product_id = (int) $product->save();

		$variation_ids = array();
		foreach ( array( 'large', 'small' ) as $option ) {
			$variation = new \WC_Product_Variation();
			$variation->set_parent_id( $product_id );
			$variation->set_attributes( array( 'size' => $option ) );
			$variation->set_regular_price( '10.00' );
			$variation->set_description( 'Variation for ' . $option );
			$variation_ids[] = (int) $variation->save();
		}

		return array( $product_id, (int) reset( $variation_ids ) );
	}

	/**
	 * Seeds a published simple product and returns its ID.
	 *
	 * Used as a wrong-parent product for the mismatched-variation 404 case. Built
	 * with WC_Product_Simple for the same reason seedVariation() avoids the test
	 * factory: the env runs the distributed WooCommerce build.
	 *
	 * @return int The created product ID.
	 */
	private function seedSimpleProduct(): int {
		$product = new \WC_Product_Simple();
		$product->set_name( 'Simple Product' );
		$product->set_regular_price( '5.00' );

		return (int) $product->save();
	}
}
