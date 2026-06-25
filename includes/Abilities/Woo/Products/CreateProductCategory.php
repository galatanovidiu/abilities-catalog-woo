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
 * Write ability: `og-wc-products/create-product-category`.
 *
 * Wraps `POST wc/v3/products/categories` via `rest_do_request()`, creating a new
 * product category term in the hierarchical `product_cat` taxonomy. The result is
 * shaped through {@see ProductTermListShaper::termSummary()} into the same flat,
 * closed term row the batch-02 reads return (id, name, slug, parent, count,
 * description), so the raw category body — `display`, `image`, `menu_order` — never
 * leaks; those are write-only inputs here and are not echoed back.
 *
 * The category controller routes `slug`, `parent`, and `description` through the
 * shared term-create path and `display` / `image` through its term-meta update, so
 * this dispatches one request and lets WooCommerce run its own validation, slug
 * handling, and image sideload. A duplicate slug surfaces WordPress core's
 * `term_exists` 400 (not a WooCommerce-prefixed code), which the agent can branch
 * on.
 *
 * Only available when WooCommerce is active (it is a {@see ConditionalAbility}).
 *
 * @since 0.1.0
 */
final class CreateProductCategory implements ConditionalAbility {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-wc-products/create-product-category';
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
			'label'               => __( 'Create Product Category', 'abilities-catalog-woo' ),
			'description'         => __( 'Creates a new WooCommerce product category (the hierarchical product_cat taxonomy) and returns the new category as a flat row: id, name, slug, parent, product count, and description. Only name is required; WordPress derives the slug from the name unless you set one. Set parent to nest the category under another (parent 0, the default, makes a top-level category); discover a parent id with og-wc-products/list-product-categories. A slug already used in this taxonomy is rejected with a WordPress core term_exists 400 error (note: not woocommerce_rest_*), so retry with a different slug or omit it. This affects only the store catalog taxonomy, not products, orders, or money, and is reversible by deleting the category. The result omits the display type and image you may have set; surface the returned id to review the category in wp-admin.', 'abilities-catalog-woo' ),
			'category'            => 'og-wc-products',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'name' ),
				'properties'           => array(
					'name'        => array(
						'type'        => 'string',
						'description' => __( 'The category name shown to shoppers, e.g. "Hats". Required.', 'abilities-catalog-woo' ),
					),
					'slug'        => array(
						'type'        => 'string',
						'description' => __( 'The URL slug for the category. Omit to let WordPress derive it from the name. A slug already in use on product_cat is rejected with a term_exists 400 error.', 'abilities-catalog-woo' ),
					),
					'parent'      => array(
						'type'        => 'integer',
						'minimum'     => 0,
						'description' => __( 'The parent category term id to nest this category under. Use 0 (the default) for a top-level category. Discover a parent id with og-wc-products/list-product-categories.', 'abilities-catalog-woo' ),
					),
					'description' => array(
						'type'        => 'string',
						'description' => __( 'The category description (HTML allowed; sanitized by WordPress).', 'abilities-catalog-woo' ),
					),
					'display'     => array(
						'type'        => 'string',
						'enum'        => array( 'default', 'products', 'subcategories', 'both' ),
						'default'     => 'default',
						'description' => __( 'What the category archive page shows: "default" (the theme default), "products" (products only), "subcategories" (subcategories only), or "both".', 'abilities-catalog-woo' ),
					),
					'image'       => array(
						'type'                 => 'object',
						'description'          => __( 'The category thumbnail image. Set id to use an existing media library attachment, or set src to a URL that WooCommerce sideloads into the media library when id is empty. name and alt set the image title and alt text.', 'abilities-catalog-woo' ),
						'properties'           => array(
							'id'   => array(
								'type'        => 'integer',
								'minimum'     => 1,
								'description' => __( 'An existing media attachment id to use as the category image.', 'abilities-catalog-woo' ),
							),
							'src'  => array(
								'type'        => 'string',
								'format'      => 'uri',
								'description' => __( 'A source image URL; WooCommerce sideloads it into the media library when id is empty.', 'abilities-catalog-woo' ),
							),
							'name' => array(
								'type'        => 'string',
								'description' => __( 'The image title.', 'abilities-catalog-woo' ),
							),
							'alt'  => array(
								'type'        => 'string',
								'description' => __( 'The image alternative text.', 'abilities-catalog-woo' ),
							),
						),
						'additionalProperties' => false,
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
				'screen'       => 'term.php?taxonomy=product_cat&tag_ID={id}&post_type=product',
			),
		);
	}

	/**
	 * Permission check: WooCommerce's edit capability for product terms.
	 *
	 * Encodes the catalog capability for `og-wc-products/create-product-category`: the
	 * `edit_product_terms` capability, which is what
	 * `wc_rest_check_product_term_permissions( 'product_cat', 'create' )` resolves to
	 * on the wrapped `POST wc/v3/products/categories` route (it maps `create` to the
	 * taxonomy's `edit_terms` cap, registered as `edit_product_terms` for
	 * `product_cat`). This is the WRITE context, so it is `edit_product_terms` —
	 * stricter than the read abilities' `manage_product_terms` mapping. Coarse and
	 * object-independent; the wrapped route surfaces the specific create errors
	 * (e.g. core `term_exists` 400 for a duplicate slug) via {@see RestError::from()}
	 * instead of collapsing them to a permission denial. The explicit activity guard
	 * keeps the denial clean when WooCommerce is inactive and the cap is unmapped.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may create product categories.
	 */
	public function hasPermission( $input ): bool {
		return WooPlugin::isActive() && current_user_can( 'edit_product_terms' );
	}

	/**
	 * Executes the ability by dispatching the internal `wc/v3` REST create request.
	 *
	 * Create forwards only fields the caller actually set (skipping unset/empty
	 * scalars) so an omitted field keeps the controller default; the nested `image`
	 * object is forwarded whole when present.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The shaped category term row, or the REST error
	 *                                        (e.g. core `term_exists` 400 for a duplicate slug).
	 */
	public function execute( $input ) {
		if ( ! WooPlugin::isActive() ) {
			return WooPlugin::unavailable();
		}

		$input = is_array( $input ) ? $input : array();

		$request = new WP_REST_Request( 'POST', '/wc/v3/products/categories' );

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
		if ( isset( $input['display'] ) && '' !== $input['display'] ) {
			$request->set_param( 'display', (string) $input['display'] );
		}
		if ( isset( $input['image'] ) && is_array( $input['image'] ) ) {
			$request->set_param( 'image', $input['image'] );
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
