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
 * Write ability: `og-wc-products/update-product-tag`.
 *
 * Wraps `PUT wc/v3/products/tags/<id>` via `rest_do_request()` (the `id` is a path
 * segment, built by concatenation), then returns the updated tag as one flat,
 * closed term row — id, name, slug, parent (always 0 for the flat product_tag
 * taxonomy), count, and description — through
 * {@see ProductTermListShaper::termSummary()}, the same shape the read batch uses.
 * Send only the fields you want to change; an omitted field keeps its current
 * value. A new `slug` that already exists on the taxonomy is rejected with a
 * `term_exists` 400 (WordPress core's own code, surfaced via {@see RestError::from()}).
 *
 * This edit is reversible (run it again with the old values) and affects only the
 * catalog taxonomy, not orders or money.
 *
 * Only available when WooCommerce is active (it is a {@see ConditionalAbility}).
 *
 * @since 0.1.0
 */
final class UpdateProductTag implements ConditionalAbility {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-wc-products/update-product-tag';
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
			'label'               => __( 'Update Product Tag', 'abilities-catalog-woo' ),
			'description'         => __( 'Updates an existing WooCommerce product tag by ID and returns the updated tag as a flat row: id, name, slug, parent (always 0 — product tags are a flat taxonomy), product count, and description. Send only the fields you want to change; an omitted field keeps its current value. Setting a slug that already exists on the tag taxonomy fails with a term_exists 400, so branch on that to pick a different slug. This edit is reversible (run it again with the previous values) and affects only the catalog taxonomy, not orders or money. Discover IDs with og-wc-products/list-product-tags. Use og-wc-products/update-product-category for hierarchical categories instead.', 'abilities-catalog-woo' ),
			'category'            => 'og-wc-products',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'id' ),
				'properties'           => array(
					'id'          => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'The product tag term ID to update. Discover IDs with og-wc-products/list-product-tags.', 'abilities-catalog-woo' ),
					),
					'name'        => array(
						'type'        => 'string',
						'description' => __( 'A new tag name shown to shoppers. Omit to keep the current name.', 'abilities-catalog-woo' ),
					),
					'slug'        => array(
						'type'        => 'string',
						'description' => __( 'A new slug used in URLs and queries. A slug that already exists on the tag taxonomy returns a term_exists 400. Omit to keep the current slug.', 'abilities-catalog-woo' ),
					),
					'description' => array(
						'type'        => 'string',
						'description' => __( 'A new tag description. Pass an empty string to clear it; omit to keep the current description.', 'abilities-catalog-woo' ),
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
				'screen'       => 'term.php?taxonomy=product_tag&tag_ID={id}&post_type=product',
			),
		);
	}

	/**
	 * Permission check: WooCommerce's edit capability for product terms.
	 *
	 * Mirrors `wc_rest_check_product_term_permissions( 'product_tag', 'edit', $id )`,
	 * which maps the `edit` context to the taxonomy's `edit_terms` capability —
	 * registered as `edit_product_terms` for `product_tag`
	 * (class-wc-post-types.php). This differs from the product-term READ abilities,
	 * which use `manage_product_terms` (the read context maps to `manage_terms`); the
	 * write context maps to `edit_terms`. This is a coarse, object-INDEPENDENT
	 * guard: the per-object decision is deferred to the wrapped route, so a missing
	 * tag surfaces its specific `woocommerce_rest_term_invalid` 404 via
	 * {@see RestError::from()} instead of collapsing to a generic permission denial.
	 * The explicit activity guard keeps the denial clean when WooCommerce is
	 * inactive.
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
	 * The `id` is concatenated into the route path (never set as a body param) so
	 * the wrapped route resolves the right tag. Each present editable field is
	 * forwarded on key presence so an explicit empty string can clear it.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The shaped updated tag row, or the REST error
	 *                                        (e.g. `woocommerce_rest_term_invalid` 404,
	 *                                        `term_exists` 400).
	 */
	public function execute( $input ) {
		if ( ! WooPlugin::isActive() ) {
			return WooPlugin::unavailable();
		}

		$input = is_array( $input ) ? $input : array();
		$id    = absint( $input['id'] ?? 0 );

		$request = new WP_REST_Request( 'PUT', '/wc/v3/products/tags/' . $id );

		foreach ( array( 'name', 'slug', 'description' ) as $field ) {
			if ( ! array_key_exists( $field, $input ) ) {
				continue;
			}

			$request->set_param( $field, (string) $input[ $field ] );
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
