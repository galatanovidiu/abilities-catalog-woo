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
 * Read ability: `wc-products/get-product-attribute`.
 *
 * Wraps `GET wc/v3/products/attributes/<id>` via `rest_do_request()` and returns
 * one global product attribute definition as a flat, closed record: its `id`,
 * `name`, `slug`, `type`, `order_by`, and `has_archives`. This is the global
 * attribute taxonomy (e.g. Color, Size), not a product's per-product attribute
 * selection — to read the terms (values) under this attribute, use the
 * attribute-terms list ability. Rows are shaped by
 * {@see ProductTermListShaper::attributeSummary()}; the raw `wc/v3` row also
 * carries `_links` and additional meta that this projection strips.
 *
 * Only available when WooCommerce is active (it is a {@see ConditionalAbility}).
 *
 * @since 0.1.0
 */
final class GetProductAttribute implements ConditionalAbility {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'wc-products/get-product-attribute';
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
			'label'               => __( 'Get Product Attribute', 'abilities-catalog-woo' ),
			'description'         => __( 'Returns one WooCommerce global product attribute by ID: its name, slug (e.g. pa_color), type, default term sort order (order_by), and whether it has public archive pages (has_archives). This reads the global attribute definition, not a product\'s per-product attribute selection. Discover IDs with wc-products/list-product-attributes; read the terms (values) under this attribute with wc-products/list-attribute-terms.', 'abilities-catalog-woo' ),
			'category'            => 'wc-products',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'id' ),
				'properties'           => array(
					'id' => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'The global attribute ID. Discover IDs with wc-products/list-product-attributes.', 'abilities-catalog-woo' ),
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => ProductTermListShaper::attributeItemSchema(),
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
	 * Permission check: WooCommerce's manage capability for product attributes.
	 *
	 * Mirrors the wrapped `wc/v3` route's `wc_rest_check_manager_permissions(
	 * 'attributes', 'read' )`, which resolves to the `manage_product_terms`
	 * capability. This is a coarse, object-INDEPENDENT type-level guard: the
	 * per-object decision is deferred to the wrapped route, so a missing attribute
	 * surfaces its specific `woocommerce_rest_taxonomy_invalid` 404 via
	 * {@see RestError::from()} instead of collapsing to a generic permission
	 * denial. The explicit activity guard keeps the denial clean when WooCommerce
	 * is inactive.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may read product attributes.
	 */
	public function hasPermission( $input ): bool {
		return WooPlugin::isActive() && current_user_can( 'manage_product_terms' );
	}

	/**
	 * Executes the ability by dispatching the internal `wc/v3` REST request.
	 *
	 * The `id` is a route segment, so it is concatenated into the path rather than
	 * set as a query param.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The shaped attribute record, or the REST error
	 *                                        (e.g. `woocommerce_rest_taxonomy_invalid` 404).
	 */
	public function execute( $input ) {
		if ( ! WooPlugin::isActive() ) {
			return WooPlugin::unavailable();
		}

		$input = is_array( $input ) ? $input : array();
		$id    = absint( $input['id'] ?? 0 );

		$request  = new WP_REST_Request( 'GET', '/wc/v3/products/attributes/' . $id );
		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			return RestError::from( $response );
		}

		$data = rest_get_server()->response_to_data( $response, false );
		$data = is_array( $data ) ? $data : array();

		return ProductTermListShaper::attributeSummary( $data );
	}
}
