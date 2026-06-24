<?php
/**
 * Integration tests for the wc-products/create-attribute-term ability.
 *
 * @package AbilitiesCatalogWoo\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogWoo\Tests\Integration\Abilities\Products;

use GalatanOvidiu\AbilitiesCatalogWoo\Tests\TestCase;
use WP_Error;

/**
 * Exercises wc-products/create-attribute-term: the happy-path create returning a
 * shaped term row with a real id, the duplicate-slug term_exists 400, the
 * bad-attribute_id taxonomy-invalid 404 that must not collapse to a permission
 * error, the wrong-capability denial, and the exact closed output shape (no
 * menu_order leak).
 */
final class CreateAttributeTermTest extends TestCase {

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
		$ability = wp_get_ability( 'wc-products/create-attribute-term' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'wc-products/create-attribute-term', $ability->get_name() );
	}

	public function test_admin_creates_attribute_term(): void {
		$this->actingAs( 'administrator' );

		$attribute = $this->createGlobalAttribute( 'Color' );

		$result = wp_get_ability( 'wc-products/create-attribute-term' )->execute(
			array(
				'attribute_id' => $attribute['id'],
				'name'         => 'Red',
				'description'  => 'A bold red.',
			)
		);

		$this->assertIsArray( $result );
		$this->assertGreaterThan( 0, $result['id'] );
		$this->assertSame( 'Red', $result['name'] );
		$this->assertSame( 'red', $result['slug'] );
		$this->assertSame( 0, $result['parent'] );
		$this->assertSame( 'A bold red.', $result['description'] );

		// Side-effect read-back: the term really exists on the pa_* taxonomy.
		$term = get_term( $result['id'], $attribute['taxonomy'] );
		$this->assertNotWPError( $term );
		$this->assertNotNull( $term );
		$this->assertSame( 'Red', $term->name );
	}

	public function test_output_shape_is_exact_and_closed(): void {
		$this->actingAs( 'administrator' );

		$attribute = $this->createGlobalAttribute( 'Size' );

		$result = wp_get_ability( 'wc-products/create-attribute-term' )->execute(
			array(
				'attribute_id' => $attribute['id'],
				'name'         => 'Large',
				'menu_order'   => 5,
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

		// No raw term fields leak through, including the menu_order we set.
		$this->assertArrayNotHasKey( 'menu_order', $result );
		$this->assertArrayNotHasKey( '_links', $result );
	}

	public function test_duplicate_name_returns_term_exists_400(): void {
		$this->actingAs( 'administrator' );

		$attribute = $this->createGlobalAttribute( 'Material' );

		$first = wp_get_ability( 'wc-products/create-attribute-term' )->execute(
			array(
				'attribute_id' => $attribute['id'],
				'name'         => 'Cotton',
				'slug'         => 'cotton',
			)
		);
		$this->assertIsArray( $first );

		// A second term with the SAME NAME on the same pa_* taxonomy is the real
		// rejection path: wp_insert_term() returns a term_exists error (the pa_*
		// taxonomy is non-hierarchical, so a name match alone rejects), which the
		// wrapped wc/v3 create route forwards verbatim with status 400. (A second
		// term with only a duplicate *slug* but a different name would instead
		// succeed with an auto-suffixed slug, so it is not a rejection path.)
		$result = wp_get_ability( 'wc-products/create-attribute-term' )->execute(
			array(
				'attribute_id' => $attribute['id'],
				'name'         => 'Cotton',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'term_exists', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] );
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_bad_attribute_id_returns_taxonomy_invalid_404(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'wc-products/create-attribute-term' )->execute(
			array(
				'attribute_id' => 99999999,
				'name'         => 'Orphan',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'woocommerce_rest_taxonomy_invalid', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] );
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_subscriber_is_denied(): void {
		$attribute = $this->createGlobalAttribute( 'Finish' );
		$this->actingAs( 'subscriber' );

		$ability = wp_get_ability( 'wc-products/create-attribute-term' );

		$this->assertFalse(
			$ability->check_permissions(
				array(
					'attribute_id' => $attribute['id'],
					'name'         => 'Matte',
				)
			)
		);

		$result = $ability->execute(
			array(
				'attribute_id' => $attribute['id'],
				'name'         => 'Matte',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );

		// The denied write created nothing.
		$this->assertFalse( get_term_by( 'slug', 'matte', $attribute['taxonomy'] ) );
	}
}
