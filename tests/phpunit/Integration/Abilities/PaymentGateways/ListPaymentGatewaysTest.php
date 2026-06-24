<?php
/**
 * Integration tests for the `wc-payment-gateways/list-payment-gateways` ability.
 *
 * @package AbilitiesCatalogWoo\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogWoo\Tests\Integration\Abilities\PaymentGateways;

use GalatanOvidiu\AbilitiesCatalogWoo\Support\PaymentGatewayShaper;
use GalatanOvidiu\AbilitiesCatalogWoo\Tests\TestCase;
use WP_Error;

/**
 * @covers \GalatanOvidiu\AbilitiesCatalogWoo\Abilities\Woo\PaymentGateways\ListPaymentGateways
 */
final class ListPaymentGatewaysTest extends TestCase {

	private const ABILITY = 'wc-payment-gateways/list-payment-gateways';

	/**
	 * The exact keys a shaped payment-gateway summary row exposes.
	 *
	 * Asserting against this fixed set proves the raw gateway body is never leaked:
	 * crucially, the `settings` map (which carries stored credentials) and the raw
	 * `method_supports`/`_links` fields never reach the consumer.
	 *
	 * @var list<string>
	 */
	private const ROW_KEYS = array(
		'id',
		'title',
		'description',
		'enabled',
		'method_title',
		'order',
	);

	public function test_registered(): void {
		$ability = wp_get_ability( self::ABILITY );

		$this->assertNotNull( $ability );
		$this->assertSame( self::ABILITY, $ability->get_name() );
	}

	public function test_happy_path_returns_shaped_rows(): void {
		$this->actingAs( 'administrator' );

		// Core installs bacs, cheque, cod, and paypal in the WC test env, so no
		// seeding is needed.
		$result = wp_get_ability( self::ABILITY )->execute( array() );

		$this->assertIsArray( $result );
		$this->assertSame( array( 'items', 'total' ), array_keys( $result ) );
		$this->assertIsArray( $result['items'] );
		$this->assertIsInt( $result['total'] );
		$this->assertNotEmpty( $result['items'] );
		$this->assertCount( $result['total'], $result['items'] );

		$row = $result['items'][0];
		$this->assertSame( self::ROW_KEYS, array_keys( $row ) );
		$this->assertIsString( $row['id'] );
		$this->assertIsString( $row['title'] );
		$this->assertIsString( $row['description'] );
		$this->assertIsBool( $row['enabled'] );
		$this->assertIsString( $row['method_title'] );
		$this->assertIsInt( $row['order'] );

		$ids = wp_list_pluck( $result['items'], 'id' );
		$this->assertContains( 'bacs', $ids );
		$this->assertContains( 'cheque', $ids );
		$this->assertContains( 'cod', $ids );
	}

	public function test_enabled_flag_reflects_gateway_state(): void {
		$this->actingAs( 'administrator' );

		// Enable the bank-transfer (bacs) gateway via its settings option, the same
		// store the REST route reads `enabled` from.
		$settings            = (array) get_option( 'woocommerce_bacs_settings', array() );
		$settings['enabled'] = 'yes';
		update_option( 'woocommerce_bacs_settings', $settings );

		// The gateway instances are built once at boot and cached, reading `enabled`
		// from their settings in the constructor. Re-init so the route's
		// WC()->payment_gateways()->payment_gateways() reflects the option just set.
		WC()->payment_gateways()->init();

		$result = wp_get_ability( self::ABILITY )->execute( array() );

		$rows = array();
		foreach ( $result['items'] as $item ) {
			$rows[ $item['id'] ] = $item;
		}

		$this->assertArrayHasKey( 'bacs', $rows );
		$this->assertTrue( $rows['bacs']['enabled'] );
	}

	public function test_output_shape_has_no_settings_or_credentials(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( self::ABILITY )->execute( array() );

		$this->assertSame( array( 'items', 'total' ), array_keys( $result ) );

		// Prove EVERY row is summary-thin: the exact closed key set, and no
		// settings / credential-bearing field appears anywhere in any list row.
		foreach ( $result['items'] as $row ) {
			$this->assertSame( self::ROW_KEYS, array_keys( $row ) );
			$this->assertArrayNotHasKey( 'settings', $row );
			$this->assertArrayNotHasKey( 'method_supports', $row );
			$this->assertArrayNotHasKey( 'method_description', $row );
			$this->assertArrayNotHasKey( '_links', $row );
		}

		// The redaction marker only belongs in the detail (get) view; it must never
		// surface in a list response, because a list row carries no settings at all.
		$this->assertStringNotContainsString(
			PaymentGatewayShaper::REDACTED,
			(string) wp_json_encode( $result )
		);
	}

	public function test_wrong_capability_is_denied(): void {
		$this->actingAs( 'subscriber' );

		$ability = wp_get_ability( self::ABILITY );
		$this->assertFalse( $ability->check_permissions( array() ) );

		$result = $ability->execute( array() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_logged_out_user_is_denied(): void {
		wp_set_current_user( 0 );

		$result = wp_get_ability( self::ABILITY )->execute( array() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}
}
