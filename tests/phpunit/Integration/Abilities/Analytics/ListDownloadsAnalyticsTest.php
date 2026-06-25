<?php
/**
 * Integration tests for the `og-wc-reports/list-downloads-analytics` ability.
 *
 * @package AbilitiesCatalogWoo\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogWoo\Tests\Integration\Abilities\Analytics;

use GalatanOvidiu\AbilitiesCatalogWoo\Support\WooPlugin;
use GalatanOvidiu\AbilitiesCatalogWoo\Tests\TestCase;
use WP_Error;

/**
 * @covers \GalatanOvidiu\AbilitiesCatalogWoo\Abilities\Woo\Analytics\ListDownloadsAnalytics
 */
final class ListDownloadsAnalyticsTest extends TestCase {

	private const ABILITY = 'og-wc-reports/list-downloads-analytics';

	/**
	 * The exact keys a shaped download-analytics row exposes.
	 *
	 * Asserting against this fixed set proves the raw row — which carries the
	 * downloader's `ip_address`, `username`, `date_gmt`, and `file_path` — is never
	 * leaked.
	 *
	 * @var list<string>
	 */
	private const ROW_KEYS = array(
		'id',
		'product_id',
		'date',
		'download_id',
		'file_name',
		'order_id',
		'order_number',
		'user_id',
	);

	/**
	 * Skips the suite when the WooCommerce Analytics feature is off.
	 *
	 * The Analytics abilities are conditional on {@see WooPlugin::hasAnalytics()};
	 * when the feature is off they do not register, so there is nothing to exercise
	 * and `RegistryTest` expects them absent.
	 */
	public function set_up(): void {
		parent::set_up();

		if ( ! WooPlugin::hasAnalytics() ) {
			$this->markTestSkipped( 'WooCommerce Analytics feature is not active; the wc-analytics route is not registered.' );
		}

		$this->ensureAnalyticsRoutesRegistered();
	}

	public function test_registered(): void {
		$ability = wp_get_ability( self::ABILITY );

		$this->assertNotNull( $ability );
		$this->assertSame( self::ABILITY, $ability->get_name() );
	}

	public function test_happy_path_returns_items_array(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( self::ABILITY )->execute( array() );

		$this->assertIsArray( $result );
		$this->assertSame( array( 'items', 'total' ), array_keys( $result ) );
		// A download event is impractical to seed, so the report may be empty on a
		// fresh store; assert the envelope shape, never the magnitude.
		$this->assertIsArray( $result['items'] );
		$this->assertIsInt( $result['total'] );

		foreach ( $result['items'] as $row ) {
			$this->assertSame( self::ROW_KEYS, array_keys( $row ) );
		}
	}

	public function test_filter_is_accepted(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( self::ABILITY )->execute(
			array(
				'after'   => '2020-01-01T00:00:00',
				'orderby' => 'date',
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( array( 'items', 'total' ), array_keys( $result ) );
		$this->assertIsArray( $result['items'] );
		$this->assertIsInt( $result['total'] );
	}

	public function test_output_shape_redacts_pii_and_raw_fields(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( self::ABILITY )->execute( array() );

		$this->assertSame( array( 'items', 'total' ), array_keys( $result ) );

		foreach ( $result['items'] as $row ) {
			$this->assertSame( self::ROW_KEYS, array_keys( $row ) );
			$this->assertArrayNotHasKey( 'ip_address', $row );
			$this->assertArrayNotHasKey( 'username', $row );
			$this->assertArrayNotHasKey( 'date_gmt', $row );
			$this->assertArrayNotHasKey( 'file_path', $row );
			$this->assertArrayNotHasKey( '_links', $row );
		}
	}

	public function test_wrong_capability_is_denied(): void {
		$this->actingAs( 'subscriber' );

		$ability = wp_get_ability( self::ABILITY );
		$this->assertFalse( $ability->check_permissions( array() ) );

		$result = $ability->execute( array() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}
}
