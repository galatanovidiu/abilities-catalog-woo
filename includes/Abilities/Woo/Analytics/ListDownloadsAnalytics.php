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
 * Read ability: `og-wc-reports/list-downloads-analytics`.
 *
 * Wraps `GET /wc-analytics/reports/downloads` via `rest_do_request()` and returns
 * the Analytics downloads report as flat per-event rows — one row per downloadable-
 * file download. Each row is shaped to a small identity subset (`id`, `product_id`,
 * `date`, `download_id`, `file_name`, `order_id`, `order_number`, `user_id`) by
 * {@see AnalyticsReportShaper::analyticsRow()}.
 *
 * PII redaction: the raw download-log row also carries the downloader's `ip_address`
 * and WordPress `username`. Both are intentionally dropped — they are absent from
 * {@see self::ROW_KEYS}, so the shaper's whitelist never copies them and they cannot
 * leak through this ability.
 *
 * This is the per-event list counterpart to `og-wc-reports/get-downloads-stats` (which
 * returns the aggregated download_count over the range). The Analytics downloads
 * route is paginated, so `total` is read from the `X-WP-Total` response header (the
 * full matching count), falling back to the number of returned rows only when the
 * header is absent.
 *
 * Only available when the store's WooCommerce **Analytics** feature is enabled (it
 * is a {@see ConditionalAbility} gated on {@see WooPlugin::hasAnalytics()}); when
 * Analytics is off the ability does not register, degrading cleanly rather than
 * denying. The Analytics lookup tables are populated by an asynchronous data sync,
 * so a just-recorded download may not appear in this report until that sync has run.
 *
 * @since 0.1.0
 */
final class ListDownloadsAnalytics implements ConditionalAbility {

