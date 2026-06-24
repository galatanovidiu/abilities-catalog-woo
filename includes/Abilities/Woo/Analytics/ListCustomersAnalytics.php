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
 * Read ability: `wc-reports/list-customers-analytics`.
 *
 * Wraps `GET /wc-analytics/reports/customers` via `rest_do_request()` and returns
 * each analytics customer record as a flat summary row: `id`, `user_id`, `name`,
 * `username`, `email`, `country`, `city`, `state`, `postcode`, `date_registered`,
 * `date_last_active`, `orders_count`, `total_spend`, and `avg_order_value`. The
 * controller's `_gmt` duplicate date fields and the separate `first_name`/`last_name`
 * are intentionally NOT returned — only the closed set above is surfaced.
 *
 * This is the customers segment list — an identity/spend record, NOT a time-series.
 * The `after`/`before` range filters by ORDER date (the controller remaps them to
 * `order_after`/`order_before` internally), so a range narrows to customers who
 * ordered within it. There is no `/stats` companion that mirrors this list one-to-one;
 * use `wc-reports/get-customers-stats` for the store-wide customer aggregate.
 *
 * PII: each row carries the customer `name`, `username`, and `email`. That is gated by
 * the `view_woocommerce_reports` capability — the same gate WooCommerce's own Analytics
 * customer report uses; no extra contact fields are returned.
 *
 * Only available when the store's WooCommerce **Analytics** feature is enabled (it is a
 * {@see ConditionalAbility} gated on {@see WooPlugin::hasAnalytics()}); when Analytics is
 * off the ability does not register at all (it degrades cleanly, it does not deny). The
 * report reads the `wc_customer_lookup` table, which an async sync populates, so a
 * just-registered customer may not appear immediately.
 *
 * @since 0.1.0
 */
final class ListCustomersAnalytics implements ConditionalAbility {

