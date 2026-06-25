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
 * Read ability: `og-wc-reports/list-taxes-analytics`.
 *
 * Wraps `GET /wc-analytics/reports/taxes` via `rest_do_request()` and returns each
 * tax rate's collected-tax performance over a date range as a flat summary row:
 * `tax_rate_id`, `name`, `tax_rate`, `country`, `state`, `total_tax`, `order_tax`,
 * `shipping_tax`, and `orders_count`. The taxes list is flat (no `extended_info`),
 * so every field is read top-level. The controller's `priority` field is dropped —
 * only the whitelisted fields above are returned.
 *
 * This is the per-tax-rate list read; use `og-wc-reports/get-taxes-stats` for the
 * aggregated tax totals over a range. Set the range with `after`/`before`
 * (ISO8601 date-time).
 *
 * Only available when the store's WooCommerce **Analytics** feature is enabled (it
 * is a {@see ConditionalAbility}); when Analytics is off the ability does not
 * register at all. The analytics taxes report reads the `wc_order_tax_lookup`
 * table, which is populated by an async data sync, so a freshly-placed order may
 * not appear until that sync runs.
 *
 * @since 0.1.0
 */
final class ListTaxesAnalytics implements ConditionalAbility {

	/**
	 * Whitelist of returned row fields mapped to their cast type.
	 *
	 * The taxes list row is flat — there is no `extended_info` — so every field is
	 * read straight off the row top level by {@see AnalyticsReportShaper::analyticsRow()}.
	 * Money/rate fields (`tax_rate`, `total_tax`, `order_tax`, `shipping_tax`) are
	 * cast to floats; the count/identity fields stay ints.
	 *
	 * @var array<string,string>
	 */
	private const ROW_KEYS = array(
		'tax_rate_id'  => 'int',
		'name'         => 'string',
		'tax_rate'     => 'float',
		'country'      => 'string',
		'state'        => 'string',
		'total_tax'    => 'float',
		'order_tax'    => 'float',
		'shipping_tax' => 'float',
		'orders_count' => 'int',
	);

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-wc-reports/list-taxes-analytics';
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
			'label'               => __( 'List Taxes Analytics', 'abilities-catalog-woo' ),
			'description'         => __( 'Returns per-tax-rate analytics from WooCommerce Analytics as flat rows, each with tax_rate_id, name, tax_rate, country, state, total_tax, order_tax, shipping_tax, and orders_count. Use this for a ranked tax-rate list over a date range; use og-wc-reports/get-taxes-stats for the aggregated tax totals. The range is set by after/before as ISO8601 date-times; omit them for the full history. Only available when the store\'s WooCommerce Analytics feature is enabled. The report reads an analytics lookup table that an async sync populates, so a just-placed order may not appear immediately.', 'abilities-catalog-woo' ),
			'category'            => 'og-wc-reports',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'after'    => array(
						'type'        => 'string',
						'format'      => 'date-time',
						'description' => __( 'Limit to tax collected on or after this ISO8601 date-time (e.g. 2024-01-01T00:00:00). Omit for no lower bound.', 'abilities-catalog-woo' ),
					),
					'before'   => array(
						'type'        => 'string',
						'format'      => 'date-time',
						'description' => __( 'Limit to tax collected on or before this ISO8601 date-time (e.g. 2024-01-31T23:59:59). Omit for no upper bound.', 'abilities-catalog-woo' ),
					),
					'per_page' => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'maximum'     => 100,
						'default'     => 10,
						'description' => __( 'Maximum number of tax-rate rows to return (1-100). Defaults to 10.', 'abilities-catalog-woo' ),
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
						'enum'        => array( 'name', 'tax_rate_id', 'tax_code', 'rate', 'order_tax', 'total_tax', 'shipping_tax', 'orders_count' ),
						'default'     => 'tax_rate_id',
						'description' => __( 'Field to sort by: "name", "tax_rate_id", "tax_code", "rate", "order_tax", "total_tax", "shipping_tax", or "orders_count".', 'abilities-catalog-woo' ),
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
						'description' => __( 'The per-tax-rate analytics rows. Use og-wc-reports/get-taxes-stats for the aggregated totals over the range.', 'abilities-catalog-woo' ),
						'items'       => AnalyticsReportShaper::analyticsItemSchema(
							array(
								'tax_rate_id'  => array(
									'type'        => 'integer',
									'description' => __( 'The tax rate ID.', 'abilities-catalog-woo' ),
								),
								'name'         => array(
									'type'        => 'string',
									'description' => __( 'The tax rate name, or an empty string when none is set.', 'abilities-catalog-woo' ),
								),
								'tax_rate'     => array(
									'type'        => 'number',
									'description' => __( 'The tax rate percentage applied (e.g. 8.25).', 'abilities-catalog-woo' ),
								),
								'country'      => array(
									'type'        => 'string',
									'description' => __( 'The country/region code for the tax rate, or an empty string when none is set.', 'abilities-catalog-woo' ),
								),
								'state'        => array(
									'type'        => 'string',
									'description' => __( 'The state code for the tax rate, or an empty string when none is set.', 'abilities-catalog-woo' ),
								),
								'total_tax'    => array(
									'type'        => 'number',
									'description' => __( 'Total tax collected for this rate in the range (order tax plus shipping tax), in the store currency.', 'abilities-catalog-woo' ),
								),
								'order_tax'    => array(
									'type'        => 'number',
									'description' => __( 'Tax collected on order line items for this rate in the range, in the store currency.', 'abilities-catalog-woo' ),
								),
								'shipping_tax' => array(
									'type'        => 'number',
									'description' => __( 'Tax collected on shipping for this rate in the range, in the store currency.', 'abilities-catalog-woo' ),
								),
								'orders_count' => array(
									'type'        => 'integer',
									'description' => __( 'Number of distinct orders that applied this tax rate.', 'abilities-catalog-woo' ),
								),
							)
						),
					),
					'total' => array(
						'type'        => 'integer',
						'description' => __( 'Total number of tax rates matching the query, from the X-WP-Total response header (falls back to the number of returned rows if the header is absent). May exceed the returned rows when paging.', 'abilities-catalog-woo' ),
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
	 * The wrapped `/wc-analytics/reports/taxes` route resolves its permission to
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
	 * @return array<string,mixed>|\WP_Error The shaped tax-rate rows and total, or the REST error.
	 */
	public function execute( $input ) {
		if ( ! WooPlugin::isActive() ) {
			return WooPlugin::unavailable();
		}

		$input = is_array( $input ) ? $input : array();

		$request = new WP_REST_Request( 'GET', '/wc-analytics/reports/taxes' );
		$request->set_param( 'per_page', max( 1, min( 100, absint( $input['per_page'] ?? 10 ) ) ) );
		$request->set_param( 'page', max( 1, absint( $input['page'] ?? 1 ) ) );
		$request->set_param( 'order', 'asc' === strtolower( (string) ( $input['order'] ?? 'desc' ) ) ? 'asc' : 'desc' );
		$request->set_param( 'orderby', (string) ( $input['orderby'] ?? 'tax_rate_id' ) );
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
