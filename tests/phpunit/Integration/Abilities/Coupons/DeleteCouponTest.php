<?php
/**
 * Integration tests for the og-wc-coupons/delete-coupon ability.
 *
 * @package AbilitiesCatalogWoo\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogWoo\Tests\Integration\Abilities\Coupons;

use GalatanOvidiu\AbilitiesCatalogWoo\Tests\TestCase;
use WC_Coupon;
use WP_Error;

/**
 * Exercises og-wc-coupons/delete-coupon: the default Trash delete (permanent: false)
 * and the force permanent delete (permanent: true), the captured coupon code, the
 * missing-coupon 404 that must not collapse to a permission error, the
 * wrong-capability denial, and the exact closed output shape (no edit_link, no raw
 * coupon fields).
 */
final class DeleteCouponTest extends TestCase {

	/**
	 * The full closed key set the ability returns.
	 *
	 * @var list<string>
	 */
	private const EXPECTED_KEYS = array(
		'deleted',
		'id',
		'code',
		'force_used',
		'permanent',
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
		$coupon->save();

		return $coupon->get_id();
	}

	public function test_ability_is_registered(): void {
		$ability = wp_get_ability( 'og-wc-coupons/delete-coupon' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'og-wc-coupons/delete-coupon', $ability->get_name() );
	}

	public function test_default_delete_trashes_coupon_and_captures_code(): void {
		$this->enableCoupons();
		$this->actingAs( 'administrator' );

		// The default-force Trash path needs EMPTY_TRASH_DAYS > 0 (WP default 30).
		// If the env sets it to 0 the route returns woocommerce_rest_trash_not_supported 501.
		if ( ! ( defined( 'EMPTY_TRASH_DAYS' ) && EMPTY_TRASH_DAYS > 0 ) ) {
			$this->markTestSkipped( 'EMPTY_TRASH_DAYS is 0, so the Trash path is unavailable in this env.' );
		}

		$coupon_id = $this->seedCoupon( 'trash10' );

		$result = wp_get_ability( 'og-wc-coupons/delete-coupon' )->execute( array( 'id' => $coupon_id ) );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['deleted'] );
		$this->assertSame( $coupon_id, $result['id'] );
		$this->assertSame( 'trash10', $result['code'] );
		$this->assertFalse( $result['force_used'] );
		$this->assertFalse( $result['permanent'] );

		// A default delete trashes the coupon; the post row survives in 'trash'.
		clean_post_cache( $coupon_id );
		$post = get_post( $coupon_id );
		$this->assertNotNull( $post );
		$this->assertSame( 'trash', $post->post_status );
	}

	public function test_force_delete_removes_coupon_permanently(): void {
		$this->enableCoupons();
		$this->actingAs( 'administrator' );

		$coupon_id = $this->seedCoupon( 'gone20' );

		$result = wp_get_ability( 'og-wc-coupons/delete-coupon' )->execute(
			array(
				'id'    => $coupon_id,
				'force' => true,
			)
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['deleted'] );
		$this->assertSame( $coupon_id, $result['id'] );
		$this->assertSame( 'gone20', $result['code'] );
		$this->assertTrue( $result['force_used'] );
		$this->assertTrue( $result['permanent'] );

		// A force delete removes the post row entirely.
		clean_post_cache( $coupon_id );
		$this->assertNull( get_post( $coupon_id ) );
	}

	public function test_output_shape_is_exact_and_closed(): void {
		$this->enableCoupons();
		$this->actingAs( 'administrator' );

		$coupon_id = $this->seedCoupon( 'shape30' );

		$result = wp_get_ability( 'og-wc-coupons/delete-coupon' )->execute(
			array(
				'id'    => $coupon_id,
				'force' => true,
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( self::EXPECTED_KEYS, array_keys( $result ) );

		// No dead-end edit link, no raw coupon body fields leak through.
		$this->assertArrayNotHasKey( 'edit_link', $result );
		$this->assertArrayNotHasKey( 'amount', $result );
		$this->assertArrayNotHasKey( 'meta_data', $result );
		$this->assertArrayNotHasKey( '_links', $result );

		$this->assertIsBool( $result['deleted'] );
		$this->assertIsInt( $result['id'] );
		$this->assertIsString( $result['code'] );
		$this->assertIsBool( $result['force_used'] );
		$this->assertIsBool( $result['permanent'] );
	}

	public function test_missing_coupon_returns_404_not_permission_error(): void {
		$this->enableCoupons();
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'og-wc-coupons/delete-coupon' )->execute( array( 'id' => 99999999 ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'woocommerce_rest_shop_coupon_invalid_id', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] );
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_subscriber_is_denied_and_coupon_survives(): void {
		$this->enableCoupons();
		$coupon_id = $this->seedCoupon( 'denied40' );

		$this->actingAs( 'subscriber' );

		$ability = wp_get_ability( 'og-wc-coupons/delete-coupon' );

		$this->assertFalse( $ability->check_permissions( array( 'id' => $coupon_id ) ) );

		$result = $ability->execute( array( 'id' => $coupon_id ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );

		// The denied caller did not delete the coupon.
		clean_post_cache( $coupon_id );
		$this->assertNotNull( get_post( $coupon_id ) );
	}
}
