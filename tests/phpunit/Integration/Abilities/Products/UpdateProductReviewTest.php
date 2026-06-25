<?php
/**
 * Integration tests for the og-wc-products/update-product-review ability.
 *
 * @package AbilitiesCatalogWoo\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogWoo\Tests\Integration\Abilities\Products;

use GalatanOvidiu\AbilitiesCatalogWoo\Tests\TestCase;
use WC_Product_Simple;
use WP_Error;

/**
 * Exercises og-wc-products/update-product-review: a review-text + rating edit on a
 * seeded review, the status moderation lever (hold un-approves the underlying
 * comment), the missing-review 404 that must not collapse to a permission error,
 * the wrong-capability denial (with the review unchanged), and the exact closed
 * output shape including reviewer_email (no raw comment fields leak).
 */
final class UpdateProductReviewTest extends TestCase {

	/**
	 * The closed key set the ability returns for one updated review row.
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

	public function test_ability_is_registered(): void {
		$ability = wp_get_ability( 'og-wc-products/update-product-review' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'og-wc-products/update-product-review', $ability->get_name() );
	}

	public function test_admin_updates_review_text_and_rating(): void {
		$this->actingAs( 'administrator' );

		$product_id = $this->seedProduct( 'Seeded Product' );
		$review_id  = $this->seedReview( $product_id, 'Ada Reviewer', 'ada@example.com', 'It was fine.', 3 );

		$result = wp_get_ability( 'og-wc-products/update-product-review' )->execute(
			array(
				'id'     => $review_id,
				'review' => 'Actually it is excellent.',
				'rating' => 5,
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( $review_id, $result['id'] );
		$this->assertSame( 'Actually it is excellent.', $result['review'] );
		$this->assertSame( 5, $result['rating'] );

		// The changes persisted to the live comment and its rating meta.
		$this->assertSame( 'Actually it is excellent.', get_comment( $review_id )->comment_content );
		$this->assertSame( 5, (int) get_comment_meta( $review_id, 'rating', true ) );
	}

	public function test_status_hold_moderates_the_review(): void {
		$this->actingAs( 'administrator' );

		$product_id = $this->seedProduct( 'Seeded Product' );
		$review_id  = $this->seedReview( $product_id, 'Bob Buyer', 'bob@example.com', 'Solid.', 4 );

		$result = wp_get_ability( 'og-wc-products/update-product-review' )->execute(
			array(
				'id'     => $review_id,
				'status' => 'hold',
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'hold', $result['status'] );

		// The moderation primitive ran: the underlying comment is now unapproved.
		$this->assertSame( 'unapproved', wp_get_comment_status( $review_id ) );
	}

	public function test_output_shape_is_exact_and_closed(): void {
		$this->actingAs( 'administrator' );

		$product_id = $this->seedProduct( 'Seeded Product' );
		$review_id  = $this->seedReview( $product_id, 'Cara Customer', 'cara@example.com', 'Nice.', 4 );

		$result = wp_get_ability( 'og-wc-products/update-product-review' )->execute(
			array(
				'id'     => $review_id,
				'review' => 'Updated.',
			)
		);

		$this->assertIsArray( $result );

		$keys = array_keys( $result );
		sort( $keys );
		$expected = self::EXPECTED_KEYS;
		sort( $expected );
		$this->assertSame( $expected, $keys );

		// The reviewer email is included by design (moderation tool).
		$this->assertArrayHasKey( 'reviewer_email', $result );
		$this->assertSame( 'cara@example.com', $result['reviewer_email'] );

		// No raw comment fields leak through.
		$this->assertArrayNotHasKey( 'reviewer_avatar_urls', $result );
		$this->assertArrayNotHasKey( 'comment_author_IP', $result );
		$this->assertArrayNotHasKey( 'product_permalink', $result );
		$this->assertArrayNotHasKey( '_links', $result );
	}

	public function test_missing_review_returns_404_not_permission_error(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'og-wc-products/update-product-review' )->execute(
			array(
				'id'     => 99999999,
				'review' => 'Ghost edit.',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'woocommerce_rest_review_invalid_id', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] );
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_subscriber_is_denied_and_review_unchanged(): void {
		$product_id = $this->seedProduct( 'Seeded Product' );
		$review_id  = $this->seedReview( $product_id, 'Ada Reviewer', 'ada@example.com', 'Original text.', 5 );

		$this->actingAs( 'subscriber' );

		$ability = wp_get_ability( 'og-wc-products/update-product-review' );

		$this->assertFalse(
			$ability->check_permissions(
				array(
					'id'     => $review_id,
					'review' => 'Hacked text.',
				)
			)
		);

		$result = $ability->execute(
			array(
				'id'     => $review_id,
				'review' => 'Hacked text.',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );

		// The denied write did not change the review.
		$this->assertSame( 'Original text.', get_comment( $review_id )->comment_content );
	}

	/**
	 * Seeds a published simple product via the WooCommerce runtime object API and
	 * returns its ID. The distributed woocommerce.zip ships no test framework, so
	 * the WC_Helper_* factories do not exist; the runtime classes always load with
	 * the plugin.
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
	 * Seeds an approved product review (a comment of type `review`) on the product
	 * and returns the comment ID. A WooCommerce review is a WordPress comment.
	 *
	 * @param int    $product_id The product the review belongs to.
	 * @param string $author     The reviewer name.
	 * @param string $email      The reviewer email.
	 * @param string $content    The review text.
	 * @param int    $rating     The star rating (stored as the `rating` comment meta).
	 * @return int The created comment (review) ID.
	 */
	private function seedReview( int $product_id, string $author, string $email, string $content, int $rating ): int {
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

		update_comment_meta( (int) $comment_id, 'rating', $rating );

		return (int) $comment_id;
	}
}
