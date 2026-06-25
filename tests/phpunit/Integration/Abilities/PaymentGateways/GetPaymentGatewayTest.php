<?php
/**
 * Integration tests for the og-wc-payment-gateways/get-payment-gateway ability.
 *
 * @package AbilitiesCatalogWoo\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogWoo\Tests\Integration\Abilities\PaymentGateways;

use GalatanOvidiu\AbilitiesCatalogWoo\Support\PaymentGatewayShaper;
use GalatanOvidiu\AbilitiesCatalogWoo\Tests\TestCase;
use WP_Error;

/**
 * Exercises og-wc-payment-gateways/get-payment-gateway: the shaped single-gateway
 * record, the redaction of a password-type settings field (the raw secret must
 * never appear), the missing-gateway 404 that must not collapse to a permission
 * error, the wrong-capability denial, and the exact closed output shape.
 */
final class GetPaymentGatewayTest extends TestCase {

	/**
	 * The full closed key set the ability returns for one gateway.
	 *
	 * @var list<string>
	 */
	private const EXPECTED_KEYS = array(
		'id',
		'title',
		'description',
		'enabled',
		'method_title',
		'order',
		'method_description',
		'settings',
	);

	/**
	 * The raw secret seeded into a gateway's settings; it must never reach output.
	 *
	 * @var string
	 */
	private const RAW_SECRET = 'super-secret-api-key-1234567890';

	public function test_ability_is_registered(): void {
		$ability = wp_get_ability( 'og-wc-payment-gateways/get-payment-gateway' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'og-wc-payment-gateways/get-payment-gateway', $ability->get_name() );
	}

	public function test_admin_reads_gateway_detail(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'og-wc-payment-gateways/get-payment-gateway' )->execute( array( 'id' => 'bacs' ) );

		$this->assertIsArray( $result );
		$this->assertSame( 'bacs', $result['id'] );
		$this->assertIsString( $result['title'] );
		$this->assertIsBool( $result['enabled'] );
		$this->assertIsString( $result['method_title'] );
		$this->assertIsString( $result['method_description'] );
		$this->assertIsArray( $result['settings'] );
		$this->assertNotEmpty( $result['settings'] );
	}

	public function test_password_field_value_is_redacted(): void {
		$this->actingAs( 'administrator' );

		// Inject a password-type settings field carrying a raw secret value into the
		// gateway's REST response, so the shaper receives a present credential to mask.
		$secret = self::RAW_SECRET;
		add_filter(
			'woocommerce_rest_prepare_payment_gateway',
			static function ( $response ) use ( $secret ) {
				$data                         = $response->get_data();
				$data['settings']['api_secret'] = array(
					'id'    => 'api_secret',
					'label' => 'API Secret',
					'type'  => 'password',
					'value' => $secret,
				);
				$response->set_data( $data );
				return $response;
			}
		);

		$result = wp_get_ability( 'og-wc-payment-gateways/get-payment-gateway' )->execute( array( 'id' => 'bacs' ) );

		$this->assertIsArray( $result );

		$secret_field = null;
		foreach ( $result['settings'] as $field ) {
			if ( 'api_secret' === $field['id'] ) {
				$secret_field = $field;
				break;
			}
		}

		$this->assertNotNull( $secret_field, 'The seeded password field should be present in the settings.' );
		$this->assertSame( PaymentGatewayShaper::REDACTED, $secret_field['value'] );
		$this->assertTrue( $secret_field['has_value'], 'has_value must report the secret as configured.' );

		// The raw secret must not appear anywhere in the output.
		$this->assertStringNotContainsString( self::RAW_SECRET, wp_json_encode( $result ) );
	}

	public function test_non_secret_field_keeps_real_value(): void {
		$this->actingAs( 'administrator' );

		// "Account details" / title-type fields on bacs are non-secret; the title field carries a real value.
		$result = wp_get_ability( 'og-wc-payment-gateways/get-payment-gateway' )->execute( array( 'id' => 'bacs' ) );

		$this->assertIsArray( $result );

		$title_field = null;
		foreach ( $result['settings'] as $field ) {
			if ( 'title' === $field['id'] ) {
				$title_field = $field;
				break;
			}
		}

		$this->assertNotNull( $title_field );
		$this->assertNotSame( PaymentGatewayShaper::REDACTED, $title_field['value'] );
	}

	public function test_output_shape_is_exact_and_closed(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'og-wc-payment-gateways/get-payment-gateway' )->execute( array( 'id' => 'bacs' ) );

		$this->assertIsArray( $result );
		$this->assertSame( self::EXPECTED_KEYS, array_keys( $result ) );

		// No raw REST fields leak through.
		$this->assertArrayNotHasKey( 'method_supports', $result );
		$this->assertArrayNotHasKey( '_links', $result );

		$this->assertIsString( $result['id'] );
		$this->assertIsBool( $result['enabled'] );
		$this->assertIsInt( $result['order'] );

		// Each settings field is the closed { id, label, type, value, has_value } shape.
		foreach ( $result['settings'] as $field ) {
			$this->assertSame(
				array( 'id', 'label', 'type', 'value', 'has_value' ),
				array_keys( $field )
			);
			$this->assertIsString( $field['value'] );
			$this->assertIsBool( $field['has_value'] );
		}
	}

	public function test_missing_gateway_returns_404_not_permission_error(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'og-wc-payment-gateways/get-payment-gateway' )->execute( array( 'id' => 'does-not-exist' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'woocommerce_rest_payment_gateway_invalid', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] );
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_subscriber_is_denied(): void {
		$this->actingAs( 'subscriber' );

		$ability = wp_get_ability( 'og-wc-payment-gateways/get-payment-gateway' );

		$this->assertFalse( $ability->check_permissions( array( 'id' => 'bacs' ) ) );

		$result = $ability->execute( array( 'id' => 'bacs' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}
}
