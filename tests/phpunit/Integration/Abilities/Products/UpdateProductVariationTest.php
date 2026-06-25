<?php
/**
 * Integration tests for the og-wc-products/update-product-variation ability.
 *
 * @package AbilitiesCatalogWoo\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogWoo\Tests\Integration\Abilities\Products;

use GalatanOvidiu\AbilitiesCatalogWoo\Abilities\Woo\Products\UpdateProductVariation;
use GalatanOvidiu\AbilitiesCatalogWoo\Tests\TestCase;
use WP_Error;

/**
 * Exercises og-wc-products/update-product-variation: the happy-path field change on a
 * seeded variation, the missing-variation 404 that must not collapse to a
 * permission error, the wrong-cap denial, and the closed output shape (no raw
 * variation fields leak).
 */
final class UpdateProductVariationTest extends TestCase {

	/**
	 * The exact, closed output key set: the variation summary fields plus product_id.
	 *
	 * Order matches ProductWriteRequest::shapeVariationResult() — the
	 * ProductListShaper::variationSummary() fields with product_id appended last.
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
		'product_id',
	);

	public function test_ability_is_registered(): void {
		$ability = wp_get_ability( 'og-wc-products/update-product-variation' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'og-wc-products/update-product-variation', $ability->get_name() );
	}

	public function test_admin_updates_a_variation_field(): void {
		$this->actingAs( 'administrator' );
		[ $product_id, $variation_id ] = $this->seedVariation();

		$result = wp_get_ability( 'og-wc-products/update-product-variation' )->execute(
			array(
				'product_id'    => $product_id,
				'id'            => $variation_id,
				'regular_price' => '42.00',
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( $variation_id, $result['id'] );
		$this->assertSame( $product_id, $result['product_id'] );
		$this->assertSame( '42.00', $result['regular_price'] );

		// Side-effect read-back through the WooCommerce runtime object.
		$variation = wc_get_product( $variation_id );
		$this->assertSame( '42.00', $variation->get_regular_price() );
	}

	public function test_only_sent_fields_change(): void {
		$this->actingAs( 'administrator' );
		[ $product_id, $variation_id ] = $this->seedVariation();

		$result = wp_get_ability( 'og-wc-products/update-product-variation' )->execute(
			array(
				'product_id' => $product_id,
				'id'         => $variation_id,
				'sku'        => 'SKU-UPDATED',
			)
		);

		$this->assertSame( 'SKU-UPDATED', $result['sku'] );
		// The price seeded at 10.00 was not sent, so it is left untouched.
		$this->assertSame( '10.00', $result['regular_price'] );
	}

	public function test_output_shape_is_closed(): void {
		$this->actingAs( 'administrator' );
		[ $product_id, $variation_id ] = $this->seedVariation();

		$result = wp_get_ability( 'og-wc-products/update-product-variation' )->execute(
			array(
				'product_id' => $product_id,
				'id'         => $variation_id,
				'status'     => 'private',
			)
		);

		$this->assertSame( self::OUTPUT_KEYS, array_keys( $result ) );

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

		$result = wp_get_ability( 'og-wc-products/update-product-variation' )->execute(
			array(
				'product_id'    => $product_id,
				'id'            => 99999999,
				'regular_price' => '1.00',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'woocommerce_rest_product_variation_invalid_id', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] );
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_subscriber_is_denied(): void {
		[ $product_id, $variation_id ] = $this->seedVariation();
		$this->actingAs( 'subscriber' );

		$this->assertFalse( ( new UpdateProductVariation() )->hasPermission( array() ) );

		$result = wp_get_ability( 'og-wc-products/update-product-variation' )->execute(
			array(
				'product_id'    => $product_id,
				'id'            => $variation_id,
				'regular_price' => '99.00',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );

		// The variation survived the denied write unchanged.
		$variation = wc_get_product( $variation_id );
		$this->assertSame( '10.00', $variation->get_regular_price() );
	}

	/**
	 * Seeds a variable product with two variations and returns the parent and a child ID.
	 *
	 * Builds the product with WooCommerce's runtime object API (WC_Product_Variable,
	 * WC_Product_Attribute, WC_Product_Variation) rather than the WC_Helper_Product
	 * test factory, because the test environment mounts the distributed WooCommerce
	 * build, which ships no tests/ helper framework. The custom (non-taxonomy) "size"
	 * attribute is marked used-for-variations so each variation carries a
	 * name/option attribute selection, and each variation is seeded at 10.00 so the
	 * "only sent fields change" assertion has a known baseline.
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
			$variation_ids[] = (int) $variation->save();
		}

		return array( $product_id, (int) reset( $variation_ids ) );
	}
}
