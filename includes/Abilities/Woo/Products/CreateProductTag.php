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
 * Write ability: `wc-products/create-product-tag`.
 *
 * Wraps `POST wc/v3/products/tags` via `rest_do_request()`, creating one product
 * tag and returning it as a flat, closed term row through
 * {@see ProductTermListShaper::termSummary()} — id, name, slug, parent (always 0,
 * tags are a flat taxonomy), count, and description. Never returns the raw `wc/v3`
 * tag body.
 *
 * Reversible: a tag created here is editable with `wc-products/update-product-tag`
 * and removable with the corresponding delete ability. The change touches only the
 * `product_tag` catalog taxonomy — it does not affect orders, money, or products
 * already published.
 *
 * Only available when WooCommerce is active (it is a {@see ConditionalAbility}).
 *
 * @since 0.1.0
 */
final class CreateProductTag implements ConditionalAbility {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'wc-products/create-product-tag';
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
			'label'               => __( 'Create Product Tag', 'abilities-catalog-woo' ),
			'description'         => __( 'Creates one WooCommerce product tag and returns its id, name, slug, parent (always 0 — product tags are a flat, non-hierarchical taxonomy), product count, and description. Only name is required; an omitted slug is derived from the name. Reusing an existing tag slug fails with a "term_exists" 400 error carrying the existing tag id in resource_id, so branch on that to reuse instead of recreate. Reversible: edit it later with wc-products/update-product-tag. This edits only the product_tag catalog taxonomy and does not affect orders or products. To list or discover tags use wc-products/list-product-tags; for categories use wc-products/create-product-category instead.', 'abilities-catalog-woo' ),
			'category'            => 'wc-products',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'name' ),
				'properties'           => array(
					'name'        => array(
						'type'        => 'string',
						'description' => __( 'The tag name shown to shoppers, e.g. "Sale". Required.', 'abilities-catalog-woo' ),
					),
					'slug'        => array(
						'type'        => 'string',
						'description' => __( 'The URL slug for the tag. Omit to let WooCommerce derive it from the name. Reusing an existing tag slug returns a "term_exists" 400 error with the existing tag id in resource_id.', 'abilities-catalog-woo' ),
					),
					'description' => array(
						'type'        => 'string',
						'description' => __( 'An optional description for the tag (HTML allowed; sanitized by WooCommerce).', 'abilities-catalog-woo' ),
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => ProductTermListShaper::termItemSchema(),
			'execute_callback'    => array( $this, 'execute' ),
			'permission_callback' => array( $this, 'hasPermission' ),
			'meta'                => array(
				'annotations'  => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => false,
				),
				'show_in_rest' => true,
				'screen'       => 'term.php?taxonomy=product_tag&tag_ID={id}&post_type=product',
			),
		);
	}

	/**
	 * Permission check: WooCommerce's edit capability for product terms.
	 *
	 * Encodes the catalog capability for `wc-products/create-product-tag`: the
	 * `edit_product_terms` capability, which is what
	 * `wc_rest_check_product_term_permissions( 'product_tag', 'create' )` resolves
	 * to on the wrapped `POST wc/v3/products/tags` route (the create context maps
	 * to `edit_terms`, registered as `edit_product_terms` for the `product_tag`
	 * taxonomy). This is a coarse, object-INDEPENDENT guard; the wrapped route
	 * applies any finer checks and surfaces the specific `term_exists` 400 for a
	 * duplicate slug via {@see RestError::from()} rather than collapsing it to a
	 * permission denial. The explicit activity guard keeps the denial clean when
	 * WooCommerce is inactive and the capability is unmapped.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may create product tags.
	 */
	public function hasPermission( $input ): bool {
		return WooPlugin::isActive() && current_user_can( 'edit_product_terms' );
	}

	/**
	 * Executes the ability by dispatching the internal `wc/v3` REST create request.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The shaped product tag row, or the REST
	 *                                        error (e.g. `term_exists` 400 for a
	 *                                        duplicate slug).
	 */
	public function execute( $input ) {
		if ( ! WooPlugin::isActive() ) {
			return WooPlugin::unavailable();
		}

		$input = is_array( $input ) ? $input : array();

		$request = new WP_REST_Request( 'POST', '/wc/v3/products/tags' );

		$request->set_param( 'name', (string) ( $input['name'] ?? '' ) );
		if ( isset( $input['slug'] ) && '' !== $input['slug'] ) {
			$request->set_param( 'slug', (string) $input['slug'] );
		}
		if ( isset( $input['description'] ) && '' !== $input['description'] ) {
			$request->set_param( 'description', (string) $input['description'] );
		}

		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			return RestError::from( $response );
		}

		$data = rest_get_server()->response_to_data( $response, false );
		$data = is_array( $data ) ? $data : array();

		return ProductTermListShaper::termSummary( $data );
	}
}
