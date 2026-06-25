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
 * Read ability: `og-wc-reports/get-products-stats`.
 *
 * Wraps `GET /wc-analytics/reports/products/stats` via `rest_do_request()` and
 * returns the aggregated product-sales KPIs over a date range: a `totals` object
 * (`items_sold`, `net_revenue`, `orders_count`), the number of period buckets the
 * report computed (`intervals_count`), and the `period` envelope echoing the
 * request's `after`/`before`/`interval` back. The full per-interval breakdown — a
 * potentially huge `intervals` array, one object per bucket — is intentionally
 * omitted; only its size is reported as `intervals_count`. Use this for an
 * aggregated products-sold/net-revenue total; use `og-wc-reports/list-products-analytics`
 * for per-product rows.
 *
 * Only available when the store's WooCommerce **Analytics** feature is enabled (it
 * is a {@see ConditionalAbility}); the `wc-analytics` namespace is feature-gated and
 * lazy-loaded, so when Analytics is off this ability degrades cleanly (absent) and
 * does not deny.
 *
 * @since 0.1.0
 */
final class GetProductsStats implements ConditionalAbility {

	/**
	 * Totals keys copied as floats (monetary KPIs).
	 *
	 * @var list<string>
	 */
	private const FLOAT_KEYS = array( 'net_revenue' );

	/**
	 * Totals keys copied as ints (count KPIs).
	 *
	 * @var list<string>
	 */
	private const INT_KEYS = array( 'items_sold', 'orders_count' );

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-wc-reports/get-products-stats';
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
			'label'               => __( 'Get Products Stats', 'abilities-catalog-woo' ),
			'description'         => __( 'Returns aggregated WooCommerce Analytics product-sales totals over a date range: items_sold, net_revenue, and orders_count, plus intervals_count (the number of period buckets) and the period the report covered. The full per-interval breakdown is intentionally omitted — only intervals_count is reported. Use this for an aggregated products total; use og-wc-reports/list-products-analytics for per-product rows. The date range is set with after/before (ISO8601 date-time) and interval buckets the breakdown that drives intervals_count. Only available when the store\'s WooCommerce Analytics feature is enabled.', 'abilities-catalog-woo' ),
			'category'            => 'og-wc-reports',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'after'    => array(
						'type'        => 'string',
						'format'      => 'date-time',
						'description' => __( 'Start of the date range as an ISO8601 date-time string (e.g. 2024-01-01T00:00:00). Omit to use the report default range.', 'abilities-catalog-woo' ),
					),
					'before'   => array(
						'type'        => 'string',
						'format'      => 'date-time',
						'description' => __( 'End of the date range as an ISO8601 date-time string (e.g. 2024-01-31T23:59:59). Omit to use the report default range.', 'abilities-catalog-woo' ),
					),
					'interval' => array(
						'type'        => 'string',
						'enum'        => array( 'hour', 'day', 'week', 'month', 'quarter', 'year' ),
						'default'     => 'week',
						'description' => __( 'The bucket size for the per-period breakdown that drives intervals_count: "hour", "day", "week", "month", "quarter", or "year". Defaults to "week". Does not change the totals, only how many interval buckets the range is split into.', 'abilities-catalog-woo' ),
					),
					'products' => array(
						'type'        => 'array',
						'items'       => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
						'description' => __( 'Limit the totals to the given product IDs. Omit to aggregate across all products. Discover product IDs with og-wc-products/list-products.', 'abilities-catalog-woo' ),
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
	 * Permission check: WooCommerce's reports-view capability.
	 *
	 * The wrapped `/wc-analytics/reports/products/stats` route's permission check
	 * resolves through `WC_REST_Reports_V1_Controller::get_items_permissions_check()`
	 * → `wc_rest_check_manager_permissions( 'reports', 'read' )` →
	 * `view_woocommerce_reports`, so that is the coarse, object-independent baseline
	 * here. The activity + analytics guards keep the denial clean when WooCommerce or
	 * its Analytics feature is off (the cap is unmapped / the route absent). This is
	 * the hard server-side guard; the wrapped route surfaces any further error.
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
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The shaped product-stats totals, or the REST error.
	 */
	public function execute( $input ) {
		if ( ! WooPlugin::isActive() ) {
			return WooPlugin::unavailable();
		}

		$input = is_array( $input ) ? $input : array();

		$request = new WP_REST_Request( 'GET', '/wc-analytics/reports/products/stats' );

		$interval = (string) ( $input['interval'] ?? 'week' );
		$request->set_param( 'interval', $interval );

		if ( ! empty( $input['after'] ) ) {
			$request->set_param( 'after', (string) $input['after'] );
		}
		if ( ! empty( $input['before'] ) ) {
			$request->set_param( 'before', (string) $input['before'] );
		}
		if ( ! empty( $input['products'] ) && is_array( $input['products'] ) ) {
			$request->set_param( 'products', array_map( 'absint', $input['products'] ) );
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
	 * The closed totals schema for this report's three product-sales KPIs.
	 *
	 * @return array<string,mixed> A closed JSON-Schema object fragment.
	 */
	private function totalsSchema(): array {
		return array(
			'type'                 => 'object',
			'required'             => array( 'items_sold', 'net_revenue', 'orders_count' ),
			'properties'           => array(
				'items_sold'   => array(
					'type'        => 'integer',
					'description' => __( 'Total number of product items sold in the date range.', 'abilities-catalog-woo' ),
				),
				'net_revenue'  => array(
					'type'        => 'number',
					'description' => __( 'Net sales (net revenue) from products in the date range, in the store currency.', 'abilities-catalog-woo' ),
				),
				'orders_count' => array(
					'type'        => 'integer',
					'description' => __( 'Number of orders that contributed to these product totals in the date range.', 'abilities-catalog-woo' ),
				),
			),
			'additionalProperties' => false,
		);
	}
}
