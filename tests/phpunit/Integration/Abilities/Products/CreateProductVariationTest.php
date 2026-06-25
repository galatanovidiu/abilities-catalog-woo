<?php
/**
 * Integration tests for the og-wc-products/create-product-variation ability.
 *
 * @package AbilitiesCatalogWoo\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogWoo\Tests\Integration\Abilities\Products;

use GalatanOvidiu\AbilitiesCatalogWoo\Abilities\Woo\Products\CreateProductVariation;
use GalatanOvidiu\AbilitiesCatalogWoo\Tests\TestCase;
use WP_Error;

/**
 * Exercises og-wc-products/create-product-variation: the happy-path create returning a
 * shaped variation row, a forwarded field honored in the result, the missing /
 * non-variable parent 404 that must not collapse to a permission error, the
 * wrong-cap denial, and the closed output shape (no raw variation fields leak).
 */
final class CreateProductVariationTest extends TestCase {

	/**
	 * The exact, closed output key set: the variation summary fields plus product_id.
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
		$ability = wp_get_ability( 'og-wc-products/create-product-variation' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'og-wc-products/create-product-variation', $ability->get_name() );
	}

	public function test_admin_creates_a_variation(): void {
		$this->actingAs( 'administrator' );
		$product_id = $this->seedVariableProduct();

		$result = wp_get_ability( 'og-wc-products/create-product-variation' )->execute(
			array(
				'product_id'    => $product_id,
				'regular_price' => '12.50',
				'attributes'    => array(
					array(
						'name'   => 'size',
						'option' => 'medium',
					),
				),
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( self::OUTPUT_KEYS, array_keys( $result ) );
		$this->assertGreaterThan( 0, $result['id'] );
		$this->assertSame( $product_id, $result['product_id'] );

		// The created variation is a real, persisted child of the parent.
		$variation = wc_get_product( $result['id'] );
		$this->assertInstanceOf( \WC_Product_Variation::class, $variation );
		$this->assertSame( $product_id, $variation->get_parent_id() );
	}

	public function test_forwarded_field_is_honored_in_the_result(): void {
		$this->actingAs( 'administrator' );
		$product_id = $this->seedVariableProduct();

		$result = wp_get_ability( 'og-wc-products/create-product-variation' )->execute(
			array(
				'product_id'    => $product_id,
				'regular_price' => '33.00',
				'attributes'    => array(
					array(
						'name'   => 'size',
						'option' => 'medium',
					),
				),
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( '33.00', $result['regular_price'] );
	}

	public function test_output_does_not_leak_raw_variation_fields(): void {
		$this->actingAs( 'administrator' );
		$product_id = $this->seedVariableProduct();

		$result = wp_get_ability( 'og-wc-products/create-product-variation' )->execute(
			array(
				'product_id'    => $product_id,
				'regular_price' => '9.00',
			)
		);

		$this->assertIsArray( $result );
		// Raw wc/v3 fields the shaper deliberately strips must not appear.
		$this->assertArrayNotHasKey( '_links', $result );
		$this->assertArrayNotHasKey( 'meta_data', $result );
		$this->assertArrayNotHasKey( 'date_modified', $result );
		$this->assertArrayNotHasKey( 'dimensions', $result );
		$this->assertArrayNotHasKey( 'parent_id', $result );
		$this->assertArrayNotHasKey( 'description', $result );
	}

	public function test_missing_parent_with_attributes_returns_404_not_permission_error(): void {
		$this->actingAs( 'administrator' );

		// When attributes are supplied the route resolves the parent and rejects a
		// missing one with a specific 404, rather than masking it as a permission
		// failure. (Without attributes the create route does not resolve the parent.)
		$result = wp_get_ability( 'og-wc-products/create-product-variation' )->execute(
			array(
				'product_id' => 99999999,
				'attributes' => array(
					array(
						'name'   => 'size',
						'option' => 'medium',
					),
				),
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'woocommerce_rest_product_variation_invalid_parent', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] );
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_missing_required_product_id_is_rejected_as_invalid_input(): void {
		$this->actingAs( 'administrator' );

		// product_id is the required route segment; the Abilities API validates the
		// input against the schema before execute() runs, so its absence surfaces as
		// the generic schema-validation error, not a permission collapse.
		$result = wp_get_ability( 'og-wc-products/create-product-variation' )->execute(
			array( 'regular_price' => '9.00' )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_input', $result->get_error_code() );
	}

	public function test_subscriber_is_denied(): void {
		$product_id = $this->seedVariableProduct();
		$this->actingAs( 'subscriber' );

		$this->assertFalse( ( new CreateProductVariation() )->hasPermission( array() ) );

		$result = wp_get_ability( 'og-wc-products/create-product-variation' )->execute(
			array(
				'product_id'    => $product_id,
				'regular_price' => '9.00',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );

		// The denied write created nothing: the parent has no variations.
		$parent = wc_get_product( $product_id );
		$this->assertInstanceOf( \WC_Product_Variable::class, $parent );
		$this->assertEmpty( $parent->get_children() );
	}

	/**
	 * Seeds an empty variable product (a "size" used-for-variations attribute, no
	 * children) and returns its ID.
	 *
	 * Built with WooCommerce's runtime object API (WC_Product_Variable +
	 * WC_Product_Attribute) rather than the WC_Helper_Product test factory, because
	 * the test environment mounts the distributed WooCommerce build, which ships no
	 * tests/ helper framework. No variations are created here so the ability under
	 * test does the creating.
	 *
	 * @return int The created parent product ID.
	 */
	private function seedVariableProduct(): int {
		$attribute = new \WC_Product_Attribute();
		$attribute->set_name( 'size' );
		$attribute->set_options( array( 'small', 'medium', 'large' ) );
		$attribute->set_visible( true );
		$attribute->set_variation( true );

		$product = new \WC_Product_Variable();
		$product->set_name( 'Variable Product' );
		$product->set_status( 'publish' );
		$product->set_attributes( array( $attribute ) );

		return (int) $product->save();
	}
}
