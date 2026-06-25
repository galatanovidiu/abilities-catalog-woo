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
 * Destructive write ability: `og-wc-products/delete-attribute-term`.
 *
 * Wraps `DELETE wc/v3/products/attributes/<attribute_id>/terms/<id>` via
 * `rest_do_request()`, permanently removing one term from a global product
 * attribute's `pa_*` taxonomy (e.g. removing "Red" from the "Color" attribute).
 * The `attribute_id` is a required ROUTE SEGMENT naming the parent attribute, so
 * it is concatenated into the path, not sent as a body param.
 *
 * Attribute terms have NO Trash: the wrapped term-delete route 501s
 * (`woocommerce_rest_trash_not_supported`) on `force=false`, so this ability
 * HARD-SETS `force=true`. The delete is therefore permanent and irreversible —
 * there is no recoverable state and no `force` input. Deleting a term also
 * unsets it from any product variation or product that referenced it.
 *
 * Before deleting, this reads the term's `name` so the result can confirm what
 * was removed (and so a missing term returns the route's
 * `woocommerce_rest_term_invalid` 404, and a bad `attribute_id` the route's
 * `woocommerce_rest_taxonomy_invalid` 404, HERE — not a later permission
 * collapse). The wc/v3 term-delete route returns the prepared term snapshot, NOT
 * a `{deleted:true}` body, so `deleted` is derived from a non-error response.
 *
 * Only available when WooCommerce is active (it is a {@see ConditionalAbility}).
 *
 * @since 0.1.0
 */
final class DeleteAttributeTerm implements ConditionalAbility {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-wc-products/delete-attribute-term';
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
			'label'               => __( 'Delete Attribute Term', 'abilities-catalog-woo' ),
			'description'         => __( 'Permanently deletes a term from a global product attribute (e.g. removes "Red" from the "Color" attribute). This cannot be undone: attribute terms have no Trash, so the delete is irreversible — there is no force option and no restore. Deleting a term also unsets it from any product that referenced it. attribute_id is the parent attribute the term belongs to; discover it with og-wc-products/list-product-attributes, and discover the term id with og-wc-products/list-attribute-terms. A missing term returns a "woocommerce_rest_term_invalid" 404; a non-existent attribute_id returns a "woocommerce_rest_taxonomy_invalid" 404. Returns a confirmation carrying the deleted term\'s name; no edit_link is returned because the term no longer exists. To delete the whole attribute and its entire taxonomy instead, use og-wc-products/delete-product-attribute.', 'abilities-catalog-woo' ),
			'category'            => 'og-wc-products',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'attribute_id', 'id' ),
				'properties'           => array(
					'attribute_id' => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'The parent attribute\'s id — the global attribute the term belongs to. Discover it with og-wc-products/list-product-attributes. A non-existent attribute_id returns a "woocommerce_rest_taxonomy_invalid" 404.', 'abilities-catalog-woo' ),
					),
					'id'           => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'The attribute term id to permanently delete. Discover it with og-wc-products/list-attribute-terms. A non-existent term returns a "woocommerce_rest_term_invalid" 404.', 'abilities-catalog-woo' ),
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
						'description' => __( 'Whether the attribute term was deleted. Always true on a non-error response; the wc/v3 term-delete route returns the deleted term snapshot rather than a deleted flag, so this is derived from the successful response.', 'abilities-catalog-woo' ),
					),
					'id'         => array(
						'type'        => 'integer',
						'description' => __( 'The deleted term\'s id.', 'abilities-catalog-woo' ),
					),
					'name'       => array(
						'type'        => 'string',
						'description' => __( 'The name of the deleted term, captured before deletion so a human can confirm what was removed. No edit_link is returned because the term no longer exists.', 'abilities-catalog-woo' ),
					),
					'force_used' => array(
						'type'        => 'boolean',
						'description' => __( 'Always true: attribute terms have no Trash, so the delete is forced and permanent.', 'abilities-catalog-woo' ),
					),
					'permanent'  => array(
						'type'        => 'boolean',
						'description' => __( 'Always true: the term was permanently removed and cannot be restored.', 'abilities-catalog-woo' ),
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
	 * Permission check: WooCommerce's delete capability for product terms.
	 *
	 * Encodes the catalog capability for `og-wc-products/delete-attribute-term`: the
	 * `delete_product_terms` capability, which is what
	 * `wc_rest_check_product_term_permissions( $taxonomy, 'delete' )` resolves to
	 * on the wrapped term-delete route — the helper maps the `delete` context to
	 * the taxonomy's `delete_terms` cap, registered as `delete_product_terms` for
	 * the `pa_*` attribute taxonomies. (This differs from the product-term READS,
	 * which gate on `manage_product_terms`, because the read context maps to
	 * `manage_terms`.) This is a coarse, object-INDEPENDENT guard: the
	 * per-attribute and per-term decision is deferred to the wrapped route, so a
	 * missing attribute surfaces its `woocommerce_rest_taxonomy_invalid` 404 and a
	 * missing term its `woocommerce_rest_term_invalid` 404 via
	 * {@see RestError::from()} instead of collapsing to a generic permission
	 * denial. The explicit activity guard keeps the denial clean when WooCommerce
	 * is inactive and the capability is unmapped.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may delete product attribute terms.
	 */
	public function hasPermission( $input ): bool {
		return WooPlugin::isActive() && current_user_can( 'delete_product_terms' );
	}

	/**
	 * Executes the ability by dispatching the internal `wc/v3` REST delete request.
	 *
	 * Pre-reads the term (GET) to capture its name and to surface a missing term or
	 * bad attribute_id as the route's specific 404 here, then DELETEs with
	 * `force=true` (the term-delete route 501s on `force=false`). The route returns
	 * the prepared term snapshot, so `deleted` is derived from the non-error
	 * response rather than read from a `deleted` key.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The deleted flag, id, name, and force/permanent flags, or the REST error.
	 */
	public function execute( $input ) {
		if ( ! WooPlugin::isActive() ) {
			return WooPlugin::unavailable();
		}

		$input        = is_array( $input ) ? $input : array();
		$attribute_id = absint( $input['attribute_id'] ?? 0 );
		$id           = absint( $input['id'] ?? 0 );

		$path = '/wc/v3/products/attributes/' . $attribute_id . '/terms/' . $id;

		// Capture the name before the term is gone; a missing term or bad
		// attribute_id 404s here with the route's specific code.
		$before = rest_do_request( new WP_REST_Request( 'GET', $path ) );
		if ( $before->is_error() ) {
			return RestError::from( $before );
		}

		$before_data = rest_get_server()->response_to_data( $before, false );
		$name        = is_array( $before_data ) ? (string) ( $before_data['name'] ?? '' ) : '';

		// Attribute terms have no Trash: the route 501s on force=false, so force the
		// permanent delete.
		$request = new WP_REST_Request( 'DELETE', $path );
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
