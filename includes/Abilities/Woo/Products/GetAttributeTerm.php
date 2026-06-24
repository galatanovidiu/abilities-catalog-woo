<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogWoo\Abilities\Woo\Products;

use GalatanOvidiu\AbilitiesCatalogWoo\Contracts\ConditionalAbility;
use GalatanOvidiu\AbilitiesCatalogWoo\Support\RestError;
use GalatanOvidiu\AbilitiesCatalogWoo\Support\WooPlugin;
use WP_REST_Request;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read ability: `wc-products/get-attribute-term`.
 *
 * Wraps `GET wc/v3/products/attributes/<attribute_id>/terms/<id>` via
 * `rest_do_request()` and returns one attribute term as a flat, closed record:
 * its id, name, slug, the count of published products assigned to it, and its
 * description. Both ids are route segments: `attribute_id` selects the global
 * attribute (its `pa_*` taxonomy) and `id` selects the term inside it. The raw
 * `wc/v3` term body also carries `menu_order`; this projects only the useful
 * subset.
 *
 * Only available when WooCommerce is active (it is a {@see ConditionalAbility}).
 *
 * @since 0.1.0
 */
final class GetAttributeTerm implements ConditionalAbility {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'wc-products/get-attribute-term';
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
			'label'               => __( 'Get Attribute Term', 'abilities-catalog-woo' ),
			'description'         => __( 'Returns one WooCommerce product attribute term by ID: its name, slug, the count of published products assigned to it, and its description. An attribute term is one value of a global attribute (e.g. "Red" under the "Color" attribute). Provide attribute_id (the parent attribute) and id (the term). Discover attribute_id with wc-products/list-product-attributes; discover id with wc-products/list-attribute-terms. Use wc-products/list-attribute-terms to scan an attribute\'s terms; use this for one term\'s detail.', 'abilities-catalog-woo' ),
			'category'            => 'wc-products',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'attribute_id', 'id' ),
				'properties'           => array(
					'attribute_id' => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'The parent attribute ID (the global attribute the term belongs to). Discover IDs with wc-products/list-product-attributes.', 'abilities-catalog-woo' ),
					),
					'id'           => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'The attribute term ID. Discover IDs with wc-products/list-attribute-terms.', 'abilities-catalog-woo' ),
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'id' ),
				'properties'           => array(
					'id'          => array(
						'type'        => 'integer',
						'description' => __( 'The attribute term ID. Use it to filter products by this attribute term.', 'abilities-catalog-woo' ),
					),
					'name'        => array(
						'type'        => 'string',
						'description' => __( 'The term name shown to shoppers, e.g. Red.', 'abilities-catalog-woo' ),
					),
					'slug'        => array(
						'type'        => 'string',
						'description' => __( 'The term slug used in URLs and queries.', 'abilities-catalog-woo' ),
					),
					'count'       => array(
						'type'        => 'integer',
						'description' => __( 'The number of published products assigned to this term.', 'abilities-catalog-woo' ),
					),
					'description' => array(
						'type'        => 'string',
						'description' => __( 'The term description, or an empty string when none is set.', 'abilities-catalog-woo' ),
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
	 * Permission check: WooCommerce's manage capability for product terms.
	 *
	 * The wrapped `wc/v3` term GET route runs `wc_rest_check_product_term_permissions(
	 * $taxonomy, 'read' )`, which resolves the `pa_*` taxonomy's `manage_terms`
	 * meta-cap to `manage_product_terms` — the baseline every successful caller must
	 * hold. This is a coarse, object-INDEPENDENT type-level guard: the per-object
	 * decision is deferred to the wrapped route, so a bad attribute_id surfaces its
	 * specific `woocommerce_rest_taxonomy_invalid` 404 and a missing term surfaces
	 * `woocommerce_rest_term_invalid` 404 via {@see RestError::from()} instead of
	 * collapsing to a generic permission denial. The explicit activity guard keeps
	 * the denial clean when WooCommerce is inactive.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may read product attribute terms.
	 */
	public function hasPermission( $input ): bool {
		return WooPlugin::isActive() && current_user_can( 'manage_product_terms' );
	}

	/**
	 * Executes the ability by dispatching the internal `wc/v3` REST request.
	 *
	 * The `attribute_id` and `id` are concatenated into the route path because both
	 * are route segments, not query params.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The shaped attribute term record, or the REST error
	 *                                        (`woocommerce_rest_taxonomy_invalid` 404 for a bad
	 *                                        attribute_id, `woocommerce_rest_term_invalid` 404 for a
	 *                                        missing term).
	 */
	public function execute( $input ) {
		if ( ! WooPlugin::isActive() ) {
			return WooPlugin::unavailable();
		}

		$input        = is_array( $input ) ? $input : array();
		$attribute_id = absint( $input['attribute_id'] ?? 0 );
		$id           = absint( $input['id'] ?? 0 );

		$request  = new WP_REST_Request( 'GET', '/wc/v3/products/attributes/' . $attribute_id . '/terms/' . $id );
		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			return RestError::from( $response );
		}

		$data = rest_get_server()->response_to_data( $response, false );
		$data = is_array( $data ) ? $data : array();

		return array(
			'id'          => (int) ( $data['id'] ?? $id ),
			'name'        => (string) ( $data['name'] ?? '' ),
			'slug'        => (string) ( $data['slug'] ?? '' ),
			'count'       => (int) ( $data['count'] ?? 0 ),
			'description' => (string) ( $data['description'] ?? '' ),
		);
	}
}
