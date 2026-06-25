<?php
/**
 * Integration tests for the og-wc-products/update-product ability.
 *
 * @package AbilitiesCatalogWoo\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogWoo\Tests\Integration\Abilities\Products;

use GalatanOvidiu\AbilitiesCatalogWoo\Tests\TestCase;
use WP_Error;
use WC_Product_Simple;

/**
 * Exercises og-wc-products/update-product: a field change with the captured
 * previous_status, the missing-product 404 that must not collapse to a
 * permission error, the route's 400 on an invalid enum value, the
 * wrong-capability denial (with the product unchanged), and the exact closed
 * output shape.
 */
final class UpdateProductTest extends TestCase {

	/**
	 * The full closed key set the ability returns: the shaped product summary
	 * fields plus previous_status.
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
		'previous_status',
	);

	public function test_ability_is_registered(): void {
		$ability = wp_get_ability( 'og-wc-products/update-product' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'og-wc-products/update-product', $ability->get_name() );
	}

	public function test_admin_updates_status_and_captures_previous(): void {
		$this->actingAs( 'administrator' );

		$id = $this->seedSimpleProduct( 'Widget Pro' );

		$result = wp_get_ability( 'og-wc-products/update-product' )->execute(
			array(
				'id'     => $id,
				'status' => 'draft',
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( $id, $result['id'] );
		$this->assertSame( 'draft', $result['status'] );
		$this->assertSame( 'publish', $result['previous_status'] );

		// The change persisted to the live product.
		$reloaded = wc_get_product( $id );
		$this->assertSame( 'draft', $reloaded->get_status() );
	}

	public function test_admin_updates_a_scalar_field(): void {
		$this->actingAs( 'administrator' );

		$id = $this->seedSimpleProduct( 'Widget Pro' );

		$result = wp_get_ability( 'og-wc-products/update-product' )->execute(
			array(
				'id'            => $id,
				'regular_price' => '24.99',
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( '24.99', $result['regular_price'] );
		$this->assertSame( '24.99', wc_get_product( $id )->get_regular_price() );
	}

	public function test_output_shape_is_exact_and_closed(): void {
		$this->actingAs( 'administrator' );

		$id = $this->seedSimpleProduct( 'Widget Pro' );

		$result = wp_get_ability( 'og-wc-products/update-product' )->execute(
			array(
				'id'     => $id,
				'status' => 'draft',
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( self::EXPECTED_KEYS, array_keys( $result ) );

		// No raw ~120-field product fields leak through.
		$this->assertArrayNotHasKey( 'meta_data', $result );
		$this->assertArrayNotHasKey( '_links', $result );
		$this->assertArrayNotHasKey( 'description', $result );
	}

	public function test_missing_product_returns_404_not_permission_error(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'og-wc-products/update-product' )->execute(
			array(
				'id'     => 99999999,
				'status' => 'draft',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'woocommerce_rest_product_invalid_id', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] );
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_invalid_enum_value_returns_route_400_not_permission_error(): void {
		$this->actingAs( 'administrator' );

		$id = $this->seedSimpleProduct( 'Widget Pro' );

		// `type` is enum-constrained by the ability schema; send a value off that
		// enum so input validation in the Abilities wrapper rejects it before the
		// body runs (ability_invalid_input, no get_error_data status).
		$result = wp_get_ability( 'og-wc-products/update-product' )->execute(
			array(
				'id'   => $id,
				'type' => 'not-a-real-type',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_input', $result->get_error_code() );
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code() );

		// The product survived the rejected update unchanged.
		$this->assertSame( 'simple', wc_get_product( $id )->get_type() );
	}

	public function test_subscriber_is_denied_and_product_unchanged(): void {
		$id = $this->seedSimpleProduct( 'Widget Pro' );

		$this->actingAs( 'subscriber' );

		$ability = wp_get_ability( 'og-wc-products/update-product' );

		$this->assertFalse(
			$ability->check_permissions(
				array(
					'id'     => $id,
					'status' => 'draft',
				)
			)
		);

		$result = $ability->execute(
			array(
				'id'     => $id,
				'status' => 'draft',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );

		// The denied write did not change the product.
		$this->assertSame( 'publish', wc_get_product( $id )->get_status() );
	}

	/**
	 * Seeds a published simple product and returns its ID.
	 *
	 * Builds the product with WooCommerce's runtime object API (WC_Product_Simple)
	 * rather than the WC_Helper_Product test factory, because the test environment
	 * mounts the distributed WooCommerce build, which ships no tests/ helper
	 * framework. A non-empty name is always set so a later status change off
	 * publish persists.
	 *
	 * @param string $name The product name.
	 * @return int The created product ID.
	 */
	private function seedSimpleProduct( string $name ): int {
		$product = new WC_Product_Simple();
		$product->set_name( $name );
		$product->set_status( 'publish' );
		$product->set_regular_price( '10.00' );

		return (int) $product->save();
	}
}
