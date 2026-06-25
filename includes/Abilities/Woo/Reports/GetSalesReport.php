<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogWoo\Abilities\Woo\Reports;

use GalatanOvidiu\AbilitiesCatalogWoo\Contracts\ConditionalAbility;
use GalatanOvidiu\AbilitiesCatalogWoo\Support\RestError;
use GalatanOvidiu\AbilitiesCatalogWoo\Support\WooPlugin;
use WP_REST_Request;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read ability: `og-wc-reports/get-sales-report`.
 *
 * Wraps `GET /wc/v3/reports/sales` via `rest_do_request()` and returns the legacy
 * sales report as a single shaped object: the period's top-level money totals
 * (gross/net/average sales, orders, items, tax, shipping, refunds, discounts,
 * customers) plus a `totals` map keyed by day or month, each bucket carrying its
 * own eight-field breakdown.
 *
 * This is the legacy `wc/v3` reports surface, kept light and always present. For
 * trend, segment, or leaderboard analysis use the richer `wc-analytics` abilities,
 * which exist only when the WooCommerce Analytics feature is enabled.
 *
 * The wrapped route returns a SINGLE object (not a paginated collection), so this
 * ability returns one object, not `{ items, total }`. Only available when
 * WooCommerce is active (it is a {@see ConditionalAbility}).
 *
 * @since 0.1.0
 */
