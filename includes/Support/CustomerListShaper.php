<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogWoo\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Projects raw `wc/v3` customer rows into flat, closed rows for the catalog's
 * customer list and get-customer abilities.
 *
 * A `GET wc/v3/customers` row carries the full billing and shipping blocks, meta
 * data, the avatar URL, and the `is_paying_customer` flag — most of which a
 * consumer never reads. This shaper exposes two shapes: {@see self::summary()}
 * copies the small fixed field set a consumer needs to scan a list of customers,
 * and {@see self::detail()} adds the trimmed billing and shipping blocks a
 * consumer needs to read, contact, and ship to one customer in full. Each value
 * is cast to the type the WC customers schema promises (`total_spent` is a money
 * STRING in wc/v3). {@see self::itemSchema()} and {@see self::detailSchema()} pin
 * the rows closed so the runtime row and the declared schema cannot drift.
 *
 * PII: this shaper exposes ONLY a deliberate closed subset of personal data —
 * the customer email, names, username, and a trimmed billing/shipping address
 * block. The billing block carries an email and a phone; the WC shipping address
 * has NO email field, so the shipping block carries a phone but no email. This
 * shaper NEVER exposes `meta_data`, `avatar_url`, `is_paying_customer`, or the
 * raw customer object. These reads are an admin tool whose hard guard is the
 * capability the customer abilities check; the subset here is the minimum a
 * consumer needs to identify, contact, and fulfil for a customer.
 *
 * This is NOT under `includes/Abilities/`, so the Registry never treats it as an
 * ability. It performs no WooCommerce calls and holds no ability logic; it only
 * shapes rows and declares their schema.
 *
 * @since 0.1.0
 */
final class CustomerListShaper {

