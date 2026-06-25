<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogWoo\Abilities\Woo\Orders;

use GalatanOvidiu\AbilitiesCatalogWoo\Contracts\ConditionalAbility;
use GalatanOvidiu\AbilitiesCatalogWoo\Support\OrderWriteRequest;
use GalatanOvidiu\AbilitiesCatalogWoo\Support\OrderWriteSchema;
use GalatanOvidiu\AbilitiesCatalogWoo\Support\RestError;
use GalatanOvidiu\AbilitiesCatalogWoo\Support\WooPlugin;
use WP_REST_Request;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Write ability: `og-wc-orders/update-order`.
 *
 * Wraps `PUT wc/v3/orders/<id>` via `rest_do_request()`, changing an existing
 * order's writable fields and returning the shaped updated order
 * ({@see OrderWriteRequest::shapeResult()}, the get-order detail shape: id,
 * number, status, totals, the billing/shipping subset, line_items, edit_link).
 *
 * The id is set as a path segment by concatenation (never `set_param`), and the
 * writable fields are forwarded by {@see OrderWriteRequest::fill()} on key
 * presence, so an update changes only what the caller sent.
 *
 * Status is deliberately NOT writable here: the input schema merges
 * {@see OrderWriteSchema::writableProperties()}, which excludes `status`, and
 * never merges `createStatusProperty()`. Changing an existing order's status is
 * the separate elevated ability `og-wc-orders/update-order-status` (batch 23),
 * because the paid statuses fire stock changes and customer emails.
 *
 * The coarse type-level `edit_shop_orders` cap is the hard guard; the wrapped
 * route resolves the object-level `edit_post` meta-cap against the id and
 * surfaces a missing order as `woocommerce_rest_shop_order_invalid_id` (status
 * 400) via {@see RestError::from()}, not as a permission denial.
 *
 * Only available when WooCommerce is active (it is a {@see ConditionalAbility}).
 *
 * @since 0.1.0
 */
final class UpdateOrder implements ConditionalAbility {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-wc-orders/update-order';
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
			'label'               => __( 'Update Order', 'abilities-catalog-woo' ),
			'description'         => __( 'Updates an existing WooCommerce order by ID and returns the shaped order (id, number, status, totals, the billing/shipping subset, line_items, edit_link). Send only the fields you want to change; omitted fields are left untouched, and the billing, shipping, line_items, shipping_lines, fee_lines, and coupon_lines blocks REPLACE the current values rather than appending. Discover IDs with og-wc-orders/list-orders. This ability does NOT change status: to change an order\'s status use og-wc-orders/update-order-status (a separate ability because paid statuses such as processing and completed fire stock changes and customer emails). Use og-wc-orders/create-order to make a new order instead. WARNING: setting set_paid to true marks the order paid and fires payment_complete, which reduces stock and sends the paid-order customer emails. Surface edit_link so a human can review the change.', 'abilities-catalog-woo' ),
			'category'            => 'og-wc-orders',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'id' ),
				'properties'           => array_merge(
					array(
						'id' => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'description' => __( 'The ID of the order to update. Discover IDs with og-wc-orders/list-orders.', 'abilities-catalog-woo' ),
						),
					),
					OrderWriteSchema::writableProperties()
				),
				'additionalProperties' => false,
			),
			'output_schema'       => OrderWriteRequest::outputSchema(),
			'execute_callback'    => array( $this, 'execute' ),
			'permission_callback' => array( $this, 'hasPermission' ),
			'meta'                => array(
				'annotations'  => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => false,
				),
				'show_in_rest' => true,
				'screen'       => 'admin.php?page=wc-orders&action=edit&id={id}',
			),
		);
	}

	/**
	 * Permission check: WooCommerce's edit capability for orders.
	 *
	 * Encodes the catalog capability for `og-wc-orders/update-order`: the coarse,
	 * type-level `edit_shop_orders` primitive (WP core derives it from the
	 * `shop_order` post type's `capability_type` with `map_meta_cap`). The wrapped
	 * `wc/v3` PUT route runs `wc_rest_check_post_permissions( 'shop_order',
	 * 'edit', $id )`, which resolves the object-level `edit_post` meta-cap against
	 * the target id, so the object-level decision is deferred to the route. Doing
	 * it here would mask a missing id as a permission denial instead of the
	 * route's specific `woocommerce_rest_shop_order_invalid_id` error. The
	 * explicit activity guard keeps the denial clean when WooCommerce is inactive.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may edit orders.
	 */
	public function hasPermission( $input ): bool {
		return WooPlugin::isActive() && current_user_can( 'edit_shop_orders' );
	}

	/**
	 * Executes the ability by dispatching the internal `wc/v3` REST update request.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The shaped updated order, or the REST error
	 *                                        (e.g. `woocommerce_rest_shop_order_invalid_id`).
	 */
	public function execute( $input ) {
		if ( ! WooPlugin::isActive() ) {
			return WooPlugin::unavailable();
		}

		$input = is_array( $input ) ? $input : array();
		$id    = absint( $input['id'] ?? 0 );

		$request = new WP_REST_Request( 'PUT', '/wc/v3/orders/' . $id );
		OrderWriteRequest::fill( $request, $input );

		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			return RestError::from( $response );
		}

		$data = rest_get_server()->response_to_data( $response, false );

		return OrderWriteRequest::shapeResult( is_array( $data ) ? $data : array() );
	}
}
