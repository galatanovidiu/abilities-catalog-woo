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
 * Destructive write ability: `og-wc-shipping/delete-shipping-zone`.
 *
 * Wraps `DELETE wc/v3/shipping/zones/<id>` via `rest_do_request()`, sending
 * `force=true` because WooCommerce shipping zones do not support trashing — with
 * the default `force=false` the route returns `rest_trash_not_supported` (501).
 * The delete therefore calls `$zone->delete()`, which is permanent: it removes the
 * zone, its regions (locations), and all of its configured shipping methods, with
 * no restore.
 *
 * Before deleting, this reads the zone's name (a `GET wc/v3/shipping/zones/<id>`)
 * so the result can confirm what was removed and a missing zone returns the route's
 * `woocommerce_rest_shipping_zone_invalid` 404 here rather than a permission
 * collapse.
 *
 * Zone 0 ("Rest of the World") is the always-present read-only catch-all and cannot
 * be deleted, so the input requires `id >= 1`.
 *
 * Only available when WooCommerce is active (it is a {@see ConditionalAbility}).
 *
 * @since 0.1.0
 */
final class DeleteShippingZone implements ConditionalAbility {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-wc-shipping/delete-shipping-zone';
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
			'label'               => __( 'Delete Shipping Zone', 'abilities-catalog-woo' ),
			'description'         => __( 'Permanently deletes a WooCommerce shipping zone by ID and returns a confirmation: the deleted flag, the id, and the zone name (captured before deletion). This CANNOT be undone — WooCommerce force-deletes the zone, bypassing the Trash, so there is no restore. Deleting a zone also removes its regions (locations) and all of its configured shipping methods, so carts that previously matched it fall through to the next zone. Zone 0 ("Rest of the World") is the always-present, read-only catch-all and cannot be deleted; pass a real zone id (>= 1). No edit_link is returned because the zone no longer exists. Discover zone IDs with og-wc-shipping/list-shipping-zones.', 'abilities-catalog-woo' ),
			'category'            => 'og-wc-shipping',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'id' ),
				'properties'           => array(
					'id' => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'The shipping zone ID to permanently delete. Discover IDs with og-wc-shipping/list-shipping-zones. Zone 0 is the read-only "Rest of the World" catch-all and cannot be deleted.', 'abilities-catalog-woo' ),
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
						'description' => __( 'Whether the shipping zone was permanently deleted.', 'abilities-catalog-woo' ),
					),
					'id'        => array(
						'type'        => 'integer',
						'description' => __( 'The deleted shipping zone\'s ID.', 'abilities-catalog-woo' ),
					),
					'name'      => array(
						'type'        => 'string',
						'description' => __( 'The name of the deleted zone, captured before deletion so a human can confirm what was removed. No edit_link is returned because the zone no longer exists.', 'abilities-catalog-woo' ),
					),
					'permanent' => array(
						'type'        => 'boolean',
						'description' => __( 'Always true: shipping zones have no Trash, so the delete is permanent and cannot be undone.', 'abilities-catalog-woo' ),
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
	 * Permission check: WooCommerce's store-management capability.
	 *
	 * Encodes the catalog capability for `og-wc-shipping/delete-shipping-zone`:
	 * `manage_woocommerce`, which is what `wc_rest_check_manager_permissions(
	 * 'settings', 'delete' )` resolves to on the wrapped `DELETE wc/v3/shipping/zones/<id>`
	 * route — the helper ignores its `$context` argument and maps the `settings`
	 * object to `manage_woocommerce`. Coarse and object-independent: the per-zone
	 * existence check (and the zone-0 catch-all behaviour) is deferred to the wrapped
	 * route, which surfaces a specific `woocommerce_rest_shipping_zone_invalid` 404
	 * instead of masking a missing zone as "permission denied".
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may delete shipping zones.
	 */
	public function hasPermission( $input ): bool {
		return WooPlugin::isActive() && current_user_can( 'manage_woocommerce' );
	}

	/**
	 * Executes the ability by dispatching the internal `wc/v3` REST delete request.
	 *
	 * Reads the zone name first (so the result can confirm what was removed and a
	 * missing zone 404s here), then force-deletes the zone — `force=true` is
	 * mandatory because the route 501s on `force=false`.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The deleted flag, id, name, and permanent flag, or the REST error.
	 */
	public function execute( $input ) {
		if ( ! WooPlugin::isActive() ) {
			return WooPlugin::unavailable();
		}

		$input = is_array( $input ) ? $input : array();
		$id    = absint( $input['id'] ?? 0 );

		// Capture the zone name before the row is gone; a missing zone 404s here.
		$before = rest_do_request( new WP_REST_Request( 'GET', '/wc/v3/shipping/zones/' . $id ) );
		if ( $before->is_error() ) {
			return RestError::from( $before );
		}

		$before_data = rest_get_server()->response_to_data( $before, false );
		$name        = is_array( $before_data ) ? (string) ( $before_data['name'] ?? '' ) : '';

		$request = new WP_REST_Request( 'DELETE', '/wc/v3/shipping/zones/' . $id );
		$request->set_param( 'force', true );

		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			return RestError::from( $response );
		}

		return array(
			'deleted'   => true,
			'id'        => $id,
			'name'      => $name,
			'permanent' => true,
		);
	}
}
