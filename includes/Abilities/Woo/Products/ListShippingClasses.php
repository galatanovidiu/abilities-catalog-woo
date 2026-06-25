<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogWoo\Abilities\Woo\Products;

use GalatanOvidiu\AbilitiesCatalogWoo\Contracts\ConditionalAbility;
use GalatanOvidiu\AbilitiesCatalogWoo\Support\BooleanInput;
use GalatanOvidiu\AbilitiesCatalogWoo\Support\RestError;
use GalatanOvidiu\AbilitiesCatalogWoo\Support\WooPlugin;
use WP_REST_Request;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read ability: `og-wc-products/list-shipping-classes`.
 *
 * Wraps `GET wc/v3/products/shipping_classes` via `rest_do_request()` and returns
 * each shipping class as a flat summary row, so a consumer scans the store's
 * shipping classes without the raw term body. Filter with search, paging, ordering,
 * and `hide_empty`.
 *
 * `product_shipping_class` is a flat (non-hierarchical) taxonomy, so the
 * controller's schema omits `parent`. This ability shapes its rows inline (rather
 * than through the shared {@see \GalatanOvidiu\AbilitiesCatalogWoo\Support\ProductTermListShaper},
 * which carries a `parent` field) so a shipping-class row exposes exactly the five
 * fields the WC controller returns — `id`, `name`, `slug`, `count`, `description` —
 * with no `parent` and no raw term fields. Use `og-wc-products/get-shipping-class` for
 * one shipping class by ID.
 *
 * Only available when WooCommerce is active (it is a {@see ConditionalAbility}).
 * The WC shipping-classes list route sends pagination headers, so `total` is the
 * full matching count from `X-WP-Total`, not just the number of rows on this page;
 * if the header is ever absent it falls back to the number of returned rows.
 *
 * @since 0.1.0
 */
