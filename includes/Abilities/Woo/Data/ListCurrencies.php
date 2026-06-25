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
 * Read ability: `og-wc-data/list-currencies`.
 *
 * Wraps `GET wc/v3/data/currencies` via `rest_do_request()` and returns every
 * currency WooCommerce knows about as a flat `{ code, name, symbol }` row via
 * {@see DataReferenceShaper::currencySummary()}, plus the total count. This is the
 * discovery step for the ISO-4217 codes the by-code ability accepts: pass a row's
 * `code` to `og-wc-data/get-currency` to read one currency, or use
 * `og-wc-data/get-current-currency` to read the currency this store charges in.
 *
 * This is WooCommerce's full static currency table (shipped in `i18n/`), not the
 * currencies enabled on the store; the list is the same on every site.
 *
 * Only available when WooCommerce is active (it is a {@see ConditionalAbility}).
 * The route returns a bare array via `rest_ensure_response()` with no pagination
 * header, so `total` is the number of rows returned.
 *
 * @since 0.1.0
 */
final class ListCurrencies implements ConditionalAbility {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-wc-data/list-currencies';
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
			'label'               => __( 'List Currencies', 'abilities-catalog-woo' ),
			'description'         => __( 'Returns every currency WooCommerce knows about as flat { code, name, symbol } rows, plus the total count. Read-only discovery step: use a row\'s ISO-4217 code with og-wc-data/get-currency to read one currency, or og-wc-data/get-current-currency to read the currency this store charges in. This is WooCommerce\'s full static currency table, not the currencies enabled on the store, so the list is the same on every site.', 'abilities-catalog-woo' ),
			'category'            => 'og-wc-data',
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
						'description' => __( 'The currencies as flat summary rows. Use a row\'s code with og-wc-data/get-currency for a single currency.', 'abilities-catalog-woo' ),
						'items'       => DataReferenceShaper::currencyItemSchema(),
					),
					'total' => array(
						'type'        => 'integer',
						'description' => __( 'The number of currencies returned. This route exposes no total header, so it counts the returned rows.', 'abilities-catalog-woo' ),
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
	 * Permission check: WooCommerce's settings-manager capability.
	 *
	 * The wrapped `/wc/v3/data/currencies` route gates reads on
	 * `wc_rest_check_manager_permissions( 'settings', 'read' )`, which resolves to
	 * `manage_woocommerce`. This ability mirrors that exact cap — never wider — and
	 * the activity guard keeps the denial clean when WooCommerce is inactive and the
	 * capability is unmapped. This is a coarse type-level guard; the wrapped route
	 * surfaces any specific error.
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
	 * @return array<string,mixed>|\WP_Error The currencies and total, or the REST error.
	 */
	public function execute( $input ) {
		if ( ! WooPlugin::isActive() ) {
			return WooPlugin::unavailable();
		}

		$input = is_array( $input ) ? $input : array();

		$request  = new WP_REST_Request( 'GET', '/wc/v3/data/currencies' );
		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			return RestError::from( $response );
		}

		$data = rest_get_server()->response_to_data( $response, false );

		$rows = array();
		foreach ( is_array( $data ) ? $data : array() as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$rows[] = DataReferenceShaper::currencySummary( $row );
		}

		return array(
			'items' => $rows,
			'total' => count( $rows ),
		);
	}
}
