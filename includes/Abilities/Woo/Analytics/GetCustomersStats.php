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
 * Read ability: `wc-reports/get-customers-stats`.
 *
 * Wraps `GET /wc-analytics/reports/customers/stats` via `rest_do_request()` and
 * returns the store-wide customer aggregate: `customers_count`,
 * `avg_orders_count`, `avg_total_spend`, and `avg_avg_order_value`.
 *
 * THE NO-INTERVALS EXCEPTION: unlike every other `wc-analytics` `/stats` report,
 * the customers/stats controller does NOT extend `GenericStatsController` — it
 * returns a single `{ totals: {...} }` aggregate with NO per-period `intervals`
 * array (`Customers/Stats/Controller.php::get_items()`). So this ability returns
 * `{ totals }` ONLY — there is no `intervals_count` and no `period` envelope, and
 * it does NOT use {@see \GalatanOvidiu\AbilitiesCatalogWoo\Support\AnalyticsReportShaper::statsEnvelopeSchema()}.
 * The controller also defines no `interval`/`page`/`per_page`/`order`/`orderby`
 * params; its filters are customer identity/date filters, so this ability exposes
 * the verified date-range filters (`registered_after`/`registered_before`,
 * `last_order_after`/`last_order_before`) only.
 *
 * Only available when the store's WooCommerce **Analytics** feature is enabled (it
 * is a {@see ConditionalAbility} gated on {@see WooPlugin::hasAnalytics()}); when
 * Analytics is off the ability does not register and degrades cleanly (absent),
 * rather than denying.
 *
 * @since 0.1.0
 */
final class GetCustomersStats implements ConditionalAbility {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'wc-reports/get-customers-stats';
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
			'label'               => __( 'Get Customers Stats', 'abilities-catalog-woo' ),
			'description'         => __( 'Returns the store-wide WooCommerce Analytics customer aggregate: customers_count (number of customers), avg_orders_count (average orders per customer), avg_total_spend (average lifetime spend per customer), and avg_avg_order_value (average AOV per customer). This is a SINGLE aggregate with no time buckets — unlike the other analytics /stats reports it returns no intervals_count and no period envelope. Use this for headline customer KPIs; use wc-reports/list-customers-analytics for the per-customer rows. Optionally narrow the set with registered_after/registered_before (signup date) and last_order_after/last_order_before (last-order date), all ISO8601 date-times; omit them for all customers. Only available when the store\'s WooCommerce Analytics feature is enabled.', 'abilities-catalog-woo' ),
			'category'            => 'wc-reports',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'registered_after'  => array(
						'type'        => 'string',
						'format'      => 'date-time',
						'description' => __( 'Limit the aggregate to customers registered on or after this ISO8601 date-time (e.g. 2024-01-01T00:00:00). Omit for no lower bound on signup date.', 'abilities-catalog-woo' ),
					),
					'registered_before' => array(
						'type'        => 'string',
						'format'      => 'date-time',
						'description' => __( 'Limit the aggregate to customers registered on or before this ISO8601 date-time (e.g. 2024-12-31T23:59:59). Omit for no upper bound on signup date.', 'abilities-catalog-woo' ),
					),
					'last_order_after'  => array(
						'type'        => 'string',
						'format'      => 'date-time',
						'description' => __( 'Limit the aggregate to customers whose last order was on or after this ISO8601 date-time. Omit for no lower bound on last-order date.', 'abilities-catalog-woo' ),
					),
					'last_order_before' => array(
						'type'        => 'string',
						'format'      => 'date-time',
						'description' => __( 'Limit the aggregate to customers whose last order was on or before this ISO8601 date-time. Omit for no upper bound on last-order date.', 'abilities-catalog-woo' ),
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'totals' ),
				'properties'           => array(
					'totals' => array(
						'type'                 => 'object',
						'required'             => array(
							'customers_count',
							'avg_orders_count',
							'avg_total_spend',
							'avg_avg_order_value',
						),
						'properties'           => array(
							'customers_count'     => array(
								'type'        => 'integer',
								'description' => __( 'Number of customers in the (optionally filtered) set.', 'abilities-catalog-woo' ),
							),
							'avg_orders_count'    => array(
								'type'        => 'number',
								'description' => __( 'Average number of orders per customer.', 'abilities-catalog-woo' ),
							),
							'avg_total_spend'     => array(
								'type'        => 'number',
								'description' => __( 'Average total (lifetime) spend per customer, in the store currency.', 'abilities-catalog-woo' ),
							),
							'avg_avg_order_value' => array(
								'type'        => 'number',
								'description' => __( 'Average order value (AOV) per customer, in the store currency.', 'abilities-catalog-woo' ),
							),
						),
						'additionalProperties' => false,
						'description'          => __( 'The store-wide customer aggregate. A single set of totals with no per-period breakdown.', 'abilities-catalog-woo' ),
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
	 * Permission check: WooCommerce's reports-view capability, gated on Analytics.
	 *
	 * Mirrors the wrapped `wc-analytics` route's effective permission, which chains
	 * to `wc_rest_check_manager_permissions( 'reports', 'read' )` →
	 * `view_woocommerce_reports`. The `hasAnalytics()` guard keeps the denial clean
	 * when the Analytics feature is off (the route would otherwise be absent). This
	 * is a coarse, object-independent guard; the wrapped route surfaces any
	 * per-request error via {@see RestError::from()}.
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
	 * Dispatching the `/wc-analytics/reports/customers/stats` route triggers the lazy
	 * registration of the `wc-analytics` namespace, so the route is present by the
	 * time this runs. The controller returns `{ totals: {...} }` with no `intervals`,
	 * so this projects the four whitelisted KPI fields into a flat, closed `totals`
	 * object (counts cast to int, money/average fields cast to float) and returns
	 * `{ totals }` only — no `intervals_count`, no `period`.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The shaped customer aggregate, or the REST error.
	 */
	public function execute( $input ) {
		if ( ! WooPlugin::isActive() ) {
			return WooPlugin::unavailable();
		}

		$input = is_array( $input ) ? $input : array();

		$request = new WP_REST_Request( 'GET', '/wc-analytics/reports/customers/stats' );
		foreach ( array( 'registered_after', 'registered_before', 'last_order_after', 'last_order_before' ) as $param ) {
			if ( empty( $input[ $param ] ) ) {
				continue;
			}

			$request->set_param( $param, (string) $input[ $param ] );
		}

		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			return RestError::from( $response );
		}

		$data       = rest_get_server()->response_to_data( $response, false );
		$data       = is_array( $data ) ? $data : array();
		$raw_totals = (array) ( $data['totals'] ?? array() );

		return array(
			'totals' => array(
				'customers_count'     => (int) ( $raw_totals['customers_count'] ?? 0 ),
				'avg_orders_count'    => (float) ( $raw_totals['avg_orders_count'] ?? 0 ),
				'avg_total_spend'     => (float) ( $raw_totals['avg_total_spend'] ?? 0 ),
				'avg_avg_order_value' => (float) ( $raw_totals['avg_avg_order_value'] ?? 0 ),
			),
		);
	}
}
