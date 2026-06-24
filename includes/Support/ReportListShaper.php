<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogWoo\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Projects raw `wc/v3` legacy "totals" report rows into flat, closed rows.
 *
 * The five totals reports — orders, products, customers, coupons, reviews —
 * share one row shape: a `slug` (a status / type / bucket key), a human-readable
 * `name`, and a `total` count. This shaper pins that one closed schema so the five
 * `get-*-totals` abilities and the declared output schema cannot drift apart.
 *
 * TOTAL TYPING: the totals controllers' `get_item_schema()` declares `total` as a
 * JSON `string`, but `get_reports()` computes it as an `(int)` and
 * `prepare_item_for_response()` passes it through unchanged, so the dispatched
 * value is an integer. {@see self::totalsRow()} casts it to `int` and
 * {@see self::totalsItemSchema()} types it `integer` — matching the real value
 * rather than the source schema's mislabel.
 *
 * `get-sales-report` and `get-top-sellers-report` are NOT shaped here: their
 * payloads (the per-period sales object and the `{product_id,name,quantity}`
 * top-sellers row) are unique, so they shape inline.
 *
 * This is NOT under `includes/Abilities/`, so the Registry never treats it as an
 * ability. It performs no WooCommerce calls and holds no ability logic; it only
 * shapes rows and declares their schema.
 *
 * @since 0.1.0
 */
final class ReportListShaper {

	/**
	 * Flat row for a single legacy "totals" report entry.
	 *
	 * @param array<string,mixed> $row A single row from a `GET wc/v3/reports/{x}/totals` response.
	 * @return array{slug:string, name:string, total:int} The flat totals row.
	 */
	public static function totalsRow( array $row ): array {
		return array(
			'slug'  => (string) ( $row['slug'] ?? '' ),
			'name'  => (string) ( $row['name'] ?? '' ),
			'total' => (int) ( $row['total'] ?? 0 ),
		);
	}

	/**
	 * The `output_schema` item definition matching {@see self::totalsRow()}.
	 *
	 * Closed object with the three totals fields. `total` is typed `integer` — see
	 * the class note on the source schema's `string` mislabel.
	 *
	 * @return array<string,mixed> A JSON-Schema object fragment.
	 */
	public static function totalsItemSchema(): array {
		return array(
			'type'                 => 'object',
			'required'             => array( 'slug', 'name', 'total' ),
			'properties'           => array(
				'slug'  => array(
					'type'        => 'string',
					'description' => __( 'The bucket key for this row — an order status, product type, customer/coupon class, or review rating, depending on the report.', 'abilities-catalog-woo' ),
				),
				'name'  => array(
					'type'        => 'string',
					'description' => __( 'The human-readable label for the bucket.', 'abilities-catalog-woo' ),
				),
				'total' => array(
					'type'        => 'integer',
					'description' => __( 'The count of items in this bucket.', 'abilities-catalog-woo' ),
				),
			),
			'additionalProperties' => false,
		);
	}
}
