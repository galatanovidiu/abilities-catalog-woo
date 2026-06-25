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
 * Write ability: `og-wc-products/update-product-variation`.
 *
 * Wraps `PUT wc/v3/products/<product_id>/variations/<id>` via `rest_do_request()`,
 * editing a single variation of a variable product and returning the shaped
 * updated variation (the {@see ProductListShaper::variationSummary()} fields plus
 * the parent `product_id`). Send only the fields you want to change; an omitted
 * field is left untouched.
 *
 * The variation must belong to the given `product_id`, or the route returns
 * `woocommerce_rest_product_variation_invalid_id` (404). That existence/parent
 * check stays in the wrapped route, so a missing or mismatched id surfaces the
 * route's specific 404 via {@see RestError::from()} rather than collapsing to a
 * generic permission denial.
 *
 * Only available when WooCommerce is active (it is a {@see ConditionalAbility}).
 *
 * @since 0.1.0
 */
final class UpdateProductVariation implements ConditionalAbility {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-wc-products/update-product-variation';
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
			'label'               => __( 'Update Product Variation', 'abilities-catalog-woo' ),
			'description'         => __( 'Updates a single WooCommerce product variation by its parent product_id and variation id, returning the shaped updated variation (id, sku, prices, stock status and quantity, the attribute selections, permalink, edit_link, and product_id). Send only the fields you want to change; omitted fields are left untouched. The variation must belong to product_id, or the route returns woocommerce_rest_product_variation_invalid_id (404). Discover both IDs with og-wc-products/list-product-variations. To create a new variation instead use og-wc-products/create-product-variation; to edit the parent product use og-wc-products/update-product.', 'abilities-catalog-woo' ),
			'category'            => 'og-wc-products',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'product_id', 'id' ),
				'properties'           => array_merge(
					array(
						'product_id' => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'description' => __( 'The parent variable product ID. Discover it with og-wc-products/list-product-variations or og-wc-products/list-products.', 'abilities-catalog-woo' ),
						),
						'id'         => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'description' => __( 'The variation ID to update, which must belong to product_id. Discover it with og-wc-products/list-product-variations.', 'abilities-catalog-woo' ),
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
	 * Permission check: WooCommerce's coarse product edit capability.
	 *
	 * Encodes the catalog capability for this write: the type-level
	 * `edit_products` primitive. The wrapped route's `update_item_permissions_check`
	 * re-checks the object-level `edit_product` meta-cap against the variation id
	 * (`wc_rest_check_post_permissions( 'product_variation', 'edit', $id )`, the
	 * `product_variation` post type mapping to the `product` capabilities). Keeping
	 * this guard coarse and object-independent lets the route surface the specific
	 * `woocommerce_rest_product_variation_invalid_id` 404 (or a
	 * `woocommerce_rest_cannot_edit` 403) for a missing/mismatched id rather than
	 * masking it as a generic permission denial. The activity guard keeps the
	 * denial clean when WooCommerce is off.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may edit products.
	 */
	public function hasPermission( $input ): bool {
		return WooPlugin::isActive() && current_user_can( 'edit_products' );
	}

	/**
	 * Executes the ability by dispatching the internal `wc/v3` REST update request.
	 *
	 * The route segment ids are concatenated onto the URL (never `set_param`), and
	 * the writable variation fields present in the input are forwarded by
	 * {@see ProductWriteRequest::fill()}. A missing or mismatched variation 404s in
	 * the wrapped route.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The shaped updated variation, or the REST error.
	 */
	public function execute( $input ) {
		if ( ! WooPlugin::isActive() ) {
			return WooPlugin::unavailable();
		}

		$input      = is_array( $input ) ? $input : array();
		$product_id = absint( $input['product_id'] ?? 0 );
		$id         = absint( $input['id'] ?? 0 );

		$request = new WP_REST_Request( 'PUT', '/wc/v3/products/' . $product_id . '/variations/' . $id );

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
	 * Built from {@see ProductListShaper::variationItemSchema()} so the row shape
	 * and its schema cannot drift, with the `product_id`
	 * {@see ProductWriteRequest::shapeVariationResult()} adds appended and the
	 * envelope pinned closed.
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
