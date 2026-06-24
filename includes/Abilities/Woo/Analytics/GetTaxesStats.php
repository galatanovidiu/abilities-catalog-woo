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
 * Read ability: `wc-reports/get-taxes-stats`.
 *
 * Wraps `GET /wc-analytics/reports/taxes/stats` via `rest_do_request()` and
 * returns the aggregated tax `totals` over a date range, plus `intervals_count`
 * (how many period buckets the report computed) and the `period` envelope echoing
 * the request back. The wrapped route's huge per-interval `intervals` array is
 * intentionally NOT returned — only its size is reported as `intervals_count`
 * ({@see AnalyticsReportShaper} owns that totals-subset rule).
 *
 * Only available when the store's WooCommerce **Analytics** feature is enabled (it
 * is a {@see ConditionalAbility} gated on {@see WooPlugin::hasAnalytics()}). The
 * `wc-analytics` namespace is feature-gated and lazy-loaded, so when Analytics is
 * off this ability does not register at all (it degrades cleanly, it does not deny).
 *
 * @since 0.1.0
 */
final class GetTaxesStats implements ConditionalAbility {

	/**
	 * Totals keys read as floats (monetary tax KPIs).
	 *
	 * @var array<int,string>
	 */
	private const FLOAT_KEYS = array(
		'total_tax',
		'order_tax',
		'shipping_tax',
	);

	/**
	 * Totals keys read as ints (count KPIs).
	 *
	 * @var array<int,string>
	 */
	private const INT_KEYS = array(
		'orders_count',
		'tax_codes',
	);

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'wc-reports/get-taxes-stats';
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
			'label'               => __( 'Get Taxes Stats', 'abilities-catalog-woo' ),
			'description'         => __( 'Returns aggregated WooCommerce Analytics tax KPIs over a date range: total_tax, order_tax, shipping_tax, orders_count, and tax_codes (the number of distinct tax codes that applied). Use this for store-wide tax totals; use wc-reports/list-taxes-analytics for per-tax-rate rows (which rate collected how much). The date range is set with after/before (ISO8601 date-time); interval buckets the breakdown that drives intervals_count. The full per-interval breakdown is intentionally omitted — only intervals_count (the number of buckets) is reported. Only available when the store\'s WooCommerce Analytics feature is enabled.', 'abilities-catalog-woo' ),
			'category'            => 'wc-reports',
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
					'per_page' => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'maximum'     => 100,
						'default'     => 10,
						'description' => __( 'Maximum number of interval buckets the underlying report computes (1-100). Does not change the returned totals; it only bounds intervals_count. Defaults to 10.', 'abilities-catalog-woo' ),
					),
					'page'     => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'Page of interval buckets to compute, for paging past the first per_page buckets. Does not change the returned totals.', 'abilities-catalog-woo' ),
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
	 * The wrapped `/wc-analytics/reports/taxes/stats` route resolves its permission
	 * through `wc_rest_check_manager_permissions( 'reports', 'read' )`, which maps to
	 * `view_woocommerce_reports`. This mirrors that exact cap as the coarse,
	 * object-independent hard guard. The Analytics activity check keeps the denial
	 * clean when the feature is off (the cap exists regardless, but the route does
	 * not), matching the conditional registration gate.
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

		$request = new WP_REST_Request( 'GET', '/wc-analytics/reports/taxes/stats' );
		if ( ! empty( $input['after'] ) ) {
			$request->set_param( 'after', (string) $input['after'] );
		}
		if ( ! empty( $input['before'] ) ) {
			$request->set_param( 'before', (string) $input['before'] );
		}
		$interval = (string) ( $input['interval'] ?? 'week' );
		$request->set_param( 'interval', $interval );
		$request->set_param( 'per_page', max( 1, min( 100, absint( $input['per_page'] ?? 10 ) ) ) );
		if ( isset( $input['page'] ) ) {
			$request->set_param( 'page', max( 1, absint( $input['page'] ) ) );
		}

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
	 * The closed `totals` object schema for the taxes-stats KPIs.
	 *
	 * Field order matches the projection in {@see execute()} (floats first, then
	 * ints). Every field is `readonly` in the wrapped controller, so all are required
	 * and always present after the cast (absent KPIs default to 0).
	 *
	 * @return array<string,mixed> A closed JSON-Schema object fragment.
	 */
	private function totalsSchema(): array {
		return array(
			'type'                 => 'object',
			'required'             => array(
				'total_tax',
				'order_tax',
				'shipping_tax',
				'orders_count',
				'tax_codes',
			),
			'properties'           => array(
				'total_tax'    => array(
					'type'        => 'number',
					'description' => __( 'Total tax collected in the range (order tax plus shipping tax), in the store currency.', 'abilities-catalog-woo' ),
				),
				'order_tax'    => array(
					'type'        => 'number',
					'description' => __( 'Tax charged on order line items in the range, in the store currency.', 'abilities-catalog-woo' ),
				),
				'shipping_tax' => array(
					'type'        => 'number',
					'description' => __( 'Tax charged on shipping in the range, in the store currency.', 'abilities-catalog-woo' ),
				),
				'orders_count' => array(
					'type'        => 'integer',
					'description' => __( 'Number of orders that carried tax in the range.', 'abilities-catalog-woo' ),
				),
				'tax_codes'    => array(
					'type'        => 'integer',
					'description' => __( 'Number of distinct tax codes that applied across the range.', 'abilities-catalog-woo' ),
				),
			),
			'additionalProperties' => false,
		);
	}
}
