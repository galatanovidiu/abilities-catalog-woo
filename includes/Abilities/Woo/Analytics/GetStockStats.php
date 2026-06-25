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
 * Read ability: `og-wc-reports/get-stock-stats`.
 *
 * Wraps `GET /wc-analytics/reports/stock/stats` via `rest_do_request()` and
 * returns the store-wide inventory snapshot: how many products there are
 * (`products`), how many are low on stock (`lowstock`), and the per-status
 * counts (`instock`, `outofstock`, `onbackorder`).
 *
 * THE NO-INTERVALS EXCEPTION: unlike the other `wc-analytics` `/stats` reports,
 * the stock/stats controller does NOT extend `GenericStatsController` — it
 * returns a single `{ totals: {...} }` current-inventory snapshot with NO
 * per-period `intervals` array (`Stock/Stats/Controller.php::get_items()`). So
 * this ability returns `{ totals }` ONLY — there is no `intervals_count` and no
 * `period` envelope, and it does NOT use
 * {@see \GalatanOvidiu\AbilitiesCatalogWoo\Support\AnalyticsReportShaper::statsEnvelopeSchema()}.
 * The whitelisted snapshot keys are extracted with
 * {@see AnalyticsReportShaper::analyticsRow()} (all cast to int, defaulting to 0
 * when absent) and wrapped in `{ totals }` inline. The controller exposes no
 * useful filter params (only `context`), so the input is the all-optional
 * empty-but-object idiom.
 *
 * Only available when the store's WooCommerce **Analytics** feature is enabled
 * (it is a {@see ConditionalAbility} gated on {@see WooPlugin::hasAnalytics()});
 * when Analytics is off the ability does not register and degrades cleanly
 * (absent), rather than denying.
 *
 * @since 0.1.0
 */
final class GetStockStats implements ConditionalAbility {

	/**
	 * The whitelisted snapshot keys, each cast to int by the shaper.
	 *
	 * `products` + `lowstock` are fixed columns; `instock`/`outofstock`/
	 * `onbackorder` are the three standard `wc_get_product_stock_status_options()`
	 * keys the controller adds. Listing them here keeps the projection and the
	 * closed output schema in sync.
	 *
	 * @var array<string,string>
	 */
	private const TOTALS_KEYS = array(
		'products'    => 'int',
		'lowstock'    => 'int',
		'instock'     => 'int',
		'outofstock'  => 'int',
		'onbackorder' => 'int',
	);

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-wc-reports/get-stock-stats';
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
			'label'               => __( 'Get Stock Stats', 'abilities-catalog-woo' ),
			'description'         => __( 'Returns the store-wide WooCommerce Analytics inventory snapshot: products (total number of products), lowstock (products low on stock), instock, outofstock, and onbackorder (per-status product counts). This is a CURRENT snapshot, not a time series — unlike the other analytics /stats reports it returns no intervals_count and no period envelope, and takes no date or filter parameters. Use this for the store-wide instock/lowstock/outofstock totals; use og-wc-reports/list-stock-analytics for the per-product inventory rows. Only available when the store\'s WooCommerce Analytics feature is enabled.', 'abilities-catalog-woo' ),
			'category'            => 'og-wc-reports',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => (object) array(),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'totals' ),
				'properties'           => array(
					'totals' => array(
						'type'                 => 'object',
						'required'             => array(
							'products',
							'lowstock',
							'instock',
							'outofstock',
							'onbackorder',
						),
						'properties'           => array(
							'products'    => array(
								'type'        => 'integer',
								'description' => __( 'Total number of products in the catalog.', 'abilities-catalog-woo' ),
							),
							'lowstock'    => array(
								'type'        => 'integer',
								'description' => __( 'Number of products that are low on stock (at or below their low-stock threshold).', 'abilities-catalog-woo' ),
							),
							'instock'     => array(
								'type'        => 'integer',
								'description' => __( 'Number of products with stock status "in stock".', 'abilities-catalog-woo' ),
							),
							'outofstock'  => array(
								'type'        => 'integer',
								'description' => __( 'Number of products with stock status "out of stock".', 'abilities-catalog-woo' ),
							),
							'onbackorder' => array(
								'type'        => 'integer',
								'description' => __( 'Number of products with stock status "on backorder".', 'abilities-catalog-woo' ),
							),
						),
						'additionalProperties' => false,
						'description'          => __( 'The store-wide inventory snapshot. A single set of counts with no per-period breakdown.', 'abilities-catalog-woo' ),
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
	 * Permission check: WooCommerce's reports-view capability, gated on Analytics.
	 *
	 * Mirrors the wrapped `wc-analytics` route's effective permission, which chains
	 * to `wc_rest_check_manager_permissions( 'reports' )` → `view_woocommerce_reports`.
	 * The `hasAnalytics()` guard keeps the denial clean when the Analytics feature is
	 * off (the route would otherwise be absent). This is a coarse, object-independent
	 * guard; the wrapped route surfaces any per-request error via
	 * {@see RestError::from()}.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may read WooCommerce analytics reports.
	 */
	public function hasPermission( $input ): bool {
		return WooPlugin::isActive()
			&& WooPlugin::hasAnalytics()
			&& current_user_can( 'view_woocommerce_reports' );
	}

	/**
	 * Executes the ability by dispatching the internal `wc-analytics` REST request.
	 *
	 * Dispatching the `/wc-analytics/reports/stock/stats` route triggers the lazy
	 * registration of the `wc-analytics` namespace, so the route is present by the
	 * time this runs. The controller returns `{ totals: {...} }` with no `intervals`,
	 * so this projects the five whitelisted count fields into a flat, closed `totals`
	 * object (all cast to int via {@see AnalyticsReportShaper::analyticsRow()}) and
	 * returns `{ totals }` only — no `intervals_count`, no `period`.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The shaped inventory snapshot, or the REST error.
	 */
	public function execute( $input ) {
		if ( ! WooPlugin::isActive() ) {
			return WooPlugin::unavailable();
		}

		$input = is_array( $input ) ? $input : array();

		$request  = new WP_REST_Request( 'GET', '/wc-analytics/reports/stock/stats' );
		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			return RestError::from( $response );
		}

		$data = rest_get_server()->response_to_data( $response, false );
		$data = is_array( $data ) ? $data : array();

		return array(
			'totals' => AnalyticsReportShaper::analyticsRow(
				(array) ( $data['totals'] ?? array() ),
				self::TOTALS_KEYS
			),
		);
	}
}
