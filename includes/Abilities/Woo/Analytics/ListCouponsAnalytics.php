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
 * Read ability: `og-wc-reports/list-coupons-analytics`.
 *
 * Wraps `GET /wc-analytics/reports/coupons` via `rest_do_request()` and returns
 * each coupon's discount performance over a date range as a flat summary row:
 * `coupon_id`, `amount` (total discount given), `orders_count`, plus the coupon
 * `code` and `discount_type` lifted out of the controller's `extended_info`. The
 * rest of `extended_info` (creation/expiry dates) is never returned — only the two
 * identity fields are flattened out.
 *
 * This is the per-coupon list read; use `og-wc-reports/get-coupons-stats` for the
 * aggregated totals (discount amount, coupon count, order count) over a range.
 * Set the range with `after`/`before` (ISO8601 date-time).
 *
 * Only available when the store's WooCommerce **Analytics** feature is enabled (it
 * is a {@see ConditionalAbility}); when Analytics is off the ability does not
 * register at all. The analytics coupons report reads the `wc_order_coupon_lookup`
 * table, which is populated by an async data sync, so a freshly-placed order may
 * not appear until that sync runs.
 *
 * @since 0.1.0
 */
final class ListCouponsAnalytics implements ConditionalAbility {

	/**
	 * Whitelist of returned row fields mapped to their cast type.
	 *
	 * `code` and `discount_type` are absent at the row top level —
	 * {@see AnalyticsReportShaper::analyticsRow()} falls back to
	 * `$row['extended_info'][$field]` for them, which is why the dispatched request
	 * sets `extended_info` to true.
	 *
	 * @var array<string,string>
	 */
	private const ROW_KEYS = array(
		'coupon_id'     => 'int',
		'amount'        => 'float',
		'orders_count'  => 'int',
		'code'          => 'string',
		'discount_type' => 'string',
	);

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-wc-reports/list-coupons-analytics';
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
			'label'               => __( 'List Coupons Analytics', 'abilities-catalog-woo' ),
			'description'         => __( 'Returns per-coupon discount performance from WooCommerce Analytics as flat rows, each with coupon_id, amount (total discount given), orders_count, and the coupon code and discount_type. The rest of the report\'s extended_info (creation and expiry dates) is intentionally dropped. Use this for a ranked coupon list over a date range; use og-wc-reports/get-coupons-stats for the aggregated totals. The range is set by after/before as ISO8601 date-times; omit them for WooCommerce\'s default range. Only available when the store\'s WooCommerce Analytics feature is enabled; for the always-present legacy totals use the wc-reports coupon totals reads. The report reads an analytics lookup table that an async sync populates, so a just-placed order may not appear immediately.', 'abilities-catalog-woo' ),
			'category'            => 'og-wc-reports',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'after'    => array(
						'type'        => 'string',
						'format'      => 'date-time',
						'description' => __( 'Limit to coupons used on or after this ISO8601 date-time (e.g. 2024-01-01T00:00:00). Omit for no lower bound.', 'abilities-catalog-woo' ),
					),
					'before'   => array(
						'type'        => 'string',
						'format'      => 'date-time',
						'description' => __( 'Limit to coupons used on or before this ISO8601 date-time (e.g. 2024-01-31T23:59:59). Omit for no upper bound.', 'abilities-catalog-woo' ),
					),
					'per_page' => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'maximum'     => 100,
						'default'     => 10,
						'description' => __( 'Maximum number of coupon rows to return (1-100). Defaults to 10.', 'abilities-catalog-woo' ),
					),
					'page'     => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'default'     => 1,
						'description' => __( 'Page of results to return, for paging past the first per_page rows.', 'abilities-catalog-woo' ),
					),
					'order'    => array(
						'type'        => 'string',
						'enum'        => array( 'asc', 'desc' ),
						'default'     => 'desc',
						'description' => __( 'Sort direction: "asc" (ascending) or "desc" (descending).', 'abilities-catalog-woo' ),
					),
					'orderby'  => array(
						'type'        => 'string',
						'enum'        => array( 'coupon_id', 'code', 'amount', 'orders_count' ),
						'default'     => 'coupon_id',
						'description' => __( 'Field to sort by: "coupon_id", "code", "amount" (discount given), or "orders_count".', 'abilities-catalog-woo' ),
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
						'description' => __( 'The per-coupon analytics rows. Use og-wc-reports/get-coupons-stats for the aggregated totals over the range.', 'abilities-catalog-woo' ),
						'items'       => AnalyticsReportShaper::analyticsItemSchema(
							array(
								'coupon_id'     => array(
									'type'        => 'integer',
									'description' => __( 'The coupon ID.', 'abilities-catalog-woo' ),
								),
								'amount'        => array(
									'type'        => 'number',
									'description' => __( 'Total discount given by this coupon in the range, in the store currency.', 'abilities-catalog-woo' ),
								),
								'orders_count'  => array(
									'type'        => 'integer',
									'description' => __( 'Number of orders this coupon was used on.', 'abilities-catalog-woo' ),
								),
								'code'          => array(
									'type'        => 'string',
									'description' => __( 'The coupon code, lifted from the report\'s extended_info, or an empty string when unavailable.', 'abilities-catalog-woo' ),
								),
								'discount_type' => array(
									'type'        => 'string',
									'description' => __( 'The coupon discount type (e.g. "fixed_cart", "percent"), lifted from the report\'s extended_info, or an empty string when unavailable.', 'abilities-catalog-woo' ),
								),
							)
						),
					),
					'total' => array(
						'type'        => 'integer',
						'description' => __( 'Total number of coupons matching the query, from the X-WP-Total response header (falls back to the number of returned rows if the header is absent). May exceed the returned rows when paging.', 'abilities-catalog-woo' ),
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
	 * Permission check: WooCommerce reports view capability, gated on Analytics.
	 *
	 * The wrapped `/wc-analytics/reports/coupons` route resolves its permission to
	 * `view_woocommerce_reports` (`WC_REST_Reports_V1_Controller::get_items_permissions_check()`
	 * → `wc_rest_check_manager_permissions( 'reports', 'read' )`), so this mirrors that
	 * exact cap as the coarse, object-independent guard. The `hasAnalytics()` gate keeps
	 * the denial clean when the Analytics feature is off (the route is not registered).
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may read WooCommerce analytics reports.
	 */
	public function hasPermission( $input ): bool {
		return WooPlugin::isActive() && WooPlugin::hasAnalytics() && current_user_can( 'view_woocommerce_reports' );
	}

	/**
	 * Executes the ability by dispatching the internal `wc-analytics` REST request.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The shaped coupon rows and total, or the REST error.
	 */
	public function execute( $input ) {
		if ( ! WooPlugin::isActive() ) {
			return WooPlugin::unavailable();
		}

		$input = is_array( $input ) ? $input : array();

		$request = new WP_REST_Request( 'GET', '/wc-analytics/reports/coupons' );
		// extended_info carries code/discount_type, which the report omits from the top-level row.
		$request->set_param( 'extended_info', true );
		$request->set_param( 'per_page', max( 1, min( 100, absint( $input['per_page'] ?? 10 ) ) ) );
		$request->set_param( 'page', max( 1, absint( $input['page'] ?? 1 ) ) );
		$request->set_param( 'order', 'asc' === strtolower( (string) ( $input['order'] ?? 'desc' ) ) ? 'asc' : 'desc' );
		$request->set_param( 'orderby', (string) ( $input['orderby'] ?? 'coupon_id' ) );
		if ( ! empty( $input['after'] ) ) {
			$request->set_param( 'after', (string) $input['after'] );
		}
		if ( ! empty( $input['before'] ) ) {
			$request->set_param( 'before', (string) $input['before'] );
		}

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

			$rows[] = AnalyticsReportShaper::analyticsRow( $row, self::ROW_KEYS );
		}

		$headers = $response->get_headers();
		$total   = isset( $headers['X-WP-Total'] ) ? (int) $headers['X-WP-Total'] : count( $rows );

		return array(
			'items' => $rows,
			'total' => $total,
		);
	}
}
