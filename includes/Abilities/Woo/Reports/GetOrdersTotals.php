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
 * Read ability: `wc-reports/get-orders-totals`.
 *
 * Wraps `GET wc/v3/reports/orders/totals` via `rest_do_request()` and returns one
 * row per order status with its order count — the data behind the "Orders by
 * status" breakdown. Each row is shaped through {@see ReportListShaper::totalsRow()}
 * to `{ slug, name, total }`: `slug` is the status without the `wc-` prefix (e.g.
 * `completed`, `processing`, `on-hold`), `name` the human label, and `total` the
 * number of orders in that status.
 *
 * This is one of WooCommerce's small, always-present legacy `wc/v3` reports. For
 * the time-series money totals (sales, net sales, refunds over a period) use
 * `wc-reports/get-sales-report` instead; for deeper trend/segment analysis the
 * richer `wc-analytics` surface is available only when WooCommerce Analytics is
 * active.
 *
 * Only available when WooCommerce is active (it is a {@see ConditionalAbility}).
 * The route returns a bare array with no pagination header, so `total` is the
 * number of status rows returned (only statuses with at least one order appear).
 *
 * @since 0.1.0
 */
final class GetOrdersTotals implements ConditionalAbility {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'wc-reports/get-orders-totals';
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
			'label'               => __( 'Get Orders Totals', 'abilities-catalog-woo' ),
			'description'         => __( 'Returns the order count for each order status as flat { slug, name, total } rows — the data behind WooCommerce\'s "Orders by status" breakdown (e.g. how many orders are completed, processing, or on-hold). Read-only legacy report; only statuses with at least one order appear. Use wc-reports/get-sales-report for time-series money totals over a date range, and the wc-analytics surface (when active) for deeper trend analysis.', 'abilities-catalog-woo' ),
			'category'            => 'wc-reports',
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
						'description' => __( 'One row per order status, each with the status slug, its human-readable name, and the count of orders in that status.', 'abilities-catalog-woo' ),
						'items'       => ReportListShaper::totalsItemSchema(),
					),
					'total' => array(
						'type'        => 'integer',
						'description' => __( 'The number of status rows returned. This route exposes no total header, so it counts the returned rows (statuses with at least one order), not the sum of order counts.', 'abilities-catalog-woo' ),
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
	 * Permission check: WooCommerce's reports-viewer capability.
	 *
	 * The wrapped `/wc/v3/reports/orders/totals` route inherits
	 * `get_items_permissions_check()` from `WC_REST_Reports_V1_Controller`, which
	 * calls `wc_rest_check_manager_permissions( 'reports' )` — resolving to
	 * `view_woocommerce_reports`. This ability mirrors that exact cap — never wider —
	 * and the activity guard keeps the denial clean when WooCommerce is inactive and
	 * the capability is unmapped. This is a coarse type-level guard; the wrapped
	 * route surfaces any specific error.
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
	 * @return array<string,mixed>|\WP_Error The per-status order counts and total, or the REST error.
	 */
	public function execute( $input ) {
		if ( ! WooPlugin::isActive() ) {
			return WooPlugin::unavailable();
		}

		$input = is_array( $input ) ? $input : array();

		$request  = new WP_REST_Request( 'GET', '/wc/v3/reports/orders/totals' );
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

			$rows[] = ReportListShaper::totalsRow( $row );
		}

		return array(
			'items' => $rows,
			'total' => count( $rows ),
		);
	}
}
