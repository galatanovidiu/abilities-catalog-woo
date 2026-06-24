<?php
/**
 * Integration tests for the wc-products/create-product-tag ability.
 *
 * @package AbilitiesCatalogWoo\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogWoo\Tests\Integration\Abilities\Products;

use GalatanOvidiu\AbilitiesCatalogWoo\Tests\TestCase;
use WP_Error;

/**
 * Exercises wc-products/create-product-tag: the happy-path create returning a
 * shaped tag with a real id, the duplicate-slug term_exists 400 surfaced via
 * RestError (not a permission collapse), the wrong-capability denial, and the
 * exact closed output shape (flat, parent always 0, no raw term fields).
 */
final class CreateProductTagTest extends TestCase {

	/**
	 * The full closed key set the ability returns for one tag.
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
		$ability = wp_get_ability( 'wc-products/create-product-tag' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'wc-products/create-product-tag', $ability->get_name() );
	}

	public function test_admin_creates_tag(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'wc-products/create-product-tag' )->execute( array( 'name' => 'Sale' ) );

		$this->assertIsArray( $result );
		$this->assertGreaterThan( 0, $result['id'] );
		$this->assertSame( 'Sale', $result['name'] );
		$this->assertSame( 'sale', $result['slug'] );
		$this->assertSame( 0, $result['parent'] );

		// The tag was actually persisted on the product_tag taxonomy.
		$term = get_term( $result['id'], 'product_tag' );
		$this->assertNotNull( $term );
		$this->assertSame( 'Sale', $term->name );
	}

	public function test_description_is_forwarded(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'wc-products/create-product-tag' )->execute(
			array(
				'name'        => 'Clearance',
				'description' => 'Items on clearance.',
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'Items on clearance.', $result['description'] );
	}

	public function test_output_shape_is_exact_and_closed(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'wc-products/create-product-tag' )->execute( array( 'name' => 'Featured' ) );

		$this->assertIsArray( $result );
		$this->assertSame( self::EXPECTED_KEYS, array_keys( $result ) );

		// No raw term fields leak through.
		$this->assertArrayNotHasKey( 'menu_order', $result );
		$this->assertArrayNotHasKey( '_links', $result );
		$this->assertArrayNotHasKey( 'taxonomy', $result );
		$this->assertArrayNotHasKey( 'display', $result );

		$this->assertIsInt( $result['id'] );
		$this->assertIsString( $result['name'] );
		$this->assertIsString( $result['slug'] );
		$this->assertIsInt( $result['parent'] );
		$this->assertIsInt( $result['count'] );
		$this->assertIsString( $result['description'] );
	}

	public function test_duplicate_name_returns_term_exists_400_not_permission_error(): void {
		$this->actingAs( 'administrator' );

		// Seed an existing tag whose NAME the create will collide with.
		wp_insert_term( 'On Sale', 'product_tag', array( 'slug' => 'on-sale' ) );

		// A create with the SAME NAME is the real rejection path: wp_insert_term()
		// returns term_exists (product_tag is non-hierarchical, so a name match
		// alone rejects) and the wrapped wc/v3 route forwards it with status 400.
		// A duplicate *slug* with a different name would instead succeed with an
		// auto-suffixed slug, so it is not a rejection path.
		$result = wp_get_ability( 'wc-products/create-product-tag' )->execute(
			array(
				'name' => 'On Sale',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'term_exists', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] );
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_subscriber_is_denied(): void {
		$this->actingAs( 'subscriber' );

		$ability = wp_get_ability( 'wc-products/create-product-tag' );

		$this->assertFalse( $ability->check_permissions( array( 'name' => 'Nope' ) ) );

		$result = $ability->execute( array( 'name' => 'Nope' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );

		// The denied write created nothing.
		$this->assertFalse( get_term_by( 'slug', 'nope', 'product_tag' ) );
	}
}
