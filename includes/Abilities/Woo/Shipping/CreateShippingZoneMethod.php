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
 * Write ability: `og-wc-shipping/create-shipping-zone-method`.
 *
 * Wraps `POST wc/v3/shipping/zones/{zone_id}/methods` via `rest_do_request()`,
 * adding a configured shipping method instance to a shipping zone. The `zone_id`
 * is a required route segment; `method_id` is the method TYPE to add (e.g.
 * `flat_rate`), which the route passes to `$zone->add_shipping_method()` to mint a
 * new instance. The result is the flat, closed
 * {@see ShippingZoneMethodListShaper::summary()} record of the created instance —
 * its `instance_id`, `method_id`, `title`, `enabled`, `order`, and a compact
 * `settings_summary` — not the raw per-setting field descriptors.
 *
 * The optional `settings` object is keyed by the method type's own setting ids
 * (which differ per type), so it is an open object; the route applies only the
 * keys it recognizes for the chosen method type and ignores the rest.
 *
 * To change an existing instance use `og-wc-shipping/update-shipping-zone-method`; the
 * method type is fixed at creation and cannot be changed afterward.
 *
 * Only available when WooCommerce is active (it is a {@see ConditionalAbility}).
 *
 * @since 0.1.0
 */
final class CreateShippingZoneMethod implements ConditionalAbility {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-wc-shipping/create-shipping-zone-method';
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
			'label'               => __( 'Create Shipping Zone Method', 'abilities-catalog-woo' ),
			'description'         => __( 'Adds a shipping method instance to a shipping zone and returns the created instance: its instance_id, method_id, title, enabled, order, and a settings_summary. Provide zone_id (discover with og-wc-shipping/list-shipping-zones) and method_id, the method TYPE to add — e.g. flat_rate, free_shipping, or local_pickup (discover available types with og-wc-shipping/list-shipping-methods). The optional settings object configures the method and is keyed by that method type\'s own setting ids (e.g. {"cost": "10", "title": "Standard"} for flat_rate); its keys depend on the method type, and the route applies only the keys it recognizes and ignores unknown ones. After creating, see the configured setting ids with og-wc-shipping/get-shipping-zone-method. To change an existing instance use og-wc-shipping/update-shipping-zone-method; the method type is fixed at creation. An unknown zone_id is rejected by the route with woocommerce_rest_shipping_zone_invalid 404.', 'abilities-catalog-woo' ),
			'category'            => 'og-wc-shipping',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'zone_id', 'method_id' ),
				'properties'           => array(
					'zone_id'   => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'The shipping zone to add the method to. Discover zone ids with og-wc-shipping/list-shipping-zones.', 'abilities-catalog-woo' ),
					),
					'method_id' => array(
						'type'        => 'string',
						'description' => __( 'The shipping method TYPE to add, e.g. flat_rate, free_shipping, or local_pickup (not an instance id). Discover the available types with og-wc-shipping/list-shipping-methods.', 'abilities-catalog-woo' ),
					),
					'settings'  => array(
						'type'                 => 'object',
						'description'          => __( 'Optional configuration for the method, keyed by the method type\'s own setting ids (e.g. {"cost": "10", "title": "Standard"} for flat_rate). The valid keys depend on the method type; the route applies only the keys it recognizes for the chosen type and ignores unknown ones. For free_shipping, min_amount has no effect unless requires is also set to "min_amount" (or "either"/"both"); the default empty requires means free shipping is always offered. Discover a configured method\'s setting ids with og-wc-shipping/get-shipping-zone-method.', 'abilities-catalog-woo' ),
						'additionalProperties' => true,
					),
					'enabled'   => array(
						'type'        => 'boolean',
						'description' => __( 'Whether the method is enabled and offered to shoppers in the zone. Defaults to enabled.', 'abilities-catalog-woo' ),
					),
					'order'     => array(
						'type'        => 'integer',
						'description' => __( 'The method sort position within the zone, in ascending sequence (lower is shown first).', 'abilities-catalog-woo' ),
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
	 * Permission check: WooCommerce's shipping/settings management capability.
	 *
	 * Mirrors `wc_rest_check_manager_permissions( 'settings', 'create' )`, which the
	 * helper resolves to `manage_woocommerce` regardless of the context argument —
	 * the baseline the wrapped `wc/v3` shipping-zone-methods create route enforces.
	 * This is a coarse, object-INDEPENDENT guard; the wrapped route surfaces the
	 * specific object-level error (an unknown zone is
	 * `woocommerce_rest_shipping_zone_invalid` 404) via {@see RestError::from()}
	 * instead of collapsing it to a generic permission denial. The explicit activity
	 * guard keeps the denial clean when WooCommerce is inactive.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may manage WooCommerce shipping.
	 */
	public function hasPermission( $input ): bool {
		return WooPlugin::isActive() && current_user_can( 'manage_woocommerce' );
	}

	/**
	 * Executes the ability by dispatching the internal `wc/v3` REST create request.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The shaped created method instance, or the
	 *                                        REST error (e.g.
	 *                                        `woocommerce_rest_shipping_zone_invalid` 404).
	 */
	public function execute( $input ) {
		if ( ! WooPlugin::isActive() ) {
			return WooPlugin::unavailable();
		}

		$input = is_array( $input ) ? $input : array();

		$zone_id = absint( $input['zone_id'] ?? 0 );

		$request = new WP_REST_Request( 'POST', '/wc/v3/shipping/zones/' . $zone_id . '/methods' );
		$request->set_param( 'method_id', (string) ( $input['method_id'] ?? '' ) );

		if ( array_key_exists( 'settings', $input ) ) {
			$request->set_param( 'settings', (array) $input['settings'] );
		}
		if ( array_key_exists( 'enabled', $input ) ) {
			$request->set_param( 'enabled', (bool) $input['enabled'] );
		}
		if ( array_key_exists( 'order', $input ) ) {
			$request->set_param( 'order', (int) $input['order'] );
		}

		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			return RestError::from( $response );
		}

		$data = rest_get_server()->response_to_data( $response, false );

		return ShippingZoneMethodListShaper::summary( is_array( $data ) ? $data : array() );
	}
}
