<?php
/**
 * Integration tests for the `og-wc-settings/list-group-settings` ability.
 *
 * @package AbilitiesCatalogWoo\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogWoo\Tests\Integration\Abilities\Settings;

use GalatanOvidiu\AbilitiesCatalogWoo\Support\SettingListShaper;
use GalatanOvidiu\AbilitiesCatalogWoo\Tests\TestCase;
use WP_Error;

/**
 * @covers \GalatanOvidiu\AbilitiesCatalogWoo\Abilities\Woo\Settings\ListGroupSettings
 */
final class ListGroupSettingsTest extends TestCase {

	private const ABILITY = 'og-wc-settings/list-group-settings';

	/**
	 * The deterministic settings group seeded for every test.
	 *
	 * @var string
	 */
	private const SEEDED_GROUP = 'test';

	/**
	 * The non-secret text option seeded into the `test` group.
	 *
	 * @var string
	 */
	private const TEXT_OPTION = 'test_text_option';

	/**
	 * The password-type option seeded into the `test` group.
	 *
	 * @var string
	 */
	private const SECRET_OPTION = 'test_password_option';

	/**
	 * The raw secret seeded for the password option. It must never appear in output.
	 *
	 * @var string
	 */
	private const RAW_SECRET = 'super-secret-key';

	/**
	 * The exact keys a shaped setting-option summary row exposes.
	 *
	 * Asserting against this fixed set proves the raw REST body is never leaked:
	 * only these projected fields reach the consumer. No `tip`, `placeholder`,
	 * `options`, or `group_id` leaks.
	 *
	 * @var list<string>
	 */
	private const ROW_KEYS = array(
		'id',
		'label',
		'type',
		'value',
		'default',
	);

	/**
	 * Seeds the deterministic `test` settings group + options before each test.
	 *
	 * The wrapped route `GET /wc/v3/settings/<group>` resolves a group's options
	 * through the `woocommerce_settings-<group>` filter and validates the group
	 * against `woocommerce_settings_groups`. WooCommerce attaches those in
	 * `register_wp_admin_settings()` on `rest_api_init`, which the minimal PHPUnit
	 * bootstrap does not reliably fire, so the core groups are absent and reading
	 * `general` returns the WC 404 `rest_setting_setting_group_invalid`.
	 * {@see TestCase::seedTestSettingsGroup()} registers a deterministic `test`
	 * group with a text option and a `password` option (the latter exercises the
	 * secret-redaction path) and stores their values, so the happy path exercises a
	 * genuinely populated group exactly as a live store would.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		$this->seedTestSettingsGroup();
	}

	public function test_registered(): void {
		$ability = wp_get_ability( self::ABILITY );

		$this->assertNotNull( $ability );
		$this->assertSame( self::ABILITY, $ability->get_name() );
	}

	public function test_happy_path_returns_shaped_rows(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( self::ABILITY )->execute( array( 'group' => self::SEEDED_GROUP ) );

		$this->assertIsArray( $result );
		$this->assertSame( array( 'group', 'items', 'total' ), array_keys( $result ) );
		$this->assertSame( self::SEEDED_GROUP, $result['group'] );
		$this->assertIsArray( $result['items'] );
		$this->assertIsInt( $result['total'] );
		$this->assertNotEmpty( $result['items'] );
		$this->assertCount( $result['total'], $result['items'] );

		foreach ( $result['items'] as $row ) {
			$this->assertSame( self::ROW_KEYS, array_keys( $row ) );
			$this->assertIsString( $row['id'] );
			$this->assertIsString( $row['label'] );
			$this->assertIsString( $row['type'] );
			$this->assertIsString( $row['value'] );
			$this->assertIsString( $row['default'] );
		}

		// Both seeded options are returned.
		$ids = wp_list_pluck( $result['items'], 'id' );
		$this->assertContains( self::TEXT_OPTION, $ids );
		$this->assertContains( self::SECRET_OPTION, $ids );
	}

	public function test_password_type_value_is_redacted(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( self::ABILITY )->execute( array( 'group' => self::SEEDED_GROUP ) );

		$this->assertIsArray( $result );

		$rows = array();
		foreach ( $result['items'] as $row ) {
			$rows[ $row['id'] ] = $row;
		}

		// The non-secret text option exposes its real value.
		$this->assertArrayHasKey( self::TEXT_OPTION, $rows );
		$this->assertSame( 'visible-value', $rows[ self::TEXT_OPTION ]['value'] );

		// The password option's value is redacted to the hidden marker.
		$this->assertArrayHasKey( self::SECRET_OPTION, $rows );
		$this->assertSame( 'password', $rows[ self::SECRET_OPTION ]['type'] );
		$this->assertSame( SettingListShaper::REDACTED, $rows[ self::SECRET_OPTION ]['value'] );

		// The raw secret must not appear anywhere in the encoded output.
		$this->assertStringNotContainsString( self::RAW_SECRET, (string) wp_json_encode( $result ) );
	}

	public function test_missing_group_returns_specific_404_not_permission_collapse(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( self::ABILITY )->execute( array( 'group' => 'no_such_group' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_setting_setting_group_invalid', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] );
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_missing_required_group_is_rejected(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( self::ABILITY )->execute( array() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_input', $result->get_error_code() );
	}

	public function test_wrong_capability_is_denied(): void {
		$this->actingAs( 'subscriber' );

		$ability = wp_get_ability( self::ABILITY );
		$this->assertFalse( $ability->check_permissions( array( 'group' => self::SEEDED_GROUP ) ) );

		$result = $ability->execute( array( 'group' => self::SEEDED_GROUP ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_logged_out_user_is_denied(): void {
		wp_set_current_user( 0 );

		$result = wp_get_ability( self::ABILITY )->execute( array( 'group' => self::SEEDED_GROUP ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_output_shape_has_no_raw_fields(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( self::ABILITY )->execute( array( 'group' => self::SEEDED_GROUP ) );

		$this->assertSame( array( 'group', 'items', 'total' ), array_keys( $result ) );
		$row = $result['items'][0];
		$this->assertSame( self::ROW_KEYS, array_keys( $row ) );
		$this->assertArrayNotHasKey( '_links', $row );
		$this->assertArrayNotHasKey( 'tip', $row );
		$this->assertArrayNotHasKey( 'placeholder', $row );
		$this->assertArrayNotHasKey( 'options', $row );
		$this->assertArrayNotHasKey( 'group_id', $row );
	}
}
