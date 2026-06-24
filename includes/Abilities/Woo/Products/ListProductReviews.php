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
 * Read ability: `wc-products/list-product-reviews`.
 *
 * Wraps `GET wc/v3/products/reviews` via `rest_do_request()` and returns each
 * product review as a flat summary row through
 * {@see ProductReviewListShaper::reviewSummary()}. A WooCommerce product review
 * is a WordPress comment on a product (`comment_type = 'review'`), so this is the
 * admin moderation read for the review queue: filter by product, moderation
 * status, search term, or reviewer email, page through results, and sort.
 *
 * PII: each row INCLUDES `reviewer` (name) and `reviewer_email`. This is an admin
 * moderation tool whose hard guard is `moderate_comments` — the exact capability
 * WordPress's own Comments screen requires to view a commenter's email. Surfacing
 * the email is the moderation workflow (identify, contact, or block a reviewer),
 * so redacting it would break the tool without protecting anything the capability
 * does not already expose. The shaper omits avatar URLs and never exposes the
 * reviewer IP.
 *
 * Only available when WooCommerce is active (it is a {@see ConditionalAbility}).
 * The WC reviews list route sends pagination headers, so `total` is the full
 * matching count from `X-WP-Total`, not just the rows on this page; the wrapped
 * route falls back to `count( $rows )` only if that header is absent.
 *
 * @since 0.1.0
 */
