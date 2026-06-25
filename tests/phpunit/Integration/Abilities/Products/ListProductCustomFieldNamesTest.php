<?php
/**
 * Integration tests for the `og-wc-products/list-product-custom-field-names` ability.
 *
 * @package AbilitiesCatalogWoo\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogWoo\Tests\Integration\Abilities\Products;

use GalatanOvidiu\AbilitiesCatalogWoo\Tests\TestCase;
use WC_Product_Simple;
use WP_Error;

/**
 * @covers \GalatanOvidiu\AbilitiesCatalogWoo\Abilities\Woo\Products\ListProductCustomFieldNames
 */
final class ListProductCustomFieldNamesTest extends TestCase {

	private const ABILITY = 'og-wc-products/list-product-custom-field-names';

	/**
	 * Seeds a published product carrying a public custom-field meta key.
	 *
	 * The WC route reads distinct, non-underscore-prefixed product meta keys
	 * straight from the postmeta table, so the key must be public to surface.
	 *
	 * @param string $meta_key The public meta key to attach.
	 * @return int The created product ID.
	 */
	private function seed_product_with_meta( string $meta_key ): int {
		$product = new WC_Product_Simple();
		$product->set_regular_price( '10' );
		$product->set_status( 'publish' );
		$product_id = $product->save();

		update_post_meta( $product_id, $meta_key, 'some value' );

		return $product_id;
	}

	public function test_registered(): void {
		$ability = wp_get_ability( self::ABILITY );

		$this->assertNotNull( $ability );
		$this->assertSame( self::ABILITY, $ability->get_name() );
	}

	public function test_happy_path_returns_seeded_custom_field_name(): void {
		$this->actingAs( 'administrator' );
		$this->seed_product_with_meta( 'gtin_code' );

		$result = wp_get_ability( self::ABILITY )->execute( array() );

		$this->assertIsArray( $result );
		$this->assertIsArray( $result['names'] );
		$this->assertContains( 'gtin_code', $result['names'] );
		$this->assertIsInt( $result['total'] );
		$this->assertGreaterThanOrEqual( 1, $result['total'] );
	}

	public function test_names_are_plain_strings_with_no_private_keys(): void {
		$this->actingAs( 'administrator' );
		$this->seed_product_with_meta( 'warranty_length' );

		$result = wp_get_ability( self::ABILITY )->execute( array() );

		foreach ( $result['names'] as $name ) {
			$this->assertIsString( $name );
			$this->assertStringStartsNotWith( '_', $name );
		}
	}

	public function test_search_narrows_results(): void {
		$this->actingAs( 'administrator' );
		$this->seed_product_with_meta( 'flavour_profile' );
		$this->seed_product_with_meta( 'shipping_zone_label' );

		$result = wp_get_ability( self::ABILITY )->execute( array( 'search' => 'flavour' ) );

		$this->assertContains( 'flavour_profile', $result['names'] );
		$this->assertNotContains( 'shipping_zone_label', $result['names'] );
	}

	public function test_output_shape_is_exactly_names_and_total(): void {
		$this->actingAs( 'administrator' );
		$this->seed_product_with_meta( 'origin_country' );

		$result = wp_get_ability( self::ABILITY )->execute( array() );

		$this->assertSame( array( 'names', 'total' ), array_keys( $result ) );
	}

	public function test_wrong_capability_is_denied(): void {
		$this->actingAs( 'subscriber' );

		$result = wp_get_ability( self::ABILITY )->execute( array() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}
}
