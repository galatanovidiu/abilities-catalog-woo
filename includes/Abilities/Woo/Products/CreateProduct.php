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
 * Write ability: `wc-products/create-product`.
 *
 * Wraps `POST wc/v3/products` via `rest_do_request()`, creating a new WooCommerce
 * product. Only `name` is required; every other field is optional and falls back
 * to WooCommerce's defaults (type `simple`, status `publish`). The result is the
 * flat, closed {@see ProductListShaper::summary()} record of the created product
 * — its `id`, `name`, `type`, `status`, `sku`, the price set, stock, visibility,
 * `permalink`, and an `edit_link` — not the raw ~120-field product body.
 *
 * To change an existing product use `wc-products/update-product`; to copy one use
 * `wc-products/duplicate-product`. To add variations, create the product with
 * `type` = `variable`, then add variations with
 * `wc-products/create-product-variation`.
 *
 * Only available when WooCommerce is active (it is a {@see ConditionalAbility}).
 *
 * @since 0.1.0
 */
final class CreateProduct implements ConditionalAbility {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'wc-products/create-product';
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
			'label'               => __( 'Create Product', 'abilities-catalog-woo' ),
			'description'         => __( 'Creates a new WooCommerce product and returns its id, name, type, status, sku, prices, stock, permalink, and edit_link. Only name is required; type defaults to simple and status to publish, so omit status (or set draft) to keep the product hidden while you finish it. Set type to variable to add variations afterward with wc-products/create-product-variation. To change an existing product use wc-products/update-product; to copy one use wc-products/duplicate-product. A duplicate sku is rejected by the route with a 400. After creating, surface edit_link so a human can review the product.', 'abilities-catalog-woo' ),
			'category'            => 'wc-products',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'name' ),
				'properties'           => array_merge(
					array(
						'name' => array(
							'type'        => 'string',
							'description' => __( 'The product name shown to shoppers. Required.', 'abilities-catalog-woo' ),
						),
					),
					ProductWriteSchema::writableProperties()
				),
				'additionalProperties' => false,
			),
			'output_schema'       => ProductListShaper::itemSchema(),
			'execute_callback'    => array( $this, 'execute' ),
			'permission_callback' => array( $this, 'hasPermission' ),
			'meta'                => array(
				'annotations'  => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => false,
				),
				'show_in_rest' => true,
				'screen'       => 'post.php?post={id}&action=edit',
			),
		);
	}

	/**
	 * Permission check: WooCommerce's create capability for products.
	 *
	 * Mirrors `wc_rest_check_post_permissions( 'product', 'create' )`, which maps
	 * the product post type's `publish_posts` meta-cap to `publish_products` — the
	 * baseline the wrapped `wc/v3` create route enforces (with no object id). This
	 * is a coarse, object-INDEPENDENT type-level guard; the wrapped route surfaces
	 * the schema's own 400 (e.g. a duplicate `sku`) via {@see RestError::from()}
	 * instead of collapsing it to a generic permission denial. The explicit
	 * activity guard keeps the denial clean when WooCommerce is inactive.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may create products.
	 */
	public function hasPermission( $input ): bool {
		return WooPlugin::isActive() && current_user_can( 'publish_products' );
	}

	/**
	 * Executes the ability by dispatching the internal `wc/v3` REST create request.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The shaped created product, or the REST error
	 *                                        (e.g. `product_invalid_sku` 400).
	 */
	public function execute( $input ) {
		if ( ! WooPlugin::isActive() ) {
			return WooPlugin::unavailable();
		}

		$input = is_array( $input ) ? $input : array();

		$request = new WP_REST_Request( 'POST', '/wc/v3/products' );
		$request->set_param( 'name', (string) ( $input['name'] ?? '' ) );

		ProductWriteRequest::fill( $request, $input, false );

		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			return RestError::from( $response );
		}

		$data = rest_get_server()->response_to_data( $response, false );

		return ProductWriteRequest::shapeResult( is_array( $data ) ? $data : array() );
	}
}
