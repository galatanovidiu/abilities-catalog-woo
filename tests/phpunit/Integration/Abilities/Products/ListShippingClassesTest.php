<?php
/**
 * Integration tests for the `wc-products/list-shipping-classes` ability.
 *
 * @package AbilitiesCatalogWoo\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogWoo\Tests\Integration\Abilities\Products;

use GalatanOvidiu\AbilitiesCatalogWoo\Tests\TestCase;
use WP_Error;

/**
 * @covers \GalatanOvidiu\AbilitiesCatalogWoo\Abilities\Woo\Products\ListShippingClasses
 */
final class ListShippingClassesTest extends TestCase {

	private const ABILITY = 'wc-products/list-shipping-classes';

	/**
	 * The exact keys a shaped shipping-class summary row exposes.
	 *
	 * Asserting against this fixed set proves the raw term body is never leaked:
	 * only these projected fields reach the consumer. `product_shipping_class` is a
	 * flat taxonomy, so there is no `parent` field; no `menu_order`, `taxonomy`, or
	 * other raw field leaks either.
	 *
	 * @var list<string>
	 */
	private const ROW_KEYS = array(
		'id',
		'name',
		'slug',
		'count',
		'description',
	);

	public function test_registered(): void {
		$ability = wp_get_ability( self::ABILITY );

		$this->assertNotNull( $ability );
		$this->assertSame( self::ABILITY, $ability->get_name() );
	}

	public function test_happy_path_returns_shaped_rows(): void {
		$this->actingAs( 'administrator' );
		$this->seedShippingClass( 'Heavy' );
		$this->seedShippingClass( 'Fragile' );

		$result = wp_get_ability( self::ABILITY )->execute( array() );

		$this->assertIsArray( $result );
		$this->assertSame( array( 'items', 'total' ), array_keys( $result ) );
		$this->assertIsArray( $result['items'] );
		$this->assertIsInt( $result['total'] );
		$this->assertGreaterThanOrEqual( 2, $result['total'] );
		$this->assertNotEmpty( $result['items'] );

		$row = $result['items'][0];
		$this->assertSame( self::ROW_KEYS, array_keys( $row ) );
		$this->assertIsInt( $row['id'] );
		$this->assertIsString( $row['name'] );
		$this->assertIsString( $row['slug'] );
		$this->assertIsInt( $row['count'] );
		$this->assertIsString( $row['description'] );

		$names = wp_list_pluck( $result['items'], 'name' );
		$this->assertContains( 'Heavy', $names );
		$this->assertContains( 'Fragile', $names );
	}

	public function test_slug_narrows_results(): void {
		$this->actingAs( 'administrator' );
		$this->seedShippingClass( 'Bulky Items', 'bulky-items' );
		$this->seedShippingClass( 'Standard', 'standard' );

		$result = wp_get_ability( self::ABILITY )->execute( array( 'slug' => 'bulky-items' ) );

		$names = wp_list_pluck( $result['items'], 'name' );
		$this->assertContains( 'Bulky Items', $names );
		$this->assertNotContains( 'Standard', $names );
	}

	public function test_output_shape_has_no_parent_or_raw_fields(): void {
		$this->actingAs( 'administrator' );
		$this->seedShippingClass( 'Oversized' );

		$result = wp_get_ability( self::ABILITY )->execute( array() );

		$this->assertSame( array( 'items', 'total' ), array_keys( $result ) );
		$row = $result['items'][0];
		$this->assertSame( self::ROW_KEYS, array_keys( $row ) );
		$this->assertArrayNotHasKey( 'parent', $row );
		$this->assertArrayNotHasKey( 'menu_order', $row );
		$this->assertArrayNotHasKey( 'taxonomy', $row );
		$this->assertArrayNotHasKey( '_links', $row );
	}

	public function test_wrong_capability_is_denied(): void {
		$this->seedShippingClass( 'Heavy' );
		$this->actingAs( 'subscriber' );

		$ability = wp_get_ability( self::ABILITY );
		$this->assertFalse( $ability->check_permissions( array() ) );

		$result = $ability->execute( array() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_logged_out_user_is_denied(): void {
		$this->seedShippingClass( 'Heavy' );
		wp_set_current_user( 0 );

		$result = wp_get_ability( self::ABILITY )->execute( array() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	/**
	 * Seeds a product shipping class and returns its term ID.
	 *
	 * Uses core `wp_insert_term()` on WooCommerce's `product_shipping_class`
	 * taxonomy, which registers with the plugin, so no WC_Helper_* factory is needed.
	 *
	 * @param string $name The shipping class name.
	 * @param string $slug Optional explicit slug.
	 * @return int The created term ID.
	 */
	private function seedShippingClass( string $name, string $slug = '' ): int {
		$args = '' === $slug ? array() : array( 'slug' => $slug );
		$term = wp_insert_term( $name, 'product_shipping_class', $args );
		$this->assertIsArray( $term );

		return (int) $term['term_id'];
	}
}
