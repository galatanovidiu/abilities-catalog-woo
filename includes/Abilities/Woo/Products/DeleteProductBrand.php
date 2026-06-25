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
 * Destructive write ability: `og-wc-products/delete-product-brand`.
 *
 * Wraps `DELETE wc/v3/products/brands/<id>` via `rest_do_request()`. Product
 * brands are the hierarchical `product_brand` taxonomy; like every WooCommerce
 * product-term route they support no Trash, so the delete is PERMANENT and
 * irreversible — the route 501s on `force=false`, so this hard-sets `force=true`
 * (there is no `force` input because there is no recoverable state). The wrapped
 * controller `wp_delete_term()`s the brand; products previously in the brand are
 * simply unbranded, not deleted.
 *
 * Before deleting, this reads the brand's name (a missing brand 404s here with
 * `woocommerce_rest_term_invalid`, not as a later permission collapse) so the
 * result confirms what was removed. The result is a tiny fixed shape with NO
 * `edit_link` — the brand no longer exists.
 *
 * Only available when WooCommerce is active AND the product Brands feature has
 * registered its `/wc/v3/products/brands` route (it is a
 * {@see ConditionalAbility} gated on {@see WooPlugin::hasBrandsSupport()}). When
 * Brands is absent this ability does not register, so it degrades cleanly rather
 * than denying.
 *
 * @since 0.1.0
 */
final class DeleteProductBrand implements ConditionalAbility {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-wc-products/delete-product-brand';
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
		return array(
			'label'               => __( 'Delete Product Brand', 'abilities-catalog-woo' ),
			'description'         => __( 'Permanently deletes one WooCommerce product brand (a product_brand term) by ID. This cannot be undone: product brands have no Trash, so the brand is removed at once and there is no restore. Products that were in the brand are not deleted — they are simply unbranded. Returns the deleted brand\'s name for confirmation; no edit_link is returned because the brand no longer exists. This ability exists only when the store\'s WooCommerce Brands feature is active. Discover IDs with og-wc-products/list-product-brands.', 'abilities-catalog-woo' ),
			'category'            => 'og-wc-products',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'id' ),
				'properties'           => array(
					'id' => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'The product brand term ID to permanently delete. Discover IDs with og-wc-products/list-product-brands.', 'abilities-catalog-woo' ),
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
						'description' => __( 'Whether the brand was permanently deleted.', 'abilities-catalog-woo' ),
					),
					'id'         => array(
						'type'        => 'integer',
						'description' => __( 'The deleted brand term ID.', 'abilities-catalog-woo' ),
					),
					'name'       => array(
						'type'        => 'string',
						'description' => __( 'The name of the deleted brand, captured before deletion so a human can confirm what was removed. No edit_link is returned because the brand no longer exists.', 'abilities-catalog-woo' ),
					),
					'force_used' => array(
						'type'        => 'boolean',
						'description' => __( 'Always true: product brands have no Trash, so the delete is always a permanent force delete.', 'abilities-catalog-woo' ),
					),
					'permanent'  => array(
						'type'        => 'boolean',
						'description' => __( 'Always true: the brand was permanently removed and cannot be restored.', 'abilities-catalog-woo' ),
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
				'screen'       => 'edit-tags.php?taxonomy=product_brand&post_type=product',
			),
		);
	}

	/**
	 * Permission check: WooCommerce's delete capability for product terms.
	 *
	 * Mirrors `wc_rest_check_product_term_permissions( 'product_brand', 'delete' )`,
	 * which resolves the `delete` term cap for the `product_brand` taxonomy —
	 * registered as `delete_product_terms` — the baseline the wrapped `wc/v3`
	 * DELETE route enforces (note the brand READ uses `manage_product_terms`; the
	 * delete cap is distinct). This is a coarse, object-INDEPENDENT type-level
	 * guard: the per-object decision is deferred to the wrapped route, so a missing
	 * brand surfaces its specific `woocommerce_rest_term_invalid` 404 via
	 * {@see RestError::from()} instead of collapsing to a generic permission
	 * denial. The brands-support gate lives in {@see self::isAvailable()}, not
	 * here, so this stays a plain capability check; the explicit activity guard
	 * keeps the denial clean when WooCommerce is inactive.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may delete product terms.
	 */
	public function hasPermission( $input ): bool {
		return WooPlugin::isActive() && current_user_can( 'delete_product_terms' );
	}

	/**
	 * Executes the ability by dispatching the internal `wc/v3` REST delete request.
	 *
	 * Pre-reads the brand to capture its name (a missing brand 404s here), then
	 * force-deletes it. The term-delete route returns the prepared term snapshot,
	 * not a `{deleted:true}` body, so `deleted` is derived from the non-error
	 * response.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The deleted flag, id, name, force_used,
	 *                                        and permanent, or the REST error (e.g.
	 *                                        `woocommerce_rest_term_invalid` 404).
	 */
	public function execute( $input ) {
		if ( ! WooPlugin::isActive() ) {
			return WooPlugin::unavailable();
		}

		$input = is_array( $input ) ? $input : array();
		$id    = absint( $input['id'] ?? 0 );

		// Capture the name before the term is gone; a missing brand 404s here.
		$before = rest_do_request( new WP_REST_Request( 'GET', '/wc/v3/products/brands/' . $id ) );
		if ( $before->is_error() ) {
			return RestError::from( $before );
		}

		$before_data = rest_get_server()->response_to_data( $before, false );
		$name        = is_array( $before_data ) ? (string) ( $before_data['name'] ?? '' ) : '';

		// Product brands have no Trash; the route 501s on force=false, so force the delete.
		$request = new WP_REST_Request( 'DELETE', '/wc/v3/products/brands/' . $id );
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
