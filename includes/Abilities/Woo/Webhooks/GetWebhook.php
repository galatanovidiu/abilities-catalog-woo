<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogWoo\Abilities\Woo\Webhooks;

use GalatanOvidiu\AbilitiesCatalogWoo\Contracts\ConditionalAbility;
use GalatanOvidiu\AbilitiesCatalogWoo\Support\RestError;
use GalatanOvidiu\AbilitiesCatalogWoo\Support\WebhookListShaper;
use GalatanOvidiu\AbilitiesCatalogWoo\Support\WooPlugin;
use WP_REST_Request;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read ability: `wc-webhooks/get-webhook`.
 *
 * Wraps `GET /wc/v3/webhooks/<id>` via `rest_do_request()` and returns one
 * webhook's flat detail row through {@see WebhookListShaper::detail()}. The
 * request dispatches in the default `view` context, never `edit`, so the
 * response cannot carry the webhook's signing `secret`.
 *
 * The `secret` is the HMAC key WooCommerce uses to sign every delivered payload;
 * it is a stored credential and is REDACTED entirely. In its place the detail row
 * carries a `has_secret` boolean (so an agent can tell signed deliveries from
 * unsigned without seeing the key) and `failure_count`; both are derived inside
 * {@see WebhookListShaper::detail()} from a non-route core read and never expose
 * the secret value. The redaction lives in the shaper, so this ability physically
 * cannot leak the key.
 *
 * Only available when WooCommerce is active (it is a {@see ConditionalAbility}).
 *
 * @since 0.1.0
 */
final class GetWebhook implements ConditionalAbility {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'wc-webhooks/get-webhook';
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
			'label'               => __( 'Get Webhook', 'abilities-catalog-woo' ),
			'description'         => __( 'Returns one WooCommerce webhook by ID: its name, status, topic, resource, event, hooks (the WooCommerce action names that fire a delivery), delivery URL, failure count, and dates. The signing secret is signed but hidden — it is never returned; instead has_secret tells you whether deliveries are signed (true) or unsigned (false). Discover IDs with wc-webhooks/list-webhooks.', 'abilities-catalog-woo' ),
			'category'            => 'wc-webhooks',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'id' ),
				'properties'           => array(
					'id' => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'The webhook ID. Discover IDs with wc-webhooks/list-webhooks.', 'abilities-catalog-woo' ),
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => WebhookListShaper::detailSchema(),
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
	 * Permission check: WooCommerce's manager capability.
	 *
	 * The wrapped route resolves read access through
	 * `wc_rest_check_manager_permissions( 'webhooks', 'read' )`, which requires
	 * `manage_woocommerce`; this coarse, object-independent gate mirrors it and is
	 * never weaker than that baseline. The object-level decision is deferred to the
	 * wrapped route, so a missing id surfaces as the route's specific
	 * `woocommerce_rest_shop_webhook_invalid_id` 404 instead of a permission
	 * collapse.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may read webhooks.
	 */
	public function hasPermission( $input ): bool {
		return WooPlugin::isActive() && current_user_can( 'manage_woocommerce' );
	}

	/**
	 * Executes the ability by dispatching the internal `wc/v3` REST request.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The shaped webhook detail row, or the REST error.
	 */
	public function execute( $input ) {
		if ( ! WooPlugin::isActive() ) {
			return WooPlugin::unavailable();
		}

		$input = is_array( $input ) ? $input : array();
		$id    = absint( $input['id'] ?? 0 );

		$request  = new WP_REST_Request( 'GET', '/wc/v3/webhooks/' . $id );
		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			return RestError::from( $response );
		}

		$data = rest_get_server()->response_to_data( $response, false );

		return WebhookListShaper::detail( is_array( $data ) ? $data : array() );
	}
}