final class GetSalesReport implements ConditionalAbility {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-wc-reports/get-sales-report';
	}

	/**
	 * {@inheritDoc}
	 */
	public function isAvailable(): bool {
		return WooPlugin::isActive();
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return array(
			'label'               => __( 'Get Sales Report', 'abilities-catalog-woo' ),
			'description'         => __( 'Returns WooCommerce\'s legacy sales report for a period as a single object: top-level totals (total_sales, net_sales, average_sales, total_orders, total_items, total_tax, total_shipping, total_refunds, total_discount, total_customers) plus a totals map broken down by day or month (each bucket carries sales, orders, items, tax, shipping, discount, refunds, and new customers). Use this for the time-series money totals of a store; use og-wc-reports/get-orders-totals for a per-status order count instead. This is the legacy reports surface — for trend or segment analysis use the wc-analytics abilities, which exist only when the WooCommerce Analytics feature is enabled.', 'abilities-catalog-woo' ),
			'category'            => 'og-wc-reports',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'period'   => array(
						'type'        => 'string',
						'enum'        => array( 'week', 'month', 'last_month', 'year' ),
						'description' => __( 'A named reporting period: "week" (last 7 days), "month" (this month), "last_month", or "year". Omit it to report a custom range built from date_min/date_max instead.', 'abilities-catalog-woo' ),
					),
					'date_min' => array(
						'type'        => 'string',
						'format'      => 'date',
						'description' => __( 'Custom-range start date as YYYY-MM-DD (e.g. 2024-01-31). Used only when period is omitted; ignored otherwise.', 'abilities-catalog-woo' ),
					),
					'date_max' => array(
						'type'        => 'string',
						'format'      => 'date',
						'description' => __( 'Custom-range end date as YYYY-MM-DD (e.g. 2024-02-29). Used only when period is omitted; ignored otherwise.', 'abilities-catalog-woo' ),
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'total_sales', 'totals_grouped_by', 'totals' ),
				'properties'           => array(
					'total_sales'       => array(
						'type'        => 'string',
						'description' => __( 'Gross sales in the period, as a decimal string.', 'abilities-catalog-woo' ),
					),
					'net_sales'         => array(
						'type'        => 'string',
						'description' => __( 'Net sales in the period (gross minus refunds, tax, and shipping), as a decimal string.', 'abilities-catalog-woo' ),
					),
					'average_sales'     => array(
						'type'        => 'string',
						'description' => __( 'Average net daily sales over the period, as a decimal string.', 'abilities-catalog-woo' ),
					),
					'total_orders'      => array(
						'type'        => 'integer',
						'description' => __( 'Number of orders placed in the period.', 'abilities-catalog-woo' ),
					),
					'total_items'       => array(
						'type'        => 'integer',
						'description' => __( 'Number of line items purchased in the period.', 'abilities-catalog-woo' ),
					),
					'total_tax'         => array(
						'type'        => 'string',
						'description' => __( 'Total tax charged in the period (including shipping tax), as a decimal string.', 'abilities-catalog-woo' ),
					),
					'total_shipping'    => array(
						'type'        => 'string',
						'description' => __( 'Total charged for shipping in the period, as a decimal string.', 'abilities-catalog-woo' ),
					),
					'total_refunds'     => array(
						'type'        => 'string',
						'description' => __( 'Total of refunds issued in the period. WooCommerce types this field as an integer in the source schema, but the dispatched value is a decimal string in practice, so it is returned as a string.', 'abilities-catalog-woo' ),
					),
					'total_discount'    => array(
						'type'        => 'string',
						'description' => __( 'Total of coupon discounts applied in the period. WooCommerce types this field as an integer in the source schema, but the dispatched value is a decimal string in practice, so it is returned as a string.', 'abilities-catalog-woo' ),
					),
					'total_customers'   => array(
						'type'        => 'integer',
						'description' => __( 'Number of new customers (accounts registered) in the period.', 'abilities-catalog-woo' ),
					),
					'totals_grouped_by' => array(
						'type'        => 'string',
						'enum'        => array( 'day', 'month' ),
						'description' => __( 'How the totals map is bucketed: "day" (keys are YYYY-MM-DD) or "month" (keys are YYYY-MM).', 'abilities-catalog-woo' ),
					),
					'totals'            => array(
						'type'                 => 'object',
						'description'          => __( 'Per-period breakdown keyed by date (YYYY-MM-DD or YYYY-MM, matching totals_grouped_by). Each value is one period bucket.', 'abilities-catalog-woo' ),
						'additionalProperties' => self::periodBucketSchema(),
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
	 * Permission check: WooCommerce's reports-read capability.
	 *
	 * Mirrors the wrapped route, which inherits `get_items_permissions_check()`
	 * from `WC_REST_Reports_V1_Controller` and calls
	 * `wc_rest_check_manager_permissions( 'reports' )`, resolving to
	 * `view_woocommerce_reports`. This coarse, object-independent gate is the hard
	 * server-side guard; the report has no per-object dimension to defer. The
	 * activity guard keeps the denial clean when WooCommerce is inactive.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may read WooCommerce reports.
	 */
	public function hasPermission( $input ): bool {
		return WooPlugin::isActive() && current_user_can( 'view_woocommerce_reports' );
	}

	/**
	 * Executes the ability by dispatching the internal WooCommerce REST request.
	 *
	 * The v1 sales controller's `get_items()` wraps the single report object in a
	 * one-element collection, so the dispatched data is `[ 0 => report ]`; this
	 * unwraps index 0 before shaping.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The shaped sales report, or the REST error.
	 */
	public function execute( $input ) {
		if ( ! WooPlugin::isActive() ) {
			return WooPlugin::unavailable();
		}

		$input = is_array( $input ) ? $input : array();

		$request = new WP_REST_Request( 'GET', '/wc/v3/reports/sales' );
		if ( ! empty( $input['period'] ) ) {
			$request->set_param( 'period', (string) $input['period'] );
		}
		if ( ! empty( $input['date_min'] ) ) {
			$request->set_param( 'date_min', (string) $input['date_min'] );
		}
		if ( ! empty( $input['date_max'] ) ) {
			$request->set_param( 'date_max', (string) $input['date_max'] );
		}

		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			return RestError::from( $response );
		}

		$data   = rest_get_server()->response_to_data( $response, false );
		$rows   = is_array( $data ) ? array_values( $data ) : array();
		$report = isset( $rows[0] ) && is_array( $rows[0] ) ? $rows[0] : array();

		$totals      = array();
		$source_rows = isset( $report['totals'] ) && is_array( $report['totals'] ) ? $report['totals'] : array();
		foreach ( $source_rows as $key => $bucket ) {
			if ( ! is_array( $bucket ) ) {
				continue;
			}

			$totals[ (string) $key ] = $this->shapeBucket( $bucket );
		}

		return array(
			'total_sales'       => (string) ( $report['total_sales'] ?? '0' ),
			'net_sales'         => (string) ( $report['net_sales'] ?? '0' ),
			'average_sales'     => (string) ( $report['average_sales'] ?? '0' ),
			'total_orders'      => (int) ( $report['total_orders'] ?? 0 ),
			'total_items'       => (int) ( $report['total_items'] ?? 0 ),
			'total_tax'         => (string) ( $report['total_tax'] ?? '0' ),
			'total_shipping'    => (string) ( $report['total_shipping'] ?? '0' ),
			'total_refunds'     => (string) ( $report['total_refunds'] ?? '0' ),
			'total_discount'    => (string) ( $report['total_discount'] ?? '0' ),
			'total_customers'   => (int) ( $report['total_customers'] ?? 0 ),
			'totals_grouped_by' => (string) ( $report['totals_grouped_by'] ?? 'day' ),
			'totals'            => (object) $totals,
		);
	}

	/**
	 * Projects one raw per-period bucket into the closed eight-field shape.
	 *
	 * @param array<string,mixed> $bucket A single `totals[date]` bucket from the response.
	 * @return array<string,mixed> The flat closed bucket.
	 */
	private function shapeBucket( array $bucket ): array {
		return array(
			'sales'     => (string) ( $bucket['sales'] ?? '0' ),
			'orders'    => (int) ( $bucket['orders'] ?? 0 ),
			'items'     => (int) ( $bucket['items'] ?? 0 ),
			'tax'       => (string) ( $bucket['tax'] ?? '0' ),
			'shipping'  => (string) ( $bucket['shipping'] ?? '0' ),
			'discount'  => (string) ( $bucket['discount'] ?? '0' ),
			'refunds'   => (string) ( $bucket['refunds'] ?? '0' ),
			'customers' => (int) ( $bucket['customers'] ?? 0 ),
		);
	}

	/**
	 * The closed per-period bucket schema used for `totals`' `additionalProperties`.
	 *
	 * Mirrors the v3 sales controller's object-of-objects shape: eight fields, the
	 * three count fields integers and the five money fields decimal strings.
	 *
	 * @return array<string,mixed> A JSON-Schema object fragment.
	 */
	private static function periodBucketSchema(): array {
		return array(
			'type'                 => 'object',
			'required'             => array( 'sales', 'orders', 'items', 'tax', 'shipping', 'discount', 'refunds', 'customers' ),
			'properties'           => array(
				'sales'     => array(
					'type'        => 'string',
					'description' => __( 'Gross sales in this period bucket, as a decimal string.', 'abilities-catalog-woo' ),
				),
				'orders'    => array(
					'type'        => 'integer',
					'description' => __( 'Number of orders in this period bucket.', 'abilities-catalog-woo' ),
				),
				'items'     => array(
					'type'        => 'integer',
					'description' => __( 'Number of line items sold in this period bucket.', 'abilities-catalog-woo' ),
				),
				'tax'       => array(
					'type'        => 'string',
					'description' => __( 'Tax charged in this period bucket, as a decimal string.', 'abilities-catalog-woo' ),
				),
				'shipping'  => array(
					'type'        => 'string',
					'description' => __( 'Shipping charged in this period bucket, as a decimal string.', 'abilities-catalog-woo' ),
				),
				'discount'  => array(
					'type'        => 'string',
					'description' => __( 'Coupon discounts applied in this period bucket, as a decimal string.', 'abilities-catalog-woo' ),
				),
				'refunds'   => array(
					'type'        => 'string',
					'description' => __( 'Refunds issued in this period bucket, as a decimal string.', 'abilities-catalog-woo' ),
				),
				'customers' => array(
					'type'        => 'integer',
					'description' => __( 'New customers registered in this period bucket.', 'abilities-catalog-woo' ),
				),
			),
			'additionalProperties' => false,
		);
	}
}
