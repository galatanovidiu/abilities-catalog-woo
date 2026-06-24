<?php
/**
 * Integration tests for the `wc-products/list-products` ability.
 *
 * @package AbilitiesCatalogWoo\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogWoo\Tests\Integration\Abilities\Products;

use GalatanOvidiu\AbilitiesCatalogWoo\Tests\TestCase;
use WC_Product_Simple;
use WP_Error;

/**
 * @covers \GalatanOvidiu\AbilitiesCatalogWoo\Abilities\Woo\Products\ListProducts
 */
final class ListProductsTest extends TestCase {

	private const ABILITY = 'wc-products/list-products';

	/**
	 * The exact keys a shaped product summary row exposes.
	 *
	 * Asserting against this fixed set proves the raw ~120-field product body is
	 * never leaked: only these projected fields reach the consumer.
	 *
	 * @var list<string>
	 */
	private const ROW_KEYS = array(
		'id',
		'name',
		'type',
		'status',
		'sku',
		'price',
		'regular_price',
		'sale_price',
		'stock_status',
		'stock_quantity',
		'catalog_visibility',
		'permalink',
		'date_created',
		'edit_link',
	);

	public function test_registered(): void {
		$ability = wp_get_ability( self::ABILITY );

		$this->assertNotNull( $ability );
		$this->assertSame( self::ABILITY, $ability->get_name() );
	}

	public function test_happy_path_returns_shaped_rows(): void {
		$this->actingAs( 'administrator' );
		$this->seedSimpleProduct();
		$this->seedSimpleProduct();

		$result = wp_get_ability( self::ABILITY )->execute( array() );

		$this->assertIsArray( $result );
		$this->assertSame( array( 'items', 'total' ), array_keys( $result ) );
		$this->assertIsArray( $result['items'] );
		$this->assertIsInt( $result['total'] );
		$this->assertGreaterThanOrEqual( 2, $result['total'] );
		$this->assertNotEmpty( $result['items'] );

		$row = $result['items'][0];
		$this->assertSame( self::ROW_KEYS, array_keys( $row ) );
		$this->assertIsInt( $row['id'] );
		$this->assertIsString( $row['name'] );
		$this->assertIsString( $row['price'] );
		$this->assertStringContainsString( 'post.php?post=' . $row['id'], $row['edit_link'] );
	}

	public function test_search_narrows_results(): void {
		$this->actingAs( 'administrator' );

		$wanted = $this->seedSimpleProduct();
		$wanted->set_name( 'Galatan Test Widget' );
		$wanted->save();

		$other = $this->seedSimpleProduct();
		$other->set_name( 'Unrelated Gadget' );
		$other->save();

		$result = wp_get_ability( self::ABILITY )->execute( array( 'search' => 'Galatan Test Widget' ) );

		$names = wp_list_pluck( $result['items'], 'name' );
		$this->assertContains( 'Galatan Test Widget', $names );
		$this->assertNotContains( 'Unrelated Gadget', $names );
	}

	public function test_status_filter_excludes_other_statuses(): void {
		$this->actingAs( 'administrator' );

		$draft = $this->seedSimpleProduct();
		$draft->set_status( 'draft' );
		$draft->save();

		$published = $this->seedSimpleProduct();
		$published->set_status( 'publish' );
		$published->save();

		$result = wp_get_ability( self::ABILITY )->execute( array( 'status' => 'draft' ) );

		$ids = wp_list_pluck( $result['items'], 'id' );
		$this->assertContains( $draft->get_id(), $ids );
		$this->assertNotContains( $published->get_id(), $ids );

		foreach ( $result['items'] as $row ) {
			$this->assertSame( 'draft', $row['status'] );
		}
	}

	public function test_total_comes_from_the_pagination_header(): void {
		$this->actingAs( 'administrator' );

		for ( $i = 0; $i < 3; $i++ ) {
			$this->seedSimpleProduct();
		}

		$result = wp_get_ability( self::ABILITY )->execute( array( 'per_page' => 1 ) );

		// One row on the page, but total reflects the full matching set via X-WP-Total.
		$this->assertCount( 1, $result['items'] );
		$this->assertGreaterThanOrEqual( 3, $result['total'] );
	}

	public function test_output_shape_has_no_raw_product_fields(): void {
		$this->actingAs( 'administrator' );
		$this->seedSimpleProduct();

		$result = wp_get_ability( self::ABILITY )->execute( array() );

		$this->assertSame( array( 'items', 'total' ), array_keys( $result ) );
		$row = $result['items'][0];
		$this->assertSame( self::ROW_KEYS, array_keys( $row ) );
		$this->assertArrayNotHasKey( 'meta_data', $row );
		$this->assertArrayNotHasKey( 'images', $row );
		$this->assertArrayNotHasKey( 'description', $row );
		$this->assertArrayNotHasKey( '_links', $row );
	}

	public function test_wrong_capability_is_denied(): void {
		$this->seedSimpleProduct();
		$this->actingAs( 'subscriber' );

		$result = wp_get_ability( self::ABILITY )->execute( array() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_logged_out_user_is_denied(): void {
		$this->seedSimpleProduct();
		wp_set_current_user( 0 );

		$result = wp_get_ability( self::ABILITY )->execute( array() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	/**
	 * Seeds a published simple product and returns the product object.
	 *
	 * Builds the product with WooCommerce's runtime object API (WC_Product_Simple)
	 * rather than the WC_Helper_Product test factory, because the test environment
	 * mounts the distributed WooCommerce build, which ships no tests/ helper
	 * framework. Matches the seeding idiom of the sibling product tests. The object
	 * is returned so callers can mutate it (set_name/set_status) and re-save.
	 *
	 * A name is set because WordPress's wp_update_post() will not transition a
	 * product out of "publish" to "draft" when both its title and content are
	 * empty, which the status-filter test relies on.
	 *
	 * @return WC_Product_Simple The created simple product.
	 */
	private function seedSimpleProduct(): WC_Product_Simple {
		$product = new WC_Product_Simple();
		$product->set_name( 'Seeded Product' );
		$product->set_status( 'publish' );
		$product->set_regular_price( '10.00' );
		$product->save();

		return $product;
	}
}
