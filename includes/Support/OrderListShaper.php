<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogWoo\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Projects raw `wc/v3` order rows into flat, closed rows for the catalog's order
 * list and get-order abilities.
 *
 * A `GET wc/v3/orders` row carries dozens of nested fields — full billing and
 * shipping blocks, meta, tax lines, coupon lines, refunds, the customer's IP and
 * user agent — most of which a consumer never reads. This shaper exposes two
 * shapes: {@see self::summary()} copies the small fixed field set a consumer
 * needs to scan a list of orders, and {@see self::detail()} adds the line items
 * and the trimmed billing/shipping blocks a consumer needs to read one order in
 * full. Each value is cast to the type the WC orders schema promises (amounts are
 * STRINGS in wc/v3). {@see self::itemSchema()} and {@see self::detailSchema()}
 * pin the rows closed so the runtime row and the declared schema cannot drift.
 *
 * PII: this shaper exposes ONLY a fixed subset of personal data. The summary row
 * adds `billing_first_name`, `billing_last_name`, and `billing_email`. The detail
 * row adds a billing block (first_name, last_name, email, address_1, city, state,
 * postcode, country) and a shipping block (the same fields EXCEPT email — the WC
 * shipping address has no email). It NEVER exposes `meta_data`, the customer's IP
 * or user agent, phone numbers, company names, address line 2, or the raw order
 * object. These reads are an admin tool whose hard guard is the capability the
 * order abilities check; the subset here is the minimum a consumer needs to read,
 * contact, and fulfil an order.
 *
 * This is NOT under `includes/Abilities/`, so the Registry never treats it as an
 * ability. It performs no WooCommerce calls and holds no ability logic; it only
 * shapes rows and declares their schema.
 *
 * @since 0.1.0
 */
final class OrderListShaper {

	/**
	 * Flat summary row for a single `wc/v3` order list item.
	 *
	 * Each value is read with a null-coalescing default and cast to the type the
	 * WC orders schema guarantees. The reviewer-facing name and email are read
	 * from the nested `billing` block and flattened. `line_items_count` is the
	 * number of line items on the order, derived from the row.
	 *
	 * @param array<string,mixed> $row A single row from a `GET wc/v3/orders` response.
	 * @return array{
	 *     id:int,
	 *     number:string,
	 *     status:string,
	 *     currency:string,
	 *     total:string,
	 *     total_tax:string,
	 *     date_created:string,
	 *     customer_id:int,
	 *     billing_first_name:string,
	 *     billing_last_name:string,
	 *     billing_email:string,
	 *     payment_method_title:string,
	 *     line_items_count:int
	 * } The flat summary row.
	 */
	public static function summary( array $row ): array {
		$billing    = (array) ( $row['billing'] ?? array() );
		$line_items = (array) ( $row['line_items'] ?? array() );

		return array(
			'id'                   => (int) ( $row['id'] ?? 0 ),
			'number'               => (string) ( $row['number'] ?? '' ),
			'status'               => (string) ( $row['status'] ?? '' ),
			'currency'             => (string) ( $row['currency'] ?? '' ),
			'total'                => (string) ( $row['total'] ?? '' ),
			'total_tax'            => (string) ( $row['total_tax'] ?? '' ),
			'date_created'         => (string) ( $row['date_created'] ?? '' ),
			'customer_id'          => (int) ( $row['customer_id'] ?? 0 ),
			'billing_first_name'   => (string) ( $billing['first_name'] ?? '' ),
			'billing_last_name'    => (string) ( $billing['last_name'] ?? '' ),
			'billing_email'        => (string) ( $billing['email'] ?? '' ),
			'payment_method_title' => (string) ( $row['payment_method_title'] ?? '' ),
			'line_items_count'     => count( $line_items ),
		);
	}

