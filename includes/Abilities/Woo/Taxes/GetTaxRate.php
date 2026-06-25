<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogWoo\Abilities\Woo\Taxes;

use GalatanOvidiu\AbilitiesCatalogWoo\Contracts\ConditionalAbility;
use GalatanOvidiu\AbilitiesCatalogWoo\Support\RestError;
use GalatanOvidiu\AbilitiesCatalogWoo\Support\TaxRateListShaper;
use GalatanOvidiu\AbilitiesCatalogWoo\Support\WooPlugin;
use WP_REST_Request;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read ability: `og-wc-taxes/get-tax-rate`.
 *
 * Wraps `GET wc/v3/taxes/<id>` via `rest_do_request()` and returns one tax rate as
 * a flat, closed row shaped by {@see TaxRateListShaper::summary()}. The id is a
 * numeric path segment, so it is concatenated into the route (not passed as a query
 * param) and the wrapped route resolves the rate. An unknown id surfaces the route's
 * own `woocommerce_rest_invalid_id` 404 via {@see RestError::from()}, never a
 * permission collapse.
 *
 * Only available when WooCommerce is active (it is a {@see ConditionalAbility}).
 *
 * @since 0.1.0
 */
final class GetTaxRate implements ConditionalAbility {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-wc-taxes/get-tax-rate';
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
			'label'               => __( 'Get Tax Rate', 'abilities-catalog-woo' ),
			'description'         => __( 'Returns one WooCommerce tax rate by ID: its country, state, rate (a decimal-string percentage), name, priority, compound and shipping flags, and tax class. Use og-wc-taxes/list-tax-rates to discover IDs, and og-wc-taxes/list-tax-classes for the available class slugs.', 'abilities-catalog-woo' ),
			'category'            => 'og-wc-taxes',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'id' ),
				'properties'           => array(
					'id' => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'The tax rate ID. Discover IDs with og-wc-taxes/list-tax-rates.', 'abilities-catalog-woo' ),
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => TaxRateListShaper::itemSchema(),
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
	 * Permission check: WooCommerce's manager capability for settings.
	 *
	 * The wrapped `GET wc/v3/taxes/<id>` route gates on
	 * `wc_rest_check_manager_permissions( 'settings', 'read' )`, which resolves to
	 * `manage_woocommerce`, so this mirrors that exact cap. This is a coarse,
	 * object-independent guard: the object-level decision (a missing rate) is
	 * deferred to the wrapped route, so execute() can surface the route's specific
	 * `woocommerce_rest_invalid_id` 404 instead of masking it as a permission
	 * denial.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may read tax settings.
	 */
	public function hasPermission( $input ): bool {
		return WooPlugin::isActive() && current_user_can( 'manage_woocommerce' );
	}

	/**
	 * Executes the ability by dispatching the internal WooCommerce REST request.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The shaped tax rate, or the REST error.
	 */
	public function execute( $input ) {
		if ( ! WooPlugin::isActive() ) {
			return WooPlugin::unavailable();
		}

		$input = is_array( $input ) ? $input : array();
		$id    = absint( $input['id'] ?? 0 );

		$request  = new WP_REST_Request( 'GET', '/wc/v3/taxes/' . $id );
		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			return RestError::from( $response );
		}

		$data = rest_get_server()->response_to_data( $response, false );

		return TaxRateListShaper::summary( is_array( $data ) ? $data : array() );
	}
}
