<?php
/**
 * Integration tests for the `og-wc-settings/list-setting-groups` ability.
 *
 * @package AbilitiesCatalogWoo\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogWoo\Tests\Integration\Abilities\Settings;

use GalatanOvidiu\AbilitiesCatalogWoo\Tests\TestCase;
use WP_Error;

/**
 * @covers \GalatanOvidiu\AbilitiesCatalogWoo\Abilities\Woo\Settings\ListSettingGroups
 */
final class ListSettingGroupsTest extends TestCase {

	private const ABILITY = 'og-wc-settings/list-setting-groups';

	/**
	 * The keys a shaped group row exposes — and nothing more.
	 *
	 * @var list<string>
	 */
	private const ROW_KEYS = array( 'id', 'label', 'description', 'parent_id' );

	/**
	 * The deterministic settings group seeded for every test.
	 *
	 * The page-backed core groups (general/products/tax) only register once the
	 * WooCommerce admin settings pages are instantiated, which the minimal test
	 * bootstrap does not reliably do, so they are absent and order-dependent.
	 * {@see TestCase::seedTestSettingsGroup()} registers this group through the
	 * `woocommerce_settings_groups` filter — exactly the source the wrapped
	 * `GET /wc/v3/settings` route reads — so it is the order-independent anchor.
	 *
	 * @var string
	 */
	private const SEEDED_GROUP_ID = 'test';

	/**
	 * Seeds the deterministic `test` settings group before each test.
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

	public function test_happy_path_returns_seeded_group(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( self::ABILITY )->execute( array() );

		$this->assertIsArray( $result );
		$this->assertIsArray( $result['items'] );
		$this->assertNotEmpty( $result['items'] );
		$this->assertIsInt( $result['total'] );
		$this->assertSame( count( $result['items'] ), $result['total'] );

		$ids = array_column( $result['items'], 'id' );
		$this->assertContains( self::SEEDED_GROUP_ID, $ids );
	}

	public function test_each_row_is_exactly_the_closed_schema(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( self::ABILITY )->execute( array() );

		$this->assertNotEmpty( $result['items'] );
		foreach ( $result['items'] as $row ) {
			$this->assertSame( self::ROW_KEYS, array_keys( $row ) );
			$this->assertIsString( $row['id'] );
			$this->assertIsString( $row['label'] );
			$this->assertIsString( $row['description'] );
			$this->assertIsString( $row['parent_id'] );

			// No raw REST fields leak (the controller emits sub_groups + _links).
			$this->assertArrayNotHasKey( 'sub_groups', $row );
			$this->assertArrayNotHasKey( '_links', $row );
		}
	}

	public function test_output_shape_is_exactly_items_and_total(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( self::ABILITY )->execute( array() );

		$this->assertSame( array( 'items', 'total' ), array_keys( $result ) );
	}

	public function test_wrong_capability_is_denied(): void {
		$this->actingAs( 'subscriber' );

		$result = wp_get_ability( self::ABILITY )->execute( array() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}
}
