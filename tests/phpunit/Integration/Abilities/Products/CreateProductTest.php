<?php
/**
 * Integration tests for the og-wc-products/create-product ability.
 *
 * @package AbilitiesCatalogWoo\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogWoo\Tests\Integration\Abilities\Products;

use GalatanOvidiu\AbilitiesCatalogWoo\Tests\TestCase;
use WP_Error;
use WC_Product_Simple;

/**
 * Exercises og-wc-products/create-product: the happy-path create returning a shaped
 * product with id > 0, a forwarded field honored on the created product, the
 * route's 400 surfaced via RestError (not a permission collapse), the wrong-cap
 * denial, and the exact closed output shape.
 */
final class CreateProductTest extends TestCase {

	/**
	 * The closed key set the ability returns: the ProductListShaper summary fields.
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
	);

	public function test_ability_is_registered(): void {
		$ability = wp_get_ability( 'og-wc-products/create-product' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'og-wc-products/create-product', $ability->get_name() );
	}

	public function test_admin_creates_product(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'og-wc-products/create-product' )->execute( array( 'name' => 'Widget' ) );

		$this->assertIsArray( $result );
		$this->assertGreaterThan( 0, $result['id'] );
		$this->assertSame( 'Widget', $result['name'] );

		// The product was actually persisted: re-read it from the store.
		$product = wc_get_product( $result['id'] );
		$this->assertNotFalse( $product );
		$this->assertSame( 'Widget', $product->get_name() );
	}

	public function test_forwarded_fields_are_honored(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'og-wc-products/create-product' )->execute(
			array(
				'name'          => 'Priced Widget',
				'status'        => 'draft',
				'regular_price' => '19.99',
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'draft', $result['status'] );
		$this->assertSame( '19.99', $result['regular_price'] );
	}

	public function test_output_shape_is_exact_and_closed(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'og-wc-products/create-product' )->execute( array( 'name' => 'Shape Widget' ) );

		$this->assertIsArray( $result );
		$this->assertSame( self::EXPECTED_KEYS, array_keys( $result ) );

		// No raw ~120-field product fields leak through.
		$this->assertArrayNotHasKey( 'meta_data', $result );
		$this->assertArrayNotHasKey( '_links', $result );
		$this->assertArrayNotHasKey( 'description', $result );
		$this->assertArrayNotHasKey( 'dimensions', $result );
	}

	public function test_duplicate_sku_returns_route_400_not_permission_error(): void {
		$this->actingAs( 'administrator' );

		// Seed an existing product that already owns the SKU.
		$existing = new WC_Product_Simple();
		$existing->set_name( 'Existing' );
		$existing->set_sku( 'DUP-SKU' );
		$existing->save();

		$result = wp_get_ability( 'og-wc-products/create-product' )->execute(
			array(
				'name' => 'Clash Widget',
				'sku'  => 'DUP-SKU',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'product_invalid_sku', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] );
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_invalid_type_enum_is_rejected_at_input_validation(): void {
		$this->actingAs( 'administrator' );

		// `type` is a closed enum in the input schema, so the Abilities API rejects
		// a bad value during input validation (before execute()), not as a route
		// 400 — but the rejection must NOT collapse to a permission error.
		$result = wp_get_ability( 'og-wc-products/create-product' )->execute(
			array(
				'name' => 'Bad Type',
				'type' => 'not-a-real-type',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_input', $result->get_error_code() );
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_subscriber_is_denied(): void {
		$this->actingAs( 'subscriber' );

		$ability = wp_get_ability( 'og-wc-products/create-product' );

		$this->assertFalse( $ability->check_permissions( array( 'name' => 'Nope' ) ) );

		$result = $ability->execute( array( 'name' => 'Nope' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}
}
