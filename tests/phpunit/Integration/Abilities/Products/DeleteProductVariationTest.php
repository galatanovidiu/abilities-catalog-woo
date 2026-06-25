<?php
/**
 * Integration tests for the og-wc-products/delete-product-variation ability.
 *
 * @package AbilitiesCatalogWoo\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogWoo\Tests\Integration\Abilities\Products;

use GalatanOvidiu\AbilitiesCatalogWoo\Tests\TestCase;
use WP_Error;
use WC_Product_Attribute;
use WC_Product_Variable;
use WC_Product_Variation;

/**
 * Exercises og-wc-products/delete-product-variation: the permanent force=true delete
 * (variation gone, parent product intact), the recoverable force=false trash (or
 * the 501 when Trash is disabled), the route's specific 404 for a missing /
 * parent-mismatched variation surfaced via RestError (not a permission collapse),
 * the missing-required-product_id schema rejection, the wrong-cap denial leaving
 * the variation intact, and the exact closed output shape.
 */
final class DeleteProductVariationTest extends TestCase {

	/**
	 * The closed key set the ability returns.
	 *
	 * @var list<string>
	 */
	private const EXPECTED_KEYS = array(
		'deleted',
		'id',
		'name',
		'force_used',
		'permanent',
	);

	/**
	 * Seeds a variable product with one child variation and returns both ids.
	 *
	 * Built with WooCommerce's runtime object API (WC_Product_Variable +
	 * WC_Product_Attribute + WC_Product_Variation) rather than the WC_Helper_Product
	 * test factory, because the test environment mounts the distributed WooCommerce
	 * build, which ships no tests/ helper framework.
	 *
	 * @return array{product_id: int, variation_id: int} The parent and child ids.
	 */
	private function seedVariation(): array {
		$attribute = new WC_Product_Attribute();
		$attribute->set_name( 'size' );
		$attribute->set_options( array( 'small', 'medium', 'large' ) );
		$attribute->set_visible( true );
		$attribute->set_variation( true );

		$product = new WC_Product_Variable();
		$product->set_name( 'Variable Product' );
		$product->set_status( 'publish' );
		$product->set_attributes( array( $attribute ) );
		$product_id = (int) $product->save();

		$variation = new WC_Product_Variation();
		$variation->set_parent_id( $product_id );
		$variation->set_attributes( array( 'size' => 'medium' ) );
		$variation->set_regular_price( '10.00' );
		$variation_id = (int) $variation->save();

		return array(
			'product_id'   => $product_id,
			'variation_id' => $variation_id,
		);
	}

	public function test_ability_is_registered(): void {
		$ability = wp_get_ability( 'og-wc-products/delete-product-variation' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'og-wc-products/delete-product-variation', $ability->get_name() );
	}

	public function test_force_true_permanently_deletes_variation(): void {
		$this->actingAs( 'administrator' );

		$ids = $this->seedVariation();

		$result = wp_get_ability( 'og-wc-products/delete-product-variation' )->execute(
			array(
				'product_id' => $ids['product_id'],
				'id'         => $ids['variation_id'],
				'force'      => true,
			)
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['deleted'] );
		$this->assertTrue( $result['permanent'] );
		$this->assertTrue( $result['force_used'] );
		$this->assertSame( $ids['variation_id'], $result['id'] );
		$this->assertNotSame( '', $result['name'] );

		// The variation is gone, but the parent product survives.
		// wc_get_product() can return a hollow WC_Product_Variation object even
		// after a force-delete (the factory creates the object from a DB product-
		// type query before it checks the post status).  Use clean_post_cache() +
		// get_post() — the WP-layer source of truth — to assert the post is gone,
		// then verify the parent is still readable via wc_get_product().
		clean_post_cache( $ids['variation_id'] );
		$this->assertNull( get_post( $ids['variation_id'] ) );
		$this->assertNotFalse( wc_get_product( $ids['product_id'] ) );
	}

