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
 * Read ability: `og-wc-products/list-product-attributes`.
 *
 * Wraps `GET wc/v3/products/attributes` via `rest_do_request()` and returns each
 * global product attribute definition as a flat summary row through
 * {@see ProductTermListShaper::attributeSummary()}, so a consumer scans the store's
 * attributes (Color, Size, …) without the raw attribute body. Use the returned `id`
 * with og-wc-products/list-attribute-terms to read an attribute's terms.
 *
 * The wrapped route accepts no collection params except `context`: it always returns
 * every global attribute, so there is no search or paging to expose — the input is
 * the no-input schema. WooCommerce sets `X-WP-Total` to the row count on this route,
 * so `total` and the number of returned rows always coincide here.
 *
 * Only available when WooCommerce is active (it is a {@see ConditionalAbility}).
 *
 * @since 0.1.0
 */
final class ListProductAttributes implements ConditionalAbility {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-wc-products/list-product-attributes';
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
			'label'               => __( 'List Product Attributes', 'abilities-catalog-woo' ),
			'description'         => __( 'Returns the store\'s global WooCommerce product attributes (e.g. Color, Size) as flat summary rows, each with its id, name, slug, type, order_by, and has_archives. Always returns every global attribute: this route takes no search or paging filters. Use a returned id with og-wc-products/list-attribute-terms to read that attribute\'s terms, or og-wc-products/get-product-attribute for one attribute. Read-only: lists attribute definitions, not the terms under them and not per-product attribute selections.', 'abilities-catalog-woo' ),
			'category'            => 'og-wc-products',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => (object) array(),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'items', 'total' ),
				'properties'           => array(
					'items' => array(
						'type'        => 'array',
						'description' => __( 'The global product attributes as flat summary rows. Use og-wc-products/list-attribute-terms with a row\'s id to read its terms.', 'abilities-catalog-woo' ),
						'items'       => ProductTermListShaper::attributeItemSchema(),
					),
					'total' => array(
						'type'        => 'integer',
						'description' => __( 'The number of global attributes returned, read from the X-WP-Total response header. This route is unpaged and returns every attribute, so total always equals the number of rows in items.', 'abilities-catalog-woo' ),
					),
				),
				'additionalProperties' => false,
			),
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
	 * Encodes the catalog baseline for `og-wc-products/list-product-attributes`: the
	 * `manage_product_terms` capability, which is what
	 * `wc_rest_check_manager_permissions( 'attributes', 'read' )` resolves to on the
	 * wrapped `GET wc/v3/products/attributes` route. This is a coarse, object-
	 * independent guard that is not weaker than the route's own check. The explicit
	 * activity guard keeps the denial clean when WooCommerce is inactive and the
	 * capability is unmapped.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may read the store's product attributes.
	 */
	public function hasPermission( $input ): bool {
		return WooPlugin::isActive() && current_user_can( 'manage_product_terms' );
	}

	/**
	 * Executes the ability by dispatching the internal WooCommerce REST request.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The list of product attributes, or the REST error.
	 */
	public function execute( $input ) {
		if ( ! WooPlugin::isActive() ) {
			return WooPlugin::unavailable();
		}

		$input = is_array( $input ) ? $input : array();

		$request  = new WP_REST_Request( 'GET', '/wc/v3/products/attributes' );
		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			return RestError::from( $response );
		}

		$data = rest_get_server()->response_to_data( $response, false );

		$rows = array();
		foreach ( is_array( $data ) ? $data : array() as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$rows[] = ProductTermListShaper::attributeSummary( $item );
		}

		$headers = $response->get_headers();
		$total   = isset( $headers['X-WP-Total'] ) ? (int) $headers['X-WP-Total'] : count( $rows );

		return array(
			'items' => $rows,
			'total' => $total,
		);
	}
}
