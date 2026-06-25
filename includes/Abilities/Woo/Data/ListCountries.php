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
 * Read ability: `og-wc-data/list-countries`.
 *
 * Wraps `GET /wc/v3/data/countries` via `rest_do_request()` and returns every
 * country WooCommerce knows about as a flat summary row, each with its ISO-3166
 * alpha-2 `code`, full `name`, and `states` (its provinces/regions as `{code,name}`
 * pairs). The list route maps each country through the same `get_country()`
 * projection the single-resource route uses, so a listed row carries the same
 * shape `og-wc-data/get-country` returns — there is no thinner-row/fatter-row split
 * here, and a consumer rarely needs the by-code call.
 *
 * Only available when WooCommerce is active (it is a {@see ConditionalAbility}).
 * The WC data/countries list route returns a bare array via `rest_ensure_response()`
 * with no `X-WP-Total` header, so `total` is the number of rows returned. This is
 * the full, fixed country table WooCommerce ships, so that count is exhaustive.
 *
 * @since 0.1.0
 */
final class ListCountries implements ConditionalAbility {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-wc-data/list-countries';
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
			'label'               => __( 'List Countries', 'abilities-catalog-woo' ),
			'description'         => __( 'Returns every country WooCommerce supports as flat rows, each with its ISO-3166 alpha-2 code, full name, and states (the country\'s provinces or regions as code/name pairs). Use this to discover the country code an order, customer, or shipping zone needs, and the state codes valid within a country. This is WooCommerce\'s static country table, not the store\'s selling/shipping locations. Use og-wc-data/get-country to look up one country by code.', 'abilities-catalog-woo' ),
			'category'            => 'og-wc-data',
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
						'description' => __( 'The countries as flat summary rows. Use og-wc-data/get-country for a single country by code.', 'abilities-catalog-woo' ),
						'items'       => DataReferenceShaper::countryItemSchema(),
					),
					'total' => array(
						'type'        => 'integer',
						'description' => __( 'The number of countries returned. The WooCommerce data/countries route sends no total header, so this counts the returned rows; it is the full fixed country table.', 'abilities-catalog-woo' ),
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
	 * Permission check: WooCommerce's settings-read capability.
	 *
	 * Mirrors the wrapped route, which gates on
	 * `wc_rest_check_manager_permissions('settings', 'read')` → `manage_woocommerce`.
	 * This is a coarse, object-independent guard; the country table is static so
	 * there is no object-level decision to defer. The explicit activity guard keeps
	 * the denial clean when WooCommerce is inactive.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may read WooCommerce reference data.
	 */
	public function hasPermission( $input ): bool {
		return WooPlugin::isActive() && current_user_can( 'manage_woocommerce' );
	}

	/**
	 * Executes the ability by dispatching the internal WC REST request.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The list of countries, or the REST error.
	 */
	public function execute( $input ) {
		if ( ! WooPlugin::isActive() ) {
			return WooPlugin::unavailable();
		}

		$input = is_array( $input ) ? $input : array();

		$request  = new WP_REST_Request( 'GET', '/wc/v3/data/countries' );
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

			$rows[] = DataReferenceShaper::countrySummary( $item );
		}

		return array(
			'items' => $rows,
			'total' => count( $rows ),
		);
	}
}
