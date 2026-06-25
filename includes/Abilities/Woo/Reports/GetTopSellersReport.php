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
 * Read ability: `og-wc-reports/get-top-sellers-report`.
 *
 * Wraps `GET /wc/v3/reports/top_sellers` via `rest_do_request()` and returns the
 * best-selling products for a reporting period, each as a flat row of
 * `product_id`, `name`, and total `quantity` sold, ordered most-sold first.
 *
 * This is the legacy `wc/v3` top-sellers report — a quick "what sold the most by
 * unit count" answer over a fixed period or custom date range. For trend lines,
 * revenue-weighted leaderboards, or segmented product analytics use the richer
 * `wc-analytics` surface (present only when the Analytics feature is on).
 *
 * The top-sellers route reuses the sales report's collection params (it extends
 * the sales controller), so it accepts the same optional `period` / `date_min` /
 * `date_max` filters. Only products on COMPLETED-ish orders within the range
 * appear; products never sold do not. The route returns a bare JSON array with no
 * pagination headers, so `total` is the number of rows returned.
 *
 * Only available when WooCommerce is active (it is a {@see ConditionalAbility}).
 *
 * @since 0.1.0
 */
final class GetTopSellersReport implements ConditionalAbility {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-wc-reports/get-top-sellers-report';
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
			'label'               => __( 'Get Top Sellers Report', 'abilities-catalog-woo' ),
			'description'         => __( 'Returns WooCommerce\'s legacy top-sellers report: the best-selling products for a period, each as a flat row of product_id, name, and total quantity sold, ordered most-sold first. Answers "which products sold the most by unit count". With no input it reports the default period (this week); pass period or a date_min/date_max range to scope it. Products are counted only from orders within the range; products never sold do not appear. Use og-wc-reports/get-sales-report for time-series money totals (sales, tax, refunds) rather than per-product units, and the wc-analytics product leaderboards for revenue-weighted or segmented analysis.', 'abilities-catalog-woo' ),
			'category'            => 'og-wc-reports',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'period'   => array(
						'type'        => 'string',
						'enum'        => array( 'week', 'month', 'last_month', 'year' ),
						'description' => __( 'A preset reporting period: "week", "month", "last_month", or "year". Omit it to use the default (this week) or to use a custom date_min/date_max range instead.', 'abilities-catalog-woo' ),
					),
					'date_min' => array(
						'type'        => 'string',
						'format'      => 'date',
						'description' => __( 'Start date of a custom range as YYYY-MM-DD (e.g. 2026-01-01). Used only when period is omitted.', 'abilities-catalog-woo' ),
					),
					'date_max' => array(
						'type'        => 'string',
						'format'      => 'date',
						'description' => __( 'End date of a custom range as YYYY-MM-DD (e.g. 2026-01-31). Used only when period is omitted.', 'abilities-catalog-woo' ),
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
						'description' => __( 'The best-selling products as flat rows, ordered most-sold first. Empty when no products sold in the period.', 'abilities-catalog-woo' ),
						'items'       => array(
							'type'                 => 'object',
							'required'             => array( 'product_id', 'name', 'quantity' ),
							'properties'           => array(
								'product_id' => array(
									'type'        => 'integer',
									'description' => __( 'The product ID. Read the full product with og-wc-products/get-product.', 'abilities-catalog-woo' ),
								),
								'name'       => array(
									'type'        => 'string',
									'description' => __( 'The product name at the time of the report.', 'abilities-catalog-woo' ),
								),
								'quantity'   => array(
									'type'        => 'integer',
									'description' => __( 'Total number of units of this product purchased in the period.', 'abilities-catalog-woo' ),
								),
							),
							'additionalProperties' => false,
						),
					),
					'total' => array(
						'type'        => 'integer',
						'description' => __( 'The number of product rows returned. This route exposes no total header, so it counts the returned rows.', 'abilities-catalog-woo' ),
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
	 * Permission check: WooCommerce's reports capability.
	 *
	 * Coarse type-level gate mirroring the wrapped route, which inherits
	 * `get_items_permissions_check()` from `WC_REST_Reports_V1_Controller` →
	 * `wc_rest_check_manager_permissions( 'reports' )` → `view_woocommerce_reports`.
	 * The capability is unmapped meaningfully when WooCommerce is inactive, so the
	 * explicit activity guard keeps the denial clean.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may read WooCommerce reports.
	 */
	public function hasPermission( $input ): bool {
		return WooPlugin::isActive() && current_user_can( 'view_woocommerce_reports' );
	}

	/**
	 * Executes the ability by dispatching the internal `wc/v3` REST request.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The top-sellers rows, or the REST error.
	 */
	public function execute( $input ) {
		if ( ! WooPlugin::isActive() ) {
			return WooPlugin::unavailable();
		}

		$input = is_array( $input ) ? $input : array();

		$request = new WP_REST_Request( 'GET', '/wc/v3/reports/top_sellers' );
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

		$data = rest_get_server()->response_to_data( $response, false );

		$rows = array();
		foreach ( is_array( $data ) ? $data : array() as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$rows[] = array(
				'product_id' => (int) ( $item['product_id'] ?? 0 ),
				'name'       => (string) ( $item['name'] ?? '' ),
				'quantity'   => (int) ( $item['quantity'] ?? 0 ),
			);
		}

		return array(
			'items' => $rows,
			'total' => count( $rows ),
		);
	}
}
