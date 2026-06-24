<?php
/**
 * Integration tests for the wc-products/update-shipping-class ability.
 *
 * @package AbilitiesCatalogWoo\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogWoo\Tests\Integration\Abilities\Products;

use GalatanOvidiu\AbilitiesCatalogWoo\Tests\TestCase;
use WP_Error;

/**
 * Exercises wc-products/update-shipping-class: a name change on a seeded class,
 * the missing-class 404 that must not collapse to a permission error, the
 * wrong-capability denial (with the class unchanged), and the exact closed output
 * shape (no parent, no raw term fields leak).
 */
final class UpdateShippingClassTest extends TestCase {

	/**
	 * The closed key set the ability returns for one shipping-class term row.
	 *
	 * @var list<string>
	 */
	private const EXPECTED_KEYS = array(
		'id',
		'name',
		'slug',
		'count',
		'description',
	);

	public function test_ability_is_registered(): void {
		$ability = wp_get_ability( 'wc-products/update-shipping-class' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'wc-products/update-shipping-class', $ability->get_name() );
	}

	public function test_admin_updates_shipping_class_name(): void {
		$this->actingAs( 'administrator' );

		$id = $this->seedShippingClass( 'Heavy', '' );

		$result = wp_get_ability( 'wc-products/update-shipping-class' )->execute(
			array(
				'id'   => $id,
				'name' => 'Bulky',
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( $id, $result['id'] );
		$this->assertSame( 'Bulky', $result['name'] );

		// The change persisted to the live term.
		$this->assertSame( 'Bulky', get_term( $id, 'product_shipping_class' )->name );
	}

	public function test_admin_updates_description_only_keeps_name(): void {
		$this->actingAs( 'administrator' );

		$id = $this->seedShippingClass( 'Fragile', 'Old note' );

		$result = wp_get_ability( 'wc-products/update-shipping-class' )->execute(
			array(
				'id'          => $id,
				'description' => 'Handle with care',
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'Handle with care', $result['description'] );

		// The omitted name field is left unchanged.
		$this->assertSame( 'Fragile', $result['name'] );
		$this->assertSame( 'Fragile', get_term( $id, 'product_shipping_class' )->name );
	}

	public function test_output_shape_is_exact_and_closed(): void {
		$this->actingAs( 'administrator' );

		$id = $this->seedShippingClass( 'Oversize', '' );

		$result = wp_get_ability( 'wc-products/update-shipping-class' )->execute(
			array(
				'id'   => $id,
				'name' => 'Extra Large',
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( self::EXPECTED_KEYS, array_keys( $result ) );

		// Shipping classes are flat: no parent, and no raw term fields leak through.
		$this->assertArrayNotHasKey( 'parent', $result );
		$this->assertArrayNotHasKey( 'taxonomy', $result );
		$this->assertArrayNotHasKey( '_links', $result );
	}

	public function test_missing_class_returns_404_not_permission_error(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'wc-products/update-shipping-class' )->execute(
			array(
				'id'   => 99999999,
				'name' => 'Ghost',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'woocommerce_rest_term_invalid', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] );
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_subscriber_is_denied_and_class_unchanged(): void {
		$id = $this->seedShippingClass( 'Heavy', '' );

		$this->actingAs( 'subscriber' );

		$ability = wp_get_ability( 'wc-products/update-shipping-class' );

		$this->assertFalse(
			$ability->check_permissions(
				array(
					'id'   => $id,
					'name' => 'Bulky',
				)
			)
		);

		$result = $ability->execute(
			array(
				'id'   => $id,
				'name' => 'Bulky',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );

		// The denied write did not change the class.
		$this->assertSame( 'Heavy', get_term( $id, 'product_shipping_class' )->name );
	}

	/**
	 * Seeds a product_shipping_class term and returns its ID.
	 *
	 * @param string $name        The shipping class name.
	 * @param string $description The shipping class description.
	 * @return int The created term ID.
	 */
	private function seedShippingClass( string $name, string $description ): int {
		$term = wp_insert_term(
			$name,
			'product_shipping_class',
			array(
				'description' => $description,
			)
		);

		$this->assertIsArray( $term );

		return (int) $term['term_id'];
	}
}
