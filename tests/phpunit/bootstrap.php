<?php
/**
 * Bootstrap the PHPUnit tests.
 *
 * Loads the WordPress test environment, then activates this add-on so its
 * WooCommerce abilities register on `wp_abilities_api_init`. The Abilities API
 * itself ships in WordPress 7.0 core. WooCommerce is the add-on's runtime
 * dependency, so it is loaded here too; the WP test library does not auto-load
 * site plugins.
 *
 * @package AbilitiesCatalogWoo\Tests
 *
 * phpcs:disable WordPressVIPMinimum.Files.IncludingFile.UsingVariable
 */

declare(strict_types=1);

define('TESTS_REPO_ROOT_DIR', dirname(__DIR__, 2));

// Load Composer dev dependencies (PHPUnit polyfills, the Tests\ autoloader) when present.
if (file_exists(TESTS_REPO_ROOT_DIR . '/vendor/autoload.php')) {
	require_once TESTS_REPO_ROOT_DIR . '/vendor/autoload.php';
}

// Detect where to load the WordPress test environment from.
if (false !== getenv('WP_TESTS_DIR')) {
	$_test_root = getenv('WP_TESTS_DIR');
} elseif (false !== getenv('WP_DEVELOP_DIR')) {
	$_test_root = getenv('WP_DEVELOP_DIR') . '/tests/phpunit';
} elseif (false !== getenv('WP_PHPUNIT__DIR')) {
	$_test_root = getenv('WP_PHPUNIT__DIR');
} elseif (file_exists(TESTS_REPO_ROOT_DIR . '/../../../../../tests/phpunit/includes/functions.php')) {
	$_test_root = TESTS_REPO_ROOT_DIR . '/../../../../../tests/phpunit';
} else { // Fallback.
	$_test_root = '/tmp/wordpress-tests-lib';
}

// Give access to the tests_add_filter() function.
require_once $_test_root . '/includes/functions.php';

// Activate the plugins during the test environment boot.
tests_add_filter(
	'muplugins_loaded',
	static function (): void {
		// Load WooCommerce (a sibling plugin in the test env) FIRST so the
		// WooCommerce ability group sees its dependency present when it registers.
		// The WP test library does not auto-load site plugins, so a dependency must
		// be required here. Guarded so the suite still boots if WooCommerce is absent
		// (its tests then skip via WooPlugin::isActive()), matching how the abilities
		// themselves degrade.
		$woocommerce = dirname(TESTS_REPO_ROOT_DIR) . '/woocommerce/woocommerce.php';
		if (is_readable($woocommerce)) {
			require_once $woocommerce;
		}

		// Use require (not require_once) so the plugin file always loads here.
		require TESTS_REPO_ROOT_DIR . '/abilities-catalog-woo.php';

		// Load the sibling Abilities Catalog if present, so the MCP IntegrationTest
		// can exercise the catalog's domain filters. Guarded — the add-on works
		// standalone and its abilities register without the catalog.
		$catalog = dirname(TESTS_REPO_ROOT_DIR) . '/abilities-catalog/abilities-catalog.php';
		if (is_readable($catalog)) {
			require_once $catalog;
		}
	}
);

// Start up the WP testing environment.
require $_test_root . '/includes/bootstrap.php';
