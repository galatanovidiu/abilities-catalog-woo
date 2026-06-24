<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogWoo\Abilities\Woo\Shipping;

use GalatanOvidiu\AbilitiesCatalogWoo\Contracts\ConditionalAbility;
use GalatanOvidiu\AbilitiesCatalogWoo\Support\RestError;
use GalatanOvidiu\AbilitiesCatalogWoo\Support\WooPlugin;
use WP_REST_Request;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Destructive write ability: `wc-shipping/delete-shipping-zone-method`.
 *
 * Wraps `DELETE wc/v3/shipping/zones/<zone_id>/methods/<instance_id>` via
 * `rest_do_request()` with `force=true`. The zone_id and instance_id are route
 * segments concatenated into the path (cast to int first), never query params.
 *
 * Permanence: the wrapped route calls `$zone->delete_shipping_method( $instance_id )`,
 * a hard delete with no Trash. Sending `force=false` (the route default) is rejected
 * with `rest_trash_not_supported` 501, so this ability always sends `force=true` and
 * the delete cannot be undone.
 *
 * Before deleting, this reads the method instance via the single-instance GET route so
 * the result can confirm the human-facing `title` (falling back to `method_id`), and so
 * a missing instance returns the route's `woocommerce_rest_shipping_zone_method_invalid`
 * 404 (or a missing zone its `woocommerce_rest_shipping_zone_invalid` 404) here rather
 * than collapsing to a generic permission denial.
 *
 * Only available when WooCommerce is active (it is a {@see ConditionalAbility}).
 *
 * @since 0.1.0
 */
final class DeleteShippingZoneMethod implements ConditionalAbility {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'wc-shipping/delete-shipping-zone-method';
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
			'label'               => __( 'Delete Shipping Zone Method', 'abilities-catalog-woo' ),
			'description'         => __( 'Permanently deletes a configured shipping-method instance from a shipping zone, removing that rate from the zone\'s checkout options. This cannot be undone: the method instance is force-deleted, bypassing the Trash, so there is no restore (re-add it with wc-shipping/create-shipping-zone-method). The zone itself and its other methods are unaffected. Returns the deleted instance id and its title for confirmation; no edit_link is returned because the instance no longer exists. Discover zone_id with wc-shipping/list-shipping-zones and instance_id with wc-shipping/list-shipping-zone-methods.', 'abilities-catalog-woo' ),
			'category'            => 'wc-shipping',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'zone_id', 'instance_id' ),
				'properties'           => array(
					'zone_id'     => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'The shipping zone ID that holds the method instance. Discover IDs with wc-shipping/list-shipping-zones.', 'abilities-catalog-woo' ),
					),
					'instance_id' => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'The configured method instance ID within the zone to permanently delete. Discover IDs with wc-shipping/list-shipping-zone-methods.', 'abilities-catalog-woo' ),
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'deleted', 'id' ),
				'properties'           => array(
					'deleted'   => array(
						'type'        => 'boolean',
						'description' => __( 'Whether the shipping-method instance was permanently deleted.', 'abilities-catalog-woo' ),
					),
					'id'        => array(
						'type'        => 'integer',
						'description' => __( 'The deleted method instance ID (the instance_id that was removed from the zone).', 'abilities-catalog-woo' ),
					),
					'name'      => array(
						'type'        => 'string',
						'description' => __( 'The method instance title (its customer-facing label, e.g. "Flat rate"), captured before deletion so a human can confirm what was removed; falls back to the method type id (e.g. flat_rate) when no title is set. No edit_link is returned because the instance no longer exists.', 'abilities-catalog-woo' ),
					),
					'permanent' => array(
						'type'        => 'boolean',
						'description' => __( 'Always true: shipping-method instances have no Trash, so this delete cannot be undone.', 'abilities-catalog-woo' ),
					),
				),
				'additionalProperties' => false,
			),
			'execute_callback'    => array( $this, 'execute' ),
			'permission_callback' => array( $this, 'hasPermission' ),
			'meta'                => array(
				'annotations'  => array(
					'readonly'    => false,
					'destructive' => true,
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
	 * Mirrors the wrapped `wc/v3` DELETE route, which gates on
	 * `wc_rest_check_manager_permissions( 'settings', 'delete' )` — that helper
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
	 * Executes the ability by pre-reading the instance then dispatching the
	 * internal `wc/v3` REST delete request with `force=true`.
	 *
	 * The zone_id and instance_id are route segments, so they are concatenated into
	 * the path (cast to int first), never sent as query params. The pre-read GET
	 * captures the instance title (falling back to method_id) and surfaces a missing
	 * zone or instance as the route's specific 404. `force=true` is mandatory: the
	 * route returns `rest_trash_not_supported` 501 without it.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The deleted flag, id, name, and permanent
	 *                                        flag, or the REST error
	 *                                        (`woocommerce_rest_shipping_zone_invalid`
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
		$base        = '/wc/v3/shipping/zones/' . $zone_id . '/methods/' . $instance_id;

		// Capture the title before the instance is gone; a missing zone or instance 404s here.
		$before = rest_do_request( new WP_REST_Request( 'GET', $base ) );
		if ( $before->is_error() ) {
			return RestError::from( $before );
		}

		$before_data = rest_get_server()->response_to_data( $before, false );
		$before_data = is_array( $before_data ) ? $before_data : array();
		$title       = (string) ( $before_data['title'] ?? '' );
		if ( '' === $title ) {
			$title = (string) ( $before_data['method_id'] ?? '' );
		}

		$request = new WP_REST_Request( 'DELETE', $base );
		$request->set_param( 'force', true );

		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			return RestError::from( $response );
		}

		return array(
			'deleted'   => true,
			'id'        => $instance_id,
			'name'      => $title,
			'permanent' => true,
		);
	}
}
