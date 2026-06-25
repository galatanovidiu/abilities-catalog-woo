<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogWoo\Abilities\Woo\Products;

use GalatanOvidiu\AbilitiesCatalogWoo\Contracts\ConditionalAbility;
use GalatanOvidiu\AbilitiesCatalogWoo\Support\ProductListShaper;
use GalatanOvidiu\AbilitiesCatalogWoo\Support\ProductWriteRequest;
use GalatanOvidiu\AbilitiesCatalogWoo\Support\RestError;
use GalatanOvidiu\AbilitiesCatalogWoo\Support\WooPlugin;
use WP_REST_Request;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Write ability: `og-wc-products/duplicate-product`.
 *
 * Wraps `POST wc/v3/products/<id>/duplicate` via `rest_do_request()`, which copies
 * the source product into a NEW post whose status is `draft` and whose name ends
 * in "(copy)". The route's `duplicate_product` callback runs
 * `WC_Admin_Duplicate_Product::product_duplicate()` and returns the new product's
 * data, which this projects through {@see ProductWriteRequest::shapeResult()} so
 * the result matches the read shape rather than the raw ~120-field product body.
 *
 * It confirms the source through a GET of `wc/v3/products/<id>` first, so a missing
 * source returns the route's specific `woocommerce_rest_product_invalid_id` 404
 * rather than a generic failure (mirrors the CF7 `DuplicateForm` confirm-then-act
 * shape).
 *
 * Only available when WooCommerce is active (it is a {@see ConditionalAbility}).
 *
 * @since 0.1.0
 */
final class DuplicateProduct implements ConditionalAbility {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-wc-products/duplicate-product';
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
			'label'               => __( 'Duplicate Product', 'abilities-catalog-woo' ),
			'description'         => __( 'Duplicates a WooCommerce product by ID and returns the copy as a flat summary (its new id, name, type, status, sku, prices, stock, and edit_link). The copy is created as a DRAFT named "{original name} (copy)", so it is not visible to shoppers until published — edit it and publish with og-wc-products/update-product, and surface edit_link so a human can review it. Discover the source product ID with og-wc-products/list-products. A missing source returns a 404 (woocommerce_rest_product_invalid_id).', 'abilities-catalog-woo' ),
			'category'            => 'og-wc-products',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'id' ),
				'properties'           => array(
					'id' => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'The ID of the product to copy. Discover IDs with og-wc-products/list-products.', 'abilities-catalog-woo' ),
					),
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
	 * A duplicate creates a NEW product, so this requires `publish_products` —
	 * what the wrapped route's `create_item_permissions_check` resolves to via
	 * `wc_rest_check_post_permissions( 'product', 'create' )` (the `create`
	 * context maps to the product post type's `publish_posts` cap,
	 * `publish_products`). Coarse and object-independent; the wrapped route
	 * surfaces the source product's specific 404 so it is not masked as a
	 * permission failure. The explicit activity guard keeps the denial clean when
	 * WooCommerce is inactive and the capability is unmapped.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may create products.
	 */
	public function hasPermission( $input ): bool {
		return WooPlugin::isActive() && current_user_can( 'publish_products' );
	}

	/**
	 * Executes the ability by confirming the source then dispatching the duplicate.
	 *
	 * Reads the source through the GET route first so a missing product returns
	 * the route's specific `woocommerce_rest_product_invalid_id` 404, then
	 * dispatches the duplicate and returns the shaped new draft product.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The shaped new draft product, or the REST error.
	 */
	public function execute( $input ) {
		if ( ! WooPlugin::isActive() ) {
			return WooPlugin::unavailable();
		}

		$input = is_array( $input ) ? $input : array();
		$id    = absint( $input['id'] ?? 0 );

		// Confirm the source through the route so a missing product returns the
		// route's specific 404 (woocommerce_rest_product_invalid_id) instead of a
		// generic failure.
		$source = rest_do_request( new WP_REST_Request( 'GET', '/wc/v3/products/' . $id ) );
		if ( $source->is_error() ) {
			return RestError::from( $source );
		}

		$response = rest_do_request( new WP_REST_Request( 'POST', '/wc/v3/products/' . $id . '/duplicate' ) );
		if ( $response->is_error() ) {
			return RestError::from( $response );
		}

		$data = rest_get_server()->response_to_data( $response, false );

		return ProductWriteRequest::shapeResult( is_array( $data ) ? $data : array() );
	}
}
