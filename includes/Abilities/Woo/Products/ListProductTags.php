<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogWoo\Abilities\Woo\Products;

use GalatanOvidiu\AbilitiesCatalogWoo\Contracts\ConditionalAbility;
use GalatanOvidiu\AbilitiesCatalogWoo\Support\BooleanInput;
use GalatanOvidiu\AbilitiesCatalogWoo\Support\ProductTermListShaper;
use GalatanOvidiu\AbilitiesCatalogWoo\Support\RestError;
use GalatanOvidiu\AbilitiesCatalogWoo\Support\WooPlugin;
use WP_REST_Request;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read ability: `og-wc-products/list-product-tags`.
 *
 * Wraps `GET wc/v3/products/tags` via `rest_do_request()` and returns each product
 * tag as a flat summary row through {@see ProductTermListShaper::termSummary()}, so
 * a consumer scans the store's tags without the raw term body. Filter with search,
 * paging, ordering, and `hide_empty`.
 *
 * `product_tag` is a flat (non-hierarchical) taxonomy, so a tag never has a parent;
 * the shared term row carries `parent`, which is always `0` here. Use
 * `og-wc-products/list-product-categories` for the hierarchical category taxonomy.
 *
 * Only available when WooCommerce is active (it is a {@see ConditionalAbility}).
 * The WC tags list route sends pagination headers, so `total` is the full matching
 * count from `X-WP-Total`, not just the number of rows on this page; if the header
 * is ever absent it falls back to the number of returned rows.
 *
 * @since 0.1.0
 */
final class ListProductTags implements ConditionalAbility {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-wc-products/list-product-tags';
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
			'label'               => __( 'List Product Tags', 'abilities-catalog-woo' ),
			'description'         => __( 'Returns the store\'s WooCommerce product tags as flat summary rows, each with its id, name, slug, product count, and description. Filter by search term, page through results, sort with orderby/order, and set hide_empty to drop tags with no published products. Product tags are flat (no hierarchy), so each row\'s parent is always 0. Use og-wc-products/get-product-tag for one tag by ID, or og-wc-products/list-product-categories for the hierarchical category taxonomy.', 'abilities-catalog-woo' ),
			'category'            => 'og-wc-products',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'search'     => array(
						'type'        => 'string',
						'description' => __( 'Limit results to tags whose name matches a search term.', 'abilities-catalog-woo' ),
					),
					'per_page'   => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'maximum'     => 100,
						'default'     => 100,
						'description' => __( 'Maximum number of tags to return (1-100). Defaults to 100, which covers every tag on a typical store.', 'abilities-catalog-woo' ),
					),
					'page'       => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'default'     => 1,
						'description' => __( 'The page of results to return, starting at 1. Use total to compute how many pages exist.', 'abilities-catalog-woo' ),
					),
					'order'      => array(
						'type'        => 'string',
						'enum'        => array( 'asc', 'desc' ),
						'description' => __( 'Sort direction: "asc" (ascending) or "desc" (descending).', 'abilities-catalog-woo' ),
					),
					'orderby'    => array(
						'type'        => 'string',
						'enum'        => array( 'id', 'name', 'slug', 'count' ),
						'description' => __( 'Sort the result set by this field: "id", "name", "slug", or "count" (number of products).', 'abilities-catalog-woo' ),
					),
					'hide_empty' => array(
						'type'        => 'boolean',
						'description' => __( 'When true, omit tags that have no published products assigned.', 'abilities-catalog-woo' ),
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
						'description' => __( 'The product tags as flat summary rows. Use og-wc-products/get-product-tag for a single tag by ID. Each row\'s parent is always 0 because tags are a flat taxonomy.', 'abilities-catalog-woo' ),
						'items'       => ProductTermListShaper::termItemSchema(),
					),
					'total' => array(
						'type'        => 'integer',
						'description' => __( 'The total number of tags matching the query across all pages, read from the X-WP-Total response header. May exceed the number of returned rows when paging.', 'abilities-catalog-woo' ),
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
	 * Permission check: WooCommerce's read capability for product terms.
	 *
	 * Encodes the catalog baseline for `og-wc-products/list-product-tags`: the
	 * `manage_product_terms` capability, which is what
	 * `wc_rest_check_product_term_permissions( 'product_tag', 'read' )` resolves to
	 * on the wrapped `GET wc/v3/products/tags` route — the helper reads the `read`
	 * context as `manage_terms`, which the `product_tag` taxonomy registers as
	 * `manage_product_terms`. This is a coarse, object-independent guard. The
	 * explicit activity guard keeps the denial clean when WooCommerce is inactive
	 * and the capability is unmapped.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may read product tags.
	 */
	public function hasPermission( $input ): bool {
		return WooPlugin::isActive() && current_user_can( 'manage_product_terms' );
	}

	/**
	 * Executes the ability by dispatching the internal WooCommerce REST request.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The list of tags, or the REST error.
	 */
	public function execute( $input ) {
		if ( ! WooPlugin::isActive() ) {
			return WooPlugin::unavailable();
		}

		$input = is_array( $input ) ? $input : array();

		$request = new WP_REST_Request( 'GET', '/wc/v3/products/tags' );

		if ( ! empty( $input['search'] ) ) {
			$request->set_param( 'search', (string) $input['search'] );
		}
		$request->set_param( 'per_page', max( 1, min( 100, absint( $input['per_page'] ?? 100 ) ) ) );
		$request->set_param( 'page', max( 1, absint( $input['page'] ?? 1 ) ) );
		if ( ! empty( $input['order'] ) ) {
			$request->set_param( 'order', (string) $input['order'] );
		}
		if ( ! empty( $input['orderby'] ) ) {
			$request->set_param( 'orderby', (string) $input['orderby'] );
		}
		if ( array_key_exists( 'hide_empty', $input ) ) {
			$request->set_param( 'hide_empty', BooleanInput::sanitize( $input['hide_empty'] ) );
		}

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

			$rows[] = ProductTermListShaper::termSummary( $item );
		}

		$headers = $response->get_headers();
		$total   = isset( $headers['X-WP-Total'] ) ? (int) $headers['X-WP-Total'] : count( $rows );

		return array(
			'items' => $rows,
			'total' => $total,
		);
	}
}
