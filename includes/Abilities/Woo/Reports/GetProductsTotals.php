<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogWoo\Abilities\Woo\Reports;

use GalatanOvidiu\AbilitiesCatalogWoo\Contracts\ConditionalAbility;
use GalatanOvidiu\AbilitiesCatalogWoo\Support\ReportListShaper;
use GalatanOvidiu\AbilitiesCatalogWoo\Support\RestError;
use GalatanOvidiu\AbilitiesCatalogWoo\Support\WooPlugin;
use WP_REST_Request;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read ability: `og-wc-reports/get-products-totals`.
 *
 * Wraps `GET wc/v3/reports/products/totals` via `rest_do_request()` and returns
 * one row per product type — `slug` (the `product_type` term, e.g. "simple",
 * "variable", "grouped", "external"), its human `name`, and the `total` count of
 * products of that type. This is the legacy product-mix breakdown: it counts how
 * many products exist of each type, NOT sales or revenue.
 *
 * Each row is shaped through {@see ReportListShaper::totalsRow()} so the raw report
 * body never leaks. Only available when WooCommerce is active (it is a
 * {@see ConditionalAbility}). For revenue, order, or trend analysis use the richer
 * `wc-analytics` reports (present only when the Analytics feature is on); use
 * `og-wc-products/list-products` to enumerate the products themselves.
 *
 * @since 0.1.0
 */
final class GetProductsTotals implements ConditionalAbility {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-wc-reports/get-products-totals';
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
			'label'               => __( 'Get Products Totals', 'abilities-catalog-woo' ),
			'description'         => __( 'Returns the WooCommerce product-type breakdown as flat rows: each row has a product type slug (e.g. "simple", "variable", "grouped", "external"), its human-readable name, and the count of products of that type. This is the legacy product-mix report — it counts products by type, not sales or revenue. For sales or trend analysis use the wc-analytics reports (present only when the Analytics feature is on); use og-wc-products/list-products to enumerate the products themselves.', 'abilities-catalog-woo' ),
			'category'            => 'og-wc-reports',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => (object) array(),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'items', 'total' ),
				'properties'           => array(
					'items' => array(
						'type'        => 'array',
						'description' => __( 'One row per product type, each with its slug, name, and product count.', 'abilities-catalog-woo' ),
						'items'       => ReportListShaper::totalsItemSchema(),
					),
					'total' => array(
						'type'        => 'integer',
						'description' => __( 'The number of product-type rows returned (one per registered product type that has products). This route exposes no total header, so it counts the returned rows.', 'abilities-catalog-woo' ),
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
	 * Permission check: WooCommerce's reports-viewing capability.
	 *
	 * Mirrors the wrapped route's own guard. Every `wc/v3/reports/*` controller
	 * inherits `get_items_permissions_check()` from `WC_REST_Reports_V1_Controller`,
	 * which calls `wc_rest_check_manager_permissions( 'reports' )` — that maps to
	 * `view_woocommerce_reports`. This is the coarse, object-independent baseline;
	 * the report exposes no per-object decision. The explicit activity guard keeps
	 * the denial clean when WooCommerce is inactive.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may view WooCommerce reports.
	 */
	public function hasPermission( $input ): bool {
		return WooPlugin::isActive() && current_user_can( 'view_woocommerce_reports' );
	}

	/**
	 * Executes the ability by dispatching the internal WooCommerce REST request.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The product-type totals rows, or the REST error.
	 */
	public function execute( $input ) {
		if ( ! WooPlugin::isActive() ) {
			return WooPlugin::unavailable();
		}

		$input = is_array( $input ) ? $input : array();

		$request  = new WP_REST_Request( 'GET', '/wc/v3/reports/products/totals' );
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

			$rows[] = ReportListShaper::totalsRow( $item );
		}

		return array(
			'items' => $rows,
			'total' => count( $rows ),
		);
	}
}
