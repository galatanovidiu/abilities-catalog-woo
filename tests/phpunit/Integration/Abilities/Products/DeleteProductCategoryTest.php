<?php
/**
 * Integration tests for the wc-products/delete-product-category ability.
 *
 * @package AbilitiesCatalogWoo\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogWoo\Tests\Integration\Abilities\Products;

use GalatanOvidiu\AbilitiesCatalogWoo\Tests\TestCase;
use WP_Error;

/**
 * Exercises wc-products/delete-product-category: the permanent (force=true) delete
 * that removes a seeded category, the default-product-category guard that returns
 * woocommerce_rest_cannot_delete 500, the missing-category 404 surfaced as the
 * route's specific code (not a permission collapse), the wrong-capability denial
 * that leaves the category intact, and the exact closed output shape with no
 * edit_link and no raw term leak.
 */
final class DeleteProductCategoryTest extends TestCase {

	/**
	 * Original value of the `default_product_cat` option, saved in setUp so
	 * test_default_category_cannot_be_deleted() can restore it in tearDown.
	 *
	 * @var int
	 */
	private int $original_default_product_cat = 0;

	/**
	 * The full closed key set the ability returns.
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

	public function test_ability_is_registered(): void {
		$ability = wp_get_ability( 'wc-products/delete-product-category' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'wc-products/delete-product-category', $ability->get_name() );
	}

	public function test_admin_permanently_deletes_a_category(): void {
		$this->actingAs( 'administrator' );

		$term = wp_insert_term( 'Hats', 'product_cat' );
		$this->assertIsArray( $term );
		$id = (int) $term['term_id'];

		$result = wp_get_ability( 'wc-products/delete-product-category' )->execute(
			array( 'id' => $id )
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['deleted'] );
		$this->assertSame( $id, $result['id'] );
		$this->assertSame( 'Hats', $result['name'] );
		$this->assertTrue( $result['force_used'] );
		$this->assertTrue( $result['permanent'] );

		// The category is gone from the product_cat taxonomy.
		$this->assertNull( get_term( $id, 'product_cat' ) );
	}

	public function test_output_shape_is_exact_and_closed(): void {
		$this->actingAs( 'administrator' );

		$term = wp_insert_term( 'Boots', 'product_cat' );
		$this->assertIsArray( $term );
		$id = (int) $term['term_id'];

		$result = wp_get_ability( 'wc-products/delete-product-category' )->execute(
			array( 'id' => $id )
		);

		$this->assertIsArray( $result );
		$this->assertSame( self::EXPECTED_KEYS, array_keys( $result ) );

		// The deleted object is gone, so no edit link, and no raw term fields leak.
		$this->assertArrayNotHasKey( 'edit_link', $result );
		$this->assertArrayNotHasKey( 'slug', $result );
		$this->assertArrayNotHasKey( 'count', $result );
		$this->assertArrayNotHasKey( '_links', $result );
	}

	public function test_default_category_cannot_be_deleted(): void {
		$this->actingAs( 'administrator' );

		// Save the pre-test value so we can restore it in tearDown.
		$this->original_default_product_cat = (int) get_option( 'default_product_cat', 0 );

		// The test environment does not seed a real `product_cat` term for the
		// default_product_cat option, so we create one and point the option at it.
		// This mirrors what WooCommerce does on a live install and is the only
		// way to trigger the woocommerce_rest_cannot_delete guard in
		// WC_REST_Terms_Controller::delete_item() (class-wc-rest-terms-controller.php:565).
		$existing_default_id = $this->original_default_product_cat;
		if ( $existing_default_id <= 0 || ! term_exists( $existing_default_id, 'product_cat' ) ) {
			$term_result = wp_insert_term( 'Uncategorized', 'product_cat' );
			$this->assertIsArray( $term_result, 'Failed to create Uncategorized product category.' );
			$existing_default_id = (int) $term_result['term_id'];
			update_option( 'default_product_cat', $existing_default_id );
		}

		$result = wp_get_ability( 'wc-products/delete-product-category' )->execute(
			array( 'id' => $existing_default_id )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'woocommerce_rest_cannot_delete', $result->get_error_code() );
		$this->assertSame( 500, $result->get_error_data()['status'] );
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code() );

		// The default category survives the rejected delete.
		$this->assertInstanceOf( \WP_Term::class, get_term( $existing_default_id, 'product_cat' ) );
	}

	/**
	 * Restores the default_product_cat option after the default-category test.
	 */
	public function tear_down(): void {
		if ( $this->original_default_product_cat !== (int) get_option( 'default_product_cat', 0 ) ) {
			update_option( 'default_product_cat', $this->original_default_product_cat );
		}

		parent::tear_down();
	}

	public function test_missing_category_returns_term_invalid_404_not_permission_error(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'wc-products/delete-product-category' )->execute(
			array( 'id' => 99999999 )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'woocommerce_rest_term_invalid', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] );
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_subscriber_is_denied_and_category_survives(): void {
		$this->actingAs( 'subscriber' );

		$term = wp_insert_term( 'Scarves', 'product_cat' );
		$this->assertIsArray( $term );
		$id = (int) $term['term_id'];

		$ability = wp_get_ability( 'wc-products/delete-product-category' );

		$this->assertFalse( $ability->check_permissions( array( 'id' => $id ) ) );

		$result = $ability->execute( array( 'id' => $id ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );

		// The denied delete left the category intact.
		$this->assertInstanceOf( \WP_Term::class, get_term( $id, 'product_cat' ) );
	}
}
