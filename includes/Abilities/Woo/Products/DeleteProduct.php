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
 * Destructive write ability: `og-wc-products/delete-product`.
 *
 * Wraps `DELETE wc/v3/products/<id>` via `rest_do_request()`, deleting a single
 * WooCommerce product. The product is Trash-capable: with `force` omitted or
 * false the product is moved to the Trash (recoverable); with `force` true it is
 * permanently deleted (irreversible). Before deleting, this reads the product's
 * `name` so the result can confirm what was removed, and so a missing product
 * returns the route's `woocommerce_rest_product_invalid_id` 404 here — not a
 * later permission collapse.
 *
 * Two preconditions follow WooCommerce's delete semantics. (1) Trash support is
 * gated on `EMPTY_TRASH_DAYS`: on a store where Trash is disabled
 * (`EMPTY_TRASH_DAYS == 0`) the route rejects a `force=false` call with
 * `woocommerce_rest_trash_not_supported` 501, so the caller must pass
 * `force=true` there. (2) Deleting an already-trashed product with `force=false`
 * returns `woocommerce_rest_already_trashed` 410. A `force=true` delete of a
 * variable product also permanently deletes all of its variations.
 *
 * The result is the tiny fixed shape `{ deleted, id, name, force_used,
 * permanent }`. No `edit_link` is returned — the product is gone (or trashed and
 * not editable), so an edit link would be a dead end.
 *
 * Only available when WooCommerce is active (it is a {@see ConditionalAbility}).
 *
 * @since 0.1.0
 */
final class DeleteProduct implements ConditionalAbility {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-wc-products/delete-product';
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
			'label'               => __( 'Delete Product', 'abilities-catalog-woo' ),
			'description'         => __( 'Deletes a WooCommerce product by id and returns a confirmation { deleted, id, name, force_used, permanent }. By default (force false) the product is moved to the Trash and can be restored; set force true to permanently delete it, which cannot be undone and also permanently deletes the variations of a variable product. If the store has the Trash disabled (EMPTY_TRASH_DAYS is 0) a force=false call is rejected with woocommerce_rest_trash_not_supported 501, so pass force=true there; deleting an already-trashed product with force=false returns woocommerce_rest_already_trashed 410. A missing product returns woocommerce_rest_product_invalid_id 404. No edit_link is returned because the product is gone. To delete a single variation use og-wc-products/delete-product-variation. Discover ids with og-wc-products/list-products.', 'abilities-catalog-woo' ),
			'category'            => 'og-wc-products',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'id' ),
				'properties'           => array(
					'id'    => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'The product id to delete. Discover ids with og-wc-products/list-products.', 'abilities-catalog-woo' ),
					),
					'force' => array(
						'type'        => 'boolean',
						'default'     => false,
						'description' => __( 'Whether to permanently delete the product. false (the default) moves it to the Trash if the store has Trash enabled; true permanently deletes it (and the variations of a variable product), which cannot be undone. If the store has Trash disabled (EMPTY_TRASH_DAYS is 0) the route requires force=true.', 'abilities-catalog-woo' ),
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'deleted', 'id', 'force_used', 'permanent' ),
				'properties'           => array(
					'deleted'    => array(
						'type'        => 'boolean',
						'description' => __( 'Whether the product was deleted: true when it was permanently deleted or moved to the Trash.', 'abilities-catalog-woo' ),
					),
					'id'         => array(
						'type'        => 'integer',
						'description' => __( 'The id of the deleted product.', 'abilities-catalog-woo' ),
					),
					'name'       => array(
						'type'        => 'string',
						'description' => __( 'The name of the deleted product, captured before deletion so a human can confirm what was removed. No edit_link is returned because the product is gone.', 'abilities-catalog-woo' ),
					),
					'force_used' => array(
						'type'        => 'boolean',
						'description' => __( 'The force value used for the delete: true means a permanent delete was requested, false means a move to the Trash.', 'abilities-catalog-woo' ),
					),
					'permanent'  => array(
						'type'        => 'boolean',
						'description' => __( 'Whether the delete was permanent (true) or recoverable from the Trash (false). Mirrors force_used.', 'abilities-catalog-woo' ),
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
				'screen'       => 'edit.php?post_type=product',
			),
		);
	}

	/**
	 * Permission check: the coarse WooCommerce product delete capability.
	 *
	 * Encodes the type-level `delete_products` capability. The object-level check
	 * (`wc_rest_check_post_permissions( 'product', 'delete', $id )`, which resolves
	 * the `delete_product` meta-cap) is deferred to the wrapped `wc/v3` route, so a
	 * missing or non-deletable product surfaces the route's specific
	 * 404/403/410/501 via {@see RestError::from()} rather than being masked as a
	 * generic permission denial. Coarse and object-independent by design.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may delete products.
	 */
	public function hasPermission( $input ): bool {
		return WooPlugin::isActive() && current_user_can( 'delete_products' );
	}

	/**
	 * Executes the ability by dispatching the internal `wc/v3` product delete request.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The fixed delete-confirmation shape, or the REST error.
	 */
	public function execute( $input ) {
		if ( ! WooPlugin::isActive() ) {
			return WooPlugin::unavailable();
		}

		$input = is_array( $input ) ? $input : array();
		$id    = absint( $input['id'] ?? 0 );
		$force = ! empty( $input['force'] );

		// Capture the name before the product is gone; a missing product 404s here
		// with woocommerce_rest_product_invalid_id.
		$before = rest_do_request( new WP_REST_Request( 'GET', '/wc/v3/products/' . $id ) );
		if ( $before->is_error() ) {
			return RestError::from( $before );
		}

		$before_data = rest_get_server()->response_to_data( $before, false );
		$name        = is_array( $before_data ) ? (string) ( $before_data['name'] ?? '' ) : '';

		$request = new WP_REST_Request( 'DELETE', '/wc/v3/products/' . $id );
		$request->set_param( 'force', $force );

		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			return RestError::from( $response );
		}

		// The route returns the prepared product snapshot, not a {deleted:true}
		// body — a non-error DELETE means the product was trashed or deleted.
		return array(
			'deleted'    => true,
			'id'         => $id,
			'name'       => $name,
			'force_used' => $force,
			'permanent'  => $force,
		);
	}
}
