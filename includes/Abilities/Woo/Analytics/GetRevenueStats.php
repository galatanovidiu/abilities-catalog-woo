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
 * Read ability: `og-wc-reports/get-revenue-stats`.
 *
 * Wraps `GET /wc-analytics/reports/revenue/stats` via `rest_do_request()` and
 * returns the aggregated revenue KPI `totals` over a date range, plus
 * `intervals_count` (how many period buckets the report computed) and the `period`
 * envelope echoing the request back. The wrapped route's huge per-interval
 * `intervals` array is intentionally NOT returned — only its size is reported as
 * `intervals_count` ({@see AnalyticsReportShaper} owns that totals-subset rule).
 *
 * Only available when the store's WooCommerce **Analytics** feature is enabled (it
 * is a {@see ConditionalAbility} gated on {@see WooPlugin::hasAnalytics()}). The
 * `wc-analytics` namespace is feature-gated and lazy-loaded, so when Analytics is
 * off this ability does not register at all (it degrades cleanly, it does not deny).
 *
 * @since 0.1.0
 */
final class GetRevenueStats implements ConditionalAbility {

	/**
	 * Totals keys read as floats (monetary KPIs).
	 *
	 * @var array<int,string>
	 */
	private const FLOAT_KEYS = array(
		'total_sales',
		'net_revenue',
		'gross_sales',
		'coupons',
		'shipping',
		'taxes',
		'refunds',
	);

	/**
	 * Totals keys read as ints (count KPIs).
	 *
	 * @var array<int,string>
	 */
	private const INT_KEYS = array(
		'coupons_count',
		'orders_count',
		'num_items_sold',
	);

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-wc-reports/get-revenue-stats';
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
			'label'               => __( 'Get Revenue Stats', 'abilities-catalog-woo' ),
			'description'         => __( 'Returns aggregated WooCommerce Analytics revenue KPIs over a date range: total_sales, net_revenue, gross_sales, coupons, coupons_count, shipping, taxes, refunds, orders_count, and num_items_sold. Use this for store-wide revenue totals; use og-wc-reports/get-orders-stats for order-centric KPIs (average order value, customer counts) or og-wc-reports/list-orders-analytics for per-order rows. The date range is set with after/before (ISO8601 date-time); interval buckets the breakdown that drives intervals_count. The full per-interval breakdown is intentionally omitted — only intervals_count (the number of buckets) is reported. Only available when the store\'s WooCommerce Analytics feature is enabled.', 'abilities-catalog-woo' ),
			'category'            => 'og-wc-reports',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'after'    => array(
						'type'        => 'string',
						'format'      => 'date-time',
						'description' => __( 'Start of the date range, as an ISO8601 date-time string (e.g. 2024-01-01T00:00:00). Omit to use the store default range.', 'abilities-catalog-woo' ),
					),
					'before'   => array(
						'type'        => 'string',
						'format'      => 'date-time',
						'description' => __( 'End of the date range, as an ISO8601 date-time string (e.g. 2024-01-31T23:59:59). Omit to use the store default range.', 'abilities-catalog-woo' ),
					),
					'interval' => array(
						'type'        => 'string',
						'enum'        => array( 'hour', 'day', 'week', 'month', 'quarter', 'year' ),
						'default'     => 'week',
						'description' => __( 'Bucket size for the per-period breakdown that drives intervals_count: "hour", "day", "week", "month", "quarter", or "year". Defaults to "week".', 'abilities-catalog-woo' ),
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => AnalyticsReportShaper::statsEnvelopeSchema( $this->totalsSchema() ),
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
	 * Permission check: WooCommerce's reports-read capability.
	 *
	 * The wrapped `/wc-analytics/reports/revenue/stats` route resolves its
	 * permission through `wc_rest_check_manager_permissions( 'reports', 'read' )`,
	 * which maps to `view_woocommerce_reports`. This mirrors that exact cap as the
	 * coarse, object-independent hard guard. The Analytics activity check keeps the
	 * denial clean when the feature is off (the cap exists regardless, but the route
	 * does not), matching the conditional registration gate.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may read WooCommerce reports.
	 */
	public function hasPermission( $input ): bool {
		return WooPlugin::isActive() && WooPlugin::hasAnalytics() && current_user_can( 'view_woocommerce_reports' );
	}

	/**
	 * Executes the ability by dispatching the internal `wc-analytics` REST request.
	 *
	 * Dispatching the route triggers the lazy registration of the `wc-analytics`
	 * namespace, so the route is present by the time this runs when Analytics is on.
	 * If Analytics is off the route is absent and `rest_do_request()` returns a
	 * `rest_no_route` error surfaced via {@see RestError::from()}.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The shaped totals envelope, or the REST error.
	 */
	public function execute( $input ) {
		if ( ! WooPlugin::isActive() ) {
			return WooPlugin::unavailable();
		}

		$input = is_array( $input ) ? $input : array();

		$request = new WP_REST_Request( 'GET', '/wc-analytics/reports/revenue/stats' );
		if ( ! empty( $input['after'] ) ) {
			$request->set_param( 'after', (string) $input['after'] );
		}
		if ( ! empty( $input['before'] ) ) {
			$request->set_param( 'before', (string) $input['before'] );
		}
		$interval = (string) ( $input['interval'] ?? 'week' );
		$request->set_param( 'interval', $interval );

		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			return RestError::from( $response );
		}

		$data = rest_get_server()->response_to_data( $response, false );
		$data = is_array( $data ) ? $data : array();

		$shaped = AnalyticsReportShaper::statsTotals( $data, self::FLOAT_KEYS, self::INT_KEYS );

		return array(
			'totals'          => $shaped['totals'],
			'intervals_count' => $shaped['intervals_count'],
			'period'          => array(
				'after'    => (string) ( $input['after'] ?? '' ),
				'before'   => (string) ( $input['before'] ?? '' ),
				'interval' => $interval,
			),
		);
	}

