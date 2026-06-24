<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogWoo\Abilities\Woo\Analytics;

use GalatanOvidiu\AbilitiesCatalogWoo\Contracts\ConditionalAbility;
use GalatanOvidiu\AbilitiesCatalogWoo\Support\AnalyticsReportShaper;
use GalatanOvidiu\AbilitiesCatalogWoo\Support\RestError;
use GalatanOvidiu\AbilitiesCatalogWoo\Support\WooPlugin;
use WP_REST_Request;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read ability: `wc-reports/list-products-analytics`.
 *
 * Wraps `GET /wc-analytics/reports/products` via `rest_do_request()` and returns
 * each product's sales performance over a date range as a flat summary row:
 * `product_id`, `items_sold`, `net_revenue`, `orders_count`, plus the product
 * `name` and `sku` lifted out of the controller's `extended_info`. The fat
 * `extended_info` object (image, category_ids, variations, stock) is never
 * returned — only the two identity fields are flattened out, the rest dropped.
 *
 * This is the per-product list read; use `wc-reports/get-products-stats` for the
 * aggregated totals (items sold, net revenue, orders) over a range. Discover the
 * date range with `after`/`before` (ISO8601 date-time).
 *
 * Only available when the store's WooCommerce **Analytics** feature is enabled (it
 * is a {@see ConditionalAbility}); when Analytics is off the ability does not
 * register at all. The analytics product report reads the `wc_order_product_lookup`
 * table, which is populated by an async data sync, so a freshly-placed order may not
 * appear until that sync runs.
 *
 * @since 0.1.0
 */
final class ListProductsAnalytics implements ConditionalAbility {

