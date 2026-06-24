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
 * Read ability: `wc-reports/get-coupons-totals`.
 *
 * Wraps `GET /wc/v3/reports/coupons/totals` via `rest_do_request()` and returns
 * one row per coupon type (`percent`, `fixed_cart`, `fixed_product`, plus any
 * type a plugin registers) with the number of coupons of that type. Each row is
 * shaped through {@see ReportListShaper::totalsRow()} into the shared closed
 * `{ slug, name, total }` totals shape.
 *
 * The wrapped route caches its result in the `rest_api_coupons_type_count`
 * transient for one year, so a count can lag a recent coupon create/delete until
 * the transient is refreshed — the description states this.
 *
 * Only available when WooCommerce is active (it is a {@see ConditionalAbility}).
 * The totals route returns a bare collection with no pagination headers, so
 * `total` is the number of rows returned (the number of coupon types).
 *
 * @since 0.1.0
 */
final class GetCouponsTotals implements ConditionalAbility {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'wc-reports/get-coupons-totals';
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
			'label'               => __( 'Get Coupons Totals', 'abilities-catalog-woo' ),
			'description'         => __( 'Returns the WooCommerce coupon count broken down by coupon type, as flat rows of { slug, name, total } — e.g. how many "percent", "fixed_cart", and "fixed_product" coupons exist. This is the legacy reports summary; the count is cached for up to one year, so it may lag a recently created or deleted coupon. For richer, time-filtered coupon analytics use the wc-analytics surface where available.', 'abilities-catalog-woo' ),
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
						'description' => __( 'One row per coupon type, each with the type slug, its human-readable name, and the count of coupons of that type.', 'abilities-catalog-woo' ),
						'items'       => ReportListShaper::totalsItemSchema(),
					),
					'total' => array(
						'type'        => 'integer',
						'description' => __( 'The number of coupon-type rows returned. The totals route exposes no total header, so this counts the returned rows (one per coupon type).', 'abilities-catalog-woo' ),
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
	 * Permission check: WooCommerce's reports-view capability.
	 *
	 * Mirrors the wrapped route, which inherits `get_items_permissions_check()`
	 * from `WC_REST_Reports_V1_Controller` — it calls
	 * `wc_rest_check_manager_permissions( 'reports' )`, mapping to
	 * `view_woocommerce_reports`. That is the minimum a successful caller must
	 * hold and is never weaker than the route's own check. The activity guard
	 * keeps the denial clean when WooCommerce is inactive.
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
	 * @return array<string,mixed>|\WP_Error The coupon-type totals rows, or the REST error.
	 */
	public function execute( $input ) {
		if ( ! WooPlugin::isActive() ) {
			return WooPlugin::unavailable();
		}

		$input = is_array( $input ) ? $input : array();

		$request  = new WP_REST_Request( 'GET', '/wc/v3/reports/coupons/totals' );
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
