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
 * Read ability: `og-wc-orders/get-order`.
 *
 * Wraps `GET wc/v3/orders/<id>` via `rest_do_request()` and returns one order as
 * a flat, closed record: the {@see OrderListShaper::summary()} fields (number,
 * status, currency, total, total_tax, date, customer, the flattened billing name
 * and email, payment method, line-item count) plus the detail a single-order view
 * needs — the line items, the trimmed billing and shipping blocks, and an
 * `edit_link`. The raw `wc/v3` order body carries roughly a hundred fields a
 * consumer never reads; this projects only the useful subset via the shaper.
 *
 * PII: an order carries billing name, email, and address. The capability
 * (`read_private_shop_orders`) is the hard server-side guard; the shaper exposes a
 * fixed subset and never the raw order or its `meta_data`.
 *
 * Only available when WooCommerce is active (it is a {@see ConditionalAbility}).
 *
 * @since 0.1.0
 */
final class GetOrder implements ConditionalAbility {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-wc-orders/get-order';
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
			'label'               => __( 'Get Order', 'abilities-catalog-woo' ),
			'description'         => __( 'Returns one WooCommerce order by ID: its number, status, currency, totals, creation date, customer ID, payment method, the line items (what was bought, quantity, and line total), the billing block (name, email, and address), the shipping block (name and address; the shipping address has no email), and an edit_link. Use og-wc-orders/list-orders to scan orders and discover IDs; use this for one order\'s full detail. Returns personal data (buyer name, email, address): visible only with the order capability. Read-only: does not return order notes or refunds — use og-wc-orders/list-order-notes and og-wc-orders/list-order-refunds for those.', 'abilities-catalog-woo' ),
			'category'            => 'og-wc-orders',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'id' ),
				'properties'           => array(
					'id' => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'The order ID. Discover IDs with og-wc-orders/list-orders.', 'abilities-catalog-woo' ),
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => OrderListShaper::detailSchema(),
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
	 * Mirrors `wc_rest_check_post_permissions( 'shop_order', 'read' )`, which maps
	 * the `shop_order` post type's `read_private_posts` meta-cap to the primitive
	 * `read_private_shop_orders` — the baseline the wrapped `wc/v3` GET route
	 * enforces. This is a coarse, object-INDEPENDENT type-level guard: the
	 * per-object decision is deferred to the wrapped route, so a missing order
	 * surfaces its specific `woocommerce_rest_shop_order_invalid_id` 404 via
	 * {@see RestError::from()} instead of collapsing to a generic permission
	 * denial. Orders carry PII, so this cap is the real protection. The explicit
	 * activity guard keeps the denial clean when WooCommerce is inactive.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may read orders.
	 */
	public function hasPermission( $input ): bool {
		return WooPlugin::isActive() && current_user_can( 'read_private_shop_orders' );
	}

	/**
	 * Executes the ability by dispatching the internal `wc/v3` REST request.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The shaped order record, or the REST error
	 *                                        (e.g. `woocommerce_rest_shop_order_invalid_id` 404).
	 */
	public function execute( $input ) {
		if ( ! WooPlugin::isActive() ) {
			return WooPlugin::unavailable();
		}

		$input = is_array( $input ) ? $input : array();
		$id    = absint( $input['id'] ?? 0 );

		$request  = new WP_REST_Request( 'GET', '/wc/v3/orders/' . $id );
		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			return RestError::from( $response );
		}

		$data = rest_get_server()->response_to_data( $response, false );
		$data = is_array( $data ) ? $data : array();

		return OrderListShaper::detail( $data );
	}
}
