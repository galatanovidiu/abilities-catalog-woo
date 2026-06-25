<?php
/**
 * Integration tests for the og-wc-products/delete-product-tag ability.
 *
 * @package AbilitiesCatalogWoo\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogWoo\Tests\Integration\Abilities\Products;

use GalatanOvidiu\AbilitiesCatalogWoo\Tests\TestCase;
use WP_Error;

/**
 * Exercises og-wc-products/delete-product-tag: the happy-path permanent delete that
 * removes the term, the missing-tag woocommerce_rest_term_invalid 404 surfaced
 * via RestError (not a permission collapse), the wrong-capability denial that
 * leaves the tag intact, and the exact closed output shape (no edit_link, no raw
 * term fields).
 */
final class DeleteProductTagTest extends TestCase {

	/**
	 * The full closed key set the ability returns.
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

	/**
	 * Seeds a product tag and returns its term ID.
	 *
	 * @param string $name The tag name.
	 * @param string $slug The tag slug.
	 * @return int The seeded term ID.
	 */
	private function seedTag( string $name, string $slug ): int {
		$term = wp_insert_term( $name, 'product_tag', array( 'slug' => $slug ) );
		$this->assertIsArray( $term );

		return (int) $term['term_id'];
	}

	public function test_ability_is_registered(): void {
		$ability = wp_get_ability( 'og-wc-products/delete-product-tag' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'og-wc-products/delete-product-tag', $ability->get_name() );
	}

	public function test_admin_permanently_deletes_tag(): void {
		$this->actingAs( 'administrator' );

		$id = $this->seedTag( 'Sale', 'sale' );

		$result = wp_get_ability( 'og-wc-products/delete-product-tag' )->execute( array( 'id' => $id ) );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['deleted'] );
		$this->assertSame( $id, $result['id'] );
		$this->assertSame( 'Sale', $result['name'] );
		$this->assertTrue( $result['force_used'] );
		$this->assertTrue( $result['permanent'] );

		// The term is gone: there is no Trash for tags.
		$this->assertNull( get_term( $id, 'product_tag' ) );
		$this->assertFalse( get_term_by( 'slug', 'sale', 'product_tag' ) );
	}

	public function test_output_shape_is_exact_and_closed(): void {
		$this->actingAs( 'administrator' );

		$id = $this->seedTag( 'Featured', 'featured' );

		$result = wp_get_ability( 'og-wc-products/delete-product-tag' )->execute( array( 'id' => $id ) );

		$this->assertIsArray( $result );
		$this->assertSame( self::EXPECTED_KEYS, array_keys( $result ) );

		// No dead-end edit link and no raw term fields leak through.
		$this->assertArrayNotHasKey( 'edit_link', $result );
		$this->assertArrayNotHasKey( 'slug', $result );
		$this->assertArrayNotHasKey( '_links', $result );
		$this->assertArrayNotHasKey( 'taxonomy', $result );

		$this->assertIsBool( $result['deleted'] );
		$this->assertIsInt( $result['id'] );
		$this->assertIsString( $result['name'] );
		$this->assertIsBool( $result['force_used'] );
		$this->assertIsBool( $result['permanent'] );
	}

	public function test_missing_tag_returns_term_invalid_404_not_permission_error(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'og-wc-products/delete-product-tag' )->execute( array( 'id' => 99999999 ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'woocommerce_rest_term_invalid', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] );
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_subscriber_is_denied_and_tag_survives(): void {
		$this->actingAs( 'subscriber' );

		$id = $this->seedTag( 'Keep', 'keep' );

		$ability = wp_get_ability( 'og-wc-products/delete-product-tag' );

		$this->assertFalse( $ability->check_permissions( array( 'id' => $id ) ) );

		$result = $ability->execute( array( 'id' => $id ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );

		// The denied delete left the tag intact.
		$this->assertNotNull( get_term( $id, 'product_tag' ) );
	}
}
