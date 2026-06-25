<?php
/**
 * Integration tests for the og-wc-coupons/list-coupons ability.
 *
 * @package AbilitiesCatalogWoo\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogWoo\Tests\Integration\Abilities\Coupons;

use GalatanOvidiu\AbilitiesCatalogWoo\Support\WooPlugin;
use GalatanOvidiu\AbilitiesCatalogWoo\Tests\TestCase;
use WC_Coupon;

/**
 * Exercises og-wc-coupons/list-coupons: the shaped summary rows and total, a code
 * filter narrowing the result, the wrong-capability denial, and the exact closed
 * row shape (no raw coupon body fields leak; individual_use is a bool).
 */
final class ListCouponsTest extends TestCase {

	/**
	 * The full closed key set the ability returns for one summary row.
	 *
	 * @var list<string>
	 */
	private const ROW_KEYS = array(
		'id',
		'code',
		'amount',
		'discount_type',
		'date_expires',
		'usage_count',
		'usage_limit',
		'individual_use',
	);

	/**
	 * Seeds a coupon via the WooCommerce runtime object (NOT WC_Helper_Coupon,
	 * which the distributed woocommerce.zip does not ship).
	 *
	 * @param string $code The coupon code.
	 * @return int The created coupon ID.
	 */
	private function seedCoupon( string $code ): int {
		$coupon = new WC_Coupon();
		$coupon->set_code( $code );
		$coupon->set_amount( 10 );
		$coupon->set_discount_type( 'percent' );
		$coupon->save();

		return $coupon->get_id();
	}

	public function test_ability_is_registered(): void {
		$ability = wp_get_ability( 'og-wc-coupons/list-coupons' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'og-wc-coupons/list-coupons', $ability->get_name() );
	}

	/**
	 * The coupon-enabled detector that gates every wc-coupons ability's
	 * isAvailable() must track the `woocommerce_enable_coupons` option exactly — the
	 * same gate WooCommerce uses to register the `shop_coupon` post type. When it is
	 * off the coupon abilities degrade to absent instead of returning a raw 403.
	 */
	public function test_has_coupons_enabled_tracks_the_option(): void {
		update_option( 'woocommerce_enable_coupons', 'no' );
		$this->assertFalse(
			WooPlugin::hasCouponsEnabled(),
			'Coupons disabled: the detector must report the coupon abilities should not register.'
		);

		update_option( 'woocommerce_enable_coupons', 'yes' );
		$this->assertTrue(
			WooPlugin::hasCouponsEnabled(),
			'Coupons enabled: the detector must report the coupon abilities should register.'
		);
	}

	public function test_admin_lists_coupons(): void {
		$this->enableCoupons();
		$this->actingAs( 'administrator' );

		$this->seedCoupon( 'save10' );
		$this->seedCoupon( 'save20' );

		$result = wp_get_ability( 'og-wc-coupons/list-coupons' )->execute( array() );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'items', $result );
		$this->assertArrayHasKey( 'total', $result );
		$this->assertIsArray( $result['items'] );
		$this->assertIsInt( $result['total'] );
		$this->assertGreaterThanOrEqual( 2, $result['total'] );
		$this->assertGreaterThanOrEqual( 2, count( $result['items'] ) );

		$codes = array_column( $result['items'], 'code' );
		$this->assertContains( 'save10', $codes );
		$this->assertContains( 'save20', $codes );
	}

	public function test_code_filter_narrows_results(): void {
		$this->enableCoupons();
		$this->actingAs( 'administrator' );

		$this->seedCoupon( 'keepme' );
		$this->seedCoupon( 'dropme' );

		$result = wp_get_ability( 'og-wc-coupons/list-coupons' )->execute( array( 'code' => 'keepme' ) );

		$this->assertIsArray( $result );
		$this->assertCount( 1, $result['items'] );
		$this->assertSame( 'keepme', $result['items'][0]['code'] );
	}

	public function test_row_shape_is_exact_and_closed(): void {
		$this->enableCoupons();
		$this->actingAs( 'administrator' );

		$this->seedCoupon( 'shape15' );

		$result = wp_get_ability( 'og-wc-coupons/list-coupons' )->execute( array( 'code' => 'shape15' ) );

		$this->assertIsArray( $result );
		$this->assertNotEmpty( $result['items'] );

		$row = $result['items'][0];

		$this->assertSame( self::ROW_KEYS, array_keys( $row ) );

		// No raw coupon body fields leak through.
		$this->assertArrayNotHasKey( '_links', $row );
		$this->assertArrayNotHasKey( 'used_by', $row );
		$this->assertArrayNotHasKey( 'email_restrictions', $row );
		$this->assertArrayNotHasKey( 'meta_data', $row );
		$this->assertArrayNotHasKey( 'description', $row );

		$this->assertIsInt( $row['id'] );
		$this->assertIsString( $row['code'] );
		$this->assertIsString( $row['amount'] );
		$this->assertIsString( $row['discount_type'] );
		$this->assertIsString( $row['date_expires'] );
		$this->assertIsInt( $row['usage_count'] );
		$this->assertTrue( null === $row['usage_limit'] || is_int( $row['usage_limit'] ) );
		$this->assertIsBool( $row['individual_use'] );
	}

	public function test_subscriber_is_denied(): void {
		$this->enableCoupons();
		$this->seedCoupon( 'denied30' );

		$this->actingAs( 'subscriber' );

		$ability = wp_get_ability( 'og-wc-coupons/list-coupons' );

		$this->assertFalse( $ability->check_permissions( array() ) );
	}
}
