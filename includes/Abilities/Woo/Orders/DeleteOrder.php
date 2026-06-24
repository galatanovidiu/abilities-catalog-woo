<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogWoo\Abilities\Woo\Orders;

use GalatanOvidiu\AbilitiesCatalogWoo\Contracts\ConditionalAbility;
use GalatanOvidiu\AbilitiesCatalogWoo\Support\BooleanInput;
use GalatanOvidiu\AbilitiesCatalogWoo\Support\RestError;
use GalatanOvidiu\AbilitiesCatalogWoo\Support\WooPlugin;
use WP_REST_Request;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Destructive write ability: `wc-orders/delete-order`.
 *
 * Wraps `DELETE wc/v3/orders/<id>` via `rest_do_request()`. An order is a
 * financial and legal record, so the delete behaviour depends on `force`:
 *
 * - `force=false` (the default) moves the order to the Trash via
 *   `$object->delete()`, leaving the order recoverable from the wp-admin orders
 *   list (it sets the order status to `trash`).
 * - `force=true` permanently deletes the order via `$object->delete( true )`. This
 *   is irreversible — there is no restore.
 *
 * Before deleting, this pre-reads the order so the result can confirm the order
 * number that was removed, and so a missing order returns the route's
 * `woocommerce_rest_shop_order_invalid_id` 404 here rather than collapsing to a
 * permission denial.
 *
 * The Trash path depends on `EMPTY_TRASH_DAYS > 0` (WordPress defaults to 30). If
 * the site disables Trash (`EMPTY_TRASH_DAYS = 0`), a `force=false` delete returns
 * `woocommerce_rest_trash_not_supported` 501; the caller must then pass
 * `force=true` to delete the order.
 *
 * The WooCommerce DELETE response is the prepared order object (it carries NO
 * `deleted` field), so this synthesizes `deleted => true` from a non-error
 * response.
 *
 * Only available when WooCommerce is active (it is a {@see ConditionalAbility}).
 *
 * @since 0.1.0
 */
final class DeleteOrder implements ConditionalAbility {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'wc-orders/delete-order';
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
			'label'               => __( 'Delete Order', 'abilities-catalog-woo' ),
			'description'         => __( 'Deletes a WooCommerce order by ID and returns the deleted order\'s number for confirmation. An order is a financial and legal record, so deletion is reversibility-controlled by force: force=false (the default) moves the order to the Trash, where it stays recoverable from the wp-admin orders list; force=true permanently deletes it and CANNOT be undone — there is no restore. Discover order IDs with wc-orders/list-orders. The Trash path needs Trash enabled on the site (EMPTY_TRASH_DAYS > 0, the WordPress default); if Trash is disabled, a force=false delete returns woocommerce_rest_trash_not_supported (501) and you must pass force=true. A missing order returns woocommerce_rest_shop_order_invalid_id (404). No edit_link is returned because the order is trashed or gone.', 'abilities-catalog-woo' ),
			'category'            => 'wc-orders',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'id' ),
				'properties'           => array(
					'id'    => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'The order ID to delete. Discover IDs with wc-orders/list-orders.', 'abilities-catalog-woo' ),
					),
					'force' => array(
						'type'        => 'boolean',
						'default'     => false,
						'description' => __( 'Whether to permanently delete the order. false (default) moves it to the Trash, recoverable from wp-admin; true deletes it permanently and irreversibly. With Trash disabled on the site (EMPTY_TRASH_DAYS = 0), false returns a 501 and you must set true.', 'abilities-catalog-woo' ),
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'deleted', 'id', 'number', 'force_used', 'permanent' ),
				'properties'           => array(
					'deleted'    => array(
						'type'        => 'boolean',
						'description' => __( 'Whether the order was deleted (trashed or permanently removed).', 'abilities-catalog-woo' ),
					),
					'id'         => array(
						'type'        => 'integer',
						'description' => __( 'The deleted order\'s ID.', 'abilities-catalog-woo' ),
					),
					'number'     => array(
						'type'        => 'string',
						'description' => __( 'The deleted order\'s order number, so a human can confirm what was removed. No edit_link is returned because the order is trashed or gone.', 'abilities-catalog-woo' ),
					),
					'force_used' => array(
						'type'        => 'boolean',
						'description' => __( 'Whether a permanent delete ran (the force value sent). false means the order was moved to the Trash.', 'abilities-catalog-woo' ),
					),
					'permanent'  => array(
						'type'        => 'boolean',
						'description' => __( 'Whether the order is gone forever. false means it sits in the Trash and can be restored from wp-admin; true means it cannot be recovered.', 'abilities-catalog-woo' ),
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
				'screen'       => 'admin.php?page=wc-orders',
			),
		);
	}

	/**
	 * Permission check: WooCommerce's order delete capability.
	 *
	 * Encodes the catalog capability for `wc-orders/delete-order`: the primitive
	 * `delete_shop_orders` cap. The wrapped route resolves `wc_rest_check_post_permissions(
	 * 'shop_order', 'delete', $id )` to the `delete_post` meta-cap, which WP maps via the
	 * `shop_order` post type to this primitive. Coarse and object-INDEPENDENT: the
	 * per-object decision is deferred to the wrapped route, so a missing order surfaces
	 * its specific `woocommerce_rest_shop_order_invalid_id` 404 (and an already-trashed
	 * order its `woocommerce_rest_already_trashed` 410) via {@see RestError::from()}
	 * instead of collapsing to a generic permission denial.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may delete orders.
	 */
	public function hasPermission( $input ): bool {
		return WooPlugin::isActive() && current_user_can( 'delete_shop_orders' );
	}

	/**
	 * Executes the ability by dispatching the internal `wc/v3` REST delete request.
	 *
	 * Pre-reads the order to capture its number (so a missing order 404s here), then
	 * dispatches the DELETE with the forwarded `force` flag. The WooCommerce DELETE
	 * response is the prepared order object with no `deleted` field, so `deleted` is
	 * synthesized as true from a non-error response.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The deletion envelope, or the REST error
	 *                                        (`woocommerce_rest_shop_order_invalid_id` 404,
	 *                                        `woocommerce_rest_already_trashed` 410,
	 *                                        `woocommerce_rest_trash_not_supported` 501).
	 */
	public function execute( $input ) {
		if ( ! WooPlugin::isActive() ) {
			return WooPlugin::unavailable();
		}

		$input = is_array( $input ) ? $input : array();
		$id    = absint( $input['id'] ?? 0 );
		$force = BooleanInput::sanitize( $input['force'] ?? false );

		// Capture the order number before the row is gone; a missing order 404s here.
		$before = rest_do_request( new WP_REST_Request( 'GET', '/wc/v3/orders/' . $id ) );
		if ( $before->is_error() ) {
			return RestError::from( $before );
		}

		$before_data = rest_get_server()->response_to_data( $before, false );
		$number      = is_array( $before_data ) ? (string) ( $before_data['number'] ?? '' ) : '';

		$request = new WP_REST_Request( 'DELETE', '/wc/v3/orders/' . $id );
		$request->set_param( 'force', $force );

		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			return RestError::from( $response );
		}

		return array(
			'deleted'    => true,
			'id'         => $id,
			'number'     => $number,
			'force_used' => $force,
			'permanent'  => $force,
		);
	}
}
