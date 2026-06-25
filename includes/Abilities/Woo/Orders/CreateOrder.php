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
 * Write ability: `og-wc-orders/create-order`.
 *
 * Wraps `POST wc/v3/orders` via `rest_do_request()`, creating a new WooCommerce
 * order. Every field is optional — `status` defaults to `pending` and no field is
 * route-required — so a bare create makes an empty pending order; add `line_items`
 * to put products on it. The result is the flat, closed get-order detail shape
 * (the {@see \GalatanOvidiu\AbilitiesCatalogWoo\Support\OrderListShaper::detail()}
 * record): the order `id`, `number`, `status`, totals, `customer_id`, the trimmed
 * billing/shipping blocks, `line_items`, and an `edit_link` — never the raw
 * ~100-field order or its `meta_data`.
 *
 * SIDE EFFECTS the caller must understand before setting payment fields: this
 * ability can fire `payment_complete`. That happens two ways — `set_paid = true`,
 * and creating directly into a paid status (`processing` or `completed`). Either
 * marks the order paid, which REDUCES STOCK and SENDS THE PAID-ORDER CUSTOMER
 * EMAILS. Leave `set_paid` false (the default) and use `pending`/`on-hold` for an
 * unpaid order if those effects are not wanted.
 *
 * To change an existing order use `og-wc-orders/update-order`; to change an existing
 * order's status use `og-wc-orders/update-order-status` (a separate ability because
 * paid statuses fire stock changes and customer emails). `status` here sets the
 * INITIAL status only.
 *
 * Only available when WooCommerce is active (it is a {@see ConditionalAbility}).
 *
 * @since 0.1.0
 */
final class CreateOrder implements ConditionalAbility {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-wc-orders/create-order';
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
			'label'               => __( 'Create Order', 'abilities-catalog-woo' ),
			'description'         => __( 'Creates a new WooCommerce order and returns the shaped order: id, number, status, totals, customer_id, the billing/shipping blocks, line_items, and edit_link. Every field is optional; status defaults to pending and no field is required, so add line_items (each a product_id and quantity) to put products on the order. SIDE EFFECTS: set_paid=true marks the order paid and fires payment_complete, which sets the status to processing, REDUCES STOCK, and SENDS THE PAID-ORDER CUSTOMER EMAILS (WooCommerce: "It will set the status to processing and reduce stock items."); creating directly into a paid status (processing or completed) does the same. Keep set_paid false and use pending or on-hold for an unpaid order to avoid those effects. To edit an existing order use og-wc-orders/update-order; to change an order\'s status use og-wc-orders/update-order-status (status here is the INITIAL status only). After creating, surface edit_link so a human can review the order.', 'abilities-catalog-woo' ),
			'category'            => 'og-wc-orders',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array_merge(
					OrderWriteSchema::writableProperties(),
					array(
						'status' => OrderWriteSchema::createStatusProperty(),
					)
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
	 * Permission check: WooCommerce's create capability for orders.
	 *
	 * Mirrors `wc_rest_check_post_permissions( 'shop_order', 'create' )`, which maps
	 * the `shop_order` post type's `publish_posts` meta-cap to the core-derived
	 * primitive `publish_shop_orders` (the post type is registered with
	 * `capability_type = 'shop_order'`, `map_meta_cap = true`). This is the coarse,
	 * object-INDEPENDENT type-level guard the wrapped `wc/v3` create route enforces
	 * (with no object id); the wrapped route surfaces the schema's own 400 via
	 * {@see RestError::from()} instead of collapsing it to a generic permission
	 * denial. The explicit activity guard keeps the denial clean when WooCommerce
	 * is inactive. Under HPOS the same `publish_shop_orders` cap is re-derived, so
	 * this guard is storage-agnostic.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may create orders.
	 */
	public function hasPermission( $input ): bool {
		return WooPlugin::isActive() && current_user_can( 'publish_shop_orders' );
	}

	/**
	 * Executes the ability by dispatching the internal `wc/v3` REST create request.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The shaped created order, or the REST error.
	 */
	public function execute( $input ) {
		if ( ! WooPlugin::isActive() ) {
			return WooPlugin::unavailable();
		}

		$input = is_array( $input ) ? $input : array();

		$request = new WP_REST_Request( 'POST', '/wc/v3/orders' );

		OrderWriteRequest::fill( $request, $input );

		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			return RestError::from( $response );
		}

		$data = rest_get_server()->response_to_data( $response, false );

		return OrderWriteRequest::shapeResult( is_array( $data ) ? $data : array() );
	}
}
