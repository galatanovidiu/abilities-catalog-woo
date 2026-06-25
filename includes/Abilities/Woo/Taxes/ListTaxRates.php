<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogWoo\Abilities\Woo\Taxes;

use GalatanOvidiu\AbilitiesCatalogWoo\Contracts\ConditionalAbility;
use GalatanOvidiu\AbilitiesCatalogWoo\Support\RestError;
use GalatanOvidiu\AbilitiesCatalogWoo\Support\TaxRateListShaper;
use GalatanOvidiu\AbilitiesCatalogWoo\Support\WooPlugin;
use WP_REST_Request;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read ability: `og-wc-taxes/list-tax-rates`.
 *
 * Wraps `GET wc/v3/taxes` via `rest_do_request()` and returns each configured tax
 * rate as a flat summary row. The route has NO free-text search; results are
 * filtered by tax class and paged/sorted only. Each row is projected through
 * {@see TaxRateListShaper::summary()}, which drops the raw `postcodes`/`cities`
 * arrays and `order` column the WC schema also carries.
 *
 * Only available when WooCommerce is active (it is a {@see ConditionalAbility}).
 * The `wc/v3/taxes` list route DOES send pagination headers, so `total` is read
 * from `X-WP-Total` (the full matching count) when present, falling back to the
 * number of returned rows.
 *
 * @since 0.1.0
 */
final class ListTaxRates implements ConditionalAbility {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-wc-taxes/list-tax-rates';
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
			'label'               => __( 'List Tax Rates', 'abilities-catalog-woo' ),
			'description'         => __( 'Returns the store\'s WooCommerce tax rates as flat summary rows, each with its id, country, state, rate (a decimal-string percentage), name, priority, compound and shipping flags, and tax class. Filter by tax class to read one class\'s rates; discover class slugs with og-wc-taxes/list-tax-classes. Use og-wc-taxes/get-tax-rate for a single rate. This route has no free-text search.', 'abilities-catalog-woo' ),
			'category'            => 'og-wc-taxes',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'per_page' => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'maximum'     => 100,
						'default'     => 100,
						'description' => __( 'Maximum number of tax rates to return (1-100). Defaults to 100, which covers every rate on a typical store.', 'abilities-catalog-woo' ),
					),
					'page'     => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'default'     => 1,
						'description' => __( 'Page of the result set to return, starting at 1.', 'abilities-catalog-woo' ),
					),
					'offset'   => array(
						'type'        => 'integer',
						'minimum'     => 0,
						'description' => __( 'Number of tax rates to skip before returning results. Overrides page-based paging when set.', 'abilities-catalog-woo' ),
					),
					'order'    => array(
						'type'        => 'string',
						'enum'        => array( 'asc', 'desc' ),
						'default'     => 'asc',
						'description' => __( 'Sort direction: "asc" (ascending) or "desc" (descending).', 'abilities-catalog-woo' ),
					),
					'orderby'  => array(
						'type'        => 'string',
						'enum'        => array( 'id', 'order', 'priority' ),
						'default'     => 'order',
						'description' => __( 'Field to sort by: "id" (rate ID), "order" (the manual display order, the default), or "priority".', 'abilities-catalog-woo' ),
					),
					'class'    => array(
						'type'        => 'string',
						'description' => __( 'Limit results to one tax class by its slug, e.g. standard, reduced-rate, or zero-rate. Discover slugs with og-wc-taxes/list-tax-classes.', 'abilities-catalog-woo' ),
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
						'description' => __( 'The tax rates as flat summary rows. Use og-wc-taxes/get-tax-rate for a single rate.', 'abilities-catalog-woo' ),
						'items'       => TaxRateListShaper::itemSchema(),
					),
					'total' => array(
						'type'        => 'integer',
						'description' => __( 'Total number of tax rates matching the query, read from the X-WP-Total header; falls back to the number of returned rows when the header is absent.', 'abilities-catalog-woo' ),
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
	 * Permission check: WooCommerce's manager capability for tax settings.
	 *
	 * Mirrors the wrapped route: `WC_REST_Taxes_Controller` gates reads on
	 * `wc_rest_check_manager_permissions( 'settings', 'read' )`, which resolves to
	 * `manage_woocommerce`. This is a coarse, object-independent guard; the route
	 * itself enforces nothing object-level for a list. The activity guard keeps the
	 * denial clean when WooCommerce is inactive (the cap would be unmapped).
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may read tax rates.
	 */
	public function hasPermission( $input ): bool {
		return WooPlugin::isActive() && current_user_can( 'manage_woocommerce' );
	}

	/**
	 * Executes the ability by dispatching the internal `wc/v3` REST request.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The list of tax rates, or the REST error.
	 */
	public function execute( $input ) {
		if ( ! WooPlugin::isActive() ) {
			return WooPlugin::unavailable();
		}

		$input = is_array( $input ) ? $input : array();

		$request = new WP_REST_Request( 'GET', '/wc/v3/taxes' );
		$request->set_param( 'per_page', max( 1, min( 100, absint( $input['per_page'] ?? 100 ) ) ) );
		$request->set_param( 'page', max( 1, absint( $input['page'] ?? 1 ) ) );
		if ( isset( $input['offset'] ) ) {
			$request->set_param( 'offset', absint( $input['offset'] ) );
		}
		$request->set_param( 'order', 'desc' === strtolower( (string) ( $input['order'] ?? 'asc' ) ) ? 'desc' : 'asc' );
		$request->set_param( 'orderby', (string) ( $input['orderby'] ?? 'order' ) );
		if ( ! empty( $input['class'] ) ) {
			$request->set_param( 'class', (string) $input['class'] );
		}

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

			$rows[] = TaxRateListShaper::summary( $item );
		}

		$headers = $response->get_headers();
		$total   = isset( $headers['X-WP-Total'] ) ? (int) $headers['X-WP-Total'] : count( $rows );

		return array(
			'items' => $rows,
			'total' => $total,
		);
	}
}
