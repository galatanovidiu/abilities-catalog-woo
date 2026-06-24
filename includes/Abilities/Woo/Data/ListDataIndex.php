<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogWoo\Abilities\Woo\Data;

use GalatanOvidiu\AbilitiesCatalogWoo\Contracts\ConditionalAbility;
use GalatanOvidiu\AbilitiesCatalogWoo\Support\RestError;
use GalatanOvidiu\AbilitiesCatalogWoo\Support\WooPlugin;
use WP_REST_Request;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read ability: `wc-data/list-data-index`.
 *
 * Wraps `GET wc/v3/data` via `rest_do_request()` and returns the index of
 * WooCommerce's static reference-data resources, each as a flat row with its
 * `slug`, a human `description`, and the `endpoint` URL that lists or reads it.
 * The index always returns three rows: `continents`, `countries`, and
 * `currencies`. It is the discovery entry point for the rest of the `wc-data`
 * abilities.
 *
 * The route body carries only `{ slug, description }`; the resource URL lives in
 * each row's HAL `_links.self.href`. `rest_get_server()->response_to_data(
 * $response, false )` KEEPS `_links` (the `false` only skips `_embedded`
 * resolution; `WP_REST_Server::response_to_data()` adds the compact `_links`
 * regardless, and `prepare_response_for_collection()` has already baked each
 * item's links into the row), so this lifts `_links.self[0].href` into a flat
 * `endpoint` string per row. As a defensive fallback — if a future WP build
 * strips per-item links — it rebuilds the URL as `rest_url( 'wc/v3/data/' . slug
 * )`, which is the exact URL the controller's `prepare_links()` emits.
 *
 * Only available when WooCommerce is active (it is a {@see ConditionalAbility}).
 * The `wc/v3/data` index route returns a bare array with no pagination headers,
 * so `total` is the number of rows returned.
 *
 * @since 0.1.0
 */
final class ListDataIndex implements ConditionalAbility {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'wc-data/list-data-index';
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
			'label'               => __( 'List Data Index', 'abilities-catalog-woo' ),
			'description'         => __( 'Returns the index of WooCommerce reference-data resources: three rows (continents, countries, currencies), each with its slug, a short description, and the endpoint URL that lists it. Use this to discover the wc-data reference surface, then read each resource with wc-data/list-continents, wc-data/list-countries, or wc-data/list-currencies. Read-only: it lists the resources, not their contents.', 'abilities-catalog-woo' ),
			'category'            => 'wc-data',
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
						'description' => __( 'The reference-data resources as flat rows. Read each resource with its matching wc-data list ability (e.g. the "currencies" row maps to wc-data/list-currencies).', 'abilities-catalog-woo' ),
						'items'       => array(
							'type'                 => 'object',
							'required'             => array( 'slug', 'endpoint' ),
							'properties'           => array(
								'slug'        => array(
									'type'        => 'string',
									'description' => __( 'The resource ID, one of "continents", "countries", or "currencies".', 'abilities-catalog-woo' ),
								),
								'description' => array(
									'type'        => 'string',
									'description' => __( 'A human-readable description of the resource.', 'abilities-catalog-woo' ),
								),
								'endpoint'    => array(
									'type'        => 'string',
									'description' => __( 'The REST URL that lists this resource (e.g. .../wc/v3/data/currencies).', 'abilities-catalog-woo' ),
								),
							),
							'additionalProperties' => false,
						),
					),
					'total' => array(
						'type'        => 'integer',
						'description' => __( 'The number of resource rows returned. This index has no total header, so it counts the returned rows (always 3).', 'abilities-catalog-woo' ),
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
	 * Permission check: WooCommerce's manage capability.
	 *
	 * Mirrors the wrapped route, which gates on
	 * `wc_rest_check_manager_permissions( 'settings', 'read' )` →
	 * `manage_woocommerce`. This is the coarse, object-independent baseline; the
	 * index has no object-level surface to defer. The activity guard keeps the
	 * denial clean when WooCommerce is inactive.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may read WooCommerce data resources.
	 */
	public function hasPermission( $input ): bool {
		return WooPlugin::isActive() && current_user_can( 'manage_woocommerce' );
	}

	/**
	 * Executes the ability by dispatching the internal WooCommerce REST request.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The data index, or the REST error.
	 */
	public function execute( $input ) {
		if ( ! WooPlugin::isActive() ) {
			return WooPlugin::unavailable();
		}

		$input = is_array( $input ) ? $input : array();

		$request  = new WP_REST_Request( 'GET', '/wc/v3/data' );
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

			$slug     = (string) ( $item['slug'] ?? '' );
			$endpoint = isset( $item['_links']['self'][0]['href'] )
				? (string) $item['_links']['self'][0]['href']
				: rest_url( 'wc/v3/data/' . $slug );

			$rows[] = array(
				'slug'        => $slug,
				'description' => (string) ( $item['description'] ?? '' ),
				'endpoint'    => $endpoint,
			);
		}

		return array(
			'items' => $rows,
			'total' => count( $rows ),
		);
	}
}
