<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogWoo\Abilities\Woo\Shipping;

use GalatanOvidiu\AbilitiesCatalogWoo\Contracts\ConditionalAbility;
use GalatanOvidiu\AbilitiesCatalogWoo\Support\RestError;
use GalatanOvidiu\AbilitiesCatalogWoo\Support\ShippingZoneMethodListShaper;
use GalatanOvidiu\AbilitiesCatalogWoo\Support\WooPlugin;
use WP_REST_Request;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read ability: `wc-shipping/list-shipping-zone-methods`.
 *
 * Wraps `GET wc/v3/shipping/zones/{zone_id}/methods` via `rest_do_request()` and
 * returns the shipping methods configured on one zone as flat summary rows through
 * {@see ShippingZoneMethodListShaper::summary()}. These are method INSTANCES — the
 * concrete methods an admin added to the zone (each with its own title, enabled
 * flag, sort order, and configured settings) — not the method-type templates the
 * `wc-shipping/list-shipping-methods` registry lists. The `zone_id` identifies the
 * parent zone and is a required route segment, so this read is always scoped to one
 * zone. A zone with no methods configured returns an empty `items` array.
 *
 * Only available when WooCommerce is active (it is a {@see ConditionalAbility}).
 * The WC zone-methods route sets `X-WP-Total` to the row count, so `total` is the
 * number of rows returned.
 *
 * @since 0.1.0
 */
final class ListShippingZoneMethods implements ConditionalAbility {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'wc-shipping/list-shipping-zone-methods';
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
			'label'               => __( 'List Shipping Zone Methods', 'abilities-catalog-woo' ),
			'description'         => __( 'Returns the shipping methods configured on one WooCommerce shipping zone as flat summary rows, each with its instance_id, method_id, title, enabled flag, order, and a compact settings_summary of its configured values. These are method INSTANCES added to the zone, not the available method types; list the method-type registry with wc-shipping/list-shipping-methods. Identify the zone with zone_id (discover zones with wc-shipping/list-shipping-zones; zone_id 0 is the "Rest of the World" catch-all zone). A zone with no methods configured returns an empty items array. Read-only: returns each method\'s settings as a compact summary, not the full per-setting field descriptors — use wc-shipping/get-shipping-zone-method for one method instance in full.', 'abilities-catalog-woo' ),
			'category'            => 'wc-shipping',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'zone_id' ),
				'properties'           => array(
					'zone_id' => array(
						'type'        => 'integer',
						'minimum'     => 0,
						'description' => __( 'The shipping zone ID whose configured methods to list. Discover zone IDs with wc-shipping/list-shipping-zones; zone_id 0 is the always-present "Rest of the World" catch-all zone.', 'abilities-catalog-woo' ),
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'zone_id', 'items', 'total' ),
				'properties'           => array(
					'zone_id' => array(
						'type'        => 'integer',
						'description' => __( 'The shipping zone ID the methods belong to.', 'abilities-catalog-woo' ),
					),
					'items'   => array(
						'type'        => 'array',
						'description' => __( 'The configured shipping-method instances as flat summary rows. Use wc-shipping/get-shipping-zone-method for one instance in full.', 'abilities-catalog-woo' ),
						'items'       => ShippingZoneMethodListShaper::itemSchema(),
					),
					'total'   => array(
						'type'        => 'integer',
						'description' => __( 'The number of method instances returned, which equals the X-WP-Total the route reports.', 'abilities-catalog-woo' ),
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
	 * Permission check: WooCommerce's shipping-management capability.
	 *
	 * Encodes the catalog baseline for `wc-shipping/list-shipping-zone-methods`:
	 * `manage_woocommerce`, which is what the wrapped `GET
	 * wc/v3/shipping/zones/{zone_id}/methods` route resolves to — its
	 * `get_items_permissions_check()` calls `wc_rest_check_manager_permissions(
	 * 'settings', 'read' )`, and `wc_rest_check_manager_permissions()` maps the
	 * `settings` object to `manage_woocommerce` regardless of context. This is a
	 * coarse, object-independent check; the wrapped route surfaces the specific
	 * `woocommerce_rest_shipping_zone_invalid` 404 for a missing parent zone. The
	 * explicit activity guard keeps the denial clean when WooCommerce is inactive
	 * and the capability is unmapped.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may read shipping zone methods.
	 */
	public function hasPermission( $input ): bool {
		return WooPlugin::isActive() && current_user_can( 'manage_woocommerce' );
	}

	/**
	 * Executes the ability by dispatching the internal WooCommerce REST request.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The zone's configured methods, or the REST error.
	 */
	public function execute( $input ) {
		if ( ! WooPlugin::isActive() ) {
			return WooPlugin::unavailable();
		}

		$input   = is_array( $input ) ? $input : array();
		$zone_id = absint( $input['zone_id'] ?? 0 );

		$request  = new WP_REST_Request( 'GET', '/wc/v3/shipping/zones/' . $zone_id . '/methods' );
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

			$rows[] = ShippingZoneMethodListShaper::summary( $item );
		}

		return array(
			'zone_id' => $zone_id,
			'items'   => $rows,
			'total'   => count( $rows ),
		);
	}
}
