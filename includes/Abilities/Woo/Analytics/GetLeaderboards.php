<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogWoo\Abilities\Woo\Analytics;

use GalatanOvidiu\AbilitiesCatalogWoo\Contracts\ConditionalAbility;
use GalatanOvidiu\AbilitiesCatalogWoo\Support\RestError;
use GalatanOvidiu\AbilitiesCatalogWoo\Support\WooPlugin;
use WP_REST_Request;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read ability: `og-wc-reports/get-leaderboards`.
 *
 * Wraps `GET /wc-analytics/leaderboards` via `rest_do_request()` and returns
 * WooCommerce Analytics' bounded leaderboard tables — top customers (by total
 * spend), top coupons (by orders), top categories (by items sold), and top
 * products (by items sold) — over a date range. Each leaderboard is already a
 * compact, display-ready table (`id`, `label`, column `headers`, and `rows` of
 * cells), so this ability passes the shaped leaderboard objects through a closed
 * nested schema rather than reducing them to a totals subset (unlike the
 * `og-wc-reports/*-stats` abilities). There is no top-level total on this route.
 *
 * Only available when the store's WooCommerce **Analytics** feature is enabled
 * (it is a {@see ConditionalAbility} gated on {@see WooPlugin::hasAnalytics()});
 * when Analytics is off the ability does not register, so it degrades cleanly
 * (absent) rather than denying.
 *
 * The `wc-analytics` namespace is lazy-loaded — `rest_do_request()` triggers its
 * registration on dispatch, so the route is present by the time `execute()` runs.
 *
 * @since 0.1.0
 */
