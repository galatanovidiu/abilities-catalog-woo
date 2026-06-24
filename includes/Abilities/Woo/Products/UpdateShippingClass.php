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
 * Write ability: `wc-products/update-shipping-class`.
 *
 * Wraps `PUT wc/v3/products/shipping_classes/<id>` via `rest_do_request()`,
 * updating an existing product shipping class (the flat `product_shipping_class`
 * taxonomy). The `id` is concatenated into the route path, and each editable
 * field is forwarded only when the caller sends it, so an omitted field keeps its
 * current value. The result is the same flat, closed term row the read abilities
 * return through {@see ProductTermListShaper::termSummary()} — id, name, slug,
 * count, description — with no `parent`, because shipping classes are
 * non-hierarchical (the `wc/v3` shipping-classes controller's own schema omits it).
 *
 * This is a safe, reversible catalog edit: it touches only the
 * `product_shipping_class` taxonomy, not products, orders, or money, and any
 * change can be reverted with a second update. A missing shipping class surfaces
 * the wrapped route's specific `woocommerce_rest_term_invalid` 404 via
 * {@see RestError::from()} rather than collapsing to a generic permission denial.
 *
 * Only available when WooCommerce is active (it is a {@see ConditionalAbility}).
 *
 * @since 0.1.0
 */
final class UpdateShippingClass implements ConditionalAbility {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'wc-products/update-shipping-class';
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
			'label'               => __( 'Update Shipping Class', 'abilities-catalog-woo' ),
			'description'         => __( 'Updates an existing WooCommerce product shipping class (the flat product_shipping_class taxonomy) by ID and returns the shaped class row: id, name, slug, product count, and description. Send only the fields you want to change; an omitted field keeps its current value. Shipping classes are non-hierarchical, so there is no parent. Reversible catalog edit affecting only the shipping-class taxonomy, not products, orders, or money. Changing slug to one already used by another product_shipping_class term returns a term_exists error. Discover IDs with wc-products/list-shipping-classes.', 'abilities-catalog-woo' ),
			'category'            => 'wc-products',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'id' ),
				'properties'           => array(
					'id'          => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'The shipping class term ID to update. Discover IDs with wc-products/list-shipping-classes.', 'abilities-catalog-woo' ),
					),
					'name'        => array(
						'type'        => 'string',
						'description' => __( 'A new shipping class name. Omit to keep the current name.', 'abilities-catalog-woo' ),
					),
					'slug'        => array(
						'type'        => 'string',
						'description' => __( 'A new URL slug. Must be unique within product_shipping_class; reusing another class\'s slug returns a term_exists error. Omit to keep the current slug.', 'abilities-catalog-woo' ),
					),
					'description' => array(
						'type'        => 'string',
						'description' => __( 'A new shipping class description (HTML allowed; sanitized by WordPress). Omit to keep the current description.', 'abilities-catalog-woo' ),
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
	 * Mirrors `wc_rest_check_product_term_permissions( 'product_shipping_class',
	 * 'edit' )` on the wrapped `PUT wc/v3/products/shipping_classes/<id>` route,
	 * which maps the `edit` context to the `product_shipping_class` taxonomy's
	 * `edit_terms` capability — registered as `edit_product_terms`. This is the
	 * WRITE-context cap (`edit_terms`), which differs from the read abilities'
	 * `manage_product_terms` (`manage_terms`). It is a coarse, object-INDEPENDENT
	 * guard: the per-object decision is deferred to the wrapped route, so a missing
	 * shipping class surfaces its specific `woocommerce_rest_term_invalid` 404 via
	 * {@see RestError::from()} instead of collapsing to a generic permission denial.
	 * The explicit activity guard keeps the denial clean when WooCommerce is
	 * inactive and the capability is unmapped.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may edit product terms.
	 */
	public function hasPermission( $input ): bool {
		return WooPlugin::isActive() && current_user_can( 'edit_product_terms' );
	}

	/**
	 * Executes the ability by dispatching the internal `wc/v3` REST update request.
	 *
	 * The `id` is concatenated into the route path; each editable field is
	 * forwarded only when present in the input, so an omitted field keeps its
	 * current value.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The shaped shipping-class term row, or the REST error
	 *                                        (e.g. `woocommerce_rest_term_invalid` 404, `term_exists` 400).
	 */
	public function execute( $input ) {
		if ( ! WooPlugin::isActive() ) {
			return WooPlugin::unavailable();
		}

		$input = is_array( $input ) ? $input : array();
		$id    = absint( $input['id'] ?? 0 );

		$request = new WP_REST_Request( 'PUT', '/wc/v3/products/shipping_classes/' . $id );

		if ( array_key_exists( 'name', $input ) ) {
			$request->set_param( 'name', (string) $input['name'] );
		}
		if ( array_key_exists( 'slug', $input ) ) {
			$request->set_param( 'slug', (string) $input['slug'] );
		}
		if ( array_key_exists( 'description', $input ) ) {
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
	 * Builds the closed output schema: the flat shipping-class-row fields for one
	 * term, without `parent` (shipping classes are a flat taxonomy).
	 *
	 * Derived from {@see ProductTermListShaper::termItemSchema()} so the field
	 * descriptions stay in sync with the term list abilities; the `parent`
	 * property is removed because the shipping-class taxonomy has none (the
	 * `wc/v3` shipping-classes controller's schema omits it).
	 *
	 * @return array<string,mixed> A JSON-Schema object fragment.
	 */
	private function outputSchema(): array {
		$schema = ProductTermListShaper::termItemSchema();

		unset( $schema['properties']['parent'] );

		return $schema;
	}
}
