<?php
/**
 * Integration tests for the `wc-data/get-current-currency` ability.
 *
 * @package AbilitiesCatalogWoo\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogWoo\Tests\Integration\Abilities\Data;

use GalatanOvidiu\AbilitiesCatalogWoo\Tests\TestCase;
use WP_Error;

/**
 * @covers \GalatanOvidiu\AbilitiesCatalogWoo\Abilities\Woo\Data\GetCurrentCurrency
 */
final class GetCurrentCurrencyTest extends TestCase {

	private const ABILITY = 'wc-data/get-current-currency';

	/**
	 * The exact closed key set the currency summary exposes.
	 *
	 * @var list<string>
	 */
	private const ROW_KEYS = array( 'code', 'name', 'symbol' );

	public function test_registered(): void {
		$ability = wp_get_ability( self::ABILITY );

		$this->assertNotNull( $ability );
		$this->assertSame( self::ABILITY, $ability->get_name() );
	}

	public function test_happy_path_returns_store_currency(): void {
		$this->actingAs( 'administrator' );

		// The default WC install currency is USD; pin it so the assertion is exact.
		update_option( 'woocommerce_currency', 'USD' );

		$result = wp_get_ability( self::ABILITY )->execute( array() );

		$this->assertIsArray( $result );
		$this->assertSame( 'USD', $result['code'] );
		$this->assertIsString( $result['name'] );
		$this->assertNotSame( '', $result['name'] );
		$this->assertIsString( $result['symbol'] );
		$this->assertNotSame( '', $result['symbol'] );
	}

	public function test_reflects_a_changed_store_currency(): void {
		$this->actingAs( 'administrator' );

		update_option( 'woocommerce_currency', 'EUR' );

		$result = wp_get_ability( self::ABILITY )->execute( array() );

		// The /current route reads get_option('woocommerce_currency'), so the
		// configured value is what comes back — not a hard-coded default.
		$this->assertSame( 'EUR', $result['code'] );
		$this->assertNotSame( '', $result['name'] );
	}

	public function test_output_shape_is_closed(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( self::ABILITY )->execute( array() );

		$this->assertIsArray( $result );
		$this->assertSame( self::ROW_KEYS, array_keys( $result ) );

		// No raw REST envelope leaks (HAL links / collection envelope).
		$json = (string) wp_json_encode( $result );
		$this->assertStringNotContainsString( '_links', $json );
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
