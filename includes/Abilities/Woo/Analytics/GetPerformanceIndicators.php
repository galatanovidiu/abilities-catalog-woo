<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogWoo\Abilities\Woo\Analytics;

use GalatanOvidiu\AbilitiesCatalogWoo\Contracts\ConditionalAbility;
use GalatanOvidiu\AbilitiesCatalogWoo\Support\RestError;
use GalatanOvidiu\AbilitiesCatalogWoo\Support\WooPlugin;
use WP_REST_Request;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read ability: `og-wc-reports/get-performance-indicators`.
 *
 * Wraps `GET /wc-analytics/reports/performance-indicators` via `rest_do_request()`
 * and returns the store's top-level KPI snapshot for a date range: one flat row per
 * indicator with its `stat` key, human `label`, numeric `value` (or `null` when the
 * indicator has no data for the range), and `format` (`number` or `currency`). This
 * is the single-call "store at a glance" read — total sales, net revenue, orders,
 * average order value, items sold, refunds, and so on — as opposed to the per-report
 * stats abilities (`og-wc-reports/get-revenue-stats`, `og-wc-reports/get-orders-stats`,
 * `og-wc-reports/get-products-stats`), which each return one report's full KPI totals
 * plus the interval count.
 *
 * Only available when the store's WooCommerce **Analytics** feature is enabled (it is
 * a {@see ConditionalAbility} gated on {@see WooPlugin::hasAnalytics()}); when
 * Analytics is off it does not register, rather than denying. The UI-only `chart`
 * field the controller carries is dropped — it is not useful to an agent.
 *
 * @since 0.1.0
 */
