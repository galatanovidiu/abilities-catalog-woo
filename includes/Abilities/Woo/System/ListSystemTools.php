<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogWoo\Abilities\Woo\System;

use GalatanOvidiu\AbilitiesCatalogWoo\Contracts\ConditionalAbility;
use GalatanOvidiu\AbilitiesCatalogWoo\Support\RestError;
use GalatanOvidiu\AbilitiesCatalogWoo\Support\SystemToolListShaper;
use GalatanOvidiu\AbilitiesCatalogWoo\Support\WooPlugin;
use WP_REST_Request;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read ability: `og-wc-system/list-system-tools`.
 *
 * Wraps `GET wc/v3/system_status/tools` via `rest_do_request()` and returns every
 * WooCommerce maintenance tool as a flat `{ id, name, action, description }` row
 * (see {@see SystemToolListShaper}). The `action` is the button label — what
 * running the tool would do.
 *
 * This ability only LISTS the tools so a consumer can discover their ids and read
 * what each does; it does NOT run any of them. Running a tool is the deferred
 * dangerous `og-wc-system/run-system-tool` ability (not built), because several tools
 * are irreversible (e.g. delete_taxes, reset_roles, db_update_routine).
 *
 * Only available when WooCommerce is active (it is a {@see ConditionalAbility}).
 * The route returns a bare array via `rest_ensure_response()` with no pagination
 * header, so `total` is the number of tools returned — which always coincides with
 * the full count, since the tool list is fixed and unpaged.
 *
 * @since 0.1.0
 */
final class ListSystemTools implements ConditionalAbility {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-wc-system/list-system-tools';
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
			'label'               => __( 'List System Tools', 'abilities-catalog-woo' ),
			'description'         => __( 'Returns every WooCommerce maintenance tool as flat { id, name, action, description } rows, plus the total count. The action is the button label — what running the tool would do (e.g. clear_transients, clear_expired_transients, regenerate_thumbnails). Read-only discovery step: use it to learn which tools exist and their ids, then read one with og-wc-system/get-system-tool. This ability only LISTS the tools; running one is the separate, deferred og-wc-system/run-system-tool ability, because several tools are irreversible. This route is unpaged, so total always equals the number of returned rows.', 'abilities-catalog-woo' ),
			'category'            => 'og-wc-system',
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
						'description' => __( 'The maintenance tools as flat rows. Read one tool with og-wc-system/get-system-tool; run one with the deferred og-wc-system/run-system-tool.', 'abilities-catalog-woo' ),
						'items'       => SystemToolListShaper::itemSchema(),
					),
					'total' => array(
						'type'        => 'integer',
						'description' => __( 'The number of tools returned. This route exposes no total header and is unpaged, so this count equals the full list size.', 'abilities-catalog-woo' ),
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
	 * Permission check: WooCommerce's manager capability.
	 *
	 * The wrapped `/wc/v3/system_status/tools` route gates its read on
	 * `wc_rest_check_manager_permissions( 'system_status', 'read' )`, which resolves
	 * to `manage_woocommerce`. This coarse, object-independent guard mirrors that
	 * exactly and is never weaker than the route. The activity guard keeps the denial
	 * clean when WooCommerce is inactive and the capability is unmapped.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may read the system-status tools.
	 */
	public function hasPermission( $input ): bool {
		return WooPlugin::isActive() && current_user_can( 'manage_woocommerce' );
	}

	/**
	 * Executes the ability by dispatching the internal WooCommerce REST request.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The list of tools and total, or the REST error.
	 */
	public function execute( $input ) {
		if ( ! WooPlugin::isActive() ) {
			return WooPlugin::unavailable();
		}

		$input = is_array( $input ) ? $input : array();

		$request  = new WP_REST_Request( 'GET', '/wc/v3/system_status/tools' );
		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			return RestError::from( $response );
		}

		$data = rest_get_server()->response_to_data( $response, false );

		$rows = array();
		foreach ( is_array( $data ) ? $data : array() as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$rows[] = SystemToolListShaper::summary( $row );
		}

		return array(
			'items' => $rows,
			'total' => count( $rows ),
		);
	}
}
