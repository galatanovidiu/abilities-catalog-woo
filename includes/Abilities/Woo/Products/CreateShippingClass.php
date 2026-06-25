<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogWoo\Abilities\Woo\Products;

use GalatanOvidiu\AbilitiesCatalogWoo\Contracts\ConditionalAbility;
use GalatanOvidiu\AbilitiesCatalogWoo\Support\ProductTermListShaper;
use GalatanOvidiu\AbilitiesCatalogWoo\Support\RestError;
use GalatanOvidiu\AbilitiesCatalogWoo\Support\WooPlugin;
use WP_REST_Request;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Write ability: `og-wc-products/create-shipping-class`.
 *
 * Wraps `POST wc/v3/products/shipping_classes` via `rest_do_request()`, creating
 * one product shipping class and returning it as a flat, closed term row through
 * {@see ProductTermListShaper::termSummary()} — id, name, slug, count, and
 * description. Shipping classes are a flat (non-hierarchical) taxonomy, so the row
 * carries no `parent` (the `wc/v3` controller's own schema omits it). Never returns
 * the raw `wc/v3` term body.
 *
 * Reversible: a class created here is editable with
 * `og-wc-products/update-shipping-class`. The change touches only the
 * `product_shipping_class` catalog taxonomy — it does not affect orders, money,
 * or products already published; products are assigned a shipping class
 * separately when they are saved.
 *
 * Only available when WooCommerce is active (it is a {@see ConditionalAbility}).
 *
 * @since 0.1.0
 */
final class CreateShippingClass implements ConditionalAbility {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-wc-products/create-shipping-class';
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
			'label'               => __( 'Create Shipping Class', 'abilities-catalog-woo' ),
			'description'         => __( 'Creates one WooCommerce product shipping class and returns its id, name, slug, product count, and description. Only name is required; an omitted slug is derived from the name. Shipping classes are a flat (non-hierarchical) taxonomy, so there is no parent. Reusing an existing slug returns a "term_exists" 400 error carrying the existing class id in resource_id, so branch on that to reuse instead of recreate. Reversible: edit it later with og-wc-products/update-shipping-class. This edits only the product_shipping_class catalog taxonomy and does not affect orders or products; a product is assigned its shipping class separately when it is saved.', 'abilities-catalog-woo' ),
			'category'            => 'og-wc-products',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'name' ),
				'properties'           => array(
					'name'        => array(
						'type'        => 'string',
						'description' => __( 'The shipping class name shown in wp-admin and on the product, e.g. "Heavy". Required.', 'abilities-catalog-woo' ),
					),
					'slug'        => array(
						'type'        => 'string',
						'description' => __( 'The URL slug for the class. Omit to let WooCommerce derive it from the name. Reusing an existing slug returns a "term_exists" 400 error with the existing class id in resource_id.', 'abilities-catalog-woo' ),
					),
					'description' => array(
						'type'        => 'string',
						'description' => __( 'An optional description for the shipping class (HTML allowed; sanitized by WooCommerce).', 'abilities-catalog-woo' ),
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => $this->outputSchema(),
			'execute_callback'    => array( $this, 'execute' ),
			'permission_callback' => array( $this, 'hasPermission' ),
			'meta'                => array(
				'annotations'  => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => false,
				),
				'show_in_rest' => true,
				'screen'       => 'term.php?taxonomy=product_shipping_class&tag_ID={id}&post_type=product',
			),
		);
	}

	/**
	 * Permission check: WooCommerce's edit capability for product terms.
	 *
	 * Encodes the catalog capability for `og-wc-products/create-shipping-class`: the
	 * `edit_product_terms` capability, which is what
	 * `wc_rest_check_product_term_permissions( 'product_shipping_class', 'create' )`
	 * resolves to on the wrapped `POST wc/v3/products/shipping_classes` route (the
	 * create context maps to `edit_terms`, registered as `edit_product_terms` for
	 * the `product_shipping_class` taxonomy). This is a coarse, object-INDEPENDENT
	 * guard; the wrapped route applies any finer checks and surfaces the specific
	 * `term_exists` 400 for a duplicate slug via {@see RestError::from()} rather than
	 * collapsing it to a permission denial. The explicit activity guard keeps the
	 * denial clean when WooCommerce is inactive and the capability is unmapped.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may create product shipping classes.
	 */
	public function hasPermission( $input ): bool {
		return WooPlugin::isActive() && current_user_can( 'edit_product_terms' );
	}

	/**
	 * Executes the ability by dispatching the internal `wc/v3` REST create request.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The shaped shipping-class row, or the REST
	 *                                        error (e.g. `term_exists` 400 for a
	 *                                        duplicate slug).
	 */
	public function execute( $input ) {
		if ( ! WooPlugin::isActive() ) {
			return WooPlugin::unavailable();
		}

		$input = is_array( $input ) ? $input : array();

		$request = new WP_REST_Request( 'POST', '/wc/v3/products/shipping_classes' );

		$request->set_param( 'name', (string) ( $input['name'] ?? '' ) );
		if ( isset( $input['slug'] ) && '' !== $input['slug'] ) {
			$request->set_param( 'slug', (string) $input['slug'] );
		}
		if ( isset( $input['description'] ) && '' !== $input['description'] ) {
			$request->set_param( 'description', (string) $input['description'] );
		}

		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			return RestError::from( $response );
		}

		$data = rest_get_server()->response_to_data( $response, false );
		$data = is_array( $data ) ? $data : array();

		$row = ProductTermListShaper::termSummary( $data );

		return array(
			'id'          => $row['id'],
			'name'        => $row['name'],
			'slug'        => $row['slug'],
			'count'       => $row['count'],
			'description' => $row['description'],
		);
	}

	/**
	 * Builds the closed output schema: the flat shipping-class-row fields for the
	 * created term, without `parent` (shipping classes are a flat taxonomy).
	 *
	 * Derived from {@see ProductTermListShaper::termItemSchema()} so the field
	 * descriptions stay in sync with the term list abilities; the `parent` property
	 * is removed because the shipping-class taxonomy has none (the `wc/v3`
	 * shipping-classes controller's schema omits it).
	 *
	 * @return array<string,mixed> A JSON-Schema object fragment.
	 */
	private function outputSchema(): array {
		$schema = ProductTermListShaper::termItemSchema();

		unset( $schema['properties']['parent'] );

		return $schema;
	}
}
