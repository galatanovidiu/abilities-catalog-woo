<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogWoo\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shared writable JSON-Schema property fragments for the WooCommerce order write
 * abilities (batch 17, namespace `wc-orders`).
 *
 * `og-wc-orders/create-order` and `og-wc-orders/update-order` expose the SAME small
 * writable subset of the ~100-field `wc/v3` order schema. Centralizing those
 * fragments here keeps the two abilities in sync and pins the subset to fields
 * confirmed present and non-readonly in WC's own order REST controller
 * (class-wc-rest-orders-v2-controller.php). Each method returns a map of
 * property-name => JSON-Schema fragment; an ability merges it with its own
 * required keys (`id` on update) and sets `additionalProperties` on the
 * enclosing input object.
 *
 * Two deliberate shape choices, both source-verified:
 *
 * 1. The `status` property is NOT in {@see self::writableProperties()}. It is
 *    factored into {@see self::createStatusProperty()} and merged in by
 *    create-order ONLY. This is the structural lever that keeps `status` off
 *    update-order: changing an existing order's status is the separate elevated
 *    ability `og-wc-orders/update-order-status` (batch 23), because paid statuses
 *    fire stock changes and customer emails. Omitting `status` from the shared
 *    subset — not relying on copy alone — is what prevents update-order from
 *    accepting it.
 * 2. The `shipping` block has NO `email`/`phone` fields — the WC shipping
 *    address schema omits them (controller :1363-1414), unlike `billing`
 *    (controller :1300-1362), which carries both.
 *
 * Nested block objects (`billing`/`shipping`) and array item objects
 * (`line_items`/`shipping_lines`/`fee_lines`/`coupon_lines`/`meta_data`) close
 * with `additionalProperties => false` over the catalog's own writable subset,
 * so a caller passes only the fields the catalog drives. WC accepts JSON
 * booleans, so boolean fields pass through without coercion.
 *
 * This is NOT under `includes/Abilities/`, so the Registry never treats it as an
 * ability.
 *
 * @since 0.1.0
 */
final class OrderWriteSchema {

	/**
	 * The writable order input fields shared by create-order and update-order.
	 *
	 * Excludes `id` (update only, the route segment) AND `status` (create only,
	 * via {@see self::createStatusProperty()}). Every field is confirmed
	 * non-readonly in class-wc-rest-orders-v2-controller.php at the line cited on
	 * each entry.
	 *
	 * @return array<string,mixed> Property-name => JSON-Schema fragment.
	 */
	public static function writableProperties(): array {
		return array(
			'customer_id'          => array(
				'type'        => 'integer',
				'description' => __( 'The WordPress user/customer ID who owns the order; 0 for a guest. Discover customer IDs with og-wc-customers/list-customers.', 'abilities-catalog-woo' ),
			),
			'customer_note'        => array(
				'type'        => 'string',
				'description' => __( 'A note left by the customer during checkout, stored on the order.', 'abilities-catalog-woo' ),
			),
			'billing'              => self::billingSchema(),
			'shipping'             => self::shippingSchema(),
			'line_items'           => array(
				'type'        => 'array',
				'description' => __( 'The products on the order. Each item identifies a product (and optional variation) and a quantity. Discover product_id with og-wc-products/list-products and variation_id with og-wc-products/list-product-variations.', 'abilities-catalog-woo' ),
				'items'       => self::lineItemSchema(),
			),
			'shipping_lines'       => array(
				'type'        => 'array',
				'description' => __( 'The shipping charges on the order, each a method ID, a display title, and a total as a decimal string.', 'abilities-catalog-woo' ),
				'items'       => self::shippingLineSchema(),
			),
			'fee_lines'            => array(
				'type'        => 'array',
				'description' => __( 'Arbitrary fee lines on the order, each a name and a total as a decimal string.', 'abilities-catalog-woo' ),
				'items'       => self::feeLineSchema(),
			),
			'coupon_lines'         => array(
				'type'        => 'array',
				'description' => __( 'The coupons applied to the order, each given by its code. WooCommerce recomputes the discount from the coupon.', 'abilities-catalog-woo' ),
				'items'       => self::couponLineSchema(),
			),
			'payment_method'       => array(
				'type'        => 'string',
				'description' => __( 'The payment method ID, e.g. "bacs" or "cod".', 'abilities-catalog-woo' ),
			),
			'payment_method_title' => array(
				'type'        => 'string',
				'description' => __( 'The payment method title shown to the buyer, e.g. "Direct bank transfer".', 'abilities-catalog-woo' ),
			),
			'set_paid'             => array(
				'type'        => 'boolean',
				'default'     => false,
				'description' => __( 'WARNING: SIDE EFFECTS. When true, WooCommerce marks the order paid and fires payment_complete, which SETS THE STATUS TO PROCESSING, REDUCES STOCK, and SENDS THE PAID-ORDER CUSTOMER EMAILS (WooCommerce: "Define if the order is paid. It will set the status to processing and reduce stock items."). Leave false (the default) for an unpaid order; set true only when you intend those effects to fire.', 'abilities-catalog-woo' ),
			),
			'meta_data'            => array(
				'type'        => 'array',
				'description' => __( 'Custom metadata to store on the order, each a key/value pair. Use this for integration data the catalog does not model directly.', 'abilities-catalog-woo' ),
				'items'       => self::metaDataSchema(),
			),
		);
	}

