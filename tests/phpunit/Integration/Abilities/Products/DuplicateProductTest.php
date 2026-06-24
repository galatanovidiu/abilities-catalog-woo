<?php
/**
 * Integration tests for the `wc-products/duplicate-product` ability.
 *
 * @package AbilitiesCatalogWoo\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogWoo\Tests\Integration\Abilities\Products;

use GalatanOvidiu\AbilitiesCatalogWoo\Tests\TestCase;
use WC_Product_Simple;
use WP_Error;

/**
 * @covers \GalatanOvidiu\AbilitiesCatalogWoo\Abilities\Woo\Products\DuplicateProduct
 */
final class DuplicateProductTest extends TestCase {

	private const ABILITY = 'wc-products/duplicate-product';

	/**
	 * The exact keys the shaped duplicate result exposes.
	 *
	 * Asserting against this fixed set proves the raw ~120-field product body is
	 * never leaked: only these projected fields reach the consumer. Matches
	 * {@see \GalatanOvidiu\AbilitiesCatalogWoo\Support\ProductListShaper::summary()}.
	 *
	 * @var list<string>
	 */
	private const ROW_KEYS = array(
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
	);

	public function test_registered(): void {
		$ability = wp_get_ability( self::ABILITY );

		$this->assertNotNull( $ability );
		$this->assertSame( self::ABILITY, $ability->get_name() );
	}

	public function test_happy_path_creates_a_new_draft_copy(): void {
		$this->actingAs( 'administrator' );
		$source = $this->seedSimpleProduct();

		$result = wp_get_ability( self::ABILITY )->execute( array( 'id' => $source->get_id() ) );

		$this->assertIsArray( $result );
		$this->assertIsInt( $result['id'] );
		$this->assertGreaterThan( 0, $result['id'] );
		$this->assertNotSame( $source->get_id(), $result['id'] );
		$this->assertSame( 'draft', $result['status'] );
	}

	public function test_missing_source_returns_specific_404(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( self::ABILITY )->execute( array( 'id' => 99999999 ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'woocommerce_rest_product_invalid_id', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] );
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_output_shape_has_no_raw_product_fields(): void {
		$this->actingAs( 'administrator' );
		$source = $this->seedSimpleProduct();

		$result = wp_get_ability( self::ABILITY )->execute( array( 'id' => $source->get_id() ) );

		$this->assertSame( self::ROW_KEYS, array_keys( $result ) );
		$this->assertArrayNotHasKey( 'meta_data', $result );
		$this->assertArrayNotHasKey( 'images', $result );
		$this->assertArrayNotHasKey( 'description', $result );
		$this->assertArrayNotHasKey( '_links', $result );
	}

	public function test_wrong_capability_is_denied(): void {
		$source = $this->seedSimpleProduct();
		$this->actingAs( 'subscriber' );

		$result = wp_get_ability( self::ABILITY )->execute( array( 'id' => $source->get_id() ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );

		// The source product must survive the denied duplicate unchanged.
		$this->assertInstanceOf( WC_Product_Simple::class, wc_get_product( $source->get_id() ) );
	}

	/**
	 * Seeds a published simple product and returns the product object.
	 *
	 * Builds the product with WooCommerce's runtime object API (WC_Product_Simple)
	 * rather than the WC_Helper_Product test factory, because the test environment
	 * mounts the distributed WooCommerce build, which ships no tests/ helper
	 * framework. Matches the seeding idiom of the sibling product tests.
	 *
	 * @return WC_Product_Simple The created simple product.
	 */
	private function seedSimpleProduct(): WC_Product_Simple {
		$product = new WC_Product_Simple();
		$product->set_name( 'Seeded Product' );
		$product->set_status( 'publish' );
		$product->set_regular_price( '10.00' );
		$product->save();

		return $product;
	}
}
