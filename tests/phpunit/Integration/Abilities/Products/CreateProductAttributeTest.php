<?php
/**
 * Integration tests for the `wc-products/create-product-attribute` ability.
 *
 * @package AbilitiesCatalogWoo\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogWoo\Tests\Integration\Abilities\Products;

use GalatanOvidiu\AbilitiesCatalogWoo\Tests\TestCase;
use WP_Error;

/**
 * Exercises wc-products/create-product-attribute: the happy-path create returning
 * a shaped attribute row with a real id, the exact closed output shape (no raw
 * attribute body leaks), and the wrong-cap denial (attributes are manager-tier,
 * so an editor without manage_product_terms is denied too).
 *
 * @covers \GalatanOvidiu\AbilitiesCatalogWoo\Abilities\Woo\Products\CreateProductAttribute
 */
final class CreateProductAttributeTest extends TestCase {

	private const ABILITY = 'wc-products/create-product-attribute';

	/**
	 * The exact keys a shaped attribute summary row exposes.
	 *
	 * Asserting against this fixed set proves the raw attribute body never leaks:
	 * only these projected fields reach the consumer (no menu_order, no _links).
	 *
	 * @var list<string>
	 */
	private const ROW_KEYS = array(
		'id',
		'name',
		'slug',
		'type',
		'order_by',
		'has_archives',
	);

	public function test_registered(): void {
		$ability = wp_get_ability( self::ABILITY );

		$this->assertNotNull( $ability );
		$this->assertSame( self::ABILITY, $ability->get_name() );
	}

	public function test_admin_creates_attribute(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( self::ABILITY )->execute(
			array(
				'name' => 'Color',
				'type' => 'select',
			)
		);

		$this->assertIsArray( $result );
		$this->assertGreaterThan( 0, $result['id'] );
		$this->assertSame( 'Color', $result['name'] );
		$this->assertSame( 'select', $result['type'] );

		// The attribute was actually persisted: it appears in the WC attribute list.
		$names = wp_list_pluck( wc_get_attribute_taxonomies(), 'attribute_label' );
		$this->assertContains( 'Color', $names );
	}

	public function test_optional_fields_are_honored(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( self::ABILITY )->execute(
			array(
				'name'         => 'Size',
				'order_by'     => 'name',
				'has_archives' => true,
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'name', $result['order_by'] );
		$this->assertTrue( $result['has_archives'] );
	}

	public function test_output_shape_is_exact_and_closed(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( self::ABILITY )->execute( array( 'name' => 'Material' ) );

		$this->assertIsArray( $result );
		$this->assertSame( self::ROW_KEYS, array_keys( $result ) );
		$this->assertIsInt( $result['id'] );
		$this->assertIsString( $result['name'] );
		$this->assertIsString( $result['slug'] );
		$this->assertIsString( $result['type'] );
		$this->assertIsString( $result['order_by'] );
		$this->assertIsBool( $result['has_archives'] );

		// No raw attribute body fields leak through.
		$this->assertArrayNotHasKey( 'menu_order', $result );
		$this->assertArrayNotHasKey( '_links', $result );
	}

	public function test_subscriber_is_denied(): void {
		$this->actingAs( 'subscriber' );

		$ability = wp_get_ability( self::ABILITY );

		$this->assertFalse( $ability->check_permissions( array( 'name' => 'Nope' ) ) );

		$result = $ability->execute( array( 'name' => 'Nope' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );

		// The attribute was not created.
		$names = wp_list_pluck( wc_get_attribute_taxonomies(), 'attribute_label' );
		$this->assertNotContains( 'Nope', $names );
	}

	public function test_editor_without_manager_cap_is_denied(): void {
		// Attributes are manager-tier (manage_product_terms): a plain editor, who
		// lacks that cap, must be denied even though they can edit posts.
		$this->actingAs( 'editor' );

		$ability = wp_get_ability( self::ABILITY );

		$this->assertFalse( $ability->check_permissions( array( 'name' => 'Brand' ) ) );

		$result = $ability->execute( array( 'name' => 'Brand' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}
}
