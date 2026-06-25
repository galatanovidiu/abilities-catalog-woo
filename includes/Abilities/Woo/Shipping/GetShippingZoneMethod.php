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
 * Read ability: `og-wc-shipping/get-shipping-zone-method`.
 *
 * Wraps `GET wc/v3/shipping/zones/<zone_id>/methods/<instance_id>` via
 * `rest_do_request()` and returns one shipping method instance configured on a
 * zone as a flat, closed record: its instance_id, method type slug (method_id),
 * customer-facing title, enabled flag, sort order, a compact settings_summary of
 * its configured values, and the method type's method_title and
 * method_description. This is one CONFIGURED instance on a zone; for the catalog
 * of available method TYPES use `og-wc-shipping/list-shipping-methods` /
 * `og-wc-shipping/get-shipping-method`. Both route segments are required; the route
 * surfaces the specific 404 for a missing zone or a missing instance.
 *
 * Only available when WooCommerce is active (it is a {@see ConditionalAbility}).
 *
 * @since 0.1.0
 */
final class GetShippingZoneMethod implements ConditionalAbility {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-wc-shipping/get-shipping-zone-method';
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
			'label'               => __( 'Get Shipping Zone Method', 'abilities-catalog-woo' ),
			'description'         => __( 'Returns one shipping method instance configured on a zone by zone_id and instance_id: its method type slug (method_id), customer-facing title, enabled flag, sort order, a compact settings_summary of its configured values (e.g. cost, title), and the method type\'s method_title and method_description. This is a single configured instance on a zone; use og-wc-shipping/get-shipping-method for the available method type itself. Discover zone_id with og-wc-shipping/list-shipping-zones and instance_id with og-wc-shipping/list-shipping-zone-methods.', 'abilities-catalog-woo' ),
			'category'            => 'og-wc-shipping',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'zone_id', 'instance_id' ),
				'properties'           => array(
					'zone_id'     => array(
						'type'        => 'integer',
						'minimum'     => 0,
						'description' => __( 'The shipping zone ID that holds the method instance. Discover IDs with og-wc-shipping/list-shipping-zones; id 0 is the always-present "Rest of the World" catch-all zone.', 'abilities-catalog-woo' ),
					),
					'instance_id' => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'The configured method instance ID within the zone. Discover IDs with og-wc-shipping/list-shipping-zone-methods.', 'abilities-catalog-woo' ),
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => ShippingZoneMethodListShaper::detailSchema(),
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
	 * Mirrors the wrapped `wc/v3` GET route, which gates on
	 * `wc_rest_check_manager_permissions( 'settings', 'read' )` — that helper
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
	 * @return bool True if the current user may read shipping zone methods.
	 */
	public function hasPermission( $input ): bool {
		return WooPlugin::isActive() && current_user_can( 'manage_woocommerce' );
	}

	/**
	 * Executes the ability by dispatching the internal `wc/v3` REST request.
	 *
	 * The zone_id and instance_id are route segments, so they are concatenated into
	 * the path (cast to int first), never sent as query params.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The shaped method-instance detail row, or
	 *                                        the REST error (`woocommerce_rest_shipping_zone_invalid`
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

		$request  = new WP_REST_Request( 'GET', '/wc/v3/shipping/zones/' . $zone_id . '/methods/' . $instance_id );
		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			return RestError::from( $response );
		}

		$data = rest_get_server()->response_to_data( $response, false );
		$data = is_array( $data ) ? $data : array();

		return ShippingZoneMethodListShaper::detail( $data );
	}
}
