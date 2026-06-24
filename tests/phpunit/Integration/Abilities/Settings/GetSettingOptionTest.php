<?php
/**
 * Integration tests for the wc-settings/get-setting-option ability.
 *
 * @package AbilitiesCatalogWoo\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogWoo\Tests\Integration\Abilities\Settings;

use GalatanOvidiu\AbilitiesCatalogWoo\Support\SettingListShaper;
use GalatanOvidiu\AbilitiesCatalogWoo\Tests\TestCase;
use WP_Error;

/**
 * Exercises wc-settings/get-setting-option: the shaped single-option record on a
 * non-secret option, the redaction of a password-type option's value, the
 * missing-option 404 that must not collapse to a permission error, the
 * wrong-capability denial, and the exact closed output shape.
 *
 * The test injects its own options into the core `general` settings group through
 * the `woocommerce_settings-general` filter, so the seed is deterministic and does
 * not depend on which core options happen to exist. Each injected option declares
 * an `option_key`; the wrapped route reads the value with
 * `WC_Admin_Settings::get_option()`, so the stored option holds the value.
 */
final class GetSettingOptionTest extends TestCase {

	/**
	 * The full closed key set the ability returns for one setting option.
	 *
	 * @var list<string>
	 */
	private const EXPECTED_KEYS = array(
		'id',
		'group_id',
		'label',
		'description',
		'type',
		'value',
		'default',
		'has_value',
	);

	/**
	 * The stored option key for the seeded non-secret text option.
	 *
	 * @var string
	 */
	private const TEXT_OPTION_KEY = 'abilities_catalog_woo_test_text';

	/**
	 * The stored option key for the seeded password option.
	 *
	 * @var string
	 */
	private const SECRET_OPTION_KEY = 'abilities_catalog_woo_test_secret';

	/**
	 * The raw secret value stored for the password option.
	 *
	 * @var string
	 */
	private const SECRET_VALUE = 'sk_live_super_secret_value';

	/**
	 * Registers a non-secret text option and a password option in the `general`
	 * group, and stores their values.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		update_option( self::TEXT_OPTION_KEY, 'public-text-value' );
		update_option( self::SECRET_OPTION_KEY, self::SECRET_VALUE );

		add_filter( 'woocommerce_settings-general', array( $this, 'injectOptions' ) );
	}

	/**
	 * Removes the injected options and stored values.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		remove_filter( 'woocommerce_settings-general', array( $this, 'injectOptions' ) );

		delete_option( self::TEXT_OPTION_KEY );
		delete_option( self::SECRET_OPTION_KEY );

		parent::tear_down();
	}

	/**
	 * Appends the test options to the `general` group settings list.
	 *
	 * @param array<int,array<string,mixed>> $settings The existing group settings.
	 * @return array<int,array<string,mixed>> The settings with the test options appended.
	 */
	public function injectOptions( $settings ): array {
		$settings   = is_array( $settings ) ? $settings : array();
		$settings[] = array(
			'id'          => 'ac_test_text',
			'option_key'  => self::TEXT_OPTION_KEY,
			'label'       => 'Test Text Option',
			'description' => 'A non-secret text option for tests.',
			'type'        => 'text',
			'default'     => '',
		);
		$settings[] = array(
			'id'          => 'ac_test_secret',
			'option_key'  => self::SECRET_OPTION_KEY,
			'label'       => 'Test Secret Option',
			'description' => 'A password-type option for tests.',
			'type'        => 'password',
			'default'     => '',
		);

		return $settings;
	}

	public function test_ability_is_registered(): void {
		$ability = wp_get_ability( 'wc-settings/get-setting-option' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'wc-settings/get-setting-option', $ability->get_name() );
	}

	public function test_admin_reads_non_secret_option_with_real_value(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'wc-settings/get-setting-option' )->execute(
			array(
				'group' => 'general',
				'id'    => 'ac_test_text',
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'ac_test_text', $result['id'] );
		$this->assertSame( 'general', $result['group_id'] );
		$this->assertSame( 'Test Text Option', $result['label'] );
		$this->assertSame( 'text', $result['type'] );
		$this->assertSame( 'public-text-value', $result['value'] );
		$this->assertTrue( $result['has_value'] );
	}

	public function test_password_option_value_is_redacted(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'wc-settings/get-setting-option' )->execute(
			array(
				'group' => 'general',
				'id'    => 'ac_test_secret',
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'ac_test_secret', $result['id'] );
		$this->assertSame( 'password', $result['type'] );

		// The value is masked, and the configured flag stays true.
		$this->assertSame( SettingListShaper::REDACTED, $result['value'] );
		$this->assertTrue( $result['has_value'] );

		// The raw secret never appears anywhere in the output.
		$this->assertStringNotContainsString( self::SECRET_VALUE, wp_json_encode( $result ) );
	}

	public function test_output_shape_is_exact_and_closed(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'wc-settings/get-setting-option' )->execute(
			array(
				'group' => 'general',
				'id'    => 'ac_test_text',
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( self::EXPECTED_KEYS, array_keys( $result ) );

		// No raw setting body fields leak through.
		$this->assertArrayNotHasKey( '_links', $result );
		$this->assertArrayNotHasKey( 'tip', $result );
		$this->assertArrayNotHasKey( 'placeholder', $result );
		$this->assertArrayNotHasKey( 'options', $result );

		$this->assertIsString( $result['id'] );
		$this->assertIsString( $result['group_id'] );
		$this->assertIsString( $result['label'] );
		$this->assertIsString( $result['description'] );
		$this->assertIsString( $result['type'] );
		$this->assertIsString( $result['value'] );
		$this->assertIsString( $result['default'] );
		$this->assertIsBool( $result['has_value'] );
	}

	public function test_missing_option_returns_404_not_permission_error(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'wc-settings/get-setting-option' )->execute(
			array(
				'group' => 'general',
				'id'    => 'no-such-option-id',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_setting_setting_invalid', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] );
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_missing_group_returns_404_not_permission_error(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'wc-settings/get-setting-option' )->execute(
			array(
				'group' => 'no-such-group',
				'id'    => 'ac_test_text',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_setting_setting_group_invalid', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] );
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_subscriber_is_denied(): void {
		$this->actingAs( 'subscriber' );

		$ability = wp_get_ability( 'wc-settings/get-setting-option' );

		$this->assertFalse(
			$ability->check_permissions(
				array(
					'group' => 'general',
					'id'    => 'ac_test_text',
				)
			)
		);

		$result = $ability->execute(
			array(
				'group' => 'general',
				'id'    => 'ac_test_text',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}
}