	/**
	 * Flat summary row for a single `wc/v3` customer list item.
	 *
	 * Each value is read with a null-coalescing default and cast to the type the
	 * WC customers schema guarantees. `total_spent` is kept as a string because
	 * wc/v3 returns it as a formatted money string, not a number.
	 *
	 * @param array<string,mixed> $row A single row from a `GET wc/v3/customers` response.
	 * @return array{
	 *     id:int,
	 *     email:string,
	 *     first_name:string,
	 *     last_name:string,
	 *     username:string,
	 *     role:string,
	 *     date_created:string,
	 *     orders_count:int,
	 *     total_spent:string
	 * } The flat summary row.
	 */
	public static function summary( array $row ): array {
		return array(
			'id'           => (int) ( $row['id'] ?? 0 ),
			'email'        => (string) ( $row['email'] ?? '' ),
			'first_name'   => (string) ( $row['first_name'] ?? '' ),
			'last_name'    => (string) ( $row['last_name'] ?? '' ),
			'username'     => (string) ( $row['username'] ?? '' ),
			'role'         => (string) ( $row['role'] ?? '' ),
			'date_created' => (string) ( $row['date_created'] ?? '' ),
			'orders_count' => (int) ( $row['orders_count'] ?? 0 ),
			'total_spent'  => (string) ( $row['total_spent'] ?? '' ),
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
				'id'           => array(
					'type'        => 'integer',
					'description' => __( 'The customer ID. Read the full customer with og-wc-customers/get-customer.', 'abilities-catalog-woo' ),
				),
				'email'        => array(
					'type'        => 'string',
					'description' => __( 'The customer account email. Shown so staff can contact the customer; visible only with the customer capability.', 'abilities-catalog-woo' ),
				),
				'first_name'   => array(
					'type'        => 'string',
					'description' => __( 'The customer first name, or an empty string when none is set.', 'abilities-catalog-woo' ),
				),
				'last_name'    => array(
					'type'        => 'string',
					'description' => __( 'The customer last name, or an empty string when none is set.', 'abilities-catalog-woo' ),
				),
				'username'     => array(
					'type'        => 'string',
					'description' => __( 'The customer login name.', 'abilities-catalog-woo' ),
				),
				'role'         => array(
					'type'        => 'string',
					'description' => __( 'The user role, e.g. customer or subscriber.', 'abilities-catalog-woo' ),
				),
				'date_created' => array(
					'type'        => 'string',
					'description' => __( 'The registration date as an ISO-8601 date-time string in the site timezone.', 'abilities-catalog-woo' ),
				),
				'orders_count' => array(
					'type'        => 'integer',
					'description' => __( 'The number of orders the customer has placed.', 'abilities-catalog-woo' ),
				),
				'total_spent'  => array(
					'type'        => 'string',
					'description' => __( 'The lifetime total spent, as a decimal string in the store currency.', 'abilities-catalog-woo' ),
				),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * Flat detail row for a single `wc/v3` customer, for the get-customer ability.
	 *
	 * Returns every field from {@see self::summary()} plus the trimmed billing and
	 * shipping blocks reduced to the PII subset documented on this class. The
	 * billing block includes email and phone; the shipping block includes phone
	 * but NOT email, because the WC shipping address has no email field.
	 *
	 * @param array<string,mixed> $row A single row from a `GET wc/v3/customers/{id}` response.
	 * @return array<string,mixed> The flat detail row: the summary fields plus
	 *                             `billing` and `shipping`.
	 */
	public static function detail( array $row ): array {
		$billing  = (array) ( $row['billing'] ?? array() );
		$shipping = (array) ( $row['shipping'] ?? array() );

		return array_merge(
			self::summary( $row ),
			array(
				'billing'  => array(
					'first_name' => (string) ( $billing['first_name'] ?? '' ),
					'last_name'  => (string) ( $billing['last_name'] ?? '' ),
					'company'    => (string) ( $billing['company'] ?? '' ),
					'address_1'  => (string) ( $billing['address_1'] ?? '' ),
					'address_2'  => (string) ( $billing['address_2'] ?? '' ),
					'city'       => (string) ( $billing['city'] ?? '' ),
					'state'      => (string) ( $billing['state'] ?? '' ),
					'postcode'   => (string) ( $billing['postcode'] ?? '' ),
					'country'    => (string) ( $billing['country'] ?? '' ),
					'email'      => (string) ( $billing['email'] ?? '' ),
					'phone'      => (string) ( $billing['phone'] ?? '' ),
				),
				'shipping' => array(
					'first_name' => (string) ( $shipping['first_name'] ?? '' ),
					'last_name'  => (string) ( $shipping['last_name'] ?? '' ),
					'company'    => (string) ( $shipping['company'] ?? '' ),
					'address_1'  => (string) ( $shipping['address_1'] ?? '' ),
					'address_2'  => (string) ( $shipping['address_2'] ?? '' ),
					'city'       => (string) ( $shipping['city'] ?? '' ),
					'state'      => (string) ( $shipping['state'] ?? '' ),
					'postcode'   => (string) ( $shipping['postcode'] ?? '' ),
					'country'    => (string) ( $shipping['country'] ?? '' ),
					'phone'      => (string) ( $shipping['phone'] ?? '' ),
				),
			)
		);
	}

	/**
	 * The `output_schema` definition matching {@see self::detail()}.
	 *
	 * Reuses {@see self::itemSchema()} for the summary fields and adds the closed
	 * nested billing and shipping objects for the detail fields.
	 *
	 * @return array<string,mixed> A JSON-Schema object fragment.
	 */
	public static function detailSchema(): array {
		$schema = self::itemSchema();

		$schema['properties']['billing'] = array(
			'type'                 => 'object',
			'description'          => __( 'The customer billing address subset. Shown so staff can read, contact, and bill the customer; visible only with the customer capability.', 'abilities-catalog-woo' ),
			'properties'           => array(
				'first_name' => array(
					'type'        => 'string',
					'description' => __( 'The billing first name, or an empty string when none is set.', 'abilities-catalog-woo' ),
				),
				'last_name'  => array(
					'type'        => 'string',
					'description' => __( 'The billing last name, or an empty string when none is set.', 'abilities-catalog-woo' ),
				),
				'company'    => array(
					'type'        => 'string',
					'description' => __( 'The billing company name, or an empty string when none is set.', 'abilities-catalog-woo' ),
				),
				'address_1'  => array(
					'type'        => 'string',
					'description' => __( 'The billing street address line 1.', 'abilities-catalog-woo' ),
				),
				'address_2'  => array(
					'type'        => 'string',
					'description' => __( 'The billing street address line 2, or an empty string when none is set.', 'abilities-catalog-woo' ),
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
				'email'      => array(
					'type'        => 'string',
					'description' => __( 'The billing email address, or an empty string when none is set.', 'abilities-catalog-woo' ),
				),
				'phone'      => array(
					'type'        => 'string',
					'description' => __( 'The billing phone number, or an empty string when none is set.', 'abilities-catalog-woo' ),
				),
			),
			'additionalProperties' => false,
		);

		$schema['properties']['shipping'] = array(
			'type'                 => 'object',
			'description'          => __( 'The customer shipping address subset. The WooCommerce shipping address has no email field. Visible only with the customer capability.', 'abilities-catalog-woo' ),
			'properties'           => array(
				'first_name' => array(
					'type'        => 'string',
					'description' => __( 'The shipping first name, or an empty string when none is set.', 'abilities-catalog-woo' ),
				),
				'last_name'  => array(
					'type'        => 'string',
					'description' => __( 'The shipping last name, or an empty string when none is set.', 'abilities-catalog-woo' ),
				),
				'company'    => array(
					'type'        => 'string',
					'description' => __( 'The shipping company name, or an empty string when none is set.', 'abilities-catalog-woo' ),
				),
				'address_1'  => array(
					'type'        => 'string',
					'description' => __( 'The shipping street address line 1.', 'abilities-catalog-woo' ),
				),
				'address_2'  => array(
					'type'        => 'string',
					'description' => __( 'The shipping street address line 2, or an empty string when none is set.', 'abilities-catalog-woo' ),
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
				'phone'      => array(
					'type'        => 'string',
					'description' => __( 'The shipping phone number, or an empty string when none is set.', 'abilities-catalog-woo' ),
				),
			),
			'additionalProperties' => false,
		);

		return $schema;
	}
}
