<?php
/**
 * Integration tests for the `og-wc-reports/list-coupons-analytics` ability.
 *
 * @package AbilitiesCatalogWoo\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogWoo\Tests\Integration\Abilities\Analytics;

use GalatanOvidiu\AbilitiesCatalogWoo\Support\WooPlugin;
use GalatanOvidiu\AbilitiesCatalogWoo\Tests\TestCase;
use WC_Coupon;
use WC_Order;
use WC_Product_Simple;

/**
 * @covers \GalatanOvidiu\AbilitiesCatalogWoo\Abilities\Woo\Analytics\ListCouponsAnalytics
 */
final class ListCouponsAnalyticsTest extends TestCase {

	private const ABILITY = 'og-wc-reports/list-coupons-analytics';

	/**
	 * The closed set of row keys the ability returns.
	 *
	 * @var array<int,string>
	 */
	private const ROW_KEYS = array( 'coupon_id', 'amount', 'orders_count', 'code', 'discount_type' );

	/**
	 * Skips the whole class cleanly when WooCommerce Analytics is off.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		if ( ! WooPlugin::hasAnalytics() ) {
			$this->markTestSkipped( 'WooCommerce Analytics feature is not enabled.' );
		}

		$this->ensureAnalyticsRoutesRegistered();

		$this->actingAs( 'administrator' );
	}

	/**
	 * Creates a completed order that used a coupon and syncs the analytics lookup tables.
	 *
	 * `OrdersScheduler::import()` runs the same data-store syncs the async scheduler
	 * would (including `CouponsDataStore::sync_order_coupons`), synchronously, so the
	 * coupon appears in the report immediately.
	 *
	 * @param string $code The coupon code. Must be unique across coupons seeded in one test.
	 * @return int The seeded coupon ID.
	 */
	private function seedCompletedOrderWithCoupon( string $code = 'save10' ): int {
		$coupon = new WC_Coupon();
		$coupon->set_code( $code );
		$coupon->set_discount_type( 'fixed_cart' );
		$coupon->set_amount( 5 );
		$coupon->save();

		$product = new WC_Product_Simple();
		$product->set_name( 'Seeded Coupon Product' );
		$product->set_sku( 'SEED-COUPON-' . $code );
		$product->set_status( 'publish' );
		$product->set_regular_price( '20.00' );
		$product->save();

		$order = new WC_Order();
		$order->add_product( wc_get_product( $product->get_id() ), 1 );
		$order->apply_coupon( $code );
		$order->set_status( 'completed' );
		$order->calculate_totals();
		$order->save();

		\Automattic\WooCommerce\Internal\Admin\Schedulers\OrdersScheduler::import( $order->get_id() );

		return (int) $coupon->get_id();
	}

	/**
	 * The ability registers and resolves to its name.
	 */
	public function test_ability_is_registered(): void {
		$ability = wp_get_ability( self::ABILITY );

		$this->assertNotNull( $ability );
		$this->assertSame( self::ABILITY, $ability->get_name() );
	}

	/**
	 * Happy path: the seeded coupon appears with shaped fields and a sane total.
	 */
	public function test_execute_returns_shaped_rows(): void {
		$coupon_id = $this->seedCompletedOrderWithCoupon();

		$result = wp_get_ability( self::ABILITY )->execute( array() );

		$this->assertIsArray( $result );
		$this->assertSame( array( 'items', 'total' ), array_keys( $result ) );
		$this->assertIsArray( $result['items'] );
		$this->assertIsInt( $result['total'] );

		$match = null;
		foreach ( $result['items'] as $row ) {
			if ( (int) $row['coupon_id'] === $coupon_id ) {
				$match = $row;
				break;
			}
		}

		$this->assertNotNull( $match, 'Seeded coupon did not appear in the analytics report.' );
		$this->assertSame( self::ROW_KEYS, array_keys( $match ) );
		$this->assertIsInt( $match['coupon_id'] );
		$this->assertIsFloat( $match['amount'] );
		$this->assertIsInt( $match['orders_count'] );
		$this->assertSame( 'save10', $match['code'] );
		$this->assertSame( 'fixed_cart', $match['discount_type'] );
	}

	/**
	 * The output never leaks the raw extended_info block or other raw row fields.
	 */
	public function test_output_drops_extended_info(): void {
		$this->seedCompletedOrderWithCoupon();

		$result = wp_get_ability( self::ABILITY )->execute( array() );

		$this->assertNotEmpty( $result['items'] );
		foreach ( $result['items'] as $row ) {
			$this->assertSame( self::ROW_KEYS, array_keys( $row ) );
			$this->assertArrayNotHasKey( 'extended_info', $row );
			$this->assertArrayNotHasKey( '_links', $row );
			$this->assertArrayNotHasKey( 'segments', $row );
			$this->assertArrayNotHasKey( 'date_created', $row );
		}
	}

	/**
	 * An optional orderby filter is accepted without erroring.
	 */
	public function test_orderby_filter_accepted(): void {
		$this->seedCompletedOrderWithCoupon();

		$result = wp_get_ability( self::ABILITY )->execute( array( 'orderby' => 'amount' ) );

		$this->assertIsArray( $result );
		$this->assertSame( array( 'items', 'total' ), array_keys( $result ) );
		$this->assertIsArray( $result['items'] );
	}

	/**
	 * A future date range narrows out all rows (range filter is applied).
	 */
	public function test_after_filter_narrows_results(): void {
		$this->seedCompletedOrderWithCoupon();

		$result = wp_get_ability( self::ABILITY )->execute(
			array( 'after' => gmdate( 'Y-m-d\TH:i:s', time() + DAY_IN_SECONDS ) )
		);

		$this->assertIsArray( $result );
		$this->assertSame( array( 'items', 'total' ), array_keys( $result ) );
		$this->assertSame( array(), $result['items'] );
	}

	/**
	 * A subscriber lacks view_woocommerce_reports, so permission is denied.
	 */
	public function test_subscriber_is_denied(): void {
		$this->actingAs( 'subscriber' );

		$ability = wp_get_ability( self::ABILITY );

		$this->assertFalse( $ability->check_permissions( array() ) );

		$result = $ability->execute( array() );
		$this->assertWPError( $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}
}
