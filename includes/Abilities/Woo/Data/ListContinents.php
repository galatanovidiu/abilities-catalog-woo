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
 * Read ability: `og-wc-data/list-continents`.
 *
 * Wraps `GET /wc/v3/data/continents` via `rest_do_request()` and returns each
 * continent as a flat summary row carrying its `code`, `name`, and the
 * `countries` on it (each as a `{code,name}` pair). The raw WooCommerce route
 * nests fat per-country locale detail (currency, separators, units, states)
 * under every continent; {@see DataReferenceShaper::continentSummary()} drops
 * that and keeps only the country code and name — the per-country detail is
 * reachable via `og-wc-data/get-country`.
 *
 * Only available when WooCommerce is active (it is a {@see ConditionalAbility}).
 * This continent reference data ships with WooCommerce and is always present.
 * The list route returns a bare array with no pagination headers, so `total`
 * is the number of rows returned.
 *
 * @since 0.1.0
 */
final class ListContinents implements ConditionalAbility {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-wc-data/list-continents';
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
			'label'               => __( 'List Continents', 'abilities-catalog-woo' ),
			'description'         => __( 'Returns WooCommerce\'s continents as flat summary rows, each with its 2-letter code, name, and the list of countries on it (code and name only). Use this to discover continent codes and which countries belong to each; use og-wc-data/get-continent for one continent by code, or og-wc-data/get-country for a country\'s full detail (states and locale). Read-only reference data shipped with WooCommerce.', 'abilities-catalog-woo' ),
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
						'description' => __( 'The continents as flat summary rows. Use og-wc-data/get-continent for a single continent by code.', 'abilities-catalog-woo' ),
						'items'       => DataReferenceShaper::continentItemSchema(),
					),
					'total' => array(
						'type'        => 'integer',
						'description' => __( 'The number of continents returned. WooCommerce\'s continents route exposes no total header, so this counts the returned rows.', 'abilities-catalog-woo' ),
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
	 * Permission check: WooCommerce's settings-manager capability.
	 *
	 * Mirrors the wrapped route, which gates on `wc_rest_check_manager_permissions(
	 * 'settings', 'read' )` → `manage_woocommerce`. This is a coarse, object-
	 * independent guard; the route serves the same static reference data to every
	 * caller who clears it, so there is no object-level decision to defer. The
	 * explicit activity guard keeps the denial clean when WooCommerce is inactive.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may read WooCommerce reference data.
	 */
	public function hasPermission( $input ): bool {
		return WooPlugin::isActive() && current_user_can( 'manage_woocommerce' );
	}

	/**
	 * Executes the ability by dispatching the internal WooCommerce REST request.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The list of continents, or the REST error.
	 */
	public function execute( $input ) {
		if ( ! WooPlugin::isActive() ) {
			return WooPlugin::unavailable();
		}

		$input = is_array( $input ) ? $input : array();

		$request  = new WP_REST_Request( 'GET', '/wc/v3/data/continents' );
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

			$rows[] = DataReferenceShaper::continentSummary( $item );
		}

		return array(
			'items' => $rows,
			'total' => count( $rows ),
		);
	}
}
