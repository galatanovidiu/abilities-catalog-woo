<?php
/**
 * Integration tests for the wc-products/get-attribute-term ability.
 *
 * @package AbilitiesCatalogWoo\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogWoo\Tests\Integration\Abilities\Products;

use GalatanOvidiu\AbilitiesCatalogWoo\Tests\TestCase;
use WP_Error;

/**
 * Exercises wc-products/get-attribute-term: the shaped single-term record, the
 * missing-term 404 and the bad-attribute_id 404 that must not collapse to a
 * permission error, the wrong-capability denial, and the exact closed output
 * shape (no menu_order leak).
 */
final class GetAttributeTermTest extends TestCase {

	/**
	 * The closed key set the ability returns.
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
		$ability = wp_get_ability( 'wc-products/get-attribute-term' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'wc-products/get-attribute-term', $ability->get_name() );
	}

	public function test_admin_reads_attribute_term_detail(): void {
		$this->actingAs( 'administrator' );

		$seed = $this->seedAttributeTerm( 'Color', 'Red', 'A bold red.' );

		$result = wp_get_ability( 'wc-products/get-attribute-term' )->execute(
			array(
				'attribute_id' => $seed['attribute_id'],
				'id'           => $seed['term_id'],
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( $seed['term_id'], $result['id'] );
		$this->assertSame( 'Red', $result['name'] );
		$this->assertSame( 'red', $result['slug'] );
		$this->assertSame( 'A bold red.', $result['description'] );
		$this->assertIsInt( $result['count'] );
	}

	public function test_output_shape_is_exact_and_closed(): void {
		$this->actingAs( 'administrator' );

		$seed = $this->seedAttributeTerm( 'Size', 'Large', '' );

		$result = wp_get_ability( 'wc-products/get-attribute-term' )->execute(
			array(
				'attribute_id' => $seed['attribute_id'],
				'id'           => $seed['term_id'],
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( self::EXPECTED_KEYS, array_keys( $result ) );

		// No raw term fields leak through.
		$this->assertArrayNotHasKey( 'menu_order', $result );
		$this->assertArrayNotHasKey( 'parent', $result );
		$this->assertArrayNotHasKey( '_links', $result );
	}

	public function test_missing_term_returns_404_not_permission_error(): void {
		$this->actingAs( 'administrator' );

		$seed = $this->seedAttributeTerm( 'Material', 'Cotton', '' );

		$result = wp_get_ability( 'wc-products/get-attribute-term' )->execute(
			array(
				'attribute_id' => $seed['attribute_id'],
				'id'           => 99999999,
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'woocommerce_rest_term_invalid', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] );
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_bad_attribute_id_returns_taxonomy_invalid_404(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'wc-products/get-attribute-term' )->execute(
			array(
				'attribute_id' => 99999999,
				'id'           => 1,
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'woocommerce_rest_taxonomy_invalid', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] );
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_subscriber_is_denied(): void {
		$seed = $this->seedAttributeTerm( 'Finish', 'Matte', '' );
		$this->actingAs( 'subscriber' );

		$ability = wp_get_ability( 'wc-products/get-attribute-term' );

		$this->assertFalse(
			$ability->check_permissions(
				array(
					'attribute_id' => $seed['attribute_id'],
					'id'           => $seed['term_id'],
				)
			)
		);

		$result = $ability->execute(
			array(
				'attribute_id' => $seed['attribute_id'],
				'id'           => $seed['term_id'],
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	/**
	 * Seeds a global attribute and one term on its pa_* taxonomy.
	 *
	 * Uses WooCommerce's runtime API (`wc_create_attribute`) for the attribute and
	 * core `wp_insert_term()` for the term, because the test environment mounts the
	 * distributed WooCommerce build, which ships no tests/ helper framework. The
	 * attribute taxonomy is `pa_<slug>` (e.g. `pa_color`).
	 *
	 * @param string $attribute_name The global attribute name (e.g. "Color").
	 * @param string $term_name      The term name (e.g. "Red").
	 * @param string $description    The term description.
	 * @return array{attribute_id:int,term_id:int} The created attribute and term IDs.
	 */
	private function seedAttributeTerm( string $attribute_name, string $term_name, string $description ): array {
		$attribute_id = wc_create_attribute(
			array(
				'name' => $attribute_name,
				'type' => 'select',
			)
		);

		$taxonomy = wc_attribute_taxonomy_name_by_id( (int) $attribute_id );

		// Register the taxonomy so wp_insert_term() can write to it within the request.
		if ( ! taxonomy_exists( $taxonomy ) ) {
			register_taxonomy( $taxonomy, 'product', array( 'hierarchical' => false ) );
		}

		$term = wp_insert_term(
			$term_name,
			$taxonomy,
			array( 'description' => $description )
		);

		return array(
			'attribute_id' => (int) $attribute_id,
			'term_id'      => (int) $term['term_id'],
		);
	}
}
