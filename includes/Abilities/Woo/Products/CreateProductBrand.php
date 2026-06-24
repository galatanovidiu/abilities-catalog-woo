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
 * Write ability: `wc-products/create-product-brand`.
 *
 * Wraps `POST wc/v3/products/brands` via `rest_do_request()`, creating a new
 * product brand term in the hierarchical `product_brand` taxonomy. The result is
 * shaped through {@see ProductTermListShaper::termSummary()} into the same flat,
 * closed term row the batch reads return (id, name, slug, parent, count,
 * description), so the raw brand body — `display`, `image`, `menu_order` — never
 * leaks; those are not exposed by this ability.
 *
 * The Brands controller extends the categories controller, so the writable term
 * fields are `name`, `slug`, `parent`, and `description`; this exposes that
 * minimal useful subset. The create/update routes are registered on the WC terms
 * base, which makes `name` required on create. This dispatches one request and
 * lets WooCommerce run its own validation and slug handling. A duplicate slug
 * surfaces WordPress core's `term_exists` 400 (not a WooCommerce-prefixed code),
 * which the agent can branch on.
 *
 * Only available when WooCommerce is active AND the product Brands feature has
 * registered its `/wc/v3/products/brands` route (it is a {@see ConditionalAbility}
 * gated on {@see WooPlugin::hasBrandsSupport()}). When Brands is absent this
 * ability does not register, so it degrades cleanly rather than denying.
 *
 * @since 0.1.0
 */
final class CreateProductBrand implements ConditionalAbility {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'wc-products/create-product-brand';
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
			'label'               => __( 'Create Product Brand', 'abilities-catalog-woo' ),
			'description'         => __( 'Creates a new WooCommerce product brand (the hierarchical product_brand taxonomy) and returns the new brand as a flat row: id, name, slug, parent, product count, and description. Only name is required; WordPress derives the slug from the name unless you set one. Set parent to nest the brand under another (parent 0, the default, makes a top-level brand); discover a parent id with wc-products/list-product-brands. A slug already used in this taxonomy is rejected with a WordPress core term_exists 400 error (note: not woocommerce_rest_*), so retry with a different slug or omit it. This ability exists only when the store\'s WooCommerce Brands feature is active. This affects only the store catalog taxonomy, not products, orders, or money, and is reversible by deleting the brand. Surface the returned id to review the brand in wp-admin.', 'abilities-catalog-woo' ),
			'category'            => 'wc-products',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'name' ),
				'properties'           => array(
					'name'        => array(
						'type'        => 'string',
						'description' => __( 'The brand name shown to shoppers, e.g. "Acme". Required.', 'abilities-catalog-woo' ),
					),
					'slug'        => array(
						'type'        => 'string',
						'description' => __( 'The URL slug for the brand. Omit to let WordPress derive it from the name. A slug already in use on product_brand is rejected with a term_exists 400 error.', 'abilities-catalog-woo' ),
					),
					'parent'      => array(
						'type'        => 'integer',
						'minimum'     => 0,
						'description' => __( 'The parent brand term id to nest this brand under. Use 0 (the default) for a top-level brand. Discover a parent id with wc-products/list-product-brands.', 'abilities-catalog-woo' ),
					),
					'description' => array(
						'type'        => 'string',
						'description' => __( 'The brand description (HTML allowed; sanitized by WordPress).', 'abilities-catalog-woo' ),
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
	 * Encodes the catalog capability for `wc-products/create-product-brand`: the
	 * `edit_product_terms` capability, which is what
	 * `wc_rest_check_product_term_permissions( 'product_brand', 'create' )` resolves
	 * to on the wrapped `POST wc/v3/products/brands` route (it maps `create` to the
	 * taxonomy's `edit_terms` cap, registered as `edit_product_terms` for
	 * `product_brand`). This is the WRITE context, so it is `edit_product_terms` —
	 * stricter than the read abilities' `manage_product_terms` mapping. Coarse and
	 * object-independent; the wrapped route surfaces the specific create errors (e.g.
	 * core `term_exists` 400 for a duplicate slug) via {@see RestError::from()}
	 * instead of collapsing them to a permission denial. The brands-support gate
	 * lives in {@see self::isAvailable()}, not here, so this stays a plain capability
	 * check; the explicit activity guard keeps the denial clean when WooCommerce is
	 * inactive and the cap is unmapped.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may create product brands.
	 */
	public function hasPermission( $input ): bool {
		return WooPlugin::isActive() && current_user_can( 'edit_product_terms' );
	}

	/**
	 * Executes the ability by dispatching the internal `wc/v3` REST create request.
	 *
	 * Create forwards only fields the caller actually set (skipping unset/empty
	 * scalars) so an omitted field keeps the controller default.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The shaped brand term row, or the REST error
	 *                                        (e.g. core `term_exists` 400 for a duplicate slug).
	 */
	public function execute( $input ) {
		if ( ! WooPlugin::isActive() ) {
			return WooPlugin::unavailable();
		}

		$input = is_array( $input ) ? $input : array();

		$request = new WP_REST_Request( 'POST', '/wc/v3/products/brands' );

		$request->set_param( 'name', (string) ( $input['name'] ?? '' ) );

		if ( isset( $input['slug'] ) && '' !== $input['slug'] ) {
			$request->set_param( 'slug', (string) $input['slug'] );
		}
		if ( array_key_exists( 'parent', $input ) ) {
			$request->set_param( 'parent', absint( $input['parent'] ) );
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

		return ProductTermListShaper::termSummary( $data );
	}
}
