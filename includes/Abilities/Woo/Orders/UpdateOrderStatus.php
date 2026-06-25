<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogWoo\Abilities\Woo\Orders;

use GalatanOvidiu\AbilitiesCatalogWoo\Contracts\ConditionalAbility;
use GalatanOvidiu\AbilitiesCatalogWoo\Support\OrderListShaper;
use GalatanOvidiu\AbilitiesCatalogWoo\Support\RestError;
use GalatanOvidiu\AbilitiesCatalogWoo\Support\WooPlugin;
use WP_REST_Request;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Elevated write ability: `og-wc-orders/update-order-status`.
 *
 * Wraps `PUT wc/v3/orders/<id>` via `rest_do_request()`, sending ONLY the
 * `status` field, and returns the shaped order ({@see OrderListShaper::summary()})
 * plus a `previous_status` field captured from a pre-read GET, so the agent sees
 * the transition (`previous_status` → `status`). The id is set as a path segment
 * by concatenation (never `set_param`).
 *
 * Side effects (LOUD, the agent-UX safeguard): a status change runs WooCommerce's
 * status-transition handlers in `WC_Order::save()`. Moving an order to a PAID
 * status (`processing` or `completed`) reduces stock, runs the order's
 * `payment_complete` path, and sends the paid-order customer emails. These side
 * effects are real and irreversible — they are NOT undone by changing the status
 * back (the emails are already sent, the stock already changed). This is a
 * separate, elevated ability from `og-wc-orders/update-order` (which deliberately
 * cannot change status) precisely because of these side effects.
 *
 * The coarse type-level `edit_shop_orders` cap is the hard guard; the wrapped
 * route resolves the object-level `edit_post` meta-cap against the id and
 * surfaces a missing order as `woocommerce_rest_shop_order_invalid_id` (status
 * 400) via {@see RestError::from()}, not as a permission denial. The pre-read GET
 * surfaces the same missing-order error before the write.
 *
 * Only available when WooCommerce is active (it is a {@see ConditionalAbility}).
 *
 * @since 0.1.0
 */
final class UpdateOrderStatus implements ConditionalAbility {

	/**
	 * The order statuses the agent may set, WITHOUT the `wc-` prefix.
	 *
	 * The source-verified registered set from the V2 orders controller's
	 * `get_order_statuses()` (`array_keys( wc_get_order_statuses() )` with the
	 * `wc-` prefix stripped), minus the internal `auto-draft`. The wrapped route
	 * accepts the bare slug; it stores the `wc-` prefix internally and strips it on
	 * read, so sending a `wc-` prefix is wrong.
	 *
	 * @var list<string>
	 */
	private const STATUSES = array(
		'pending',
		'processing',
		'on-hold',
		'completed',
		'cancelled',
		'refunded',
		'failed',
	);

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-wc-orders/update-order-status';
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
			'label'               => __( 'Update Order Status', 'abilities-catalog-woo' ),
			'description'         => __( 'Changes an existing WooCommerce order\'s status and returns the shaped order (id, number, status, total, customer_id, the billing-name subset, edit_link) plus previous_status so you can see the transition. This is the ONLY way to change an order\'s status: og-wc-orders/update-order cannot change status and routes here. WARNING — this triggers real, irreversible side effects: moving an order to a PAID status (processing or completed) runs WooCommerce\'s status-transition handlers, which REDUCE STOCK, run the order\'s payment_complete path, and SEND THE PAID-ORDER CUSTOMER EMAILS. Those side effects are already done and are NOT reversed by changing the status back (the emails are already sent, the stock already changed). It is a separate, elevated ability from og-wc-orders/update-order precisely because of these side effects. Setting the order to the status it already has is a no-op. Discover IDs with og-wc-orders/list-orders.', 'abilities-catalog-woo' ),
			'category'            => 'og-wc-orders',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'id', 'status' ),
				'properties'           => array(
					'id'     => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'The ID of the order whose status to change. Discover IDs with og-wc-orders/list-orders.', 'abilities-catalog-woo' ),
					),
					'status' => array(
						'type'        => 'string',
						'enum'        => self::STATUSES,
						'description' => __( 'The new order status, a bare slug WITHOUT the wc- prefix: pending, processing, on-hold, completed, cancelled, refunded, or failed. WARNING: moving to a PAID status (processing or completed) reduces stock, runs payment_complete, and sends the paid-order customer emails — these are not reversed by changing the status back.', 'abilities-catalog-woo' ),
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
					'idempotent'  => true,
				),
				'show_in_rest' => true,
				'screen'       => 'admin.php?page=wc-orders&action=edit&id={id}',
			),
		);
	}

	/**
	 * Permission check: WooCommerce's edit capability for orders.
	 *
	 * Encodes the catalog capability for `og-wc-orders/update-order-status`: the
	 * coarse, type-level `edit_shop_orders` primitive (WP core derives it from the
	 * `shop_order` post type's `capability_type` with `map_meta_cap`). The wrapped
	 * `wc/v3` PUT route runs `wc_rest_check_post_permissions( 'shop_order',
	 * 'edit', $id )`, which resolves the object-level `edit_post` meta-cap against
	 * the target id, so the object-level decision is deferred to the route. Doing
	 * it here would mask a missing id as a permission denial instead of the route's
	 * specific `woocommerce_rest_shop_order_invalid_id` error. The explicit
	 * activity guard keeps the denial clean when WooCommerce is inactive.
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
	 * Reads the current status first (so the missing-order invalid-id error also
	 * surfaces here), then dispatches the PUT with only the `status` param, and
	 * returns the shaped order with the captured `previous_status`.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The shaped order plus `previous_status`,
	 *                                        or the REST error (e.g.
	 *                                        `woocommerce_rest_shop_order_invalid_id`).
	 */
	public function execute( $input ) {
		if ( ! WooPlugin::isActive() ) {
			return WooPlugin::unavailable();
		}

		$input  = is_array( $input ) ? $input : array();
		$id     = absint( $input['id'] ?? 0 );
		$status = (string) ( $input['status'] ?? '' );

		// Snapshot the status before the change. A missing order 400s here.
		$before = rest_do_request( new WP_REST_Request( 'GET', '/wc/v3/orders/' . $id ) );
		if ( $before->is_error() ) {
			return RestError::from( $before );
		}

		$before_data     = rest_get_server()->response_to_data( $before, false );
		$previous_status = is_array( $before_data ) ? (string) ( $before_data['status'] ?? '' ) : '';

		$request = new WP_REST_Request( 'PUT', '/wc/v3/orders/' . $id );
		$request->set_param( 'status', $status );

		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			return RestError::from( $response );
		}

		$data   = rest_get_server()->response_to_data( $response, false );
		$result = OrderListShaper::summary( is_array( $data ) ? $data : array() );

		$result['previous_status'] = $previous_status;

		return $result;
	}

	/**
	 * The `output_schema`: the shaped order summary plus `previous_status`.
	 *
	 * Reuses {@see OrderListShaper::itemSchema()} and adds the captured
	 * `previous_status` field so the agent can read the transition.
	 *
	 * @return array<string,mixed> A closed JSON-Schema object fragment.
	 */
	private function outputSchema(): array {
		$schema = OrderListShaper::itemSchema();

		$schema['properties']['previous_status'] = array(
			'type'        => 'string',
			'description' => __( 'The order status BEFORE this change, captured from a pre-read so a human can see the transition (previous_status to status). Empty if it could not be read.', 'abilities-catalog-woo' ),
		);

		return $schema;
	}
}
