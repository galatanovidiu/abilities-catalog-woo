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
 * Write ability: `wc-products/create-product-review`.
 *
 * Wraps `POST wc/v3/products/reviews` via `rest_do_request()`, creating a new
 * WooCommerce product review. A WooCommerce product review IS a WordPress comment
 * on a product (`comment_type = 'review'`), so this writes a comment row, not a
 * post. The result is shaped through {@see ProductReviewListShaper::reviewSummary()}
 * into the same flat, closed row the batch-03 review reads return (id, product_id,
 * product_name, status, reviewer, reviewer_email, rating, review, verified,
 * date_created), so the raw comment body never leaks.
 *
 * The controller requires a real product `product_id` (a non-product id is rejected
 * with `woocommerce_rest_product_invalid_id` 404) and non-empty review content (an
 * empty `review` is rejected with `woocommerce_rest_review_content_invalid` 400),
 * which {@see RestError::from()} surfaces verbatim so the agent can branch on them.
 *
 * PII: `reviewer_email` is returned by design. This is an admin moderation tool
 * whose hard guard is the `edit_products` capability — the WRITE capability
 * `wc_rest_check_product_reviews_permissions()` requires for review creation, which
 * is stricter than the `moderate_comments` review READ cap — so surfacing the email
 * exposes nothing the capability does not already grant.
 *
 * Only available when WooCommerce is active (it is a {@see ConditionalAbility}).
 *
 * @since 0.1.0
 */
final class CreateProductReview implements ConditionalAbility {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'wc-products/create-product-review';
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
		$item = ProductReviewListShaper::reviewItemSchema();

		$item['required'] = array( 'id' );

		return array(
			'label'               => __( 'Create Product Review', 'abilities-catalog-woo' ),
			'description'         => __( 'Creates a new WooCommerce product review on a product (a product review is a WordPress comment with comment_type "review") and returns it as a flat row: id, product_id, product_name, status, reviewer, reviewer_email, rating, review text, verified-buyer flag, and date. product_id, reviewer, reviewer_email, and review are required; an invalid product_id is rejected with a woocommerce_rest_product_invalid_id 404 and an empty review with a woocommerce_rest_review_content_invalid 400. status defaults to "approved" (the review is published immediately); set status to "hold" to leave it awaiting moderation. This writes only a review, not the product, and is reversible via wc-products/update-product-review (set status to spam/trash) or by deleting the review.', 'abilities-catalog-woo' ),
			'category'            => 'wc-products',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'product_id', 'reviewer', 'reviewer_email', 'review' ),
				'properties'           => array(
					'product_id'     => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'The ID of the product this review is for. Required. Discover product IDs with wc-products/list-products. A non-product id is rejected with a woocommerce_rest_product_invalid_id 404.', 'abilities-catalog-woo' ),
					),
					'reviewer'       => array(
						'type'        => 'string',
						'description' => __( 'The reviewer display name. Required.', 'abilities-catalog-woo' ),
					),
					'reviewer_email' => array(
						'type'        => 'string',
						'description' => __( 'The reviewer email address. Required.', 'abilities-catalog-woo' ),
					),
					'review'         => array(
						'type'        => 'string',
						'description' => __( 'The review text. Required and must not be empty; an empty review is rejected with a woocommerce_rest_review_content_invalid 400.', 'abilities-catalog-woo' ),
					),
					'rating'         => array(
						'type'        => 'integer',
						'minimum'     => 0,
						'maximum'     => 5,
						'description' => __( 'The star rating from 0 to 5. Optional; omit for no rating (0).', 'abilities-catalog-woo' ),
					),
					'status'         => array(
						'type'        => 'string',
						'enum'        => array( 'approved', 'hold', 'spam', 'unspam', 'trash', 'untrash' ),
						'default'     => 'approved',
						'description' => __( 'The moderation status. "approved" (the default) publishes the review immediately; "hold" leaves it awaiting moderation. "spam", "unspam", "trash", and "untrash" are also accepted but for a new review you normally want "approved" or "hold".', 'abilities-catalog-woo' ),
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => $item,
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
	 * Encodes the catalog capability for `wc-products/create-product-review`: the
	 * `edit_products` capability, which is what
	 * `wc_rest_check_product_reviews_permissions( 'create' )` resolves to on the
	 * wrapped `POST wc/v3/products/reviews` route. Note the asymmetry with the review
	 * READ abilities, which gate on `moderate_comments`: review WRITE is the stricter
	 * `edit_products`. Coarse and object-independent; the wrapped route surfaces the
	 * specific create errors (e.g. `woocommerce_rest_product_invalid_id` 404 for a
	 * non-product id) via {@see RestError::from()} instead of collapsing them to a
	 * permission denial. The explicit activity guard keeps the denial clean when
	 * WooCommerce is inactive and the cap is unmapped.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may create product reviews.
	 */
	public function hasPermission( $input ): bool {
		return WooPlugin::isActive() && current_user_can( 'edit_products' );
	}

	/**
	 * Executes the ability by dispatching the internal `wc/v3` REST create request.
	 *
	 * Forwards only the fields the caller actually supplied (required scalars are
	 * always set; optional `rating` and `status` only when present) so an omitted
	 * `status` keeps the controller default of "approved".
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The shaped review row, or the REST error
	 *                                        (e.g. `woocommerce_rest_product_invalid_id` 404,
	 *                                        `woocommerce_rest_review_content_invalid` 400).
	 */
	public function execute( $input ) {
		if ( ! WooPlugin::isActive() ) {
			return WooPlugin::unavailable();
		}

		$input = is_array( $input ) ? $input : array();

		$request = new WP_REST_Request( 'POST', '/wc/v3/products/reviews' );

		$request->set_param( 'product_id', absint( $input['product_id'] ?? 0 ) );
		$request->set_param( 'reviewer', (string) ( $input['reviewer'] ?? '' ) );
		$request->set_param( 'reviewer_email', (string) ( $input['reviewer_email'] ?? '' ) );
		$request->set_param( 'review', (string) ( $input['review'] ?? '' ) );

		if ( array_key_exists( 'rating', $input ) ) {
			$request->set_param( 'rating', absint( $input['rating'] ) );
		}
		if ( isset( $input['status'] ) && '' !== $input['status'] ) {
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
