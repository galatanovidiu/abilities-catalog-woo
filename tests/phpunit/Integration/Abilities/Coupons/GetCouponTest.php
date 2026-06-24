<?php
/**
 * Integration tests for the wc-coupons/get-coupon ability.
 *
 * @package AbilitiesCatalogWoo\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogWoo\Tests\Integration\Abilities\Coupons;

use GalatanOvidiu\AbilitiesCatalogWoo\Tests\TestCase;
use WC_Coupon;
use WP_Error;

/**
 * Exercises wc-coupons/get-coupon: the shaped single-coupon record, the
 * missing-coupon 404 that must not collapse to a permission error, the
 * wrong-capability denial, and the exact closed output shape (flat detail row
 * plus edit_link).
 */
final class GetCouponTest extends TestCase {

	/**
	 * The full closed key set the ability returns for one coupon.
	 *
	 * @var list<string>
	 */
	private const EXPECTED_KEYS = array(
		'id',
		'code',
		'amount',
		'discount_type',
		'date_expires',
		'usage_count',
		'usage_limit',
		'individual_use',
		'description',
		'product_ids',
		'exclude_product_ids',
		'minimum_amount',
		'maximum_amount',
		'edit_link',
	);

	/**
	 * Seeds a coupon via the WooCommerce runtime object (NOT WC_Helper_Coupon,
	 * which the distributed woocommerce.zip does not ship).
	 *
	 * @param string $code The coupon code.
	 * @return int The created coupon ID.
	 */
	private function seedCoupon( string $code = 'save10' ): int {
		$coupon = new WC_Coupon();
		$coupon->set_code( $code );
		$coupon->set_amount( 10 );
		$coupon->set_discount_type( 'percent' );
		$coupon->set_description( 'Ten percent off.' );
		$coupon->save();

		return $coupon->get_id();
	}

	public function test_ability_is_registered(): void {
		$ability = wp_get_ability( 'wc-coupons/get-coupon' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'wc-coupons/get-coupon', $ability->get_name() );
	}

	public function test_admin_reads_coupon_detail(): void {
		$this->enableCoupons();
		$this->actingAs( 'administrator' );

		$coupon_id = $this->seedCoupon();

		$result = wp_get_ability( 'wc-coupons/get-coupon' )->execute( array( 'id' => $coupon_id ) );

		$this->assertIsArray( $result );
		$this->assertSame( $coupon_id, $result['id'] );
		$this->assertSame( 'save10', $result['code'] );
		$this->assertSame( 'percent', $result['discount_type'] );
		$this->assertSame( 'Ten percent off.', $result['description'] );
		$this->assertIsString( $result['amount'] );
		$this->assertIsArray( $result['product_ids'] );
		$this->assertIsArray( $result['exclude_product_ids'] );
		$this->assertStringContainsString( 'post=' . $coupon_id, $result['edit_link'] );
		$this->assertStringContainsString( 'action=edit', $result['edit_link'] );
	}

	public function test_output_shape_is_exact_and_closed(): void {
		$this->enableCoupons();
		$this->actingAs( 'administrator' );

		$coupon_id = $this->seedCoupon( 'shape20' );

		$result = wp_get_ability( 'wc-coupons/get-coupon' )->execute( array( 'id' => $coupon_id ) );

		$this->assertIsArray( $result );
		$this->assertSame( self::EXPECTED_KEYS, array_keys( $result ) );

		// No raw coupon body fields leak through.
		$this->assertArrayNotHasKey( '_links', $result );
		$this->assertArrayNotHasKey( 'used_by', $result );
		$this->assertArrayNotHasKey( 'email_restrictions', $result );
		$this->assertArrayNotHasKey( 'meta_data', $result );

		$this->assertIsInt( $result['id'] );
		$this->assertIsString( $result['code'] );
		$this->assertIsString( $result['amount'] );
		$this->assertIsString( $result['discount_type'] );
		$this->assertIsInt( $result['usage_count'] );
		$this->assertIsBool( $result['individual_use'] );
		$this->assertIsString( $result['description'] );
		$this->assertIsString( $result['minimum_amount'] );
		$this->assertIsString( $result['maximum_amount'] );
		$this->assertIsString( $result['edit_link'] );
	}

	public function test_missing_coupon_returns_404_not_permission_error(): void {
		$this->enableCoupons();
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'wc-coupons/get-coupon' )->execute( array( 'id' => 99999999 ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'woocommerce_rest_shop_coupon_invalid_id', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] );
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_subscriber_is_denied(): void {
		$this->enableCoupons();
		$coupon_id = $this->seedCoupon( 'denied30' );

		$this->actingAs( 'subscriber' );

		$ability = wp_get_ability( 'wc-coupons/get-coupon' );

		$this->assertFalse( $ability->check_permissions( array( 'id' => $coupon_id ) ) );

		$result = $ability->execute( array( 'id' => $coupon_id ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}
}
