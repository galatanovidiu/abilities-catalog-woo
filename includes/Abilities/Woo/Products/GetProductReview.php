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
 * Read ability: `og-wc-products/get-product-review`.
 *
 * Wraps `GET wc/v3/products/reviews/<id>` via `rest_do_request()` and returns one
 * product review as a flat, closed row through
 * {@see ProductReviewListShaper::reviewSummary()} — the same shaped fields a
 * `og-wc-products/list-product-reviews` row carries, including the reviewer name and
 * email. A WooCommerce product review is a WordPress comment on a product
 * (`comment_type = 'review'`).
 *
 * PII: `reviewer` and `reviewer_email` are returned by design. This is an admin
 * moderation tool whose hard guard is the `moderate_comments` capability — the
 * exact capability WordPress's own Comments screen requires to view a commenter's
 * email — so surfacing them here exposes nothing the capability does not already
 * grant, and identifying or contacting a reviewer is the core moderation workflow.
 *
 * Only available when WooCommerce is active (it is a {@see ConditionalAbility}).
 *
 * @since 0.1.0
 */
final class GetProductReview implements ConditionalAbility {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-wc-products/get-product-review';
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
			'label'               => __( 'Get Product Review', 'abilities-catalog-woo' ),
			'description'         => __( 'Returns one WooCommerce product review by ID: its rating, review text, moderation status, the reviewed product, whether the reviewer is a verified buyer, and the reviewer name and email. A product review is a WordPress comment on a product. The reviewer email is included because this is an admin moderation tool gated on the moderate_comments capability. Use og-wc-products/list-product-reviews to scan reviews and discover IDs; use this for one review\'s detail.', 'abilities-catalog-woo' ),
			'category'            => 'og-wc-products',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'id' ),
				'properties'           => array(
					'id' => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'The product review ID. Discover IDs with og-wc-products/list-product-reviews.', 'abilities-catalog-woo' ),
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => $item,
			'execute_callback'    => array( $this, 'execute' ),
			'permission_callback' => array( $this, 'hasPermission' ),
			'meta'                => array(
				'annotations'  => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
				'show_in_rest' => true,
			),
		);
	}

	/**
	 * Permission check: WooCommerce's read capability for product reviews.
	 *
	 * Mirrors `wc_rest_check_product_reviews_permissions( 'read' )`, which resolves
	 * to `moderate_comments` — the baseline the wrapped `wc/v3` GET route enforces
	 * and the same capability WordPress's Comments screen requires to view a
	 * commenter's email. This is a coarse, object-INDEPENDENT type-level guard: the
	 * per-object decision is deferred to the wrapped route, so a missing review
	 * surfaces its specific `woocommerce_rest_review_invalid_id` 404 via
	 * {@see RestError::from()} instead of collapsing to a generic permission denial.
	 * The explicit activity guard keeps the denial clean when WooCommerce is
	 * inactive.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may read product reviews.
	 */
	public function hasPermission( $input ): bool {
		return WooPlugin::isActive() && current_user_can( 'moderate_comments' );
	}

	/**
	 * Executes the ability by dispatching the internal `wc/v3` REST request.
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

		$request  = new WP_REST_Request( 'GET', '/wc/v3/products/reviews/' . $id );
		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			return RestError::from( $response );
		}

		$data = rest_get_server()->response_to_data( $response, false );
		$data = is_array( $data ) ? $data : array();

		return ProductReviewListShaper::reviewSummary( $data );
	}
}
