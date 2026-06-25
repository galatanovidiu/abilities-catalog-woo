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
 * Write ability: `wc-coupons/create-coupon`.
 *
 * Wraps `POST wc/v3/coupons` via `rest_do_request()`, creating a new WooCommerce
 * coupon and returning the same flat, closed record `wc-coupons/get-coupon`
 * returns (code, amount, discount_type, expiry, usage count/limit, individual_use,
 * description, product include/exclude ID lists, the order-amount thresholds) plus
 * the wp-admin `edit_link`. The created object is shaped through
 * {@see CouponListShaper}, never returned raw.
 *
 * Only the writable subset is exposed as input; the route persists the coupon on a
 * plain POST dispatch (its `create_item` callback calls `$object->save()`
 * unconditionally), so no save-context param is set. The required `code` is the
 * coupon code shoppers type at checkout; a duplicate code is rejected by the route
 * with `woocommerce_rest_coupon_code_already_exists` (400), surfaced verbatim via
 * {@see RestError::from()}.
 *
 * Only available when WooCommerce is active AND the store has coupons enabled (the
 * `woocommerce_enable_coupons` option is `yes`); it is a {@see ConditionalAbility}
 * gated on {@see WooPlugin::hasCouponsEnabled()}. When coupons are disabled this
 * ability does not register, so it degrades cleanly (absent) rather than denying.
 *
 * @since 0.1.0
 */
