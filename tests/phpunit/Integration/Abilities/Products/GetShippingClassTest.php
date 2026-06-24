<?php
/**
 * Integration tests for the wc-products/get-shipping-class ability.
 *
 * @package AbilitiesCatalogWoo\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogWoo\Tests\Integration\Abilities\Products;

use GalatanOvidiu\AbilitiesCatalogWoo\Tests\TestCase;
use WP_Error;

/**
 * Exercises wc-products/get-shipping-class: the shaped single shipping-class
 * record, the missing-term 404 that must not collapse to a permission error, the
 * wrong-capability denial, and the exact closed output shape (flat, no parent).
 */
final class GetShippingClassTest extends TestCase {

	/**
	 * The full closed key set the ability returns for one shipping class.
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
		$ability = wp_get_ability( 'wc-products/get-shipping-class' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'wc-products/get-shipping-class', $ability->get_name() );
	}

	public function test_admin_reads_shipping_class_detail(): void {
		$this->actingAs( 'administrator' );

		$term = wp_insert_term(
			'Heavy',
			'product_shipping_class',
			array( 'description' => 'Oversized, heavy items.' )
		);
		$term_id = (int) $term['term_id'];

		$result = wp_get_ability( 'wc-products/get-shipping-class' )->execute( array( 'id' => $term_id ) );

		$this->assertIsArray( $result );
		$this->assertSame( $term_id, $result['id'] );
		$this->assertSame( 'Heavy', $result['name'] );
		$this->assertSame( 'heavy', $result['slug'] );
		$this->assertSame( 'Oversized, heavy items.', $result['description'] );
		$this->assertIsInt( $result['count'] );
	}

	public function test_output_shape_is_exact_and_closed(): void {
		$this->actingAs( 'administrator' );

		$term    = wp_insert_term( 'Fragile', 'product_shipping_class' );
		$term_id = (int) $term['term_id'];

		$result = wp_get_ability( 'wc-products/get-shipping-class' )->execute( array( 'id' => $term_id ) );

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

	public function test_missing_shipping_class_returns_404_not_permission_error(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'wc-products/get-shipping-class' )->execute( array( 'id' => 99999999 ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'woocommerce_rest_term_invalid', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] );
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_subscriber_is_denied(): void {
		$term    = wp_insert_term( 'Bulky', 'product_shipping_class' );
		$term_id = (int) $term['term_id'];

		$this->actingAs( 'subscriber' );

		$ability = wp_get_ability( 'wc-products/get-shipping-class' );

		$this->assertFalse( $ability->check_permissions( array( 'id' => $term_id ) ) );

		$result = $ability->execute( array( 'id' => $term_id ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}
}
