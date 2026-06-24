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
 * Read ability: `wc-taxes/list-tax-classes`.
 *
 * Wraps `GET wc/v3/taxes/classes` via `rest_do_request()` and returns each tax
 * class as a flat `{slug, name}` row. The built-in `standard` class is always
 * present; further classes (e.g. `reduced-rate`, `zero-rate`) are the ones the
 * store has defined. Use a row's `slug` to filter rates by class with
 * `wc-taxes/list-tax-rates`.
 *
 * Only available when WooCommerce is active (it is a {@see ConditionalAbility}).
 * The tax-classes route takes no paging or search, so it returns every class in
 * one call; `total` therefore equals the number of rows returned.
 *
 * @since 0.1.0
 */
final class ListTaxClasses implements ConditionalAbility {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'wc-taxes/list-tax-classes';
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
			'label'               => __( 'List Tax Classes', 'abilities-catalog-woo' ),
			'description'         => __( 'Returns the store\'s WooCommerce tax classes as flat summary rows, each with its slug and name. The built-in "standard" class is always present; other classes (e.g. reduced-rate, zero-rate) are store-defined. Use a class slug to filter rates with wc-taxes/list-tax-rates. Read-only: returns the class list only, not the rates within each class.', 'abilities-catalog-woo' ),
			'category'            => 'wc-taxes',
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
						'description' => __( 'The tax classes as flat summary rows. Use a row\'s slug with wc-taxes/list-tax-rates to list that class\'s rates.', 'abilities-catalog-woo' ),
						'items'       => TaxRateListShaper::classItemSchema(),
					),
					'total' => array(
						'type'        => 'integer',
						'description' => __( 'The number of tax classes returned. This route is not paged, so it equals the total number of classes on the store.', 'abilities-catalog-woo' ),
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
	 * Permission check: WooCommerce's manager capability for store settings.
	 *
	 * The wrapped tax-classes route gates `get_items` on
	 * `wc_rest_check_manager_permissions( 'settings', 'read' )`, which resolves to
	 * `manage_woocommerce` (see WC `wc-rest-functions.php`). This ability mirrors
	 * that exact cap, so it never widens visibility past what the route allows. The
	 * explicit activity guard keeps the denial clean when WooCommerce is inactive.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may read tax classes.
	 */
	public function hasPermission( $input ): bool {
		return WooPlugin::isActive() && current_user_can( 'manage_woocommerce' );
	}

	/**
	 * Executes the ability by dispatching the internal WooCommerce REST request.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The list of tax classes, or the REST error.
	 */
	public function execute( $input ) {
		if ( ! WooPlugin::isActive() ) {
			return WooPlugin::unavailable();
		}

		$input = is_array( $input ) ? $input : array();

		$request  = new WP_REST_Request( 'GET', '/wc/v3/taxes/classes' );
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

			$rows[] = TaxRateListShaper::classSummary( $item );
		}

		return array(
			'items' => $rows,
			'total' => count( $rows ),
		);
	}
}