final class GetPerformanceIndicators implements ConditionalAbility {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-wc-reports/get-performance-indicators';
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
			'label'               => __( 'Get Performance Indicators', 'abilities-catalog-woo' ),
			'description'         => __( 'Returns WooCommerce Analytics performance indicators for a date range as flat rows, each with its stat key, human label, numeric value (or null when the indicator has no data for the range), and format ("number" or "currency"). This is the single-call store-at-a-glance KPI snapshot (total sales, net revenue, orders, average order value, items sold, refunds, and more); use it instead of the per-report stats abilities (og-wc-reports/get-revenue-stats, og-wc-reports/get-orders-stats) when you want a cross-report KPI summary rather than one report\'s full totals. Set after/before (ISO8601 date-time) to bound the range; an empty or data-less range yields indicators with a null value. Omit stats to return every available indicator (recommended); to limit, pass an array of stat keys (e.g. "revenue/total_sales"). Only available when the store\'s WooCommerce Analytics feature is enabled.', 'abilities-catalog-woo' ),
			'category'            => 'og-wc-reports',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'after'  => array(
						'type'        => 'string',
						'format'      => 'date-time',
						'description' => __( 'Limit indicators to data on or after this ISO8601 date-time (start of the range), e.g. 2024-01-01T00:00:00.', 'abilities-catalog-woo' ),
					),
					'before' => array(
						'type'        => 'string',
						'format'      => 'date-time',
						'description' => __( 'Limit indicators to data on or before this ISO8601 date-time (end of the range), e.g. 2024-01-31T23:59:59.', 'abilities-catalog-woo' ),
					),
					'stats'  => array(
						'type'        => 'array',
						'items'       => array(
							'type' => 'string',
						),
						'description' => __( 'Optional list of indicator stat keys to limit the response to, e.g. ["revenue/total_sales", "orders/orders_count"]. Omit it (do NOT pass an empty array) to return every available indicator — an empty array is rejected by WooCommerce with a 400 woocommerce_analytics_performance_indicators_empty_query error.', 'abilities-catalog-woo' ),
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'indicators', 'total' ),
				'properties'           => array(
					'indicators' => array(
						'type'        => 'array',
						'description' => __( 'The performance indicators as flat rows. A value of null means the indicator has no data for the range.', 'abilities-catalog-woo' ),
						'items'       => array(
							'type'                 => 'object',
							'required'             => array( 'stat', 'label', 'value', 'format' ),
							'properties'           => array(
								'stat'   => array(
									'type'        => 'string',
									'description' => __( 'The indicator stat key, e.g. "revenue/total_sales" or "orders/orders_count".', 'abilities-catalog-woo' ),
								),
								'label'  => array(
									'type'        => 'string',
									'description' => __( 'The human-readable label for the indicator, e.g. "Total sales".', 'abilities-catalog-woo' ),
								),
								'value'  => array(
									'type'        => array( 'number', 'null' ),
									'description' => __( 'The indicator value for the range, or null when the indicator has no data for the range (keep null; it is not the same as 0).', 'abilities-catalog-woo' ),
								),
								'format' => array(
									'type'        => 'string',
									'enum'        => array( 'number', 'currency' ),
									'description' => __( 'How to render the value: "number" (a plain count or quantity) or "currency" (a monetary amount in the store currency).', 'abilities-catalog-woo' ),
								),
							),
							'additionalProperties' => false,
						),
					),
					'total'      => array(
						'type'        => 'integer',
						'description' => __( 'The number of indicators returned.', 'abilities-catalog-woo' ),
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
	 * Permission check: the WooCommerce reports-view capability.
	 *
	 * Mirrors the wrapped route's effective check
	 * (`wc_rest_check_manager_permissions( 'reports', 'read' )` →
	 * `view_woocommerce_reports`). The gate also confirms WooCommerce is active and
	 * the Analytics feature is on, so a call cannot reach the lazy-loaded route when
	 * the surface is absent. This is the coarse, object-independent hard guard; the
	 * report itself carries no per-object access decision.
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
	 * When the caller omits `stats`, this resolves the full allowed indicator set from
	 * the sibling `/allowed` route and forwards it, so "no stats" means "all
	 * indicators". The route's own `stats` default is frozen empty at registration and
	 * is not applied on an internal dispatch, so omitting the param entirely would 400
	 * with `woocommerce_analytics_performance_indicators_empty_query`. The UI-only
	 * `chart` field is dropped; `value` is kept as-is (it may be `null`).
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The indicators and their count, or the REST error.
	 */
	public function execute( $input ) {
		if ( ! WooPlugin::isActive() ) {
			return WooPlugin::unavailable();
		}

		$input = is_array( $input ) ? $input : array();

		$request = new WP_REST_Request( 'GET', '/wc-analytics/reports/performance-indicators' );

		if ( ! empty( $input['after'] ) ) {
			$request->set_param( 'after', (string) $input['after'] );
		}
		if ( ! empty( $input['before'] ) ) {
			$request->set_param( 'before', (string) $input['before'] );
		}
		$stats = array();
		if ( ! empty( $input['stats'] ) && is_array( $input['stats'] ) ) {
			foreach ( $input['stats'] as $stat ) {
				$stats[] = (string) $stat;
			}
		} else {
			$stats = $this->allowedStats();
		}
		if ( ! empty( $stats ) ) {
			$request->set_param( 'stats', $stats );
		}

		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			return RestError::from( $response );
		}

		$data = rest_get_server()->response_to_data( $response, false );

		$indicators = array();
		foreach ( is_array( $data ) ? $data : array() as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$value = $item['value'] ?? null;

			$indicators[] = array(
				'stat'   => (string) ( $item['stat'] ?? '' ),
				'label'  => (string) ( $item['label'] ?? '' ),
				'value'  => null === $value ? null : (float) $value,
				'format' => (string) ( $item['format'] ?? 'number' ),
			);
		}

		return array(
			'indicators' => $indicators,
			'total'      => count( $indicators ),
		);
	}

	/**
	 * Resolves the full set of allowed performance-indicator stat keys.
	 *
	 * Dispatches the sibling `/wc-analytics/reports/performance-indicators/allowed`
	 * route, whose rows are `{ stat, chart, label }`, and collects each `stat`. Used to
	 * honor this ability's "omit stats to get all indicators" contract, since the main
	 * route's `stats` default is not applied on an internal dispatch. Returns an empty
	 * list when the allowed route errors or yields nothing (the caller then sends no
	 * `stats`, surfacing the route's own error rather than guessing).
	 *
	 * @return list<string> The allowed stat keys.
	 */
	private function allowedStats(): array {
		$request  = new WP_REST_Request( 'GET', '/wc-analytics/reports/performance-indicators/allowed' );
		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			return array();
		}

		$data  = rest_get_server()->response_to_data( $response, false );
		$stats = array();
		foreach ( is_array( $data ) ? $data : array() as $item ) {
			if ( ! is_array( $item ) || empty( $item['stat'] ) ) {
				continue;
			}

			$stats[] = (string) $item['stat'];
		}

		return $stats;
	}
}
