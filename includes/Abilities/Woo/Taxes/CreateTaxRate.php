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
 * Write ability: `wc-taxes/create-tax-rate`.
 *
 * Wraps `POST wc/v3/taxes` via `rest_do_request()`, creating a new WooCommerce tax
 * rate, and returns the flat, closed {@see TaxRateListShaper::summary()} record of
 * the created rate ({@see TaxRateListShaper::itemSchema()}), never the raw REST body
 * (the route's raw `postcodes`/`cities`/`order` fields are dropped).
 *
 * FINANCIAL — the blast radius: a tax rate changes what customers are charged at
 * checkout. Once created, this rate applies to every future order whose
 * country/state/postcode/city matches it, taking effect immediately for new carts.
 *
 * Only `country` is required (the region the rate applies to). The body fields the
 * route accepts are forwarded only when present in the input. The `rate` is a decimal
 * STRING (e.g. `8.25`) — WooCommerce stores the percentage as a decimal string, so it
 * is kept a string here. To change an existing rate use `wc-taxes/update-tax-rate`;
 * discover ids with `wc-taxes/list-tax-rates`.
 *
 * The catalog exposes the singular `postcode`/`city` strings (the stable wc/v3 V1
 * fields), not the V3 `postcodes`/`cities` arrays, to keep the input simple and
 * closed.
 *
 * Only available when WooCommerce is active (it is a {@see ConditionalAbility}).
 *
 * @since 0.1.0
 */
final class CreateTaxRate implements ConditionalAbility {

	/**
	 * The optional writable body fields forwarded to the wrapped route when present.
	 *
	 * Each maps one-to-one to a `wc/v3` taxes write field. `country` is set
	 * separately as the required field; these are the optional remainder.
	 *
	 * @var list<string>
	 */
	private const WRITABLE_FIELDS = array(
		'state',
		'postcode',
		'city',
		'rate',
		'name',
		'priority',
		'compound',
		'shipping',
		'class',
	);

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'wc-taxes/create-tax-rate';
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
			'label'               => __( 'Create Tax Rate', 'abilities-catalog-woo' ),
			'description'         => __( 'Creates a new WooCommerce tax rate and returns its id, country, state, rate, name, priority, compound and shipping flags, and tax class. FINANCIAL: this changes what customers are charged at checkout — once created, the rate applies to every future order whose country/state/postcode/city matches it, and takes effect immediately for new carts. Only country is required (use the ISO code, e.g. US, or * for all). rate is a decimal-string percentage (e.g. "8.25"); keep it a string. To change an existing rate use wc-taxes/update-tax-rate; discover ids with wc-taxes/list-tax-rates, and class slugs with wc-taxes/list-tax-classes.', 'abilities-catalog-woo' ),
			'category'            => 'wc-taxes',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'country' ),
				'properties'           => array(
					'country'  => array(
						'type'        => 'string',
						'description' => __( 'The country code this rate applies to, in ISO 3166-1 alpha-2 format (e.g. US), or * for all countries. Required.', 'abilities-catalog-woo' ),
					),
					'state'    => array(
						'type'        => 'string',
						'description' => __( 'The state, province, or district code this rate applies to, or * for all. Omit to apply to the whole country.', 'abilities-catalog-woo' ),
					),
					'postcode' => array(
						'type'        => 'string',
						'description' => __( 'The postcode/ZIP this rate applies to, or * for all. A single postcode string (not a list).', 'abilities-catalog-woo' ),
					),
					'city'     => array(
						'type'        => 'string',
						'description' => __( 'The city this rate applies to, or * for all. A single city string (not a list).', 'abilities-catalog-woo' ),
					),
					'rate'     => array(
						'type'        => 'string',
						'description' => __( 'The tax rate as a decimal-string percentage, e.g. "8.25". WooCommerce stores the rate as a decimal string, so send it as a string, not a number.', 'abilities-catalog-woo' ),
					),
					'name'     => array(
						'type'        => 'string',
						'description' => __( 'The rate display label shown to staff, e.g. "VAT" or "CA Tax".', 'abilities-catalog-woo' ),
					),
					'priority' => array(
						'type'        => 'integer',
						'description' => __( 'The rate priority. Only one matching rate per priority is applied, so use distinct priorities to layer rates.', 'abilities-catalog-woo' ),
					),
					'compound' => array(
						'type'        => 'boolean',
						'description' => __( 'Whether this is a compound rate, applied on top of other taxes rather than alongside them.', 'abilities-catalog-woo' ),
					),
					'shipping' => array(
						'type'        => 'boolean',
						'description' => __( 'Whether this rate is also applied to shipping costs.', 'abilities-catalog-woo' ),
					),
					'class'    => array(
						'type'        => 'string',
						'description' => __( 'The tax-class slug this rate belongs to, e.g. standard, reduced-rate, or zero-rate. Discover available slugs with wc-taxes/list-tax-classes.', 'abilities-catalog-woo' ),
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => TaxRateListShaper::itemSchema(),
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
	 * Permission check: WooCommerce's manager capability for settings.
	 *
	 * The wrapped `POST wc/v3/taxes` route gates on
	 * `wc_rest_check_manager_permissions( 'settings', 'create' )`. That helper
	 * ignores the `$context` argument and resolves the `'settings'` object to
	 * `manage_woocommerce`, so this mirrors that exact cap. It is a coarse,
	 * object-independent guard; the wrapped route surfaces the schema's own 400 via
	 * {@see RestError::from()} rather than collapsing it to a generic permission
	 * denial. The explicit activity guard keeps the denial clean when WooCommerce
	 * is inactive.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may write tax settings.
	 */
	public function hasPermission( $input ): bool {
		return WooPlugin::isActive() && current_user_can( 'manage_woocommerce' );
	}

	/**
	 * Executes the ability by dispatching the internal `wc/v3` REST create request.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The shaped created tax rate, or the REST error.
	 */
	public function execute( $input ) {
		if ( ! WooPlugin::isActive() ) {
			return WooPlugin::unavailable();
		}

		$input = is_array( $input ) ? $input : array();

		$request = new WP_REST_Request( 'POST', '/wc/v3/taxes' );
		$request->set_param( 'country', (string) ( $input['country'] ?? '' ) );

		// Forward only the optional fields actually present in the input.
		foreach ( self::WRITABLE_FIELDS as $field ) {
			if ( ! array_key_exists( $field, $input ) ) {
				continue;
			}

			$request->set_param( $field, $input[ $field ] );
		}

		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			return RestError::from( $response );
		}

		$data = rest_get_server()->response_to_data( $response, false );

		return TaxRateListShaper::summary( is_array( $data ) ? $data : array() );
	}
}
