<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogWoo\Abilities\Woo\Products;

use GalatanOvidiu\AbilitiesCatalogWoo\Contracts\ConditionalAbility;
use GalatanOvidiu\AbilitiesCatalogWoo\Support\RestError;
use GalatanOvidiu\AbilitiesCatalogWoo\Support\WooPlugin;
use WP_REST_Request;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Destructive write ability: `og-wc-products/delete-product-category`.
 *
 * Wraps `DELETE wc/v3/products/categories/<id>` via `rest_do_request()`, permanently
 * deleting a term from the hierarchical `product_cat` taxonomy. The shared
 * WooCommerce terms controller does NOT support trashing for taxonomies — its
 * `delete_item()` returns `woocommerce_rest_trash_not_supported` 501 unless `force`
 * is truthy — so this ability hard-sets `force=true` and exposes no `force` input.
 * The delete is therefore permanent and irreversible: there is no Trash and no
 * recoverable state for a product category.
 *
 * Before deleting, this reads the category's `name` (via a GET) so the result can
 * confirm what was removed, and so a missing category surfaces the route's specific
 * `woocommerce_rest_term_invalid` 404 here rather than collapsing into a later
 * permission denial.
 *
 * Two preconditions the description states loudly:
 * - The store's DEFAULT product category cannot be deleted. The controller guards
 *   the id against `get_option( 'default_product_cat' )` and returns
 *   `woocommerce_rest_cannot_delete` 500 for it.
 * - Deleting a non-default category does NOT delete its products; WooCommerce
 *   reassigns those products to the default product category.
 *
 * The WC delete route returns the prepared term snapshot (not a `{deleted:true}`
 * body), so `deleted` is derived from a non-error response. The result is a tiny
 * fixed shape — `{ deleted, id, name, force_used, permanent }` — with no
 * `edit_link`, because the category no longer exists.
 *
 * Only available when WooCommerce is active (it is a {@see ConditionalAbility}).
 *
 * @since 0.1.0
 */
final class DeleteProductCategory implements ConditionalAbility {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-wc-products/delete-product-category';
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
			'label'               => __( 'Delete Product Category', 'abilities-catalog-woo' ),
			'description'         => __( 'Permanently deletes a WooCommerce product category (a term in the hierarchical product_cat taxonomy) by id, and returns a confirmation with the deleted category name. This cannot be undone: product categories have no Trash, so the delete is permanent and irreversible (the tool always force-deletes; there is no recoverable state). Deleting a category does NOT delete its products — WooCommerce reassigns those products to the store default product category. The store DEFAULT product category cannot be deleted: attempting it returns a woocommerce_rest_cannot_delete 500 error. A missing id returns a woocommerce_rest_term_invalid 404 error. Discover ids with og-wc-products/list-product-categories. The result returns the deleted category id and name (no edit_link, because the category no longer exists).', 'abilities-catalog-woo' ),
			'category'            => 'og-wc-products',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'id' ),
				'properties'           => array(
					'id' => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'The product category term id to permanently delete. Discover ids with og-wc-products/list-product-categories. The store default product category cannot be deleted (the route returns woocommerce_rest_cannot_delete 500).', 'abilities-catalog-woo' ),
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'deleted', 'id', 'name', 'force_used', 'permanent' ),
				'properties'           => array(
					'deleted'    => array(
						'type'        => 'boolean',
						'description' => __( 'Whether the category was permanently deleted.', 'abilities-catalog-woo' ),
					),
					'id'         => array(
						'type'        => 'integer',
						'description' => __( 'The deleted category term id.', 'abilities-catalog-woo' ),
					),
					'name'       => array(
						'type'        => 'string',
						'description' => __( 'The name of the deleted category, captured before deletion so a human can confirm what was removed. No edit_link is returned because the category no longer exists.', 'abilities-catalog-woo' ),
					),
					'force_used' => array(
						'type'        => 'boolean',
						'description' => __( 'Always true: product categories have no Trash, so the delete always force-deletes permanently.', 'abilities-catalog-woo' ),
					),
					'permanent'  => array(
						'type'        => 'boolean',
						'description' => __( 'Always true: the delete is permanent and irreversible, with no Trash and no restore path.', 'abilities-catalog-woo' ),
					),
				),
				'additionalProperties' => false,
			),
			'execute_callback'    => array( $this, 'execute' ),
			'permission_callback' => array( $this, 'hasPermission' ),
			'meta'                => array(
				'annotations'  => array(
					'readonly'    => false,
					'destructive' => true,
					'idempotent'  => false,
				),
				'show_in_rest' => true,
				'screen'       => 'edit-tags.php?taxonomy=product_cat&post_type=product',
			),
		);
	}

	/**
	 * Permission check: WooCommerce's delete capability for product terms.
	 *
	 * Encodes the catalog capability for `og-wc-products/delete-product-category`: the
	 * `delete_product_terms` capability, which is what
	 * `wc_rest_check_product_term_permissions( 'product_cat', 'delete' )` resolves to
	 * on the wrapped `DELETE wc/v3/products/categories/<id>` route (it maps the
	 * `delete` context to the taxonomy's `delete_terms` cap, registered as
	 * `delete_product_terms` for `product_cat`). Coarse and object-independent: the
	 * wrapped route surfaces the specific object-level errors (the
	 * `woocommerce_rest_term_invalid` 404 for a missing category, the
	 * `woocommerce_rest_cannot_delete` 500 for the default category) via
	 * {@see RestError::from()} instead of collapsing them into a permission denial.
	 * The explicit activity guard keeps the denial clean when WooCommerce is inactive
	 * and the cap is unmapped.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may delete product categories.
	 */
	public function hasPermission( $input ): bool {
		return WooPlugin::isActive() && current_user_can( 'delete_product_terms' );
	}

	/**
	 * Executes the ability by dispatching the internal `wc/v3` REST delete request.
	 *
	 * Reads the category name first (a missing category 404s here), then deletes with
	 * `force=true` (the only value the terms route accepts — `force=false` returns
	 * `woocommerce_rest_trash_not_supported` 501, since product categories have no
	 * Trash). The route returns the prepared term snapshot, not a `{deleted:true}`
	 * body, so `deleted` is derived from a non-error response.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The deleted flag, id, name, force_used, and
	 *                                        permanent, or the REST error (e.g.
	 *                                        `woocommerce_rest_term_invalid` 404 for a
	 *                                        missing category, `woocommerce_rest_cannot_delete`
	 *                                        500 for the default category).
	 */
	public function execute( $input ) {
		if ( ! WooPlugin::isActive() ) {
			return WooPlugin::unavailable();
		}

		$input = is_array( $input ) ? $input : array();
		$id    = absint( $input['id'] ?? 0 );

		// Capture the name before the term is gone; a missing category 404s here.
		$before = rest_do_request( new WP_REST_Request( 'GET', '/wc/v3/products/categories/' . $id ) );
		if ( $before->is_error() ) {
			return RestError::from( $before );
		}

		$before_data = rest_get_server()->response_to_data( $before, false );
		$name        = is_array( $before_data ) ? (string) ( $before_data['name'] ?? '' ) : '';

		// Product categories have no Trash; the route 501s on force=false, so force is always true.
		$request = new WP_REST_Request( 'DELETE', '/wc/v3/products/categories/' . $id );
		$request->set_param( 'force', true );

		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			return RestError::from( $response );
		}

		return array(
			'deleted'    => true,
			'id'         => $id,
			'name'       => $name,
			'force_used' => true,
			'permanent'  => true,
		);
	}
}
