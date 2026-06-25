<?php
/**
 * Integration tests for the `og-wc-products/list-attribute-terms` ability.
 *
 * @package AbilitiesCatalogWoo\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogWoo\Tests\Integration\Abilities\Products;

use GalatanOvidiu\AbilitiesCatalogWoo\Tests\TestCase;
use WP_Error;

/**
 * Exercises og-wc-products/list-attribute-terms: the shaped term rows for a global
 * attribute's `pa_*` taxonomy, the `search` filter narrowing the set, the
 * bad-attribute_id 404 (which must surface as the route's
 * `woocommerce_rest_taxonomy_invalid`, not collapse to a permission error), the
 * wrong-capability denial, and the exact closed output shape (no `menu_order`
 * leak from the raw term row).
 *
 * @covers \GalatanOvidiu\AbilitiesCatalogWoo\Abilities\Woo\Products\ListAttributeTerms
 */
final class ListAttributeTermsTest extends TestCase {

	private const ABILITY = 'og-wc-products/list-attribute-terms';

	/**
	 * The exact keys a shaped term summary row exposes.
	 *
	 * Asserting against this fixed set proves the raw WC term row (which carries
	 * `menu_order`) is never leaked: only these projected fields reach the
	 * consumer.
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

		$attribute_id = $this->seedColorAttribute();
		wp_insert_term( 'Red', 'pa_color' );
		wp_insert_term( 'Blue', 'pa_color' );

		$result = wp_get_ability( self::ABILITY )->execute( array( 'attribute_id' => $attribute_id ) );

		$this->assertIsArray( $result );
		$this->assertSame( array( 'attribute_id', 'items', 'total' ), array_keys( $result ) );
		$this->assertSame( $attribute_id, $result['attribute_id'] );
		$this->assertIsArray( $result['items'] );
		$this->assertIsInt( $result['total'] );
		$this->assertGreaterThanOrEqual( 2, $result['total'] );
		$this->assertNotEmpty( $result['items'] );

		$row = $result['items'][0];
		$this->assertSame( self::ROW_KEYS, array_keys( $row ) );
		$this->assertIsInt( $row['id'] );
		$this->assertIsString( $row['name'] );
		$this->assertIsString( $row['slug'] );
		$this->assertIsInt( $row['parent'] );
		$this->assertIsInt( $row['count'] );
		$this->assertIsString( $row['description'] );

		$names = wp_list_pluck( $result['items'], 'name' );
		$this->assertContains( 'Red', $names );
		$this->assertContains( 'Blue', $names );
	}

	public function test_search_narrows_results(): void {
		$this->actingAs( 'administrator' );

		$attribute_id = $this->seedColorAttribute();
		wp_insert_term( 'Red', 'pa_color' );
		wp_insert_term( 'Blue', 'pa_color' );

		$result = wp_get_ability( self::ABILITY )->execute(
			array(
				'attribute_id' => $attribute_id,
				'search'       => 'Red',
			)
		);

		$names = wp_list_pluck( $result['items'], 'name' );
		$this->assertContains( 'Red', $names );
		$this->assertNotContains( 'Blue', $names );
	}

	public function test_bad_attribute_id_returns_taxonomy_invalid_404(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( self::ABILITY )->execute( array( 'attribute_id' => 99999999 ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'woocommerce_rest_taxonomy_invalid', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] );
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_output_shape_has_no_raw_term_fields(): void {
		$this->actingAs( 'administrator' );

		$attribute_id = $this->seedColorAttribute();
		wp_insert_term( 'Red', 'pa_color' );

		$result = wp_get_ability( self::ABILITY )->execute( array( 'attribute_id' => $attribute_id ) );

		$this->assertSame( array( 'attribute_id', 'items', 'total' ), array_keys( $result ) );
		$row = $result['items'][0];
		$this->assertSame( self::ROW_KEYS, array_keys( $row ) );
		$this->assertArrayNotHasKey( 'menu_order', $row );
		$this->assertArrayNotHasKey( '_links', $row );
	}

	public function test_wrong_capability_is_denied(): void {
		$attribute_id = $this->seedColorAttribute();
		$this->actingAs( 'subscriber' );

		$ability = wp_get_ability( self::ABILITY );

		$this->assertFalse( $ability->check_permissions( array( 'attribute_id' => $attribute_id ) ) );

		$result = $ability->execute( array( 'attribute_id' => $attribute_id ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	/**
	 * Seeds a global "Color" select attribute and registers its `pa_color`
	 * taxonomy in the current request, returning the attribute ID.
	 *
	 * Delegates to the shared {@see TestCase::createGlobalAttribute()} helper,
	 * which creates the attribute, registers the `pa_color` taxonomy in-request
	 * (so `wp_insert_term( …, 'pa_color' )` and the wrapped REST query see it),
	 * and clears the WC attribute caches.
	 *
	 * @return int The created attribute ID.
	 */
	private function seedColorAttribute(): int {
		return $this->createGlobalAttribute( 'Color' )['id'];
	}
}
