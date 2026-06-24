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
 * Read ability: `wc-products/get-product-tag`.
 *
 * Wraps `GET wc/v3/products/tags/<id>` via `rest_do_request()` and returns one
 * product tag as a flat, closed record: its id, name, slug, product count, and
 * description. Product tags are a flat (non-hierarchical) taxonomy, so unlike a
 * category the row carries no `parent`. The raw `wc/v3` term body adds `_links`
 * the consumer never reads; this projects only the useful subset.
 *
 * Only available when WooCommerce is active (it is a {@see ConditionalAbility}).
 *
 * @since 0.1.0
 */
final class GetProductTag implements ConditionalAbility {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'wc-products/get-product-tag';
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
			'label'               => __( 'Get Product Tag', 'abilities-catalog-woo' ),
			'description'         => __( 'Returns one WooCommerce product tag by ID: its name, slug, the number of products assigned to it (count), and its description. Product tags are a flat taxonomy, so no parent is returned. Discover IDs with wc-products/list-product-tags; use wc-products/get-product-category for a category instead.', 'abilities-catalog-woo' ),
			'category'            => 'wc-products',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'id' ),
				'properties'           => array(
					'id' => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'The product tag term ID. Discover IDs with wc-products/list-product-tags.', 'abilities-catalog-woo' ),
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => $this->outputSchema(),
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
	 * Mirrors `wc_rest_check_product_term_permissions( 'product_tag', 'read' )`,
	 * which resolves the `product_tag` taxonomy's `manage_terms` cap to
	 * `manage_product_terms` — the baseline the wrapped `wc/v3` GET route
	 * enforces. This is a coarse, object-INDEPENDENT type-level guard: the
	 * per-object decision is deferred to the wrapped route, so a missing tag
	 * surfaces its specific `woocommerce_rest_term_invalid` 404 via
	 * {@see RestError::from()} instead of collapsing to a generic permission
	 * denial. The explicit activity guard keeps the denial clean when WooCommerce
	 * is inactive.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may read product tags.
	 */
	public function hasPermission( $input ): bool {
		return WooPlugin::isActive() && current_user_can( 'manage_product_terms' );
	}

	/**
	 * Executes the ability by dispatching the internal `wc/v3` REST request.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The shaped tag record, or the REST error
	 *                                        (e.g. `woocommerce_rest_term_invalid` 404).
	 */
	public function execute( $input ) {
		if ( ! WooPlugin::isActive() ) {
			return WooPlugin::unavailable();
		}

		$input = is_array( $input ) ? $input : array();
		$id    = absint( $input['id'] ?? 0 );

		$request  = new WP_REST_Request( 'GET', '/wc/v3/products/tags/' . $id );
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
	 * Builds the closed output schema: the flat tag-row fields for one term,
	 * without `parent` (tags are a flat taxonomy).
	 *
	 * Derived from {@see ProductTermListShaper::termItemSchema()} so the field
	 * descriptions stay in sync with the term list abilities; the `parent`
	 * property is removed because the tag taxonomy has none.
	 *
	 * @return array<string,mixed> A JSON-Schema object fragment.
	 */
	private function outputSchema(): array {
		$schema = ProductTermListShaper::termItemSchema();

		unset( $schema['properties']['parent'] );

		return $schema;
	}
}
