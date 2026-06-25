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
 * Read ability: `og-wc-reports/list-orders-analytics`.
 *
 * Wraps `GET /wc-analytics/reports/orders` via `rest_do_request()` and returns the
 * Analytics orders report as flat per-order rows. Each row is shaped to a small
 * identity + KPI subset (`order_id`, `order_number`, `date_created`, `status`,
 * `customer_id`, `customer_type`, `num_items_sold`, `net_total`,
 * `total_formatted`); the row's fat `extended_info` object (per-order products,
 * coupons, customer, attribution) is intentionally dropped by
 * {@see AnalyticsReportShaper::analyticsRow()}.
 *
 * This is the per-order list counterpart to `og-wc-reports/get-orders-stats` (which
 * returns the aggregated KPI totals over the range). The Analytics orders route is
 * a paginated report, so `total` is read from the `X-WP-Total` response header (the
 * full matching count), falling back to the number of returned rows only when the
 * header is absent.
 *
 * Only available when the store's WooCommerce **Analytics** feature is enabled (it
 * is a {@see ConditionalAbility} gated on {@see WooPlugin::hasAnalytics()}); when
 * Analytics is off the ability does not register, degrading cleanly rather than
 * denying. The Analytics lookup tables are populated by an asynchronous data sync,
 * so a freshly-placed order may not appear in this report until that sync has run.
 *
 * @since 0.1.0
 */
final class ListOrdersAnalytics implements ConditionalAbility {

