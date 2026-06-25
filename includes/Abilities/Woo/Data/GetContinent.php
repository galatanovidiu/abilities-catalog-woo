<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogWoo\Abilities\Woo\Data;

use GalatanOvidiu\AbilitiesCatalogWoo\Contracts\ConditionalAbility;
use GalatanOvidiu\AbilitiesCatalogWoo\Support\DataReferenceShaper;
use GalatanOvidiu\AbilitiesCatalogWoo\Support\RestError;
use GalatanOvidiu\AbilitiesCatalogWoo\Support\WooPlugin;
use WP_REST_Request;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read ability: `og-wc-data/get-continent`.
 *
 * Wraps `GET wc/v3/data/continents/<code>` via `rest_do_request()` and returns one
 * continent from WooCommerce's static reference data as a flat, closed record: its
 * 2-letter code, full name, and the countries on it (each as a `{code,name}` pair).
 * This data ships with WooCommerce and is always present.
 *
 * The raw `wc/v3` continent row nests fat country objects carrying per-country
 * locale detail (currency, separators, units) plus a `states` list; this projects
 * only the minimal stable `{code,name}` per country via
 * {@see DataReferenceShaper::continentSummary()}. Read a country's full detail
 * (states, locale) with `og-wc-data/get-country`.
 *
 * Only available when WooCommerce is active (it is a {@see ConditionalAbility}).
 *
 * @since 0.1.0
 */
final class GetContinent implements ConditionalAbility {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-wc-data/get-continent';
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
			'label'               => __( 'Get Continent', 'abilities-catalog-woo' ),
			'description'         => __( 'Returns one continent from WooCommerce\'s reference data by its 2-letter code: the continent name and the countries on it (each as a code and name). Use this to resolve a continent code to its name or to list the countries WooCommerce groups under it; for a country\'s own detail (states, locale) use og-wc-data/get-country instead. Discover continent codes with og-wc-data/list-continents.', 'abilities-catalog-woo' ),
			'category'            => 'og-wc-data',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'code' ),
				'properties'           => array(
					'code' => array(
						'type'        => 'string',
						'description' => __( 'The 2-letter continent code, e.g. NA for North America. Case-insensitive (WooCommerce uppercases it). Discover codes with og-wc-data/list-continents.', 'abilities-catalog-woo' ),
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => DataReferenceShaper::continentItemSchema(),
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
	 * Permission check: WooCommerce's shop-manager capability.
	 *
	 * Mirrors `wc_rest_check_manager_permissions( 'settings', 'read' )`, which the
	 * wrapped `wc/v3/data/continents/<code>` GET route enforces — it resolves to
	 * `manage_woocommerce`, the uniform baseline for the whole `wc/v3/data`
	 * reference surface. This is a coarse, object-INDEPENDENT type-level guard: the
	 * per-code decision is deferred to the wrapped route, so an unknown continent
	 * code surfaces its specific `woocommerce_rest_data_invalid_location` 404 via
	 * {@see RestError::from()} instead of collapsing to a generic permission denial.
	 * The explicit activity guard keeps the denial clean when WooCommerce is
	 * inactive.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may read WooCommerce reference data.
	 */
	public function hasPermission( $input ): bool {
		return WooPlugin::isActive() && current_user_can( 'manage_woocommerce' );
	}

	/**
	 * Executes the ability by dispatching the internal `wc/v3` REST request.
	 *
	 * The continent code is uppercased and concatenated into the route path; the
	 * detail route's `location` parameter is bound from that URL segment, and the
	 * controller uppercases it again, so a lowercase input still resolves.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The shaped continent record, or the REST
	 *                                        error (e.g. `woocommerce_rest_data_invalid_location` 404).
	 */
	public function execute( $input ) {
		if ( ! WooPlugin::isActive() ) {
			return WooPlugin::unavailable();
		}

		$input = is_array( $input ) ? $input : array();
		$code  = strtoupper( trim( (string) ( $input['code'] ?? '' ) ) );

		$request  = new WP_REST_Request( 'GET', '/wc/v3/data/continents/' . $code );
		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			return RestError::from( $response );
		}

		$data = rest_get_server()->response_to_data( $response, false );
		$data = is_array( $data ) ? $data : array();

		return DataReferenceShaper::continentSummary( $data );
	}
}
