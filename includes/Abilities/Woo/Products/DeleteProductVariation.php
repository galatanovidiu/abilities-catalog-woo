<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogWoo\Abilities\Woo\Products;

use GalatanOvidiu\AbilitiesCatalogWoo\Contracts\ConditionalAbility;
use GalatanOvidiu\AbilitiesCatalogWoo\Support\BooleanInput;
use GalatanOvidiu\AbilitiesCatalogWoo\Support\RestError;
use GalatanOvidiu\AbilitiesCatalogWoo\Support\WooPlugin;
use WP_REST_Request;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Destructive write ability: `og-wc-products/delete-product-variation`.
 *
 * Wraps `DELETE wc/v3/products/<product_id>/variations/<id>` via
 * `rest_do_request()`. The `product_id` is a required route segment: the route
 * resolves the variation against that exact parent (`check_variation_parent()`),
 * so a missing variation OR a variation that belongs to a different parent both
 * surface the route's `woocommerce_rest_product_variation_invalid_id` 404. The
 * slash-bearing path is built by concatenation (never `set_param`) so both
 * segments are sent as real path segments.
 *
 * Variations are Trash-capable, mirroring their parent product: with `force`
 * false the route moves the variation to the Trash (recoverable) — but a store
 * with `EMPTY_TRASH_DAYS == 0` (Trash disabled) rejects that with
 * `woocommerce_rest_trash_not_supported` 501; with `force` true it permanently
 * deletes the variation, which cannot be undone. The variation post type maps to
 * the product capability type, so the delete is gated by the coarse
 * `delete_products` primitive and the wrapped route runs the object-level check.
 *
 * Before deleting, this reads the variation's formatted name (e.g.
 * "Color: Red, Size: Large") so the result can confirm what was removed, and so a
 * missing/mismatched variation returns the route's 404 here rather than collapsing
 * to a later permission error. The result is a tiny fixed shape
 * (`deleted, id, name, force_used, permanent`) with NO `edit_link` — the variation
 * is gone, so a dead-end edit link would be misleading.
 *
 * Only available when WooCommerce is active (it is a {@see ConditionalAbility}).
 *
 * @since 0.1.0
 */