	/**
	 * The whitelisted output row fields and their cast types.
	 *
	 * @var array<string,string>
	 */
	private const ROW_KEYS = array(
		'order_id'        => 'int',
		'order_number'    => 'string',
		'date_created'    => 'string',
		'status'          => 'string',
		'customer_id'     => 'int',
		'customer_type'   => 'string',
		'num_items_sold'  => 'int',
		'net_total'       => 'float',
		'total_formatted' => 'string',
	);

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-wc-reports/list-orders-analytics';
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
			'label'               => __( 'List Orders Analytics', 'abilities-catalog-woo' ),
			'description'         => __( 'Returns the WooCommerce Analytics orders report as flat per-order rows, each with order_id, order_number, date_created, status, customer_id, customer_type (new or returning), num_items_sold, net_total, and total_formatted. Filter the date range with after/before (ISO8601 date-time) and page with per_page/page. Use this for a per-order breakdown; use og-wc-reports/get-orders-stats for the aggregated KPI totals over a range. The large per-order extended_info (products, coupons, customer, attribution) is intentionally omitted. Available only when the store\'s WooCommerce Analytics feature is enabled; Analytics reads from lookup tables synced asynchronously, so a just-placed order may not appear yet.', 'abilities-catalog-woo' ),
			'category'            => 'og-wc-reports',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'after'    => array(
						'type'        => 'string',
						'format'      => 'date-time',
						'description' => __( 'Limit to orders on or after this ISO8601 date-time (start of the range), e.g. 2024-01-01T00:00:00.', 'abilities-catalog-woo' ),
					),
					'before'   => array(
						'type'        => 'string',
						'format'      => 'date-time',
						'description' => __( 'Limit to orders on or before this ISO8601 date-time (end of the range), e.g. 2024-01-31T23:59:59.', 'abilities-catalog-woo' ),
					),
					'per_page' => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'maximum'     => 100,
						'default'     => 100,
						'description' => __( 'Maximum number of order rows to return (1-100). Defaults to 100.', 'abilities-catalog-woo' ),
					),
					'page'     => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'default'     => 1,
						'description' => __( 'Page number of the result set, for paging past the first per_page rows.', 'abilities-catalog-woo' ),
					),
					'order'    => array(
						'type'        => 'string',
						'enum'        => array( 'asc', 'desc' ),
						'default'     => 'desc',
						'description' => __( 'Sort direction: "asc" (oldest/lowest first) or "desc" (newest/highest first).', 'abilities-catalog-woo' ),
					),
					'orderby'  => array(
						'type'        => 'string',
						'enum'        => array( 'date', 'num_items_sold', 'net_total' ),
						'default'     => 'date',
						'description' => __( 'Sort field: "date" (order date), "num_items_sold", or "net_total".', 'abilities-catalog-woo' ),
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
						'description' => __( 'The order-analytics rows. Each is a flat per-order summary; the large extended_info object is omitted.', 'abilities-catalog-woo' ),
						'items'       => AnalyticsReportShaper::analyticsItemSchema(
							array(
								'order_id'        => array(
									'type'        => 'integer',
									'description' => __( 'The order ID.', 'abilities-catalog-woo' ),
								),
								'order_number'    => array(
									'type'        => 'string',
									'description' => __( 'The human-readable order number (may differ from the order ID).', 'abilities-catalog-woo' ),
								),
								'date_created'    => array(
									'type'        => 'string',
									'description' => __( 'The date the order was created, in the site timezone (ISO8601 date-time).', 'abilities-catalog-woo' ),
								),
								'status'          => array(
									'type'        => 'string',
									'description' => __( 'The order status slug (e.g. completed, processing, refunded).', 'abilities-catalog-woo' ),
								),
								'customer_id'     => array(
									'type'        => 'integer',
									'description' => __( 'The customer ID, or 0 for a guest order.', 'abilities-catalog-woo' ),
								),
								'customer_type'   => array(
									'type'        => 'string',
									'description' => __( 'Whether the order was placed by a "new" or "returning" customer.', 'abilities-catalog-woo' ),
								),
								'num_items_sold'  => array(
									'type'        => 'integer',
									'description' => __( 'The number of items sold in the order.', 'abilities-catalog-woo' ),
								),
								'net_total'       => array(
									'type'        => 'number',
									'description' => __( 'The net order revenue (total less tax, shipping, and refunds), in the store currency.', 'abilities-catalog-woo' ),
								),
								'total_formatted' => array(
									'type'        => 'string',
									'description' => __( 'The net revenue formatted with the store currency symbol.', 'abilities-catalog-woo' ),
								),
							)
						),
					),
					'total' => array(
						'type'        => 'integer',
						'description' => __( 'Total number of orders matching the query. Read from the X-WP-Total pagination header (the full matching count), falling back to the number of returned rows when the header is absent.', 'abilities-catalog-woo' ),
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
	 * Permission check: the WooCommerce reports capability.
	 *
	 * Encodes the catalog baseline for the Analytics reads: `view_woocommerce_reports`,
	 * which is exactly the capability the wrapped route enforces
	 * (`wc_rest_check_manager_permissions( 'reports', 'read' )` →
	 * `view_woocommerce_reports`). This is a coarse, object-independent guard; the
	 * wrapped route surfaces any specific error. The `hasAnalytics()` guard keeps the
	 * denial clean when Analytics is off (the route would otherwise be absent).
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may read WooCommerce reports.
	 */
	public function hasPermission( $input ): bool {
		return WooPlugin::isActive()
			&& WooPlugin::hasAnalytics()
			&& current_user_can( 'view_woocommerce_reports' );
	}

	/**
	 * Executes the ability by dispatching the internal `wc-analytics` REST request.
	 *
	 * The `wc-analytics` namespace is lazy-loaded: WooCommerce registers its controllers
	 * on the `rest_pre_dispatch` filter (priority 0), i.e. only once a `wc-analytics` route
	 * is dispatched. But `WP_REST_Server::dispatch()` matches against the route table built
	 * BEFORE that filter runs, so the route registered during a dispatch cannot match that
	 * same dispatch and `rest_do_request()` returns `rest_no_route` (404); worse, once that
	 * lazy filter has fired it removes itself, so a later dispatch on a reused server never
	 * re-registers the routes at all. So this calls {@see self::ensureAnalyticsRoutes()}
	 * first to (re)attach the loader and prime the routes before the real request,
	 * guaranteeing the route is present when the real dispatch runs. When Analytics is off
	 * the routes stay absent and `rest_do_request()` returns a `rest_no_route` error,
	 * surfaced verbatim via {@see RestError::from()}.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The shaped order rows and total, or the REST error.
	 */
	public function execute( $input ) {
		if ( ! WooPlugin::isActive() ) {
			return WooPlugin::unavailable();
		}

		$input = is_array( $input ) ? $input : array();

		self::ensureAnalyticsRoutes();

		$request = new WP_REST_Request( 'GET', '/wc-analytics/reports/orders' );
		if ( ! empty( $input['after'] ) ) {
			$request->set_param( 'after', (string) $input['after'] );
		}
		if ( ! empty( $input['before'] ) ) {
			$request->set_param( 'before', (string) $input['before'] );
		}
		$request->set_param( 'per_page', max( 1, min( 100, absint( $input['per_page'] ?? 100 ) ) ) );
		$request->set_param( 'page', max( 1, absint( $input['page'] ?? 1 ) ) );
		$request->set_param( 'order', 'asc' === strtolower( (string) ( $input['order'] ?? 'desc' ) ) ? 'asc' : 'desc' );
		$request->set_param( 'orderby', (string) ( $input['orderby'] ?? 'date' ) );

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

	/**
	 * Ensures the lazy-loaded `wc-analytics` routes are registered and matchable.
	 *
	 * WooCommerce lazy-loads the `wc-analytics` namespace: on `rest_api_init` it attaches a
	 * `rest_pre_dispatch` (priority 0) filter that registers the controllers only once a
	 * `wc-analytics` route is dispatched. Two things make a plain dispatch unreliable: (1)
	 * that filter fires AFTER `WP_REST_Server::dispatch()` has already built its route-match
	 * list, so the route registered during a dispatch cannot match that same dispatch and
	 * `rest_do_request()` returns `rest_no_route` (404); and (2) the filter removes itself
	 * once it has fired, so on a REST server reused across calls without a fresh
	 * `rest_api_init` (the PHPUnit harness boots one server and does not re-fire the action)
	 * the loader is gone and never re-registers.
	 *
	 * So this re-fires `rest_api_init` on the live server — which re-attaches WooCommerce's
	 * own lazy loader and satisfies `register_rest_route()`'s requirement that
	 * `rest_api_init` has fired — and then issues one throwaway dispatch so the re-attached
	 * loader registers the routes before the real request runs. Both steps stay behind the
	 * `rest_do_request` boundary (no direct WooCommerce symbol use). When Analytics is off
	 * the routes stay absent and the real dispatch returns the truthful `rest_no_route`
	 * error. The whole helper is skipped once the route is already present, so a live REST
	 * request (where it is) incurs neither the re-fire nor the priming dispatch.
	 *
	 * @return void
	 */
	private static function ensureAnalyticsRoutes(): void {
		$server = rest_get_server();

		if ( isset( $server->get_routes()['/wc-analytics/reports/orders'] ) ) {
			return;
		}

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Re-firing WordPress core's own `rest_api_init`, not a custom hook, to re-attach WooCommerce's lazy `wc-analytics` loader in its expected context.
		do_action( 'rest_api_init', $server );

		rest_do_request( new WP_REST_Request( 'GET', '/wc-analytics/reports/orders' ) );
	}
}
