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
 * Read ability: `wc-products/list-attribute-terms`.
 *
 * Wraps `GET wc/v3/products/attributes/<attribute_id>/terms` via
 * `rest_do_request()` and returns each term of one global product attribute as a
 * flat summary row through {@see ProductTermListShaper::termSummary()} — so a
 * consumer reads the values of an attribute (e.g. the Red/Blue/Green terms of a
 * "Color" attribute) without the raw WC term body (which carries `menu_order`).
 *
 * The `attribute_id` is a REQUIRED route segment, not a query parameter: the path
 * is built by concatenation as `/wc/v3/products/attributes/<attribute_id>/terms`.
 * `parent` is always 0 for these terms (the `pa_*` taxonomies are flat).
 *
 * Only available when WooCommerce is active (it is a {@see ConditionalAbility}).
 * The WC terms list route sends pagination headers, so `total` is the full
 * matching count from `X-WP-Total`, not just the number of rows on this page.
 *
 * @since 0.1.0
 */
final class ListAttributeTerms implements ConditionalAbility {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'wc-products/list-attribute-terms';
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
			'label'               => __( 'List Attribute Terms', 'abilities-catalog-woo' ),
			'description'         => __( 'Returns the terms of one global product attribute (e.g. the Red/Blue/Green values of a "Color" attribute) as flat summary rows, each with its id, name, slug, product count, and description. Pass the parent attribute_id; discover it with wc-products/list-product-attributes. Filter by search term, narrow with hide_empty, and sort with orderby/order. Use wc-products/get-attribute-term for a single term. Read-only: lists terms of a global attribute, not the categories or tags (use wc-products/list-product-categories or wc-products/list-product-tags for those).', 'abilities-catalog-woo' ),
			'category'            => 'wc-products',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'attribute_id' ),
				'properties'           => array(
					'attribute_id' => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'The parent attribute ID whose terms to list. Discover it with wc-products/list-product-attributes.', 'abilities-catalog-woo' ),
					),
					'search'       => array(
						'type'        => 'string',
						'description' => __( 'Limit results to terms whose name matches a search term.', 'abilities-catalog-woo' ),
					),
					'per_page'     => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'maximum'     => 100,
						'default'     => 100,
						'description' => __( 'Maximum number of terms to return (1-100). Defaults to 100, which covers every term on a typical attribute.', 'abilities-catalog-woo' ),
					),
					'page'         => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'default'     => 1,
						'description' => __( 'The page of results to return, starting at 1. Use total to compute how many pages exist.', 'abilities-catalog-woo' ),
					),
					'order'        => array(
						'type'        => 'string',
						'enum'        => array( 'asc', 'desc' ),
						'description' => __( 'Sort direction: "asc" (ascending) or "desc" (descending).', 'abilities-catalog-woo' ),
					),
					'orderby'      => array(
						'type'        => 'string',
						'enum'        => array( 'id', 'name', 'slug', 'count', 'description' ),
						'description' => __( 'Sort the result set by this term attribute. Defaults to "name".', 'abilities-catalog-woo' ),
					),
					'hide_empty'   => array(
						'type'        => 'boolean',
						'description' => __( 'When true, exclude terms not assigned to any product. Defaults to false.', 'abilities-catalog-woo' ),
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'attribute_id', 'items', 'total' ),
				'properties'           => array(
					'attribute_id' => array(
						'type'        => 'integer',
						'description' => __( 'The parent attribute ID whose terms were listed, echoed from the input.', 'abilities-catalog-woo' ),
					),
					'items'        => array(
						'type'        => 'array',
						'description' => __( 'The attribute terms as flat summary rows. Use wc-products/get-attribute-term for a single term.', 'abilities-catalog-woo' ),
						'items'       => ProductTermListShaper::termItemSchema(),
					),
					'total'        => array(
						'type'        => 'integer',
						'description' => __( 'The total number of terms matching the query across all pages, read from the X-WP-Total response header. May exceed the number of returned rows when paging.', 'abilities-catalog-woo' ),
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
	 * Permission check: WooCommerce's manage capability for product terms.
	 *
	 * Encodes the catalog baseline for `wc-products/list-attribute-terms`: the
	 * `manage_product_terms` capability, which is what the wrapped
	 * `GET wc/v3/products/attributes/<attribute_id>/terms` route resolves to —
	 * `wc_rest_check_product_term_permissions( $taxonomy, 'read' )` maps the read
	 * context to `manage_terms`, registered as `manage_product_terms` for the
	 * `pa_*` attribute taxonomies. This is a coarse, object-independent guard; the
	 * wrapped route surfaces the specific 404 (`woocommerce_rest_taxonomy_invalid`)
	 * for an unknown attribute_id rather than masking it as a permission failure.
	 * The explicit activity guard keeps the denial clean when WooCommerce is
	 * inactive and the capability is unmapped.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may read product attribute terms.
	 */
	public function hasPermission( $input ): bool {
		return WooPlugin::isActive() && current_user_can( 'manage_product_terms' );
	}

	/**
	 * Executes the ability by dispatching the internal WooCommerce REST request.
	 *
	 * The `attribute_id` is a route segment, so the path is built by concatenation
	 * (never `set_param`), matching the controller's
	 * `products/attributes/(?P<attribute_id>[\d]+)/terms` route.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The list of attribute terms, or the REST error.
	 */
	public function execute( $input ) {
		if ( ! WooPlugin::isActive() ) {
			return WooPlugin::unavailable();
		}

		$input        = is_array( $input ) ? $input : array();
		$attribute_id = absint( $input['attribute_id'] ?? 0 );

		$request = new WP_REST_Request( 'GET', '/wc/v3/products/attributes/' . $attribute_id . '/terms' );

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
			'attribute_id' => $attribute_id,
			'items'        => $rows,
			'total'        => $total,
		);
	}
}
