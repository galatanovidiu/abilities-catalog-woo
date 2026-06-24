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
 * Read ability: `wc-webhooks/list-webhooks`.
 *
 * Wraps `GET /wc/v3/webhooks` via `rest_do_request()` and returns each webhook as
 * a flat summary row through {@see WebhookListShaper::summary()}. A webhook's
 * `secret` is the HMAC key that signs every delivered payload (a stored
 * credential); the shaper builds each row from a fixed allow-list and never reads
 * the secret, so a list row carries no credential at all. The dispatch uses the
 * default `view` context, in which WooCommerce already omits the secret, and the
 * shaper closes the leak structurally regardless of context.
 *
 * Only available when WooCommerce is active (it is a {@see ConditionalAbility}).
 * `total` is read from the route's `X-WP-Total` response header when present, so
 * it reflects the full matching count, not just the returned page.
 *
 * @since 0.1.0
 */
final class ListWebhooks implements ConditionalAbility {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'wc-webhooks/list-webhooks';
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
			'label'               => __( 'List Webhooks', 'abilities-catalog-woo' ),
			'description'         => __( 'Returns the store\'s WooCommerce webhooks as flat summary rows, each with its id, name, status, topic, delivery_url, and date_created. Use wc-webhooks/get-webhook for one webhook\'s full configuration. The signing secret is never returned (a webhook\'s secret HMAC-signs its deliveries and is a stored credential); get-webhook reports a has_secret boolean instead of the key.', 'abilities-catalog-woo' ),
			'category'            => 'wc-webhooks',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'search'   => array(
						'type'        => 'string',
						'description' => __( 'Limit results to webhooks whose name matches a search term.', 'abilities-catalog-woo' ),
					),
					'per_page' => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'maximum'     => 100,
						'default'     => 100,
						'description' => __( 'Maximum number of webhooks to return (1-100). Defaults to 100, which covers every webhook on a typical store.', 'abilities-catalog-woo' ),
					),
					'page'     => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'default'     => 1,
						'description' => __( 'Page of results to return, for paging past the first per_page webhooks.', 'abilities-catalog-woo' ),
					),
					'offset'   => array(
						'type'        => 'integer',
						'minimum'     => 0,
						'description' => __( 'Number of webhooks to skip before returning results. Overrides page when set.', 'abilities-catalog-woo' ),
					),
					'status'   => array(
						'type'        => 'string',
						'enum'        => array( 'all', 'active', 'paused', 'disabled' ),
						'description' => __( 'Limit results to webhooks with this status: "all" (default), "active", "paused", or "disabled".', 'abilities-catalog-woo' ),
					),
					'order'    => array(
						'type'        => 'string',
						'enum'        => array( 'asc', 'desc' ),
						'description' => __( 'Sort direction: "asc" (oldest first) or "desc" (newest first). Defaults to "desc".', 'abilities-catalog-woo' ),
					),
					'orderby'  => array(
						'type'        => 'string',
						'enum'        => array( 'date', 'id', 'title' ),
						'description' => __( 'Sort attribute: "date" (default), "id", or "title" (the webhook name).', 'abilities-catalog-woo' ),
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'items', 'total' ),
				'properties'           => array(
					'items' => array(
						'type'        => 'array',
						'description' => __( 'The webhooks as flat summary rows. No row carries the signing secret. Use wc-webhooks/get-webhook for a single webhook\'s full configuration.', 'abilities-catalog-woo' ),
						'items'       => WebhookListShaper::itemSchema(),
					),
					'total' => array(
						'type'        => 'integer',
						'description' => __( 'Total number of webhooks matching the query, from the X-WP-Total response header.', 'abilities-catalog-woo' ),
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
	 * Permission check: WooCommerce's read capability for webhooks.
	 *
	 * Encodes the catalog baseline for `wc-webhooks/list-webhooks`: the
	 * `manage_woocommerce` capability, which is what
	 * `wc_rest_check_manager_permissions( 'webhooks', 'read' )` resolves to on the
	 * wrapped `GET wc/v3/webhooks` route (webhooks-v1:143-149 →
	 * wc-rest-functions.php). This is a coarse, object-independent guard. The
	 * explicit activity guard keeps the denial clean when WooCommerce is inactive
	 * and the REST routes are unregistered.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may read the store's webhooks.
	 */
	public function hasPermission( $input ): bool {
		return WooPlugin::isActive() && current_user_can( 'manage_woocommerce' );
	}

	/**
	 * Executes the ability by dispatching the internal WooCommerce REST request.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The list of webhooks, or the REST error.
	 */
	public function execute( $input ) {
		if ( ! WooPlugin::isActive() ) {
			return WooPlugin::unavailable();
		}

		$input = is_array( $input ) ? $input : array();

		$request = new WP_REST_Request( 'GET', '/wc/v3/webhooks' );

		if ( ! empty( $input['search'] ) ) {
			$request->set_param( 'search', (string) $input['search'] );
		}
		$request->set_param( 'per_page', max( 1, min( 100, absint( $input['per_page'] ?? 100 ) ) ) );
		$request->set_param( 'page', max( 1, absint( $input['page'] ?? 1 ) ) );
		if ( isset( $input['offset'] ) ) {
			$request->set_param( 'offset', absint( $input['offset'] ) );
		}
		if ( ! empty( $input['status'] ) ) {
			$request->set_param( 'status', (string) $input['status'] );
		}
		if ( ! empty( $input['order'] ) ) {
			$request->set_param( 'order', (string) $input['order'] );
		}
		if ( ! empty( $input['orderby'] ) ) {
			$request->set_param( 'orderby', (string) $input['orderby'] );
		}

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

			$rows[] = WebhookListShaper::summary( $item );
		}

		$headers = $response->get_headers();
		$total   = isset( $headers['X-WP-Total'] ) ? (int) $headers['X-WP-Total'] : count( $rows );

		return array(
			'items' => $rows,
			'total' => $total,
		);
	}
}
