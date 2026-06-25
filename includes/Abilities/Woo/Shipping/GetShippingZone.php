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
 * Read ability: `og-wc-shipping/get-shipping-zone`.
 *
 * Wraps `GET wc/v3/shipping/zones/<id>` via `rest_do_request()` and returns one
 * shipping zone as a flat, closed record: its id, name, and order. The raw `wc/v3`
 * zone body adds `_links` the consumer never reads; this projects only the useful
 * subset through {@see ShippingZoneListShaper::summary()} so the single-get and the
 * list ability share one row shape.
 *
 * Zone 0 ("Rest of the World") is a real, always-present, read-only catch-all zone,
 * so the id minimum is 0 rather than 1.
 *
 * Only available when WooCommerce is active (it is a {@see ConditionalAbility}).
 *
 * @since 0.1.0
 */
final class GetShippingZone implements ConditionalAbility {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-wc-shipping/get-shipping-zone';
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
			'label'               => __( 'Get Shipping Zone', 'abilities-catalog-woo' ),
			'description'         => __( 'Returns one WooCommerce shipping zone by ID: its id, name, and order (its sort position). Discover IDs with og-wc-shipping/list-shipping-zones. Id 0 is the always-present, read-only "Rest of the World" zone (the catch-all for regions not covered by another zone). Use og-wc-shipping/get-shipping-zone-locations for the zone\'s geographic match rules and og-wc-shipping/list-shipping-zone-methods for the methods configured on it.', 'abilities-catalog-woo' ),
			'category'            => 'og-wc-shipping',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'id' ),
				'properties'           => array(
					'id' => array(
						'type'        => 'integer',
						'minimum'     => 0,
						'description' => __( 'The shipping zone ID. Discover IDs with og-wc-shipping/list-shipping-zones. Id 0 is the always-present "Rest of the World" catch-all zone.', 'abilities-catalog-woo' ),
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => ShippingZoneListShaper::itemSchema(),
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
	 * Permission check: WooCommerce's shipping management capability.
	 *
	 * Mirrors the wrapped `wc/v3` GET route, which gates on
	 * `wc_rest_check_manager_permissions( 'settings', 'read' )` — and that helper
	 * ignores the read/edit context and resolves to `manage_woocommerce`. This is a
	 * coarse, object-INDEPENDENT type-level guard: the per-object decision is
	 * deferred to the wrapped route, so a missing zone surfaces its specific
	 * `woocommerce_rest_shipping_zone_invalid` 404 via {@see RestError::from()}
	 * instead of collapsing to a generic permission denial. The explicit activity
	 * guard keeps the denial clean when WooCommerce is inactive.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may read shipping zones.
	 */
	public function hasPermission( $input ): bool {
		return WooPlugin::isActive() && current_user_can( 'manage_woocommerce' );
	}

	/**
	 * Executes the ability by dispatching the internal `wc/v3` REST request.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The shaped shipping-zone record, or the
	 *                                        REST error (e.g. `woocommerce_rest_shipping_zone_invalid` 404).
	 */
	public function execute( $input ) {
		if ( ! WooPlugin::isActive() ) {
			return WooPlugin::unavailable();
		}

		$input = is_array( $input ) ? $input : array();
		$id    = absint( $input['id'] ?? 0 );

		$request  = new WP_REST_Request( 'GET', '/wc/v3/shipping/zones/' . $id );
		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			return RestError::from( $response );
		}

		$data = rest_get_server()->response_to_data( $response, false );
		$data = is_array( $data ) ? $data : array();

		$row = ShippingZoneListShaper::summary( $data );

		return array(
			'id'    => $row['id'],
			'name'  => $row['name'],
			'order' => $row['order'],
		);
	}
}
