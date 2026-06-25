<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogWoo\Abilities\Woo\System;

use GalatanOvidiu\AbilitiesCatalogWoo\Contracts\ConditionalAbility;
use GalatanOvidiu\AbilitiesCatalogWoo\Support\RestError;
use GalatanOvidiu\AbilitiesCatalogWoo\Support\SystemStatusShaper;
use GalatanOvidiu\AbilitiesCatalogWoo\Support\WooPlugin;
use WP_REST_Request;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read ability: `og-wc-system/get-system-status`.
 *
 * Wraps `GET wc/v3/system_status` via `rest_do_request()` and returns a small,
 * curated store-health summary through {@see SystemStatusShaper::subset()} — NOT
 * the full report. The raw route payload is a large information-disclosure document
 * (it carries `home_url`, `site_url`, `store_id`, `log_directory`, full server
 * paths, the complete `database_tables` map, and the entire active/inactive plugin
 * lists — a precise fingerprint of the install). The shaper reads only a fixed
 * allow-list of keys, so this read physically cannot leak those fields: it exposes
 * WooCommerce and WordPress versions, PHP/server facts, the database schema version
 * plus a table count, the active theme, an active-plugin count, and the store's
 * security posture. For the complete report, use the WooCommerce wp-admin System
 * Status screen (WooCommerce > Status).
 *
 * Only available when WooCommerce is active (it is a {@see ConditionalAbility}).
 *
 * @since 0.1.0
 */
final class GetSystemStatus implements ConditionalAbility {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-wc-system/get-system-status';
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
			'label'               => __( 'Get System Status', 'abilities-catalog-woo' ),
			'description'         => __( 'Returns a curated WooCommerce store-health summary: the WooCommerce and WordPress versions, PHP version, web server, WordPress memory limit and debug mode, the WooCommerce database schema version and table count, the active theme (name, version, whether it is a child theme), how many plugins are active, and the store security posture (HTTPS, hidden PHP errors). This is a small health subset, NOT the full System Status report: it deliberately omits the store URLs, store id, log directory, the full plugin lists, the settings block, and the database table map. For the complete report, use the WooCommerce wp-admin System Status screen (WooCommerce > Status). Read-only; takes no input.', 'abilities-catalog-woo' ),
			'category'            => 'og-wc-system',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => (object) array(),
				'additionalProperties' => false,
			),
			'output_schema'       => SystemStatusShaper::schema(),
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
	 * Mirrors the wrapped route, which gates on
	 * `wc_rest_check_manager_permissions( 'system_status', 'read' )` — that maps the
	 * `system_status` object to `manage_woocommerce` and ignores the context
	 * (wc-rest-functions.php; class-wc-rest-system-status-v2-controller.php:104-105).
	 * So `manage_woocommerce` is the minimum required to run the read and is not
	 * weaker than the route's own check. This is a coarse, object-independent guard.
	 * The activity guard keeps the denial clean when WooCommerce is inactive and the
	 * capability is unmapped.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may read the system status.
	 */
	public function hasPermission( $input ): bool {
		return WooPlugin::isActive() && current_user_can( 'manage_woocommerce' );
	}

	/**
	 * Executes the ability by dispatching the internal WooCommerce REST request.
	 *
	 * Dispatches in the default `view` context and passes the full REST payload only
	 * INTO {@see SystemStatusShaper::subset()}, returning ONLY the shaper's curated
	 * subset — never the raw payload.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The curated store-health subset, or the REST error.
	 */
	public function execute( $input ) {
		if ( ! WooPlugin::isActive() ) {
			return WooPlugin::unavailable();
		}

		$input = is_array( $input ) ? $input : array();

		$request  = new WP_REST_Request( 'GET', '/wc/v3/system_status' );
		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			return RestError::from( $response );
		}

		$data = rest_get_server()->response_to_data( $response, false );

		return SystemStatusShaper::subset( is_array( $data ) ? $data : array() );
	}
}
