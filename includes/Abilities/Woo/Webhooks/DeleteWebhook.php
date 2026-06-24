<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogWoo\Abilities\Woo\Webhooks;

use GalatanOvidiu\AbilitiesCatalogWoo\Contracts\ConditionalAbility;
use GalatanOvidiu\AbilitiesCatalogWoo\Support\RestError;
use GalatanOvidiu\AbilitiesCatalogWoo\Support\WooPlugin;
use WP_REST_Request;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Destructive write ability: `wc-webhooks/delete-webhook`.
 *
 * Wraps `DELETE /wc/v3/webhooks/<id>` via `rest_do_request()`. The route does not
 * support trashing: with `force=false` (its default) it returns a 501
 * `woocommerce_rest_trash_not_supported`, so this ability MUST send `force=true`.
 * The delete is therefore permanent — the route calls `$webhook->delete( true )`
 * (a force delete bypassing the Trash), and the webhook stops receiving any
 * further deliveries with no restore.
 *
 * Before deleting, this reads the webhook's name via a `GET /wc/v3/webhooks/<id>`
 * in the default `view` context, so the result can confirm what was removed and a
 * missing webhook returns the route's `woocommerce_rest_shop_webhook_invalid_id`
 * 404 here (the `view` context never carries the webhook's signing `secret`, and
 * the output projects only `id` and `name`, so the key cannot leak either way).
 *
 * This is the only webhook write in the catalog. The create and update webhook
 * operations are the deferred dangerous tier (a free-form `delivery_url` is a
 * data-exfiltration vector) and are not available.
 *
 * Only available when WooCommerce is active (it is a {@see ConditionalAbility}).
 *
 * @since 0.1.0
 */
final class DeleteWebhook implements ConditionalAbility {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'wc-webhooks/delete-webhook';
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
			'label'               => __( 'Delete Webhook', 'abilities-catalog-woo' ),
			'description'         => __( 'Permanently deletes a WooCommerce webhook by ID. This cannot be undone: WooCommerce force-deletes the webhook, bypassing the Trash (the route returns a 501 error if asked not to), so there is no restore, and the webhook stops receiving any further deliveries. Returns the deleted webhook\'s name for confirmation; no edit_link is returned because the webhook no longer exists. Discover IDs with wc-webhooks/list-webhooks. Note: this is the only webhook write in the catalog — creating and updating webhooks are the deferred dangerous tier (a free-form delivery_url is a data-exfiltration vector) and are not available.', 'abilities-catalog-woo' ),
			'category'            => 'wc-webhooks',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'id' ),
				'properties'           => array(
					'id' => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'The webhook ID to permanently delete. Discover IDs with wc-webhooks/list-webhooks.', 'abilities-catalog-woo' ),
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'deleted', 'id' ),
				'properties'           => array(
					'deleted'   => array(
						'type'        => 'boolean',
						'description' => __( 'Whether the webhook was permanently deleted.', 'abilities-catalog-woo' ),
					),
					'id'        => array(
						'type'        => 'integer',
						'description' => __( 'The deleted webhook\'s ID.', 'abilities-catalog-woo' ),
					),
					'name'      => array(
						'type'        => 'string',
						'description' => __( 'The name of the deleted webhook, captured before deletion so a human can confirm what was removed. No edit_link is returned because the webhook no longer exists.', 'abilities-catalog-woo' ),
					),
					'permanent' => array(
						'type'        => 'boolean',
						'description' => __( 'Always true: webhooks have no Trash, so the deletion is permanent and cannot be undone.', 'abilities-catalog-woo' ),
					),
				),
				'additionalProperties' => false,
			),
			'execute_callback'    => array( $this, 'execute' ),
			'permission_callback' => array( $this, 'hasPermission' ),
			'meta'                => array(
				'annotations'  => array(
					'readonly'    => false,
					'destructive' => true,
					'idempotent'  => false,
				),
				'show_in_rest' => true,
				'screen'       => 'admin.php?page=wc-settings&tab=advanced&section=webhooks',
			),
		);
	}

	/**
	 * Permission check: WooCommerce's manager capability.
	 *
	 * The wrapped route resolves delete access through
	 * `wc_rest_check_manager_permissions( 'webhooks', 'delete' )`, which requires
	 * `manage_woocommerce`; this coarse, object-independent gate mirrors it and is
	 * never weaker than that baseline. The object-level decision is deferred to the
	 * wrapped route, so a missing id surfaces as the route's specific
	 * `woocommerce_rest_shop_webhook_invalid_id` 404 instead of a permission
	 * collapse.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may delete webhooks.
	 */
	public function hasPermission( $input ): bool {
		return WooPlugin::isActive() && current_user_can( 'manage_woocommerce' );
	}

	/**
	 * Executes the ability by dispatching the internal `wc/v3` REST delete request.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The deleted flag, id, name, and permanent flag, or the REST error.
	 */
	public function execute( $input ) {
		if ( ! WooPlugin::isActive() ) {
			return WooPlugin::unavailable();
		}

		$input = is_array( $input ) ? $input : array();
		$id    = absint( $input['id'] ?? 0 );

		// Capture the name before the webhook is gone; a missing webhook 404s here.
		// The `view` context never carries the signing secret.
		$before = rest_do_request( new WP_REST_Request( 'GET', '/wc/v3/webhooks/' . $id ) );
		if ( $before->is_error() ) {
			return RestError::from( $before );
		}

		$before_data = rest_get_server()->response_to_data( $before, false );
		$name        = is_array( $before_data ) ? (string) ( $before_data['name'] ?? '' ) : '';

		// force=true is mandatory: webhooks do not support trashing, so the route
		// returns a 501 woocommerce_rest_trash_not_supported without it.
		$request = new WP_REST_Request( 'DELETE', '/wc/v3/webhooks/' . $id );
		$request->set_param( 'force', true );
		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			return RestError::from( $response );
		}

		$data = rest_get_server()->response_to_data( $response, false );

		return array(
			'deleted'   => is_array( $data ) && ! empty( $data['id'] ),
			'id'        => $id,
			'name'      => $name,
			'permanent' => true,
		);
	}
}
