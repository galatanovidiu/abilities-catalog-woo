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
 * Destructive write ability: `wc-products/delete-product-review`.
 *
 * Wraps `DELETE wc/v3/products/reviews/<id>` via `rest_do_request()`. A WooCommerce
 * product review IS a WordPress comment on a product (`comment_type = 'review'`), so
 * this deletes a comment row, not a post.
 *
 * Trash semantics mirror the wrapped controller's `delete_item()`. With `force=false`
 * (the default) the review is moved to the Trash and is recoverable — but the route
 * returns `woocommerce_rest_trash_not_supported` 501 when the store has Trash disabled
 * (`EMPTY_TRASH_DAYS == 0`), and `woocommerce_rest_already_trashed` 410 when the review
 * is already in the Trash. With `force=true` the comment is permanently `wp_delete_comment`d
 * and cannot be restored; the force path is the only one the route reports with a
 * `{ deleted: true, previous }` body.
 *
 * A review has no single display name, so before deleting this reads the review's
 * reviewer name and product name (off the pre-read GET) for the confirmation result;
 * the pre-read also makes a missing review surface the route's `woocommerce_rest_review_invalid_id`
 * 404 here, not a later permission collapse.
 *
 * The DELETE response is NOT relied on for the `deleted` flag (the trash path returns the
 * prepared review snapshot, the force path returns `{ deleted: true }`): a non-error DELETE
 * means the review was trashed or deleted, so `deleted` is derived from the non-error response.
 *
 * Only available when WooCommerce is active (it is a {@see ConditionalAbility}).
 *
 * @since 0.1.0
 */
