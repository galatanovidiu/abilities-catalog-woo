<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogWoo\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Projects raw `wc/v3` order-refund rows into flat, closed summary rows for the
 * catalog's order-refund list ability.
 *
 * A `GET wc/v3/orders/{id}/refunds` row records a refund against an order. This
 * shaper copies the small fixed field set a consumer needs to read a refund and
 * casts each value to the type the WC refunds schema promises (the refund value
 * is a STRING in wc/v3). {@see self::itemSchema()} pins the row closed so the
 * runtime row and the declared schema cannot drift.
 *
 * NOTE on `amount`: a refund has NO top-level `total` field. The refund value is
 * `amount`, a decimal string — this shaper exposes `amount` and never invents a
 * `total`. (The orders endpoint reports a refund's value as a negative `total`
 * inside the order's `refunds` array, but the refunds endpoint itself uses
 * `amount` as a positive decimal string.)
 *
 * This is NOT under `includes/Abilities/`, so the Registry never treats it as an
 * ability. It performs no WooCommerce calls and holds no ability logic; it only
 * shapes rows and declares their schema.
 *
 * @since 0.1.0
 */
final class OrderRefundListShaper {

	/**
	 * Flat summary row for a single `wc/v3` order-refund list item.
	 *
	 * Each value is read with a null-coalescing default and cast to the type the
	 * WC refunds schema guarantees. `amount` is the refund value as a decimal
	 * string; there is no `total` field on a refund. `refunded_by` is the user ID
	 * of the staff member who created the refund.
	 *
	 * @param array<string,mixed> $row A single row from a `GET wc/v3/orders/{id}/refunds` response.
	 * @return array{
	 *     id:int,
	 *     amount:string,
	 *     reason:string,
	 *     date_created:string,
	 *     refunded_by:int
	 * } The flat refund summary row.
	 */
	public static function summary( array $row ): array {
		return array(
			'id'           => (int) ( $row['id'] ?? 0 ),
			'amount'       => (string) ( $row['amount'] ?? '' ),
			'reason'       => (string) ( $row['reason'] ?? '' ),
			'date_created' => (string) ( $row['date_created'] ?? '' ),
			'refunded_by'  => (int) ( $row['refunded_by'] ?? 0 ),
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
					'description' => __( 'The refund ID.', 'abilities-catalog-woo' ),
				),
				'amount'       => array(
					'type'        => 'string',
					'description' => __( 'The refund value as a decimal string in the order currency. A refund has no separate total; this amount is its value.', 'abilities-catalog-woo' ),
				),
				'reason'       => array(
					'type'        => 'string',
					'description' => __( 'The reason recorded for the refund, or an empty string when none was given.', 'abilities-catalog-woo' ),
				),
				'date_created' => array(
					'type'        => 'string',
					'description' => __( 'The creation date as an ISO-8601 date-time string in the site timezone.', 'abilities-catalog-woo' ),
				),
				'refunded_by'  => array(
					'type'        => 'integer',
					'description' => __( 'The user ID of the staff member who created the refund.', 'abilities-catalog-woo' ),
				),
			),
			'additionalProperties' => false,
		);
	}
}