final class ListShippingClasses implements ConditionalAbility {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-wc-products/list-shipping-classes';
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
			'label'               => __( 'List Shipping Classes', 'abilities-catalog-woo' ),
			'description'         => __( 'Returns the store\'s WooCommerce product shipping classes as flat summary rows, each with its id, name, slug, product count, and description. Filter by search term, page through results, sort with orderby/order, and set hide_empty to drop shipping classes with no published products. Shipping classes are flat (no hierarchy), so a row has no parent field. Use og-wc-products/get-shipping-class for one shipping class by ID.', 'abilities-catalog-woo' ),
			'category'            => 'og-wc-products',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'search'     => array(
						'type'        => 'string',
						'description' => __( 'Limit results to shipping classes whose name matches a search term.', 'abilities-catalog-woo' ),
					),
					'per_page'   => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'maximum'     => 100,
						'default'     => 100,
						'description' => __( 'Maximum number of shipping classes to return (1-100). Defaults to 100, which covers every shipping class on a typical store.', 'abilities-catalog-woo' ),
					),
					'page'       => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'default'     => 1,
						'description' => __( 'The page of results to return, starting at 1. Use total to compute how many pages exist.', 'abilities-catalog-woo' ),
					),
					'slug'       => array(
						'type'        => 'string',
						'description' => __( 'Limit results to the shipping class with this exact slug.', 'abilities-catalog-woo' ),
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
						'description' => __( 'When true, omit shipping classes that have no published products assigned.', 'abilities-catalog-woo' ),
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
						'description' => __( 'The product shipping classes as flat summary rows. Use og-wc-products/get-shipping-class for a single shipping class by ID. A row has no parent field because shipping classes are a flat taxonomy.', 'abilities-catalog-woo' ),
						'items'       => self::shippingClassItemSchema(),
					),
					'total' => array(
						'type'        => 'integer',
						'description' => __( 'The total number of shipping classes matching the query across all pages, read from the X-WP-Total response header. May exceed the number of returned rows when paging.', 'abilities-catalog-woo' ),
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
	 * Encodes the catalog baseline for `og-wc-products/list-shipping-classes`: the
	 * `manage_product_terms` capability, which is what
	 * `wc_rest_check_product_term_permissions( 'product_shipping_class', 'read' )`
	 * resolves to on the wrapped `GET wc/v3/products/shipping_classes` route — the
	 * helper reads the `read` context as `manage_terms`, which the
	 * `product_shipping_class` taxonomy registers as `manage_product_terms`. This is
	 * a coarse, object-independent guard. The explicit activity guard keeps the
	 * denial clean when WooCommerce is inactive and the capability is unmapped.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may read product shipping classes.
	 */
	public function hasPermission( $input ): bool {
		return WooPlugin::isActive() && current_user_can( 'manage_product_terms' );
	}

	/**
	 * Executes the ability by dispatching the internal WooCommerce REST request.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The list of shipping classes, or the REST error.
	 */
	public function execute( $input ) {
		if ( ! WooPlugin::isActive() ) {
			return WooPlugin::unavailable();
		}

		$input = is_array( $input ) ? $input : array();

		$request = new WP_REST_Request( 'GET', '/wc/v3/products/shipping_classes' );

		if ( ! empty( $input['search'] ) ) {
			$request->set_param( 'search', (string) $input['search'] );
		}
		$request->set_param( 'per_page', max( 1, min( 100, absint( $input['per_page'] ?? 100 ) ) ) );
		$request->set_param( 'page', max( 1, absint( $input['page'] ?? 1 ) ) );
		if ( ! empty( $input['slug'] ) ) {
			$request->set_param( 'slug', (string) $input['slug'] );
		}
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

			$rows[] = self::shippingClassSummary( $item );
		}

		$headers = $response->get_headers();
		$total   = isset( $headers['X-WP-Total'] ) ? (int) $headers['X-WP-Total'] : count( $rows );

		return array(
			'items' => $rows,
			'total' => $total,
		);
	}

	/**
	 * Flat summary row for a single `wc/v3/products/shipping_classes` list item.
	 *
	 * Shaped inline (not through the shared {@see \GalatanOvidiu\AbilitiesCatalogWoo\Support\ProductTermListShaper})
	 * because the `product_shipping_class` taxonomy is flat: the WC controller's
	 * schema omits `parent`, so this row exposes exactly the five fields the
	 * controller returns, with no `parent`. Each value is read with a
	 * null-coalescing default and cast to the type the WC schema guarantees.
	 *
	 * @param array<string,mixed> $row A single row from a `GET wc/v3/products/shipping_classes` response.
	 * @return array{id:int,name:string,slug:string,count:int,description:string} The flat shipping-class summary row.
	 */
	private static function shippingClassSummary( array $row ): array {
		return array(
			'id'          => (int) ( $row['id'] ?? 0 ),
			'name'        => (string) ( $row['name'] ?? '' ),
			'slug'        => (string) ( $row['slug'] ?? '' ),
			'count'       => (int) ( $row['count'] ?? 0 ),
			'description' => (string) ( $row['description'] ?? '' ),
		);
	}

	/**
	 * The `output_schema` item definition matching {@see self::shippingClassSummary()}.
	 *
	 * @return array<string,mixed> A JSON-Schema object fragment.
	 */
	private static function shippingClassItemSchema(): array {
		return array(
			'type'                 => 'object',
			'required'             => array( 'id' ),
			'properties'           => array(
				'id'          => array(
					'type'        => 'integer',
					'description' => __( 'The shipping class ID. Read one shipping class with og-wc-products/get-shipping-class.', 'abilities-catalog-woo' ),
				),
				'name'        => array(
					'type'        => 'string',
					'description' => __( 'The shipping class name shown in the admin.', 'abilities-catalog-woo' ),
				),
				'slug'        => array(
					'type'        => 'string',
					'description' => __( 'The shipping class slug used in queries.', 'abilities-catalog-woo' ),
				),
				'count'       => array(
					'type'        => 'integer',
					'description' => __( 'The number of published products assigned to this shipping class.', 'abilities-catalog-woo' ),
				),
				'description' => array(
					'type'        => 'string',
					'description' => __( 'The shipping class description, or an empty string when none is set.', 'abilities-catalog-woo' ),
				),
			),
			'additionalProperties' => false,
		);
	}
}
