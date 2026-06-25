<?php
/**
 * Integration tests for the og-wc-coupons/update-coupon ability.
 *
 * @package AbilitiesCatalogWoo\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogWoo\Tests\Integration\Abilities\Coupons;

use GalatanOvidiu\AbilitiesCatalogWoo\Tests\TestCase;
use WC_Coupon;
use WP_Error;

/**
 * Exercises og-wc-coupons/update-coupon: the happy-path field change on a seeded
 * coupon, the missing-coupon 400 that must not collapse to a permission error,
 * the wrong-capability denial, and the exact closed output shape (flat detail
 * row plus edit_link).
 */
final class UpdateCouponTest extends TestCase {

	/**
	 * The full closed key set the ability returns for one updated coupon.
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
		$ability = wp_get_ability( 'og-wc-coupons/update-coupon' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'og-wc-coupons/update-coupon', $ability->get_name() );
	}

	public function test_admin_updates_coupon_amount(): void {
		$this->enableCoupons();
		$this->actingAs( 'administrator' );

		$coupon_id = $this->seedCoupon();

		$result = wp_get_ability( 'og-wc-coupons/update-coupon' )->execute(
			array(
				'id'     => $coupon_id,
				'amount' => '25',
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( $coupon_id, $result['id'] );
		$this->assertSame( '25.00', $result['amount'] );

		// The change is persisted, not only reflected in the response.
		// WC_Coupon::get_amount() returns the raw stored decimal via
		// wc_format_decimal() with no decimal-places argument ('25'), unlike the
		// REST output which formats to wc_get_price_decimals() ('25.00').
		$reloaded = new WC_Coupon( $coupon_id );
		$this->assertSame( '25', $reloaded->get_amount( 'edit' ) );
	}

	public function test_admin_changes_discount_type(): void {
		$this->enableCoupons();
		$this->actingAs( 'administrator' );

		$coupon_id = $this->seedCoupon( 'flat15' );

		$result = wp_get_ability( 'og-wc-coupons/update-coupon' )->execute(
			array(
				'id'            => $coupon_id,
				'discount_type' => 'fixed_cart',
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'fixed_cart', $result['discount_type'] );
	}

	public function test_output_shape_is_exact_and_closed(): void {
		$this->enableCoupons();
		$this->actingAs( 'administrator' );

		$coupon_id = $this->seedCoupon( 'shape20' );

		$result = wp_get_ability( 'og-wc-coupons/update-coupon' )->execute(
			array(
				'id'          => $coupon_id,
				'description' => 'Updated description.',
			)
		);

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
		$this->assertSame( 'Updated description.', $result['description'] );
		$this->assertIsArray( $result['product_ids'] );
		$this->assertIsArray( $result['exclude_product_ids'] );
		$this->assertStringContainsString( 'post=' . $coupon_id, $result['edit_link'] );
		$this->assertStringContainsString( 'action=edit', $result['edit_link'] );
	}

	public function test_missing_coupon_returns_400_not_permission_error(): void {
		$this->enableCoupons();
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'og-wc-coupons/update-coupon' )->execute(
			array(
				'id'     => 99999999,
				'amount' => '5',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'woocommerce_rest_shop_coupon_invalid_id', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] );
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_invalid_discount_type_is_rejected_before_mutation(): void {
		$this->enableCoupons();
		$this->actingAs( 'administrator' );

		$coupon_id = $this->seedCoupon( 'badtype40' );

		// `discount_type` is an enum in the input schema, so a bad value is
		// rejected by the Abilities API input validation (ability_invalid_input,
		// which carries no status) before execute() ever dispatches the request.
		$result = wp_get_ability( 'og-wc-coupons/update-coupon' )->execute(
			array(
				'id'            => $coupon_id,
				'discount_type' => 'not_a_real_type',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_input', $result->get_error_code() );
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code() );

		// The coupon survives the rejected update unchanged.
		$reloaded = new WC_Coupon( $coupon_id );
		$this->assertSame( 'percent', $reloaded->get_discount_type() );
	}

	public function test_subscriber_is_denied(): void {
		$this->enableCoupons();
		$coupon_id = $this->seedCoupon( 'denied30' );

		$this->actingAs( 'subscriber' );

		$ability = wp_get_ability( 'og-wc-coupons/update-coupon' );

		$this->assertFalse( $ability->check_permissions( array( 'id' => $coupon_id ) ) );

		$result = $ability->execute(
			array(
				'id'     => $coupon_id,
				'amount' => '99',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );

		// The coupon survives the denied update unchanged. WC_Coupon::get_amount()
		// returns the raw stored decimal ('10'), not the REST-formatted '10.00'.
		$reloaded = new WC_Coupon( $coupon_id );
		$this->assertSame( '10', $reloaded->get_amount( 'edit' ) );
	}
}
