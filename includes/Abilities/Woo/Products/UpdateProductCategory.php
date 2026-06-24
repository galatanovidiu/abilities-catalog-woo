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
 * Write ability: `wc-products/update-product-category`.
 *
 * Wraps `PUT wc/v3/products/categories/<id>` via `rest_do_request()`, updating an
 * existing product category (the hierarchical `product_cat` taxonomy). The `id` is
 * concatenated into the route path, and every editable field is forwarded only
 * when the caller sends it, so an omitted field keeps its current value. The
 * result is the same flat, closed term row the read abilities return through
 * {@see ProductTermListShaper::termSummary()} — id, name, slug, parent, count,
 * description — so `display`, `image`, and `menu_order` are accepted as input but
 * never echoed back.
 *
 * This is a safe, reversible catalog edit: it touches only the `product_cat`
 * taxonomy, not products, orders, or money, and any change can be reverted with a
 * second update. A missing category surfaces the wrapped route's specific
 * `woocommerce_rest_term_invalid` 404 via {@see RestError::from()} rather than
 * collapsing to a generic permission denial.
 *
 * Only available when WooCommerce is active (it is a {@see ConditionalAbility}).
 *
 * @since 0.1.0
 */
final class UpdateProductCategory implements ConditionalAbility {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'wc-products/update-product-category';
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
			'label'               => __( 'Update Product Category', 'abilities-catalog-woo' ),
			'description'         => __( 'Updates an existing WooCommerce product category (the hierarchical product_cat taxonomy) by ID and returns the shaped category row: id, name, slug, parent, product count, and description. Send only the fields you want to change; an omitted field keeps its current value. Reversible catalog edit affecting only the category taxonomy, not products, orders, or money. Set parent to a category term ID to nest it (0 makes it top-level); changing slug to one already used by another product_cat term returns a term_exists error. display, image, and menu_order can be set but are not returned. Discover IDs with wc-products/list-product-categories.', 'abilities-catalog-woo' ),
			'category'            => 'wc-products',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'id' ),
				'properties'           => array(
					'id'          => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'The product category term ID to update. Discover IDs with wc-products/list-product-categories.', 'abilities-catalog-woo' ),
					),
					'name'        => array(
						'type'        => 'string',
						'description' => __( 'A new category name. Omit to keep the current name.', 'abilities-catalog-woo' ),
					),
					'slug'        => array(
						'type'        => 'string',
						'description' => __( 'A new URL slug. Must be unique within product_cat; reusing another category\'s slug returns a term_exists error. Omit to keep the current slug.', 'abilities-catalog-woo' ),
					),
					'parent'      => array(
						'type'        => 'integer',
						'minimum'     => 0,
						'description' => __( 'The parent category term ID to nest this category under, or 0 to make it top-level. Discover IDs with wc-products/list-product-categories. Omit to keep the current parent.', 'abilities-catalog-woo' ),
					),
					'description' => array(
						'type'        => 'string',
						'description' => __( 'A new category description (HTML allowed; sanitized by WordPress). Omit to keep the current description.', 'abilities-catalog-woo' ),
					),
					'display'     => array(
						'type'        => 'string',
						'enum'        => array( 'default', 'products', 'subcategories', 'both' ),
						'description' => __( 'What the category archive page shows: "default" (the site\'s archive default), "products", "subcategories", or "both". Not returned in the result. Omit to keep the current setting.', 'abilities-catalog-woo' ),
					),
					'image'       => array(
						'type'                 => 'object',
						'description'          => __( 'The category thumbnail image. Provide id (an existing media attachment ID); or set src to a URL to sideload it into the media library when id is empty. Not returned in the result. Omit to keep the current image.', 'abilities-catalog-woo' ),
						'properties'           => array(
							'id'   => array(
								'type'        => 'integer',
								'minimum'     => 1,
								'description' => __( 'An existing media attachment ID to use as the category image.', 'abilities-catalog-woo' ),
							),
							'src'  => array(
								'type'        => 'string',
								'format'      => 'uri',
								'description' => __( 'An image URL to sideload into the media library when id is not given.', 'abilities-catalog-woo' ),
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
					'menu_order'  => array(
						'type'        => 'integer',
						'description' => __( 'The sort position used to custom-order categories. Not returned in the result. Omit to keep the current order.', 'abilities-catalog-woo' ),
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
	 * Mirrors `wc_rest_check_product_term_permissions( 'product_cat', 'edit' )` on
	 * the wrapped `PUT wc/v3/products/categories/<id>` route, which maps the `edit`
	 * context to the `product_cat` taxonomy's `edit_terms` capability — registered
	 * as `edit_product_terms`. This is the WRITE-context cap (`edit_terms`), which
	 * differs from the read abilities' `manage_product_terms` (`manage_terms`). It
	 * is a coarse, object-INDEPENDENT guard: the per-object decision is deferred to
	 * the wrapped route, so a missing category surfaces its specific
	 * `woocommerce_rest_term_invalid` 404 via {@see RestError::from()} instead of
	 * collapsing to a generic permission denial. The explicit activity guard keeps
	 * the denial clean when WooCommerce is inactive and the capability is unmapped.
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
	 * @return array<string,mixed>|\WP_Error The shaped category term row, or the REST error
	 *                                        (e.g. `woocommerce_rest_term_invalid` 404, `term_exists` 400).
	 */
	public function execute( $input ) {
		if ( ! WooPlugin::isActive() ) {
			return WooPlugin::unavailable();
		}

		$input = is_array( $input ) ? $input : array();
		$id    = absint( $input['id'] ?? 0 );

		$request = new WP_REST_Request( 'PUT', '/wc/v3/products/categories/' . $id );

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
		if ( array_key_exists( 'display', $input ) ) {
			$request->set_param( 'display', (string) $input['display'] );
		}
		if ( array_key_exists( 'image', $input ) && is_array( $input['image'] ) ) {
			$request->set_param( 'image', $input['image'] );
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
