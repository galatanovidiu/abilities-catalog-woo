<?php
/**
 * Integration tests for the `og-wc-products/list-product-tags` ability.
 *
 * @package AbilitiesCatalogWoo\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogWoo\Tests\Integration\Abilities\Products;

use GalatanOvidiu\AbilitiesCatalogWoo\Tests\TestCase;
use WP_Error;

/**
 * @covers \GalatanOvidiu\AbilitiesCatalogWoo\Abilities\Woo\Products\ListProductTags
 */
final class ListProductTagsTest extends TestCase {

	private const ABILITY = 'og-wc-products/list-product-tags';

	/**
	 * The exact keys a shaped product-tag summary row exposes.
	 *
	 * Asserting against this fixed set proves the raw term body is never leaked:
	 * only these projected fields reach the consumer. `parent` is carried by the
	 * shared term row and is always 0 for the flat `product_tag` taxonomy; no
	 * `menu_order` or other raw field leaks.
	 *
	 * @var list<string>
	 */
	private const ROW_KEYS = array(
		'id',
		'name',
		'slug',
		'parent',
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
		$this->seedTag( 'Sale' );
		$this->seedTag( 'Clearance' );

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
		$this->assertContains( 'Sale', $names );
		$this->assertContains( 'Clearance', $names );
	}

	public function test_search_narrows_results(): void {
		$this->actingAs( 'administrator' );
		$this->seedTag( 'Limited Edition' );
		$this->seedTag( 'Bestseller' );

		$result = wp_get_ability( self::ABILITY )->execute( array( 'search' => 'Limited Edition' ) );

		$names = wp_list_pluck( $result['items'], 'name' );
		$this->assertContains( 'Limited Edition', $names );
		$this->assertNotContains( 'Bestseller', $names );
	}

	public function test_output_shape_has_no_raw_term_fields(): void {
		$this->actingAs( 'administrator' );
		$this->seedTag( 'Featured' );

		$result = wp_get_ability( self::ABILITY )->execute( array() );

		$this->assertSame( array( 'items', 'total' ), array_keys( $result ) );
		$row = $result['items'][0];
		$this->assertSame( self::ROW_KEYS, array_keys( $row ) );
		$this->assertArrayNotHasKey( 'menu_order', $row );
		$this->assertArrayNotHasKey( 'taxonomy', $row );
		$this->assertArrayNotHasKey( '_links', $row );
	}

	public function test_wrong_capability_is_denied(): void {
		$this->seedTag( 'Sale' );
		$this->actingAs( 'subscriber' );

		$ability = wp_get_ability( self::ABILITY );
		$this->assertFalse( $ability->check_permissions( array() ) );

		$result = $ability->execute( array() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_logged_out_user_is_denied(): void {
		$this->seedTag( 'Sale' );
		wp_set_current_user( 0 );

		$result = wp_get_ability( self::ABILITY )->execute( array() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	/**
	 * Seeds a product tag and returns its term ID.
	 *
	 * Uses core `wp_insert_term()` on WooCommerce's `product_tag` taxonomy, which
	 * registers with the plugin, so no WC_Helper_* factory is needed.
	 *
	 * @param string $name The tag name.
	 * @return int The created term ID.
	 */
	private function seedTag( string $name ): int {
		$term = wp_insert_term( $name, 'product_tag' );
		$this->assertIsArray( $term );

		return (int) $term['term_id'];
	}
}
