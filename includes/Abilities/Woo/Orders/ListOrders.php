<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogWoo\Abilities\Woo\Orders;

use GalatanOvidiu\AbilitiesCatalogWoo\Contracts\ConditionalAbility;
use GalatanOvidiu\AbilitiesCatalogWoo\Support\OrderListShaper;
use GalatanOvidiu\AbilitiesCatalogWoo\Support\RestError;
use GalatanOvidiu\AbilitiesCatalogWoo\Support\WooPlugin;
use WP_REST_Request;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read ability: `wc-orders/list-orders`.
 *
 * Wraps `GET wc/v3/orders` via `rest_do_request()` and returns each order as a
 * flat summary row through {@see OrderListShaper::summary()}, so a consumer scans
 * the store's orders without the raw ~100-field order body. Exposes a minimal,
 * useful subset of the controller's collection params (search, paging, status,
 * customer, date window, ordering) — not every filter.
 *
 * Only available when WooCommerce is active (it is a {@see ConditionalAbility}).
 * The WC orders list route sends pagination headers, so `total` is the full
 * matching count from `X-WP-Total`, not just the number of rows on this page.
 *
 * PII: orders carry billing name, email, and address. The `read_private_shop_orders`
 * capability is the hard server-side guard; the shaper exposes a sensible subset
 * and never returns the raw order or its `meta_data`.
 *
 * @since 0.1.0
 */
final class ListOrders implements ConditionalAbility {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'wc-orders/list-orders';
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
			'label'               => __( 'List Orders', 'abilities-catalog-woo' ),
			'description'         => __( 'Returns the store\'s WooCommerce orders as flat summary rows, each with its id, number, status, currency, total, customer_id, billing name and email, and line-item count. Filter by search term, status, customer (user ID), or a date window (after/before), and sort with orderby/order. Use wc-orders/get-order for one order\'s full detail (line items, billing and shipping blocks, edit_link). Orders carry buyer PII; read-only, and only callers with the order capability may read them.', 'abilities-catalog-woo' ),
			'category'            => 'wc-orders',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'search'   => array(
						'type'        => 'string',
						'description' => __( 'Limit results to orders matching a search term (matches order fields such as the billing details).', 'abilities-catalog-woo' ),
					),
					'per_page' => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'maximum'     => 100,
						'default'     => 100,
						'description' => __( 'Maximum number of orders to return (1-100). Defaults to 100.', 'abilities-catalog-woo' ),
					),
					'page'     => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'default'     => 1,
						'description' => __( 'The page of results to return, starting at 1. Use total to compute how many pages exist.', 'abilities-catalog-woo' ),
					),
					'status'   => array(
						'type'        => 'string',
						'enum'        => array( 'any', 'trash', 'auto-draft', 'pending', 'processing', 'on-hold', 'completed', 'cancelled', 'refunded', 'failed' ),
						'default'     => 'any',
						'description' => __( 'Limit results to orders with a specific status. "any" (the default) returns every status the caller may read.', 'abilities-catalog-woo' ),
					),
					'customer' => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'Limit results to orders placed by this customer (a WordPress user ID). Discover IDs with wc-customers/list-customers. Guest orders have customer_id 0 and are not matched by this filter.', 'abilities-catalog-woo' ),
					),
					'after'    => array(
						'type'        => 'string',
						'format'      => 'date-time',
						'description' => __( 'Limit results to orders created after this date, as an ISO 8601 date-time string (e.g. 2024-01-31T13:45:00).', 'abilities-catalog-woo' ),
					),
					'before'   => array(
						'type'        => 'string',
						'format'      => 'date-time',
						'description' => __( 'Limit results to orders created before this date, as an ISO 8601 date-time string (e.g. 2024-01-31T13:45:00).', 'abilities-catalog-woo' ),
					),
					'orderby'  => array(
						'type'        => 'string',
						'enum'        => array( 'date', 'id', 'include', 'title', 'slug', 'modified' ),
						'default'     => 'date',
						'description' => __( 'Sort the result set by this attribute. Defaults to "date".', 'abilities-catalog-woo' ),
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
						'description' => __( 'The orders as flat summary rows. Use wc-orders/get-order for a single order\'s full detail.', 'abilities-catalog-woo' ),
						'items'       => OrderListShaper::itemSchema(),
					),
					'total' => array(
						'type'        => 'integer',
						'description' => __( 'The total number of orders matching the query across all pages, read from the X-WP-Total response header. May exceed the number of returned rows when paging.', 'abilities-catalog-woo' ),
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
	 * Permission check: WooCommerce's read capability for orders.
	 *
	 * Encodes the catalog baseline for `wc-orders/list-orders`: the
	 * `read_private_shop_orders` capability, which is what
	 * `wc_rest_check_post_permissions( 'shop_order', 'read' )` resolves to on the
	 * wrapped `GET wc/v3/orders` route (the `shop_order` post type maps the meta
	 * cap `read_private_posts` to the primitive `read_private_shop_orders` via its
	 * `capability_type`). This is the hard, object-independent guard for a surface
	 * that carries buyer PII; the wrapped route applies any per-order visibility.
	 * The explicit activity guard keeps the denial clean when WooCommerce is
	 * inactive and the capability is unmapped.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may read the store's orders.
	 */
	public function hasPermission( $input ): bool {
		return WooPlugin::isActive() && current_user_can( 'read_private_shop_orders' );
	}

	/**
	 * Executes the ability by dispatching the internal WooCommerce REST request.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The list of orders, or the REST error.
	 */
	public function execute( $input ) {
		if ( ! WooPlugin::isActive() ) {
			return WooPlugin::unavailable();
		}

		$input = is_array( $input ) ? $input : array();

		$request = new WP_REST_Request( 'GET', '/wc/v3/orders' );

		if ( ! empty( $input['search'] ) ) {
			$request->set_param( 'search', (string) $input['search'] );
		}
		$request->set_param( 'per_page', max( 1, min( 100, absint( $input['per_page'] ?? 100 ) ) ) );
		$request->set_param( 'page', max( 1, absint( $input['page'] ?? 1 ) ) );
		// The V3 status param is type array, so pass the single status as a one-element array.
		$request->set_param( 'status', array( (string) ( $input['status'] ?? 'any' ) ) );
		if ( ! empty( $input['customer'] ) ) {
			$request->set_param( 'customer', absint( $input['customer'] ) );
		}
		if ( ! empty( $input['after'] ) ) {
			$request->set_param( 'after', (string) $input['after'] );
		}
		if ( ! empty( $input['before'] ) ) {
			$request->set_param( 'before', (string) $input['before'] );
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

			$rows[] = OrderListShaper::summary( $item );
		}

		$headers = $response->get_headers();
		$total   = isset( $headers['X-WP-Total'] ) ? (int) $headers['X-WP-Total'] : count( $rows );

		return array(
			'items' => $rows,
			'total' => $total,
		);
	}
}