	/**
	 * The whitelisted output row fields and their cast types.
	 *
	 * `ip_address` and `username` are deliberately absent — the download log's PII
	 * is dropped by construction because the shaper copies only these keys.
	 *
	 * @var array<string,string>
	 */
	private const ROW_KEYS = array(
		'id'           => 'int',
		'product_id'   => 'int',
		'date'         => 'string',
		'download_id'  => 'string',
		'file_name'    => 'string',
		'order_id'     => 'int',
		'order_number' => 'string',
		'user_id'      => 'int',
	);

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-wc-reports/list-downloads-analytics';
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
			'label'               => __( 'List Downloads Analytics', 'abilities-catalog-woo' ),
			'description'         => __( 'Returns the WooCommerce Analytics downloads report as flat per-event rows — one row per downloadable-file download — each with id, product_id, date, download_id, file_name, order_id, order_number, and user_id. Filter the date range with after/before (ISO8601 date-time; omit for the Analytics default range), page with per_page/page, and narrow by products, orders, or customers (arrays of IDs). Use this for a per-download breakdown; use og-wc-reports/get-downloads-stats for the aggregated download count over a range. Privacy: the downloader\'s IP address and username are intentionally omitted from every row. Available only when the store\'s WooCommerce Analytics feature is enabled; Analytics reads from lookup tables synced asynchronously, so a just-recorded download may not appear yet.', 'abilities-catalog-woo' ),
			'category'            => 'og-wc-reports',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'after'     => array(
						'type'        => 'string',
						'format'      => 'date-time',
						'description' => __( 'Limit to downloads on or after this ISO8601 date-time (start of the range), e.g. 2024-01-01T00:00:00. Omit to use the Analytics default range.', 'abilities-catalog-woo' ),
					),
					'before'    => array(
						'type'        => 'string',
						'format'      => 'date-time',
						'description' => __( 'Limit to downloads on or before this ISO8601 date-time (end of the range), e.g. 2024-01-31T23:59:59. Omit to use the Analytics default range.', 'abilities-catalog-woo' ),
					),
					'per_page'  => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'maximum'     => 100,
						'default'     => 100,
						'description' => __( 'Maximum number of download rows to return (1-100). Defaults to 100.', 'abilities-catalog-woo' ),
					),
					'page'      => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'default'     => 1,
						'description' => __( 'Page number of the result set, for paging past the first per_page rows.', 'abilities-catalog-woo' ),
					),
					'order'     => array(
						'type'        => 'string',
						'enum'        => array( 'asc', 'desc' ),
						'default'     => 'desc',
						'description' => __( 'Sort direction: "asc" (oldest first) or "desc" (newest first).', 'abilities-catalog-woo' ),
					),
					'orderby'   => array(
						'type'        => 'string',
						'enum'        => array( 'date', 'product' ),
						'default'     => 'date',
						'description' => __( 'Sort field: "date" (download date) or "product" (product title).', 'abilities-catalog-woo' ),
					),
					'products'  => array(
						'type'        => 'array',
						'items'       => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
						'description' => __( 'Limit to downloads of the given product IDs. Omit for all products. Discover product IDs with og-wc-products/list-products.', 'abilities-catalog-woo' ),
					),
					'orders'    => array(
						'type'        => 'array',
						'items'       => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
						'description' => __( 'Limit to downloads tied to the given order IDs. Omit for all orders. Discover order IDs with og-wc-orders/list-orders.', 'abilities-catalog-woo' ),
					),
					'customers' => array(
						'type'        => 'array',
						'items'       => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
						'description' => __( 'Limit to downloads by the given customer (WooCommerce customer) IDs. Omit for all customers. Discover customer IDs with og-wc-customers/list-customers.', 'abilities-catalog-woo' ),
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
						'description' => __( 'The download-event rows. Each is a flat per-download summary; the downloader\'s IP address and username are omitted.', 'abilities-catalog-woo' ),
						'items'       => AnalyticsReportShaper::analyticsItemSchema(
							array(
								'id'           => array(
									'type'        => 'integer',
									'description' => __( 'The download-log row ID.', 'abilities-catalog-woo' ),
								),
								'product_id'   => array(
									'type'        => 'integer',
									'description' => __( 'The downloaded product\'s ID.', 'abilities-catalog-woo' ),
								),
								'date'         => array(
									'type'        => 'string',
									'description' => __( 'The date of the download, in the site timezone (ISO8601 date-time).', 'abilities-catalog-woo' ),
								),
								'download_id'  => array(
									'type'        => 'string',
									'description' => __( 'The downloadable-file identifier within the product (a hash, not a numeric ID).', 'abilities-catalog-woo' ),
								),
								'file_name'    => array(
									'type'        => 'string',
									'description' => __( 'The downloaded file\'s name, or an empty string when the product no longer exists.', 'abilities-catalog-woo' ),
								),
								'order_id'     => array(
									'type'        => 'integer',
									'description' => __( 'The ID of the order that granted the download.', 'abilities-catalog-woo' ),
								),
								'order_number' => array(
									'type'        => 'string',
									'description' => __( 'The human-readable order number (may differ from order_id).', 'abilities-catalog-woo' ),
								),
								'user_id'      => array(
									'type'        => 'integer',
									'description' => __( 'The WordPress user ID of the downloader, or 0 for a guest.', 'abilities-catalog-woo' ),
								),
							)
						),
					),
					'total' => array(
						'type'        => 'integer',
						'description' => __( 'Total number of download events matching the query. Read from the X-WP-Total pagination header (the full matching count), falling back to the number of returned rows when the header is absent.', 'abilities-catalog-woo' ),
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
	 * Calls {@see self::ensureAnalyticsRoutes()} first to (re)attach WooCommerce's lazy
	 * `wc-analytics` loader and prime the route table before the real request, so the
	 * route matches the dispatch. When Analytics is off the routes stay absent and
	 * `rest_do_request()` returns a `rest_no_route` error, surfaced verbatim via
	 * {@see RestError::from()}. The friendly `products`/`orders`/`customers` inputs map
	 * to the controller's `product_includes`/`order_includes`/`customer_includes` array
	 * filters.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The shaped download rows and total, or the REST error.
	 */
	public function execute( $input ) {
		if ( ! WooPlugin::isActive() ) {
			return WooPlugin::unavailable();
		}

		$input = is_array( $input ) ? $input : array();

		self::ensureAnalyticsRoutes();

		$request = new WP_REST_Request( 'GET', '/wc-analytics/reports/downloads' );
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
		if ( ! empty( $input['products'] ) && is_array( $input['products'] ) ) {
			$request->set_param( 'product_includes', array_map( 'absint', $input['products'] ) );
		}
		if ( ! empty( $input['orders'] ) && is_array( $input['orders'] ) ) {
			$request->set_param( 'order_includes', array_map( 'absint', $input['orders'] ) );
		}
		if ( ! empty( $input['customers'] ) && is_array( $input['customers'] ) ) {
			$request->set_param( 'customer_includes', array_map( 'absint', $input['customers'] ) );
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

		if ( isset( $server->get_routes()['/wc-analytics/reports/downloads'] ) ) {
			return;
		}

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Re-firing WordPress core's own `rest_api_init`, not a custom hook, to re-attach WooCommerce's lazy `wc-analytics` loader in its expected context.
		do_action( 'rest_api_init', $server );

		rest_do_request( new WP_REST_Request( 'GET', '/wc-analytics/reports/downloads' ) );
	}
}
