<?php
/**
 * Integration tests for the `og-wc-reports/get-downloads-stats` ability.
 *
 * @package AbilitiesCatalogWoo\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogWoo\Tests\Integration\Abilities\Analytics;

use GalatanOvidiu\AbilitiesCatalogWoo\Support\WooPlugin;
use GalatanOvidiu\AbilitiesCatalogWoo\Tests\TestCase;
use WP_Error;

/**
 * @covers \GalatanOvidiu\AbilitiesCatalogWoo\Abilities\Woo\Analytics\GetDownloadsStats
 */
final class GetDownloadsStatsTest extends TestCase {

	private const ABILITY = 'og-wc-reports/get-downloads-stats';

	/**
	 * The exact keys the shaped downloads `totals` object exposes.
	 *
	 * Asserting against this fixed set proves the raw stats body — and the huge
	 * per-interval `intervals` array — never leak: only this KPI field reaches the
	 * consumer.
	 *
	 * @var list<string>
	 */
	private const TOTALS_KEYS = array( 'download_count' );

	/**
	 * Skips the whole case when WooCommerce Analytics is off.
	 *
	 * The ability is conditional on the lazy, feature-gated `wc-analytics`
	 * namespace; when Analytics is disabled it does not register, so a test that
	 * exercised it would fail on a null ability. Skipping keeps the suite (and
	 * RegistryTest) green either way. The route is then re-registered before each
	 * dispatch because the harness's per-test REST-server reset wipes the lazy
	 * one-shot registration.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		if ( ! WooPlugin::hasAnalytics() ) {
			$this->markTestSkipped( 'WooCommerce Analytics feature is not enabled.' );
		}

		$this->ensureAnalyticsRoutesRegistered();
	}

	public function test_registered(): void {
		$ability = wp_get_ability( self::ABILITY );

		$this->assertNotNull( $ability );
		$this->assertSame( self::ABILITY, $ability->get_name() );
	}

	public function test_happy_path_returns_shaped_totals_without_intervals(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( self::ABILITY )->execute( array() );

		$this->assertIsArray( $result );
		$this->assertSame( array( 'totals', 'intervals_count', 'period' ), array_keys( $result ) );

		// The raw `intervals` array must never be passed through.
		$this->assertArrayNotHasKey( 'intervals', $result );

		$this->assertIsInt( $result['intervals_count'] );

		$this->assertIsArray( $result['totals'] );
		$this->assertSame( self::TOTALS_KEYS, array_keys( $result['totals'] ) );
		$this->assertIsInt( $result['totals']['download_count'] );
		// A fresh store records no downloads; assert shape and a non-negative count, never magnitude.
		$this->assertGreaterThanOrEqual( 0, $result['totals']['download_count'] );

		$this->assertIsArray( $result['period'] );
		$this->assertSame( array( 'after', 'before', 'interval' ), array_keys( $result['period'] ) );
		$this->assertSame( 'week', $result['period']['interval'] );
	}

	public function test_interval_filter_is_echoed_back_in_period(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( self::ABILITY )->execute(
			array(
				'after'    => '2020-01-01T00:00:00',
				'before'   => '2020-01-31T23:59:59',
				'interval' => 'day',
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( '2020-01-01T00:00:00', $result['period']['after'] );
		$this->assertSame( '2020-01-31T23:59:59', $result['period']['before'] );
		$this->assertSame( 'day', $result['period']['interval'] );
		$this->assertArrayNotHasKey( 'intervals', $result );
	}

	public function test_products_filter_is_accepted(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( self::ABILITY )->execute(
			array( 'products' => array( 1, 2 ) )
		);

		$this->assertIsArray( $result );
		$this->assertSame( array( 'totals', 'intervals_count', 'period' ), array_keys( $result ) );
		$this->assertArrayNotHasKey( 'intervals', $result );
		$this->assertIsInt( $result['totals']['download_count'] );
	}

	public function test_wrong_capability_is_denied(): void {
		$this->actingAs( 'subscriber' );

		$ability = wp_get_ability( self::ABILITY );

		// subscriber lacks view_woocommerce_reports.
		$this->assertFalse( $ability->check_permissions( array() ) );

		$result = $ability->execute( array() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}
}
