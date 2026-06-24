<?php
/**
 * Integration tests for the wc-products/update-product-tag ability.
 *
 * @package AbilitiesCatalogWoo\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogWoo\Tests\Integration\Abilities\Products;

use GalatanOvidiu\AbilitiesCatalogWoo\Tests\TestCase;
use WP_Error;

/**
 * Exercises wc-products/update-product-tag: a name change on a seeded tag, the
 * missing-tag 404 that must not collapse to a permission error, the
 * wrong-capability denial (with the tag unchanged), and the exact closed output
 * shape.
 */
final class UpdateProductTagTest extends TestCase {

	/**
	 * The full closed key set the ability returns for one tag (the shared term
	 * summary, with parent always 0 for the flat product_tag taxonomy).
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
		$ability = wp_get_ability( 'wc-products/update-product-tag' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'wc-products/update-product-tag', $ability->get_name() );
	}

	public function test_admin_updates_tag_name(): void {
		$this->actingAs( 'administrator' );

		$id = $this->seedTag( 'Sale' );

		$result = wp_get_ability( 'wc-products/update-product-tag' )->execute(
			array(
				'id'   => $id,
				'name' => 'Clearance',
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( $id, $result['id'] );
		$this->assertSame( 'Clearance', $result['name'] );

		// The change persisted to the live term.
		$this->assertSame( 'Clearance', get_term( $id, 'product_tag' )->name );
	}

	public function test_output_shape_is_exact_and_closed(): void {
		$this->actingAs( 'administrator' );

		$id = $this->seedTag( 'Featured' );

		$result = wp_get_ability( 'wc-products/update-product-tag' )->execute(
			array(
				'id'   => $id,
				'name' => 'Featured Items',
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( self::EXPECTED_KEYS, array_keys( $result ) );

		// No raw term fields leak through.
		$this->assertArrayNotHasKey( 'menu_order', $result );
		$this->assertArrayNotHasKey( '_links', $result );
		$this->assertArrayNotHasKey( 'taxonomy', $result );

		$this->assertIsInt( $result['id'] );
		$this->assertIsString( $result['name'] );
		$this->assertIsString( $result['slug'] );
		$this->assertSame( 0, $result['parent'] );
		$this->assertIsInt( $result['count'] );
		$this->assertIsString( $result['description'] );
	}

	public function test_missing_tag_returns_404_not_permission_error(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'wc-products/update-product-tag' )->execute(
			array(
				'id'   => 99999999,
				'name' => 'Nope',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'woocommerce_rest_term_invalid', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] );
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_subscriber_is_denied_and_tag_unchanged(): void {
		$id = $this->seedTag( 'Sale' );

		$this->actingAs( 'subscriber' );

		$ability = wp_get_ability( 'wc-products/update-product-tag' );

		$this->assertFalse(
			$ability->check_permissions(
				array(
					'id'   => $id,
					'name' => 'Clearance',
				)
			)
		);

		$result = $ability->execute(
			array(
				'id'   => $id,
				'name' => 'Clearance',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );

		// The denied write did not change the tag.
		$this->assertSame( 'Sale', get_term( $id, 'product_tag' )->name );
	}

	/**
	 * Seeds a product tag term and returns its ID.
	 *
	 * @param string $name The tag name.
	 * @return int The created term ID.
	 */
	private function seedTag( string $name ): int {
		$term = wp_insert_term( $name, 'product_tag' );

		return (int) $term['term_id'];
	}
}
