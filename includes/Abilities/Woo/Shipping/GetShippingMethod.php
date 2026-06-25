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
 * Read ability: `og-wc-shipping/get-shipping-method`.
 *
 * Wraps `GET wc/v3/shipping_methods/<id>` via `rest_do_request()` and returns one
 * shipping method TYPE from WooCommerce's read-only registry as a flat, closed
 * record: its string `id` slug, `title`, and `description`. A method type is the
 * kind of shipping a zone can offer (e.g. flat_rate, free_shipping, local_pickup),
 * not a configured instance on a zone — for an instance configured on a zone use
 * `og-wc-shipping/get-shipping-zone-method` instead.
 *
 * The `id` is the string method-type slug (the `[\w-]+` route segment), NOT a
 * numeric identifier, so it is concatenated into the route path. An unknown id
 * surfaces the route's `woocommerce_rest_shipping_method_invalid` 404 via
 * {@see RestError::from()}.
 *
 * Only available when WooCommerce is active (it is a {@see ConditionalAbility}).
 *
 * @since 0.1.0
 */
final class GetShippingMethod implements ConditionalAbility {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-wc-shipping/get-shipping-method';
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
			'label'               => __( 'Get Shipping Method', 'abilities-catalog-woo' ),
			'description'         => __( 'Returns one WooCommerce shipping method type from the read-only registry by its string id: the method type id, title, and description. A method type is the kind of shipping a zone can offer (e.g. flat_rate, free_shipping, local_pickup), not a configured instance on a zone — use og-wc-shipping/get-shipping-zone-method for an instance configured on a zone. Discover ids with og-wc-shipping/list-shipping-methods.', 'abilities-catalog-woo' ),
			'category'            => 'og-wc-shipping',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'id' ),
				'properties'           => array(
					'id' => array(
						'type'        => 'string',
						'description' => __( 'The shipping method type id (a slug, e.g. "flat_rate", "free_shipping", or "local_pickup"), not a number. Discover ids with og-wc-shipping/list-shipping-methods.', 'abilities-catalog-woo' ),
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => ShippingMethodListShaper::itemSchema(),
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
	 * Permission check: WooCommerce's manage capability for shipping methods.
	 *
	 * The wrapped `wc/v3` GET route gates on
	 * `wc_rest_check_manager_permissions( 'shipping_methods', 'read' )`, which
	 * ignores the read/edit context and maps the `shipping_methods` resource to
	 * `manage_woocommerce` (see `wc-rest-functions.php`). This mirrors that exact
	 * baseline. The shipping method registry is global, so there is no per-object
	 * decision to defer; an unknown id still surfaces the route's specific
	 * `woocommerce_rest_shipping_method_invalid` 404 via {@see RestError::from()}
	 * rather than collapsing to a generic permission denial. The explicit activity
	 * guard keeps the denial clean when WooCommerce is inactive.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may read shipping methods.
	 */
	public function hasPermission( $input ): bool {
		return WooPlugin::isActive() && current_user_can( 'manage_woocommerce' );
	}

	/**
	 * Executes the ability by dispatching the internal `wc/v3` REST request.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The shaped shipping-method-type record,
	 *                                        or the REST error (e.g.
	 *                                        `woocommerce_rest_shipping_method_invalid` 404).
	 */
	public function execute( $input ) {
		if ( ! WooPlugin::isActive() ) {
			return WooPlugin::unavailable();
		}

		$input = is_array( $input ) ? $input : array();
		$id    = (string) ( $input['id'] ?? '' );

		// The route id is a string slug; concatenate it into the path so it reaches
		// the route un-coerced (it is not a numeric id).
		$request  = new WP_REST_Request( 'GET', '/wc/v3/shipping_methods/' . $id );
		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			return RestError::from( $response );
		}

		$data = rest_get_server()->response_to_data( $response, false );
		$data = is_array( $data ) ? $data : array();

		return ShippingMethodListShaper::summary( $data );
	}
}