	/**
	 * The closed `totals` object schema for the revenue-stats KPIs.
	 *
	 * Field order matches the projection in {@see execute()} (floats first, then
	 * ints). Every field is `readonly` in the wrapped controller, so all are
	 * required and always present after the cast (absent KPIs default to 0).
	 *
	 * @return array<string,mixed> A closed JSON-Schema object fragment.
	 */
	private function totalsSchema(): array {
		return array(
			'type'                 => 'object',
			'required'             => array(
				'total_sales',
				'net_revenue',
				'gross_sales',
				'coupons',
				'shipping',
				'taxes',
				'refunds',
				'coupons_count',
				'orders_count',
				'num_items_sold',
			),
			'properties'           => array(
				'total_sales'    => array(
					'type'        => 'number',
					'description' => __( 'Total sales (gross sales minus refunds), in the store currency.', 'abilities-catalog-woo' ),
				),
				'net_revenue'    => array(
					'type'        => 'number',
					'description' => __( 'Net sales (total sales minus tax and shipping), in the store currency.', 'abilities-catalog-woo' ),
				),
				'gross_sales'    => array(
					'type'        => 'number',
					'description' => __( 'Gross sales (before refunds, tax, and shipping deductions), in the store currency.', 'abilities-catalog-woo' ),
				),
				'coupons'        => array(
					'type'        => 'number',
					'description' => __( 'Total amount discounted by coupons, in the store currency.', 'abilities-catalog-woo' ),
				),
				'shipping'       => array(
					'type'        => 'number',
					'description' => __( 'Total shipping charged, in the store currency.', 'abilities-catalog-woo' ),
				),
				'taxes'          => array(
					'type'        => 'number',
					'description' => __( 'Total taxes charged, in the store currency.', 'abilities-catalog-woo' ),
				),
				'refunds'        => array(
					'type'        => 'number',
					'description' => __( 'Total amount refunded, in the store currency.', 'abilities-catalog-woo' ),
				),
				'coupons_count'  => array(
					'type'        => 'integer',
					'description' => __( 'Number of distinct coupons used.', 'abilities-catalog-woo' ),
				),
				'orders_count'   => array(
					'type'        => 'integer',
					'description' => __( 'Number of orders in the range.', 'abilities-catalog-woo' ),
				),
				'num_items_sold' => array(
					'type'        => 'integer',
					'description' => __( 'Number of line items sold across all orders in the range.', 'abilities-catalog-woo' ),
				),
			),
			'additionalProperties' => false,
		);
	}
}
