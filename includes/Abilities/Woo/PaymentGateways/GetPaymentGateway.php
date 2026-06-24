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
 * Read ability: `wc-payment-gateways/get-payment-gateway`.
 *
 * Wraps `GET wc/v3/payment_gateways/<id>` via `rest_do_request()` and returns one
 * gateway as a flat, closed record: the summary fields (`id`, `title`,
 * `description`, `enabled`, `method_title`, `order`) plus `method_description` and
 * the gateway's `settings` fields. The gateway id is a STRING path segment (e.g.
 * `bacs`, `stripe`), concatenated into the route — never passed as a query param.
 *
 * SECRET REDACTION: a gateway's `settings` map routinely holds live credentials
 * (API keys, secrets, tokens, passwords). The value of any credential-bearing
 * field — `type` is `password`, or its id matches a known secret key — is masked by
 * {@see PaymentGatewayShaper::detail()} to a hidden marker, while a `has_value`
 * boolean still reports whether one is configured. The raw secret is never copied
 * into the output. The redaction lives in the shaper, so this ability physically
 * cannot leak a secret.
 *
 * Only available when WooCommerce is active (it is a {@see ConditionalAbility}).
 *
 * @since 0.1.0
 */
final class GetPaymentGateway implements ConditionalAbility {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'wc-payment-gateways/get-payment-gateway';
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
			'label'               => __( 'Get Payment Gateway', 'abilities-catalog-woo' ),
			'description'         => __( 'Returns one WooCommerce payment gateway by ID, including its title, description, enabled state, method title and description, and its settings fields. Discover the gateway id with wc-payment-gateways/list-payment-gateways. Credential-bearing settings (API keys, secrets, tokens, passwords) are masked: a redacted field shows a hidden marker for its value and a has_value flag that tells configured from empty, so a masked value means "configured but hidden", not missing.', 'abilities-catalog-woo' ),
			'category'            => 'wc-payment-gateways',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'id' ),
				'properties'           => array(
					'id' => array(
						'type'        => 'string',
						'minLength'   => 1,
						'description' => __( 'The payment gateway ID, a string such as "bacs" or "stripe". Discover the gateway id with wc-payment-gateways/list-payment-gateways.', 'abilities-catalog-woo' ),
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => PaymentGatewayShaper::detailSchema(),
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
	 * Permission check: WooCommerce's manager capability for payment gateways.
	 *
	 * The wrapped route gates the single-gateway read on
	 * `wc_rest_check_manager_permissions( 'payment_gateways', 'read' )`, which
	 * resolves to `manage_woocommerce`. This coarse, object-independent guard
	 * mirrors that exactly; the wrapped route surfaces the specific 404
	 * (`woocommerce_rest_payment_gateway_invalid`) for an unknown gateway id, so
	 * doing the object-level check here would mask a missing gateway as a permission
	 * denial.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may read payment gateways.
	 */
	public function hasPermission( $input ): bool {
		return WooPlugin::isActive() && current_user_can( 'manage_woocommerce' );
	}

	/**
	 * Executes the ability by dispatching the internal WooCommerce REST request.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The shaped gateway detail, or the REST error.
	 */
	public function execute( $input ) {
		if ( ! WooPlugin::isActive() ) {
			return WooPlugin::unavailable();
		}

		$input = is_array( $input ) ? $input : array();
		$id    = (string) ( $input['id'] ?? '' );

		// The gateway id is a string path segment; concatenate it into the route so it is not treated as a query param.
		$request  = new WP_REST_Request( 'GET', '/wc/v3/payment_gateways/' . $id );
		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			return RestError::from( $response );
		}

		$data = rest_get_server()->response_to_data( $response, false );

		return PaymentGatewayShaper::detail( is_array( $data ) ? $data : array() );
	}
}
