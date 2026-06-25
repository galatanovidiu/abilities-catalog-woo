<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogWoo\Abilities\Woo\Products;

use GalatanOvidiu\AbilitiesCatalogWoo\Contracts\ConditionalAbility;
use GalatanOvidiu\AbilitiesCatalogWoo\Support\ProductReviewListShaper;
use GalatanOvidiu\AbilitiesCatalogWoo\Support\RestError;
use GalatanOvidiu\AbilitiesCatalogWoo\Support\WooPlugin;
use WP_REST_Request;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Write ability: `og-wc-products/update-product-review`.
 *
 * Wraps `PUT wc/v3/products/reviews/<id>` via `rest_do_request()`, updating an
 * existing WooCommerce product review (a WordPress comment on a product,
 * `comment_type = 'review'`). The `id` is concatenated into the route path and
 * every editable field is forwarded only when the caller sends it, so an omitted
 * field keeps its current value. The result is the same flat, closed review row
 * the read abilities return through {@see ProductReviewListShaper::reviewSummary()}
 * — id, product_id, product_name, status, reviewer, reviewer_email, rating,
 * review, verified, date_created.
 *
 * Moderation lever: the `status` field is how a review is moderated. The wrapped
 * controller's `handle_status_param()` maps each value to a WordPress comment
 * primitive — `approved`→`wp_set_comment_status(..,'approve')`, `hold`→`..'hold'`,
 * `spam`→`wp_spam_comment()`, `unspam`→`wp_unspam_comment()`,
 * `trash`→`wp_trash_comment()`, `untrash`→`wp_untrash_comment()`. An update that
 * changes only `status` therefore moderates the review (approve/hold/spam/trash);
 * there is no separate moderation ability. `trash` is a recoverable moderation
 * state, not a permanent delete (that is the future delete-product-review
 * ability), so this stays `destructive:false`.
 *
 * PII: `reviewer_email` is returned by design. This is an admin moderation tool
 * whose hard guard is `edit_products`, so surfacing it exposes nothing the
 * capability does not already grant.
 *
 * A missing review surfaces the wrapped route's specific
 * `woocommerce_rest_review_invalid_id` 404 via {@see RestError::from()} rather than
 * collapsing to a generic permission denial.
 *
 * Only available when WooCommerce is active (it is a {@see ConditionalAbility}).
 *
 * @since 0.1.0
 */
