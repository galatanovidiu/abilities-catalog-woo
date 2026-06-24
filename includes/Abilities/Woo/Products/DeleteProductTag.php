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
 * Destructive write ability: `wc-products/delete-product-tag`.
 *
 * Wraps `DELETE wc/v3/products/tags/<id>` via `rest_do_request()`. The product
 * tag taxonomy (`product_tag`) has no Trash: the shared
 * {@see \WC_REST_Terms_Controller::delete_item()} returns
 * `woocommerce_rest_trash_not_supported` 501 unless `force` is truthy, then
 * calls `wp_delete_term()`. So this ability hard-sets `force=true` and exposes
 * no `force` input — the delete is always permanent and irreversible, with no
 * recoverable state. Deleting a tag only removes the term and its assignments;
 * the products that carried the tag are untouched (it does not reassign to a
 * default, unlike the category delete).
 *
 * Before deleting, it reads the tag's name so the result can confirm what was
 * removed (and so a missing tag returns the route's `woocommerce_rest_term_invalid`
 * 404 here, not a later permission collapse).
 *
 * Only available when WooCommerce is active (it is a {@see ConditionalAbility}).
 *
 * @since 0.1.0
 */
final class DeleteProductTag implements ConditionalAbility {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'wc-products/delete-product-tag';
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
			'label'               => __( 'Delete Product Tag', 'abilities-catalog-woo' ),
			'description'         => __( 'Permanently deletes a WooCommerce product tag by ID. This cannot be undone: product tags have no Trash, so the term is removed outright (no restore). Deleting a tag only removes the term and unassigns it from products; the products themselves are not deleted or reassigned. Returns a confirmation with the deleted tag\'s name. Discover IDs with wc-products/list-product-tags.', 'abilities-catalog-woo' ),
			'category'            => 'wc-products',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'id' ),
				'properties'           => array(
					'id' => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'The product tag term ID to permanently delete. Discover IDs with wc-products/list-product-tags.', 'abilities-catalog-woo' ),
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
						'description' => __( 'Whether the tag was deleted.', 'abilities-catalog-woo' ),
					),
					'id'         => array(
						'type'        => 'integer',
						'description' => __( 'The deleted tag\'s term ID.', 'abilities-catalog-woo' ),
					),
					'name'       => array(
						'type'        => 'string',
						'description' => __( 'The name of the deleted tag, captured before deletion so a human can confirm what was removed. No edit_link is returned because the tag no longer exists.', 'abilities-catalog-woo' ),
					),
					'force_used' => array(
						'type'        => 'boolean',
						'description' => __( 'Always true: product tags have no Trash, so the delete is forced (permanent).', 'abilities-catalog-woo' ),
					),
					'permanent'  => array(
						'type'        => 'boolean',
						'description' => __( 'Always true: the tag was permanently deleted and cannot be restored.', 'abilities-catalog-woo' ),
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
				'screen'       => 'edit-tags.php?taxonomy=product_tag&post_type=product',
			),
		);
	}

	/**
	 * Permission check: WooCommerce's delete capability for product terms.
	 *
	 * Mirrors `wc_rest_check_product_term_permissions( 'product_tag', 'delete' )`,
	 * which resolves the `product_tag` taxonomy's `delete_terms` cap to
	 * `delete_product_terms` — the baseline the wrapped `wc/v3` DELETE route
	 * enforces. Coarse and object-INDEPENDENT: the per-object decision is deferred
	 * to the wrapped route, so a missing tag surfaces its specific
	 * `woocommerce_rest_term_invalid` 404 via {@see RestError::from()} instead of
	 * collapsing to a generic permission denial. The explicit activity guard keeps
	 * the denial clean when WooCommerce is inactive.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may delete product tags.
	 */
	public function hasPermission( $input ): bool {
		return WooPlugin::isActive() && current_user_can( 'delete_product_terms' );
	}

	/**
	 * Executes the ability by dispatching the internal `wc/v3` REST delete request.
	 *
	 * Reads the tag first (capturing its name and surfacing a missing-tag 404),
	 * then deletes it with `force=true` because the term taxonomy has no Trash.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The deletion confirmation, or the REST
	 *                                        error (e.g. `woocommerce_rest_term_invalid` 404).
	 */
	public function execute( $input ) {
		if ( ! WooPlugin::isActive() ) {
			return WooPlugin::unavailable();
		}

		$input = is_array( $input ) ? $input : array();
		$id    = absint( $input['id'] ?? 0 );

		// Capture the name before the term is gone; a missing tag 404s here.
		$before = rest_do_request( new WP_REST_Request( 'GET', '/wc/v3/products/tags/' . $id ) );
		if ( $before->is_error() ) {
			return RestError::from( $before );
		}

		$before_data = rest_get_server()->response_to_data( $before, false );
		$name        = is_array( $before_data ) ? (string) ( $before_data['name'] ?? '' ) : '';

		// Product tags have no Trash; the route 501s on force=false, so force the delete.
		$request = new WP_REST_Request( 'DELETE', '/wc/v3/products/tags/' . $id );
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