final class ListProductReviews implements ConditionalAbility {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'wc-products/list-product-reviews';
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
		return array(
			'label'               => __( 'List Product Reviews', 'abilities-catalog-woo' ),
			'description'         => __( 'Returns the store\'s WooCommerce product reviews as flat summary rows, each with its id, product_id, product_name, status, reviewer, reviewer_email, rating, review text (plain-text excerpt), verified-buyer flag, and creation date. A product review is a WordPress comment on a product. This is the admin moderation read: filter by product ID, status ("approved" by default, "hold" = awaiting moderation, "spam", "trash", or "all"), search term, or reviewer email; page through results; and sort with orderby/order. The reviewer email is included on purpose because this tool is gated on the moderate_comments capability — the same capability WordPress\'s Comments screen needs to show it — so a moderator can identify, contact, or block a reviewer. Use wc-products/get-product-review for one review by ID. Read-only: does not change a review or its status.', 'abilities-catalog-woo' ),
			'category'            => 'wc-products',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'search'         => array(
						'type'        => 'string',
						'description' => __( 'Limit results to reviews whose content matches a search term.', 'abilities-catalog-woo' ),
					),
					'per_page'       => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'maximum'     => 100,
						'default'     => 100,
						'description' => __( 'Maximum number of reviews to return (1-100). Defaults to 100.', 'abilities-catalog-woo' ),
					),
					'page'           => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'default'     => 1,
						'description' => __( 'The page of results to return, starting at 1. Use total to compute how many pages exist.', 'abilities-catalog-woo' ),
					),
					'offset'         => array(
						'type'        => 'integer',
						'minimum'     => 0,
						'description' => __( 'Number of reviews to skip before returning results. When set, it overrides page-based paging.', 'abilities-catalog-woo' ),
					),
					'product'        => array(
						'type'        => 'array',
						'items'       => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
						'description' => __( 'Limit results to reviews of these product IDs. Discover product IDs with wc-products/list-products.', 'abilities-catalog-woo' ),
					),
					'status'         => array(
						'type'        => 'string',
						'enum'        => array( 'all', 'hold', 'approved', 'spam', 'trash' ),
						'default'     => 'approved',
						'description' => __( 'Limit results to reviews with this moderation status: "approved" (the default, published reviews), "hold" (awaiting moderation), "spam", "trash", or "all" (every status).', 'abilities-catalog-woo' ),
					),
					'reviewer_email' => array(
						'type'        => 'string',
						'format'      => 'email',
						'description' => __( 'Limit results to reviews left by this exact reviewer email address.', 'abilities-catalog-woo' ),
					),
					'orderby'        => array(
						'type'        => 'string',
						'enum'        => array( 'date', 'date_gmt', 'id', 'include', 'product' ),
						'default'     => 'date_gmt',
						'description' => __( 'Sort the result set by this attribute: "date_gmt" (the default, by GMT creation date), "date" (site-time creation date), "id", "include" (preserve the order of the product filter), or "product".', 'abilities-catalog-woo' ),
					),
					'order'          => array(
						'type'        => 'string',
						'enum'        => array( 'asc', 'desc' ),
						'default'     => 'desc',
						'description' => __( 'Sort direction: "desc" (newest first, the default) or "asc" (oldest first).', 'abilities-catalog-woo' ),
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'items', 'total' ),
				'properties'           => array(
					'items' => array(
						'type'        => 'array',
						'description' => __( 'The product reviews as flat summary rows. Use wc-products/get-product-review for a single review by ID.', 'abilities-catalog-woo' ),
						'items'       => ProductReviewListShaper::reviewItemSchema(),
					),
					'total' => array(
						'type'        => 'integer',
						'description' => __( 'The total number of reviews matching the query across all pages, read from the X-WP-Total response header. May exceed the number of returned rows when paging.', 'abilities-catalog-woo' ),
					),
				),
				'additionalProperties' => false,
			),
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
	 * Encodes the catalog baseline for `wc-products/list-product-reviews`: the
	 * `moderate_comments` capability, which is what
	 * `wc_rest_check_product_reviews_permissions( 'read' )` resolves to on the
	 * wrapped `GET wc/v3/products/reviews` route. It is also the capability
	 * WordPress's own Comments screen requires to view a commenter's email, which
	 * is why this read can surface `reviewer_email`. This is a coarse,
	 * object-independent guard; the wrapped route applies any per-review checks. The
	 * explicit activity guard keeps the denial clean when WooCommerce is inactive.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may read product reviews.
	 */
	public function hasPermission( $input ): bool {
		return WooPlugin::isActive() && current_user_can( 'moderate_comments' );
	}

	/**
	 * Executes the ability by dispatching the internal WooCommerce REST request.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The list of reviews, or the REST error.
	 */
	public function execute( $input ) {
		if ( ! WooPlugin::isActive() ) {
			return WooPlugin::unavailable();
		}

		$input = is_array( $input ) ? $input : array();

		$request = new WP_REST_Request( 'GET', '/wc/v3/products/reviews' );

		if ( ! empty( $input['search'] ) ) {
			$request->set_param( 'search', (string) $input['search'] );
		}
		$request->set_param( 'per_page', max( 1, min( 100, absint( $input['per_page'] ?? 100 ) ) ) );
		$request->set_param( 'page', max( 1, absint( $input['page'] ?? 1 ) ) );
		if ( array_key_exists( 'offset', $input ) ) {
			$request->set_param( 'offset', absint( $input['offset'] ) );
		}
		if ( ! empty( $input['product'] ) && is_array( $input['product'] ) ) {
			$request->set_param( 'product', array_map( 'absint', $input['product'] ) );
		}
		if ( ! empty( $input['reviewer_email'] ) ) {
			$request->set_param( 'reviewer_email', (string) $input['reviewer_email'] );
		}
		$request->set_param( 'status', (string) ( $input['status'] ?? 'approved' ) );
		$request->set_param( 'orderby', (string) ( $input['orderby'] ?? 'date_gmt' ) );
		$request->set_param( 'order', (string) ( $input['order'] ?? 'desc' ) );

		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			return RestError::from( $response );
		}

		$data = rest_get_server()->response_to_data( $response, false );

		$rows = array();
		foreach ( is_array( $data ) ? $data : array() as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$rows[] = ProductReviewListShaper::reviewSummary( $item );
		}

		$headers = $response->get_headers();
		$total   = isset( $headers['X-WP-Total'] ) ? (int) $headers['X-WP-Total'] : count( $rows );

		return array(
			'items' => $rows,
			'total' => $total,
		);
	}
}
