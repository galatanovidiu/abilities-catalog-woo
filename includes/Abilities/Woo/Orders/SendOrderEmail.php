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
 * Elevated, side-effecting write ability: `wc-orders/send-order-email`.
 *
 * Wraps `POST wc/v3/orders/<id>/actions/send_email` (WooCommerce's
 * `OrderActionsRestController`, whose `route_namespace` is `wc/v3`; the controller's
 * `get_rest_api_namespace()` key `order-actions` is a registration key, NOT part of
 * the route path) via `rest_do_request()`. It dispatches one of the WooCommerce
 * customer-facing email templates to the order's existing billing address.
 *
 * SIDE EFFECT (LOUD — this IS the agent-UX safeguard for this T2-elevated write):
 * this sends a REAL email to the order's customer and records an order note. It is
 * NOT a preview or a dry run. The send cannot be undone once it leaves.
 *
 * The route auto-selects the best template for the order's status when `template_id`
 * is omitted; a `template_id` that is invalid for the order's current status (e.g.
 * `customer_completed_order` on a `pending` order) is rejected with
 * `woocommerce_rest_invalid_email_template` (status 400). An order with no billing
 * email is rejected with `woocommerce_rest_missing_email` (status 400).
 *
 * The success response carries only a human-readable `{ message }`, so this ability
 * sets `sent => true` on success and echoes the requested `template_id` plus, from a
 * pre-read GET, the order's billing email — the route does not return either. The
 * pre-read GET also surfaces a missing order as `woocommerce_rest_not_found` (status
 * 404) before the POST.
 *
 * The coarse, object-independent `read_private_shop_orders` primitive cap is the
 * type-level hard guard (matching the sibling order reads); the wrapped route runs its
 * own per-object `read_shop_order` meta-cap check against the id, so the object-level
 * decision is deferred to the route.
 *
 * Only available when WooCommerce is active (it is a {@see ConditionalAbility}).
 *
 * @since 0.1.0
 */
final class SendOrderEmail implements ConditionalAbility {

