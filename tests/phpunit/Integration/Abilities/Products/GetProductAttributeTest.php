<?php
/**
 * Integration tests for the og-wc-products/get-product-attribute ability.
 *
 * @package AbilitiesCatalogWoo\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogWoo\Tests\Integration\Abilities\Products;

use GalatanOvidiu\AbilitiesCatalogWoo\Tests\TestCase;
use WP_Error;

/**
 * Exercises og-wc-products/get-product-attribute: the shaped single-attribute
 * record, the missing-attribute 404 that must not collapse to a permission
 * error, the wrong-capability denial, and the exact closed output shape.
 */
final class GetProductAttributeTest extends TestCase {

	/**
	 * The full closed key set the ability returns (the attribute summary fields).
	 *
	 * @var list<string>
	 */
	private const EXPECTED_KEYS = array(
		'id',
		'name',
		'slug',
		'type',
		'order_by',
		'has_archives',
	);

	public function test_ability_is_registered(): void {
		$ability = wp_get_ability( 'og-wc-products/get-product-attribute' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'og-wc-products/get-product-attribute', $ability->get_name() );
	}

	public function test_admin_reads_attribute_detail(): void {
		$this->actingAs( 'administrator' );

		$attribute_id = $this->seedAttribute( 'Color', 'select' );

		$result = wp_get_ability( 'og-wc-products/get-product-attribute' )->execute( array( 'id' => $attribute_id ) );

		$this->assertIsArray( $result );
		$this->assertSame( $attribute_id, $result['id'] );
		$this->assertSame( 'Color', $result['name'] );
		$this->assertSame( 'pa_color', $result['slug'] );
		$this->assertSame( 'select', $result['type'] );
		$this->assertIsString( $result['order_by'] );
		$this->assertIsBool( $result['has_archives'] );
	}

	public function test_output_shape_is_exact_and_closed(): void {
		$this->actingAs( 'administrator' );

		$attribute_id = $this->seedAttribute( 'Size', 'select' );

		$result = wp_get_ability( 'og-wc-products/get-product-attribute' )->execute( array( 'id' => $attribute_id ) );

		$this->assertIsArray( $result );
		$this->assertSame( self::EXPECTED_KEYS, array_keys( $result ) );

		// No raw attribute fields leak through.
		$this->assertArrayNotHasKey( '_links', $result );
		$this->assertArrayNotHasKey( 'menu_order', $result );
	}

	public function test_missing_attribute_returns_404_not_permission_error(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'og-wc-products/get-product-attribute' )->execute( array( 'id' => 99999999 ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'woocommerce_rest_taxonomy_invalid', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] );
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_subscriber_is_denied(): void {
		$attribute_id = $this->seedAttribute( 'Material', 'select' );
		$this->actingAs( 'subscriber' );

		$ability = wp_get_ability( 'og-wc-products/get-product-attribute' );

		$this->assertFalse( $ability->check_permissions( array( 'id' => $attribute_id ) ) );

		$result = $ability->execute( array( 'id' => $attribute_id ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	/**
	 * Seeds a global product attribute and returns its ID.
	 *
	 * Delegates to the shared {@see TestCase::createGlobalAttribute()} helper,
	 * which creates the attribute, registers its `pa_*` taxonomy in-request, and
	 * clears the WC attribute caches.
	 *
	 * @param string $name The attribute name (e.g. "Color").
	 * @param string $type The attribute type (e.g. "select").
	 * @return int The created attribute ID.
	 */
	private function seedAttribute( string $name, string $type ): int {
		return $this->createGlobalAttribute( $name, $type )['id'];
	}
}
