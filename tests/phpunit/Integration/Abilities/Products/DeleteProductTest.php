<?php
/**
 * Integration tests for the og-wc-products/delete-product ability.
 *
 * @package AbilitiesCatalogWoo\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogWoo\Tests\Integration\Abilities\Products;

use GalatanOvidiu\AbilitiesCatalogWoo\Tests\TestCase;
use WP_Error;
use WC_Product_Simple;

/**
 * Exercises og-wc-products/delete-product: the permanent force=true delete (product
 * gone), the recoverable force=false trash (or the 501 when Trash is disabled),
 * the route's specific 404 for a missing product surfaced via RestError (not a
 * permission collapse), the wrong-cap denial leaving the product intact, and the
 * exact closed output shape.
 */
final class DeleteProductTest extends TestCase {

	/**
	 * The closed key set the ability returns.
	 *
	 * @var list<string>
	 */
	private const EXPECTED_KEYS = array(
		'deleted',
		'id',
		'name',
		'force_used',
		'permanent',
	);

	/**
	 * Seeds a published simple product and returns its id.
	 *
	 * @param string $name The product name.
	 * @return int The product id.
	 */
	private function seedProduct( string $name = 'Seeded Product' ): int {
		$product = new WC_Product_Simple();
		$product->set_name( $name );
		$product->set_status( 'publish' );
		$product->set_regular_price( '10.00' );

		return (int) $product->save();
	}

	public function test_ability_is_registered(): void {
		$ability = wp_get_ability( 'og-wc-products/delete-product' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'og-wc-products/delete-product', $ability->get_name() );
	}

	public function test_force_true_permanently_deletes_product(): void {
		$this->actingAs( 'administrator' );

		$id = $this->seedProduct( 'Doomed Product' );

		$result = wp_get_ability( 'og-wc-products/delete-product' )->execute(
			array(
				'id'    => $id,
				'force' => true,
			)
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['deleted'] );
		$this->assertTrue( $result['permanent'] );
		$this->assertTrue( $result['force_used'] );
		$this->assertSame( $id, $result['id'] );
		$this->assertSame( 'Doomed Product', $result['name'] );

		// The product is gone from the store.
		$this->assertFalse( wc_get_product( $id ) );
	}

	public function test_force_false_trashes_or_501_when_trash_disabled(): void {
		$this->actingAs( 'administrator' );

		$id = $this->seedProduct( 'Trashable Product' );

		$result = wp_get_ability( 'og-wc-products/delete-product' )->execute(
			array(
				'id'    => $id,
				'force' => false,
			)
		);

		if ( EMPTY_TRASH_DAYS > 0 ) {
			// Trash is enabled: the product is moved to the Trash, recoverable.
			$this->assertIsArray( $result );
			$this->assertTrue( $result['deleted'] );
			$this->assertFalse( $result['permanent'] );
			$this->assertFalse( $result['force_used'] );
			$this->assertSame( 'trash', get_post_status( $id ) );
		} else {
			// Trash is disabled: the route rejects a force=false delete with 501.
			$this->assertInstanceOf( WP_Error::class, $result );
			$this->assertSame( 'woocommerce_rest_trash_not_supported', $result->get_error_code() );
			$this->assertSame( 501, $result->get_error_data()['status'] );
			$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code() );
		}
	}

	public function test_missing_product_returns_route_404_not_permission_error(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'og-wc-products/delete-product' )->execute(
			array(
				'id'    => 99999999,
				'force' => true,
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'woocommerce_rest_product_invalid_id', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] );
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_subscriber_is_denied_and_product_survives(): void {
		$id = $this->seedProduct( 'Protected Product' );

		$this->actingAs( 'subscriber' );

		$ability = wp_get_ability( 'og-wc-products/delete-product' );

		$this->assertFalse( $ability->check_permissions( array( 'id' => $id ) ) );

		$result = $ability->execute(
			array(
				'id'    => $id,
				'force' => true,
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );

		// The product survived the denied delete unchanged.
		$this->assertNotFalse( wc_get_product( $id ) );
	}

	public function test_output_shape_is_exact_and_closed(): void {
		$this->actingAs( 'administrator' );

		$id = $this->seedProduct( 'Shape Product' );

		$result = wp_get_ability( 'og-wc-products/delete-product' )->execute(
			array(
				'id'    => $id,
				'force' => true,
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( self::EXPECTED_KEYS, array_keys( $result ) );

		// No edit_link to a gone product, and no raw product body leaks through.
		$this->assertArrayNotHasKey( 'edit_link', $result );
		$this->assertArrayNotHasKey( 'meta_data', $result );
		$this->assertArrayNotHasKey( '_links', $result );
	}
}