	/**
	 * The customer-facing WooCommerce email-template ids this ability exposes.
	 *
	 * The route's full `template_id` enum is every registered `WC_Email->id`; this
	 * is the documented customer-facing subset (the `STATUS_TEMPLATE_MAP` ids plus
	 * `customer_invoice`). Bound to the input schema so the schema and the agent-
	 * facing vocabulary cannot drift.
	 *
	 * @var list<string>
	 */
	private const TEMPLATE_IDS = array(
		'customer_invoice',
		'customer_completed_order',
		'customer_processing_order',
		'customer_on_hold_order',
		'customer_failed_order',
		'customer_refunded_order',
	);

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'wc-orders/send-order-email';
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
			'label'               => __( 'Send Order Email', 'abilities-catalog-woo' ),
			'description'         => __( 'Sends a WooCommerce customer email for an existing order to the order\'s billing address and returns { sent, id, template_id, customer_email }. SIDE EFFECT: this sends a REAL email to the customer AND records an order note — it is not a preview or a dry run, and it cannot be undone once sent, so only call it when you intend the customer to receive the email. Pick the template with template_id (e.g. customer_invoice, customer_completed_order); omit it to let WooCommerce auto-select the best template for the order\'s current status. A template that is not valid for the order\'s current status (e.g. customer_completed_order on a pending order) is rejected with a 400 woocommerce_rest_invalid_email_template error, and an order with no billing email is rejected with a 400 woocommerce_rest_missing_email error. Discover order IDs with wc-orders/list-orders.', 'abilities-catalog-woo' ),
			'category'            => 'wc-orders',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'id' ),
				'properties'           => array(
					'id'          => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'The ID of the order to email about. Discover IDs with wc-orders/list-orders.', 'abilities-catalog-woo' ),
					),
					'template_id' => array(
						'type'        => 'string',
						'enum'        => self::TEMPLATE_IDS,
						'description' => __( 'The customer email template to send: customer_invoice (order details), customer_completed_order, customer_processing_order, customer_on_hold_order, customer_failed_order, or customer_refunded_order. Optional — omit to let WooCommerce auto-select the best template for the order\'s current status. A template that is not valid for the order\'s current status (e.g. customer_completed_order on a pending order) is rejected with a 400 woocommerce_rest_invalid_email_template error.', 'abilities-catalog-woo' ),
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'sent', 'id', 'template_id' ),
				'properties'           => array(
					'sent'           => array(
						'type'        => 'boolean',
						'description' => __( 'True when WooCommerce sent the email. Use this as the success signal.', 'abilities-catalog-woo' ),
					),
					'id'             => array(
						'type'        => 'integer',
						'description' => __( 'The order ID the email was sent for.', 'abilities-catalog-woo' ),
					),
					'template_id'    => array(
						'type'        => 'string',
						'description' => __( 'The email template that was sent. This echoes the requested template_id; it is an empty string when template_id was omitted and WooCommerce auto-selected the template (the route does not report which template it chose).', 'abilities-catalog-woo' ),
					),
					'customer_email' => array(
						'type'        => 'string',
						'description' => __( 'The order billing email the message was sent to. Present only when the order has a billing email; visible only with the order capability.', 'abilities-catalog-woo' ),
					),
				),
				'additionalProperties' => false,
			),
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
	 * Permission check: WooCommerce's primitive read capability for the store's orders.
	 *
	 * Gates on the coarse, object-independent `read_private_shop_orders` primitive cap,
	 * matching the sibling order reads ({@see GetOrder}, {@see ListOrders}). The wrapped
	 * route checks the real per-object `read_shop_order` meta-cap against the id
	 * (`OrderActionsRestController::check_permission( $request, 'read_shop_order',
	 * $order_id )`), so the object-level decision is deferred to the route.
	 * `read_shop_order` is a per-object meta-cap that maps to `read_post`/
	 * `read_private_post`, so checking it here object-independently would deny every
	 * caller; and doing the object-level check here would mask a missing order as a
	 * permission denial instead of the route's specific `woocommerce_rest_not_found`
	 * 404. The explicit activity guard keeps the denial clean when WooCommerce is
	 * inactive.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may read orders.
	 */
	public function hasPermission( $input ): bool {
		return WooPlugin::isActive() && current_user_can( 'read_private_shop_orders' );
	}

	/**
	 * Executes the ability by dispatching the internal `wc/v3` send-email request.
	 *
	 * Reads the order first (so a missing order 404s here and so the billing email
	 * is available to echo), then dispatches the send. The route's success response
	 * carries only `{ message }`, so `sent` is set to true on success and the
	 * `template_id`/`customer_email` are populated from the input and the pre-read.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The send result, or the REST error (e.g.
	 *                                        `woocommerce_rest_not_found` 404,
	 *                                        `woocommerce_rest_missing_email` 400,
	 *                                        `woocommerce_rest_invalid_email_template` 400).
	 */
	public function execute( $input ) {
		if ( ! WooPlugin::isActive() ) {
			return WooPlugin::unavailable();
		}

		$input = is_array( $input ) ? $input : array();

		$id          = absint( $input['id'] ?? 0 );
		$template_id = isset( $input['template_id'] ) ? (string) $input['template_id'] : '';

		// Pre-read the order so a missing order surfaces the route's 404 here, and
		// so the billing email is available to echo (the send response omits it).
		$before = rest_do_request( new WP_REST_Request( 'GET', '/wc/v3/orders/' . $id ) );
		if ( $before->is_error() ) {
			return RestError::from( $before );
		}

		$before_data    = rest_get_server()->response_to_data( $before, false );
		$before_billing = is_array( $before_data ) && is_array( $before_data['billing'] ?? null )
			? $before_data['billing']
			: array();
		$customer_email = (string) ( $before_billing['email'] ?? '' );

		$request = new WP_REST_Request( 'POST', '/wc/v3/orders/' . $id . '/actions/send_email' );

		// Forward template_id only when given; omitting it lets WC auto-select.
		if ( '' !== $template_id ) {
			$request->set_param( 'template_id', $template_id );
		}

		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			return RestError::from( $response );
		}

		$result = array(
			'sent'        => true,
			'id'          => $id,
			'template_id' => $template_id,
		);

		if ( '' !== $customer_email ) {
			$result['customer_email'] = $customer_email;
		}

		return $result;
	}
}
