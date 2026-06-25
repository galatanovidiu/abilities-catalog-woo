<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogWoo\Abilities\Woo\Products;

use GalatanOvidiu\AbilitiesCatalogWoo\Contracts\ConditionalAbility;
use GalatanOvidiu\AbilitiesCatalogWoo\Support\RestError;
use GalatanOvidiu\AbilitiesCatalogWoo\Support\WooPlugin;
use WP_REST_Request;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read ability: `og-wc-products/list-product-custom-field-names`.
 *
 * Wraps `GET wc/v3/products/custom-fields/names` via `rest_do_request()`. The WC
 * route returns a bare array of distinct, non-private (`meta_key` not starting with
 * `_`) custom-field key strings used across products — not the values, and not
 * per-product. This ability is the discovery step that tells a consumer which
 * custom-field keys exist before reading or writing a single product's `meta_data`.
 *
 * Only available when WooCommerce is active (it is a {@see ConditionalAbility}).
 *
 * @since 0.1.0
 */
final class ListProductCustomFieldNames implements ConditionalAbility {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-wc-products/list-product-custom-field-names';
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
			'label'               => __( 'List Product Custom Field Names', 'abilities-catalog-woo' ),
			'description'         => __( 'Returns the distinct custom-field key names used across products as a flat list of strings, plus the total matching count. These are the public (non-underscore-prefixed) meta keys WooCommerce stores against products. This is a read-only discovery step: it returns key names only, not their values and not per-product data. It does not edit anything — a single product\'s custom fields are read and written through that product\'s meta_data field (e.g. with og-wc-products/get-product), not here.', 'abilities-catalog-woo' ),
			'category'            => 'og-wc-products',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'search'   => array(
						'type'        => 'string',
						'description' => __( 'Limit results to custom-field names that contain this substring.', 'abilities-catalog-woo' ),
					),
					'per_page' => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'maximum'     => 100,
						'default'     => 100,
						'description' => __( 'Maximum number of names to return (1-100). Defaults to 100, which covers every custom-field name on a typical store.', 'abilities-catalog-woo' ),
					),
					'page'     => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'default'     => 1,
						'description' => __( 'The page of results to return, for paging past the first per_page names. Use total to tell whether more pages exist.', 'abilities-catalog-woo' ),
					),
					'order'    => array(
						'type'        => 'string',
						'enum'        => array( 'asc', 'desc' ),
						'default'     => 'asc',
						'description' => __( 'Sort direction by name: "asc" (A-Z) or "desc" (Z-A).', 'abilities-catalog-woo' ),
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'names', 'total' ),
				'properties'           => array(
					'names' => array(
						'type'        => 'array',
						'description' => __( 'The custom-field key names as plain strings. Use one as a key in a product\'s meta_data to read or write that custom field.', 'abilities-catalog-woo' ),
						'items'       => array(
							'type' => 'string',
						),
					),
					'total' => array(
						'type'        => 'integer',
						'description' => __( 'The total number of custom-field names matching the query across all pages, from the X-WP-Total header. May exceed the number of names returned when paging.', 'abilities-catalog-woo' ),
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
	 * Permission check: WooCommerce's product read capability.
	 *
	 * Mirrors the wrapped route, which gates on
	 * `wc_rest_check_post_permissions( 'product', 'read' )` — i.e.
	 * `read_private_products`. That is the minimum required to run the read and is
	 * not weaker than the route's own check. The activity guard keeps the denial
	 * clean when WooCommerce is inactive and the capability is unmapped.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may read product custom-field names.
	 */
	public function hasPermission( $input ): bool {
		return WooPlugin::isActive() && current_user_can( 'read_private_products' );
	}

	/**
	 * Executes the ability by dispatching the internal WooCommerce REST request.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The custom-field names and total, or the REST error.
	 */
	public function execute( $input ) {
		if ( ! WooPlugin::isActive() ) {
			return WooPlugin::unavailable();
		}

		$input = is_array( $input ) ? $input : array();

		$request = new WP_REST_Request( 'GET', '/wc/v3/products/custom-fields/names' );
		// Always set search: the WC route reads $request['search'] unconditionally,
		// so passing an empty string avoids a trim(null) deprecation when omitted.
		$request->set_param( 'search', (string) ( $input['search'] ?? '' ) );
		$request->set_param( 'per_page', max( 1, min( 100, absint( $input['per_page'] ?? 100 ) ) ) );
		$request->set_param( 'page', max( 1, absint( $input['page'] ?? 1 ) ) );
		$request->set_param( 'order', 'desc' === strtolower( (string) ( $input['order'] ?? 'asc' ) ) ? 'desc' : 'asc' );

		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			return RestError::from( $response );
		}

		$data = rest_get_server()->response_to_data( $response, false );

		$names = array();
		foreach ( is_array( $data ) ? $data : array() as $name ) {
			if ( ! is_string( $name ) ) {
				continue;
			}
			$names[] = $name;
		}

		$headers = $response->get_headers();
		$total   = isset( $headers['X-WP-Total'] ) ? (int) $headers['X-WP-Total'] : count( $names );

		return array(
			'names' => $names,
			'total' => $total,
		);
	}
}
