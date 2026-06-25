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
 * Write ability: `og-wc-coupons/update-coupon`.
 *
 * Wraps `PUT wc/v3/coupons/<id>` via `rest_do_request()` and returns the updated
 * coupon shaped through {@see CouponListShaper::detail()} plus the wp-admin
 * `edit_link`. Send only the fields you want to change — all are optional except
 * `id`. A missing coupon surfaces the route's specific
 * `woocommerce_rest_shop_coupon_invalid_id` 400 via {@see RestError::from()}
 * (this UPDATE code differs from `og-wc-coupons/get-coupon`'s
 * `woocommerce_rest_invalid_shop_coupon_id`), so it is not masked as a permission
 * failure.
 *
 * Only available when WooCommerce is active AND the store has coupons enabled (the
 * `woocommerce_enable_coupons` option is `yes`); it is a {@see ConditionalAbility}
 * gated on {@see WooPlugin::hasCouponsEnabled()}. When coupons are disabled this
 * ability does not register, so it degrades cleanly (absent) rather than denying.
 *
 * @since 0.1.0
 */
final class UpdateCoupon implements ConditionalAbility {

	/**
	 * The writable coupon fields forwarded to the `wc/v3` PUT request, in input order.
	 *
	 * Each key matches a writable property in the wc/v3 coupons schema (V2
	 * controller `get_item_schema()`). `excluded_product_ids` keeps the REST field
	 * name (the shaper exposes it under `exclude_product_ids`, but the route accepts
	 * `excluded_product_ids`).
	 *
	 * @var array<int,string>
	 */
	private const WRITABLE_FIELDS = array(
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

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-wc-coupons/update-coupon';
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
			'label'               => __( 'Update Coupon', 'abilities-catalog-woo' ),
			'description'         => __( 'Updates an existing WooCommerce coupon by ID and returns the updated coupon (code, discount amount and type, expiry date, usage count and limit, product include/exclude lists, amount thresholds, description) plus its wp-admin edit_link. Send only the fields you want to change; every field except id is optional. Use og-wc-coupons/create-coupon to make a new coupon instead. Discover IDs with og-wc-coupons/list-coupons; an unknown id returns a woocommerce_rest_shop_coupon_invalid_id 400.', 'abilities-catalog-woo' ),
			'category'            => 'og-wc-coupons',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'id' ),
				'properties'           => array(
					'id'                   => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'The coupon ID to update. Discover IDs with og-wc-coupons/list-coupons.', 'abilities-catalog-woo' ),
					),
					'code'                 => array(
						'type'        => 'string',
						'description' => __( 'A new coupon code shoppers type at checkout. Omit to keep the current code.', 'abilities-catalog-woo' ),
					),
					'discount_type'        => array(
						'type'        => 'string',
						'enum'        => array( 'percent', 'fixed_cart', 'fixed_product' ),
						'description' => __( 'The discount type: "percent" (a percentage off), "fixed_cart" (a fixed amount off the cart), or "fixed_product" (a fixed amount off each matching product).', 'abilities-catalog-woo' ),
					),
					'amount'               => array(
						'type'        => 'string',
						'description' => __( 'The discount value as a decimal string, e.g. "10". A currency amount for fixed discounts, or a percentage for percent discounts.', 'abilities-catalog-woo' ),
					),
					'description'          => array(
						'type'        => 'string',
						'description' => __( 'The internal coupon description (not shown to shoppers).', 'abilities-catalog-woo' ),
					),
					'date_expires'         => array(
						'type'        => 'string',
						'description' => __( 'The expiry date as a YYYY-MM-DD or ISO 8601 date-time string in the site timezone; an empty string clears the expiry so the coupon never expires.', 'abilities-catalog-woo' ),
					),
					'individual_use'       => array(
						'type'        => 'boolean',
						'description' => __( 'Whether the coupon can only be used alone, removing other applied coupons from the cart.', 'abilities-catalog-woo' ),
					),
					'product_ids'          => array(
						'type'        => 'array',
						'description' => __( 'The product IDs the coupon can be used on. An empty array applies it to all products. Discover product IDs with og-wc-products/list-products.', 'abilities-catalog-woo' ),
						'items'       => array(
							'type' => 'integer',
						),
					),
					'excluded_product_ids' => array(
						'type'        => 'array',
						'description' => __( 'The product IDs the coupon cannot be used on. Discover product IDs with og-wc-products/list-products.', 'abilities-catalog-woo' ),
						'items'       => array(
							'type' => 'integer',
						),
					),
					'usage_limit'          => array(
						'type'        => 'integer',
						'minimum'     => 0,
						'description' => __( 'The total number of times the coupon may be used across all shoppers.', 'abilities-catalog-woo' ),
					),
					'minimum_amount'       => array(
						'type'        => 'string',
						'description' => __( 'The minimum order amount required before the coupon applies, as a decimal string; an empty string clears it.', 'abilities-catalog-woo' ),
					),
					'maximum_amount'       => array(
						'type'        => 'string',
						'description' => __( 'The maximum order amount allowed when using the coupon, as a decimal string; an empty string clears it.', 'abilities-catalog-woo' ),
					),
					'free_shipping'        => array(
						'type'        => 'boolean',
						'description' => __( 'Whether the coupon grants free shipping (only takes effect with a free-shipping method set to require a coupon).', 'abilities-catalog-woo' ),
					),
				),
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
	 * Permission check: WooCommerce's edit capability for coupons.
	 *
	 * Encodes the catalog capability for `og-wc-coupons/update-coupon`: the coarse,
	 * object-INDEPENDENT type-level cap `edit_shop_coupons` that
	 * `wc_rest_check_post_permissions( 'shop_coupon', 'edit' )` resolves to
	 * (`edit_posts` → `edit_shop_coupons`). The per-object decision is deferred to
	 * the wrapped route, which resolves the object-level `edit_shop_coupon`
	 * meta-cap and surfaces a missing coupon's specific
	 * `woocommerce_rest_shop_coupon_invalid_id` 400 via {@see RestError::from()}
	 * instead of collapsing to a generic permission denial. The explicit activity
	 * guard keeps the denial clean when WooCommerce is inactive.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may edit coupons.
	 */
	public function hasPermission( $input ): bool {
		return WooPlugin::isActive() && current_user_can( 'edit_shop_coupons' );
	}

	/**
	 * Executes the ability by dispatching the internal `wc/v3` REST update request.
	 *
	 * Forwards each writable field present in the input (by key presence, so an
	 * explicit `""` reaches the route to blank a field), dispatches the PUT, and
	 * returns the shaped updated coupon plus `edit_link`.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The shaped updated coupon, or the REST
	 *                                        error (e.g.
	 *                                        `woocommerce_rest_shop_coupon_invalid_id`
	 *                                        400).
	 */
	public function execute( $input ) {
		if ( ! WooPlugin::isActive() ) {
			return WooPlugin::unavailable();
		}

		$input = is_array( $input ) ? $input : array();
		$id    = absint( $input['id'] ?? 0 );

		$request = new WP_REST_Request( 'PUT', '/wc/v3/coupons/' . $id );

		foreach ( self::WRITABLE_FIELDS as $field ) {
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
	 * descriptions stay in sync with the coupon get/list abilities; this adds the
	 * `edit_link` the ability builds (the shaper makes no WordPress calls).
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
