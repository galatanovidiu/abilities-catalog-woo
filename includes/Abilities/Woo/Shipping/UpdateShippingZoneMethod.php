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
 * Write ability: `og-wc-shipping/update-shipping-zone-method`.
 *
 * Wraps `PUT wc/v3/shipping/zones/<zone_id>/methods/<instance_id>` via
 * `rest_do_request()`, changing a configured shipping-method instance on a zone and
 * returning the shaped updated instance ({@see ShippingZoneMethodListShaper::summary()}:
 * instance_id, method_id, title, enabled, order, settings_summary).
 *
 * The zone_id and instance_id are route segments concatenated into the path (cast
 * to int first), never query params. The optional `enabled`, `order`, and
 * `settings` body params are forwarded only when present in input, so the update
 * changes only what the caller sent. The `settings` object is a PARTIAL update:
 * WooCommerce's `update_fields()` applies only the setting keys present in the
 * request that match the method type's form fields, so a setting not sent is left
 * unchanged. The method TYPE (`method_id`) is fixed at create and cannot be changed
 * here.
 *
 * Only available when WooCommerce is active (it is a {@see ConditionalAbility}).
 *
 * @since 0.1.0
 */
final class UpdateShippingZoneMethod implements ConditionalAbility {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-wc-shipping/update-shipping-zone-method';
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
			'label'               => __( 'Update Shipping Zone Method', 'abilities-catalog-woo' ),
			'description'         => __( 'Updates a shipping-method instance already configured on a zone and returns the shaped instance (instance_id, method_id, title, enabled, order, settings_summary). Use this to enable or disable a method, change its sort order, or change its settings; use og-wc-shipping/create-shipping-zone-method to add a new method instead. The method TYPE (method_id, e.g. flat_rate) is fixed at create and cannot be changed here. Send only what you want to change: enabled, order, and any settings keys you include are applied; omitted settings keys keep their current value (this is a partial settings update, not a full replace). Discover zone_id with og-wc-shipping/list-shipping-zones and instance_id with og-wc-shipping/list-shipping-zone-methods; see a method\'s available settings ids with og-wc-shipping/get-shipping-zone-method.', 'abilities-catalog-woo' ),
			'category'            => 'og-wc-shipping',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'zone_id', 'instance_id' ),
				'properties'           => array(
					'zone_id'     => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'The shipping zone ID that holds the method instance. Discover IDs with og-wc-shipping/list-shipping-zones.', 'abilities-catalog-woo' ),
					),
					'instance_id' => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'The configured method instance ID within the zone to update. Discover IDs with og-wc-shipping/list-shipping-zone-methods.', 'abilities-catalog-woo' ),
					),
					'enabled'     => array(
						'type'        => 'boolean',
						'description' => __( 'Whether the method is offered to shoppers in this zone. Set false to turn it off, true to turn it on. Omit to leave unchanged.', 'abilities-catalog-woo' ),
					),
					'order'       => array(
						'type'        => 'integer',
						'description' => __( 'The method sort position within the zone, in ascending sequence. Omit to leave unchanged.', 'abilities-catalog-woo' ),
					),
					'settings'    => array(
						'type'                 => 'object',
						'description'          => __( 'A partial map of setting id to new value, e.g. {"cost": "10", "title": "Standard"} for flat_rate. Only the keys you send change; omitted keys keep their current value. The valid keys depend on the method type (method_id) and are intentionally open; discover a method\'s setting ids with og-wc-shipping/get-shipping-zone-method. For free_shipping, min_amount has no effect unless requires is also set to "min_amount" (or "either"/"both"); an empty requires means free shipping is always offered.', 'abilities-catalog-woo' ),
						'additionalProperties' => true,
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => ShippingZoneMethodListShaper::itemSchema(),
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
	 * Permission check: WooCommerce's shipping-management capability.
	 *
	 * Mirrors the wrapped `wc/v3` PUT route, which gates on
	 * `wc_rest_check_manager_permissions( 'settings', 'edit' )` — that helper
	 * ignores the context argument and maps the `settings` resource to
	 * `manage_woocommerce`, the baseline a successful caller must hold. This is a
	 * coarse, object-INDEPENDENT type-level guard: the per-object decision is
	 * deferred to the wrapped route, so a missing zone surfaces its specific
	 * `woocommerce_rest_shipping_zone_invalid` 404 and a missing instance its
	 * `woocommerce_rest_shipping_zone_method_invalid` 404 via {@see RestError::from()}
	 * instead of collapsing to a generic permission denial. The explicit activity
	 * guard keeps the denial clean when WooCommerce is inactive.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may manage shipping zone methods.
	 */
	public function hasPermission( $input ): bool {
		return WooPlugin::isActive() && current_user_can( 'manage_woocommerce' );
	}

	/**
	 * Executes the ability by dispatching the internal `wc/v3` REST update request.
	 *
	 * The zone_id and instance_id are route segments, so they are concatenated into
	 * the path (cast to int first), never sent as query params. The writable body
	 * params (`enabled`, `order`, `settings`) are forwarded only when present in
	 * input, so the update changes only what the caller sent.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The shaped updated method instance, or the
	 *                                        REST error (`woocommerce_rest_shipping_zone_invalid`
	 *                                        404 for a missing zone,
	 *                                        `woocommerce_rest_shipping_zone_method_invalid`
	 *                                        404 for a missing instance).
	 */
	public function execute( $input ) {
		if ( ! WooPlugin::isActive() ) {
			return WooPlugin::unavailable();
		}

		$input       = is_array( $input ) ? $input : array();
		$zone_id     = absint( $input['zone_id'] ?? 0 );
		$instance_id = absint( $input['instance_id'] ?? 0 );

		$request = new WP_REST_Request( 'PUT', '/wc/v3/shipping/zones/' . $zone_id . '/methods/' . $instance_id );

		// Update forwards only the keys present in input, so it changes only what the caller sent.
		foreach ( array( 'enabled', 'order', 'settings' ) as $field ) {
			if ( ! array_key_exists( $field, $input ) ) {
				continue;
			}

			$request->set_param( $field, $input[ $field ] );
		}

		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			return RestError::from( $response );
		}

		$data = rest_get_server()->response_to_data( $response, false );
		$data = is_array( $data ) ? $data : array();

		return ShippingZoneMethodListShaper::summary( $data );
	}
}