	/**
	 * The `output_schema` item definition matching {@see self::summary()}.
	 *
	 * @return array<string,mixed> A JSON-Schema object fragment.
	 */
	public static function itemSchema(): array {
		return array(
			'type'                 => 'object',
			'required'             => array( 'id' ),
			'properties'           => array(
				'id'                   => array(
					'type'        => 'integer',
					'description' => __( 'The order ID. Read the full order with wc-orders/get-order.', 'abilities-catalog-woo' ),
				),
				'number'               => array(
					'type'        => 'string',
					'description' => __( 'The order number shown to staff and customers.', 'abilities-catalog-woo' ),
				),
				'status'               => array(
					'type'        => 'string',
					'description' => __( 'The order status, e.g. pending, processing, on-hold, completed, cancelled, refunded, or failed.', 'abilities-catalog-woo' ),
				),
				'currency'             => array(
					'type'        => 'string',
					'description' => __( 'The order currency in ISO 4217 format, e.g. USD.', 'abilities-catalog-woo' ),
				),
				'total'                => array(
					'type'        => 'string',
					'description' => __( 'The grand total including tax, as a decimal string in the order currency.', 'abilities-catalog-woo' ),
				),
				'total_tax'            => array(
					'type'        => 'string',
					'description' => __( 'The sum of all taxes, as a decimal string in the order currency.', 'abilities-catalog-woo' ),
				),
				'date_created'         => array(
					'type'        => 'string',
					'description' => __( 'The creation date as an ISO-8601 date-time string in the site timezone.', 'abilities-catalog-woo' ),
				),
				'customer_id'          => array(
					'type'        => 'integer',
					'description' => __( 'The user ID who owns the order, or 0 for a guest checkout.', 'abilities-catalog-woo' ),
				),
				'billing_first_name'   => array(
					'type'        => 'string',
					'description' => __( 'The billing first name, or an empty string when none is set.', 'abilities-catalog-woo' ),
				),
				'billing_last_name'    => array(
					'type'        => 'string',
					'description' => __( 'The billing last name, or an empty string when none is set.', 'abilities-catalog-woo' ),
				),
				'billing_email'        => array(
					'type'        => 'string',
					'description' => __( 'The billing email address. Shown so staff can contact the buyer; visible only with the order capability.', 'abilities-catalog-woo' ),
				),
				'payment_method_title' => array(
					'type'        => 'string',
					'description' => __( 'The payment method title shown to the buyer, e.g. Credit Card.', 'abilities-catalog-woo' ),
				),
				'line_items_count'     => array(
					'type'        => 'integer',
					'description' => __( 'The number of line items on the order.', 'abilities-catalog-woo' ),
				),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * Flat detail row for a single `wc/v3` order, for the get-order ability.
	 *
	 * Returns every field from {@see self::summary()} plus the order's line items,
	 * the trimmed billing and shipping blocks, and a ready-to-use `edit_link` to
	 * the wp-admin order editor. Each line item is reduced to the five fields a
	 * consumer needs to read what was bought; the billing and shipping blocks are
	 * reduced to the PII subset documented on this class.
	 *
	 * @param array<string,mixed> $row A single row from a `GET wc/v3/orders/{id}` response.
	 * @return array<string,mixed> The flat detail row: the summary fields plus
	 *                             `line_items`, `billing`, `shipping`, and `edit_link`.
	 */
	public static function detail( array $row ): array {
		$id       = (int) ( $row['id'] ?? 0 );
		$billing  = (array) ( $row['billing'] ?? array() );
		$shipping = (array) ( $row['shipping'] ?? array() );

		$line_items = array();
		foreach ( (array) ( $row['line_items'] ?? array() ) as $line_item ) {
			$line_item    = (array) $line_item;
			$line_items[] = array(
				'id'         => (int) ( $line_item['id'] ?? 0 ),
				'name'       => (string) ( $line_item['name'] ?? '' ),
				'product_id' => (int) ( $line_item['product_id'] ?? 0 ),
				'quantity'   => (int) ( $line_item['quantity'] ?? 0 ),
				'total'      => (string) ( $line_item['total'] ?? '' ),
			);
		}

		return array_merge(
			self::summary( $row ),
			array(
				'line_items' => $line_items,
				'billing'    => array(
					'first_name' => (string) ( $billing['first_name'] ?? '' ),
					'last_name'  => (string) ( $billing['last_name'] ?? '' ),
					'email'      => (string) ( $billing['email'] ?? '' ),
					'address_1'  => (string) ( $billing['address_1'] ?? '' ),
					'city'       => (string) ( $billing['city'] ?? '' ),
					'state'      => (string) ( $billing['state'] ?? '' ),
					'postcode'   => (string) ( $billing['postcode'] ?? '' ),
					'country'    => (string) ( $billing['country'] ?? '' ),
				),
				'shipping'   => array(
					'first_name' => (string) ( $shipping['first_name'] ?? '' ),
					'last_name'  => (string) ( $shipping['last_name'] ?? '' ),
					'address_1'  => (string) ( $shipping['address_1'] ?? '' ),
					'city'       => (string) ( $shipping['city'] ?? '' ),
					'state'      => (string) ( $shipping['state'] ?? '' ),
					'postcode'   => (string) ( $shipping['postcode'] ?? '' ),
					'country'    => (string) ( $shipping['country'] ?? '' ),
				),
				'edit_link'  => admin_url( 'post.php?post=' . $id . '&action=edit' ),
			)
		);
	}

	/**
	 * The `output_schema` definition matching {@see self::detail()}.
	 *
	 * Reuses {@see self::itemSchema()} for the summary fields and adds the closed
	 * nested objects and array for the detail fields.
	 *
	 * @return array<string,mixed> A JSON-Schema object fragment.
	 */
	public static function detailSchema(): array {
		$schema = self::itemSchema();

		$schema['properties']['line_items'] = array(
			'type'        => 'array',
			'description' => __( 'The line items on the order: what was bought, in what quantity, and at what line total.', 'abilities-catalog-woo' ),
			'items'       => array(
				'type'                 => 'object',
				'properties'           => array(
					'id'         => array(
						'type'        => 'integer',
						'description' => __( 'The line item ID within the order.', 'abilities-catalog-woo' ),
					),
					'name'       => array(
						'type'        => 'string',
						'description' => __( 'The product name as recorded on the order line.', 'abilities-catalog-woo' ),
					),
					'product_id' => array(
						'type'        => 'integer',
						'description' => __( 'The product ID for this line. Read the full product with wc-products/get-product.', 'abilities-catalog-woo' ),
					),
					'quantity'   => array(
						'type'        => 'integer',
						'description' => __( 'The quantity ordered for this line.', 'abilities-catalog-woo' ),
					),
					'total'      => array(
						'type'        => 'string',
						'description' => __( 'The line total excluding tax, after discounts, as a decimal string.', 'abilities-catalog-woo' ),
					),
				),
				'additionalProperties' => false,
			),
		);

		$schema['properties']['billing'] = array(
			'type'                 => 'object',
			'description'          => __( 'The buyer billing address subset. Shown so staff can read, contact, and bill the buyer; visible only with the order capability.', 'abilities-catalog-woo' ),
			'properties'           => array(
				'first_name' => array(
					'type'        => 'string',
					'description' => __( 'The billing first name, or an empty string when none is set.', 'abilities-catalog-woo' ),
				),
				'last_name'  => array(
					'type'        => 'string',
					'description' => __( 'The billing last name, or an empty string when none is set.', 'abilities-catalog-woo' ),
				),
				'email'      => array(
					'type'        => 'string',
					'description' => __( 'The billing email address, or an empty string when none is set.', 'abilities-catalog-woo' ),
				),
				'address_1'  => array(
					'type'        => 'string',
					'description' => __( 'The billing street address line 1.', 'abilities-catalog-woo' ),
				),
				'city'       => array(
					'type'        => 'string',
					'description' => __( 'The billing city.', 'abilities-catalog-woo' ),
				),
				'state'      => array(
					'type'        => 'string',
					'description' => __( 'The billing state, province, or district as an ISO code or name.', 'abilities-catalog-woo' ),
				),
				'postcode'   => array(
					'type'        => 'string',
					'description' => __( 'The billing postal code.', 'abilities-catalog-woo' ),
				),
				'country'    => array(
					'type'        => 'string',
					'description' => __( 'The billing country code in ISO 3166-1 alpha-2 format, e.g. US.', 'abilities-catalog-woo' ),
				),
			),
			'additionalProperties' => false,
		);

		$schema['properties']['shipping'] = array(
			'type'                 => 'object',
			'description'          => __( 'The buyer shipping address subset. The WooCommerce shipping address has no email field. Visible only with the order capability.', 'abilities-catalog-woo' ),
			'properties'           => array(
				'first_name' => array(
					'type'        => 'string',
					'description' => __( 'The shipping first name, or an empty string when none is set.', 'abilities-catalog-woo' ),
				),
				'last_name'  => array(
					'type'        => 'string',
					'description' => __( 'The shipping last name, or an empty string when none is set.', 'abilities-catalog-woo' ),
				),
				'address_1'  => array(
					'type'        => 'string',
					'description' => __( 'The shipping street address line 1.', 'abilities-catalog-woo' ),
				),
				'city'       => array(
					'type'        => 'string',
					'description' => __( 'The shipping city.', 'abilities-catalog-woo' ),
				),
				'state'      => array(
					'type'        => 'string',
					'description' => __( 'The shipping state, province, or district as an ISO code or name.', 'abilities-catalog-woo' ),
				),
				'postcode'   => array(
					'type'        => 'string',
					'description' => __( 'The shipping postal code.', 'abilities-catalog-woo' ),
				),
				'country'    => array(
					'type'        => 'string',
					'description' => __( 'The shipping country code in ISO 3166-1 alpha-2 format, e.g. US.', 'abilities-catalog-woo' ),
				),
			),
			'additionalProperties' => false,
		);

		$schema['properties']['edit_link'] = array(
			'type'        => 'string',
			'description' => __( 'The wp-admin URL for editing this order. Open it to edit the order in the dashboard.', 'abilities-catalog-woo' ),
		);

		return $schema;
	}
}
