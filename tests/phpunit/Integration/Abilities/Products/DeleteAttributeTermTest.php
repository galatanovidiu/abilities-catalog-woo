<?php
/**
 * Integration tests for the wc-products/delete-attribute-term ability.
 *
 * @package AbilitiesCatalogWoo\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogWoo\Tests\Integration\Abilities\Products;

use GalatanOvidiu\AbilitiesCatalogWoo\Tests\TestCase;
use WP_Error;

/**
 * Exercises wc-products/delete-attribute-term: the happy-path permanent delete
 * (the term is gone from its pa_* taxonomy), the missing-term
 * woocommerce_rest_term_invalid 404, the bad-attribute_id
 * woocommerce_rest_taxonomy_invalid 404 (both surfaced as specific errors, never
 * a permission collapse), the wrong-capability denial leaving the term intact,
 * and the exact closed output shape (no edit_link, no raw term leak).
 */
final class DeleteAttributeTermTest extends TestCase {

	/**
	 * The closed key set the ability returns.
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
		$ability = wp_get_ability( 'wc-products/delete-attribute-term' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'wc-products/delete-attribute-term', $ability->get_name() );
	}

	public function test_admin_deletes_attribute_term(): void {
		$this->actingAs( 'administrator' );

		$attribute = $this->createGlobalAttribute( 'Color' );
		$term      = wp_insert_term( 'Red', $attribute['taxonomy'] );
		$this->assertNotWPError( $term );
		$term_id = (int) $term['term_id'];

		$result = wp_get_ability( 'wc-products/delete-attribute-term' )->execute(
			array(
				'attribute_id' => $attribute['id'],
				'id'           => $term_id,
			)
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['deleted'] );
		$this->assertSame( $term_id, $result['id'] );
		$this->assertSame( 'Red', $result['name'] );
		$this->assertTrue( $result['force_used'] );
		$this->assertTrue( $result['permanent'] );

		// Side-effect read-back: the term is permanently gone from the pa_* taxonomy.
		$this->assertNull( get_term( $term_id, $attribute['taxonomy'] ) );
		$this->assertFalse( get_term_by( 'slug', 'red', $attribute['taxonomy'] ) );
	}

	public function test_output_shape_is_exact_and_closed(): void {
		$this->actingAs( 'administrator' );

		$attribute = $this->createGlobalAttribute( 'Size' );
		$term      = wp_insert_term( 'Large', $attribute['taxonomy'] );
		$this->assertNotWPError( $term );

		$result = wp_get_ability( 'wc-products/delete-attribute-term' )->execute(
			array(
				'attribute_id' => $attribute['id'],
				'id'           => (int) $term['term_id'],
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( self::EXPECTED_KEYS, array_keys( $result ) );
		$this->assertIsBool( $result['deleted'] );
		$this->assertIsInt( $result['id'] );
		$this->assertIsString( $result['name'] );
		$this->assertIsBool( $result['force_used'] );
		$this->assertIsBool( $result['permanent'] );

		// No dead-end edit link and no raw term fields leak through.
		$this->assertArrayNotHasKey( 'edit_link', $result );
		$this->assertArrayNotHasKey( 'slug', $result );
		$this->assertArrayNotHasKey( '_links', $result );
	}

	public function test_missing_term_returns_term_invalid_404(): void {
		$this->actingAs( 'administrator' );

		$attribute = $this->createGlobalAttribute( 'Material' );

		$result = wp_get_ability( 'wc-products/delete-attribute-term' )->execute(
			array(
				'attribute_id' => $attribute['id'],
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

		$result = wp_get_ability( 'wc-products/delete-attribute-term' )->execute(
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

	public function test_subscriber_is_denied_and_term_survives(): void {
		$attribute = $this->createGlobalAttribute( 'Finish' );
		$term      = wp_insert_term( 'Matte', $attribute['taxonomy'] );
		$this->assertNotWPError( $term );
		$term_id = (int) $term['term_id'];

		$this->actingAs( 'subscriber' );

		$ability = wp_get_ability( 'wc-products/delete-attribute-term' );

		$this->assertFalse(
			$ability->check_permissions(
				array(
					'attribute_id' => $attribute['id'],
					'id'           => $term_id,
				)
			)
		);

		$result = $ability->execute(
			array(
				'attribute_id' => $attribute['id'],
				'id'           => $term_id,
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );

		// The denied delete left the term intact.
		$survivor = get_term( $term_id, $attribute['taxonomy'] );
		$this->assertNotWPError( $survivor );
		$this->assertNotNull( $survivor );
	}
}
