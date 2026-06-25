<?php
/**
 * Integration tests for the og-wc-products/update-attribute-term ability.
 *
 * @package AbilitiesCatalogWoo\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogWoo\Tests\Integration\Abilities\Products;

use GalatanOvidiu\AbilitiesCatalogWoo\Tests\TestCase;
use WP_Error;

/**
 * Exercises og-wc-products/update-attribute-term: the happy-path rename returning a
 * shaped term row, the missing-term 404 and the bad-attribute_id 404 that must
 * not collapse to a permission error, the wrong-capability denial, and the exact
 * closed output shape (no menu_order leak).
 */
final class UpdateAttributeTermTest extends TestCase {

	/**
	 * The closed key set the ability returns.
	 *
	 * @var list<string>
	 */
	private const EXPECTED_KEYS = array(
		'id',
		'name',
		'slug',
		'parent',
		'count',
		'description',
	);

	public function test_ability_is_registered(): void {
		$ability = wp_get_ability( 'og-wc-products/update-attribute-term' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'og-wc-products/update-attribute-term', $ability->get_name() );
	}

	public function test_admin_updates_attribute_term(): void {
		$this->actingAs( 'administrator' );

		$seed = $this->seedAttributeTerm( 'Color', 'Red', 'A bold red.' );

		$result = wp_get_ability( 'og-wc-products/update-attribute-term' )->execute(
			array(
				'attribute_id' => $seed['attribute_id'],
				'id'           => $seed['term_id'],
				'name'         => 'Crimson',
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( $seed['term_id'], $result['id'] );
		$this->assertSame( 'Crimson', $result['name'] );
		$this->assertSame( 'red', $result['slug'] );
		$this->assertSame( 'A bold red.', $result['description'] );

		// Read it back from core to confirm the rename persisted.
		$term = get_term( $seed['term_id'], $seed['taxonomy'] );
		$this->assertSame( 'Crimson', $term->name );
	}

	public function test_output_shape_is_exact_and_closed(): void {
		$this->actingAs( 'administrator' );

		$seed = $this->seedAttributeTerm( 'Size', 'Large', '' );

		$result = wp_get_ability( 'og-wc-products/update-attribute-term' )->execute(
			array(
				'attribute_id' => $seed['attribute_id'],
				'id'           => $seed['term_id'],
				'description'  => 'Roomy fit.',
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( self::EXPECTED_KEYS, array_keys( $result ) );

		$this->assertIsInt( $result['id'] );
		$this->assertIsString( $result['name'] );
		$this->assertIsString( $result['slug'] );
		$this->assertIsInt( $result['parent'] );
		$this->assertIsInt( $result['count'] );
		$this->assertIsString( $result['description'] );

		// No raw term fields leak through.
		$this->assertArrayNotHasKey( 'menu_order', $result );
		$this->assertArrayNotHasKey( '_links', $result );
	}

	public function test_missing_term_returns_404_not_permission_error(): void {
		$this->actingAs( 'administrator' );

		$seed = $this->seedAttributeTerm( 'Material', 'Cotton', '' );

		$result = wp_get_ability( 'og-wc-products/update-attribute-term' )->execute(
			array(
				'attribute_id' => $seed['attribute_id'],
				'id'           => 99999999,
				'name'         => 'Linen',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'woocommerce_rest_term_invalid', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] );
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_bad_attribute_id_returns_taxonomy_invalid_404(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'og-wc-products/update-attribute-term' )->execute(
			array(
				'attribute_id' => 99999999,
				'id'           => 1,
				'name'         => 'Anything',
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

		$ability = wp_get_ability( 'og-wc-products/update-attribute-term' );

		$this->assertFalse(
			$ability->check_permissions(
				array(
					'attribute_id' => $seed['attribute_id'],
					'id'           => $seed['term_id'],
					'name'         => 'Glossy',
				)
			)
		);

		$result = $ability->execute(
			array(
				'attribute_id' => $seed['attribute_id'],
				'id'           => $seed['term_id'],
				'name'         => 'Glossy',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );

		// The denied write left the term unchanged.
		$term = get_term( $seed['term_id'], $seed['taxonomy'] );
		$this->assertSame( 'Matte', $term->name );
	}

	/**
	 * Seeds a global attribute and one term on its `pa_*` taxonomy.
	 *
	 * Uses the driver-owned {@see TestCase::createGlobalAttribute()} helper (which
	 * registers the `pa_*` taxonomy in-request and clears the leaked-row caches the
	 * `WP_UnitTestCase` transaction cannot roll back), then core `wp_insert_term()`
	 * for the term.
	 *
	 * @param string $attribute_name The global attribute name (e.g. "Color").
	 * @param string $term_name      The term name (e.g. "Red").
	 * @param string $description    The term description.
	 * @return array{attribute_id:int,term_id:int,taxonomy:string} The created attribute, term, and taxonomy.
	 */
	private function seedAttributeTerm( string $attribute_name, string $term_name, string $description ): array {
		$attribute = $this->createGlobalAttribute( $attribute_name );

		$term = wp_insert_term(
			$term_name,
			$attribute['taxonomy'],
			array( 'description' => $description )
		);

		return array(
			'attribute_id' => $attribute['id'],
			'term_id'      => (int) $term['term_id'],
			'taxonomy'     => $attribute['taxonomy'],
		);
	}
}
