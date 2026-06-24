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
 * Write ability: `wc-products/create-attribute-term`.
 *
 * Wraps `POST wc/v3/products/attributes/<attribute_id>/terms` via
 * `rest_do_request()`, creating one new term on a global product attribute's
 * `pa_*` taxonomy (e.g. adding "Red" to the "Color" attribute). The
 * `attribute_id` is a required ROUTE SEGMENT — the parent attribute the term
 * belongs to — so it is concatenated into the path, not sent as a body param;
 * a missing attribute surfaces `woocommerce_rest_taxonomy_invalid` 404 from the
 * route, not a permission collapse.
 *
 * The created term is returned as one flat, closed term row through
 * {@see ProductTermListShaper::termSummary()} — id, name, slug, parent (always 0
 * for the flat attribute-term taxonomy), count, description — never the raw
 * `wc/v3` body (which also carries `menu_order`).
 *
 * Reversibility / blast radius: a catalog edit only. Creating an attribute term
 * adds a taxonomy term that products can be assigned to; it touches no orders,
 * money, or store settings, and is reversible by deleting the term. A duplicate
 * slug on this taxonomy returns the core `term_exists` 400 (with a `resource_id`
 * naming the existing term), so the agent can branch instead of creating a
 * collision.
 *
 * Only available when WooCommerce is active (it is a {@see ConditionalAbility}).
 *
 * @since 0.1.0
 */
final class CreateAttributeTerm implements ConditionalAbility {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'wc-products/create-attribute-term';
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
			'label'               => __( 'Create Attribute Term', 'abilities-catalog-woo' ),
			'description'         => __( 'Creates a new term on a global product attribute (e.g. adds "Red" to the "Color" attribute) and returns the term as a flat row: id, name, slug, parent (always 0 — attribute terms are a flat taxonomy), product count, and description. attribute_id is the parent attribute the term is added to; discover it with wc-products/list-product-attributes. The slug must be unique on this attribute taxonomy: an existing slug returns a "term_exists" 400 error (with the existing term id in resource_id), so check before retrying. This is a catalog edit only — it adds a taxonomy term products can be assigned to, affects no orders or settings, and is reversible by deleting the term. Use wc-products/update-attribute-term to rename or re-slug it later.', 'abilities-catalog-woo' ),
			'category'            => 'wc-products',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'attribute_id', 'name' ),
				'properties'           => array(
					'attribute_id' => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'The parent attribute\'s id — the global attribute this term is added to. Discover it with wc-products/list-product-attributes. A non-existent attribute_id returns a "woocommerce_rest_taxonomy_invalid" 404.', 'abilities-catalog-woo' ),
					),
					'name'         => array(
						'type'        => 'string',
						'description' => __( 'The term name shown to shoppers, e.g. "Red". Required.', 'abilities-catalog-woo' ),
					),
					'slug'         => array(
						'type'        => 'string',
						'description' => __( 'An optional URL slug for the term. Omit to let WooCommerce derive one from the name. Must be unique on this attribute taxonomy: an existing slug returns a "term_exists" 400 error.', 'abilities-catalog-woo' ),
					),
					'description'  => array(
						'type'        => 'string',
						'description' => __( 'An optional description for the term (HTML allowed; sanitized by WooCommerce).', 'abilities-catalog-woo' ),
					),
					'menu_order'   => array(
						'type'        => 'integer',
						'description' => __( 'An optional sort position for the term, used when the attribute is ordered by menu order. Not returned in the result.', 'abilities-catalog-woo' ),
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => ProductTermListShaper::termItemSchema(),
			'execute_callback'    => array( $this, 'execute' ),
			'permission_callback' => array( $this, 'hasPermission' ),
			'meta'                => array(
				'annotations'  => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => false,
				),
				'show_in_rest' => true,
			),
		);
	}

	/**
	 * Permission check: WooCommerce's edit capability for product terms.
	 *
	 * Encodes the catalog capability for `wc-products/create-attribute-term`: the
	 * `edit_product_terms` capability, which is what
	 * `wc_rest_check_product_term_permissions( $taxonomy, 'create' )` resolves to
	 * on the wrapped `POST wc/v3/products/attributes/<attribute_id>/terms` route —
	 * the helper maps the `create` context to the taxonomy's `edit_terms` cap,
	 * registered as `edit_product_terms` for the `pa_*` attribute taxonomies. (This
	 * differs from the product-term READS, which gate on `manage_product_terms`,
	 * because the read context maps to `manage_terms`.) This is a coarse,
	 * object-INDEPENDENT guard: the per-attribute decision is deferred to the
	 * wrapped route, so a missing attribute surfaces its specific
	 * `woocommerce_rest_taxonomy_invalid` 404 via {@see RestError::from()} instead
	 * of collapsing to a generic permission denial. The explicit activity guard
	 * keeps the denial clean when WooCommerce is inactive and the capability is
	 * unmapped.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may create product attribute terms.
	 */
	public function hasPermission( $input ): bool {
		return WooPlugin::isActive() && current_user_can( 'edit_product_terms' );
	}

	/**
	 * Executes the ability by dispatching the internal `wc/v3` REST create request.
	 *
	 * The `attribute_id` is the route segment naming the parent attribute, so it is
	 * concatenated into the path rather than set as a body param. A bad
	 * `attribute_id` 404s as `woocommerce_rest_taxonomy_invalid`; a duplicate slug
	 * 400s as `term_exists`.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The shaped created term row, or the REST error.
	 */
	public function execute( $input ) {
		if ( ! WooPlugin::isActive() ) {
			return WooPlugin::unavailable();
		}

		$input        = is_array( $input ) ? $input : array();
		$attribute_id = absint( $input['attribute_id'] ?? 0 );

		$request = new WP_REST_Request( 'POST', '/wc/v3/products/attributes/' . $attribute_id . '/terms' );

		$request->set_param( 'name', (string) ( $input['name'] ?? '' ) );
		if ( isset( $input['slug'] ) && '' !== $input['slug'] ) {
			$request->set_param( 'slug', (string) $input['slug'] );
		}
		if ( isset( $input['description'] ) && '' !== $input['description'] ) {
			$request->set_param( 'description', (string) $input['description'] );
		}
		if ( array_key_exists( 'menu_order', $input ) ) {
			$request->set_param( 'menu_order', (int) $input['menu_order'] );
		}

		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			return RestError::from( $response );
		}

		$data = rest_get_server()->response_to_data( $response, false );
		$data = is_array( $data ) ? $data : array();

		return ProductTermListShaper::termSummary( $data );
	}
}
