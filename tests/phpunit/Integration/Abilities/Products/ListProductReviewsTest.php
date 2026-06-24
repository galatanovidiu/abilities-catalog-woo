<?php
/**
 * Integration tests for the `wc-products/list-product-reviews` ability.
 *
 * @package AbilitiesCatalogWoo\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogWoo\Tests\Integration\Abilities\Products;

use GalatanOvidiu\AbilitiesCatalogWoo\Tests\TestCase;
use WC_Product_Simple;
use WP_Error;

/**
 * @covers \GalatanOvidiu\AbilitiesCatalogWoo\Abilities\Woo\Products\ListProductReviews
 */
final class ListProductReviewsTest extends TestCase {

	private const ABILITY = 'wc-products/list-product-reviews';

	/**
	 * The exact keys a shaped review summary row exposes.
	 *
	 * Asserting against this fixed set proves the raw comment/review body — which
	 * carries `date_created_gmt`, `product_permalink`, `reviewer_avatar_urls`, and
	 * `_links` — is never leaked, while confirming `reviewer_email` IS present by
	 * design (this is an admin moderation tool gated on `moderate_comments`).
	 *
	 * @var list<string>
	 */
	private const ROW_KEYS = array(
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

	public function test_registered(): void {
		$ability = wp_get_ability( self::ABILITY );

		$this->assertNotNull( $ability );
		$this->assertSame( self::ABILITY, $ability->get_name() );
	}

	public function test_happy_path_returns_shaped_rows(): void {
		$this->actingAs( 'administrator' );
		$product_id = $this->seedProduct( 'Reviewed Product' );
		$this->seedReview( $product_id, 'Ada Reviewer', 'ada@example.org', 'Great product, <strong>loved</strong> it.', 5 );

		$result = wp_get_ability( self::ABILITY )->execute( array() );

		$this->assertIsArray( $result );
		$this->assertSame( array( 'items', 'total' ), array_keys( $result ) );
		$this->assertIsArray( $result['items'] );
		$this->assertIsInt( $result['total'] );
		$this->assertGreaterThanOrEqual( 1, $result['total'] );
		$this->assertNotEmpty( $result['items'] );

		$row = $result['items'][0];
		$this->assertSame( self::ROW_KEYS, array_keys( $row ) );
		$this->assertIsInt( $row['id'] );
		$this->assertIsInt( $row['product_id'] );
		$this->assertIsString( $row['product_name'] );
		$this->assertIsString( $row['status'] );
		$this->assertIsString( $row['reviewer'] );
		$this->assertIsString( $row['reviewer_email'] );
		$this->assertIsInt( $row['rating'] );
		$this->assertIsString( $row['review'] );
		$this->assertIsBool( $row['verified'] );
		$this->assertIsString( $row['date_created'] );

		// The review excerpt is plain text: the controller's wpautop()/HTML is stripped.
		$this->assertStringNotContainsString( '<', $row['review'] );
		$this->assertStringContainsString( 'loved', $row['review'] );
		$this->assertSame( 'ada@example.org', $row['reviewer_email'] );
		$this->assertSame( 5, $row['rating'] );
	}

	public function test_status_filter_narrows_results(): void {
		$this->actingAs( 'administrator' );
		$product_id = $this->seedProduct( 'Status Filter Product' );
		$this->seedReview( $product_id, 'Approved Author', 'approved@example.org', 'An approved review.', 4, 1 );
		$this->seedReview( $product_id, 'Held Author', 'held@example.org', 'A held review.', 2, 0 );

		// Default status is "approved": the held review must not appear.
		$approved = wp_get_ability( self::ABILITY )->execute( array() );
		$approved_emails = wp_list_pluck( $approved['items'], 'reviewer_email' );
		$this->assertContains( 'approved@example.org', $approved_emails );
		$this->assertNotContains( 'held@example.org', $approved_emails );

		// status=hold surfaces the held review and hides the approved one.
		$held = wp_get_ability( self::ABILITY )->execute( array( 'status' => 'hold' ) );
		$held_emails = wp_list_pluck( $held['items'], 'reviewer_email' );
		$this->assertContains( 'held@example.org', $held_emails );
		$this->assertNotContains( 'approved@example.org', $held_emails );
	}

	public function test_product_filter_narrows_results(): void {
		$this->actingAs( 'administrator' );
		$product_a = $this->seedProduct( 'Product A' );
		$product_b = $this->seedProduct( 'Product B' );
		$this->seedReview( $product_a, 'On A', 'on-a@example.org', 'Review on A.', 5 );
		$this->seedReview( $product_b, 'On B', 'on-b@example.org', 'Review on B.', 5 );

		$result = wp_get_ability( self::ABILITY )->execute( array( 'product' => array( $product_a ) ) );

		$emails = wp_list_pluck( $result['items'], 'reviewer_email' );
		$this->assertContains( 'on-a@example.org', $emails );
		$this->assertNotContains( 'on-b@example.org', $emails );

		foreach ( $result['items'] as $row ) {
			$this->assertSame( $product_a, $row['product_id'] );
		}
	}

	public function test_output_shape_has_no_raw_review_fields(): void {
		$this->actingAs( 'administrator' );
		$product_id = $this->seedProduct( 'Shape Product' );
		$this->seedReview( $product_id, 'Shape Author', 'shape@example.org', 'Shape review.', 3 );

		$result = wp_get_ability( self::ABILITY )->execute( array() );

		$this->assertSame( array( 'items', 'total' ), array_keys( $result ) );
		$row = $result['items'][0];
		$this->assertSame( self::ROW_KEYS, array_keys( $row ) );
		$this->assertArrayHasKey( 'reviewer_email', $row );
		$this->assertArrayNotHasKey( 'date_created_gmt', $row );
		$this->assertArrayNotHasKey( 'product_permalink', $row );
		$this->assertArrayNotHasKey( 'reviewer_avatar_urls', $row );
		$this->assertArrayNotHasKey( '_links', $row );
	}

	public function test_wrong_capability_is_denied(): void {
		$product_id = $this->seedProduct( 'Denied Product' );
		$this->seedReview( $product_id, 'Author', 'author@example.org', 'A review.', 5 );
		// A subscriber lacks moderate_comments.
		$this->actingAs( 'subscriber' );

		$result = wp_get_ability( self::ABILITY )->execute( array() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_logged_out_user_is_denied(): void {
		$product_id = $this->seedProduct( 'Logged Out Product' );
		$this->seedReview( $product_id, 'Author', 'author@example.org', 'A review.', 5 );
		wp_set_current_user( 0 );

		$result = wp_get_ability( self::ABILITY )->execute( array() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	/**
	 * Seeds a simple published product via the WooCommerce runtime object API.
	 *
	 * The distributed `woocommerce.zip` ships no `tests/` framework, so the
	 * `WC_Helper_*` factories do not exist; the runtime `WC_Product_Simple` class
	 * loads with the plugin and is the supported way to seed. A non-empty name is
	 * always set so the product can be published.
	 *
	 * @param string $name The product name.
	 * @return int The created product ID.
	 */
	private function seedProduct( string $name ): int {
		$product = new WC_Product_Simple();
		$product->set_name( $name );
		$product->set_status( 'publish' );
		$product->set_regular_price( '10.00' );

		return (int) $product->save();
	}

	/**
	 * Seeds a product review as a `comment_type='review'` comment on a product.
	 *
	 * A WooCommerce product review IS a WordPress comment of type `review` on the
	 * product post, with the star rating stored in the `rating` comment meta. The
	 * `comment_approved` flag is 1 for an approved review and 0 for a held review.
	 *
	 * @param int    $product_id The product post ID.
	 * @param string $author     The reviewer name.
	 * @param string $email      The reviewer email.
	 * @param string $content    The review content.
	 * @param int    $rating     The star rating (0-5).
	 * @param int    $approved   1 for approved, 0 for held (awaiting moderation).
	 * @return int The created comment ID.
	 */
	private function seedReview( int $product_id, string $author, string $email, string $content, int $rating, int $approved = 1 ): int {
		$comment_id = wp_insert_comment(
			array(
				'comment_post_ID'      => $product_id,
				'comment_type'         => 'review',
				'comment_approved'     => $approved,
				'comment_author'       => $author,
				'comment_author_email' => $email,
				'comment_content'      => $content,
			)
		);

		$this->assertIsInt( $comment_id );
		$this->assertGreaterThan( 0, $comment_id );

		update_comment_meta( $comment_id, 'rating', $rating );

		return (int) $comment_id;
	}
}
