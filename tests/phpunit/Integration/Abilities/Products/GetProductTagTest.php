<?php
/**
 * Integration tests for the og-wc-products/get-product-tag ability.
 *
 * @package AbilitiesCatalogWoo\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogWoo\Tests\Integration\Abilities\Products;

use GalatanOvidiu\AbilitiesCatalogWoo\Tests\TestCase;
use WP_Error;

/**
 * Exercises og-wc-products/get-product-tag: the shaped single-tag record, the
 * missing-tag 404 that must not collapse to a permission error, the
 * wrong-capability denial, and the exact closed output shape (flat, no parent).
 */
final class GetProductTagTest extends TestCase {

	/**
	 * The full closed key set the ability returns for one tag.
	 *
	 * @var list<string>
	 */
	private const EXPECTED_KEYS = array(
		'id',
		'name',
		'slug',
		'count',
		'description',
	);

	public function test_ability_is_registered(): void {
		$ability = wp_get_ability( 'og-wc-products/get-product-tag' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'og-wc-products/get-product-tag', $ability->get_name() );
	}

	public function test_admin_reads_tag_detail(): void {
		$this->actingAs( 'administrator' );

		$tag = wp_insert_term(
			'Sale',
			'product_tag',
			array( 'description' => 'Items on sale.' )
		);
		$tag_id = (int) $tag['term_id'];

		$result = wp_get_ability( 'og-wc-products/get-product-tag' )->execute( array( 'id' => $tag_id ) );

		$this->assertIsArray( $result );
		$this->assertSame( $tag_id, $result['id'] );
		$this->assertSame( 'Sale', $result['name'] );
		$this->assertSame( 'sale', $result['slug'] );
		$this->assertSame( 'Items on sale.', $result['description'] );
		$this->assertIsInt( $result['count'] );
	}

	public function test_output_shape_is_exact_and_closed(): void {
		$this->actingAs( 'administrator' );

		$tag    = wp_insert_term( 'Featured', 'product_tag' );
		$tag_id = (int) $tag['term_id'];

		$result = wp_get_ability( 'og-wc-products/get-product-tag' )->execute( array( 'id' => $tag_id ) );

		$this->assertIsArray( $result );
		$this->assertSame( self::EXPECTED_KEYS, array_keys( $result ) );

		// Flat taxonomy: no parent, and no raw term fields leak through.
		$this->assertArrayNotHasKey( 'parent', $result );
		$this->assertArrayNotHasKey( 'menu_order', $result );
		$this->assertArrayNotHasKey( '_links', $result );
		$this->assertArrayNotHasKey( 'taxonomy', $result );

		$this->assertIsInt( $result['id'] );
		$this->assertIsString( $result['name'] );
		$this->assertIsString( $result['slug'] );
		$this->assertIsInt( $result['count'] );
		$this->assertIsString( $result['description'] );
	}

	public function test_missing_tag_returns_404_not_permission_error(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'og-wc-products/get-product-tag' )->execute( array( 'id' => 99999999 ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'woocommerce_rest_term_invalid', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] );
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_subscriber_is_denied(): void {
		$tag    = wp_insert_term( 'Clearance', 'product_tag' );
		$tag_id = (int) $tag['term_id'];

		$this->actingAs( 'subscriber' );

		$ability = wp_get_ability( 'og-wc-products/get-product-tag' );

		$this->assertFalse( $ability->check_permissions( array( 'id' => $tag_id ) ) );

		$result = $ability->execute( array( 'id' => $tag_id ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}
}
