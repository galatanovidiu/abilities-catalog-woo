<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogWoo\Abilities\Woo\Orders;

use GalatanOvidiu\AbilitiesCatalogWoo\Contracts\ConditionalAbility;
use GalatanOvidiu\AbilitiesCatalogWoo\Support\RestError;
use GalatanOvidiu\AbilitiesCatalogWoo\Support\WooPlugin;
use WP_REST_Request;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Destructive delete ability: `og-wc-orders/delete-order-refund`.
 *
 * Wraps `DELETE wc/v3/orders/<order_id>/refunds/<id>` via `rest_do_request()`.
 * Refunds do NOT support the Trash (the controller disables it via
 * `add_filter( "woocommerce_rest_shop_order_refund_object_trashable",
 * '__return_false' )`), so the wrapped CRUD `delete_item` requires `force=true`
 * (it returns `woocommerce_rest_trash_not_supported` 501 otherwise). This ability
 * therefore hard-sets `force=true` server-side and does not expose a `force`
 * input: the delete is always permanent and irreversible.
 *
 * Before deleting, it reads the refund's `amount` so the result can confirm what
 * was removed (and so a missing parent order or refund returns the route's 404
 * here, never a permission collapse): a missing order surfaces
 * `woocommerce_rest_invalid_order_id` 404; a missing refund surfaces
 * `woocommerce_rest_shop_order_refund_invalid_id` 404.
 *
 * DESYNC FOOTGUN: deleting a refund removes the WooCommerce refund RECORD only. It
 * does NOT call the payment gateway, so any money already returned to the customer
 * stays returned, while the order's recorded refund total drops — the store's
 * books and the payment processor go out of sync. Use this only to remove an
 * erroneous refund record (e.g. a duplicate entry), never to "undo" a refund.
 *
 * The WC DELETE response is the prepared refund object (no `deleted` field), so
 * the ability synthesizes `deleted => true` from a non-error response.
 *
 * Only available when WooCommerce is active (it is a {@see ConditionalAbility}).
 *
 * @since 0.1.0
 */
final class DeleteOrderRefund implements ConditionalAbility {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-wc-orders/delete-order-refund';
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
			'label'               => __( 'Delete Order Refund', 'abilities-catalog-woo' ),
			'description'         => __( 'Permanently deletes a WooCommerce order refund RECORD by its parent order_id and refund id, then returns the deleted refund amount for confirmation. Refunds do not support the Trash, so this is always permanent and cannot be undone. CRITICAL FOOTGUN: deleting a refund record does NOT reverse the payment-gateway refund — money already returned to the customer stays returned, but the order\'s recorded refund total drops, so the store\'s books and the payment processor go out of sync (desync). Use this ONLY to remove an erroneous refund record, such as a duplicate entry; NEVER use it to "undo" a refund or to give money back. Discover both IDs with og-wc-orders/list-order-refunds. No edit_link is returned because the refund no longer exists.', 'abilities-catalog-woo' ),
			'category'            => 'og-wc-orders',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'order_id', 'id' ),
				'properties'           => array(
					'order_id' => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'The parent order ID the refund belongs to. Discover it with og-wc-orders/list-orders or og-wc-orders/list-order-refunds.', 'abilities-catalog-woo' ),
					),
					'id'       => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'The refund ID to permanently delete, which must belong to order_id. Discover it with og-wc-orders/list-order-refunds.', 'abilities-catalog-woo' ),
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'deleted', 'order_id', 'id', 'amount', 'force_used', 'permanent' ),
				'properties'           => array(
					'deleted'    => array(
						'type'        => 'boolean',
						'description' => __( 'Whether the refund record was permanently deleted.', 'abilities-catalog-woo' ),
					),
					'order_id'   => array(
						'type'        => 'integer',
						'description' => __( 'The parent order ID the deleted refund belonged to.', 'abilities-catalog-woo' ),
					),
					'id'         => array(
						'type'        => 'integer',
						'description' => __( 'The deleted refund\'s ID.', 'abilities-catalog-woo' ),
					),
					'amount'     => array(
						'type'        => 'string',
						'description' => __( 'The deleted refund amount as a decimal string in the order currency, so a human can confirm which refund record was removed. No edit_link is returned because the refund no longer exists.', 'abilities-catalog-woo' ),
					),
					'force_used' => array(
						'type'        => 'boolean',
						'description' => __( 'Always true: refunds do not support the Trash, so the delete is forced (permanent).', 'abilities-catalog-woo' ),
					),
					'permanent'  => array(
						'type'        => 'boolean',
						'description' => __( 'Always true: the refund record is gone forever and cannot be restored.', 'abilities-catalog-woo' ),
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
	 * Encodes the catalog capability for `og-wc-orders/delete-order-refund`. The
	 * wrapped route gates on `wc_rest_check_post_permissions( 'shop_order_refund',
	 * 'delete', $id )`, whose `delete` meta cap maps through the
	 * `shop_order`-typed `capability_type` (the `shop_order_refund` post type is
	 * registered with `capability_type => 'shop_order'`) to the primitive
	 * `delete_shop_orders`, so this mirrors that exact cap. Coarse and
	 * object-independent: the order/refund existence and the parent/child match
	 * stay in the wrapped route, so a missing order surfaces
	 * `woocommerce_rest_invalid_order_id` 404 and a missing refund surfaces
	 * `woocommerce_rest_shop_order_refund_invalid_id` 404 via {@see RestError::from()}
	 * rather than collapsing to a generic permission denial.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may delete order refunds.
	 */
	public function hasPermission( $input ): bool {
		return WooPlugin::isActive() && current_user_can( 'delete_shop_orders' );
	}

	/**
	 * Executes the ability by dispatching the internal `wc/v3` REST delete request.
	 *
	 * Pre-reads the refund (so a missing parent order or refund 404s here and so the
	 * result can name the deleted amount), then dispatches the DELETE with
	 * `force=true` (refunds do not support the Trash). The `order_id` and `id` are
	 * baked into the route path as segments — they are not query params. The WC
	 * DELETE response is the prepared refund object with no `deleted` field, so
	 * `deleted => true` is synthesized from a non-error response.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The deleted flag, ids, amount, and force/permanent flags, or the REST error.
	 */
	public function execute( $input ) {
		if ( ! WooPlugin::isActive() ) {
			return WooPlugin::unavailable();
		}

		$input    = is_array( $input ) ? $input : array();
		$order_id = absint( $input['order_id'] ?? 0 );
		$id       = absint( $input['id'] ?? 0 );

		// Capture the amount before the record is gone; a missing order or refund 404s here.
		$before = rest_do_request( new WP_REST_Request( 'GET', '/wc/v3/orders/' . $order_id . '/refunds/' . $id ) );
		if ( $before->is_error() ) {
			return RestError::from( $before );
		}

		$before_data = rest_get_server()->response_to_data( $before, false );
		$amount      = is_array( $before_data ) ? (string) ( $before_data['amount'] ?? '' ) : '';

		// Refunds do not support the Trash, so the route requires force=true; the
		// delete is always permanent.
		$request = new WP_REST_Request( 'DELETE', '/wc/v3/orders/' . $order_id . '/refunds/' . $id );
		$request->set_param( 'force', true );

		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			return RestError::from( $response );
		}

		return array(
			'deleted'    => true,
			'order_id'   => $order_id,
			'id'         => $id,
			'amount'     => $amount,
			'force_used' => true,
			'permanent'  => true,
		);
	}
}
