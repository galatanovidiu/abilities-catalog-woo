<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogWoo\Abilities\Woo\Data;

use GalatanOvidiu\AbilitiesCatalogWoo\Contracts\ConditionalAbility;
use GalatanOvidiu\AbilitiesCatalogWoo\Support\DataReferenceShaper;
use GalatanOvidiu\AbilitiesCatalogWoo\Support\RestError;
use GalatanOvidiu\AbilitiesCatalogWoo\Support\WooPlugin;
use WP_REST_Request;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read ability: `og-wc-data/get-currency`.
 *
 * Wraps `GET wc/v3/data/currencies/<code>` via `rest_do_request()` and returns the
 * one currency's flat `{ code, name, symbol }` summary from WooCommerce's shipped
 * ISO-4217 currency table. Looks up an arbitrary currency by code; use
 * `og-wc-data/get-current-currency` for the currency the store actually charges in.
 *
 * The route's regex captures the code as the `currency` param (3-character
 * `[\w-]{3}`); the path is built by concatenation so the captured code reaches the
 * route, which uppercases it internally before lookup. An unknown code yields the
 * route's own `woocommerce_rest_data_invalid_currency` 404, surfaced verbatim via
 * {@see RestError::from()} rather than collapsing into a permission error.
 *
 * Only available when WooCommerce is active (it is a {@see ConditionalAbility}).
 *
 * @since 0.1.0
 */
final class GetCurrency implements ConditionalAbility {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-wc-data/get-currency';
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
			'label'               => __( 'Get Currency', 'abilities-catalog-woo' ),
			'description'         => __( 'Returns one WooCommerce currency by its ISO-4217 code: its code, full name, and symbol. Looks up any currency in WooCommerce\'s shipped table; use og-wc-data/get-current-currency for the currency this store charges in, and og-wc-data/list-currencies to discover valid codes.', 'abilities-catalog-woo' ),
			'category'            => 'og-wc-data',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'code' ),
				'properties'           => array(
					'code' => array(
						'type'        => 'string',
						'description' => __( 'The ISO-4217 3-letter currency code, e.g. USD. Case-insensitive (WooCommerce uppercases it). Discover valid codes with og-wc-data/list-currencies.', 'abilities-catalog-woo' ),
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => DataReferenceShaper::currencyItemSchema(),
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
	 * Permission check: the WooCommerce shop-manager capability.
	 *
	 * The wrapped `wc/v3/data/currencies/<code>` route gates on
	 * `wc_rest_check_manager_permissions( 'settings', 'read' )`, which maps to
	 * `manage_woocommerce`. This mirrors that baseline exactly — the coarse,
	 * object-independent guard — so the wrapped route still surfaces its specific
	 * `woocommerce_rest_data_invalid_currency` 404 for an unknown code instead of
	 * the denial masking it. The explicit activity guard keeps the denial clean if
	 * the cap is ever checked while WooCommerce is inactive.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may read WooCommerce reference data.
	 */
	public function hasPermission( $input ): bool {
		return WooPlugin::isActive() && current_user_can( 'manage_woocommerce' );
	}

	/**
	 * Executes the ability by dispatching the internal WooCommerce REST request.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The flat currency summary, or the REST error.
	 */
	public function execute( $input ) {
		if ( ! WooPlugin::isActive() ) {
			return WooPlugin::unavailable();
		}

		$input = is_array( $input ) ? $input : array();
		$code  = (string) ( $input['code'] ?? '' );

		// Concatenate the code into the path so the route's `currency` regex captures it.
		$request  = new WP_REST_Request( 'GET', '/wc/v3/data/currencies/' . $code );
		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			return RestError::from( $response );
		}

		$data = rest_get_server()->response_to_data( $response, false );

		return DataReferenceShaper::currencySummary( is_array( $data ) ? $data : array() );
	}
}
