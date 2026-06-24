<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogWoo\Abilities\Woo\Products;

use GalatanOvidiu\AbilitiesCatalogWoo\Contracts\ConditionalAbility;
use GalatanOvidiu\AbilitiesCatalogWoo\Support\ProductListShaper;
use GalatanOvidiu\AbilitiesCatalogWoo\Support\RestError;
use GalatanOvidiu\AbilitiesCatalogWoo\Support\WooPlugin;
use WP_REST_Request;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read ability: `wc-products/get-product-variation`.
 *
 * Wraps `GET wc/v3/products/<product_id>/variations/<id>` via `rest_do_request()`
 * and returns one variation as a flat, closed summary row (the
 * {@see ProductListShaper::variationSummary()} fields — price, stock, attribute
 * selections, permalink, edit_link) PLUS the variation `description`, which the
 * list shaper omits. Use this for a single variation's detail after finding its id
 * with `wc-products/list-product-variations`.
 *
 * Only available when WooCommerce is active (it is a {@see ConditionalAbility}).
 *
 * @since 0.1.0
 */
final class GetProductVariation implements ConditionalAbility {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'wc-products/get-product-variation';
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
			'label'               => __( 'Get Product Variation', 'abilities-catalog-woo' ),
			'description'         => __( 'Returns one WooCommerce product variation by its parent product_id and variation id, including its sku, prices, stock status and quantity, the attribute selections that define it (e.g. Size: Large), its permalink, and its description. Both IDs must match: the variation must belong to the given product, or the route returns woocommerce_rest_product_variation_invalid_id (404). Discover both IDs with wc-products/list-product-variations. Use wc-products/get-product for the parent product itself.', 'abilities-catalog-woo' ),
			'category'            => 'wc-products',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'product_id', 'id' ),
				'properties'           => array(
					'product_id' => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'The parent variable product ID. Discover it with wc-products/list-products or wc-products/list-product-variations.', 'abilities-catalog-woo' ),
					),
					'id'         => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'The variation ID, which must belong to product_id. Discover it with wc-products/list-product-variations.', 'abilities-catalog-woo' ),
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
	 * Permission check: WooCommerce's product read capability.
	 *
	 * Mirrors the wrapped route's own check. WooCommerce gates a variation read on
	 * `wc_rest_check_post_permissions( 'product_variation', 'read' )`, which maps
	 * to `read_private_products` (the `product_variation` post type uses the
	 * `product` capability type). This is a coarse, object-independent guard: the
	 * variation/parent existence and the "variation belongs to this product" check
	 * stay in the route, so a missing or mismatched id surfaces the route's
	 * specific `woocommerce_rest_product_variation_invalid_id` 404 via
	 * {@see RestError::from()} rather than collapsing to a generic permission
	 * denial. The activity guard keeps the denial clean when WooCommerce is off.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may read product variations.
	 */
	public function hasPermission( $input ): bool {
		return WooPlugin::isActive() && current_user_can( 'read_private_products' );
	}

	/**
	 * Executes the ability by dispatching the internal `wc/v3` REST request.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The shaped variation row plus description, or the REST error.
	 */
	public function execute( $input ) {
		if ( ! WooPlugin::isActive() ) {
			return WooPlugin::unavailable();
		}

		$input      = is_array( $input ) ? $input : array();
		$product_id = absint( $input['product_id'] ?? 0 );
		$id         = absint( $input['id'] ?? 0 );

		$request  = new WP_REST_Request( 'GET', '/wc/v3/products/' . $product_id . '/variations/' . $id );
		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			return RestError::from( $response );
		}

		$data = rest_get_server()->response_to_data( $response, false );
		$data = is_array( $data ) ? $data : array();

		return array_merge(
			ProductListShaper::variationSummary( $data ),
			array( 'description' => (string) ( $data['description'] ?? '' ) )
		);
	}

	/**
	 * The output schema: the variation summary fields plus `description`.
	 *
	 * Built from {@see ProductListShaper::variationItemSchema()} so the row shape
	 * and its schema cannot drift, with the variation `description` added and the
	 * envelope pinned closed.
	 *
	 * @return array<string,mixed> A JSON-Schema object fragment.
	 */
	private function outputSchema(): array {
		$schema = ProductListShaper::variationItemSchema();

		$schema['properties']['description'] = array(
			'type'        => 'string',
			'description' => __( 'The variation description as HTML, or an empty string when none is set.', 'abilities-catalog-woo' ),
		);

		return $schema;
	}
}
