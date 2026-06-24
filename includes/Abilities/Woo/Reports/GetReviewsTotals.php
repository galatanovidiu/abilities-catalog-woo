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
 * Read ability: `wc-reports/get-reviews-totals`.
 *
 * Wraps `GET /wc/v3/reports/reviews/totals` via `rest_do_request()` and returns
 * the product-review counts bucketed by star rating: exactly five rows
 * (`rated_1_out_of_5` … `rated_5_out_of_5`), each with its human label and the
 * number of reviews carrying that rating. Each row is shaped through
 * {@see ReportListShaper::totalsRow()} into a flat, closed `{ slug, name, total }`.
 *
 * This is one of WooCommerce's small, always-present legacy `wc/v3` reports. For
 * trend or segment analysis use the richer `wc-analytics` surface instead, which
 * is present only when the WooCommerce Analytics feature is enabled.
 *
 * Only available when WooCommerce is active (it is a {@see ConditionalAbility}).
 * The reports route returns a bare array with no pagination headers, so `total`
 * is the number of rows returned (always 5).
 *
 * @since 0.1.0
 */
final class GetReviewsTotals implements ConditionalAbility {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'wc-reports/get-reviews-totals';
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
			'label'               => __( 'Get Reviews Totals', 'abilities-catalog-woo' ),
			'description'         => __( 'Returns WooCommerce product-review counts bucketed by star rating: exactly five rows (rated 1 through 5 out of 5), each with its slug, label, and review count. Answers "how many product reviews have each star rating?". This is the legacy wc/v3 report; for review trends over time use the wc-analytics surface (present only when the Analytics feature is on). Read-only: returns counts only, not the review text or authors.', 'abilities-catalog-woo' ),
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
						'description' => __( 'The five review-rating buckets, one per star value, as flat rows.', 'abilities-catalog-woo' ),
						'items'       => ReportListShaper::totalsItemSchema(),
					),
					'total' => array(
						'type'        => 'integer',
						'description' => __( 'The number of rows returned. This report always returns five rating buckets; the route exposes no total header, so this counts the returned rows.', 'abilities-catalog-woo' ),
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
	 * Permission check: WooCommerce's report-viewing capability.
	 *
	 * `view_woocommerce_reports` is the capability every report controller
	 * enforces through `wc_rest_check_manager_permissions( 'reports', 'read' )`
	 * in `get_items_permissions_check()`, so this coarse, object-independent gate
	 * is never weaker than the wrapped route. The route surfaces any further
	 * error. The activity guard keeps the denial clean when WooCommerce is off.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may view WooCommerce reports.
	 */
	public function hasPermission( $input = null ): bool {
		return WooPlugin::isActive() && current_user_can( 'view_woocommerce_reports' );
	}

	/**
	 * Executes the ability by dispatching the internal WooCommerce REST request.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The shaped rating buckets, or the REST error.
	 */
	public function execute( $input = null ) {
		if ( ! WooPlugin::isActive() ) {
			return WooPlugin::unavailable();
		}

		$input = is_array( $input ) ? $input : array();

		$request  = new WP_REST_Request( 'GET', '/wc/v3/reports/reviews/totals' );
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
