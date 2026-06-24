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
 * Read ability: `wc-reports/get-customers-totals`.
 *
 * Wraps `GET /wc/v3/reports/customers/totals` via `rest_do_request()` and returns
 * the legacy customer split as two flat rows: `paying` (users with the
 * `paying_customer` meta) and `non_paying` (every other non-staff user).
 * Administrators and shop managers are excluded from both counts. Rows are shaped
 * through {@see ReportListShaper::totalsRow()} so `total` is a real integer.
 *
 * This is a legacy `wc/v3` report — a single headline pair, not a time series. For
 * customer trends, new-vs-returning segments, or per-period breakdowns, use the
 * richer `wc-analytics` surface (present only when the Analytics feature is on).
 *
 * Only available when WooCommerce is active (it is a {@see ConditionalAbility}).
 *
 * @since 0.1.0
 */
final class GetCustomersTotals implements ConditionalAbility {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'wc-reports/get-customers-totals';
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
			'label'               => __( 'Get Customers Totals', 'abilities-catalog-woo' ),
			'description'         => __( 'Returns the WooCommerce customer split as two rows: "paying" (users who have placed a paid order) and "non_paying" (everyone else), each with a count. Administrators and shop managers are excluded from both counts. This is the legacy headline pair, not a trend; use the wc-analytics customers surface for new-vs-returning segments and per-period breakdowns (present only when the Analytics feature is on).', 'abilities-catalog-woo' ),
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
						'description' => __( 'Exactly two rows: one with slug "paying" and one with slug "non_paying", each carrying its customer count in total.', 'abilities-catalog-woo' ),
						'items'       => ReportListShaper::totalsItemSchema(),
					),
					'total' => array(
						'type'        => 'integer',
						'description' => __( 'The number of rows returned (always 2: the paying and non_paying buckets). Not a customer count — sum the rows for that.', 'abilities-catalog-woo' ),
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
	 * Encodes the catalog baseline for every legacy report: `view_woocommerce_reports`,
	 * the capability the wrapped route enforces via
	 * `WC_REST_Reports_Controller::get_items_permissions_check()` →
	 * `wc_rest_check_manager_permissions( 'reports', 'read' )`. This is a coarse,
	 * object-independent guard; the report carries no per-object access, so there is
	 * no finer check to defer. The activity guard keeps the denial clean when
	 * WooCommerce is inactive (though a ConditionalAbility does not register then).
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
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The two customer rows, or the REST error.
	 */
	public function execute( $input ) {
		if ( ! WooPlugin::isActive() ) {
			return WooPlugin::unavailable();
		}

		$input = is_array( $input ) ? $input : array();

		$request  = new WP_REST_Request( 'GET', '/wc/v3/reports/customers/totals' );
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
