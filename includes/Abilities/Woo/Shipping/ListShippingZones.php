<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogWoo\Abilities\Woo\Shipping;

use GalatanOvidiu\AbilitiesCatalogWoo\Contracts\ConditionalAbility;
use GalatanOvidiu\AbilitiesCatalogWoo\Support\RestError;
use GalatanOvidiu\AbilitiesCatalogWoo\Support\ShippingZoneListShaper;
use GalatanOvidiu\AbilitiesCatalogWoo\Support\WooPlugin;
use WP_REST_Request;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read ability: `wc-shipping/list-shipping-zones`.
 *
 * Wraps `GET wc/v3/shipping/zones` via `rest_do_request()` and returns each
 * shipping zone as a flat summary row carrying its `id`, `name`, and `order`,
 * shaped through {@see ShippingZoneListShaper}. The list always includes zone 0,
 * "Rest of the World" — the always-present, read-only catch-all zone that matches
 * any region no other zone covers — which the controller prepends to every list
 * (zones-v2:113-116), so the result is never empty even on a clean install.
 *
 * Only available when WooCommerce is active (it is a {@see ConditionalAbility}).
 * The WC shipping-zones list route sends a pagination header, so `total` is the
 * full count from `X-WP-Total` (zones-v2:127); if the header is ever absent it
 * falls back to the number of returned rows.
 *
 * @since 0.1.0
 */
final class ListShippingZones implements ConditionalAbility {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'wc-shipping/list-shipping-zones';
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
			'label'               => __( 'List Shipping Zones', 'abilities-catalog-woo' ),
			'description'         => __( 'Returns the store\'s WooCommerce shipping zones as flat summary rows, each with its id, name, and order. Zones are matched top-down by their order, and the list always includes zone 0, "Rest of the World" — the always-present, read-only catch-all zone for regions no other zone covers. Use a zone id with wc-shipping/get-shipping-zone-locations to see the regions it matches, or with wc-shipping/list-shipping-zone-methods to see the shipping methods configured on it.', 'abilities-catalog-woo' ),
			'category'            => 'wc-shipping',
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
						'description' => __( 'The shipping zones as flat summary rows, including zone 0 "Rest of the World". Use a zone id with wc-shipping/get-shipping-zone-locations or wc-shipping/list-shipping-zone-methods.', 'abilities-catalog-woo' ),
						'items'       => ShippingZoneListShaper::itemSchema(),
					),
					'total' => array(
						'type'        => 'integer',
						'description' => __( 'The total number of shipping zones, read from the X-WP-Total response header (it includes zone 0 "Rest of the World"). Falls back to the number of returned rows if the header is absent.', 'abilities-catalog-woo' ),
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
	 * Permission check: WooCommerce's shipping-settings management capability.
	 *
	 * Encodes the catalog baseline for `wc-shipping/list-shipping-zones`: the
	 * `manage_woocommerce` capability, which is what
	 * `wc_rest_check_manager_permissions( 'settings', 'read' )` resolves to on the
	 * wrapped `GET wc/v3/shipping/zones` route (wc-rest-functions.php:341 ignores
	 * the context and maps shipping/settings to `manage_woocommerce`). This is a
	 * coarse, object-independent guard. The explicit activity guard keeps the
	 * denial clean when WooCommerce is inactive and the capability is unmapped.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may read shipping zones.
	 */
	public function hasPermission( $input ): bool {
		return WooPlugin::isActive() && current_user_can( 'manage_woocommerce' );
	}

	/**
	 * Executes the ability by dispatching the internal WooCommerce REST request.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The list of shipping zones, or the REST error.
	 */
	public function execute( $input ) {
		if ( ! WooPlugin::isActive() ) {
			return WooPlugin::unavailable();
		}

		$input = is_array( $input ) ? $input : array();

		$request  = new WP_REST_Request( 'GET', '/wc/v3/shipping/zones' );
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

			$rows[] = ShippingZoneListShaper::summary( $item );
		}

		$headers = $response->get_headers();
		$total   = isset( $headers['X-WP-Total'] ) ? (int) $headers['X-WP-Total'] : count( $rows );

		return array(
			'items' => $rows,
			'total' => $total,
		);
	}
}
