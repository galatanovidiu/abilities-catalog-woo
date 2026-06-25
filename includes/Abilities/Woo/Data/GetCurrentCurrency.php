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
 * Read ability: `og-wc-data/get-current-currency`.
 *
 * Wraps `GET wc/v3/data/currencies/current` via `rest_do_request()` and returns the
 * store's configured currency — the value of the `woocommerce_currency` option — as
 * one flat `{ code, name, symbol }` object through
 * {@see DataReferenceShaper::currencySummary()}.
 *
 * The dispatched path is the literal `/current` route, registered ahead of the
 * by-code `(?P<currency>[\w-]{3})` route, so it always resolves the store's own
 * currency and never a 3-character code lookup. To look up an arbitrary currency by
 * its ISO-4217 code, use `og-wc-data/get-currency` instead.
 *
 * Only available when WooCommerce is active (it is a {@see ConditionalAbility}).
 *
 * @since 0.1.0
 */
final class GetCurrentCurrency implements ConditionalAbility {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-wc-data/get-current-currency';
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
			'label'               => __( 'Get Current Currency', 'abilities-catalog-woo' ),
			'description'         => __( 'Returns the store\'s configured currency (the woocommerce_currency setting) as a code, full name, and symbol. Use this to answer "what currency does this store charge in". To look up an arbitrary currency by its ISO-4217 code instead, use og-wc-data/get-currency; to list every available currency, use og-wc-data/list-currencies. Read-only; takes no input.', 'abilities-catalog-woo' ),
			'category'            => 'og-wc-data',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => (object) array(),
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
	 * Permission check: WooCommerce's shop-manager capability.
	 *
	 * Mirrors the wrapped route, whose `/current` endpoint gates on
	 * `wc_rest_check_manager_permissions( 'settings', 'read' )` — that maps the
	 * `settings` object to `manage_woocommerce` and ignores the context
	 * (wc-rest-functions.php:341-355; class-wc-rest-data-controller.php:60). So
	 * `manage_woocommerce` is the minimum required to run the read and is not weaker
	 * than the route's own check. This is a coarse, object-independent guard. The
	 * activity guard keeps the denial clean when WooCommerce is inactive and the
	 * capability is unmapped.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may read the store currency.
	 */
	public function hasPermission( $input ): bool {
		return WooPlugin::isActive() && current_user_can( 'manage_woocommerce' );
	}

	/**
	 * Executes the ability by dispatching the internal WooCommerce REST request.
	 *
	 * Dispatches the literal `/current` path so it resolves the store-currency route
	 * (not the by-code route), then projects the row through
	 * {@see DataReferenceShaper::currencySummary()}.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The store currency as a `{ code, name, symbol }` row, or the REST error.
	 */
	public function execute( $input ) {
		if ( ! WooPlugin::isActive() ) {
			return WooPlugin::unavailable();
		}

		$input = is_array( $input ) ? $input : array();

		$request  = new WP_REST_Request( 'GET', '/wc/v3/data/currencies/current' );
		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			return RestError::from( $response );
		}

		$data = rest_get_server()->response_to_data( $response, false );

		return DataReferenceShaper::currencySummary( is_array( $data ) ? $data : array() );
	}
}
