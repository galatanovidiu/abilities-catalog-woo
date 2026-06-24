<?php
/**
 * Integration tests for the wc-products/create-shipping-class ability.
 *
 * @package AbilitiesCatalogWoo\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogWoo\Tests\Integration\Abilities\Products;

use GalatanOvidiu\AbilitiesCatalogWoo\Tests\TestCase;
use WP_Error;

/**
 * Exercises wc-products/create-shipping-class: the happy-path create returning a
 * shaped class with a real id, the wrong-capability denial, and the exact closed
 * output shape (flat, no parent, no raw term fields).
 */
final class CreateShippingClassTest extends TestCase {

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
		$ability = wp_get_ability( 'wc-products/create-shipping-class' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'wc-products/create-shipping-class', $ability->get_name() );
	}

	public function test_admin_creates_shipping_class(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'wc-products/create-shipping-class' )->execute( array( 'name' => 'Heavy' ) );

		$this->assertIsArray( $result );
		$this->assertGreaterThan( 0, $result['id'] );
		$this->assertSame( 'Heavy', $result['name'] );
		$this->assertSame( 'heavy', $result['slug'] );

		// The class was actually persisted on the product_shipping_class taxonomy.
		$term = get_term( $result['id'], 'product_shipping_class' );
		$this->assertNotNull( $term );
		$this->assertSame( 'Heavy', $term->name );
	}

	public function test_description_is_forwarded(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'wc-products/create-shipping-class' )->execute(
			array(
				'name'        => 'Fragile',
				'description' => 'Handle with care.',
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'Handle with care.', $result['description'] );
	}

	public function test_output_shape_is_exact_and_closed(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'wc-products/create-shipping-class' )->execute( array( 'name' => 'Oversized' ) );

		$this->assertIsArray( $result );
		$this->assertSame( self::EXPECTED_KEYS, array_keys( $result ) );

		// Flat taxonomy: no parent, and no raw term fields leak through.
		$this->assertArrayNotHasKey( 'parent', $result );
		$this->assertArrayNotHasKey( 'menu_order', $result );
		$this->assertArrayNotHasKey( '_links', $result );
		$this->assertArrayNotHasKey( 'taxonomy', $result );
		$this->assertArrayNotHasKey( 'display', $result );

		$this->assertIsInt( $result['id'] );
		$this->assertIsString( $result['name'] );
		$this->assertIsString( $result['slug'] );
		$this->assertIsInt( $result['count'] );
		$this->assertIsString( $result['description'] );
	}

	public function test_subscriber_is_denied(): void {
		$this->actingAs( 'subscriber' );

		$ability = wp_get_ability( 'wc-products/create-shipping-class' );

		$this->assertFalse( $ability->check_permissions( array( 'name' => 'Nope' ) ) );

		$result = $ability->execute( array( 'name' => 'Nope' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );

		// The denied write created nothing.
		$this->assertFalse( get_term_by( 'slug', 'nope', 'product_shipping_class' ) );
	}
}