final class CreateCoupon implements ConditionalAbility {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'wc-coupons/create-coupon';
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
			'label'               => __( 'Create Coupon', 'abilities-catalog-woo' ),
			'description'         => __( 'Creates a WooCommerce coupon and returns the shaped coupon (code, discount amount and type, expiry date, usage count and limit, individual_use, product include/exclude ID lists, the minimum/maximum order-amount thresholds, the description) plus the wp-admin edit_link. The required code is the coupon code shoppers type at checkout. Use this to add a new coupon; to change an existing one use wc-coupons/update-coupon instead. After creating, surface edit_link so a human can review the coupon.', 'abilities-catalog-woo' ),
			'category'            => 'wc-coupons',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'code' ),
				'properties'           => $this->writableProperties( true ),
				'additionalProperties' => false,
			),
			'output_schema'       => $this->outputSchema(),
			'execute_callback'    => array( $this, 'execute' ),
			'permission_callback' => array( $this, 'hasPermission' ),
			'meta'                => array(
				'annotations'  => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => false,
				),
				'show_in_rest' => true,
				'screen'       => 'post.php?post={id}&action=edit',
			),
		);
	}

	/**
	 * Permission check: WooCommerce's publish capability for coupons.
	 *
	 * Mirrors `wc_rest_check_post_permissions( 'shop_coupon', 'create' )`, which
	 * resolves the `create` context to the `shop_coupon` post type's publish cap —
	 * `publish_shop_coupons` — the baseline the wrapped `wc/v3` POST route enforces.
	 * This is a coarse, object-INDEPENDENT type-level guard; the wrapped route
	 * surfaces the schema's own 400 (e.g. a bad `discount_type` enum, an empty or
	 * duplicate code) so an input error is not masked as a permission denial. The
	 * explicit activity guard keeps the denial clean when WooCommerce is inactive.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may create coupons.
	 */
	public function hasPermission( $input ): bool {
		return WooPlugin::isActive() && current_user_can( 'publish_shop_coupons' );
	}

	/**
	 * Executes the ability by dispatching the internal `wc/v3` REST create request.
	 *
	 * Forwards only the present writable params (skipping absent keys), dispatches a
	 * plain POST (the route persists on save without a save-context param), and
	 * shapes the created coupon through {@see CouponListShaper::detail()} plus the
	 * `edit_link`.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The shaped created coupon, or the REST
	 *                                        error (e.g.
	 *                                        `woocommerce_rest_coupon_code_already_exists`
	 *                                        400, `rest_invalid_param` 400).
	 */
	public function execute( $input ) {
		if ( ! WooPlugin::isActive() ) {
			return WooPlugin::unavailable();
		}

		$input = is_array( $input ) ? $input : array();

		$request = new WP_REST_Request( 'POST', '/wc/v3/coupons' );

		foreach ( $this->writableFields() as $field ) {
			if ( ! array_key_exists( $field, $input ) ) {
				continue;
			}

			$request->set_param( $field, $input[ $field ] );
		}

		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			return RestError::from( $response );
		}

		$data = rest_get_server()->response_to_data( $response, false );
		$data = is_array( $data ) ? $data : array();

		$coupon_id = (int) ( $data['id'] ?? 0 );

		return array_merge(
			CouponListShaper::detail( $data ),
			array(
				'edit_link' => admin_url( 'post.php?post=' . $coupon_id . '&action=edit' ),
			)
		);
	}

	/**
	 * The writable coupon fields forwarded to the `wc/v3` request, in input order.
	 *
	 * @return list<string> The writable field names.
	 */
	private function writableFields(): array {
		return array(
			'code',
			'discount_type',
			'amount',
			'description',
			'date_expires',
			'individual_use',
			'product_ids',
			'excluded_product_ids',
			'usage_limit',
			'minimum_amount',
			'maximum_amount',
			'free_shipping',
		);
	}

	/**
	 * The input-schema properties for the writable coupon subset.
	 *
	 * The names and the `discount_type` enum match the `wc/v3` coupons
	 * `get_item_schema`. `amount`, `minimum_amount`, and `maximum_amount` are decimal
	 * strings in `wc/v3`. When `$require_code` is true the `code` description marks it
	 * required (create); on update it is optional and the caller sends only changes.
	 *
	 * @param bool $require_code Whether `code` is required (create) or optional (update).
	 * @return array<string,array<string,mixed>> The JSON-Schema property map.
	 */
	private function writableProperties( bool $require_code ): array {
		return array(
			'code'                 => array(
				'type'        => 'string',
				'description' => $require_code
					? __( 'The coupon code shoppers type at checkout (case-insensitive). Required.', 'abilities-catalog-woo' )
					: __( 'The coupon code shoppers type at checkout (case-insensitive).', 'abilities-catalog-woo' ),
			),
			'discount_type'        => array(
				'type'        => 'string',
				'enum'        => array( 'percent', 'fixed_cart', 'fixed_product' ),
				'default'     => 'fixed_cart',
				'description' => __( 'The discount type: "percent" (a percentage off the cart), "fixed_cart" (a fixed amount off the whole cart), or "fixed_product" (a fixed amount off each matching product). Defaults to "fixed_cart".', 'abilities-catalog-woo' ),
			),
			'amount'               => array(
				'type'        => 'string',
				'description' => __( 'The discount value as a decimal string: a percentage for "percent" (e.g. "10" = 10% off), a currency amount otherwise (e.g. "5.00").', 'abilities-catalog-woo' ),
			),
			'description'          => array(
				'type'        => 'string',
				'description' => __( 'An internal description for the coupon, not shown to shoppers.', 'abilities-catalog-woo' ),
			),
			'date_expires'         => array(
				'type'        => 'string',
				'format'      => 'date-time',
				'description' => __( 'The expiry date in the site timezone, as a date (e.g. 2024-12-31) or an ISO 8601 date-time string (e.g. 2024-12-31T23:59:59). Omit for a coupon that never expires.', 'abilities-catalog-woo' ),
			),
			'individual_use'       => array(
				'type'        => 'boolean',
				'default'     => false,
				'description' => __( 'If true, the coupon can only be used alone and removes any other coupons applied to the cart.', 'abilities-catalog-woo' ),
			),
			'product_ids'          => array(
				'type'        => 'array',
				'items'       => array( 'type' => 'integer' ),
				'description' => __( 'Product IDs the coupon applies to. Empty/omitted means the coupon applies to all products. Discover product IDs with wc-products/list-products.', 'abilities-catalog-woo' ),
			),
			'excluded_product_ids' => array(
				'type'        => 'array',
				'items'       => array( 'type' => 'integer' ),
				'description' => __( 'Product IDs the coupon cannot be used on. Discover product IDs with wc-products/list-products.', 'abilities-catalog-woo' ),
			),
			'usage_limit'          => array(
				'type'        => 'integer',
				'minimum'     => 0,
				'description' => __( 'How many times the coupon can be used in total. Omit for unlimited use.', 'abilities-catalog-woo' ),
			),
			'minimum_amount'       => array(
				'type'        => 'string',
				'description' => __( 'The minimum order amount required before the coupon applies, as a decimal string. Omit for no minimum.', 'abilities-catalog-woo' ),
			),
			'maximum_amount'       => array(
				'type'        => 'string',
				'description' => __( 'The maximum order amount allowed when using the coupon, as a decimal string. Omit for no maximum.', 'abilities-catalog-woo' ),
			),
			'free_shipping'        => array(
				'type'        => 'boolean',
				'default'     => false,
				'description' => __( 'If true, the coupon grants free shipping (only when a free-shipping method requires a coupon).', 'abilities-catalog-woo' ),
			),
		);
	}

	/**
	 * Builds the closed output schema: the coupon detail fields plus `edit_link`.
	 *
	 * Derived from {@see CouponListShaper::detailSchema()} so the field descriptions
	 * stay in sync with the coupon read abilities; this adds the `edit_link` the
	 * ability builds (the shaper makes no WordPress calls). The shaper's closed
	 * subset never includes a coupon password or secret, so the result carries no
	 * credential field.
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
