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
 * Read ability: `og-wc-system/get-system-tool`.
 *
 * Wraps `GET wc/v3/system_status/tools/<id>` via `rest_do_request()` and returns
 * one WooCommerce maintenance tool as a flat row ({id,name,action,description}),
 * shaped through {@see SystemToolListShaper}. The `action` is the button label —
 * what running the tool would do. This is a READ only: it describes a tool but
 * does not run it. RUNNING a tool is the deferred dangerous ability
 * `og-wc-system/run-system-tool` (several tools are irreversible, e.g. `delete_taxes`,
 * `reset_roles`), which is not part of this read.
 *
 * The tool `id` is a STRING path segment (e.g. `clear_transients`), so the route
 * is built by concatenation; the wrapped route surfaces the specific
 * `woocommerce_rest_system_status_tool_invalid_id` 404 for an unknown id.
 *
 * Only available when WooCommerce is active (it is a {@see ConditionalAbility}).
 *
 * @since 0.1.0
 */
final class GetSystemTool implements ConditionalAbility {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-wc-system/get-system-tool';
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
			'label'               => __( 'Get System Tool', 'abilities-catalog-woo' ),
			'description'         => __( 'Returns one WooCommerce maintenance tool by its string id (e.g. "clear_transients"): its id, name, action (the button label describing what running it would do), and description. Discover tool ids with og-wc-system/list-system-tools. This read only describes the tool; it does not run it. To run a tool, use the deferred dangerous og-wc-system/run-system-tool ability, which is separate because several tools are irreversible.', 'abilities-catalog-woo' ),
			'category'            => 'og-wc-system',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'id' ),
				'properties'           => array(
					'id' => array(
						'type'        => 'string',
						'minLength'   => 1,
						'description' => __( 'The tool id, a string slug such as "clear_transients". Discover ids with og-wc-system/list-system-tools.', 'abilities-catalog-woo' ),
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => SystemToolListShaper::itemSchema(),
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
	 * The wrapped `system_status/tools` GET route gates on
	 * `wc_rest_check_manager_permissions( 'system_status', 'read' )`, which resolves
	 * to `manage_woocommerce`. This coarse type-level guard mirrors that; the wrapped
	 * route surfaces the specific `woocommerce_rest_system_status_tool_invalid_id` 404
	 * for an unknown tool id, so an object-level check here would only mask it.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may read WooCommerce system-status tools.
	 */
	public function hasPermission( $input ): bool {
		return WooPlugin::isActive() && current_user_can( 'manage_woocommerce' );
	}

	/**
	 * Executes the ability by dispatching the internal WooCommerce REST request.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The shaped tool row, or the REST error.
	 */
	public function execute( $input ) {
		if ( ! WooPlugin::isActive() ) {
			return WooPlugin::unavailable();
		}

		$input = is_array( $input ) ? $input : array();
		$id    = sanitize_key( (string) ( $input['id'] ?? '' ) );

		$request  = new WP_REST_Request( 'GET', '/wc/v3/system_status/tools/' . $id );
		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			return RestError::from( $response );
		}

		$data = rest_get_server()->response_to_data( $response, false );

		return SystemToolListShaper::summary( is_array( $data ) ? $data : array() );
	}
}
