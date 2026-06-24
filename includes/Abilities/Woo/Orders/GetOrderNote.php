<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogWoo\Abilities\Woo\Orders;

use GalatanOvidiu\AbilitiesCatalogWoo\Contracts\ConditionalAbility;
use GalatanOvidiu\AbilitiesCatalogWoo\Support\OrderNoteListShaper;
use GalatanOvidiu\AbilitiesCatalogWoo\Support\RestError;
use GalatanOvidiu\AbilitiesCatalogWoo\Support\WooPlugin;
use WP_REST_Request;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read ability: `wc-orders/get-order-note`.
 *
 * Wraps `GET wc/v3/orders/<order_id>/notes/<id>` via `rest_do_request()` and
 * returns one order note as a flat, closed row through
 * {@see OrderNoteListShaper::summary()} — the same shaped fields a
 * `wc-orders/list-order-notes` row carries: `id`, `author`, `note`,
 * `customer_note`, and `date_created`. A WooCommerce order note is a WordPress
 * comment on an order; the note may be an internal admin note or a
 * customer-facing one (the `customer_note` flag).
 *
 * Both `order_id` (the parent order) and `id` (the note) are required route
 * segments: the note is read from `orders/<order_id>/notes/<id>`, and the route
 * rejects an `id` that does not belong to `order_id`.
 *
 * Only available when WooCommerce is active (it is a {@see ConditionalAbility}).
 *
 * @since 0.1.0
 */
final class GetOrderNote implements ConditionalAbility {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'wc-orders/get-order-note';
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
			'label'               => __( 'Get Order Note', 'abilities-catalog-woo' ),
			'description'         => __( 'Returns one WooCommerce order note by ID: its content, author, whether it is shown to the customer, and creation date. An order note is a WordPress comment on an order. Both order_id (the parent order) and id (the note) are required; the note is read from that order and a note that belongs to a different order is rejected. Discover order_id and id with wc-orders/list-order-notes. Read-only; orders carry customer PII, so this is gated on the read_private_shop_orders capability.', 'abilities-catalog-woo' ),
			'category'            => 'wc-orders',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'order_id', 'id' ),
				'properties'           => array(
					'order_id' => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'The parent order ID the note belongs to. Discover it with wc-orders/list-orders or wc-orders/list-order-notes.', 'abilities-catalog-woo' ),
					),
					'id'       => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'The order note ID. Discover it with wc-orders/list-order-notes.', 'abilities-catalog-woo' ),
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => OrderNoteListShaper::itemSchema(),
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
	 * Permission check: WooCommerce's read capability for orders.
	 *
	 * Orders carry customer PII (billing name, email, address), so the hard
	 * server-side guard is `read_private_shop_orders` — the primitive capability
	 * WooCommerce maps `wc_rest_check_post_permissions( 'shop_order', 'read' )` to,
	 * and the same baseline the wrapped `wc/v3` order-notes GET route enforces. This
	 * is a coarse, object-INDEPENDENT type-level guard: the per-object decision is
	 * deferred to the wrapped route, so a missing order surfaces its specific
	 * `woocommerce_rest_order_invalid_id` 404 and a missing or mismatched note
	 * surfaces `woocommerce_rest_invalid_id` 404 via {@see RestError::from()} instead
	 * of collapsing to a generic permission denial. The explicit activity guard keeps
	 * the denial clean when WooCommerce is inactive.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may read order notes.
	 */
	public function hasPermission( $input ): bool {
		return WooPlugin::isActive() && current_user_can( 'read_private_shop_orders' );
	}

	/**
	 * Executes the ability by dispatching the internal `wc/v3` REST request.
	 *
	 * The route is built by string concatenation (never `set_param`) so the
	 * `order_id` and `id` route segments land in the path the controller's regex
	 * expects.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The shaped note row, or the REST error
	 *                                        (`woocommerce_rest_order_invalid_id` 404 for a
	 *                                        missing order, `woocommerce_rest_invalid_id` 404
	 *                                        for a missing or mismatched note).
	 */
	public function execute( $input ) {
		if ( ! WooPlugin::isActive() ) {
			return WooPlugin::unavailable();
		}

		$input    = is_array( $input ) ? $input : array();
		$order_id = absint( $input['order_id'] ?? 0 );
		$id       = absint( $input['id'] ?? 0 );

		$request  = new WP_REST_Request( 'GET', '/wc/v3/orders/' . $order_id . '/notes/' . $id );
		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			return RestError::from( $response );
		}

		$data = rest_get_server()->response_to_data( $response, false );
		$data = is_array( $data ) ? $data : array();

		return OrderNoteListShaper::summary( $data );
	}
}
