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
 * Destructive write ability: `wc-products/delete-product-attribute`.
 *
 * Wraps `DELETE wc/v3/products/attributes/<id>` via `rest_do_request()`. It first
 * reads the attribute (`GET wc/v3/products/attributes/<id>`) to capture its name —
 * so a missing attribute returns the route's 404 here, and the result can confirm
 * what was removed — then deletes it.
 *
 * This is the HIGHEST blast radius in the product domain. Deleting a global
 * attribute calls `wc_delete_attribute()`, which drops the row in
 * `woocommerce_attribute_taxonomies`, unregisters the entire `pa_<slug>` taxonomy,
 * and `wp_delete_term`s EVERY term that lived on it — and the attribute is then
 * unset from every product that used it. The wrapped route does NOT support
 * trashing: it returns `woocommerce_rest_trash_not_supported` 501 on `force=false`.
 * There is therefore no recoverable state, so this ability hard-sets `force=true`
 * (it exposes no `force` input) and the delete is PERMANENT and irreversible.
 *
 * The delete returns the prepared attribute snapshot (not a `{deleted:true}` body),
 * so `deleted` is derived from the non-error response. The result is a tiny fixed
 * shape — `deleted`, `id`, `name`, `force_used`, `permanent` — never the raw object,
 * and with no `edit_link` because the attribute no longer exists.
 *
 * Only available when WooCommerce is active (it is a {@see ConditionalAbility}).
 *
 * @since 0.1.0
 */
final class DeleteProductAttribute implements ConditionalAbility {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'wc-products/delete-product-attribute';
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
			'label'               => __( 'Delete Product Attribute', 'abilities-catalog-woo' ),
			'description'         => __( 'Permanently deletes a WooCommerce global product attribute by ID and returns a confirmation with the deleted attribute\'s name. THIS IS IRREVERSIBLE AND HAS THE LARGEST BLAST RADIUS IN THE PRODUCT CATALOG: deleting a global attribute also drops its entire pa_<slug> taxonomy, deletes ALL of that attribute\'s terms, and unsets the attribute on every product that used it. Global attributes have no Trash — there is no recoverable state, so this always force-deletes (no force input). Returns deleted, id, name, force_used (always true), and permanent (always true); no edit_link is returned because the attribute no longer exists. To remove a single term instead of the whole attribute, use wc-products/delete-attribute-term. Discover attribute IDs with wc-products/list-product-attributes.', 'abilities-catalog-woo' ),
			'category'            => 'wc-products',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'id' ),
				'properties'           => array(
					'id' => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'The global attribute ID to permanently delete. Discover IDs with wc-products/list-product-attributes.', 'abilities-catalog-woo' ),
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
						'description' => __( 'Whether the attribute was deleted. True on a successful delete.', 'abilities-catalog-woo' ),
					),
					'id'         => array(
						'type'        => 'integer',
						'description' => __( 'The deleted attribute\'s ID.', 'abilities-catalog-woo' ),
					),
					'name'       => array(
						'type'        => 'string',
						'description' => __( 'The deleted attribute\'s display name, captured before deletion so a human can confirm what was removed. No edit_link is returned because the attribute no longer exists.', 'abilities-catalog-woo' ),
					),
					'force_used' => array(
						'type'        => 'boolean',
						'description' => __( 'Always true: global attributes have no Trash, so the delete is always a permanent force-delete.', 'abilities-catalog-woo' ),
					),
					'permanent'  => array(
						'type'        => 'boolean',
						'description' => __( 'Always true: the delete is permanent and irreversible, and also dropped the pa_<slug> taxonomy and all of the attribute\'s terms.', 'abilities-catalog-woo' ),
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
				'screen'       => 'edit.php?post_type=product&page=product_attributes',
			),
		);
	}

	/**
	 * Permission check: WooCommerce's manager capability for product attributes.
	 *
	 * Encodes the catalog capability for `wc-products/delete-product-attribute`:
	 * `manage_product_terms`, which is what
	 * `wc_rest_check_manager_permissions( 'attributes', 'delete' )` resolves to on
	 * the wrapped `DELETE wc/v3/products/attributes/<id>` route (the helper checks
	 * `manage_product_terms` for the `attributes` object). This is coarse and
	 * object-independent; doing the object-level check here would mask a missing
	 * attribute as "permission denied". Deferring to the route lets execute()
	 * surface the route's specific 404 (`woocommerce_rest_taxonomy_invalid` /
	 * `woocommerce_rest_attribute_invalid`) via {@see RestError::from()}.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may delete product attributes.
	 */
	public function hasPermission( $input ): bool {
		return WooPlugin::isActive() && current_user_can( 'manage_product_terms' );
	}

	/**
	 * Executes the ability by dispatching the internal `wc/v3` REST delete request.
	 *
	 * Reads the attribute first (to capture its name and to surface a missing-attribute
	 * 404 here), then force-deletes it. `force` is hard-set to `true` because the
	 * route does not support trashing attributes.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The deleted flag, id, name, force_used, and
	 *                                        permanent, or the REST error (e.g.
	 *                                        `woocommerce_rest_attribute_invalid` 404).
	 */
	public function execute( $input ) {
		if ( ! WooPlugin::isActive() ) {
			return WooPlugin::unavailable();
		}

		$input = is_array( $input ) ? $input : array();
		$id    = absint( $input['id'] ?? 0 );

		// Capture the name before the attribute is gone; a missing attribute 404s here.
		$before = rest_do_request( new WP_REST_Request( 'GET', '/wc/v3/products/attributes/' . $id ) );
		if ( $before->is_error() ) {
			return RestError::from( $before );
		}

		$before_data = rest_get_server()->response_to_data( $before, false );
		$name        = is_array( $before_data ) ? (string) ( $before_data['name'] ?? '' ) : '';

		// Attributes have no Trash, so force-delete is the only path the route accepts.
		$request = new WP_REST_Request( 'DELETE', '/wc/v3/products/attributes/' . $id );
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
