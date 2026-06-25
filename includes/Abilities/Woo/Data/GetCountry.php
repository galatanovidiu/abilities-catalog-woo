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
 * Read ability: `og-wc-data/get-country`.
 *
 * Wraps `GET wc/v3/data/countries/<code>` via `rest_do_request()` and returns one
 * country from WooCommerce's static reference tables as a flat, closed row — its
 * code, full name, and the list of states/provinces/regions (each `{code,name}`) —
 * through {@see DataReferenceShaper::countrySummary()}. This is the same shape a
 * `og-wc-data/list-countries` row carries, so a consumer can look up one country
 * without scanning the whole list.
 *
 * The route's code param is named `location`; this ability accepts a `code` input
 * and maps it onto that param. The route uppercases the code internally, so a
 * lowercase code is accepted, but the canonical form is uppercase ISO-3166 alpha-2.
 *
 * Only available when WooCommerce is active (it is a {@see ConditionalAbility}).
 *
 * @since 0.1.0
 */
final class GetCountry implements ConditionalAbility {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-wc-data/get-country';
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
			'label'               => __( 'Get Country', 'abilities-catalog-woo' ),
			'description'         => __( 'Returns one WooCommerce country from the static reference tables by its code: the country code, full name, and its states, provinces, or regions (each as a code and name). Use this to resolve a country code to its name or to list its states (e.g. for an order address). The code is an ISO-3166 alpha-2 string such as US; discover valid codes with og-wc-data/list-countries. Returns a woocommerce_rest_data_invalid_location 404 for an unknown code.', 'abilities-catalog-woo' ),
			'category'            => 'og-wc-data',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'code' ),
				'properties'           => array(
					'code' => array(
						'type'        => 'string',
						'description' => __( 'The ISO-3166 alpha-2 country code, e.g. US (case-insensitive; the canonical form is uppercase). Discover valid codes with og-wc-data/list-countries.', 'abilities-catalog-woo' ),
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => DataReferenceShaper::countryItemSchema(),
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
	 * Permission check: WooCommerce's settings read capability.
	 *
	 * Mirrors `wc_rest_check_manager_permissions( 'settings', 'read' )`, which the
	 * wrapped `wc/v3/data/countries/<code>` GET route enforces and which resolves to
	 * `manage_woocommerce`. This is a coarse, object-INDEPENDENT guard: the country
	 * code does not vary the capability, and the per-code decision is deferred to the
	 * wrapped route, so an unknown code surfaces its specific
	 * `woocommerce_rest_data_invalid_location` 404 via {@see RestError::from()}
	 * instead of collapsing to a generic permission denial. The explicit activity
	 * guard keeps the denial clean when WooCommerce is inactive.
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
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The shaped country row, or the REST error
	 *                                        (`woocommerce_rest_data_invalid_location` 404
	 *                                        for an unknown code).
	 */
	public function execute( $input ) {
		if ( ! WooPlugin::isActive() ) {
			return WooPlugin::unavailable();
		}

		$input = is_array( $input ) ? $input : array();
		$code  = (string) ( $input['code'] ?? '' );

		$request = new WP_REST_Request( 'GET', '/wc/v3/data/countries/' . $code );
		$request->set_param( 'location', $code );

		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			return RestError::from( $response );
		}

		$data = rest_get_server()->response_to_data( $response, false );
		$data = is_array( $data ) ? $data : array();

		return DataReferenceShaper::countrySummary( $data );
	}
}
