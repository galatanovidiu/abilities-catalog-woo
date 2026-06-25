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
 * Destructive write ability: `og-wc-products/delete-shipping-class`.
 *
 * Wraps `DELETE wc/v3/products/shipping_classes/<id>` via `rest_do_request()`.
 * Product shipping classes are a flat `product_shipping_class` taxonomy with no
 * Trash: the shared `wc/v3` terms controller errors with
 * `woocommerce_rest_trash_not_supported` 501 on `force=false`, so this ability
 * hard-sets `force=true` and the delete is permanent and irreversible — there is
 * no `force` input because there is no recoverable state. `wp_delete_term()`
 * removes the class and unassigns it from any products that used it; those
 * products are left with no shipping class.
 *
 * Before deleting, this reads the class's name so the result can confirm what was
 * removed (and so a missing class returns the route's specific
 * `woocommerce_rest_term_invalid` 404 here, not a later permission collapse).
 *
 * Only available when WooCommerce is active (it is a {@see ConditionalAbility}).
 *
 * @since 0.1.0
 */
final class DeleteShippingClass implements ConditionalAbility {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-wc-products/delete-shipping-class';
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
			'label'               => __( 'Delete Shipping Class', 'abilities-catalog-woo' ),
			'description'         => __( 'Permanently deletes a WooCommerce product shipping class by ID. This cannot be undone: shipping classes have no Trash, so the term is force-deleted and there is no restore. Any products assigned to the class are left with no shipping class. Returns a confirmation with the deleted class\'s name. Discover IDs with og-wc-products/list-shipping-classes.', 'abilities-catalog-woo' ),
			'category'            => 'og-wc-products',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'id' ),
				'properties'           => array(
					'id' => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'The shipping class term ID to permanently delete. Discover IDs with og-wc-products/list-shipping-classes.', 'abilities-catalog-woo' ),
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
						'description' => __( 'Whether the shipping class was deleted.', 'abilities-catalog-woo' ),
					),
					'id'         => array(
						'type'        => 'integer',
						'description' => __( 'The deleted shipping class\'s term ID.', 'abilities-catalog-woo' ),
					),
					'name'       => array(
						'type'        => 'string',
						'description' => __( 'The name of the deleted shipping class, so a human can confirm what was removed. No edit_link is returned because the class no longer exists.', 'abilities-catalog-woo' ),
					),
					'force_used' => array(
						'type'        => 'boolean',
						'description' => __( 'Always true: shipping classes have no Trash, so the delete is always a permanent force-delete.', 'abilities-catalog-woo' ),
					),
					'permanent'  => array(
						'type'        => 'boolean',
						'description' => __( 'Always true: the delete is permanent and irreversible (no Trash, no restore).', 'abilities-catalog-woo' ),
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
				'screen'       => 'admin.php?page=wc-settings&tab=shipping&section=classes',
			),
		);
	}

	/**
	 * Permission check: WooCommerce's delete capability for product terms.
	 *
	 * Mirrors `wc_rest_check_product_term_permissions( 'product_shipping_class',
	 * 'delete' )`, which resolves the `product_shipping_class` taxonomy's
	 * `delete_terms` cap to `delete_product_terms` — the baseline the wrapped
	 * `wc/v3` DELETE route enforces. This is a coarse, object-INDEPENDENT type-level
	 * guard: the per-object decision is deferred to the wrapped route, so a missing
	 * shipping class surfaces its specific `woocommerce_rest_term_invalid` 404 via
	 * {@see RestError::from()} instead of collapsing to a generic permission denial.
	 * The explicit activity guard keeps the denial clean when WooCommerce is
	 * inactive.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may delete product shipping classes.
	 */
	public function hasPermission( $input ): bool {
		return WooPlugin::isActive() && current_user_can( 'delete_product_terms' );
	}

	/**
	 * Executes the ability by dispatching the internal `wc/v3` REST delete request.
	 *
	 * Reads the class's name first (a missing class 404s here with
	 * `woocommerce_rest_term_invalid`), then deletes with `force=true` (the only
	 * mode the terms route supports — `force=false` would 501). The route returns
	 * the prepared term snapshot rather than a `{deleted:true}` body, so `deleted`
	 * is derived from the non-error response.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The deleted flag, id, name, and force/permanent
	 *                                        flags, or the REST error (e.g.
	 *                                        `woocommerce_rest_term_invalid` 404).
	 */
	public function execute( $input ) {
		if ( ! WooPlugin::isActive() ) {
			return WooPlugin::unavailable();
		}

		$input = is_array( $input ) ? $input : array();
		$id    = absint( $input['id'] ?? 0 );

		// Capture the name before the term is gone; a missing class 404s here.
		$before = rest_do_request( new WP_REST_Request( 'GET', '/wc/v3/products/shipping_classes/' . $id ) );
		if ( $before->is_error() ) {
			return RestError::from( $before );
		}

		$before_data = rest_get_server()->response_to_data( $before, false );
		$name        = is_array( $before_data ) ? (string) ( $before_data['name'] ?? '' ) : '';

		// Shipping classes have no Trash: force=true is the only supported mode.
		$request = new WP_REST_Request( 'DELETE', '/wc/v3/products/shipping_classes/' . $id );
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