final class GetLeaderboards implements ConditionalAbility {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-wc-reports/get-leaderboards';
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
			'label'               => __( 'Get Leaderboards', 'abilities-catalog-woo' ),
			'description'         => __( 'Returns WooCommerce Analytics leaderboards over a date range: top customers (by total spend), top coupons (by orders), top categories (by items sold), and top products (by items sold). Each leaderboard is a compact table with an id, label, column headers, and rows of cells ({ display, value, format }); these are returned as-is — there is no totals subset and no top-level total. Use this for ranked "top N" tables; use og-wc-reports/get-revenue-stats or og-wc-reports/get-orders-stats for aggregated KPI totals over a range. The date range is set with after/before (ISO8601 date-time); per_page caps each leaderboard at up to 20 rows. Available only when the store\'s WooCommerce Analytics feature is enabled.', 'abilities-catalog-woo' ),
			'category'            => 'og-wc-reports',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'after'    => array(
						'type'        => 'string',
						'format'      => 'date-time',
						'description' => __( 'Limit the leaderboards to data on or after this ISO8601 date-time (e.g. 2024-01-01T00:00:00). Start of the range; omit for the controller default.', 'abilities-catalog-woo' ),
					),
					'before'   => array(
						'type'        => 'string',
						'format'      => 'date-time',
						'description' => __( 'Limit the leaderboards to data on or before this ISO8601 date-time (e.g. 2024-01-31T23:59:59). End of the range; omit for the controller default.', 'abilities-catalog-woo' ),
					),
					'per_page' => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'maximum'     => 20,
						'default'     => 5,
						'description' => __( 'Maximum number of rows in each leaderboard (1-20). Defaults to 5 (the top 5).', 'abilities-catalog-woo' ),
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'leaderboards' ),
				'properties'           => array(
					'leaderboards' => array(
						'type'        => 'array',
						'description' => __( 'The leaderboard tables (top customers, coupons, categories, products). Each is a compact, display-ready table.', 'abilities-catalog-woo' ),
						'items'       => $this->leaderboardItemSchema(),
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
	 * Permission check: the WooCommerce reports-view capability.
	 *
	 * Coarse, object-independent gate. The wrapped route accepts either
	 * `view_woocommerce_reports` OR `manage_woocommerce` (see
	 * `Automattic\WooCommerce\Admin\API\Leaderboards::get_items_permissions_check()`);
	 * this ability guards on the narrower `view_woocommerce_reports`, which is the
	 * common, always-sufficient analytics-read capability shared with the
	 * `og-wc-reports/*-stats` siblings. The Analytics-feature and WooCommerce-active
	 * gates keep the denial clean when the dependency is absent. The route surfaces
	 * any object-level error itself.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may read analytics leaderboards.
	 */
	public function hasPermission( $input ): bool {
		return WooPlugin::isActive()
			&& WooPlugin::hasAnalytics()
			&& current_user_can( 'view_woocommerce_reports' );
	}

	/**
	 * Executes the ability by dispatching the internal `wc-analytics` REST request.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The shaped leaderboards, or the REST error.
	 */
	public function execute( $input ) {
		if ( ! WooPlugin::isActive() ) {
			return WooPlugin::unavailable();
		}

		$input = is_array( $input ) ? $input : array();

		$request = new WP_REST_Request( 'GET', '/wc-analytics/leaderboards' );
		if ( ! empty( $input['after'] ) ) {
			$request->set_param( 'after', (string) $input['after'] );
		}
		if ( ! empty( $input['before'] ) ) {
			$request->set_param( 'before', (string) $input['before'] );
		}
		$request->set_param( 'per_page', max( 1, min( 20, absint( $input['per_page'] ?? 5 ) ) ) );
		// The leaderboards route json_decode()s persisted_query unconditionally, and the
		// param has no schema default — on an internal dispatch it arrives null, which
		// triggers a PHP 8.1 json_decode(null) deprecation. Pass an empty string so the
		// decode is a clean no-op.
		$request->set_param( 'persisted_query', '' );

		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			return RestError::from( $response );
		}

		$data = rest_get_server()->response_to_data( $response, false );

		$leaderboards = array();
		foreach ( is_array( $data ) ? $data : array() as $leaderboard ) {
			if ( ! is_array( $leaderboard ) ) {
				continue;
			}

			$leaderboards[] = $this->shapeLeaderboard( $leaderboard );
		}

		return array(
			'leaderboards' => $leaderboards,
		);
	}

	/**
	 * Projects a raw leaderboard object into a flat, closed table.
	 *
	 * Casts `id`/`label` to strings, shapes each header to `{ label }`, and shapes
	 * each row's cells to the closed `{ display, value, format }` triple. The
	 * controller omits `format` on a row's first (name) cell, so `format` is an
	 * optional cell property; `value` is the raw value (a string for the name cell,
	 * a number for the metric cells) preserved as-is.
	 *
	 * @param array<string,mixed> $leaderboard A single leaderboard from the response.
	 * @return array<string,mixed> The flat closed leaderboard.
	 */
	private function shapeLeaderboard( array $leaderboard ): array {
		$headers = array();
		foreach ( (array) ( $leaderboard['headers'] ?? array() ) as $header ) {
			$headers[] = array(
				'label' => (string) ( is_array( $header ) ? ( $header['label'] ?? '' ) : '' ),
			);
		}

		$rows = array();
		foreach ( (array) ( $leaderboard['rows'] ?? array() ) as $row ) {
			$cells = array();
			foreach ( (array) $row as $cell ) {
				$cells[] = $this->shapeCell( is_array( $cell ) ? $cell : array() );
			}
			$rows[] = $cells;
		}

		return array(
			'id'      => (string) ( $leaderboard['id'] ?? '' ),
			'label'   => (string) ( $leaderboard['label'] ?? '' ),
			'headers' => $headers,
			'rows'    => $rows,
		);
	}

	/**
	 * Projects a raw leaderboard row cell into the closed `{ display, value, format }` shape.
	 *
	 * `display` (an HTML/text label) and `value` (the underlying value) are always
	 * present; `format` (`number`/`currency`) is present only on metric cells, so it
	 * is copied only when the raw cell carries it.
	 *
	 * @param array<string,mixed> $cell A single raw cell.
	 * @return array<string,mixed> The closed cell.
	 */
	private function shapeCell( array $cell ): array {
		$shaped = array(
			'display' => (string) ( $cell['display'] ?? '' ),
			'value'   => $cell['value'] ?? '',
		);

		if ( array_key_exists( 'format', $cell ) ) {
			$shaped['format'] = (string) $cell['format'];
		}

		return $shaped;
	}

	/**
	 * The closed `output_schema` item definition for a single leaderboard.
	 *
	 * Every nested object is pinned `additionalProperties: false`: the leaderboard,
	 * each header, and each row cell. `format` is an optional cell property (the
	 * first cell of a row omits it); `value` accepts a string or a number because
	 * the name cell carries a string while metric cells carry numbers.
	 *
	 * @return array<string,mixed> A JSON-Schema object fragment.
	 */
	private function leaderboardItemSchema(): array {
		return array(
			'type'                 => 'object',
			'required'             => array( 'id', 'label', 'headers', 'rows' ),
			'properties'           => array(
				'id'      => array(
					'type'        => 'string',
					'description' => __( 'The leaderboard ID (e.g. customers, coupons, categories, products).', 'abilities-catalog-woo' ),
				),
				'label'   => array(
					'type'        => 'string',
					'description' => __( 'The human-readable leaderboard title (e.g. "Top Customers - Total Spend").', 'abilities-catalog-woo' ),
				),
				'headers' => array(
					'type'        => 'array',
					'description' => __( 'The table column headers, in column order.', 'abilities-catalog-woo' ),
					'items'       => array(
						'type'                 => 'object',
						'required'             => array( 'label' ),
						'properties'           => array(
							'label' => array(
								'type'        => 'string',
								'description' => __( 'The column header label.', 'abilities-catalog-woo' ),
							),
						),
						'additionalProperties' => false,
					),
				),
				'rows'    => array(
					'type'        => 'array',
					'description' => __( 'The table rows. Each row is an array of cells, one per column.', 'abilities-catalog-woo' ),
					'items'       => array(
						'type'  => 'array',
						'items' => array(
							'type'                 => 'object',
							'required'             => array( 'display', 'value' ),
							'properties'           => array(
								'display' => array(
									'type'        => 'string',
									'description' => __( 'The cell display markup or text (may contain an HTML link to the wp-admin analytics screen).', 'abilities-catalog-woo' ),
								),
								'value'   => array(
									'type'        => array( 'string', 'number' ),
									'description' => __( 'The underlying cell value: a string for the name cell, a number for metric cells.', 'abilities-catalog-woo' ),
								),
								'format'  => array(
									'type'        => 'string',
									'enum'        => array( 'number', 'currency' ),
									'description' => __( 'The metric cell format (number or currency). Absent on the leading name cell.', 'abilities-catalog-woo' ),
								),
							),
							'additionalProperties' => false,
						),
					),
				),
			),
			'additionalProperties' => false,
		);
	}
}
