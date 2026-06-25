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
 * Write ability: `og-wc-products/update-product-brand`.
 *
 * Wraps `PUT wc/v3/products/brands/<id>` via `rest_do_request()`, updating an
 * existing product brand (the hierarchical `product_brand` taxonomy). The Brands
 * controller extends the categories controller, so the brand write schema is the
 * category one; this exposes the minimal useful subset — name, slug, parent,
 * description. The `id` is concatenated into the route path, and every editable
 * field is forwarded only when the caller sends it, so an omitted field keeps its
 * current value. The result is the same flat, closed term row the read abilities
 * return through {@see ProductTermListShaper::termSummary()} — id, name, slug,
 * parent, count, description — so the raw brand body (`display`, `image`,
 * `menu_order`) never leaks.
 *
 * This is a safe, reversible catalog edit: it touches only the `product_brand`
 * taxonomy, not products, orders, or money, and any change can be reverted with a
 * second update. A missing brand surfaces the wrapped route's specific
 * `woocommerce_rest_term_invalid` 404 via {@see RestError::from()} rather than
 * collapsing to a generic permission denial.
 *
 * Only available when WooCommerce is active AND the product Brands feature has
 * registered its `/wc/v3/products/brands` route (it is a {@see ConditionalAbility}
 * gated on {@see WooPlugin::hasBrandsSupport()}). When Brands is absent this
 * ability does not register, so it degrades cleanly rather than denying.
 *
 * @since 0.1.0
 */
final class UpdateProductBrand implements ConditionalAbility {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-wc-products/update-product-brand';
	}

	/**
	 * {@inheritDoc}
	 */
	public function isAvailable(): bool {
		return WooPlugin::isActive() && WooPlugin::hasBrandsSupport();
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return array(
			'label'               => __( 'Update Product Brand', 'abilities-catalog-woo' ),
			'description'         => __( 'Updates an existing WooCommerce product brand (the hierarchical product_brand taxonomy) by ID and returns the shaped brand row: id, name, slug, parent, product count, and description. Send only the fields you want to change; an omitted field keeps its current value. Reversible catalog edit affecting only the brand taxonomy, not products, orders, or money. Set parent to a brand term ID to nest it (0 makes it top-level); changing slug to one already used by another product_brand term returns a term_exists error. This ability exists only when the store\'s WooCommerce Brands feature is active. Discover IDs with og-wc-products/list-product-brands.', 'abilities-catalog-woo' ),
			'category'            => 'og-wc-products',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'id' ),
				'properties'           => array(
					'id'          => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'The brand term ID to update; discover with og-wc-products/list-product-brands.', 'abilities-catalog-woo' ),
					),
					'name'        => array(
						'type'        => 'string',
						'description' => __( 'A new brand name. Omit to keep the current name.', 'abilities-catalog-woo' ),
					),
					'slug'        => array(
						'type'        => 'string',
						'description' => __( 'A new URL slug. Must be unique within product_brand; reusing another brand\'s slug returns a term_exists error. Omit to keep the current slug.', 'abilities-catalog-woo' ),
					),
					'parent'      => array(
						'type'        => 'integer',
						'minimum'     => 0,
						'description' => __( 'The parent brand term ID to nest this brand under, or 0 to make it top-level. Discover IDs with og-wc-products/list-product-brands. Omit to keep the current parent.', 'abilities-catalog-woo' ),
					),
					'description' => array(
						'type'        => 'string',
						'description' => __( 'A new brand description (HTML allowed; sanitized by WordPress). Omit to keep the current description.', 'abilities-catalog-woo' ),
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
				'screen'       => 'term.php?taxonomy=product_brand&tag_ID={id}&post_type=product',
			),
		);
	}

	/**
	 * Permission check: WooCommerce's edit capability for product terms.
	 *
	 * Mirrors `wc_rest_check_product_term_permissions( 'product_brand', 'edit' )` on
	 * the wrapped `PUT wc/v3/products/brands/<id>` route, which maps the `edit`
	 * context to the `product_brand` taxonomy's `edit_terms` capability — registered
	 * as `edit_product_terms`. This is the WRITE-context cap (`edit_terms`), which
	 * differs from the read abilities' `manage_product_terms` (`manage_terms`). It is
	 * a coarse, object-INDEPENDENT guard: the per-object decision is deferred to the
	 * wrapped route, so a missing brand surfaces its specific
	 * `woocommerce_rest_term_invalid` 404 via {@see RestError::from()} instead of
	 * collapsing to a generic permission denial. The brands-support gate lives in
	 * {@see self::isAvailable()}, not here, so this stays a plain capability check;
	 * the explicit activity guard keeps the denial clean when WooCommerce is inactive
	 * and the capability is unmapped.
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
	 * The `id` is concatenated into the route path; each editable field is forwarded
	 * only when present in the input, so an omitted field keeps its current value.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The shaped brand term row, or the REST error
	 *                                        (e.g. `woocommerce_rest_term_invalid` 404, `term_exists` 400).
	 */
	public function execute( $input ) {
		if ( ! WooPlugin::isActive() ) {
			return WooPlugin::unavailable();
		}

		$input = is_array( $input ) ? $input : array();
		$id    = absint( $input['id'] ?? 0 );

		$request = new WP_REST_Request( 'PUT', '/wc/v3/products/brands/' . $id );

		if ( array_key_exists( 'name', $input ) ) {
			$request->set_param( 'name', (string) $input['name'] );
		}
		if ( array_key_exists( 'slug', $input ) ) {
			$request->set_param( 'slug', (string) $input['slug'] );
		}
		if ( array_key_exists( 'parent', $input ) ) {
			$request->set_param( 'parent', absint( $input['parent'] ) );
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

		return ProductTermListShaper::termSummary( $data );
	}
}
