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
 * Read ability: `wc-orders/list-order-notes`.
 *
 * Wraps `GET wc/v3/orders/{order_id}/notes` via `rest_do_request()` and returns
 * the order's notes as flat summary rows through {@see OrderNoteListShaper::summary()}.
 * An order note is a WordPress comment on the order — either a system note
 * WooCommerce records automatically (status changes, payment events) or a note an
 * admin added. The `order_id` identifies the parent order and is a required route
 * segment, so this read is always scoped to one order.
 *
 * Only available when WooCommerce is active (it is a {@see ConditionalAbility}).
 * The WC order-notes list route returns a bare array with no pagination headers,
 * so `total` is the number of rows returned.
 *
 * @since 0.1.0
 */
final class ListOrderNotes implements ConditionalAbility {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'wc-orders/list-order-notes';
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
			'label'               => __( 'List Order Notes', 'abilities-catalog-woo' ),
			'description'         => __( 'Returns the notes on one WooCommerce order as flat summary rows, each with its id, author, note text, customer_note flag, and date_created. Notes include system notes WooCommerce records automatically (status changes, payment events) and notes an admin added; customer_note is true for notes shown to the customer. Identify the parent order with order_id (discover it with wc-orders/list-orders). Read-only: does not create notes or return order details — use wc-orders/get-order for the order itself.', 'abilities-catalog-woo' ),
			'category'            => 'wc-orders',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'order_id' ),
				'properties'           => array(
					'order_id' => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'The parent order ID whose notes to list. Discover order IDs with wc-orders/list-orders.', 'abilities-catalog-woo' ),
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'order_id', 'items', 'total' ),
				'properties'           => array(
					'order_id' => array(
						'type'        => 'integer',
						'description' => __( 'The parent order ID the notes belong to.', 'abilities-catalog-woo' ),
					),
					'items'    => array(
						'type'        => 'array',
						'description' => __( 'The order notes as flat summary rows.', 'abilities-catalog-woo' ),
						'items'       => OrderNoteListShaper::itemSchema(),
					),
					'total'    => array(
						'type'        => 'integer',
						'description' => __( 'The number of notes returned. The order-notes list route exposes no total header, so this counts the returned rows.', 'abilities-catalog-woo' ),
					),
				),
				'additionalProperties' => false,
			),
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
	 * Encodes the catalog baseline for `wc-orders/list-order-notes`: the
	 * `read_private_shop_orders` capability, which is what
	 * `wc_rest_check_post_permissions( 'shop_order', 'read' )` resolves to on the
	 * wrapped `GET wc/v3/orders/{order_id}/notes` route (the `shop_order` post type
	 * maps the `read` context to its `read_private_shop_orders` cap). Orders carry
	 * PII, so this capability is the hard server-side guard. It is a coarse, object-
	 * independent check; the wrapped route surfaces the specific
	 * `woocommerce_rest_shop_order_invalid_id` 404 for a missing parent order. The
	 * explicit activity guard keeps the denial clean when WooCommerce is inactive
	 * and the capability is unmapped.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may read order notes.
	 */
	public function hasPermission( $input ): bool {
		return WooPlugin::isActive() && current_user_can( 'read_private_shop_orders' );
	}

	/**
	 * Executes the ability by dispatching the internal WooCommerce REST request.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The order's notes, or the REST error.
	 */
	public function execute( $input ) {
		if ( ! WooPlugin::isActive() ) {
			return WooPlugin::unavailable();
		}

		$input    = is_array( $input ) ? $input : array();
		$order_id = absint( $input['order_id'] ?? 0 );

		$request  = new WP_REST_Request( 'GET', '/wc/v3/orders/' . $order_id . '/notes' );
		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			return RestError::from( $response );
		}

		$data = rest_get_server()->response_to_data( $response, false );

		$rows = array();
		foreach ( is_array( $data ) ? $data : array() as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$rows[] = OrderNoteListShaper::summary( $item );
		}

		return array(
			'order_id' => $order_id,
			'items'    => $rows,
			'total'    => count( $rows ),
		);
	}
}
