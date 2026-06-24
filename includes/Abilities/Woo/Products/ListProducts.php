<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogWoo\Abilities\Woo\Products;

use GalatanOvidiu\AbilitiesCatalogWoo\Contracts\ConditionalAbility;
use GalatanOvidiu\AbilitiesCatalogWoo\Support\BooleanInput;
use GalatanOvidiu\AbilitiesCatalogWoo\Support\ProductListShaper;
use GalatanOvidiu\AbilitiesCatalogWoo\Support\RestError;
use GalatanOvidiu\AbilitiesCatalogWoo\Support\WooPlugin;
use WP_REST_Request;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read ability: `wc-products/list-products`.
 *
 * Wraps `GET wc/v3/products` via `rest_do_request()` and returns each product as
 * a flat summary row through {@see ProductListShaper::summary()}, so a consumer
 * scans the catalog without the raw ~120-field product body. Exposes a minimal,
 * useful subset of the controller's collection params (search, paging, status,
 * type, sku, category, tag, featured, on_sale, ordering) — not every filter.
 *
 * Only available when WooCommerce is active (it is a {@see ConditionalAbility}).
 * The WC products list route sends pagination headers, so `total` is the full
 * matching count from `X-WP-Total`, not just the number of rows on this page.
 *
 * @since 0.1.0
 */
final class ListProducts implements ConditionalAbility {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'wc-products/list-products';
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
			'label'               => __( 'List Products', 'abilities-catalog-woo' ),
			'description'         => __( 'Returns the store\'s WooCommerce products as flat summary rows, each with its id, name, type, status, sku, price, stock status, and edit_link. Filter by search term, status, type, sku, category or tag ID, featured, or on-sale, and sort with orderby/order. Use wc-products/get-product for one product\'s full detail (description, categories, tags, images, attributes). Read-only: does not return variations — list those with wc-products/list-product-variations.', 'abilities-catalog-woo' ),
			'category'            => 'wc-products',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'search'   => array(
						'type'        => 'string',
						'description' => __( 'Limit results to products whose name matches a search term.', 'abilities-catalog-woo' ),
					),
					'per_page' => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'maximum'     => 100,
						'default'     => 100,
						'description' => __( 'Maximum number of products to return (1-100). Defaults to 100.', 'abilities-catalog-woo' ),
					),
					'page'     => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'default'     => 1,
						'description' => __( 'The page of results to return, starting at 1. Use total to compute how many pages exist.', 'abilities-catalog-woo' ),
					),
					'status'   => array(
						'type'        => 'string',
						'enum'        => array( 'any', 'draft', 'pending', 'private', 'publish' ),
						'default'     => 'any',
						'description' => __( 'Limit results to products with a specific post status. "any" (the default) returns every status the caller may read.', 'abilities-catalog-woo' ),
					),
					'type'     => array(
						'type'        => 'string',
						'enum'        => array( 'simple', 'grouped', 'external', 'variable' ),
						'description' => __( 'Limit results to a product type: "simple", "grouped", "external" (affiliate), or "variable".', 'abilities-catalog-woo' ),
					),
					'sku'      => array(
						'type'        => 'string',
						'description' => __( 'Limit results to products with this exact stock keeping unit (SKU).', 'abilities-catalog-woo' ),
					),
					'category' => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'Limit results to products in this product-category term ID.', 'abilities-catalog-woo' ),
					),
					'tag'      => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'Limit results to products with this product-tag term ID.', 'abilities-catalog-woo' ),
					),
					'featured' => array(
						'type'        => 'boolean',
						'description' => __( 'When true, limit results to products flagged as featured.', 'abilities-catalog-woo' ),
					),
					'on_sale'  => array(
						'type'        => 'boolean',
						'description' => __( 'When true, limit results to products currently on sale.', 'abilities-catalog-woo' ),
					),
					'orderby'  => array(
						'type'        => 'string',
						'enum'        => array( 'date', 'id', 'title', 'slug', 'modified', 'menu_order', 'price', 'popularity', 'rating' ),
						'default'     => 'date',
						'description' => __( 'Sort the result set by this product attribute. Defaults to "date".', 'abilities-catalog-woo' ),
					),
					'order'    => array(
						'type'        => 'string',
						'enum'        => array( 'asc', 'desc' ),
						'default'     => 'desc',
						'description' => __( 'Sort direction: "asc" (ascending) or "desc" (descending). Defaults to "desc".', 'abilities-catalog-woo' ),
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
						'description' => __( 'The products as flat summary rows. Use wc-products/get-product for a single product\'s full detail.', 'abilities-catalog-woo' ),
						'items'       => ProductListShaper::itemSchema(),
					),
					'total' => array(
						'type'        => 'integer',
						'description' => __( 'The total number of products matching the query across all pages, read from the X-WP-Total response header. May exceed the number of returned rows when paging.', 'abilities-catalog-woo' ),
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
	 * Encodes the catalog baseline for `wc-products/list-products`: the
	 * `read_private_products` capability, which is what
	 * `wc_rest_check_post_permissions( 'product', 'read' )` resolves to on the
	 * wrapped `GET wc/v3/products` route (the product post type maps the `read`
	 * context to its `read_private_products` cap). This is a coarse, object-
	 * independent guard; the wrapped route applies any per-product visibility. The
	 * explicit activity guard keeps the denial clean when WooCommerce is inactive
	 * and the capability is unmapped.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may read the product catalog.
	 */
	public function hasPermission( $input ): bool {
		return WooPlugin::isActive() && current_user_can( 'read_private_products' );
	}

	/**
	 * Executes the ability by dispatching the internal WooCommerce REST request.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The list of products, or the REST error.
	 */
	public function execute( $input ) {
		if ( ! WooPlugin::isActive() ) {
			return WooPlugin::unavailable();
		}

		$input = is_array( $input ) ? $input : array();

		$request = new WP_REST_Request( 'GET', '/wc/v3/products' );

		if ( ! empty( $input['search'] ) ) {
			$request->set_param( 'search', (string) $input['search'] );
		}
		$request->set_param( 'per_page', max( 1, min( 100, absint( $input['per_page'] ?? 100 ) ) ) );
		$request->set_param( 'page', max( 1, absint( $input['page'] ?? 1 ) ) );
		$request->set_param( 'status', (string) ( $input['status'] ?? 'any' ) );
		if ( ! empty( $input['type'] ) ) {
			$request->set_param( 'type', (string) $input['type'] );
		}
		if ( isset( $input['sku'] ) && '' !== $input['sku'] ) {
			$request->set_param( 'sku', (string) $input['sku'] );
		}
		if ( ! empty( $input['category'] ) ) {
			$request->set_param( 'category', (string) absint( $input['category'] ) );
		}
		if ( ! empty( $input['tag'] ) ) {
			$request->set_param( 'tag', (string) absint( $input['tag'] ) );
		}
		if ( array_key_exists( 'featured', $input ) ) {
			$request->set_param( 'featured', BooleanInput::sanitize( $input['featured'] ) );
		}
		if ( array_key_exists( 'on_sale', $input ) ) {
			$request->set_param( 'on_sale', BooleanInput::sanitize( $input['on_sale'] ) );
		}
		$request->set_param( 'orderby', (string) ( $input['orderby'] ?? 'date' ) );
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

			$rows[] = ProductListShaper::summary( $item );
		}

		$headers = $response->get_headers();
		$total   = isset( $headers['X-WP-Total'] ) ? (int) $headers['X-WP-Total'] : count( $rows );

		return array(
			'items' => $rows,
			'total' => $total,
		);
	}
}
