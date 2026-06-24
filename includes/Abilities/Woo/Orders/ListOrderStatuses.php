<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogWoo\Abilities\Woo\Orders;

use GalatanOvidiu\AbilitiesCatalogWoo\Contracts\ConditionalAbility;
use GalatanOvidiu\AbilitiesCatalogWoo\Support\RestError;
use GalatanOvidiu\AbilitiesCatalogWoo\Support\WooPlugin;
use WP_REST_Request;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read ability: `wc-orders/list-order-statuses`.
 *
 * Wraps `GET wc/v3/orders/statuses` via `rest_do_request()` and returns every
 * order status WooCommerce recognises as a flat `{ slug, name }` row. The slug is
 * the value `wc-orders/list-orders` accepts in its `status` filter (the `wc-`
 * prefix is already stripped, e.g. `processing`); the name is the human-readable
 * label. This is the discovery step that tells a consumer which statuses exist
 * before filtering or interpreting orders.
 *
 * The wrapped route is public (`permission_callback => __return_true`), but this
 * ability still gates on `read_private_shop_orders`: it sits in the PII-bearing
 * orders domain and has no stricter requirement, so it stays consistent with the
 * other orders reads.
 *
 * Only available when WooCommerce is active (it is a {@see ConditionalAbility}).
 * The route returns a bare array with no pagination header, so `total` is the
 * number of statuses returned.
 *
 * @since 0.1.0
 */
final class ListOrderStatuses implements ConditionalAbility {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'wc-orders/list-order-statuses';
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
			'label'               => __( 'List Order Statuses', 'abilities-catalog-woo' ),
			'description'         => __( 'Returns every order status WooCommerce recognises as flat { slug, name } rows, plus the total count. The slug is the value to pass to wc-orders/list-orders\'s status filter (the wc- prefix is already stripped, e.g. "processing", "completed"); the name is the human-readable label. Read-only discovery step: use it to learn the valid status slugs before filtering or interpreting orders. Includes any statuses a plugin has registered, not just the core seven.', 'abilities-catalog-woo' ),
			'category'            => 'wc-orders',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => (object) array(),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'statuses', 'total' ),
				'properties'           => array(
					'statuses' => array(
						'type'        => 'array',
						'description' => __( 'The order statuses as flat rows. Pass a row\'s slug to wc-orders/list-orders to filter orders by that status.', 'abilities-catalog-woo' ),
						'items'       => array(
							'type'                 => 'object',
							'required'             => array( 'slug', 'name' ),
							'properties'           => array(
								'slug' => array(
									'type'        => 'string',
									'description' => __( 'The status slug with the wc- prefix stripped, e.g. "processing". This is the value the wc-orders/list-orders status filter accepts.', 'abilities-catalog-woo' ),
								),
								'name' => array(
									'type'        => 'string',
									'description' => __( 'The human-readable status label, e.g. "Processing".', 'abilities-catalog-woo' ),
								),
							),
							'additionalProperties' => false,
						),
					),
					'total'    => array(
						'type'        => 'integer',
						'description' => __( 'The number of statuses returned. This route exposes no total header, so it counts the returned rows.', 'abilities-catalog-woo' ),
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
	 * Permission check: WooCommerce's private-orders read capability.
	 *
	 * The wrapped `/wc/v3/orders/statuses` route is public
	 * (`permission_callback => __return_true`), but this ability lives in the
	 * PII-bearing orders domain. It has no stricter requirement of its own, so it
	 * gates on `read_private_shop_orders` — the same cap every orders read uses —
	 * keeping the surface consistent rather than wider. The activity guard keeps
	 * the denial clean when WooCommerce is inactive and the capability is unmapped.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may read orders.
	 */
	public function hasPermission( $input ): bool {
		return WooPlugin::isActive() && current_user_can( 'read_private_shop_orders' );
	}

	/**
	 * Executes the ability by dispatching the internal WooCommerce REST request.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The order statuses and total, or the REST error.
	 */
	public function execute( $input ) {
		if ( ! WooPlugin::isActive() ) {
			return WooPlugin::unavailable();
		}

		$input = is_array( $input ) ? $input : array();

		$request  = new WP_REST_Request( 'GET', '/wc/v3/orders/statuses' );
		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			return RestError::from( $response );
		}

		$data = rest_get_server()->response_to_data( $response, false );

		$statuses = array();
		foreach ( is_array( $data ) ? $data : array() as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$statuses[] = array(
				'slug' => (string) ( $row['slug'] ?? '' ),
				'name' => (string) ( $row['name'] ?? '' ),
			);
		}

		return array(
			'statuses' => $statuses,
			'total'    => count( $statuses ),
		);
	}
}
