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
 * Read ability: `og-wc-products/get-product-brand`.
 *
 * Wraps `GET wc/v3/products/brands/<id>` via `rest_do_request()` and returns one
 * product brand as a flat, closed term row — id, name, slug, parent, count, and
 * description — through {@see ProductTermListShaper::termSummary()}. The Brands
 * controller extends the categories controller, so the raw row also carries
 * `display`, `image`, and `menu_order`, which a consumer scanning the taxonomy
 * never needs; this projects only the shared term fields.
 *
 * Only available when WooCommerce is active AND the product Brands feature has
 * registered its `/wc/v3/products/brands` route (it is a
 * {@see ConditionalAbility} gated on {@see WooPlugin::hasBrandsSupport()}). When
 * Brands is absent this ability does not register, so it degrades cleanly rather
 * than denying.
 *
 * @since 0.1.0
 */
final class GetProductBrand implements ConditionalAbility {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-wc-products/get-product-brand';
	}

	/**
	 * {@inheritDoc}
	 */
	public function isAvailable(): bool {
		return WooPlugin::isActive() && WooPlugin::hasBrandsSupport();
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		$item = ProductTermListShaper::termItemSchema();

		$item['required'] = array( 'id' );

		return array(
			'label'               => __( 'Get Product Brand', 'abilities-catalog-woo' ),
			'description'         => __( 'Returns one WooCommerce product brand by ID: its name, slug, parent brand, product count, and description. Product brands are the hierarchical product_brand taxonomy; a parent of 0 means a top-level brand. This ability exists only when the store\'s WooCommerce Brands feature is active. Use og-wc-products/list-product-brands to scan brands and discover IDs; use this for one brand\'s detail.', 'abilities-catalog-woo' ),
			'category'            => 'og-wc-products',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'id' ),
				'properties'           => array(
					'id' => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'The product brand term ID. Discover IDs with og-wc-products/list-product-brands.', 'abilities-catalog-woo' ),
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => $item,
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
	 * Permission check: WooCommerce's read capability for product terms.
	 *
	 * Mirrors `wc_rest_check_product_term_permissions( 'product_brand', 'read' )`,
	 * which resolves to the `product_brand` taxonomy's `manage_terms` capability —
	 * registered as `manage_product_terms` — the baseline the wrapped `wc/v3` GET
	 * route enforces. This is a coarse, object-INDEPENDENT type-level guard: the
	 * per-object decision is deferred to the wrapped route, so a missing brand
	 * surfaces its specific `woocommerce_rest_term_invalid` 404 via
	 * {@see RestError::from()} instead of collapsing to a generic permission
	 * denial. The brands-support gate lives in {@see self::isAvailable()}, not
	 * here, so this stays a plain capability check; the explicit activity guard
	 * keeps the denial clean when WooCommerce is inactive.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may read product terms.
	 */
	public function hasPermission( $input ): bool {
		return WooPlugin::isActive() && current_user_can( 'manage_product_terms' );
	}

	/**
	 * Executes the ability by dispatching the internal `wc/v3` REST request.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The shaped brand term row, or the REST error
	 *                                        (e.g. `woocommerce_rest_term_invalid` 404).
	 */
	public function execute( $input ) {
		if ( ! WooPlugin::isActive() ) {
			return WooPlugin::unavailable();
		}

		$input = is_array( $input ) ? $input : array();
		$id    = absint( $input['id'] ?? 0 );

		$request  = new WP_REST_Request( 'GET', '/wc/v3/products/brands/' . $id );
		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			return RestError::from( $response );
		}

		$data = rest_get_server()->response_to_data( $response, false );
		$data = is_array( $data ) ? $data : array();

		return ProductTermListShaper::termSummary( $data );
	}
}
