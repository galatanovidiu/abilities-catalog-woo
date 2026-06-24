<?php
/**
 * Integration tests for the wc-coupons/create-coupon ability.
 *
 * @package AbilitiesCatalogWoo\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogWoo\Tests\Integration\Abilities\Coupons;

use GalatanOvidiu\AbilitiesCatalogWoo\Tests\TestCase;
use WP_Error;

/**
 * Exercises wc-coupons/create-coupon: the shaped created-coupon record with a real
 * id, an honored field, the wrong-capability denial, the bad-enum 400 surfaced via
 * RestError (not a permission collapse), and the exact closed output shape.
 */
final class CreateCouponTest extends TestCase {

	/**
	 * The full closed key set the ability returns for a created coupon.
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

	public function test_ability_is_registered(): void {
		$ability = wp_get_ability( 'wc-coupons/create-coupon' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'wc-coupons/create-coupon', $ability->get_name() );
	}

	public function test_admin_creates_coupon(): void {
		$this->enableCoupons();
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'wc-coupons/create-coupon' )->execute(
			array(
				'code'          => 'save10',
				'discount_type' => 'percent',
				'amount'        => '10',
			)
		);

		$this->assertIsArray( $result );
		$this->assertGreaterThan( 0, $result['id'] );
		$this->assertSame( 'save10', $result['code'] );
		$this->assertSame( 'percent', $result['discount_type'] );
		$this->assertStringContainsString( 'post=' . $result['id'], $result['edit_link'] );
		$this->assertStringContainsString( 'action=edit', $result['edit_link'] );

		// The coupon was really persisted.
		$this->assertGreaterThan( 0, wc_get_coupon_id_by_code( 'save10' ) );
	}

	public function test_field_is_honored(): void {
		$this->enableCoupons();
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'wc-coupons/create-coupon' )->execute(
			array(
				'code'        => 'desc15',
				'amount'      => '15',
				'description' => 'Loyalty reward.',
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'Loyalty reward.', $result['description'] );
		// Default discount type when omitted.
		$this->assertSame( 'fixed_cart', $result['discount_type'] );
	}

	public function test_output_shape_is_exact_and_closed(): void {
		$this->enableCoupons();
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'wc-coupons/create-coupon' )->execute(
			array(
				'code'   => 'shape20',
				'amount' => '20',
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
		$this->assertIsArray( $result['product_ids'] );
		$this->assertIsArray( $result['exclude_product_ids'] );
		$this->assertIsString( $result['edit_link'] );
	}

	public function test_bad_discount_type_is_rejected_not_permission_error(): void {
		$this->enableCoupons();
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'wc-coupons/create-coupon' )->execute(
			array(
				'code'          => 'bogus',
				'discount_type' => 'not_a_type',
			)
		);

		// `discount_type` has an enum in the input schema, so the Abilities API
		// rejects the bad value during input validation as `ability_invalid_input`
		// (no status) before execute() dispatches; if the wrapper ever stops
		// enforcing the enum, the wrapped route rejects it as `rest_invalid_param`
		// (400). Either way it is a specific input rejection, never collapsed to a
		// permission error, and nothing is persisted.
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertContains(
			$result->get_error_code(),
			array( 'ability_invalid_input', 'rest_invalid_param' )
		);
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code() );

		// The rejected coupon was not persisted.
		$this->assertSame( 0, wc_get_coupon_id_by_code( 'bogus' ) );
	}

	public function test_subscriber_is_denied(): void {
		$this->enableCoupons();
		$this->actingAs( 'subscriber' );

		$ability = wp_get_ability( 'wc-coupons/create-coupon' );

		$this->assertFalse( $ability->check_permissions( array( 'code' => 'denied30' ) ) );

		$result = $ability->execute( array( 'code' => 'denied30' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );

		// The coupon was not created.
		$this->assertSame( 0, wc_get_coupon_id_by_code( 'denied30' ) );
	}
}
