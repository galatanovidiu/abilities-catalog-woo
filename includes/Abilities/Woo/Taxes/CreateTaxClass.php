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
 * Write ability: `og-wc-taxes/create-tax-class`.
 *
 * Wraps `POST wc/v3/taxes/classes` via `rest_do_request()` (the controller calls
 * `WC_Tax::create_tax_class( $name )`), creating one WooCommerce tax class and
 * returning it as a flat, closed `{ slug, name }` row through
 * {@see TaxRateListShaper::classSummary()}. WooCommerce derives the `slug` from the
 * name; it is read-only and cannot be set. Never returns the raw `wc/v3` body.
 *
 * No update route: a tax class can be CREATED and DELETED, never updated — the
 * `wc/v3` tax-classes controller registers no PUT/EDITABLE route. To rename or
 * otherwise change a class, delete it with `og-wc-taxes/delete-tax-class` and create a
 * new one. A duplicate name is rejected with a `woocommerce_rest_tax_class_exists`
 * 400 surfaced via {@see RestError::from()}.
 *
 * Only available when WooCommerce is active (it is a {@see ConditionalAbility}).
 *
 * @since 0.1.0
 */
final class CreateTaxClass implements ConditionalAbility {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-wc-taxes/create-tax-class';
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
			'label'               => __( 'Create Tax Class', 'abilities-catalog-woo' ),
			'description'         => __( 'Creates one WooCommerce tax class and returns its slug and name. Tax classes group tax rates (the built-in classes are "standard", "reduced-rate", and "zero-rate"); the returned slug is the value you assign as a product\'s or a tax rate\'s class (e.g. via og-wc-taxes/create-tax-rate). Only name is required; WooCommerce derives the slug from it (the slug is read-only and cannot be set). A duplicate name returns a "woocommerce_rest_tax_class_exists" 400 error. Note: there is no update route — a tax class can only be created and deleted, never edited. To rename or change a class, delete it with og-wc-taxes/delete-tax-class and create a new one. List existing classes with og-wc-taxes/list-tax-classes.', 'abilities-catalog-woo' ),
			'category'            => 'og-wc-taxes',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'name' ),
				'properties'           => array(
					'name' => array(
						'type'        => 'string',
						'description' => __( 'The tax class display name shown to staff, e.g. "Reduced rate". Required. WooCommerce derives the slug from this name; a name matching an existing class returns a "woocommerce_rest_tax_class_exists" 400 error.', 'abilities-catalog-woo' ),
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => TaxRateListShaper::classItemSchema(),
			'execute_callback'    => array( $this, 'execute' ),
			'permission_callback' => array( $this, 'hasPermission' ),
			'meta'                => array(
				'annotations'  => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => false,
				),
				'show_in_rest' => true,
				'screen'       => 'admin.php?page=wc-settings&tab=tax',
			),
		);
	}

	/**
	 * Permission check: WooCommerce's manager capability for store settings.
	 *
	 * Encodes the catalog capability for `og-wc-taxes/create-tax-class`:
	 * `manage_woocommerce`, which is what
	 * `wc_rest_check_manager_permissions( 'settings', 'create' )` resolves to on the
	 * wrapped `POST wc/v3/taxes/classes` route (the helper ignores the context
	 * argument and maps the `'settings'` object to `manage_woocommerce`). This is a
	 * coarse, object-INDEPENDENT guard; the wrapped route applies any finer checks
	 * and surfaces the specific `woocommerce_rest_tax_class_exists` 400 for a
	 * duplicate name via {@see RestError::from()} rather than collapsing it to a
	 * permission denial. The explicit activity guard keeps the denial clean when
	 * WooCommerce is inactive and the capability is unmapped.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may create tax classes.
	 */
	public function hasPermission( $input ): bool {
		return WooPlugin::isActive() && current_user_can( 'manage_woocommerce' );
	}

	/**
	 * Executes the ability by dispatching the internal `wc/v3` REST create request.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The shaped tax-class row, or the REST
	 *                                        error (e.g. `woocommerce_rest_tax_class_exists`
	 *                                        400 for a duplicate name).
	 */
	public function execute( $input ) {
		if ( ! WooPlugin::isActive() ) {
			return WooPlugin::unavailable();
		}

		$input = is_array( $input ) ? $input : array();

		$request = new WP_REST_Request( 'POST', '/wc/v3/taxes/classes' );

		$request->set_param( 'name', (string) ( $input['name'] ?? '' ) );

		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			return RestError::from( $response );
		}

		$data = rest_get_server()->response_to_data( $response, false );

		return TaxRateListShaper::classSummary( is_array( $data ) ? $data : array() );
	}
}
