<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogWoo\Abilities\Woo\PaymentGateways;

use GalatanOvidiu\AbilitiesCatalogWoo\Contracts\ConditionalAbility;
use GalatanOvidiu\AbilitiesCatalogWoo\Support\PaymentGatewayShaper;
use GalatanOvidiu\AbilitiesCatalogWoo\Support\RestError;
use GalatanOvidiu\AbilitiesCatalogWoo\Support\WooPlugin;
use WP_REST_Request;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read ability: `wc-payment-gateways/list-payment-gateways`.
 *
 * Wraps `GET wc/v3/payment_gateways` via `rest_do_request()` and returns each
 * installed payment gateway as a flat summary row through
 * {@see PaymentGatewayShaper::summary()}. The route is the registry of every
 * gateway WooCommerce knows about (core ships bacs, cheque, cod, and paypal; more
 * arrive with payment extensions), each carrying whether it is enabled and its
 * checkout title.
 *
 * The wrapped route returns each gateway's stored `settings` map, which can hold
 * live processor credentials (API keys, secrets, tokens). A summary row carries
 * NO settings at all: {@see PaymentGatewayShaper::summary()} omits the map
 * entirely, so a list response can never leak a credential. To inspect a gateway's
 * settings — with every credential value masked — use
 * `wc-payment-gateways/get-payment-gateway`.
 *
 * Only available when WooCommerce is active (it is a {@see ConditionalAbility}).
 * The WC payment_gateways route is not paged, so `total` is the count of rows
 * returned, which is the full number of installed gateways.
 *
 * @since 0.1.0
 */
final class ListPaymentGateways implements ConditionalAbility {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'wc-payment-gateways/list-payment-gateways';
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
			'label'               => __( 'List Payment Gateways', 'abilities-catalog-woo' ),
			'description'         => __( 'Returns the store\'s installed WooCommerce payment gateways as flat summary rows, each with its id (the gateway slug, e.g. bacs, cheque, cod, paypal), title, description, whether it is enabled, method_title, and checkout order, plus the total count. A summary row intentionally carries NO settings: to read a gateway\'s configuration use wc-payment-gateways/get-payment-gateway, where credential values (API keys, secrets, tokens) are masked, not exposed. Read-only: takes no input and returns every installed gateway, enabled or not.', 'abilities-catalog-woo' ),
			'category'            => 'wc-payment-gateways',
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
						'description' => __( 'The installed payment gateways as flat summary rows. A row never carries the gateway settings; use wc-payment-gateways/get-payment-gateway for a single gateway\'s (credential-masked) configuration.', 'abilities-catalog-woo' ),
						'items'       => PaymentGatewayShaper::itemSchema(),
					),
					'total' => array(
						'type'        => 'integer',
						'description' => __( 'The number of payment gateways returned. This route is not paged, so it equals the number of installed gateways.', 'abilities-catalog-woo' ),
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
	 * `wc_rest_check_manager_permissions( 'payment_gateways', 'read' )` — that maps
	 * the `payment_gateways` object to `manage_woocommerce` and ignores the context
	 * (wc-rest-functions.php:341-355). So `manage_woocommerce` is the minimum
	 * required to run the read and is not weaker than the route's own check. This is
	 * a coarse, object-independent guard. The activity guard keeps the denial clean
	 * when WooCommerce is inactive and the capability is unmapped.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may list payment gateways.
	 */
	public function hasPermission( $input ): bool {
		return WooPlugin::isActive() && current_user_can( 'manage_woocommerce' );
	}

	/**
	 * Executes the ability by dispatching the internal WooCommerce REST request.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The list of payment gateways, or the REST error.
	 */
	public function execute( $input ) {
		if ( ! WooPlugin::isActive() ) {
			return WooPlugin::unavailable();
		}

		$input = is_array( $input ) ? $input : array();

		$request  = new WP_REST_Request( 'GET', '/wc/v3/payment_gateways' );
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

			$rows[] = PaymentGatewayShaper::summary( $item );
		}

		return array(
			'items' => $rows,
			'total' => count( $rows ),
		);
	}
}
