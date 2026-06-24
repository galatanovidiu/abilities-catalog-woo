<?php
/**
 * Integration tests for the wc-products/update-product-attribute ability.
 *
 * @package AbilitiesCatalogWoo\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogWoo\Tests\Integration\Abilities\Products;

use GalatanOvidiu\AbilitiesCatalogWoo\Tests\TestCase;
use WP_Error;
use WP_REST_Request;

/**
 * Exercises wc-products/update-product-attribute: a name change on a seeded
 * attribute, the missing-attribute 404 that must not collapse to a permission
 * error, the wrong-capability denial (with the attribute unchanged), and the
 * exact closed output shape.
 */
final class UpdateProductAttributeTest extends TestCase {

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
		$ability = wp_get_ability( 'wc-products/update-product-attribute' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'wc-products/update-product-attribute', $ability->get_name() );
	}

	public function test_admin_updates_attribute_name(): void {
		$this->actingAs( 'administrator' );

		$attribute_id = $this->createGlobalAttribute( 'Color', 'select' )['id'];

		$result = wp_get_ability( 'wc-products/update-product-attribute' )->execute(
			array(
				'id'   => $attribute_id,
				'name' => 'Colour',
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( $attribute_id, $result['id'] );
		$this->assertSame( 'Colour', $result['name'] );
		$this->assertSame( 'pa_color', $result['slug'] );

		// The change is persisted: a fresh GET of the same attribute reports it.
		$this->assertSame( 'Colour', $this->readAttributeName( $attribute_id ) );
	}

	public function test_missing_attribute_returns_404_not_permission_error(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'wc-products/update-product-attribute' )->execute(
			array(
				'id'   => 99999999,
				'name' => 'Nope',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'woocommerce_rest_taxonomy_invalid', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] );
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_subscriber_is_denied_and_attribute_unchanged(): void {
		$attribute_id = $this->createGlobalAttribute( 'Material', 'select' )['id'];

		$this->actingAs( 'subscriber' );

		$ability = wp_get_ability( 'wc-products/update-product-attribute' );

		$this->assertFalse( $ability->check_permissions( array( 'id' => $attribute_id ) ) );

		$result = $ability->execute(
			array(
				'id'   => $attribute_id,
				'name' => 'Hacked',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );

		// Read the attribute back as an authorized user: the GET route also requires
		// manage_product_terms, so the subscriber would be denied the read-back and
		// the unchanged-name check would falsely see an empty name.
		$this->actingAs( 'administrator' );

		// The attribute survived the denied write unchanged.
		$this->assertSame( 'Material', $this->readAttributeName( $attribute_id ) );
	}

	public function test_output_shape_is_exact_and_closed(): void {
		$this->actingAs( 'administrator' );

		$attribute_id = $this->createGlobalAttribute( 'Size', 'select' )['id'];

		$result = wp_get_ability( 'wc-products/update-product-attribute' )->execute(
			array(
				'id'       => $attribute_id,
				'order_by' => 'name',
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( self::EXPECTED_KEYS, array_keys( $result ) );
		$this->assertSame( 'name', $result['order_by'] );

		// A slug-omitted update is a true no-op for the slug: the seeded "Size"
		// attribute keeps pa_size even though only order_by was changed.
		$this->assertSame( 'pa_size', $result['slug'] );

		// No raw attribute fields leak through.
		$this->assertArrayNotHasKey( '_links', $result );
		$this->assertArrayNotHasKey( 'menu_order', $result );

		$this->assertIsInt( $result['id'] );
		$this->assertIsString( $result['name'] );
		$this->assertIsString( $result['slug'] );
		$this->assertIsString( $result['type'] );
		$this->assertIsString( $result['order_by'] );
		$this->assertIsBool( $result['has_archives'] );
	}

	/**
	 * Reads an attribute's stored name straight from the `wc/v3` GET route.
	 *
	 * The route reads the `woocommerce_attribute_taxonomies` table directly, so it
	 * is the source-of-truth read-back for a persisted update (it does not depend
	 * on a possibly-stale label cache).
	 *
	 * @param int $attribute_id The global attribute ID.
	 * @return string The stored attribute name.
	 */
	private function readAttributeName( int $attribute_id ): string {
		$response = rest_do_request( new WP_REST_Request( 'GET', '/wc/v3/products/attributes/' . $attribute_id ) );
		$data     = rest_get_server()->response_to_data( $response, false );

		return (string) ( is_array( $data ) ? ( $data['name'] ?? '' ) : '' );
	}
}
