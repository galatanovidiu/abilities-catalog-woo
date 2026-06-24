<?php
/**
 * Integration tests for the wc-products/delete-shipping-class ability.
 *
 * @package AbilitiesCatalogWoo\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogWoo\Tests\Integration\Abilities\Products;

use GalatanOvidiu\AbilitiesCatalogWoo\Tests\TestCase;
use WP_Error;

/**
 * Exercises wc-products/delete-shipping-class: the permanent (force) delete that
 * removes the term, the missing-class 404 that must not collapse to a permission
 * error, the wrong-capability denial that leaves the class intact, and the exact
 * closed output shape (no edit_link, no raw term fields).
 */
final class DeleteShippingClassTest extends TestCase {

	/**
	 * The full closed key set the ability returns for one deleted shipping class.
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
		$ability = wp_get_ability( 'wc-products/delete-shipping-class' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'wc-products/delete-shipping-class', $ability->get_name() );
	}

	public function test_admin_deletes_shipping_class_permanently(): void {
		$this->actingAs( 'administrator' );

		$term    = wp_insert_term( 'Heavy', 'product_shipping_class' );
		$term_id = (int) $term['term_id'];

		$result = wp_get_ability( 'wc-products/delete-shipping-class' )->execute( array( 'id' => $term_id ) );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['deleted'] );
		$this->assertSame( $term_id, $result['id'] );
		$this->assertSame( 'Heavy', $result['name'] );
		$this->assertTrue( $result['force_used'] );
		$this->assertTrue( $result['permanent'] );

		// The term is gone from the taxonomy.
		$this->assertNull( get_term( $term_id, 'product_shipping_class' ) );
		$this->assertFalse( get_term_by( 'slug', 'heavy', 'product_shipping_class' ) );
	}

	public function test_output_shape_is_exact_and_closed(): void {
		$this->actingAs( 'administrator' );

		$term    = wp_insert_term( 'Fragile', 'product_shipping_class' );
		$term_id = (int) $term['term_id'];

		$result = wp_get_ability( 'wc-products/delete-shipping-class' )->execute( array( 'id' => $term_id ) );

		$this->assertIsArray( $result );
		$this->assertSame( self::EXPECTED_KEYS, array_keys( $result ) );

		// No dead-end edit link, and no raw term fields leak through.
		$this->assertArrayNotHasKey( 'edit_link', $result );
		$this->assertArrayNotHasKey( 'slug', $result );
		$this->assertArrayNotHasKey( 'count', $result );
		$this->assertArrayNotHasKey( '_links', $result );
		$this->assertArrayNotHasKey( 'taxonomy', $result );

		$this->assertIsBool( $result['deleted'] );
		$this->assertIsInt( $result['id'] );
		$this->assertIsString( $result['name'] );
		$this->assertIsBool( $result['force_used'] );
		$this->assertIsBool( $result['permanent'] );
	}

	public function test_missing_shipping_class_returns_404_not_permission_error(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'wc-products/delete-shipping-class' )->execute( array( 'id' => 99999999 ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'woocommerce_rest_term_invalid', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] );
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_subscriber_is_denied_and_class_survives(): void {
		$term    = wp_insert_term( 'Bulky', 'product_shipping_class' );
		$term_id = (int) $term['term_id'];

		$this->actingAs( 'subscriber' );

		$ability = wp_get_ability( 'wc-products/delete-shipping-class' );

		$this->assertFalse( $ability->check_permissions( array( 'id' => $term_id ) ) );

		$result = $ability->execute( array( 'id' => $term_id ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );

		// The denied delete left the class intact.
		$this->assertNotNull( get_term( $term_id, 'product_shipping_class' ) );
	}
}