final class UpdateProductReview implements ConditionalAbility {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-wc-products/update-product-review';
	}

	/**
	 * {@inheritDoc}
	 */
	public function isAvailable(): bool {
		return WooPlugin::isActive();
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		$output_schema = ProductReviewListShaper::reviewItemSchema();

		return array(
			'label'               => __( 'Update Product Review', 'abilities-catalog-woo' ),
			'description'         => __( 'Updates an existing WooCommerce product review by ID and returns the shaped review row (id, product_id, product_name, status, reviewer, reviewer_email, rating, review, verified, date_created). Send only the fields you want to change; an omitted field keeps its current value. The status field is the moderation lever: "approved" approves/publishes the review, "hold" un-approves it (back to the moderation queue), "spam" marks it spam, "unspam" restores it from spam, "trash" moves it to trash, and "untrash" restores it from trash. An update that changes only status moderates the review, so this ability IS the approve/hold/spam/trash action — there is no separate moderation ability. trash here is a recoverable moderation state, not a permanent delete. A product review is a WordPress comment on a product; reviewer_email is returned because this is an admin moderation tool gated on edit_products. Discover IDs with og-wc-products/list-product-reviews.', 'abilities-catalog-woo' ),
			'category'            => 'og-wc-products',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'id' ),
				'properties'           => array(
					'id'             => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'The review id to update; discover with og-wc-products/list-product-reviews.', 'abilities-catalog-woo' ),
					),
					'reviewer'       => array(
						'type'        => 'string',
						'description' => __( 'A new reviewer display name. Omit to keep the current name.', 'abilities-catalog-woo' ),
					),
					'reviewer_email' => array(
						'type'        => 'string',
						'description' => __( 'A new reviewer email. Omit to keep the current email.', 'abilities-catalog-woo' ),
					),
					'review'         => array(
						'type'        => 'string',
						'description' => __( 'New review text (HTML allowed; sanitized by WordPress). Omit to keep the current text.', 'abilities-catalog-woo' ),
					),
					'rating'         => array(
						'type'        => 'integer',
						'minimum'     => 0,
						'maximum'     => 5,
						'description' => __( 'A new star rating from 0 to 5. Omit to keep the current rating.', 'abilities-catalog-woo' ),
					),
					'status'         => array(
						'type'        => 'string',
						'enum'        => array( 'approved', 'hold', 'spam', 'unspam', 'trash', 'untrash' ),
						'description' => __( 'The moderation action to apply: "approved" approves/publishes, "hold" un-approves (back to the moderation queue), "spam" marks spam, "unspam" restores from spam, "trash" moves to trash, "untrash" restores from trash. Setting only this field moderates the review. trash is a recoverable state, not a permanent delete. Omit to leave the moderation status unchanged.', 'abilities-catalog-woo' ),
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => $output_schema,
			'execute_callback'    => array( $this, 'execute' ),
			'permission_callback' => array( $this, 'hasPermission' ),
			'meta'                => array(
				'annotations'  => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => false,
				),
				'show_in_rest' => true,
				'screen'       => 'comment.php?action=editcomment&c={id}',
			),
		);
	}

	/**
	 * Permission check: WooCommerce's edit capability for product reviews.
	 *
	 * Mirrors `wc_rest_check_product_reviews_permissions( 'edit' )` on the wrapped
	 * `PUT wc/v3/products/reviews/<id>` route, which resolves to `edit_products`.
	 * Note the asymmetry: review READ is `moderate_comments`, but review WRITE
	 * (this ability) is `edit_products`. This is a coarse, object-INDEPENDENT guard:
	 * the per-object decision is deferred to the wrapped route, so a missing review
	 * surfaces its specific `woocommerce_rest_review_invalid_id` 404 via
	 * {@see RestError::from()} instead of collapsing to a generic permission denial.
	 * The explicit activity guard keeps the denial clean when WooCommerce is
	 * inactive and the capability is unmapped.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may edit product reviews.
	 */
	public function hasPermission( $input ): bool {
		return WooPlugin::isActive() && current_user_can( 'edit_products' );
	}

	/**
	 * Executes the ability by dispatching the internal `wc/v3` REST update request.
	 *
	 * The `id` is concatenated into the route path; each editable field is
	 * forwarded only when present in the input, so an omitted field keeps its
	 * current value.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The shaped review row, or the REST error
	 *                                        (e.g. `woocommerce_rest_review_invalid_id` 404).
	 */
	public function execute( $input ) {
		if ( ! WooPlugin::isActive() ) {
			return WooPlugin::unavailable();
		}

		$input = is_array( $input ) ? $input : array();
		$id    = absint( $input['id'] ?? 0 );

		$request = new WP_REST_Request( 'PUT', '/wc/v3/products/reviews/' . $id );

		if ( array_key_exists( 'reviewer', $input ) ) {
			$request->set_param( 'reviewer', (string) $input['reviewer'] );
		}
		if ( array_key_exists( 'reviewer_email', $input ) ) {
			$request->set_param( 'reviewer_email', (string) $input['reviewer_email'] );
		}
		if ( array_key_exists( 'review', $input ) ) {
			$request->set_param( 'review', (string) $input['review'] );
		}
		if ( array_key_exists( 'rating', $input ) ) {
			$request->set_param( 'rating', (int) $input['rating'] );
		}
		if ( array_key_exists( 'status', $input ) ) {
			$request->set_param( 'status', (string) $input['status'] );
		}

		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			return RestError::from( $response );
		}

		$data = rest_get_server()->response_to_data( $response, false );
		$data = is_array( $data ) ? $data : array();

		return ProductReviewListShaper::reviewSummary( $data );
	}
}
