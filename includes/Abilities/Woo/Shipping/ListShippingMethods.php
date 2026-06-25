<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogWoo\Abilities\Woo\Shipping;

use GalatanOvidiu\AbilitiesCatalogWoo\Contracts\ConditionalAbility;
use GalatanOvidiu\AbilitiesCatalogWoo\Support\RestError;
use GalatanOvidiu\AbilitiesCatalogWoo\Support\ShippingMethodListShaper;
use GalatanOvidiu\AbilitiesCatalogWoo\Support\WooPlugin;
use WP_REST_Request;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read ability: `og-wc-shipping/list-shipping-methods`.
 *
 * Wraps `GET wc/v3/shipping_methods` via `rest_do_request()` and returns each
 * registered shipping-method TYPE as a flat summary row through
 * {@see ShippingMethodListShaper::summary()}. The route is the read-only registry
 * of method types — the templates a zone can offer (flat_rate, free_shipping,
 * local_pickup, plus any added by extensions) — NOT the method instances actually
 * configured on a zone. For those configured instances, use
 * `og-wc-shipping/list-shipping-zone-methods`.
 *
 * Only available when WooCommerce is active (it is a {@see ConditionalAbility}).
 * The WC shipping_methods route sets the `X-WP-Total` header, so `total` is the
 * full count of registered method types.
 *
 * @since 0.1.0
 */
final class ListShippingMethods implements ConditionalAbility {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-wc-shipping/list-shipping-methods';
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
			'label'               => __( 'List Shipping Methods', 'abilities-catalog-woo' ),
			'description'         => __( 'Returns the registry of available WooCommerce shipping-method types as flat summary rows, each with its id (the type slug, e.g. flat_rate, free_shipping, local_pickup), title, and description, plus the total count. These are the method TYPES (the templates a zone can offer), not the method instances configured on a particular zone — for a zone\'s configured methods use og-wc-shipping/list-shipping-zone-methods instead. Read-only: takes no input and returns the same registry regardless of zones.', 'abilities-catalog-woo' ),
			'category'            => 'og-wc-shipping',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => (object) array(),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'items', 'total' ),
				'properties'           => array(
					'items' => array(
						'type'        => 'array',
						'description' => __( 'The registered shipping-method types as flat summary rows. Use a row\'s id when adding a method to a zone.', 'abilities-catalog-woo' ),
						'items'       => ShippingMethodListShaper::itemSchema(),
					),
					'total' => array(
						'type'        => 'integer',
						'description' => __( 'The number of registered shipping-method types, read from the X-WP-Total response header (falling back to the number of rows returned). This route is not paged, so it equals the number of items.', 'abilities-catalog-woo' ),
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
	 * Permission check: WooCommerce's shop-manager capability.
	 *
	 * Mirrors the wrapped route, which gates on
	 * `wc_rest_check_manager_permissions( 'shipping_methods', 'read' )` — that maps
	 * the `shipping_methods` object to `manage_woocommerce` and ignores the context
	 * (wc-rest-functions.php:341-355). So `manage_woocommerce` is the minimum
	 * required to run the read and is not weaker than the route's own check. This is
	 * a coarse, object-independent guard. The activity guard keeps the denial clean
	 * when WooCommerce is inactive and the capability is unmapped.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may list shipping-method types.
	 */
	public function hasPermission( $input ): bool {
		return WooPlugin::isActive() && current_user_can( 'manage_woocommerce' );
	}

	/**
	 * Executes the ability by dispatching the internal WooCommerce REST request.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The list of shipping-method types, or the REST error.
	 */
	public function execute( $input ) {
		if ( ! WooPlugin::isActive() ) {
			return WooPlugin::unavailable();
		}

		$input = is_array( $input ) ? $input : array();

		$request  = new WP_REST_Request( 'GET', '/wc/v3/shipping_methods' );
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

			$rows[] = ShippingMethodListShaper::summary( $item );
		}

		$headers = $response->get_headers();
		$total   = isset( $headers['X-WP-Total'] ) ? (int) $headers['X-WP-Total'] : count( $rows );

		return array(
			'items' => $rows,
			'total' => $total,
		);
	}
}
