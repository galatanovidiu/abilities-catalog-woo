<?php
/**
 * Integration tests for the `wc-reports/list-stock-analytics` ability.
 *
 * @package AbilitiesCatalogWoo\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogWoo\Tests\Integration\Abilities\Analytics;

use GalatanOvidiu\AbilitiesCatalogWoo\Support\WooPlugin;
use GalatanOvidiu\AbilitiesCatalogWoo\Tests\TestCase;
use WC_Product_Simple;
use WP_Error;

/**
 * @covers \GalatanOvidiu\AbilitiesCatalogWoo\Abilities\Woo\Analytics\ListStockAnalytics
 */
final class ListStockAnalyticsTest extends TestCase {

	private const ABILITY = 'wc-reports/list-stock-analytics';

	/**
	 * The closed set of row keys the ability returns.
	 *
	 * @var array<int,string>
	 */
	private const ROW_KEYS = array( 'id', 'parent_id', 'name', 'sku', 'stock_status', 'stock_quantity', 'manage_stock' );

	/**
	 * Skips the test cleanly when the Analytics feature is off, then ensures the
	 * lazy `wc-analytics` routes are registered for this request.
	 *
	 * The ability is conditional on {@see WooPlugin::hasAnalytics()}; when the
	 * feature is off it does not register, so every behavioral assertion is moot.
	 * The `wc-analytics` namespace is a lazy one-shot that the test harness's
	 * per-test REST-server reset wipes, so {@see TestCase::ensureAnalyticsRoutesRegistered()}
	 * must run before any dispatch or the 2nd+ test would 404 with `rest_no_route`.
	 *
	 * @return void
	 */
	private function requireAnalytics(): void {
		if ( ! WooPlugin::hasAnalytics() ) {
			$this->markTestSkipped( 'WooCommerce Analytics feature is not enabled.' );
		}

		$this->ensureAnalyticsRoutesRegistered();
	}

	/**
	 * Seeds one published product that manages stock at a known quantity.
	 *
	 * The stock report reads the `wc_product_meta_lookup` table, kept in sync as a
	 * product is saved, so no order or async analytics sync is needed for the row to
	 * appear. Uses WooCommerce's runtime object API (the distributed wp-env zip ships
	 * no `WC_Helper_*` factories).
	 *
	 * @param string $sku The product SKU. Must be unique across products seeded in one test.
	 * @param int    $qty The managed stock quantity.
	 * @return int The seeded product ID.
	 */
	private function seedManagedStockProduct( string $sku = 'SEED-STOCK-1', int $qty = 5 ): int {
		$product = new WC_Product_Simple();
		$product->set_name( 'Seeded Stock Product ' . $sku );
		$product->set_sku( $sku );
		$product->set_status( 'publish' );
		$product->set_regular_price( '10.00' );
		$product->set_manage_stock( true );
		$product->set_stock_quantity( $qty );
		$product->save();

		return (int) $product->get_id();
	}

	/**
	 * Finds the shaped row for a given product ID in the result items.
	 *
	 * @param array<string,mixed> $result     The ability result.
	 * @param int                 $product_id The product ID to find.
	 * @return array<string,mixed>|null The matching row, or null.
	 */
	private function findRow( array $result, int $product_id ): ?array {
		foreach ( $result['items'] as $row ) {
			if ( (int) $row['id'] === $product_id ) {
				return $row;
			}
		}

		return null;
	}

	/**
	 * The ability registers and resolves to its name.
	 */
	public function test_registered(): void {
		$this->requireAnalytics();

		$ability = wp_get_ability( self::ABILITY );

		$this->assertNotNull( $ability );
		$this->assertSame( self::ABILITY, $ability->get_name() );
	}

	/**
	 * Happy path: a seeded managed-stock product appears with shaped fields.
	 */
	public function test_execute_returns_shaped_rows(): void {
		$this->requireAnalytics();
		$this->actingAs( 'administrator' );

		$product_id = $this->seedManagedStockProduct();

		$result = wp_get_ability( self::ABILITY )->execute( array() );

		$this->assertIsArray( $result );
		$this->assertNotInstanceOf( WP_Error::class, $result );
		$this->assertSame( array( 'items', 'total' ), array_keys( $result ) );
		$this->assertIsArray( $result['items'] );
		$this->assertIsInt( $result['total'] );

		$match = $this->findRow( $result, $product_id );

		$this->assertNotNull( $match, 'Seeded product did not appear in the stock report.' );
		$this->assertSame( self::ROW_KEYS, array_keys( $match ) );
		$this->assertIsInt( $match['id'] );
		$this->assertIsInt( $match['parent_id'] );
		$this->assertIsString( $match['name'] );
		$this->assertIsString( $match['sku'] );
		$this->assertIsString( $match['stock_status'] );
		$this->assertIsInt( $match['stock_quantity'] );
		$this->assertIsBool( $match['manage_stock'] );
		$this->assertSame( 'SEED-STOCK-1', $match['sku'] );
		$this->assertTrue( $match['manage_stock'] );
		$this->assertSame( 5, $match['stock_quantity'] );
	}

	/**
	 * An orderby filter is accepted and does not error.
	 */
	public function test_orderby_filter_is_accepted(): void {
		$this->requireAnalytics();
		$this->actingAs( 'administrator' );

		$this->seedManagedStockProduct();

		$result = wp_get_ability( self::ABILITY )->execute( array( 'orderby' => 'stock_quantity' ) );

		$this->assertIsArray( $result );
		$this->assertNotInstanceOf( WP_Error::class, $result );
		$this->assertSame( array( 'items', 'total' ), array_keys( $result ) );
	}

	/**
	 * A per_page filter narrows the returned rows without erroring.
	 */
	public function test_per_page_filter_narrows_results(): void {
		$this->requireAnalytics();
		$this->actingAs( 'administrator' );

		$this->seedManagedStockProduct( 'SEED-STOCK-1' );
		$this->seedManagedStockProduct( 'SEED-STOCK-2' );

		$result = wp_get_ability( self::ABILITY )->execute( array( 'per_page' => 1 ) );

		$this->assertIsArray( $result );
		$this->assertLessThanOrEqual( 1, count( $result['items'] ) );
	}

	/**
	 * The output rows carry exactly the closed schema keys; no raw analytics fields leak.
	 */
	public function test_output_shape_is_exactly_the_closed_keys(): void {
		$this->requireAnalytics();
		$this->actingAs( 'administrator' );

		$this->seedManagedStockProduct();

		$result = wp_get_ability( self::ABILITY )->execute( array() );

		$this->assertNotEmpty( $result['items'] );
		foreach ( $result['items'] as $row ) {
			$this->assertSame( self::ROW_KEYS, array_keys( $row ) );
			$this->assertArrayNotHasKey( '_links', $row );
			$this->assertArrayNotHasKey( 'low_stock_amount', $row );
			$this->assertArrayNotHasKey( 'extended_info', $row );
		}
	}

	/**
	 * A subscriber lacks view_woocommerce_reports, so permission is denied.
	 */
	public function test_subscriber_is_denied(): void {
		$this->requireAnalytics();
		$this->actingAs( 'subscriber' );

		$ability = wp_get_ability( self::ABILITY );

		$this->assertFalse( $ability->check_permissions( array() ) );

		$result = $ability->execute( array() );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}
}
