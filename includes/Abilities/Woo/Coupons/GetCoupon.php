<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogWoo\Abilities\Woo\Coupons;

use GalatanOvidiu\AbilitiesCatalogWoo\Contracts\ConditionalAbility;
use GalatanOvidiu\AbilitiesCatalogWoo\Support\CouponListShaper;
use GalatanOvidiu\AbilitiesCatalogWoo\Support\RestError;
use GalatanOvidiu\AbilitiesCatalogWoo\Support\WooPlugin;
use WP_REST_Request;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read ability: `og-wc-coupons/get-coupon`.
 *
 * Wraps `GET wc/v3/coupons/<id>` via `rest_do_request()` and returns one coupon
 * as a flat, closed record: the summary fields (code, amount, discount_type,
 * date_expires, usage_count, usage_limit, individual_use) plus the coupon
 * description, the product include/exclude ID lists, the order-amount thresholds,
 * and the `edit_link` a human can open to edit the coupon. The raw `wc/v3` body
 * carries usage/email restrictions and `_links` a consumer never reads; this
 * projects only the useful subset via {@see CouponListShaper}.
 *
 * Only available when WooCommerce is active AND the store has coupons enabled (the
 * `woocommerce_enable_coupons` option is `yes`); it is a {@see ConditionalAbility}
 * gated on {@see WooPlugin::hasCouponsEnabled()}. When coupons are disabled this
 * ability does not register, so it degrades cleanly (absent) rather than denying.
 *
 * @since 0.1.0
 */
final class GetCoupon implements ConditionalAbility {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-wc-coupons/get-coupon';
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
			'label'               => __( 'Get Coupon', 'abilities-catalog-woo' ),
			'description'         => __( 'Returns one WooCommerce coupon by ID: its code, discount amount and type, expiry date, usage count and limit, the product include/exclude ID lists, the minimum and maximum order-amount thresholds, the description, and the wp-admin edit_link. Use this for one coupon\'s full configuration; discover IDs with og-wc-coupons/list-coupons.', 'abilities-catalog-woo' ),
			'category'            => 'og-wc-coupons',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'id' ),
				'properties'           => array(
					'id' => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'The coupon ID. Discover IDs with og-wc-coupons/list-coupons.', 'abilities-catalog-woo' ),
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => $this->outputSchema(),
			'execute_callback'    => array( $this, 'execute' ),
			'permission_callback' => array( $this, 'hasPermission' ),
			'meta'                => array(
				'annotations'  => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
				'show_in_rest' => true,
			),
		);
	}

	/**
	 * Permission check: WooCommerce's read capability for coupons.
	 *
	 * Mirrors `wc_rest_check_post_permissions( 'shop_coupon', 'read' )`, which
	 * resolves the `read` context to the `shop_coupon` post type's
	 * `read_private_posts` cap — `read_private_shop_coupons` — the baseline the
	 * wrapped `wc/v3` GET route enforces. This is a coarse, object-INDEPENDENT
	 * type-level guard: the per-object decision is deferred to the wrapped route,
	 * so a missing coupon surfaces its specific
	 * `woocommerce_rest_shop_coupon_invalid_id` 404 via {@see RestError::from()}
	 * instead of collapsing to a generic permission denial. The explicit activity
	 * guard keeps the denial clean when WooCommerce is inactive.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may read coupons.
	 */
	public function hasPermission( $input ): bool {
		return WooPlugin::isActive() && current_user_can( 'read_private_shop_coupons' );
	}

	/**
	 * Executes the ability by dispatching the internal `wc/v3` REST request.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The shaped coupon record, or the REST
	 *                                        error (e.g.
	 *                                        `woocommerce_rest_shop_coupon_invalid_id`
	 *                                        404).
	 */
	public function execute( $input ) {
		if ( ! WooPlugin::isActive() ) {
			return WooPlugin::unavailable();
		}

		$input = is_array( $input ) ? $input : array();
		$id    = absint( $input['id'] ?? 0 );

		$request  = new WP_REST_Request( 'GET', '/wc/v3/coupons/' . $id );
		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			return RestError::from( $response );
		}

		$data = rest_get_server()->response_to_data( $response, false );
		$data = is_array( $data ) ? $data : array();

		$coupon_id = (int) ( $data['id'] ?? $id );

		return array_merge(
			CouponListShaper::detail( $data ),
			array(
				'edit_link' => admin_url( 'post.php?post=' . $coupon_id . '&action=edit' ),
			)
		);
	}

	/**
	 * Builds the closed output schema: the coupon detail fields plus `edit_link`.
	 *
	 * Derived from {@see CouponListShaper::detailSchema()} so the field
	 * descriptions stay in sync with the coupon list ability; this adds the
	 * `edit_link` the ability builds (the shaper does not, since it makes no
	 * WordPress calls).
	 *
	 * @return array<string,mixed> A JSON-Schema object fragment.
	 */
	private function outputSchema(): array {
		$schema = CouponListShaper::detailSchema();

		$schema['properties']['edit_link'] = array(
			'type'        => 'string',
			'description' => __( 'The wp-admin URL to edit the coupon. Surface this so a human can review the coupon.', 'abilities-catalog-woo' ),
		);

		return $schema;
	}
}
