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
 * Read ability: `wc-reports/list-categories-analytics`.
 *
 * Wraps `GET /wc-analytics/reports/categories` via `rest_do_request()` and returns
 * each product-category's sales performance over a date range as a flat summary
 * row: `category_id`, `items_sold`, `net_revenue`, `orders_count`,
 * `products_count`, plus the category `category_name` lifted out of the
 * controller's `extended_info` (which is otherwise dropped).
 *
 * This is the per-category list read; there is no matching `categories/stats`
 * report in core (the slug appears in the discovery index but registers no route).
 * Discover the date range with `after`/`before` (ISO8601 date-time); omit them for
 * the full history.
 *
 * Only available when the store's WooCommerce **Analytics** feature is enabled (it
 * is a {@see ConditionalAbility}); when Analytics is off the ability does not
 * register at all. The analytics category report reads the `wc_order_product_lookup`
 * table, which is populated by an async data sync, so a freshly-placed order may not
 * appear until that sync runs.
 *
 * @since 0.1.0
 */
final class ListCategoriesAnalytics implements ConditionalAbility {

	/**
	 * Whitelist of returned row fields mapped to their cast type.
	 *
	 * `category_name` is not a row field; the controller nests the category name at
	 * `extended_info.name`. {@see AnalyticsReportShaper::analyticsRow()} looks up by
	 * the exact field key, so the shaper lifts `name`, and {@see self::execute()}
	 * remaps that to the `category_name` output field (so the source key `name` does
	 * not leak into the closed schema). The dispatched request therefore sets
	 * `extended_info` to true.
	 *
	 * @var array<string,string>
	 */
	private const ROW_KEYS = array(
		'category_id'    => 'int',
		'items_sold'     => 'int',
		'net_revenue'    => 'float',
		'orders_count'   => 'int',
		'products_count' => 'int',
		'name'           => 'string',
	);

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'wc-reports/list-categories-analytics';
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
			'label'               => __( 'List Categories Analytics', 'abilities-catalog-woo' ),
			'description'         => __( 'Returns per-category sales performance from WooCommerce Analytics as flat rows, each with category_id, items_sold, net_revenue, orders_count, products_count, and the category_name. It answers "which product categories sold the most over a date range". The large extended_info block is intentionally dropped; only the category name is lifted out. The range is set by after/before as ISO8601 date-times; omit them for the full history. There is no matching categories stats report in core, so this list is the only category analytics read. Only available when the store\'s WooCommerce Analytics feature is enabled. The report reads an analytics lookup table that an async sync populates, so a just-placed order may not appear immediately.', 'abilities-catalog-woo' ),
			'category'            => 'wc-reports',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'after'    => array(
						'type'        => 'string',
						'format'      => 'date-time',
						'description' => __( 'Limit to categories with sales on or after this ISO8601 date-time (e.g. 2024-01-01T00:00:00). Omit for no lower bound.', 'abilities-catalog-woo' ),
					),
					'before'   => array(
						'type'        => 'string',
						'format'      => 'date-time',
						'description' => __( 'Limit to categories with sales on or before this ISO8601 date-time (e.g. 2024-01-31T23:59:59). Omit for no upper bound.', 'abilities-catalog-woo' ),
					),
					'interval' => array(
						'type'        => 'string',
						'enum'        => array( 'hour', 'day', 'week', 'month', 'quarter', 'year' ),
						'description' => __( 'Time bucket size used to compute the report over the range: "hour", "day", "week", "month", "quarter", or "year". Affects aggregation, not the returned row shape.', 'abilities-catalog-woo' ),
					),
					'per_page' => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'maximum'     => 100,
						'default'     => 10,
						'description' => __( 'Maximum number of category rows to return (1-100). Defaults to 10.', 'abilities-catalog-woo' ),
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
						'enum'        => array( 'category_id', 'items_sold', 'net_revenue', 'orders_count', 'products_count', 'category' ),
						'default'     => 'category_id',
						'description' => __( 'Field to sort by: "category_id", "items_sold", "net_revenue", "orders_count", "products_count", or "category" (by category name).', 'abilities-catalog-woo' ),
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
						'description' => __( 'The per-category analytics rows. There is no matching categories stats report; this is the category analytics surface.', 'abilities-catalog-woo' ),
						'items'       => AnalyticsReportShaper::analyticsItemSchema(
							array(
								'category_id'    => array(
									'type'        => 'integer',
									'description' => __( 'The product-category term ID.', 'abilities-catalog-woo' ),
								),
								'items_sold'     => array(
									'type'        => 'integer',
									'description' => __( 'Number of units sold across products in this category in the range.', 'abilities-catalog-woo' ),
								),
								'net_revenue'    => array(
									'type'        => 'number',
									'description' => __( 'Net sales for this category in the range (after refunds, excluding tax and shipping), in the store currency.', 'abilities-catalog-woo' ),
								),
								'orders_count'   => array(
									'type'        => 'integer',
									'description' => __( 'Number of distinct orders containing a product from this category.', 'abilities-catalog-woo' ),
								),
								'products_count' => array(
									'type'        => 'integer',
									'description' => __( 'Number of distinct products from this category that sold in the range.', 'abilities-catalog-woo' ),
								),
								'category_name'  => array(
									'type'        => 'string',
									'description' => __( 'The category name, lifted from the report\'s extended_info, or an empty string when unavailable.', 'abilities-catalog-woo' ),
								),
							)
						),
					),
					'total' => array(
						'type'        => 'integer',
						'description' => __( 'Total number of categories matching the query, from the X-WP-Total response header (falls back to the number of returned rows if the header is absent). May exceed the returned rows when paging.', 'abilities-catalog-woo' ),
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
	 * The wrapped `/wc-analytics/reports/categories` route resolves its permission to
	 * `view_woocommerce_reports` (`WC_REST_Reports_V1_Controller::get_items_permissions_check()`
	 * → `wc_rest_check_manager_permissions( 'reports', 'read' )`), so this mirrors that
	 * exact cap as the coarse, object-independent guard. The `hasAnalytics()` gate keeps
	 * the denial clean when the Analytics feature is off (the route is not registered).
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
	 * @return array<string,mixed>|\WP_Error The shaped category rows and total, or the REST error.
	 */
	public function execute( $input ) {
		if ( ! WooPlugin::isActive() ) {
			return WooPlugin::unavailable();
		}

		$input = is_array( $input ) ? $input : array();

		$request = new WP_REST_Request( 'GET', '/wc-analytics/reports/categories' );
		// extended_info carries the category name, which the report omits from the top-level row.
		$request->set_param( 'extended_info', true );
		$request->set_param( 'per_page', max( 1, min( 100, absint( $input['per_page'] ?? 10 ) ) ) );
		$request->set_param( 'page', max( 1, absint( $input['page'] ?? 1 ) ) );
		$request->set_param( 'order', 'asc' === strtolower( (string) ( $input['order'] ?? 'desc' ) ) ? 'asc' : 'desc' );
		$request->set_param( 'orderby', (string) ( $input['orderby'] ?? 'category_id' ) );
		if ( ! empty( $input['interval'] ) ) {
			$request->set_param( 'interval', (string) $input['interval'] );
		}
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

			$shaped = AnalyticsReportShaper::analyticsRow( $row, self::ROW_KEYS );

			// The controller nests the category name at extended_info.name; the shaper
			// lifts it under the source key `name`, so remap it to the closed output
			// field `category_name` and drop the raw `name` key.
			$shaped['category_name'] = $shaped['name'];
			unset( $shaped['name'] );

			$rows[] = $shaped;
		}

		$headers = $response->get_headers();
		$total   = isset( $headers['X-WP-Total'] ) ? (int) $headers['X-WP-Total'] : count( $rows );

		return array(
			'items' => $rows,
			'total' => $total,
		);
	}
}