	/**
	 * Whitelist of returned row fields mapped to their cast type.
	 *
	 * `name` and `sku` are absent at the row top level — {@see AnalyticsReportShaper::analyticsRow()}
	 * falls back to `$row['extended_info'][$field]` for them, which is why the
	 * dispatched request sets `extended_info` to true.
	 *
	 * @var array<string,string>
	 */
	private const ROW_KEYS = array(
		'product_id'   => 'int',
		'items_sold'   => 'int',
		'net_revenue'  => 'float',
		'orders_count' => 'int',
		'name'         => 'string',
		'sku'          => 'string',
	);

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'wc-reports/list-products-analytics';
	}

	/**
	 * {@inheritDoc}
	 */
	public function isAvailable(): bool {
		return WooPlugin::isActive() && WooPlugin::hasAnalytics();
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return array(
			'label'               => __( 'List Products Analytics', 'abilities-catalog-woo' ),
			'description'         => __( 'Returns per-product sales performance from WooCommerce Analytics as flat rows, each with product_id, items_sold, net_revenue, orders_count, and the product name and sku. The large extended_info block (image, categories, variations, stock) is intentionally dropped. Use this for a ranked product list over a date range; use wc-reports/get-products-stats for the aggregated totals. The range is set by after/before as ISO8601 date-times; omit them for the full history. Only available when the store\'s WooCommerce Analytics feature is enabled. The report reads an analytics lookup table that an async sync populates, so a just-placed order may not appear immediately.', 'abilities-catalog-woo' ),
			'category'            => 'wc-reports',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'after'    => array(
						'type'        => 'string',
						'format'      => 'date-time',
						'description' => __( 'Limit to products with sales on or after this ISO8601 date-time (e.g. 2024-01-01T00:00:00). Omit for no lower bound.', 'abilities-catalog-woo' ),
					),
					'before'   => array(
						'type'        => 'string',
						'format'      => 'date-time',
						'description' => __( 'Limit to products with sales on or before this ISO8601 date-time (e.g. 2024-01-31T23:59:59). Omit for no upper bound.', 'abilities-catalog-woo' ),
					),
					'per_page' => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'maximum'     => 100,
						'default'     => 100,
						'description' => __( 'Maximum number of product rows to return (1-100). Defaults to 100.', 'abilities-catalog-woo' ),
					),
					'page'     => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'default'     => 1,
						'description' => __( 'Page of results to return, for paging past the first per_page rows.', 'abilities-catalog-woo' ),
					),
					'order'    => array(
						'type'        => 'string',
						'enum'        => array( 'asc', 'desc' ),
						'default'     => 'desc',
						'description' => __( 'Sort direction: "asc" (ascending) or "desc" (descending).', 'abilities-catalog-woo' ),
					),
					'orderby'  => array(
						'type'        => 'string',
						'enum'        => array( 'date', 'net_revenue', 'orders_count', 'items_sold', 'product_name', 'variations', 'sku' ),
						'default'     => 'date',
						'description' => __( 'Field to sort by: "date", "net_revenue", "orders_count", "items_sold", "product_name", "variations", or "sku".', 'abilities-catalog-woo' ),
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
						'description' => __( 'The per-product analytics rows. Use wc-reports/get-products-stats for the aggregated totals over the range.', 'abilities-catalog-woo' ),
						'items'       => AnalyticsReportShaper::analyticsItemSchema(
							array(
								'product_id'   => array(
									'type'        => 'integer',
									'description' => __( 'The product ID.', 'abilities-catalog-woo' ),
								),
								'items_sold'   => array(
									'type'        => 'integer',
									'description' => __( 'Number of units of this product sold in the range.', 'abilities-catalog-woo' ),
								),
								'net_revenue'  => array(
									'type'        => 'number',
									'description' => __( 'Net sales of this product in the range (after refunds, excluding tax and shipping), in the store currency.', 'abilities-catalog-woo' ),
								),
								'orders_count' => array(
									'type'        => 'integer',
									'description' => __( 'Number of distinct orders this product appeared in.', 'abilities-catalog-woo' ),
								),
								'name'         => array(
									'type'        => 'string',
									'description' => __( 'The product name, lifted from the report\'s extended_info, or an empty string when unavailable.', 'abilities-catalog-woo' ),
								),
								'sku'          => array(
									'type'        => 'string',
									'description' => __( 'The product SKU, lifted from the report\'s extended_info, or an empty string when none is set.', 'abilities-catalog-woo' ),
								),
							)
						),
					),
					'total' => array(
						'type'        => 'integer',
						'description' => __( 'Total number of products matching the query, from the X-WP-Total response header (falls back to the number of returned rows if the header is absent). May exceed the returned rows when paging.', 'abilities-catalog-woo' ),
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
	 * Permission check: WooCommerce reports view capability, gated on Analytics.
	 *
	 * The wrapped `/wc-analytics/reports/products` route resolves its permission to
	 * `view_woocommerce_reports` (`WC_REST_Reports_V1_Controller::get_items_permissions_check()`
	 * → `wc_rest_check_manager_permissions( 'reports', 'read' )`), so this mirrors that
	 * exact cap as the coarse, object-independent guard. The `hasAnalytics()` gate keeps
	 * the denial clean when the Analytics feature is off (the route is not registered),
	 * matching the same conditional gate batches 12 and 13 use.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may read WooCommerce analytics reports.
	 */
	public function hasPermission( $input ): bool {
		return WooPlugin::isActive() && WooPlugin::hasAnalytics() && current_user_can( 'view_woocommerce_reports' );
	}

	/**
	 * Executes the ability by dispatching the internal `wc-analytics` REST request.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The shaped product rows and total, or the REST error.
	 */
	public function execute( $input ) {
		if ( ! WooPlugin::isActive() ) {
			return WooPlugin::unavailable();
		}

		$input = is_array( $input ) ? $input : array();

		$request = new WP_REST_Request( 'GET', '/wc-analytics/reports/products' );
		// extended_info carries name/sku, which the report omits from the top-level row.
		$request->set_param( 'extended_info', true );
		$request->set_param( 'per_page', max( 1, min( 100, absint( $input['per_page'] ?? 100 ) ) ) );
		$request->set_param( 'page', max( 1, absint( $input['page'] ?? 1 ) ) );
		$request->set_param( 'order', 'asc' === strtolower( (string) ( $input['order'] ?? 'desc' ) ) ? 'asc' : 'desc' );
		$request->set_param( 'orderby', (string) ( $input['orderby'] ?? 'date' ) );
		if ( ! empty( $input['after'] ) ) {
			$request->set_param( 'after', (string) $input['after'] );
		}
		if ( ! empty( $input['before'] ) ) {
			$request->set_param( 'before', (string) $input['before'] );
		}

		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			return RestError::from( $response );
		}

		$data = rest_get_server()->response_to_data( $response, false );

		$rows = array();
		foreach ( is_array( $data ) ? $data : array() as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$rows[] = AnalyticsReportShaper::analyticsRow( $row, self::ROW_KEYS );
		}

		$headers = $response->get_headers();
		$total   = isset( $headers['X-WP-Total'] ) ? (int) $headers['X-WP-Total'] : count( $rows );

		return array(
			'items' => $rows,
			'total' => $total,
		);
	}
}
