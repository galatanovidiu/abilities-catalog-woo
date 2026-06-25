<?php
/**
 * Integration tests for the og-wc-products/delete-product-review ability.
 *
 * @package AbilitiesCatalogWoo\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogWoo\Tests\Integration\Abilities\Products;

use GalatanOvidiu\AbilitiesCatalogWoo\Tests\TestCase;
use WC_Product_Simple;
use WP_Error;

/**
 * Exercises og-wc-products/delete-product-review: the permanent (force=true) delete and
 * its removal of the comment, the recoverable (force=false) trash, the missing-review
 * 404 that must not collapse to a permission error, the wrong-capability denial, and
 * the exact closed output shape (no edit_link, no raw comment fields).
 */
final class DeleteProductReviewTest extends TestCase {

	/**
	 * The closed key set the ability returns.
	 *
	 * @var list<string>
	 */
	private const EXPECTED_KEYS = array(
		'deleted',
		'id',
		'reviewer',
		'product_name',
		'force_used',
		'permanent',
	);

	public function test_ability_is_registered(): void {
		$ability = wp_get_ability( 'og-wc-products/delete-product-review' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'og-wc-products/delete-product-review', $ability->get_name() );
	}

	public function test_force_delete_removes_the_review(): void {
		$this->actingAs( 'administrator' );

		$product_id = $this->seedProduct( 'Seeded Product' );
		$review_id  = $this->seedReview( $product_id, 'Ada Reviewer', 'ada@example.com', 'Great product.' );

		$result = wp_get_ability( 'og-wc-products/delete-product-review' )->execute(
			array(
				'id'    => $review_id,
				'force' => true,
			)
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['deleted'] );
		$this->assertSame( $review_id, $result['id'] );
		$this->assertSame( 'Ada Reviewer', $result['reviewer'] );
		$this->assertSame( 'Seeded Product', $result['product_name'] );
		$this->assertTrue( $result['force_used'] );
		$this->assertTrue( $result['permanent'] );

		// The comment is permanently gone.
		$this->assertNull( get_comment( $review_id ) );
	}

	public function test_default_force_false_trashes_the_review(): void {
		$this->actingAs( 'administrator' );

		$product_id = $this->seedProduct( 'Seeded Product' );
		$review_id  = $this->seedReview( $product_id, 'Bob Buyer', 'bob@example.com', 'Solid.' );

		$result = wp_get_ability( 'og-wc-products/delete-product-review' )->execute( array( 'id' => $review_id ) );

		if ( EMPTY_TRASH_DAYS > 0 ) {
			// Trash enabled: the review is moved to the Trash (recoverable), not removed.
			$this->assertIsArray( $result );
			$this->assertTrue( $result['deleted'] );
			$this->assertFalse( $result['force_used'] );
			$this->assertFalse( $result['permanent'] );
			$this->assertSame( 'trash', wp_get_comment_status( $review_id ) );
		} else {
			// Trash disabled: the route refuses a non-force delete with a 501.
			$this->assertInstanceOf( WP_Error::class, $result );
			$this->assertSame( 'woocommerce_rest_trash_not_supported', $result->get_error_code() );
			$this->assertSame( 501, $result->get_error_data()['status'] );
			$this->assertNotNull( get_comment( $review_id ) );
		}
	}

	public function test_output_shape_is_exact_and_closed(): void {
		$this->actingAs( 'administrator' );

		$product_id = $this->seedProduct( 'Seeded Product' );
		$review_id  = $this->seedReview( $product_id, 'Cara Customer', 'cara@example.com', 'Nice.' );

		$result = wp_get_ability( 'og-wc-products/delete-product-review' )->execute(
			array(
				'id'    => $review_id,
				'force' => true,
			)
		);

		$this->assertIsArray( $result );

		$keys = array_keys( $result );
		sort( $keys );
		$expected = self::EXPECTED_KEYS;
		sort( $expected );
		$this->assertSame( $expected, $keys );

		// No dead-end edit link, and no raw comment/review fields leak through.
		$this->assertArrayNotHasKey( 'edit_link', $result );
		$this->assertArrayNotHasKey( 'previous', $result );
		$this->assertArrayNotHasKey( 'reviewer_email', $result );
		$this->assertArrayNotHasKey( '_links', $result );

		$this->assertIsBool( $result['deleted'] );
		$this->assertIsInt( $result['id'] );
		$this->assertIsString( $result['reviewer'] );
		$this->assertIsString( $result['product_name'] );
		$this->assertIsBool( $result['force_used'] );
		$this->assertIsBool( $result['permanent'] );
	}

	public function test_missing_review_returns_404_not_permission_error(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'og-wc-products/delete-product-review' )->execute(
			array(
				'id'    => 99999999,
				'force' => true,
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'woocommerce_rest_review_invalid_id', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] );
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_subscriber_is_denied_and_review_survives(): void {
		$product_id = $this->seedProduct( 'Seeded Product' );
		$review_id  = $this->seedReview( $product_id, 'Ada Reviewer', 'ada@example.com', 'Great.' );

		$this->actingAs( 'subscriber' );

		$ability = wp_get_ability( 'og-wc-products/delete-product-review' );

		// A subscriber lacks edit_products, so the coarse permission check denies.
		$this->assertFalse( $ability->check_permissions( array( 'id' => $review_id ) ) );

		$result = $ability->execute(
			array(
				'id'    => $review_id,
				'force' => true,
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );

		// The review survived the denied call.
		$this->assertNotNull( get_comment( $review_id ) );
	}

	/**
	 * Seeds a published simple product via the WooCommerce runtime object API and
	 * returns its ID. The distributed woocommerce.zip ships no test framework, so the
	 * WC_Helper_* factories do not exist; the runtime classes always load with the plugin.
	 *
	 * @param string $name The product name (must be non-empty).
	 * @return int The created product ID.
	 */
	private function seedProduct( string $name ): int {
		$product = new WC_Product_Simple();
		$product->set_name( $name );
		$product->set_status( 'publish' );
		$product->set_regular_price( '10.00' );
		$product->save();

		return (int) $product->get_id();
	}

	/**
	 * Seeds an approved product review (a comment of type `review`) on the product and
	 * returns the comment ID. A WooCommerce review is a WordPress comment.
	 *
	 * @param int    $product_id The product the review belongs to.
	 * @param string $author     The reviewer name.
	 * @param string $email      The reviewer email.
	 * @param string $content    The review text.
	 * @return int The created comment (review) ID.
	 */
	private function seedReview( int $product_id, string $author, string $email, string $content ): int {
		$comment_id = wp_insert_comment(
			array(
				'comment_post_ID'      => $product_id,
				'comment_type'         => 'review',
				'comment_approved'     => 1,
				'comment_author'       => $author,
				'comment_author_email' => $email,
				'comment_content'      => $content,
			)
		);

		$this->assertIsInt( $comment_id );
		$this->assertGreaterThan( 0, $comment_id );

		return (int) $comment_id;
	}
}
