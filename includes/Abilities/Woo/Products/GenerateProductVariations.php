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
 * Write ability: `og-wc-products/generate-product-variations`.
 *
 * Wraps `POST wc/v3/products/<product_id>/variations/generate` (callback
 * `generate`) via `rest_do_request()`. WooCommerce computes the cartesian product
 * of the parent variable product's variation attributes and bulk-creates one new
 * variation for every combination that does not yet have a variation, up to
 * `WC_MAX_LINKED_VARIATIONS` (99). It is the bulk equivalent of calling
 * `og-wc-products/create-product-variation` once per missing combination.
 *
 * `product_id` is a required route segment, so the request is built by string
 * concatenation rather than `set_param()`.
 *
 * The `generate` route returns only a `count` of created variations, so this
 * ability shapes the result by reading the parent's variations back through the
 * `wc/v3` variations list route after generating, projecting each row through
 * {@see ProductListShaper::variationSummary()}. `created_count` is the route's own
 * `count`. The destructive `delete` option (which force-deletes stale variations)
 * is deliberately NOT exposed, keeping this a safe, non-destructive write.
 *
 * Only available when WooCommerce is active (it is a {@see ConditionalAbility}).
 *
 * @since 0.1.0
 */
final class GenerateProductVariations implements ConditionalAbility {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-wc-products/generate-product-variations';
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
			'label'               => __( 'Generate Product Variations', 'abilities-catalog-woo' ),
			'description'         => __( 'Bulk-creates variations for one variable WooCommerce product, filling in every missing combination of the parent\'s variation attributes (the cartesian product). For a parent with attributes Size {S, M} and Color {Red, Blue}, it creates the missing S/Red, S/Blue, M/Red, M/Blue variations. Blast radius: it creates up to 99 new variations (WC_MAX_LINKED_VARIATIONS) in a single call, one per missing combination; existing variations are left untouched and none are deleted. Returns the parent product_id, the resulting variations as flat summary rows (items), and created_count (how many new variations this call made). The parent must be a variable product with its variation attributes already set; discover product_id with og-wc-products/list-products. Use og-wc-products/create-product-variation to add a single specific variation instead. Edit the generated variations afterward with og-wc-products/update-product-variation.', 'abilities-catalog-woo' ),
			'category'            => 'og-wc-products',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'product_id' ),
				'properties'           => array(
					'product_id' => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'The parent variable product ID whose missing attribute combinations to fill in. Discover it with og-wc-products/list-products (the parent must be a variable product with its variation attributes set).', 'abilities-catalog-woo' ),
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'product_id', 'items', 'created_count' ),
				'properties'           => array(
					'product_id'    => array(
						'type'        => 'integer',
						'description' => __( 'The parent variable product ID the variations belong to.', 'abilities-catalog-woo' ),
					),
					'items'         => array(
						'type'        => 'array',
						'description' => __( 'The parent\'s variations after generation, as flat summary rows (both pre-existing and newly created). Use og-wc-products/get-product-variation for a single variation\'s full detail.', 'abilities-catalog-woo' ),
						'items'       => ProductListShaper::variationItemSchema(),
					),
					'created_count' => array(
						'type'        => 'integer',
						'description' => __( 'How many NEW variations this call created (0 when every attribute combination already had a variation).', 'abilities-catalog-woo' ),
					),
				),
				'additionalProperties' => false,
			),
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
	 * Permission check: WooCommerce's coarse edit capability for products.
	 *
	 * Encodes the catalog's coarse, object-independent gate `edit_products` for
	 * the variation writes (variations share the parent product caps). The wrapped
	 * `generate` route runs `create_item_permissions_check` underneath, which
	 * resolves the route's own object-level guard; doing the object-level check
	 * here would mask a missing or non-variable parent as a generic permission
	 * denial instead of the route's `woocommerce_rest_product_invalid_id` 404.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may edit products.
	 */
	public function hasPermission( $input ): bool {
		return WooPlugin::isActive() && current_user_can( 'edit_products' );
	}

	/**
	 * Executes the ability by dispatching the internal WooCommerce generate request.
	 *
	 * Dispatches the `generate` route (which returns only a created `count`), then
	 * reads the parent's variations back through the list route to shape `items`.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The generation result, or the REST error.
	 */
	public function execute( $input ) {
		if ( ! WooPlugin::isActive() ) {
			return WooPlugin::unavailable();
		}

		$input      = is_array( $input ) ? $input : array();
		$product_id = absint( $input['product_id'] ?? 0 );

		// product_id is a path segment, not a query param, so concatenate the route.
		$request  = new WP_REST_Request( 'POST', '/wc/v3/products/' . $product_id . '/variations/generate' );
		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			return RestError::from( $response );
		}

		$result        = rest_get_server()->response_to_data( $response, false );
		$created_count = is_array( $result ) ? (int) ( $result['count'] ?? 0 ) : 0;

		// The generate route returns only the created count, so read the resulting
		// variations back to shape `items`.
		$list = rest_do_request( new WP_REST_Request( 'GET', '/wc/v3/products/' . $product_id . '/variations' ) );
		if ( $list->is_error() ) {
			return RestError::from( $list );
		}

		$rows = array();
		$data = rest_get_server()->response_to_data( $list, false );
		foreach ( is_array( $data ) ? $data : array() as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$rows[] = ProductListShaper::variationSummary( $item );
		}

		return array(
			'product_id'    => $product_id,
			'items'         => $rows,
			'created_count' => $created_count,
		);
	}
}
