<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogWoo\Abilities\Woo\Products;

use GalatanOvidiu\AbilitiesCatalogWoo\Contracts\ConditionalAbility;
use GalatanOvidiu\AbilitiesCatalogWoo\Support\ProductListShaper;
use GalatanOvidiu\AbilitiesCatalogWoo\Support\RestError;
use GalatanOvidiu\AbilitiesCatalogWoo\Support\WooPlugin;
use WP_REST_Request;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read ability: `og-wc-products/list-product-variations`.
 *
 * Wraps `GET wc/v3/products/<product_id>/variations` via `rest_do_request()` and
 * returns each variation of one variable product as a flat summary row
 * ({@see ProductListShaper::variationSummary()}: id, sku, prices, stock, status,
 * the attribute selections, permalink, and edit_link). `product_id` is a required
 * route segment, so the request is built by string concatenation rather than
 * `set_param()`.
 *
 * Only available when WooCommerce is active (it is a {@see ConditionalAbility}).
 *
 * The WC list route paginates with `X-WP-Total` headers, so `total` is the full
 * matching count, not just the number of returned rows. A parent that does not
 * exist, or is not a variable product, has no variations: the list route does NOT
 * 404 on the collection path, it returns an empty `items` with `total` 0 (the 404
 * `woocommerce_rest_product_invalid_id` is only on the single-variation path).
 *
 * @since 0.1.0
 */
final class ListProductVariations implements ConditionalAbility {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-wc-products/list-product-variations';
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
			'label'               => __( 'List Product Variations', 'abilities-catalog-woo' ),
			'description'         => __( 'Returns the variations of one variable WooCommerce product as flat summary rows, each with its id, sku, prices, stock status and quantity, status, attribute selections (e.g. {name: Color, option: Red}), permalink, and edit_link. The parent must be a variable product; provide product_id from og-wc-products/list-products or og-wc-products/get-product. Use og-wc-products/get-product-variation for one variation\'s full detail. A parent with no variations (a simple, grouped, or missing product) returns an empty list, not an error.', 'abilities-catalog-woo' ),
			'category'            => 'og-wc-products',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'product_id' ),
				'properties'           => array(
					'product_id' => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'The parent variable product ID whose variations to list. Discover it with og-wc-products/list-products (the parent must be a variable product).', 'abilities-catalog-woo' ),
					),
					'search'     => array(
						'type'        => 'string',
						'description' => __( 'Limit results to variations matching a search term.', 'abilities-catalog-woo' ),
					),
					'per_page'   => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'maximum'     => 100,
						'default'     => 100,
						'description' => __( 'Maximum number of variations to return (1-100). Defaults to 100, which covers every variation on a typical product.', 'abilities-catalog-woo' ),
					),
					'page'       => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'default'     => 1,
						'description' => __( 'The page of results to return, for paging past the first per_page variations.', 'abilities-catalog-woo' ),
					),
					'status'     => array(
						'type'        => 'string',
						'enum'        => array( 'any', 'draft', 'pending', 'private', 'publish' ),
						'default'     => 'any',
						'description' => __( 'Limit results to variations with this post status, or "any" (the default) for all statuses.', 'abilities-catalog-woo' ),
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'product_id', 'items', 'total' ),
				'properties'           => array(
					'product_id' => array(
						'type'        => 'integer',
						'description' => __( 'The parent variable product ID the variations belong to.', 'abilities-catalog-woo' ),
					),
					'items'      => array(
						'type'        => 'array',
						'description' => __( 'The variations as flat summary rows. Use og-wc-products/get-product-variation for a single variation\'s full detail.', 'abilities-catalog-woo' ),
						'items'       => ProductListShaper::variationItemSchema(),
					),
					'total'      => array(
						'type'        => 'integer',
						'description' => __( 'Total number of variations matching the query, read from the X-WP-Total response header. May exceed the number of returned rows when paging.', 'abilities-catalog-woo' ),
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
	 * Permission check: WooCommerce's read capability for products.
	 *
	 * The wrapped list route gates on `wc_rest_check_post_permissions( 'product',
	 * 'read' )`, which resolves to the product post type's `read_private_posts`
	 * meta-cap — `read_private_products`. Variations share the parent product caps,
	 * so this mirrors the route's own guard exactly. This is a coarse, object-
	 * independent gate; a missing parent is surfaced by the route as an empty list,
	 * not masked here as a permission denial.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may read product variations.
	 */
	public function hasPermission( $input ): bool {
		return WooPlugin::isActive() && current_user_can( 'read_private_products' );
	}

	/**
	 * Executes the ability by dispatching the internal WooCommerce REST request.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The variation list, or the REST error.
	 */
	public function execute( $input ) {
		if ( ! WooPlugin::isActive() ) {
			return WooPlugin::unavailable();
		}

		$input      = is_array( $input ) ? $input : array();
		$product_id = absint( $input['product_id'] ?? 0 );

		// The route is built by concatenation: product_id is a path segment, not a query param.
		$request = new WP_REST_Request( 'GET', '/wc/v3/products/' . $product_id . '/variations' );
		if ( ! empty( $input['search'] ) ) {
			$request->set_param( 'search', (string) $input['search'] );
		}
		$request->set_param( 'per_page', max( 1, min( 100, absint( $input['per_page'] ?? 100 ) ) ) );
		$request->set_param( 'page', max( 1, absint( $input['page'] ?? 1 ) ) );
		$request->set_param( 'status', (string) ( $input['status'] ?? 'any' ) );

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

			$rows[] = ProductListShaper::variationSummary( $item );
		}

		$headers = $response->get_headers();
		$total   = isset( $headers['X-WP-Total'] ) ? (int) $headers['X-WP-Total'] : count( $rows );

		return array(
			'product_id' => $product_id,
			'items'      => $rows,
			'total'      => $total,
		);
	}
}
