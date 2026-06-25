<?php
/**
 * Integration tests for the `og-wc-system/get-system-status` ability.
 *
 * @package AbilitiesCatalogWoo\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogWoo\Tests\Integration\Abilities\System;

use GalatanOvidiu\AbilitiesCatalogWoo\Tests\TestCase;
use WP_Error;

/**
 * @covers \GalatanOvidiu\AbilitiesCatalogWoo\Abilities\Woo\System\GetSystemStatus
 */
final class GetSystemStatusTest extends TestCase {

	private const ABILITY = 'og-wc-system/get-system-status';

	/**
	 * The exact top-level keys the curated subset exposes.
	 *
	 * Asserting against this fixed set proves the raw, info-disclosing system-status
	 * payload is never returned: the store URLs, store id, log directory, settings
	 * block, plugin lists, and database table map are all absent.
	 *
	 * @var list<string>
	 */
	private const TOP_KEYS = array(
		'environment',
		'database',
		'theme',
		'active_plugins_count',
		'security',
	);

	public function test_registered(): void {
		$ability = wp_get_ability( self::ABILITY );

		$this->assertNotNull( $ability );
		$this->assertSame( self::ABILITY, $ability->get_name() );
	}

	public function test_happy_path_returns_curated_subset(): void {
		$this->actingAs( 'administrator' );

		// The /system_status route returns live data in the WC test env, so no
		// seeding is needed.
		$result = wp_get_ability( self::ABILITY )->execute( array() );

		$this->assertIsArray( $result );
		$this->assertSame( self::TOP_KEYS, array_keys( $result ) );

		$this->assertIsArray( $result['environment'] );
		$this->assertSame(
			array( 'wc_version', 'wp_version', 'php_version', 'server_info', 'wp_memory_limit', 'wp_debug_mode' ),
			array_keys( $result['environment'] )
		);
		$this->assertIsString( $result['environment']['wc_version'] );
		$this->assertIsString( $result['environment']['wp_version'] );
		$this->assertIsString( $result['environment']['php_version'] );
		$this->assertIsString( $result['environment']['server_info'] );
		$this->assertIsInt( $result['environment']['wp_memory_limit'] );
		$this->assertIsBool( $result['environment']['wp_debug_mode'] );

		$this->assertIsArray( $result['database'] );
		$this->assertSame(
			array( 'wc_database_version', 'table_count' ),
			array_keys( $result['database'] )
		);
		$this->assertIsString( $result['database']['wc_database_version'] );
		$this->assertIsInt( $result['database']['table_count'] );

		$this->assertIsArray( $result['theme'] );
		$this->assertSame(
			array( 'name', 'version', 'is_child_theme' ),
			array_keys( $result['theme'] )
		);
		$this->assertIsString( $result['theme']['name'] );
		$this->assertIsString( $result['theme']['version'] );
		$this->assertIsBool( $result['theme']['is_child_theme'] );

		$this->assertIsInt( $result['active_plugins_count'] );

		$this->assertIsArray( $result['security'] );
		$this->assertSame(
			array( 'secure_connection', 'hide_errors' ),
			array_keys( $result['security'] )
		);
		$this->assertIsBool( $result['security']['secure_connection'] );
		$this->assertIsBool( $result['security']['hide_errors'] );
	}

	public function test_subset_omits_info_disclosure_fields(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( self::ABILITY )->execute( array() );

		// Only the curated keys at the top level.
		$this->assertSame( self::TOP_KEYS, array_keys( $result ) );

		// The raw payload's reconnaissance fields must be absent from the output.
		foreach ( array( 'home_url', 'site_url', 'store_id', 'log_directory', 'active_plugins', 'inactive_plugins', 'database_tables', 'settings', 'pages', 'post_type_counts' ) as $forbidden ) {
			$this->assertArrayNotHasKey( $forbidden, $result );
		}

		// Nested blocks must not carry the table map or the plugin lists either.
		$this->assertArrayNotHasKey( 'database_tables', $result['database'] );
		$this->assertArrayNotHasKey( 'home_url', $result['environment'] );
		$this->assertArrayNotHasKey( 'site_url', $result['environment'] );
		$this->assertArrayNotHasKey( 'store_id', $result['environment'] );
		$this->assertArrayNotHasKey( 'log_directory', $result['environment'] );

		// None of those fingerprinting keys may appear anywhere in the serialized output.
		$json = (string) wp_json_encode( $result );
		$this->assertStringNotContainsString( 'database_tables', $json );
		$this->assertStringNotContainsString( 'log_directory', $json );
		$this->assertStringNotContainsString( 'store_id', $json );
		$this->assertStringNotContainsString( 'inactive_plugins', $json );
	}

	public function test_wc_version_is_populated(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( self::ABILITY )->execute( array() );

		// The shaper maps the REST environment.version to wc_version, so a live WC
		// install reports a non-empty version string.
		$this->assertNotSame( '', $result['environment']['wc_version'] );
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