final class DeleteProductReview implements ConditionalAbility {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'wc-products/delete-product-review';
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
			'label'               => __( 'Delete Product Review', 'abilities-catalog-woo' ),
			'description'         => __( 'Deletes a WooCommerce product review by ID (a product review is a WordPress comment with comment_type "review") and returns a confirmation: deleted, id, reviewer name, product name, force_used, and permanent. By default (force=false) the review is moved to the Trash and can be restored — but if the store has Trash disabled (EMPTY_TRASH_DAYS == 0) the route fails with woocommerce_rest_trash_not_supported 501, and an already-trashed review fails with woocommerce_rest_already_trashed 410. Set force=true to permanently delete the comment; this bypasses the Trash and cannot be undone. This deletes only the review, not the product. Discover review IDs with wc-products/list-product-reviews. No edit_link is returned because the review is gone.', 'abilities-catalog-woo' ),
			'category'            => 'wc-products',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'id' ),
				'properties'           => array(
					'id'    => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'The product-review ID to delete. Discover IDs with wc-products/list-product-reviews. A non-existent review id is rejected with a woocommerce_rest_review_invalid_id 404.', 'abilities-catalog-woo' ),
					),
					'force' => array(
						'type'        => 'boolean',
						'default'     => false,
						'description' => __( 'Whether to bypass the Trash and permanently delete the review. false (the default) moves it to the Trash (recoverable) if the store has Trash enabled, but fails with a woocommerce_rest_trash_not_supported 501 if Trash is disabled (EMPTY_TRASH_DAYS == 0); true permanently deletes the comment and cannot be undone.', 'abilities-catalog-woo' ),
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'deleted', 'id', 'force_used', 'permanent' ),
				'properties'           => array(
					'deleted'      => array(
						'type'        => 'boolean',
						'description' => __( 'Whether the review was deleted (trashed when force=false, permanently removed when force=true).', 'abilities-catalog-woo' ),
					),
					'id'           => array(
						'type'        => 'integer',
						'description' => __( 'The deleted review\'s ID.', 'abilities-catalog-woo' ),
					),
					'reviewer'     => array(
						'type'        => 'string',
						'description' => __( 'The reviewer display name captured before deletion, so a human can confirm which review was removed. No edit_link is returned because the review no longer exists.', 'abilities-catalog-woo' ),
					),
					'product_name' => array(
						'type'        => 'string',
						'description' => __( 'The name of the product the deleted review was on, captured before deletion for confirmation.', 'abilities-catalog-woo' ),
					),
					'force_used'   => array(
						'type'        => 'boolean',
						'description' => __( 'The force value actually used: true when the review was permanently deleted, false when it was moved to the Trash.', 'abilities-catalog-woo' ),
					),
					'permanent'    => array(
						'type'        => 'boolean',
						'description' => __( 'Whether the deletion is permanent (true) or recoverable from the Trash (false). Mirrors force_used.', 'abilities-catalog-woo' ),
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
				'screen'       => 'edit-comments.php?comment_type=review',
			),
		);
	}

	/**
	 * Permission check: WooCommerce's delete capability for product reviews.
	 *
	 * Encodes the catalog capability for `wc-products/delete-product-review`: the
	 * `edit_products` capability, which is what
	 * `wc_rest_check_product_reviews_permissions( 'delete', $id )` resolves to on the
	 * wrapped `DELETE wc/v3/products/reviews/<id>` route. Note the asymmetry with the
	 * review READ abilities, which gate on `moderate_comments`: review DELETE is the
	 * stricter `edit_products`. Coarse and object-independent; the wrapped route surfaces
	 * the specific errors (`woocommerce_rest_review_invalid_id` 404 for a missing review,
	 * `woocommerce_rest_trash_not_supported` 501, `woocommerce_rest_already_trashed` 410)
	 * via {@see RestError::from()} instead of collapsing them to a permission denial. The
	 * explicit activity guard keeps the denial clean when WooCommerce is inactive and the
	 * cap is unmapped.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may delete product reviews.
	 */
	public function hasPermission( $input ): bool {
		return WooPlugin::isActive() && current_user_can( 'edit_products' );
	}

	/**
	 * Executes the ability by dispatching the internal `wc/v3` REST delete request.
	 *
	 * Pre-reads the review (GET) to capture its reviewer + product name and to surface a
	 * missing review as the route's 404, then DELETEs it, passing `force` through. Derives
	 * `deleted` from the non-error DELETE response because the trash path returns the review
	 * snapshot rather than a `{ deleted: true }` body.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The deleted flag, id, reviewer, product_name,
	 *                                        force_used, and permanent, or the REST error
	 *                                        (`woocommerce_rest_review_invalid_id` 404,
	 *                                        `woocommerce_rest_trash_not_supported` 501,
	 *                                        `woocommerce_rest_already_trashed` 410).
	 */
	public function execute( $input ) {
		if ( ! WooPlugin::isActive() ) {
			return WooPlugin::unavailable();
		}

		$input = is_array( $input ) ? $input : array();
		$id    = absint( $input['id'] ?? 0 );
		$force = array_key_exists( 'force', $input ) ? BooleanInput::sanitize( $input['force'] ) : false;

		// Capture the reviewer + product name before the row is gone; a missing review 404s here.
		$before = rest_do_request( new WP_REST_Request( 'GET', '/wc/v3/products/reviews/' . $id ) );
		if ( $before->is_error() ) {
			return RestError::from( $before );
		}

		$before_data  = rest_get_server()->response_to_data( $before, false );
		$before_data  = is_array( $before_data ) ? $before_data : array();
		$reviewer     = (string) ( $before_data['reviewer'] ?? '' );
		$product_name = (string) ( $before_data['product_name'] ?? '' );

		$request = new WP_REST_Request( 'DELETE', '/wc/v3/products/reviews/' . $id );
		$request->set_param( 'force', $force );

		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			return RestError::from( $response );
		}

		return array(
			'deleted'      => true,
			'id'           => $id,
			'reviewer'     => $reviewer,
			'product_name' => $product_name,
			'force_used'   => $force,
			'permanent'    => $force,
		);
	}
}
