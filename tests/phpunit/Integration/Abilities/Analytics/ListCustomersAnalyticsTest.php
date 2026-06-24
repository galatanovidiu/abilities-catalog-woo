<?php
/**
 * Integration tests for the `wc-reports/list-customers-analytics` ability.
 *
 * @package AbilitiesCatalogWoo\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogWoo\Tests\Integration\Abilities\Analytics;

use Automattic\WooCommerce\Internal\Admin\Schedulers\OrdersScheduler;
use GalatanOvidiu\AbilitiesCatalogWoo\Support\WooPlugin;
use GalatanOvidiu\AbilitiesCatalogWoo\Tests\TestCase;
use WC_Order;
use WC_Product_Simple;
use WP_Error;

/**
 * @covers \GalatanOvidiu\AbilitiesCatalogWoo\Abilities\Woo\Analytics\ListCustomersAnalytics
 */
final class ListCustomersAnalyticsTest extends TestCase {

	private const ABILITY = 'wc-reports/list-customers-analytics';

	/**
	 * The closed set of row keys the ability returns.
	 *
	 * Asserting against this fixed set proves the `_gmt` duplicate dates,
	 * `first_name`/`last_name`, and any raw `_links` never leak — only this PII
	 * subset reaches the consumer.
	 *
	 * @var array<int,string>
	 */
	private const ROW_KEYS = array(
		'id',
		'user_id',
		'name',
		'username',
		'email',
		'country',
		'city',
		'state',
		'postcode',
		'date_registered',
		'date_last_active',
		'orders_count',
		'total_spend',
		'avg_order_value',
	);

	/**
	 * Skips the whole class cleanly when WooCommerce Analytics is off, then
	 * forces the lazy `wc-analytics` namespace to register for this test.
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
	 * The ability registers and resolves to its name.
	 */
	public function test_ability_is_registered(): void {
		$ability = wp_get_ability( self::ABILITY );

		$this->assertNotNull( $ability );
		$this->assertSame( self::ABILITY, $ability->get_name() );
	}

	/**
	 * Happy path: a seeded customer appears with the closed PII row shape.
	 */
	public function test_execute_returns_shaped_rows(): void {
		$email = 'ada-customers-analytics@example.org';
		$this->seedCompletedOrderForCustomer( $email );

		$result = wp_get_ability( self::ABILITY )->execute( array() );

		$this->assertIsArray( $result );
		$this->assertSame( array( 'items', 'total' ), array_keys( $result ) );
		$this->assertIsArray( $result['items'] );
		$this->assertIsInt( $result['total'] );

		$match = null;
		foreach ( $result['items'] as $row ) {
			if ( $email === $row['email'] ) {
				$match = $row;
				break;
			}
		}

		$this->assertNotNull( $match, 'Seeded customer did not appear in the analytics report.' );
		$this->assertSame( self::ROW_KEYS, array_keys( $match ) );
		$this->assertIsInt( $match['id'] );
		$this->assertIsInt( $match['user_id'] );
		$this->assertIsString( $match['name'] );
		$this->assertIsInt( $match['orders_count'] );
		$this->assertIsFloat( $match['total_spend'] );
		$this->assertIsFloat( $match['avg_order_value'] );
		$this->assertSame( $email, $match['email'] );
	}

	/**
	 * The output never leaks the `_gmt` duplicate dates, name parts, or raw row fields.
	 */
	public function test_output_drops_gmt_and_name_parts(): void {
		$this->seedCompletedOrderForCustomer( 'leak-check@example.org' );

		$result = wp_get_ability( self::ABILITY )->execute( array() );

		$this->assertNotEmpty( $result['items'] );
		foreach ( $result['items'] as $row ) {
			$this->assertSame( self::ROW_KEYS, array_keys( $row ) );
			$this->assertArrayNotHasKey( 'date_registered_gmt', $row );
			$this->assertArrayNotHasKey( 'date_last_active_gmt', $row );
			$this->assertArrayNotHasKey( 'first_name', $row );
			$this->assertArrayNotHasKey( 'last_name', $row );
			$this->assertArrayNotHasKey( '_links', $row );
		}
	}

	/**
	 * An orderby filter is accepted and the call still returns the closed shape.
	 */
	public function test_orderby_filter_accepted(): void {
		$this->seedCompletedOrderForCustomer( 'orderby-filter@example.org' );

		$result = wp_get_ability( self::ABILITY )->execute( array( 'orderby' => 'total_spend' ) );

		$this->assertIsArray( $result );
		$this->assertSame( array( 'items', 'total' ), array_keys( $result ) );
		$this->assertIsArray( $result['items'] );
		$this->assertIsInt( $result['total'] );
	}

	/**
	 * A per_page filter narrows the returned rows without erroring.
	 */
	public function test_per_page_filter_narrows_results(): void {
		$this->seedCompletedOrderForCustomer( 'one-customers-analytics@example.org' );
		$this->seedCompletedOrderForCustomer( 'two-customers-analytics@example.org' );

		$result = wp_get_ability( self::ABILITY )->execute( array( 'per_page' => 1 ) );

		$this->assertIsArray( $result );
		$this->assertLessThanOrEqual( 1, count( $result['items'] ) );
	}

	/**
	 * A subscriber lacks view_woocommerce_reports, so permission is denied and no
	 * data is returned.
	 */
	public function test_subscriber_is_denied(): void {
		$this->actingAs( 'subscriber' );

		$ability = wp_get_ability( self::ABILITY );

		$this->assertFalse( $ability->check_permissions( array() ) );

		$result = $ability->execute( array() );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	/**
	 * Seeds a completed order for a guest customer with the given billing email and
	 * syncs it into the analytics lookup tables synchronously.
	 *
	 * `OrdersScheduler::import()` runs the same data-store syncs the async scheduler
	 * would — including `CustomersDataStore::sync_order_customer` — synchronously, so
	 * the customer appears in the report immediately. Uses the WooCommerce runtime
	 * object API, not the WC_Helper_* factories (absent from the distributed build).
	 *
	 * @param string $email The billing email; the customer lookup keys off it.
	 * @return int The seeded order ID.
	 */
	private function seedCompletedOrderForCustomer( string $email ): int {
		$product = new WC_Product_Simple();
		$product->set_name( 'Seeded Customers Product' );
		$product->set_sku( 'SEED-CUST-' . md5( $email ) );
		$product->set_status( 'publish' );
		$product->set_regular_price( '10.00' );
		$product->save();

		$order = new WC_Order();
		$order->add_product( wc_get_product( $product->get_id() ), 2 );
		$order->set_address(
			array(
				'first_name' => 'Ada',
				'last_name'  => 'Lovelace',
				'email'      => $email,
				'address_1'  => '1 Test St',
				'city'       => 'Testville',
				'state'      => 'CA',
				'postcode'   => '90210',
				'country'    => 'US',
			),
			'billing'
		);
		$order->set_status( 'completed' );
		$order->calculate_totals();
		$order->save();

		OrdersScheduler::import( $order->get_id() );

		return (int) $order->get_id();
	}
}
