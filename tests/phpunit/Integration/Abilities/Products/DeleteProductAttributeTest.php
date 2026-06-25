<?php
/**
 * Integration tests for the `og-wc-products/delete-product-attribute` ability.
 *
 * @package AbilitiesCatalogWoo\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogWoo\Tests\Integration\Abilities\Products;

use GalatanOvidiu\AbilitiesCatalogWoo\Tests\TestCase;
use WP_Error;

/**
 * Exercises og-wc-products/delete-product-attribute: the permanent force-delete that
 * also drops the attribute's pa_* taxonomy and all its terms, the route's specific
 * 404 for a missing attribute (not a permission collapse), the wrong-cap denial
 * (attributes are manager-tier), and the exact closed output shape with no edit_link.
 *
 * @covers \GalatanOvidiu\AbilitiesCatalogWoo\Abilities\Woo\Products\DeleteProductAttribute
 */
final class DeleteProductAttributeTest extends TestCase {

	private const ABILITY = 'og-wc-products/delete-product-attribute';

	/**
	 * Taxonomies registered in-request by this test class that must be
	 * unregistered in tearDown to prevent contaminating later tests.
	 *
	 * @var list<string>
	 */
	private array $registered_taxonomies = array();

	/**
	 * The exact keys the delete result exposes (the closed output schema).
	 *
	 * @var list<string>
	 */
	private const RESULT_KEYS = array(
		'deleted',
		'id',
		'name',
		'force_used',
		'permanent',
	);

	/**
	 * Unregisters any pa_* taxonomies this test created so they do not leak
	 * into later test classes (e.g. GetAttributeTermTest) via the in-memory
	 * $wp_taxonomies global.  The parent tear_down() already cleans the DB
	 * rows via deleteAllGlobalAttributes(), but that helper only calls
	 * unregister_taxonomy() for rows that still exist in the DB — rows
	 * deleted by the ability under test are gone from the DB before tear_down()
	 * runs, so they are skipped, leaving $wp_taxonomies stale.
	 *
	 * We sweep every registered pa_* taxonomy that no longer exists in the WC
	 * DB-backed registry and forcibly unregister it.
	 */
	public function tear_down(): void {
		// Flush caches so wc_get_attribute_taxonomy_names() reads from the DB.
		delete_transient( 'wc_attribute_taxonomies' );
		\WC_Cache_Helper::invalidate_cache_group( 'woocommerce-attributes' );

		$live_names = wc_get_attribute_taxonomy_names();

		global $wp_taxonomies;
		foreach ( array_keys( $wp_taxonomies ) as $taxonomy ) {
			if ( strncmp( $taxonomy, 'pa_', 3 ) === 0 && ! in_array( $taxonomy, $live_names, true ) ) {
				unregister_taxonomy( $taxonomy );
			}
		}

		$this->registered_taxonomies = array();

		parent::tear_down();
	}

	public function test_registered(): void {
		$ability = wp_get_ability( self::ABILITY );

		$this->assertNotNull( $ability );
		$this->assertSame( self::ABILITY, $ability->get_name() );
	}

	public function test_admin_deletes_attribute_and_drops_taxonomy(): void {
		$this->actingAs( 'administrator' );

		[ 'id' => $attr_id, 'taxonomy' => $taxonomy ] = $this->createGlobalAttribute( 'Color' );

		// Track so tear_down() can unregister the in-memory taxonomy after the
		// ability drops it from the DB (parent tear_down iterates DB rows only).
		$this->registered_taxonomies[] = $taxonomy;

		// Seed a term on the attribute's pa_* taxonomy so we can prove it is dropped.
		$term = wp_insert_term( 'Red', $taxonomy );
		$this->assertIsArray( $term );
		$term_id = (int) $term['term_id'];

		$this->assertTrue( taxonomy_exists( $taxonomy ) );

		$result = wp_get_ability( self::ABILITY )->execute( array( 'id' => $attr_id ) );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['deleted'] );
		$this->assertSame( $attr_id, $result['id'] );
		$this->assertSame( 'Color', $result['name'] );
		$this->assertTrue( $result['force_used'] );
		$this->assertTrue( $result['permanent'] );

		// Flush WC's attribute caches so the next assertions hit the DB, not a
		// stale in-memory snapshot (wc_delete_attribute already cleared the
		// transient, but the object-cache group key prefix may still be warm).
		delete_transient( 'wc_attribute_taxonomies' );
		\WC_Cache_Helper::invalidate_cache_group( 'woocommerce-attributes' );

		// The attribute is gone from WC's DB-backed registry.
		// taxonomy_exists() is intentionally NOT used here: register_taxonomy()
		// persists in $wp_taxonomies for the lifetime of the PHP request, so it
		// would wrongly return true even after the DB row and terms are deleted.
		$this->assertSame( 0, wc_attribute_taxonomy_id_by_name( 'color' ) );
		$this->assertNotContains(
			$attr_id,
			array_map( 'intval', wp_list_pluck( wc_get_attribute_taxonomies(), 'attribute_id' ) )
		);

		// All terms belonging to the attribute were deleted with it.
		$this->assertNull( get_term( $term_id ) );
	}

	public function test_missing_attribute_returns_404_not_permission_collapse(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( self::ABILITY )->execute( array( 'id' => 99999999 ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code() );
		// The route's get_taxonomy() check fires first for an unknown attribute id.
		$this->assertSame( 'woocommerce_rest_taxonomy_invalid', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] );
	}

	public function test_output_shape_is_exact_and_closed(): void {
		$this->actingAs( 'administrator' );

		[ 'id' => $attr_id ] = $this->createGlobalAttribute( 'Size' );

		$result = wp_get_ability( self::ABILITY )->execute( array( 'id' => $attr_id ) );

		$this->assertIsArray( $result );
		$this->assertSame( self::RESULT_KEYS, array_keys( $result ) );
		$this->assertIsBool( $result['deleted'] );
		$this->assertIsInt( $result['id'] );
		$this->assertIsString( $result['name'] );
		$this->assertIsBool( $result['force_used'] );
		$this->assertIsBool( $result['permanent'] );

		// No dead-end edit link and no raw attribute body leaks.
		$this->assertArrayNotHasKey( 'edit_link', $result );
		$this->assertArrayNotHasKey( 'slug', $result );
		$this->assertArrayNotHasKey( '_links', $result );
	}

	public function test_subscriber_is_denied_and_attribute_survives(): void {
		$this->actingAs( 'administrator' );
		[ 'id' => $attr_id ] = $this->createGlobalAttribute( 'Material' );

		$this->actingAs( 'subscriber' );

		$ability = wp_get_ability( self::ABILITY );

		$this->assertFalse( $ability->check_permissions( array( 'id' => $attr_id ) ) );

		$result = $ability->execute( array( 'id' => $attr_id ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );

		// The attribute survived the denied delete.
		$ids = wp_list_pluck( wc_get_attribute_taxonomies(), 'attribute_id' );
		$this->assertContains( $attr_id, array_map( 'intval', $ids ) );
	}
}
