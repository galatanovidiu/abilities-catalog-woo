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
 * Write ability: `og-wc-shipping/update-shipping-zone`.
 *
 * Wraps `PUT wc/v3/shipping/zones/<id>` via `rest_do_request()`, changing a shipping
 * zone's name and/or sort order and returning the shaped updated zone
 * ({@see ShippingZoneListShaper::summary()}: id, name, order). Send only the fields
 * you want to change; an omitted field is left untouched (the route applies `name`
 * and `order` only when present).
 *
 * The id is set as a path segment by concatenation (never `set_param`), and the
 * writable body params are forwarded on key presence, so an update changes only what
 * the caller sent.
 *
 * Zone 0 ("Rest of the World") is read-only: the route rejects an update to it with
 * `woocommerce_rest_shipping_zone_invalid_zone` (HTTP 403). A missing zone surfaces
 * `woocommerce_rest_shipping_zone_invalid` (HTTP 404). Both are surfaced verbatim via
 * {@see RestError::from()} rather than collapsed to a permission denial. The id
 * minimum is 0 (not 1) so an attempt to edit zone 0 reaches the route and gets the
 * honest 403, instead of being masked by schema input validation.
 *
 * Only available when WooCommerce is active (it is a {@see ConditionalAbility}).
 *
 * @since 0.1.0
 */
final class UpdateShippingZone implements ConditionalAbility {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-wc-shipping/update-shipping-zone';
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
			'label'               => __( 'Update Shipping Zone', 'abilities-catalog-woo' ),
			'description'         => __( 'Updates an existing WooCommerce shipping zone by ID and returns the shaped zone (id, name, order). Send only the fields you want to change; omitted fields are left untouched. Discover IDs with og-wc-shipping/list-shipping-zones. Use og-wc-shipping/create-shipping-zone to make a new zone instead. This changes only the zone label and sort order; to change its regions use og-wc-shipping/update-shipping-zone-locations and to change its rates use the shipping-zone-method abilities. IMPORTANT: zone 0, the always-present "Rest of the World" catch-all, is read-only and CANNOT be edited; attempting to update it returns a woocommerce_rest_shipping_zone_invalid_zone 403. A missing zone returns woocommerce_rest_shipping_zone_invalid 404.', 'abilities-catalog-woo' ),
			'category'            => 'og-wc-shipping',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'id' ),
				'properties'           => array(
					'id'    => array(
						'type'        => 'integer',
						'minimum'     => 0,
						'description' => __( 'The shipping zone ID to update. Discover IDs with og-wc-shipping/list-shipping-zones. Id 0 is the read-only "Rest of the World" catch-all and cannot be edited (the route returns a 403).', 'abilities-catalog-woo' ),
					),
					'name'  => array(
						'type'        => 'string',
						'description' => __( 'A new zone name (the label shown to store staff). Omit to keep the current name.', 'abilities-catalog-woo' ),
					),
					'order' => array(
						'type'        => 'integer',
						'description' => __( 'A new sort position; zones are matched against an order in ascending sequence (lower runs first). Omit to keep the current order.', 'abilities-catalog-woo' ),
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => ShippingZoneListShaper::itemSchema(),
			'execute_callback'    => array( $this, 'execute' ),
			'permission_callback' => array( $this, 'hasPermission' ),
			'meta'                => array(
				'annotations'  => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => false,
				),
				'show_in_rest' => true,
				'screen'       => 'admin.php?page=wc-settings&tab=shipping',
			),
		);
	}

	/**
	 * Permission check: WooCommerce's shipping management capability.
	 *
	 * Mirrors the wrapped `wc/v3` PUT route, which gates on
	 * `wc_rest_check_manager_permissions( 'settings', 'edit' )` — and that helper
	 * ignores the read/edit context and resolves to `manage_woocommerce`. This is a
	 * coarse, object-INDEPENDENT guard: the per-object decision is deferred to the
	 * wrapped route, so a missing zone surfaces its specific
	 * `woocommerce_rest_shipping_zone_invalid` 404 (and zone 0 its
	 * `woocommerce_rest_shipping_zone_invalid_zone` 403) via {@see RestError::from()}
	 * instead of collapsing to a generic permission denial. The explicit activity
	 * guard keeps the denial clean when WooCommerce is inactive.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may manage shipping zones.
	 */
	public function hasPermission( $input ): bool {
		return WooPlugin::isActive() && current_user_can( 'manage_woocommerce' );
	}

	/**
	 * Executes the ability by dispatching the internal `wc/v3` REST update request.
	 *
	 * Forwards only the writable body params present in the input, so an update
	 * changes only what the caller sent. A missing zone or zone 0 surfaces the
	 * route's specific error via {@see RestError::from()}.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The shaped updated zone, or the REST error
	 *                                        (`woocommerce_rest_shipping_zone_invalid` 404,
	 *                                        `woocommerce_rest_shipping_zone_invalid_zone` 403).
	 */
	public function execute( $input ) {
		if ( ! WooPlugin::isActive() ) {
			return WooPlugin::unavailable();
		}

		$input = is_array( $input ) ? $input : array();
		$id    = absint( $input['id'] ?? 0 );

		$request = new WP_REST_Request( 'PUT', '/wc/v3/shipping/zones/' . $id );

		if ( array_key_exists( 'name', $input ) ) {
			$request->set_param( 'name', (string) $input['name'] );
		}
		if ( array_key_exists( 'order', $input ) ) {
			$request->set_param( 'order', (int) $input['order'] );
		}

		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			return RestError::from( $response );
		}

		$data = rest_get_server()->response_to_data( $response, false );

		return ShippingZoneListShaper::summary( is_array( $data ) ? $data : array() );
	}
}
