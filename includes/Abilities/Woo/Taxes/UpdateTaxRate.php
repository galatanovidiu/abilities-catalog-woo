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
 * Write ability: `og-wc-taxes/update-tax-rate`.
 *
 * Wraps `PUT wc/v3/taxes/<id>` via `rest_do_request()`, changing an existing tax
 * rate's writable fields and returning the shaped updated rate
 * ({@see TaxRateListShaper::summary()}: id, country, state, rate, name, priority,
 * compound, shipping, class).
 *
 * The id is set as a path segment by concatenation (never `set_param`), and the
 * writable fields are forwarded only when present in the input, so an update
 * changes only what the caller sent. WooCommerce's `create_or_update_tax()` reads
 * the singular `country`/`state`/`postcode`/`city`/`rate`/`name`/`priority`/
 * `compound`/`shipping`/`class` request params, so only those are exposed (the V3
 * `postcodes`/`cities` arrays are deliberately not surfaced).
 *
 * Financial impact: a tax rate decides what matching customers are charged at
 * checkout, so editing one immediately changes the tax applied to future orders in
 * the matched country/state/postcode/city. The description states this blast
 * radius.
 *
 * An unknown id surfaces the route's own `woocommerce_rest_invalid_id` 404 via
 * {@see RestError::from()}, never a permission collapse.
 *
 * Only available when WooCommerce is active (it is a {@see ConditionalAbility}).
 *
 * @since 0.1.0
 */
final class UpdateTaxRate implements ConditionalAbility {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-wc-taxes/update-tax-rate';
	}

	/**
	 * {@inheritDoc}
	 */
	public function isAvailable(): bool {
		return WooPlugin::isActive();
	}

	/**
	 * The writable tax-rate fields forwarded to the wrapped route on key presence.
	 *
	 * @var list<string>
	 */
	private const WRITABLE_FIELDS = array(
		'country',
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
	public function args(): array {
		return array(
			'label'               => __( 'Update Tax Rate', 'abilities-catalog-woo' ),
			'description'         => __( 'Updates an existing WooCommerce tax rate by ID and returns the shaped rate (id, country, state, rate, name, priority, compound, shipping, class). Send only the fields you want to change; omitted fields are left untouched. FINANCIAL IMPACT: a tax rate decides what customers are charged at checkout, so editing the rate, region (country/state/postcode/city), or class immediately changes the tax applied to future orders that match it. Discover IDs with og-wc-taxes/list-tax-rates; use og-wc-taxes/create-tax-rate to add a new rate instead.', 'abilities-catalog-woo' ),
			'category'            => 'og-wc-taxes',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'id' ),
				'properties'           => array(
					'id'       => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'The ID of the tax rate to update. Discover IDs with og-wc-taxes/list-tax-rates.', 'abilities-catalog-woo' ),
					),
					'country'  => array(
						'type'        => 'string',
						'description' => __( 'The ISO 3166-1 alpha-2 country code this rate applies to, e.g. US, or * for all countries.', 'abilities-catalog-woo' ),
					),
					'state'    => array(
						'type'        => 'string',
						'description' => __( 'The state, province, or district code this rate applies to, or empty for all states.', 'abilities-catalog-woo' ),
					),
					'postcode' => array(
						'type'        => 'string',
						'description' => __( 'A single postcode/ZIP this rate applies to, or * for all postcodes.', 'abilities-catalog-woo' ),
					),
					'city'     => array(
						'type'        => 'string',
						'description' => __( 'A single city this rate applies to, or * for all cities.', 'abilities-catalog-woo' ),
					),
					'rate'     => array(
						'type'        => 'string',
						'description' => __( 'The tax rate as a decimal-string percentage, e.g. "8.25". WooCommerce stores the rate as a decimal string, so pass it as a string.', 'abilities-catalog-woo' ),
					),
					'name'     => array(
						'type'        => 'string',
						'description' => __( 'The rate display label shown to staff, e.g. "VAT".', 'abilities-catalog-woo' ),
					),
					'priority' => array(
						'type'        => 'integer',
						'description' => __( 'The rate priority; only one matching rate per priority is applied.', 'abilities-catalog-woo' ),
					),
					'compound' => array(
						'type'        => 'boolean',
						'description' => __( 'Whether this is a compound rate, applied on top of other taxes.', 'abilities-catalog-woo' ),
					),
					'shipping' => array(
						'type'        => 'boolean',
						'description' => __( 'Whether this rate is also applied to shipping costs.', 'abilities-catalog-woo' ),
					),
					'class'    => array(
						'type'        => 'string',
						'description' => __( 'The tax-class slug this rate belongs to, e.g. standard, reduced-rate, or zero-rate. Discover slugs with og-wc-taxes/list-tax-classes.', 'abilities-catalog-woo' ),
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
	 * Permission check: WooCommerce's manager capability for tax settings.
	 *
	 * The wrapped `PUT wc/v3/taxes/<id>` route gates on
	 * `wc_rest_check_manager_permissions( 'settings', 'edit' )`, whose helper ignores
	 * the context and resolves the `settings` object to `manage_woocommerce`, so this
	 * mirrors that exact cap. This is a coarse, object-independent guard: the
	 * object-level decision (a missing rate) is deferred to the wrapped route, so
	 * execute() can surface the route's specific `woocommerce_rest_invalid_id` 404
	 * instead of masking it as a permission denial.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may edit tax settings.
	 */
	public function hasPermission( $input ): bool {
		return WooPlugin::isActive() && current_user_can( 'manage_woocommerce' );
	}

	/**
	 * Executes the ability by dispatching the internal `wc/v3` REST update request.
	 *
	 * Forwards only the writable fields present in the input (so an update changes
	 * only what the caller sent), dispatches the PUT, and returns the shaped result.
	 * A missing rate surfaces the route's `woocommerce_rest_invalid_id` 404.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The shaped updated tax rate, or the REST error
	 *                                        (e.g. `woocommerce_rest_invalid_id`).
	 */
	public function execute( $input ) {
		if ( ! WooPlugin::isActive() ) {
			return WooPlugin::unavailable();
		}

		$input = is_array( $input ) ? $input : array();
		$id    = absint( $input['id'] ?? 0 );

		$request = new WP_REST_Request( 'PUT', '/wc/v3/taxes/' . $id );

		// Update forwards only the keys present in the input, so it changes only
		// what the caller sent.
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