	/**
	 * Whitelist of returned row fields mapped to their cast type.
	 *
	 * All fields are top-level on the analytics customers row (no `extended_info`
	 * nesting), so {@see AnalyticsReportShaper::analyticsRow()} reads each directly.
	 * The `_gmt` duplicate dates and `first_name`/`last_name` are absent here, so the
	 * shaper drops them by construction. Dates are emitted as strings ('' when null).
	 *
	 * @var array<string,string>
	 */
	private const ROW_KEYS = array(
		'id'               => 'int',
		'user_id'          => 'int',
		'name'             => 'string',
		'username'         => 'string',
		'email'            => 'string',
		'country'          => 'string',
		'city'             => 'string',
		'state'            => 'string',
		'postcode'         => 'string',
		'date_registered'  => 'string',
		'date_last_active' => 'string',
		'orders_count'     => 'int',
		'total_spend'      => 'float',
		'avg_order_value'  => 'float',
	);

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'wc-reports/list-customers-analytics';
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
			'label'               => __( 'List Customers Analytics', 'abilities-catalog-woo' ),
			'description'         => __( 'Returns WooCommerce Analytics customer records as flat rows, each with id, user_id, name, username, email, country, city, state, postcode, date_registered, date_last_active, orders_count, total_spend, and avg_order_value. This is the customer segment list (identity and lifetime-spend per customer), not a time series. Use it to rank or find customers by orders or spend; use wc-reports/get-customers-stats for the store-wide customer aggregate. The after/before range filters by ORDER date (so a range returns customers who ordered within it), as ISO8601 date-times; omit them for all customers. Rows carry customer PII (name and email), gated by the same view_woocommerce_reports capability WooCommerce uses. The _gmt duplicate dates and first_name/last_name are intentionally dropped. Only available when the store\'s WooCommerce Analytics feature is enabled.', 'abilities-catalog-woo' ),
			'category'            => 'wc-reports',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'after'    => array(
						'type'        => 'string',
						'format'      => 'date-time',
						'description' => __( 'Limit to customers who placed an order on or after this ISO8601 date-time (e.g. 2024-01-01T00:00:00). Filters by order date, not registration date. Omit for no lower bound.', 'abilities-catalog-woo' ),
					),
					'before'   => array(
						'type'        => 'string',
						'format'      => 'date-time',
						'description' => __( 'Limit to customers who placed an order on or before this ISO8601 date-time (e.g. 2024-01-31T23:59:59). Filters by order date, not registration date. Omit for no upper bound.', 'abilities-catalog-woo' ),
					),
					'per_page' => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'maximum'     => 100,
						'default'     => 10,
						'description' => __( 'Maximum number of customer rows to return (1-100). Defaults to 10.', 'abilities-catalog-woo' ),
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
						'enum'        => array( 'name', 'username', 'date_registered', 'date_last_active', 'orders_count', 'total_spend', 'avg_order_value' ),
						'default'     => 'date_registered',
						'description' => __( 'Field to sort by: "name", "username", "date_registered", "date_last_active", "orders_count", "total_spend", or "avg_order_value". Defaults to "date_registered".', 'abilities-catalog-woo' ),
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
						'description' => __( 'The customer analytics rows. Use wc-reports/get-customers-stats for the store-wide customer aggregate.', 'abilities-catalog-woo' ),
						'items'       => AnalyticsReportShaper::analyticsItemSchema(
							array(
								'id'               => array(
									'type'        => 'integer',
									'description' => __( 'The analytics customer ID (the wc_customer_lookup row ID, not the WordPress user ID).', 'abilities-catalog-woo' ),
								),
								'user_id'          => array(
									'type'        => 'integer',
									'description' => __( 'The linked WordPress user ID, or 0 for a guest customer with no account.', 'abilities-catalog-woo' ),
								),
								'name'             => array(
									'type'        => 'string',
									'description' => __( 'The customer full name (PII). Empty when unknown.', 'abilities-catalog-woo' ),
								),
								'username'         => array(
									'type'        => 'string',
									'description' => __( 'The WordPress username, or an empty string for a guest customer.', 'abilities-catalog-woo' ),
								),
								'email'            => array(
									'type'        => 'string',
									'description' => __( 'The customer email address (PII). Empty when unknown.', 'abilities-catalog-woo' ),
								),
								'country'          => array(
									'type'        => 'string',
									'description' => __( 'The customer country/region code, or an empty string when unknown.', 'abilities-catalog-woo' ),
								),
								'city'             => array(
									'type'        => 'string',
									'description' => __( 'The customer city, or an empty string when unknown.', 'abilities-catalog-woo' ),
								),
								'state'            => array(
									'type'        => 'string',
									'description' => __( 'The customer state/region, or an empty string when unknown.', 'abilities-catalog-woo' ),
								),
								'postcode'         => array(
									'type'        => 'string',
									'description' => __( 'The customer postal code, or an empty string when unknown.', 'abilities-catalog-woo' ),
								),
								'date_registered'  => array(
									'type'        => 'string',
									'description' => __( 'When the customer registered (site timezone, ISO8601), or an empty string for a guest with no account.', 'abilities-catalog-woo' ),
								),
								'date_last_active' => array(
									'type'        => 'string',
									'description' => __( 'When the customer was last active (site timezone, ISO8601), or an empty string when unknown.', 'abilities-catalog-woo' ),
								),
								'orders_count'     => array(
									'type'        => 'integer',
									'description' => __( 'The number of orders this customer has placed.', 'abilities-catalog-woo' ),
								),
								'total_spend'      => array(
									'type'        => 'number',
									'description' => __( 'The customer lifetime spend, in the store currency.', 'abilities-catalog-woo' ),
								),
								'avg_order_value'  => array(
									'type'        => 'number',
									'description' => __( 'The customer average order value, in the store currency.', 'abilities-catalog-woo' ),
								),
							)
						),
					),
					'total' => array(
						'type'        => 'integer',
						'description' => __( 'Total number of customers matching the query, from the X-WP-Total response header (falls back to the number of returned rows if the header is absent). May exceed the returned rows when paging.', 'abilities-catalog-woo' ),
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
	 * The wrapped `/wc-analytics/reports/customers` route resolves its permission to
	 * `view_woocommerce_reports` (`WC_REST_Reports_V1_Controller::get_items_permissions_check()`
	 * → `wc_rest_check_manager_permissions( 'reports', 'read' )`), so this mirrors that
	 * exact cap as the coarse, object-independent guard. It also gates the customer PII
	 * the rows carry. The `hasAnalytics()` gate keeps the denial clean when the Analytics
	 * feature is off (the route is not registered), matching batches 11 and 13.
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
	 * @return array<string,mixed>|\WP_Error The shaped customer rows and total, or the REST error.
	 */
	public function execute( $input ) {
		if ( ! WooPlugin::isActive() ) {
			return WooPlugin::unavailable();
		}

		$input = is_array( $input ) ? $input : array();

		$request = new WP_REST_Request( 'GET', '/wc-analytics/reports/customers' );
		$request->set_param( 'per_page', max( 1, min( 100, absint( $input['per_page'] ?? 10 ) ) ) );
		$request->set_param( 'page', max( 1, absint( $input['page'] ?? 1 ) ) );
		$request->set_param( 'order', 'asc' === strtolower( (string) ( $input['order'] ?? 'desc' ) ) ? 'asc' : 'desc' );
		$request->set_param( 'orderby', (string) ( $input['orderby'] ?? 'date_registered' ) );
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