final class DeleteProductVariation implements ConditionalAbility {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-wc-products/delete-product-variation';
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
			'label'               => __( 'Delete Product Variation', 'abilities-catalog-woo' ),
			'description'         => __( 'Deletes one variation of a WooCommerce VARIABLE product, identified by its parent product_id and its variation id, and returns a confirmation with the deleted variation\'s name (e.g. "Color: Red, Size: Large"). The variation must belong to the given parent: a missing variation or a parent mismatch returns woocommerce_rest_product_variation_invalid_id (404). By default (force=false) the variation is moved to the Trash and can be restored, but if the store has Trash disabled (EMPTY_TRASH_DAYS=0) the route requires force=true and otherwise returns woocommerce_rest_trash_not_supported (501); set force=true to permanently delete it, which cannot be undone. Deleting a variation does not delete the parent product. Discover the parent product_id with og-wc-products/list-products and the variation id with og-wc-products/list-product-variations. No edit_link is returned because the variation no longer exists.', 'abilities-catalog-woo' ),
			'category'            => 'og-wc-products',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'product_id', 'id' ),
				'properties'           => array(
					'product_id' => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'The parent variable product ID the variation belongs to. Discover it with og-wc-products/list-products.', 'abilities-catalog-woo' ),
					),
					'id'         => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'The variation ID to delete. Discover it with og-wc-products/list-product-variations for the parent product.', 'abilities-catalog-woo' ),
					),
					'force'      => array(
						'type'        => 'boolean',
						'default'     => false,
						'description' => __( 'When false (default) the variation is moved to the Trash and can be restored; when true it is permanently deleted and cannot be undone. If the store has Trash disabled (EMPTY_TRASH_DAYS=0) a false value is rejected with woocommerce_rest_trash_not_supported (501), so pass true to delete on such a store.', 'abilities-catalog-woo' ),
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
						'description' => __( 'Whether the variation was deleted (trashed or permanently removed).', 'abilities-catalog-woo' ),
					),
					'id'         => array(
						'type'        => 'integer',
						'description' => __( 'The deleted variation\'s ID.', 'abilities-catalog-woo' ),
					),
					'name'       => array(
						'type'        => 'string',
						'description' => __( 'The deleted variation\'s formatted name (its attribute selections, e.g. "Color: Red, Size: Large"), captured before deletion so a human can confirm what was removed. No edit_link is returned because the variation no longer exists.', 'abilities-catalog-woo' ),
					),
					'force_used' => array(
						'type'        => 'boolean',
						'description' => __( 'Whether the variation was permanently deleted (true) or moved to the Trash (false). Reflects the force value actually used.', 'abilities-catalog-woo' ),
					),
					'permanent'  => array(
						'type'        => 'boolean',
						'description' => __( 'Whether the deletion is irreversible. True for a permanent (force) delete; false when the variation was trashed and can be restored.', 'abilities-catalog-woo' ),
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
	 * Permission check: WooCommerce's product delete capability.
	 *
	 * Coarse, type-level, object-independent gate. WooCommerce maps the
	 * `product_variation` post type to the `product` capability type, so variation
	 * deletes resolve to the product caps; this uses the coarse `delete_products`
	 * primitive. The wrapped route runs the hard object-level check underneath
	 * (`wc_rest_check_post_permissions( 'product_variation', 'delete', $id )`) and
	 * surfaces the route's specific `woocommerce_rest_product_variation_invalid_id`
	 * 404 for a missing/mismatched variation — doing an object-level check here
	 * would mask that 404 as a generic permission denial. The activity guard keeps
	 * the denial clean when WooCommerce is off.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may delete product variations.
	 */
	public function hasPermission( $input ): bool {
		return WooPlugin::isActive() && current_user_can( 'delete_products' );
	}

	/**
	 * Executes the ability by dispatching the internal `wc/v3` delete-variation request.
	 *
	 * Pre-reads the variation (GET) to capture its formatted name and to surface a
	 * missing/mismatched variation's 404 here, then DELETEs it with the requested
	 * `force`. The `product_id` and `id` segments are concatenated into the path so
	 * they are sent as real path segments (never `set_param`). `deleted` is derived
	 * from the non-error DELETE response: the wc/v3 variation delete returns the
	 * prepared object snapshot, not a `{deleted:true}` body.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The deleted flag, id, name, and force/permanent flags, or the REST error.
	 */
	public function execute( $input ) {
		if ( ! WooPlugin::isActive() ) {
			return WooPlugin::unavailable();
		}

		$input      = is_array( $input ) ? $input : array();
		$product_id = absint( $input['product_id'] ?? 0 );
		$id         = absint( $input['id'] ?? 0 );
		$force      = BooleanInput::sanitize( $input['force'] ?? false );

		$path = '/wc/v3/products/' . $product_id . '/variations/' . $id;

		// Capture the variation name before it is gone; a missing/mismatched variation 404s here.
		$before = rest_do_request( new WP_REST_Request( 'GET', $path ) );
		if ( $before->is_error() ) {
			return RestError::from( $before );
		}

		$before_data = rest_get_server()->response_to_data( $before, false );
		$name        = is_array( $before_data ) ? (string) ( $before_data['name'] ?? '' ) : '';

		$request = new WP_REST_Request( 'DELETE', $path );
		$request->set_param( 'force', $force );

		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			return RestError::from( $response );
		}

		// A non-error DELETE means the variation was deleted; the route returns the
		// prepared object snapshot, not a {deleted:true} body.
		return array(
			'deleted'    => true,
			'id'         => $id,
			'name'       => $name,
			'force_used' => $force,
			'permanent'  => $force,
		);
	}
}
