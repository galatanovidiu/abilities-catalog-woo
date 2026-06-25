<?php
/**
 * Integration tests for the `og-wc-products/list-product-attributes` ability.
 *
 * @package AbilitiesCatalogWoo\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogWoo\Tests\Integration\Abilities\Products;

use GalatanOvidiu\AbilitiesCatalogWoo\Tests\TestCase;
use WP_Error;

/**
 * @covers \GalatanOvidiu\AbilitiesCatalogWoo\Abilities\Woo\Products\ListProductAttributes
 */
final class ListProductAttributesTest extends TestCase {

	private const ABILITY = 'og-wc-products/list-product-attributes';

	/**
	 * The exact keys a shaped attribute summary row exposes.
	 *
	 * Asserting against this fixed set proves the raw attribute body is never
	 * leaked: only these projected fields reach the consumer (no menu_order, no
	 * extra attribute meta).
	 *
	 * @var list<string>
	 */
	private const ROW_KEYS = array(
		'id',
		'name',
		'slug',
		'type',
		'order_by',
		'has_archives',
	);

	public function test_registered(): void {
		$ability = wp_get_ability( self::ABILITY );

		$this->assertNotNull( $ability );
		$this->assertSame( self::ABILITY, $ability->get_name() );
	}

	public function test_happy_path_returns_shaped_rows(): void {
		$this->actingAs( 'administrator' );
		$this->seedAttribute( 'Color' );
		$this->seedAttribute( 'Size' );

		$result = wp_get_ability( self::ABILITY )->execute( array() );

		$this->assertIsArray( $result );
		$this->assertSame( array( 'items', 'total' ), array_keys( $result ) );
		$this->assertIsArray( $result['items'] );
		$this->assertIsInt( $result['total'] );
		$this->assertGreaterThanOrEqual( 2, $result['total'] );
		$this->assertNotEmpty( $result['items'] );

		$names = wp_list_pluck( $result['items'], 'name' );
		$this->assertContains( 'Color', $names );
		$this->assertContains( 'Size', $names );

		$row = $result['items'][0];
		$this->assertSame( self::ROW_KEYS, array_keys( $row ) );
		$this->assertIsInt( $row['id'] );
		$this->assertIsString( $row['name'] );
		$this->assertIsString( $row['slug'] );
		$this->assertIsString( $row['type'] );
		$this->assertIsString( $row['order_by'] );
		$this->assertIsBool( $row['has_archives'] );
	}

	public function test_total_equals_row_count(): void {
		$this->actingAs( 'administrator' );
		$this->seedAttribute( 'Color' );
		$this->seedAttribute( 'Size' );
		$this->seedAttribute( 'Material' );

		$result = wp_get_ability( self::ABILITY )->execute( array() );

		// This route is unpaged and sends X-WP-Total = count(rows), so the two coincide.
		$this->assertSame( count( $result['items'] ), $result['total'] );
		$this->assertGreaterThanOrEqual( 3, $result['total'] );
	}

	public function test_output_shape_has_no_raw_attribute_fields(): void {
		$this->actingAs( 'administrator' );
		$this->seedAttribute( 'Color' );

		$result = wp_get_ability( self::ABILITY )->execute( array() );

		$this->assertSame( array( 'items', 'total' ), array_keys( $result ) );
		$row = $result['items'][0];
		$this->assertSame( self::ROW_KEYS, array_keys( $row ) );
		$this->assertArrayNotHasKey( 'menu_order', $row );
		$this->assertArrayNotHasKey( '_links', $row );
	}

	public function test_wrong_capability_is_denied(): void {
		$this->seedAttribute( 'Color' );
		$this->actingAs( 'subscriber' );

		$result = wp_get_ability( self::ABILITY )->execute( array() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_logged_out_user_is_denied(): void {
		$this->seedAttribute( 'Color' );
		wp_set_current_user( 0 );

		$result = wp_get_ability( self::ABILITY )->execute( array() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	/**
	 * Seeds a global product attribute and returns its id.
	 *
	 * Delegates to the shared {@see TestCase::createGlobalAttribute()} helper,
	 * which creates the attribute, registers its `pa_*` taxonomy in-request, and
	 * clears the WC attribute caches so the wrapped REST list sees fresh rows.
	 *
	 * @param string $name The attribute name, e.g. "Color".
	 * @return int The created attribute id.
	 */
	private function seedAttribute( string $name ): int {
		return $this->createGlobalAttribute( $name )['id'];
	}
}