	/**
	 * The `status` property fragment, merged in by create-order ONLY.
	 *
	 * Kept OUT of {@see self::writableProperties()} on purpose so update-order
	 * cannot accept it: changing an existing order's status is the separate
	 * elevated ability `og-wc-orders/update-order-status` (batch 23). The enum is the
	 * standard WooCommerce order-status set; the route's own enum
	 * (`get_order_statuses()`, controller :1135-1143, :1195) is `auto-draft` plus
	 * the `wc-`-stripped keys of `wc_get_order_statuses()`, so a site that
	 * registers custom statuses adds them — this fragment declares the standard
	 * set and the route accepts any registered status.
	 *
	 * @return array<string,mixed> A JSON-Schema property fragment.
	 */
	public static function createStatusProperty(): array {
		return array(
			'type'        => 'string',
			'enum'        => array( 'pending', 'processing', 'on-hold', 'completed', 'cancelled', 'refunded', 'failed' ),
			'default'     => 'pending',
			'description' => __( 'The order\'s initial status; defaults to pending. WARNING: the paid statuses (processing and completed) fire stock reduction and the paid-order customer emails, the same way set_paid does. Use pending or on-hold for an unpaid order. This sets the INITIAL status only; to change an existing order\'s status use og-wc-orders/update-order-status.', 'abilities-catalog-woo' ),
		);
	}

