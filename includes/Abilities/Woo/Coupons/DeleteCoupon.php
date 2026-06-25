<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogWoo\Abilities\Woo\Coupons;

use GalatanOvidiu\AbilitiesCatalogWoo\Contracts\ConditionalAbility;
use GalatanOvidiu\AbilitiesCatalogWoo\Support\BooleanInput;
use GalatanOvidiu\AbilitiesCatalogWoo\Support\RestError;
use GalatanOvidiu\AbilitiesCatalogWoo\Support\WooPlugin;
use WP_REST_Request;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Destructive write ability: `wc-coupons/delete-coupon`.
 *
 * Wraps `DELETE wc/v3/coupons/<id>` via `rest_do_request()`. The coupon post type
 * (`shop_coupon`) supports Trash, so the wrapped route exposes a `force` flag:
 * `force=false` (the default) moves the coupon to the wp-admin Trash, recoverable;
 * `force=true` deletes it permanently and irreversibly. Either way the discount
 * code stops working at checkout the moment the coupon leaves the published set.
 *
 * Before deleting, this reads the coupon's `code` so the result can confirm which
 * coupon was removed, and so a missing coupon returns the route's 404
 * (`woocommerce_rest_shop_coupon_invalid_id`) here rather than after the delete.
 *
 * The `wc/v3` DELETE response is the prepared coupon object — it carries no
 * `deleted` field — so the ability synthesizes `deleted => true` from a non-error
 * response. No `edit_link` is returned: the coupon is gone or in the Trash, so a
 * delete must not return a dead-end edit link.
 *
 * Only available when WooCommerce is active AND the store has coupons enabled (the
 * `woocommerce_enable_coupons` option is `yes`); it is a {@see ConditionalAbility}
 * gated on {@see WooPlugin::hasCouponsEnabled()}. When coupons are disabled this
 * ability does not register, so it degrades cleanly (absent) rather than denying.
 *
 * @since 0.1.0
 */
final class DeleteCoupon implements ConditionalAbility {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'wc-coupons/delete-coupon';
	}

	/**
	 * {@inheritDoc}
	 */
	public function isAvailable(): bool {
		return WooPlugin::isActive() && WooPlugin::hasCouponsEnabled();
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return array(
			'label'               => __( 'Delete Coupon', 'abilities-catalog-woo' ),
			'description'         => __( 'Deletes a WooCommerce coupon by ID and returns the deleted coupon\'s code for confirmation. By default (force=false) the coupon is moved to the wp-admin Trash and can be restored; set force=true to delete it permanently and irreversibly. Either way the discount code stops working at checkout immediately. The result reports permanent: true when the coupon was force-deleted (gone forever) and permanent: false when it was only trashed (restorable from wp-admin). No edit_link is returned because the coupon no longer exists or sits in the Trash. Discover IDs with wc-coupons/list-coupons.', 'abilities-catalog-woo' ),
			'category'            => 'wc-coupons',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'id' ),
				'properties'           => array(
					'id'    => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'The coupon ID to delete. Discover IDs with wc-coupons/list-coupons.', 'abilities-catalog-woo' ),
					),
					'force' => array(
						'type'        => 'boolean',
						'default'     => false,
						'description' => __( 'If false (the default), the coupon is moved to the Trash and can be restored from wp-admin. If true, the coupon is deleted permanently and cannot be recovered. Deleting the coupon stops its code working at checkout in both cases.', 'abilities-catalog-woo' ),
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
						'description' => __( 'Whether the coupon was deleted (true on a non-error delete response).', 'abilities-catalog-woo' ),
					),
					'id'         => array(
						'type'        => 'integer',
						'description' => __( 'The deleted coupon\'s ID.', 'abilities-catalog-woo' ),
					),
					'code'       => array(
						'type'        => 'string',
						'description' => __( 'The deleted coupon\'s code, captured before deletion so a human can confirm which coupon was removed.', 'abilities-catalog-woo' ),
					),
					'force_used' => array(
						'type'        => 'boolean',
						'description' => __( 'Whether a permanent delete ran: true when force=true was sent (the coupon is gone forever), false when the coupon was only moved to the Trash.', 'abilities-catalog-woo' ),
					),
					'permanent'  => array(
						'type'        => 'boolean',
						'description' => __( 'Whether the coupon is now gone for good. true means it was permanently deleted and cannot be recovered; false means it sits in the wp-admin Trash and can be restored.', 'abilities-catalog-woo' ),
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
				'screen'       => 'edit.php?post_type=shop_coupon',
			),
		);
	}

	/**
	 * Permission check: WooCommerce's delete capability for coupons.
	 *
	 * Encodes the catalog capability for `wc-coupons/delete-coupon`: the primitive
	 * `delete_shop_coupons` cap the wrapped `wc/v3` DELETE route resolves the
	 * `'delete'` context to (`wc_rest_check_post_permissions( 'shop_coupon',
	 * 'delete' )` maps `'delete' => 'delete_post'`, which WP core resolves through
	 * the `shop_coupon` post type to `delete_shop_coupons`). Coarse and
	 * object-INDEPENDENT: the wrapped route surfaces the specific 404
	 * (`woocommerce_rest_shop_coupon_invalid_id`) for a missing coupon and the 410
	 * (`woocommerce_rest_already_trashed`) for an already-trashed coupon, so those
	 * are not masked as a permission denial.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may delete coupons.
	 */
	public function hasPermission( $input ): bool {
		return WooPlugin::isActive() && current_user_can( 'delete_shop_coupons' );
	}

	/**
	 * Executes the ability by dispatching the internal `wc/v3` REST delete request.
	 *
	 * Reads the coupon's `code` first (so a missing coupon 404s here and the result
	 * names what was removed), then dispatches the DELETE with the resolved `force`
	 * flag. The DELETE response is the prepared coupon object with no `deleted`
	 * field, so `deleted => true` is synthesized from a non-error response.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The deleted flag, id, code, and force/
	 *                                        permanent flags, or the REST error (e.g.
	 *                                        `woocommerce_rest_shop_coupon_invalid_id`
	 *                                        404).
	 */
	public function execute( $input ) {
		if ( ! WooPlugin::isActive() ) {
			return WooPlugin::unavailable();
		}

		$input = is_array( $input ) ? $input : array();
		$id    = absint( $input['id'] ?? 0 );
		$force = BooleanInput::sanitize( $input['force'] ?? false );

		// Capture the code before the coupon is gone; a missing coupon 404s here.
		$before = rest_do_request( new WP_REST_Request( 'GET', '/wc/v3/coupons/' . $id ) );
		if ( $before->is_error() ) {
			return RestError::from( $before );
		}

		$before_data = rest_get_server()->response_to_data( $before, false );
		$code        = is_array( $before_data ) ? (string) ( $before_data['code'] ?? '' ) : '';

		$request = new WP_REST_Request( 'DELETE', '/wc/v3/coupons/' . $id );
		$request->set_param( 'force', $force );

		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			return RestError::from( $response );
		}

		return array(
			'deleted'    => true,
			'id'         => $id,
			'code'       => $code,
			'force_used' => $force,
			'permanent'  => $force,
		);
	}
}
