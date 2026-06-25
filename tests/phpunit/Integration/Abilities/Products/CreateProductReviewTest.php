<?php
/**
 * Integration tests for the og-wc-products/create-product-review ability.
 *
 * @package AbilitiesCatalogWoo\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogWoo\Tests\Integration\Abilities\Products;

use GalatanOvidiu\AbilitiesCatalogWoo\Tests\TestCase;
use WC_Product_Simple;
use WP_Error;

/**
 * Exercises og-wc-products/create-product-review: the happy path that creates an
 * approved review on a seeded product, the invalid product_id that surfaces
 * WooCommerce's woocommerce_rest_product_invalid_id 404 (not a permission collapse),
 * the wrong-capability denial that creates nothing, and the exact closed output
 * shape (reviewer_email present, no raw comment fields leak).
 */
final class CreateProductReviewTest extends TestCase {

	/**
	 * The full closed key set the ability returns: the shaped review row.
	 *
	 * @var list<string>
	 */
	private const EXPECTED_KEYS = array(
		'id',
		'product_id',
		'product_name',
		'status',
		'reviewer',
		'reviewer_email',
		'rating',
		'review',
		'verified',
		'date_created',
	);

	/**
	 * Seeds a published simple product and returns its id.
	 *
	 * @return int The product id.
	 */
	private function seedProduct(): int {
		$product = new WC_Product_Simple();
		$product->set_name( 'Seeded Product' );
		$product->set_status( 'publish' );
		$product->set_regular_price( '10.00' );

		return (int) $product->save();
	}

	public function test_ability_is_registered(): void {
		$ability = wp_get_ability( 'og-wc-products/create-product-review' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'og-wc-products/create-product-review', $ability->get_name() );
	}

	public function test_admin_creates_an_approved_review(): void {
		$this->actingAs( 'administrator' );

		$product_id = $this->seedProduct();

		$result = wp_get_ability( 'og-wc-products/create-product-review' )->execute(
			array(
				'product_id'     => $product_id,
				'reviewer'       => 'Sam',
				'reviewer_email' => 'sam@example.com',
				'review'         => 'Great',
				'rating'         => 5,
			)
		);

		$this->assertIsArray( $result );
		$this->assertGreaterThan( 0, $result['id'] );
		$this->assertSame( $product_id, $result['product_id'] );
		$this->assertSame( 5, $result['rating'] );
		$this->assertSame( 'approved', $result['status'] );
		$this->assertSame( 'Sam', $result['reviewer'] );

		// The review persisted as an approved comment of type review on the product.
		$comment = get_comment( $result['id'] );
		$this->assertInstanceOf( \WP_Comment::class, $comment );
		$this->assertSame( 'review', $comment->comment_type );
		$this->assertSame( (string) $product_id, $comment->comment_post_ID );
		$this->assertSame( 'approved', wp_get_comment_status( $result['id'] ) );
	}

	public function test_invalid_product_id_returns_404_not_permission_error(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'og-wc-products/create-product-review' )->execute(
			array(
				'product_id'     => 99999999,
				'reviewer'       => 'Sam',
				'reviewer_email' => 'sam@example.com',
				'review'         => 'Great',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'woocommerce_rest_product_invalid_id', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] );
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_subscriber_is_denied_and_no_review_created(): void {
		$this->actingAs( 'subscriber' );

		$product_id = $this->seedProduct();

		$ability = wp_get_ability( 'og-wc-products/create-product-review' );

		$this->assertFalse(
			$ability->check_permissions(
				array(
					'product_id'     => $product_id,
					'reviewer'       => 'Sam',
					'reviewer_email' => 'sam@example.com',
					'review'         => 'Great',
				)
			)
		);

		$result = $ability->execute(
			array(
				'product_id'     => $product_id,
				'reviewer'       => 'Sam',
				'reviewer_email' => 'sam@example.com',
				'review'         => 'Great',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );

		// The denied write created no review on the product.
		$this->assertSame( 0, (int) get_comments( array( 'post_id' => $product_id, 'count' => true ) ) );
	}

	public function test_output_shape_is_exact_and_closed(): void {
		$this->actingAs( 'administrator' );

		$product_id = $this->seedProduct();

		$result = wp_get_ability( 'og-wc-products/create-product-review' )->execute(
			array(
				'product_id'     => $product_id,
				'reviewer'       => 'Sam',
				'reviewer_email' => 'sam@example.com',
				'review'         => 'Great',
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( self::EXPECTED_KEYS, array_keys( $result ) );

		// The reviewer email is included by design (admin tool gated on edit_products).
		$this->assertSame( 'sam@example.com', $result['reviewer_email'] );

		// Raw comment fields do not leak into the row.
		$this->assertArrayNotHasKey( 'comment_author', $result );
		$this->assertArrayNotHasKey( 'comment_post_ID', $result );
		$this->assertArrayNotHasKey( '_links', $result );
		$this->assertArrayNotHasKey( 'reviewer_avatar_urls', $result );
	}
}
