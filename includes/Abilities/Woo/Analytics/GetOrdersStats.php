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
 * Read ability: `og-wc-reports/get-orders-stats`.
 *
 * Wraps `GET /wc-analytics/reports/orders/stats` via `rest_do_request()` and
 * returns the aggregated order KPIs for a date range: net revenue, order count,
 * average order value, average items per order, items sold, coupon discount and
 * coupon count, and distinct customers — plus `intervals_count` (the number of
 * per-period buckets the report computed) and the `period` envelope echoing the
 * request's `after`/`before`/`interval`.
 *
 * THE TOTALS-SUBSET RULE: the underlying stats response carries a huge
 * `intervals` array (one object per bucket in the range). This ability NEVER
 * returns it — {@see AnalyticsReportShaper::statsTotals()} extracts only the
 * whitelisted KPI fields (cast to numbers) and reports `intervals_count` instead.
 *
 * Only available when the store's WooCommerce **Analytics** feature is enabled
 * (it is a {@see ConditionalAbility} gated on {@see WooPlugin::hasAnalytics()});
 * when Analytics is off the ability does not register and degrades cleanly
 * (absent), rather than denying.
 *
 * @since 0.1.0
 */
final class GetOrdersStats implements ConditionalAbility {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-wc-reports/get-orders-stats';
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
			'label'               => __( 'Get Orders Stats', 'abilities-catalog-woo' ),
			'description'         => __( 'Returns aggregated WooCommerce order KPIs over a date range: net_revenue, orders_count, avg_order_value, avg_items_per_order, num_items_sold, coupons, coupons_count, and total_customers, plus intervals_count (the number of per-period buckets) and the period the report covered. Use this for order totals over a range; use og-wc-reports/list-orders-analytics for per-order rows, or og-wc-reports/get-revenue-stats for the broader revenue KPIs (gross/total sales, shipping, taxes, refunds). The date range is after/before (ISO8601 date-time) and interval buckets the breakdown that drives intervals_count; the full per-interval breakdown is intentionally omitted. Only available when the store\'s WooCommerce Analytics feature is enabled.', 'abilities-catalog-woo' ),
			'category'            => 'og-wc-reports',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'after'    => array(
						'type'        => 'string',
						'format'      => 'date-time',
						'description' => __( 'Start of the date range, as an ISO8601 date-time string (e.g. 2024-01-01T00:00:00). Omit for WooCommerce\'s default range.', 'abilities-catalog-woo' ),
					),
					'before'   => array(
						'type'        => 'string',
						'format'      => 'date-time',
						'description' => __( 'End of the date range, as an ISO8601 date-time string (e.g. 2024-01-31T23:59:59). Omit for WooCommerce\'s default range.', 'abilities-catalog-woo' ),
					),
					'interval' => array(
						'type'        => 'string',
						'enum'        => array( 'hour', 'day', 'week', 'month', 'quarter', 'year' ),
						'default'     => 'week',
						'description' => __( 'The bucket size for the per-period breakdown that drives intervals_count: "hour", "day", "week", "month", "quarter", or "year". Defaults to "week". Does not change the totals, only how many buckets the range is split into.', 'abilities-catalog-woo' ),
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => AnalyticsReportShaper::statsEnvelopeSchema( self::totalsSchema() ),
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
	 * Permission check: WooCommerce's reports-view capability, gated on Analytics.
	 *
	 * Mirrors the wrapped `wc-analytics` route's effective permission, which
	 * chains to `wc_rest_check_manager_permissions( 'reports', 'read' )` →
	 * `view_woocommerce_reports`. The `hasAnalytics()` guard keeps the denial
	 * clean when the Analytics feature is off (the route would otherwise be
	 * absent). This is a coarse, object-independent guard; the wrapped route
	 * surfaces any per-request error via {@see RestError::from()}.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may read WooCommerce analytics reports.
	 */
	public function hasPermission( $input ): bool {
		return WooPlugin::isActive()
			&& WooPlugin::hasAnalytics()
			&& current_user_can( 'view_woocommerce_reports' );
	}

	/**
	 * Executes the ability by dispatching the internal `wc-analytics` REST request.
	 *
	 * Dispatching the `/wc-analytics/reports/orders/stats` route triggers the lazy
	 * registration of the `wc-analytics` namespace, so the route is present by the
	 * time this runs. The shaped totals subset (never the raw `intervals` array)
	 * plus `intervals_count` and the echoed `period` envelope are returned.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The shaped stats envelope, or the REST error.
	 */
	public function execute( $input ) {
		if ( ! WooPlugin::isActive() ) {
			return WooPlugin::unavailable();
		}

		$input = is_array( $input ) ? $input : array();

		$interval = isset( $input['interval'] ) ? (string) $input['interval'] : 'week';

		$request = new WP_REST_Request( 'GET', '/wc-analytics/reports/orders/stats' );
		if ( ! empty( $input['after'] ) ) {
			$request->set_param( 'after', (string) $input['after'] );
		}
		if ( ! empty( $input['before'] ) ) {
			$request->set_param( 'before', (string) $input['before'] );
		}
		$request->set_param( 'interval', $interval );

		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			return RestError::from( $response );
		}

		$data = rest_get_server()->response_to_data( $response, false );
		$data = is_array( $data ) ? $data : array();

		$shaped = AnalyticsReportShaper::statsTotals(
			$data,
			array( 'net_revenue', 'avg_order_value', 'avg_items_per_order', 'coupons' ),
			array( 'orders_count', 'num_items_sold', 'coupons_count', 'total_customers' )
		);

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
	 * The closed `totals` object schema for the order-stats KPIs.
	 *
	 * Order-specific KPI fields (the `products` distinct-count from the controller
	 * is intentionally omitted, keeping this aligned with the revenue-stats shape
	 * and focused on order KPIs). Monetary/average fields are numbers; count fields
	 * are integers. The shaper's cast and this schema's key set are kept in sync by
	 * the matching whitelists in {@see self::execute()}.
	 *
	 * @return array<string,mixed> A closed JSON-Schema object fragment for `totals`.
	 */
	private static function totalsSchema(): array {
		return array(
			'type'                 => 'object',
			'required'             => array(
				'net_revenue',
				'orders_count',
				'avg_order_value',
				'avg_items_per_order',
				'num_items_sold',
				'coupons',
				'coupons_count',
				'total_customers',
			),
			'properties'           => array(
				'net_revenue'         => array(
					'type'        => 'number',
					'description' => __( 'Net sales over the range (gross sales minus refunds, taxes, and shipping), in the store currency.', 'abilities-catalog-woo' ),
				),
				'orders_count'        => array(
					'type'        => 'integer',
					'description' => __( 'Number of orders in the range.', 'abilities-catalog-woo' ),
				),
				'avg_order_value'     => array(
					'type'        => 'number',
					'description' => __( 'Average order value over the range, in the store currency.', 'abilities-catalog-woo' ),
				),
				'avg_items_per_order' => array(
					'type'        => 'number',
					'description' => __( 'Average number of items per order over the range.', 'abilities-catalog-woo' ),
				),
				'num_items_sold'      => array(
					'type'        => 'integer',
					'description' => __( 'Total number of items sold across all orders in the range.', 'abilities-catalog-woo' ),
				),
				'coupons'             => array(
					'type'        => 'number',
					'description' => __( 'Total amount discounted by coupons over the range, in the store currency.', 'abilities-catalog-woo' ),
				),
				'coupons_count'       => array(
					'type'        => 'integer',
					'description' => __( 'Number of distinct coupons used in the range.', 'abilities-catalog-woo' ),
				),
				'total_customers'     => array(
					'type'        => 'integer',
					'description' => __( 'Number of distinct customers who placed orders in the range.', 'abilities-catalog-woo' ),
				),
			),
			'additionalProperties' => false,
		);
	}
}
