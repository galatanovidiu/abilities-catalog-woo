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
 * Write ability: `wc-orders/add-order-note`.
 *
 * Wraps `POST wc/v3/orders/<order_id>/notes` via `rest_do_request()`, adding a note
 * to an existing order. The `order_id` is a required route segment, so it is built
 * into the path by concatenation; the note content and the two flags are forwarded
 * as request params. The result is the flat, closed
 * {@see OrderNoteListShaper::summary()} record of the created note — its `id`,
 * `author`, `note`, `customer_note` flag, and `date_created` — plus the parent
 * `order_id`, not the raw comment payload.
 *
 * Side effect (LOUD): with `customer_note = true` WooCommerce shows the note to the
 * customer AND emails it to them; with `customer_note = false` (the default) the
 * note is a private admin reference note that is never shown or emailed.
 *
 * Only available when WooCommerce is active (it is a {@see ConditionalAbility}).
 *
 * @since 0.1.0
 */
final class AddOrderNote implements ConditionalAbility {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'wc-orders/add-order-note';
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
			'label'               => __( 'Add Order Note', 'abilities-catalog-woo' ),
			'description'         => __( 'Adds a note to an existing WooCommerce order and returns the created note: its id, author, note text, customer_note flag, date_created, and the parent order_id. By default (customer_note false) the note is a private admin reference note. Setting customer_note to true SHOWS the note to the customer AND EMAILS it to them, so only set it for a message you intend the customer to receive. Set added_by_user to true to attribute the note to the current user instead of the system. This does not change the order itself; to edit an order use wc-orders/update-order and to change its status use wc-orders/update-order-status.', 'abilities-catalog-woo' ),
			'category'            => 'wc-orders',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'order_id', 'note' ),
				'properties'           => array(
					'order_id'      => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'The parent order ID to attach the note to. Discover it with wc-orders/list-orders.', 'abilities-catalog-woo' ),
					),
					'note'          => array(
						'type'        => 'string',
						'description' => __( 'The note content.', 'abilities-catalog-woo' ),
					),
					'customer_note' => array(
						'type'        => 'boolean',
						'default'     => false,
						'description' => __( 'If true, the note is shown to the customer AND the customer is emailed it. If false (the default), the note is a private admin reference note that is never shown or emailed.', 'abilities-catalog-woo' ),
					),
					'added_by_user' => array(
						'type'        => 'boolean',
						'default'     => false,
						'description' => __( 'If true, the note is attributed to the current user. If false (the default), it is attributed to the system.', 'abilities-catalog-woo' ),
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => $this->outputSchema(),
			'execute_callback'    => array( $this, 'execute' ),
			'permission_callback' => array( $this, 'hasPermission' ),
			'meta'                => array(
				'annotations'  => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => false,
				),
				'show_in_rest' => true,
				'screen'       => 'admin.php?page=wc-orders&action=edit&id={order_id}',
			),
		);
	}

	/**
	 * Permission check: WooCommerce's create capability for orders.
	 *
	 * Mirrors `wc_rest_check_post_permissions( 'shop_order', 'create' )`, which maps
	 * the `shop_order` post type's `publish_posts` meta-cap to the core-derived
	 * primitive `publish_shop_orders` — the baseline the wrapped `wc/v3` note-create
	 * route enforces. This is a coarse, object-INDEPENDENT type-level guard; the
	 * wrapped route surfaces the object-level error (a missing parent order is
	 * `woocommerce_rest_order_invalid_id` 404) via {@see RestError::from()} instead
	 * of collapsing it to a generic permission denial. The explicit activity guard
	 * keeps the denial clean when WooCommerce is inactive.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may add order notes.
	 */
	public function hasPermission( $input ): bool {
		return WooPlugin::isActive() && current_user_can( 'publish_shop_orders' );
	}

	/**
	 * Executes the ability by dispatching the internal `wc/v3` REST note-create request.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The shaped created note plus its parent
	 *                                        order_id, or the REST error (e.g.
	 *                                        `woocommerce_rest_order_invalid_id` 404).
	 */
	public function execute( $input ) {
		if ( ! WooPlugin::isActive() ) {
			return WooPlugin::unavailable();
		}

		$input = is_array( $input ) ? $input : array();

		$order_id = absint( $input['order_id'] ?? 0 );

		// The order_id is a required route segment, so build it into the path.
		$request = new WP_REST_Request( 'POST', '/wc/v3/orders/' . $order_id . '/notes' );
		$request->set_param( 'note', (string) ( $input['note'] ?? '' ) );
		$request->set_param( 'customer_note', (bool) ( $input['customer_note'] ?? false ) );
		$request->set_param( 'added_by_user', (bool) ( $input['added_by_user'] ?? false ) );

		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			return RestError::from( $response );
		}

		$data = rest_get_server()->response_to_data( $response, false );

		$note = OrderNoteListShaper::summary( is_array( $data ) ? $data : array() );

		return array_merge(
			array( 'order_id' => $order_id ),
			$note
		);
	}

	/**
	 * The shared `output_schema`: the parent order_id plus the shaped note row.
	 *
	 * @return array<string,mixed> A closed JSON-Schema object fragment.
	 */
	private function outputSchema(): array {
		$item = OrderNoteListShaper::itemSchema();

		return array(
			'type'                 => 'object',
			'required'             => array( 'order_id', 'id' ),
			'properties'           => array_merge(
				array(
					'order_id' => array(
						'type'        => 'integer',
						'description' => __( 'The parent order ID the note was added to.', 'abilities-catalog-woo' ),
					),
				),
				$item['properties']
			),
			'additionalProperties' => false,
		);
	}
}
