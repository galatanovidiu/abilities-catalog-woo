<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogWoo\Abilities\Woo\Products;

use GalatanOvidiu\AbilitiesCatalogWoo\Contracts\ConditionalAbility;
use GalatanOvidiu\AbilitiesCatalogWoo\Support\ProductListShaper;
use GalatanOvidiu\AbilitiesCatalogWoo\Support\ProductWriteRequest;
use GalatanOvidiu\AbilitiesCatalogWoo\Support\ProductWriteSchema;
use GalatanOvidiu\AbilitiesCatalogWoo\Support\RestError;
use GalatanOvidiu\AbilitiesCatalogWoo\Support\WooPlugin;
use WP_REST_Request;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Write ability: `wc-products/create-product-variation`.
 *
 * Wraps `POST wc/v3/products/<product_id>/variations` via `rest_do_request()`,
 * adding one variation to an existing VARIABLE product. The parent is named only
 * by the `product_id` route segment, so the slash-bearing path is built by
 * concatenation (never `set_param`). The writable subset (attribute selections,
 * sku, prices, stock, image, etc.) is forwarded by {@see ProductWriteRequest::fill()};
 * the result is the created variation projected through
 * {@see ProductWriteRequest::shapeVariationResult()} (the
 * {@see ProductListShaper::variationSummary()} fields plus the parent `product_id`),
 * never the raw ~120-field variation body.
 *
 * Preconditions surfaced by the route, not masked as a permission failure: when
 * `attributes` are supplied for a parent that does not exist the route returns
 * `woocommerce_rest_product_variation_invalid_parent` (404). To add many variations
 * at once from the parent's attribute combinations, use
 * `wc-products/generate-product-variations` instead.
 *
 * Only available when WooCommerce is active (it is a {@see ConditionalAbility}).
 *
 * @since 0.1.0
 */
final class CreateProductVariation implements ConditionalAbility {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'wc-products/create-product-variation';
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
			'label'               => __( 'Create Product Variation', 'abilities-catalog-woo' ),
			'description'         => __( 'Adds one variation to an existing WooCommerce VARIABLE product and returns the shaped variation (id, sku, prices, stock, the attribute selections that define it, permalink, edit_link) plus the parent product_id. Identify each variation by its attributes, e.g. {"name": "Color", "option": "Red"} — the names must match attributes the parent marks "used for variations". The parent should already be type=variable; if you pass attributes for a parent that does not exist the route returns woocommerce_rest_product_variation_invalid_parent (404). Set regular_price, sku, stock and the other writable fields as needed. Discover the parent product_id with wc-products/list-products and read existing variations with wc-products/list-product-variations. To bulk-create every missing attribute combination at once, use wc-products/generate-product-variations instead.', 'abilities-catalog-woo' ),
			'category'            => 'wc-products',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'product_id' ),
				'properties'           => array_merge(
					array(
						'product_id' => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'description' => __( 'The parent variable product ID the variation is added to. The product must be type=variable. Discover it with wc-products/list-products.', 'abilities-catalog-woo' ),
						),
					),
					ProductWriteSchema::writableVariationProperties()
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
				'screen'       => 'post.php?post={product_id}&action=edit',
			),
		);
	}

	/**
	 * Permission check: WooCommerce's product edit capability.
	 *
	 * Coarse, type-level, object-independent gate. WooCommerce maps the
	 * `product_variation` post type to the `product` capability type, so variation
	 * writes resolve to the product caps; this uses the coarse `edit_products`
	 * primitive (the shop-manager/administrator product-author cap). The wrapped
	 * route runs the hard object-level check underneath
	 * (`wc_rest_check_post_permissions( 'product_variation', 'create' )`) and, when
	 * attributes are supplied for a missing parent, surfaces the route's specific
	 * `woocommerce_rest_product_variation_invalid_parent` 404 via {@see RestError::from()}
	 * — doing an object-level check here would mask that 404 as a generic permission
	 * denial. The activity guard keeps the denial clean when WooCommerce is off.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may create product variations.
	 */
	public function hasPermission( $input ): bool {
		return WooPlugin::isActive() && current_user_can( 'edit_products' );
	}

	/**
	 * Executes the ability by dispatching the internal `wc/v3` create-variation request.
	 *
	 * The `product_id` route segment is concatenated into the path so it is sent as
	 * a real path segment; the writable variation fields are forwarded from input.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The shaped created variation, or the REST error.
	 */
	public function execute( $input ) {
		if ( ! WooPlugin::isActive() ) {
			return WooPlugin::unavailable();
		}

		$input      = is_array( $input ) ? $input : array();
		$product_id = absint( $input['product_id'] ?? 0 );

		$request = new WP_REST_Request( 'POST', '/wc/v3/products/' . $product_id . '/variations' );

		ProductWriteRequest::fill( $request, $input, true );

		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			return RestError::from( $response );
		}

		$data = rest_get_server()->response_to_data( $response, false );

		return ProductWriteRequest::shapeVariationResult( is_array( $data ) ? $data : array(), $product_id );
	}

	/**
	 * The output schema: the variation summary fields plus the parent `product_id`.
	 *
	 * Built from {@see ProductListShaper::variationItemSchema()} so the shaped row
	 * and its schema cannot drift, with `product_id` added (it is the parent the
	 * variation belongs to, supplied by {@see ProductWriteRequest::shapeVariationResult()}).
	 *
	 * @return array<string,mixed> A JSON-Schema object fragment.
	 */
	private function outputSchema(): array {
		$schema = ProductListShaper::variationItemSchema();

		$schema['properties']['product_id'] = array(
			'type'        => 'integer',
			'description' => __( 'The parent product ID this variation belongs to.', 'abilities-catalog-woo' ),
		);

		return $schema;
	}
}