	/**
	 * The `billing` address block (controller :1300-1362).
	 *
	 * Closed over the catalog's writable subset, which matches WC's billing
	 * properties: name, company, two address lines, city/state/postcode/country,
	 * and (unlike shipping) email and phone.
	 *
	 * @return array<string,mixed> A JSON-Schema object fragment.
	 */
	private static function billingSchema(): array {
		return array(
			'type'                 => 'object',
			'description'          => __( 'The billing address. Send only the fields you set; the WooCommerce billing address carries email and phone (the shipping address does not).', 'abilities-catalog-woo' ),
			'properties'           => array(
				'first_name' => array(
					'type'        => 'string',
					'description' => __( 'Billing first name.', 'abilities-catalog-woo' ),
				),
				'last_name'  => array(
					'type'        => 'string',
					'description' => __( 'Billing last name.', 'abilities-catalog-woo' ),
				),
				'company'    => array(
					'type'        => 'string',
					'description' => __( 'Billing company name.', 'abilities-catalog-woo' ),
				),
				'address_1'  => array(
					'type'        => 'string',
					'description' => __( 'Billing street address line 1.', 'abilities-catalog-woo' ),
				),
				'address_2'  => array(
					'type'        => 'string',
					'description' => __( 'Billing street address line 2.', 'abilities-catalog-woo' ),
				),
				'city'       => array(
					'type'        => 'string',
					'description' => __( 'Billing city.', 'abilities-catalog-woo' ),
				),
				'state'      => array(
					'type'        => 'string',
					'description' => __( 'Billing state, province, or district as an ISO code or name.', 'abilities-catalog-woo' ),
				),
				'postcode'   => array(
					'type'        => 'string',
					'description' => __( 'Billing postal code.', 'abilities-catalog-woo' ),
				),
				'country'    => array(
					'type'        => 'string',
					'description' => __( 'Billing country code in ISO 3166-1 alpha-2 format, e.g. US.', 'abilities-catalog-woo' ),
				),
				'email'      => array(
					'type'        => 'string',
					'format'      => 'email',
					'description' => __( 'Billing email address.', 'abilities-catalog-woo' ),
				),
				'phone'      => array(
					'type'        => 'string',
					'description' => __( 'Billing phone number.', 'abilities-catalog-woo' ),
				),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * The `shipping` address block (controller :1363-1414).
	 *
	 * The same fields as billing EXCEPT email and phone — the WC shipping address
	 * schema omits both.
	 *
	 * @return array<string,mixed> A JSON-Schema object fragment.
	 */
	private static function shippingSchema(): array {
		return array(
			'type'                 => 'object',
			'description'          => __( 'The shipping address. Send only the fields you set. The WooCommerce shipping address has no email or phone field.', 'abilities-catalog-woo' ),
			'properties'           => array(
				'first_name' => array(
					'type'        => 'string',
					'description' => __( 'Shipping first name.', 'abilities-catalog-woo' ),
				),
				'last_name'  => array(
					'type'        => 'string',
					'description' => __( 'Shipping last name.', 'abilities-catalog-woo' ),
				),
				'company'    => array(
					'type'        => 'string',
					'description' => __( 'Shipping company name.', 'abilities-catalog-woo' ),
				),
				'address_1'  => array(
					'type'        => 'string',
					'description' => __( 'Shipping street address line 1.', 'abilities-catalog-woo' ),
				),
				'address_2'  => array(
					'type'        => 'string',
					'description' => __( 'Shipping street address line 2.', 'abilities-catalog-woo' ),
				),
				'city'       => array(
					'type'        => 'string',
					'description' => __( 'Shipping city.', 'abilities-catalog-woo' ),
				),
				'state'      => array(
					'type'        => 'string',
					'description' => __( 'Shipping state, province, or district as an ISO code or name.', 'abilities-catalog-woo' ),
				),
				'postcode'   => array(
					'type'        => 'string',
					'description' => __( 'Shipping postal code.', 'abilities-catalog-woo' ),
				),
				'country'    => array(
					'type'        => 'string',
					'description' => __( 'Shipping country code in ISO 3166-1 alpha-2 format, e.g. US.', 'abilities-catalog-woo' ),
				),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * A `line_items` item object: `{ product_id, quantity, variation_id? }`
	 * (controller :1489-1545).
	 *
	 * `variation_id` is optional and used only for a variable product's variation.
	 *
	 * @return array<string,mixed> A JSON-Schema object fragment.
	 */
	private static function lineItemSchema(): array {
		return array(
			'type'                 => 'object',
			'required'             => array( 'product_id', 'quantity' ),
			'properties'           => array(
				'product_id'   => array(
					'type'        => 'integer',
					'description' => __( 'The product ID to add to the order. Discover with og-wc-products/list-products.', 'abilities-catalog-woo' ),
				),
				'quantity'     => array(
					'type'        => 'integer',
					'description' => __( 'The quantity of this product on the order line.', 'abilities-catalog-woo' ),
				),
				'variation_id' => array(
					'type'        => 'integer',
					'description' => __( 'The variation ID, for a variable product. Discover with og-wc-products/list-product-variations.', 'abilities-catalog-woo' ),
				),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * A `shipping_lines` item object: `{ method_id, method_title, total }`
	 * (controller :1735-1797).
	 *
	 * @return array<string,mixed> A JSON-Schema object fragment.
	 */
	private static function shippingLineSchema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'method_id'    => array(
					'type'        => 'string',
					'description' => __( 'The shipping method ID, e.g. "flat_rate".', 'abilities-catalog-woo' ),
				),
				'method_title' => array(
					'type'        => 'string',
					'description' => __( 'The shipping method title shown to the buyer, e.g. "Flat rate".', 'abilities-catalog-woo' ),
				),
				'total'        => array(
					'type'        => 'string',
					'description' => __( 'The shipping total excluding tax, as a decimal string, e.g. "5.00".', 'abilities-catalog-woo' ),
				),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * A `fee_lines` item object: `{ name, total }` (controller :1826-1895).
	 *
	 * @return array<string,mixed> A JSON-Schema object fragment.
	 */
	private static function feeLineSchema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'name'  => array(
					'type'        => 'string',
					'description' => __( 'The fee name shown on the order, e.g. "Handling".', 'abilities-catalog-woo' ),
				),
				'total' => array(
					'type'        => 'string',
					'description' => __( 'The fee total excluding tax, as a decimal string, e.g. "2.50".', 'abilities-catalog-woo' ),
				),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * A `coupon_lines` item object: `{ code }` (controller :1924-1971).
	 *
	 * @return array<string,mixed> A JSON-Schema object fragment.
	 */
	private static function couponLineSchema(): array {
		return array(
			'type'                 => 'object',
			'required'             => array( 'code' ),
			'properties'           => array(
				'code' => array(
					'type'        => 'string',
					'description' => __( 'The coupon code to apply. WooCommerce recomputes the discount from the coupon.', 'abilities-catalog-woo' ),
				),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * A `meta_data` item object: `{ key, value }` (controller :1463-1488).
	 *
	 * The route's `id` sub-field is read-only, so it is omitted; the object is
	 * closed to the writable `key`/`value` pair.
	 *
	 * @return array<string,mixed> A JSON-Schema object fragment.
	 */
	private static function metaDataSchema(): array {
		return array(
			'type'                 => 'object',
			'required'             => array( 'key', 'value' ),
			'properties'           => array(
				'key'   => array(
					'type'        => 'string',
					'description' => __( 'The meta key.', 'abilities-catalog-woo' ),
				),
				'value' => array(
					'description' => __( 'The meta value. A string, number, boolean, or array.', 'abilities-catalog-woo' ),
				),
			),
			'additionalProperties' => false,
		);
	}
}
