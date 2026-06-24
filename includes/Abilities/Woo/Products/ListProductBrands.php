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
 * Read ability: `wc-products/list-product-brands`.
 *
 * Wraps `GET wc/v3/products/brands` via `rest_do_request()` and returns each
 * product brand as a flat summary row through
 * {@see ProductTermListShaper::termSummary()}, so a consumer scans the brand
 * tree without the raw brand body. The Brands controller extends the categories
 * controller, so the raw row also carries `display`, `image`, and `menu_order`,
 * which a consumer scanning the taxonomy never needs; this projects only the
 * shared term fields. Exposes a minimal, useful subset of the terms controller's
 * collection params (search, paging, ordering, parent, hide_empty) — not every
 * filter.
 *
 * Only available when WooCommerce is active AND the product Brands feature has
 * registered its `/wc/v3/products/brands` route (it is a {@see ConditionalAbility}
 * gated on {@see WooPlugin::hasBrandsSupport()}). When Brands is absent this
 * ability does not register, so it degrades cleanly rather than denying.
 *
 * The WC terms list route sends pagination headers, so `total` is the full
 * matching count from `X-WP-Total`, not just the number of rows on this page.
 * The wrapped route falls back to `count( $rows )` only if that header is absent.
 *
 * @since 0.1.0
 */
final class ListProductBrands implements ConditionalAbility {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'wc-products/list-product-brands';
	}

	/**
	 * {@inheritDoc}
	 */
	public function isAvailable(): bool {
		return WooPlugin::isActive() && WooPlugin::hasBrandsSupport();
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return array(
			'label'               => __( 'List Product Brands', 'abilities-catalog-woo' ),
			'description'         => __( 'Returns the store\'s WooCommerce product brands as flat summary rows, each with its id, name, slug, parent, product count, and description. Filter by search term or parent brand ID, hide empty brands, page through results, and sort with orderby/order. Product brands are hierarchical: use parent to walk the tree (parent 0 returns only top-level brands). This ability exists only when the store\'s WooCommerce Brands feature is active. Use wc-products/get-product-brand for one brand by ID. Read-only: does not return the brand image, display type, or menu order.', 'abilities-catalog-woo' ),
			'category'            => 'wc-products',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'search'     => array(
						'type'        => 'string',
						'description' => __( 'Limit results to brands whose name matches a search term.', 'abilities-catalog-woo' ),
					),
					'per_page'   => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'maximum'     => 100,
						'default'     => 100,
						'description' => __( 'Maximum number of brands to return (1-100). Defaults to 100, which covers every brand on a typical store.', 'abilities-catalog-woo' ),
					),
					'page'       => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'default'     => 1,
						'description' => __( 'The page of results to return, starting at 1. Use total to compute how many pages exist.', 'abilities-catalog-woo' ),
					),
					'slug'       => array(
						'type'        => 'string',
						'description' => __( 'Limit results to the brand with this exact slug.', 'abilities-catalog-woo' ),
					),
					'parent'     => array(
						'type'        => 'integer',
						'minimum'     => 0,
						'description' => __( 'Limit results to brands whose parent is this brand term ID. Use 0 to return only top-level brands.', 'abilities-catalog-woo' ),
					),
					'hide_empty' => array(
						'type'        => 'boolean',
						'description' => __( 'When true, exclude brands that have no published products assigned.', 'abilities-catalog-woo' ),
					),
					'orderby'    => array(
						'type'        => 'string',
						'enum'        => array( 'id', 'include', 'name', 'slug', 'term_group', 'description', 'count' ),
						'default'     => 'name',
						'description' => __( 'Sort the result set by this attribute: "name" (the default), "id", "include", "slug", "term_group", "description", or "count" (number of products).', 'abilities-catalog-woo' ),
					),
					'order'      => array(
						'type'        => 'string',
						'enum'        => array( 'asc', 'desc' ),
						'default'     => 'asc',
						'description' => __( 'Sort direction: "asc" (ascending, the default) or "desc" (descending).', 'abilities-catalog-woo' ),
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
						'description' => __( 'The product brands as flat summary rows. Use wc-products/get-product-brand for a single brand by ID.', 'abilities-catalog-woo' ),
						'items'       => ProductTermListShaper::termItemSchema(),
					),
					'total' => array(
						'type'        => 'integer',
						'description' => __( 'The total number of brands matching the query across all pages, read from the X-WP-Total response header. May exceed the number of returned rows when paging.', 'abilities-catalog-woo' ),
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
	 * Encodes the catalog baseline for `wc-products/list-product-brands`: the
	 * `manage_product_terms` capability, which is what
	 * `wc_rest_check_product_term_permissions( 'product_brand', 'read' )` resolves
	 * to on the wrapped `GET wc/v3/products/brands` route (it reads the
	 * `manage_terms` cap of the `product_brand` taxonomy, registered as
	 * `manage_product_terms`). This is a coarse, object-independent guard; the
	 * wrapped route applies any per-term checks. The brands-support gate lives in
	 * {@see self::isAvailable()}, not here, so this stays a plain capability check;
	 * the explicit activity guard keeps the denial clean when WooCommerce is
	 * inactive and the capability is unmapped.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may read product brands.
	 */
	public function hasPermission( $input ): bool {
		return WooPlugin::isActive() && current_user_can( 'manage_product_terms' );
	}

	/**
	 * Executes the ability by dispatching the internal WooCommerce REST request.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The list of brands, or the REST error.
	 */
	public function execute( $input ) {
		if ( ! WooPlugin::isActive() ) {
			return WooPlugin::unavailable();
		}

		$input = is_array( $input ) ? $input : array();

		$request = new WP_REST_Request( 'GET', '/wc/v3/products/brands' );

		if ( ! empty( $input['search'] ) ) {
			$request->set_param( 'search', (string) $input['search'] );
		}
		$request->set_param( 'per_page', max( 1, min( 100, absint( $input['per_page'] ?? 100 ) ) ) );
		$request->set_param( 'page', max( 1, absint( $input['page'] ?? 1 ) ) );
		if ( ! empty( $input['slug'] ) ) {
			$request->set_param( 'slug', (string) $input['slug'] );
		}
		if ( array_key_exists( 'parent', $input ) ) {
			$request->set_param( 'parent', absint( $input['parent'] ) );
		}
		if ( array_key_exists( 'hide_empty', $input ) ) {
			$request->set_param( 'hide_empty', BooleanInput::sanitize( $input['hide_empty'] ) );
		}
		$request->set_param( 'orderby', (string) ( $input['orderby'] ?? 'name' ) );
		$request->set_param( 'order', (string) ( $input['order'] ?? 'asc' ) );

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