	public function test_force_false_trashes_or_501_when_trash_disabled(): void {
		$this->actingAs( 'administrator' );

		$ids = $this->seedVariation();

		$result = wp_get_ability( 'og-wc-products/delete-product-variation' )->execute(
			array(
				'product_id' => $ids['product_id'],
				'id'         => $ids['variation_id'],
				'force'      => false,
			)
		);

		if ( EMPTY_TRASH_DAYS > 0 ) {
			// Trash is enabled: the variation is moved to the Trash, recoverable.
			$this->assertIsArray( $result );
			$this->assertTrue( $result['deleted'] );
			$this->assertFalse( $result['permanent'] );
			$this->assertFalse( $result['force_used'] );
			$this->assertSame( 'trash', get_post_status( $ids['variation_id'] ) );
		} else {
			// Trash is disabled: the route rejects a force=false delete with 501.
			$this->assertInstanceOf( WP_Error::class, $result );
			$this->assertSame( 'woocommerce_rest_trash_not_supported', $result->get_error_code() );
			$this->assertSame( 501, $result->get_error_data()['status'] );
			$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code() );
		}
	}

	public function test_missing_variation_returns_route_404_not_permission_error(): void {
		$this->actingAs( 'administrator' );

		$ids = $this->seedVariation();

		$result = wp_get_ability( 'og-wc-products/delete-product-variation' )->execute(
			array(
				'product_id' => $ids['product_id'],
				'id'         => 99999999,
				'force'      => true,
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'woocommerce_rest_product_variation_invalid_id', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] );
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_parent_mismatch_returns_route_404(): void {
		$this->actingAs( 'administrator' );

		$ids   = $this->seedVariation();
		$other = $this->seedVariation();

		// A real variation, but referenced under the wrong parent: the route's
		// check_variation_parent() rejects it with the same 404 as a missing id.
		$result = wp_get_ability( 'og-wc-products/delete-product-variation' )->execute(
			array(
				'product_id' => $other['product_id'],
				'id'         => $ids['variation_id'],
				'force'      => true,
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'woocommerce_rest_product_variation_invalid_id', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] );

		// The variation survived the mismatched request.
		$this->assertNotFalse( wc_get_product( $ids['variation_id'] ) );
	}

	public function test_missing_required_product_id_is_rejected_as_invalid_input(): void {
		$this->actingAs( 'administrator' );

		// product_id is a required route segment; the Abilities API validates the
		// input against the schema before execute() runs, so its absence surfaces as
		// the generic schema-validation error, not a permission collapse.
		$result = wp_get_ability( 'og-wc-products/delete-product-variation' )->execute(
			array(
				'id'    => 123,
				'force' => true,
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_input', $result->get_error_code() );
	}

	public function test_subscriber_is_denied_and_variation_survives(): void {
		$ids = $this->seedVariation();

		$this->actingAs( 'subscriber' );

		$ability = wp_get_ability( 'og-wc-products/delete-product-variation' );

		$this->assertFalse(
			$ability->check_permissions(
				array(
					'product_id' => $ids['product_id'],
					'id'         => $ids['variation_id'],
				)
			)
		);

		$result = $ability->execute(
			array(
				'product_id' => $ids['product_id'],
				'id'         => $ids['variation_id'],
				'force'      => true,
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );

		// The variation survived the denied delete unchanged.
		$this->assertNotFalse( wc_get_product( $ids['variation_id'] ) );
	}

	public function test_output_shape_is_exact_and_closed(): void {
		$this->actingAs( 'administrator' );

		$ids = $this->seedVariation();

		$result = wp_get_ability( 'og-wc-products/delete-product-variation' )->execute(
			array(
				'product_id' => $ids['product_id'],
				'id'         => $ids['variation_id'],
				'force'      => true,
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( self::EXPECTED_KEYS, array_keys( $result ) );

		// No edit_link to a gone variation, and no raw variation body leaks through.
		$this->assertArrayNotHasKey( 'edit_link', $result );
		$this->assertArrayNotHasKey( 'meta_data', $result );
		$this->assertArrayNotHasKey( '_links', $result );
	}
}
