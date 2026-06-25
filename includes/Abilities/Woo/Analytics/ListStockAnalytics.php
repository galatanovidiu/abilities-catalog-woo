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
 * Read ability: `og-wc-reports/list-stock-analytics`.
 *
 * Wraps `GET /wc-analytics/reports/stock` via `rest_do_request()` and returns the
 * store's CURRENT inventory snapshot as flat rows — one per product/variation —
 * each with `id`, `parent_id`, `name`, `sku`, `stock_status`, `stock_quantity`, and
 * `manage_stock`. This is a present-state snapshot, NOT a time series: the stock
 * report controller `unset()`s the `after`/`before` date params, so there is no
 * date window to filter on.
 *
 * This is the per-product inventory list read; use `og-wc-reports/get-stock-stats` for
 * the store-wide instock/lowstock/outofstock counts. The controller always casts
 * `stock_quantity` to a number, so a product that does not manage stock reports `0`
 * (with `manage_stock` false), never a JSON null.
 *
 * Only available when the store's WooCommerce **Analytics** feature is enabled (it
 * is a {@see ConditionalAbility}); when Analytics is off the ability does not
 * register at all. The stock report reads the `wc_product_meta_lookup` table, which
 * is kept in sync as products are saved, so a just-saved product appears
 * immediately (unlike the order-driven analytics reports).
 *
 * @since 0.1.0
 */
final class ListStockAnalytics implements ConditionalAbility {

	/**
	 * Whitelist of returned row fields mapped to their cast type.
	 *
	 * Every field is present at the row top level (the stock report nests nothing in
	 * `extended_info`). `stock_quantity` is declared `int`: the controller casts it
	 * to a number even for unmanaged products, so the shaped value is always an
	 * integer (`0` when stock is not managed), never null.
	 *
	 * @var array<string,string>
	 */
	private const ROW_KEYS = array(
		'id'             => 'int',
		'parent_id'      => 'int',
		'name'           => 'string',
		'sku'            => 'string',
		'stock_status'   => 'string',
		'stock_quantity' => 'int',
		'manage_stock'   => 'bool',
	);

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-wc-reports/list-stock-analytics';
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
			'label'               => __( 'List Stock Analytics', 'abilities-catalog-woo' ),
			'description'         => __( 'Returns WooCommerce Analytics\' current inventory snapshot as flat rows, one per product or variation, each with id, parent_id, name, sku, stock_status, stock_quantity, and manage_stock. This is a present-state snapshot, not a time series, so it takes no after/before date window. Use this to list the per-product stock levels and statuses; use og-wc-reports/get-stock-stats for the store-wide instock/lowstock/outofstock counts. Filter the report type to all, lowstock, or a specific stock_status (e.g. outofstock). Only available when the store\'s WooCommerce Analytics feature is enabled. A product that does not manage stock reports stock_quantity 0 with manage_stock false.', 'abilities-catalog-woo' ),
			'category'            => 'og-wc-reports',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'type'     => array(
						'type'        => 'string',
						'enum'        => array( 'all', 'lowstock', 'instock', 'outofstock', 'onbackorder' ),
						'default'     => 'all',
						'description' => __( 'Limit the report to a stock report type: "all" (every product), "lowstock", or a specific stock status ("instock", "outofstock", "onbackorder"). Defaults to "all".', 'abilities-catalog-woo' ),
					),
					'per_page' => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'maximum'     => 100,
						'default'     => 100,
						'description' => __( 'Maximum number of inventory rows to return (1-100). Defaults to 100.', 'abilities-catalog-woo' ),
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
						'default'     => 'asc',
						'description' => __( 'Sort direction: "asc" (ascending) or "desc" (descending). Defaults to "asc".', 'abilities-catalog-woo' ),
					),
					'orderby'  => array(
						'type'        => 'string',
						'enum'        => array( 'stock_status', 'stock_quantity', 'sku', 'title', 'date', 'id' ),
						'default'     => 'stock_status',
						'description' => __( 'Field to sort by: "stock_status" (default), "stock_quantity", "sku", "title", "date", or "id".', 'abilities-catalog-woo' ),
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
						'description' => __( 'The current inventory rows. Use og-wc-reports/get-stock-stats for the store-wide stock-status counts.', 'abilities-catalog-woo' ),
						'items'       => AnalyticsReportShaper::analyticsItemSchema(
							array(
								'id'             => array(
									'type'        => 'integer',
									'description' => __( 'The product or variation ID.', 'abilities-catalog-woo' ),
								),
								'parent_id'      => array(
									'type'        => 'integer',
									'description' => __( 'The parent product ID for a variation, or 0 for a top-level product.', 'abilities-catalog-woo' ),
								),
								'name'           => array(
									'type'        => 'string',
									'description' => __( 'The product or variation name.', 'abilities-catalog-woo' ),
								),
								'sku'            => array(
									'type'        => 'string',
									'description' => __( 'The SKU, or an empty string when none is set.', 'abilities-catalog-woo' ),
								),
								'stock_status'   => array(
									'type'        => 'string',
									'description' => __( 'The stock status: "instock", "outofstock", or "onbackorder".', 'abilities-catalog-woo' ),
								),
								'stock_quantity' => array(
									'type'        => 'integer',
									'description' => __( 'The current stock quantity. Reports 0 for a product that does not manage stock (check manage_stock to tell a real zero from an unmanaged product).', 'abilities-catalog-woo' ),
								),
								'manage_stock'   => array(
									'type'        => 'boolean',
									'description' => __( 'Whether the product manages stock at the product level. When false, stock_quantity is not meaningful.', 'abilities-catalog-woo' ),
								),
							)
						),
					),
					'total' => array(
						'type'        => 'integer',
						'description' => __( 'Total number of inventory rows matching the query, from the X-WP-Total response header (falls back to the number of returned rows if the header is absent). May exceed the returned rows when paging.', 'abilities-catalog-woo' ),
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
	 * The wrapped `/wc-analytics/reports/stock` route resolves its permission to
	 * `view_woocommerce_reports` (`WC_REST_Reports_V1_Controller::get_items_permissions_check()`
	 * → `wc_rest_check_manager_permissions( 'reports', 'read' )`), so this mirrors that
	 * exact cap as the coarse, object-independent guard. The `hasAnalytics()` gate keeps
	 * the denial clean when the Analytics feature is off (the route is not registered),
	 * matching the same conditional gate batches 11 and 12 use.
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
	 * @return array<string,mixed>|\WP_Error The shaped inventory rows and total, or the REST error.
	 */
	public function execute( $input ) {
		if ( ! WooPlugin::isActive() ) {
			return WooPlugin::unavailable();
		}

		$input = is_array( $input ) ? $input : array();

		$request = new WP_REST_Request( 'GET', '/wc-analytics/reports/stock' );
		$request->set_param( 'type', (string) ( $input['type'] ?? 'all' ) );
		$request->set_param( 'per_page', max( 1, min( 100, absint( $input['per_page'] ?? 100 ) ) ) );
		$request->set_param( 'page', max( 1, absint( $input['page'] ?? 1 ) ) );
		$request->set_param( 'order', 'desc' === strtolower( (string) ( $input['order'] ?? 'asc' ) ) ? 'desc' : 'asc' );
		$request->set_param( 'orderby', (string) ( $input['orderby'] ?? 'stock_status' ) );

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
