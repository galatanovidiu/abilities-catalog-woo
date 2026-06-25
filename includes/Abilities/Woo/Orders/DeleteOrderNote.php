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
 * Destructive delete ability: `og-wc-orders/delete-order-note`.
 *
 * Wraps `DELETE wc/v3/orders/<order_id>/notes/<id>` via `rest_do_request()`.
 * Order notes do NOT support the Trash — the route requires `force=true` (it
 * returns `woocommerce_rest_trash_not_supported` 501 otherwise) and then calls
 * `wc_delete_order_note()`, which deletes the underlying comment permanently. So
 * this ability hard-sets `force=true` server-side and does NOT expose `force` as
 * input: there is no recoverable option to offer.
 *
 * Before deleting, it reads the note text so the result can confirm what was
 * removed (and so a missing parent order or missing note returns the route's 404
 * here, not a permission collapse): a missing order is
 * `woocommerce_rest_order_invalid_id` 404 and a missing note is
 * `woocommerce_rest_invalid_id` 404.
 *
 * The WooCommerce delete response is the prepared note object, NOT a `{deleted}`
 * envelope, so `deleted => true` is synthesized from the non-error response.
 *
 * Only available when WooCommerce is active (it is a {@see ConditionalAbility}).
 *
 * @since 0.1.0
 */
final class DeleteOrderNote implements ConditionalAbility {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-wc-orders/delete-order-note';
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
			'label'               => __( 'Delete Order Note', 'abilities-catalog-woo' ),
			'description'         => __( 'Permanently deletes a single order note by ID and returns the deleted note text for confirmation. This cannot be undone: order notes do not support the Trash, so the note is force-deleted and there is no restore. Removing a note does not change the order itself (its status, totals, and line items are untouched) — it only removes that one private or customer-facing comment from the order history. Identify the parent order with og-wc-orders/list-orders and the note with og-wc-orders/list-order-notes. No edit_link is returned because the note no longer exists.', 'abilities-catalog-woo' ),
			'category'            => 'og-wc-orders',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'order_id', 'id' ),
				'properties'           => array(
					'order_id' => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'The parent order ID the note belongs to. Discover IDs with og-wc-orders/list-orders.', 'abilities-catalog-woo' ),
					),
					'id'       => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'The order-note ID to permanently delete. Discover IDs with og-wc-orders/list-order-notes.', 'abilities-catalog-woo' ),
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'deleted', 'order_id', 'id', 'note', 'force_used', 'permanent' ),
				'properties'           => array(
					'deleted'    => array(
						'type'        => 'boolean',
						'description' => __( 'Whether the order note was permanently deleted.', 'abilities-catalog-woo' ),
					),
					'order_id'   => array(
						'type'        => 'integer',
						'description' => __( 'The parent order ID the deleted note belonged to.', 'abilities-catalog-woo' ),
					),
					'id'         => array(
						'type'        => 'integer',
						'description' => __( 'The deleted order-note ID.', 'abilities-catalog-woo' ),
					),
					'note'       => array(
						'type'        => 'string',
						'description' => __( 'The text of the deleted note, captured before deletion so a human can confirm what was removed. No edit_link is returned because the note no longer exists.', 'abilities-catalog-woo' ),
					),
					'force_used' => array(
						'type'        => 'boolean',
						'description' => __( 'Always true: order notes have no Trash, so the delete is always a permanent force-delete.', 'abilities-catalog-woo' ),
					),
					'permanent'  => array(
						'type'        => 'boolean',
						'description' => __( 'Always true: the note is gone for good and cannot be restored.', 'abilities-catalog-woo' ),
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
	 * Permission check: WooCommerce's delete capability for orders.
	 *
	 * Encodes the catalog capability for the order-notes delete route: the wrapped
	 * `DELETE wc/v3/orders/<order_id>/notes/<id>` route gates on
	 * `wc_rest_check_post_permissions( 'shop_order', 'delete', ... )`, which maps the
	 * `'delete'` context to the `shop_order` post type's primitive
	 * `delete_shop_orders` cap. This is a coarse, object-independent guard; the
	 * wrapped route surfaces the specific 404 for a missing order or note via
	 * {@see RestError::from()} rather than collapsing it to a generic permission
	 * denial. The explicit activity guard keeps the denial clean when WooCommerce is
	 * inactive.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may delete order notes.
	 */
	public function hasPermission( $input ): bool {
		return WooPlugin::isActive() && current_user_can( 'delete_shop_orders' );
	}

	/**
	 * Executes the ability by dispatching the internal `wc/v3` REST delete request.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The synthesized delete envelope, or the REST error.
	 */
	public function execute( $input ) {
		if ( ! WooPlugin::isActive() ) {
			return WooPlugin::unavailable();
		}

		$input    = is_array( $input ) ? $input : array();
		$order_id = absint( $input['order_id'] ?? 0 );
		$id       = absint( $input['id'] ?? 0 );

		$path = '/wc/v3/orders/' . $order_id . '/notes/' . $id;

		// Capture the note text before the row is gone; a missing order or note 404s here.
		$before = rest_do_request( new WP_REST_Request( 'GET', $path ) );
		if ( $before->is_error() ) {
			return RestError::from( $before );
		}

		$before_data = rest_get_server()->response_to_data( $before, false );
		$note        = is_array( $before_data ) ? (string) ( $before_data['note'] ?? '' ) : '';

		// Order notes have no Trash; the route requires force=true and the delete is permanent.
		$request = new WP_REST_Request( 'DELETE', $path );
		$request->set_param( 'force', true );

		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			return RestError::from( $response );
		}

		// The WC delete response is the prepared note object, with no `deleted` flag;
		// a non-error response means the note was removed.
		return array(
			'deleted'    => true,
			'order_id'   => $order_id,
			'id'         => $id,
			'note'       => $note,
			'force_used' => true,
			'permanent'  => true,
		);
	}
}
