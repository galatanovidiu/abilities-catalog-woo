<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogWoo\Abilities\Woo\Orders;

use GalatanOvidiu\AbilitiesCatalogWoo\Contracts\ConditionalAbility;
use GalatanOvidiu\AbilitiesCatalogWoo\Support\OrderRefundListShaper;
use GalatanOvidiu\AbilitiesCatalogWoo\Support\RestError;
use GalatanOvidiu\AbilitiesCatalogWoo\Support\WooPlugin;
use WP_REST_Request;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read ability: `og-wc-orders/get-order-refund`.
 *
 * Wraps `GET wc/v3/orders/<order_id>/refunds/<id>` via `rest_do_request()` and
 * returns one refund as a flat, closed summary row (the
 * {@see OrderRefundListShaper::summary()} fields: `id`, `amount`, `reason`,
 * `date_created`, `refunded_by`). Both IDs are route segments. An unknown refund
 * id returns `woocommerce_rest_shop_order_refund_invalid_id` (404); a refund that
 * exists but belongs to a different order returns
 * `woocommerce_rest_invalid_order_refund_id` (404). Both surface via
 * {@see RestError::from()}. Use this for one refund's detail after finding its id
 * with `og-wc-orders/list-order-refunds`.
 *
 * Orders carry PII; this read exposes only the refund subset, never the raw
 * refund object or its `meta_data`. The `read_private_shop_orders` capability is
 * the hard server-side guard.
 *
 * Only available when WooCommerce is active (it is a {@see ConditionalAbility}).
 *
 * @since 0.1.0
 */
final class GetOrderRefund implements ConditionalAbility {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-wc-orders/get-order-refund';
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
			'label'               => __( 'Get Order Refund', 'abilities-catalog-woo' ),
			'description'         => __( 'Returns one WooCommerce order refund by its parent order_id and refund id, including the refund amount (a decimal string in the order currency), the reason recorded, the creation date, and the user ID of the staff member who issued it. Both IDs must match: the refund must belong to the given order, or the route returns woocommerce_rest_invalid_order_refund_id (404). Discover both IDs with og-wc-orders/list-order-refunds. Returns the refund subset only, never the raw refund or order PII.', 'abilities-catalog-woo' ),
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
						'description' => __( 'The refund ID, which must belong to order_id. Discover it with og-wc-orders/list-order-refunds.', 'abilities-catalog-woo' ),
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => OrderRefundListShaper::itemSchema(),
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
	 * Permission check: WooCommerce's order read capability.
	 *
	 * Mirrors the wrapped route's own check. WooCommerce gates a refund read on
	 * `wc_rest_check_post_permissions( 'shop_order_refund', 'read' )`, whose
	 * `read` meta cap (`read_private_posts`) maps through the
	 * `shop_order`-typed `capability_type` to the primitive
	 * `read_private_shop_orders` (the `shop_order_refund` post type is registered
	 * with `capability_type => 'shop_order'`). This is a coarse, object-independent
	 * guard: the order/refund existence and the "refund belongs to this order"
	 * check stay in the route, so an unknown refund id surfaces
	 * `woocommerce_rest_shop_order_refund_invalid_id` 404 while a refund under the
	 * wrong order surfaces `woocommerce_rest_invalid_order_refund_id` 404 via
	 * {@see RestError::from()} rather than collapsing to a generic permission denial. The activity guard keeps the
	 * denial clean when WooCommerce is off. Orders carry PII, so this read-private
	 * cap is the hard server-side guard.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may read order refunds.
	 */
	public function hasPermission( $input ): bool {
		return WooPlugin::isActive() && current_user_can( 'read_private_shop_orders' );
	}

	/**
	 * Executes the ability by dispatching the internal `wc/v3` REST request.
	 *
	 * The `order_id` and `id` are baked into the route path as segments (they are
	 * not query params), so the route enforces both existence and the
	 * parent/child match.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The shaped refund row, or the REST error.
	 */
	public function execute( $input ) {
		if ( ! WooPlugin::isActive() ) {
			return WooPlugin::unavailable();
		}

		$input    = is_array( $input ) ? $input : array();
		$order_id = absint( $input['order_id'] ?? 0 );
		$id       = absint( $input['id'] ?? 0 );

		$request  = new WP_REST_Request( 'GET', '/wc/v3/orders/' . $order_id . '/refunds/' . $id );
		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			return RestError::from( $response );
		}

		$data = rest_get_server()->response_to_data( $response, false );
		$data = is_array( $data ) ? $data : array();

		return OrderRefundListShaper::summary( $data );
	}
}
