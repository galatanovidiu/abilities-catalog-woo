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
 * Write ability: `wc-products/update-product`.
 *
 * Wraps `PUT wc/v3/products/<id>` via `rest_do_request()`, changing an existing
 * product's writable fields and returning the shaped updated product
 * ({@see ProductListShaper::summary()}: name, type, status, sku, prices, stock,
 * visibility, permalink, edit_link) plus a `previous_status` snapshot.
 *
 * The id is set as a path segment by concatenation (never `set_param`), and the
 * writable fields are forwarded by {@see ProductWriteRequest::fill()} on key
 * presence, so an update changes only what the caller sent.
 *
 * Capture-before-write: this first dispatches a `GET wc/v3/products/<id>` to read
 * the product's current status, returned as `previous_status` so a human can see
 * whether a live product was taken offline. That read also makes a missing
 * product surface the GET route's specific `woocommerce_rest_product_invalid_id`
 * 404 (the PUT route would return the same code with a 400 status), mirroring the
 * `previous_*` capture pattern of CF7's `UpdateForm`.
 *
 * Only available when WooCommerce is active (it is a {@see ConditionalAbility}).
 *
 * @since 0.1.0
 */
final class UpdateProduct implements ConditionalAbility {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'wc-products/update-product';
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
			'label'               => __( 'Update Product', 'abilities-catalog-woo' ),
			'description'         => __( 'Updates an existing WooCommerce product by ID and returns the shaped product (name, type, status, sku, prices, stock, permalink, edit_link) plus previous_status, the status before the change. Send only the fields you want to change; omitted fields are left untouched. Discover IDs with wc-products/list-products. Use wc-products/create-product to make a new product instead, or wc-products/duplicate-product to clone one. Note: changing status to draft or private takes a live product offline; the categories, tags, and images arrays REPLACE the current sets rather than appending. Surface edit_link so a human can review the change.', 'abilities-catalog-woo' ),
			'category'            => 'wc-products',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'id' ),
				'properties'           => array_merge(
					array(
						'id' => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'description' => __( 'The ID of the product to update. Discover IDs with wc-products/list-products.', 'abilities-catalog-woo' ),
						),
					),
					ProductWriteSchema::writableProperties()
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
				'screen'       => 'post.php?post={id}&action=edit',
			),
		);
	}

	/**
	 * Permission check: WooCommerce's edit capability for products.
	 *
	 * Encodes the catalog capability for `wc-products/update-product`: the
	 * coarse, type-level `edit_products` primitive. The wrapped `wc/v3` PUT route
	 * runs `wc_rest_check_post_permissions( 'product', 'edit', $id )`, which
	 * resolves the object-level `edit_product` meta-cap against the target id, so
	 * the object-level decision is deferred to the route — doing it here would
	 * mask a missing id as a permission denial instead of the route's specific
	 * `woocommerce_rest_product_invalid_id` error. The explicit activity guard
	 * keeps the denial clean when WooCommerce is inactive.
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
	 * Reads the product's current status first (so a missing product 404s here),
	 * then dispatches the PUT and returns the shaped result with the captured
	 * `previous_status`.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The shaped updated product, or the REST error
	 *                                        (e.g. `woocommerce_rest_product_invalid_id`).
	 */
	public function execute( $input ) {
		if ( ! WooPlugin::isActive() ) {
			return WooPlugin::unavailable();
		}

		$input = is_array( $input ) ? $input : array();
		$id    = absint( $input['id'] ?? 0 );

		// Snapshot the status before the edit. A missing product 404s here.
		$before = rest_do_request( new WP_REST_Request( 'GET', '/wc/v3/products/' . $id ) );
		if ( $before->is_error() ) {
			return RestError::from( $before );
		}

		$before_data     = rest_get_server()->response_to_data( $before, false );
		$previous_status = is_array( $before_data ) ? (string) ( $before_data['status'] ?? '' ) : '';

		$request = new WP_REST_Request( 'PUT', '/wc/v3/products/' . $id );
		ProductWriteRequest::fill( $request, $input, false );

		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			return RestError::from( $response );
		}

		$data   = rest_get_server()->response_to_data( $response, false );
		$result = ProductWriteRequest::shapeResult( is_array( $data ) ? $data : array() );

		$result['previous_status'] = $previous_status;

		return $result;
	}

	/**
	 * Builds the closed output schema: the shaped product summary plus
	 * `previous_status`.
	 *
	 * @return array<string,mixed> A JSON-Schema object fragment.
	 */
	private function outputSchema(): array {
		$schema = ProductListShaper::itemSchema();

		$schema['required']                      = array( 'id', 'status' );
		$schema['properties']['previous_status'] = array(
			'type'        => 'string',
			'description' => __( 'The product status before this update (e.g. publish, draft). Compare it to status to tell whether a live product was taken offline or a draft was published.', 'abilities-catalog-woo' ),
		);

		return $schema;
	}
}
