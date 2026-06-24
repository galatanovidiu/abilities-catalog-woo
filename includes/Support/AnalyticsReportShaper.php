<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogWoo\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Projects raw `wc-analytics` report payloads into flat, closed rows and totals
 * for the catalog's analytics sales-read abilities.
 *
 * This is the CANONICAL analytics shaper: batches 12 and 13 EXTEND this same
 * class (no new file, no renamed methods) with the same four public signatures.
 * Keep them stable — the analytics abilities already call them.
 *
 * THE TOTALS-SUBSET RULE (load-bearing): a `wc-analytics` stats response is
 * `{ totals: {...}, intervals: [ ... ] }`, where `intervals` is a per-period
 * array — one object per bucket in the date range, potentially hundreds, each
 * carrying its own `subtotals`. That array is the huge payload we must NEVER
 * pass through. {@see self::statsTotals()} extracts only the caller's whitelisted
 * KPI keys (cast to numbers) and reports `intervals_count` (the SIZE of the
 * `intervals` array) instead of the array itself. Totals fields are
 * report-specific (revenue vs orders vs products differ), so each ability supplies
 * its own `$float_keys`/`$int_keys` lists and its own closed totals schema; this
 * shaper does the extraction, the cast, and the `intervals_count` — never the
 * `intervals` array.
 *
 * THE EXTENDED_INFO DROP: a `wc-analytics` list row (orders, products) nests a fat
 * `extended_info` object (products/coupons/customer/attribution for orders;
 * image/category_ids/variations/stock for products) that we never return.
 * {@see self::analyticsRow()} reads each whitelisted field top-level first and
 * falls back to `$row['extended_info'][$field]` only for the individual identity
 * fields the products row surfaces there (`name`, `sku`). The full `extended_info`
 * object is never copied — only the named nested fields are lifted out.
 *
 * This is NOT under `includes/Abilities/`, so the Registry never treats it as an
 * ability. It performs no WooCommerce calls and holds no ability logic; it only
 * shapes rows/totals and declares their schema. The leaderboards ability shapes
 * its bounded payload inline and does not use this shaper.
 *
 * @since 0.1.0
 */
final class AnalyticsReportShaper {

	/**
	 * Extract the whitelisted KPI totals from a raw stats response, with intervals_count.
	 *
	 * `$data` is the full stats payload (`response_to_data()` of a
	 * `/wc-analytics/reports/*​/stats` route): `$data['totals']` is the KPI object and
	 * `$data['intervals']` is the big per-period array. For each key in `$float_keys`
	 * the value is copied as `(float)`, for each key in `$int_keys` as `(int)`, both
	 * defaulting to `0` when absent. Floats are emitted before ints; the caller's
	 * closed totals schema declares the canonical key order, so the order here is
	 * cosmetic. The raw `intervals` array is NEVER copied — only its element count is
	 * reported as `intervals_count`. The caller merges in the `period` envelope.
	 *
	 * @param array<string,mixed> $data       The full stats response with `totals` and `intervals`.
	 * @param array<int,string>   $float_keys Totals keys to copy as floats (monetary / average KPIs).
	 * @param array<int,string>   $int_keys   Totals keys to copy as ints (count KPIs).
	 * @return array{totals:array<string,float|int>, intervals_count:int} The shaped totals
	 *                                         and the size of the omitted `intervals` array.
	 */
	public static function statsTotals( array $data, array $float_keys, array $int_keys ): array {
		$raw_totals = (array) ( $data['totals'] ?? array() );

		$totals = array();
		foreach ( $float_keys as $key ) {
			$totals[ $key ] = (float) ( $raw_totals[ $key ] ?? 0 );
		}
		foreach ( $int_keys as $key ) {
			$totals[ $key ] = (int) ( $raw_totals[ $key ] ?? 0 );
		}

		return array(
			'totals'          => $totals,
			'intervals_count' => count( (array) ( $data['intervals'] ?? array() ) ),
		);
	}

	/**
	 * The SHARED closed output envelope schema for the stats abilities.
	 *
	 * Wraps a per-ability totals schema in the fixed `{ totals, intervals_count,
	 * period }` envelope so all four stats abilities (revenue/orders/products + the
	 * batch-12/13 stats that DO return intervals) declare the same outer shape. The
	 * `period` object echoes the request's `after`/`before`/`interval` back to the
	 * caller; its props are all optional strings. Every object is closed
	 * (`additionalProperties: false`).
	 *
	 * @param array<string,mixed> $totals_schema The per-ability closed totals object schema.
	 * @return array<string,mixed> A JSON-Schema object fragment for the stats envelope.
	 */
	public static function statsEnvelopeSchema( array $totals_schema ): array {
		return array(
			'type'                 => 'object',
			'required'             => array( 'totals', 'intervals_count', 'period' ),
			'properties'           => array(
				'totals'          => $totals_schema,
				'intervals_count' => array(
					'type'        => 'integer',
					'description' => __( 'The number of per-period buckets the report computed over the date range. The full per-interval breakdown is intentionally omitted — only this count is reported.', 'abilities-catalog-woo' ),
				),
				'period'          => array(
					'type'                 => 'object',
					'properties'           => array(
						'after'    => array(
							'type'        => 'string',
							'description' => __( 'The start of the date range (ISO8601 date-time), echoed back from the request.', 'abilities-catalog-woo' ),
						),
						'before'   => array(
							'type'        => 'string',
							'description' => __( 'The end of the date range (ISO8601 date-time), echoed back from the request.', 'abilities-catalog-woo' ),
						),
						'interval' => array(
							'type'        => 'string',
							'description' => __( 'The bucket size that drives intervals_count (hour, day, week, month, quarter, or year), echoed back from the request.', 'abilities-catalog-woo' ),
						),
					),
					'additionalProperties' => false,
				),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * Flat, closed row for a single `wc-analytics` list report item (orders, products).
	 *
	 * `$keys` is an associative whitelist of `output_field => type`, where type is
	 * one of `int`, `float`, `string`, `bool`. For each field the value is read from
	 * `$row[$field]` when set; otherwise it falls back to
	 * `$row['extended_info'][$field]` when that is set — this is how the products row
	 * surfaces `name`/`sku`, which the controller nests inside `extended_info`. When
	 * neither holds a value the type default is used (`0` for int/float, `''` for
	 * string, `false` for bool). Each value is cast per its declared type. The full
	 * `extended_info` object is NEVER copied — only the individually whitelisted
	 * nested fields are lifted out. The result preserves `$keys` order.
	 *
	 * @param array<string,mixed>  $row  A single row from a `GET /wc-analytics/reports/{orders|products}` response.
	 * @param array<string,string> $keys Map of output field name to type (`int`/`float`/`string`/`bool`).
	 * @return array<string,float|int|string|bool> The flat closed row in `$keys` order.
	 */
	public static function analyticsRow( array $row, array $keys ): array {
		$extended_info = (array) ( $row['extended_info'] ?? array() );

		$shaped = array();
		foreach ( $keys as $field => $type ) {
			if ( array_key_exists( $field, $row ) ) {
				$value = $row[ $field ];
			} elseif ( array_key_exists( $field, $extended_info ) ) {
				$value = $extended_info[ $field ];
			} else {
				$value = null;
			}

			$shaped[ $field ] = self::castValue( $value, $type );
		}

		return $shaped;
	}

	/**
	 * The SHARED helper that wraps a list row's `properties` into a closed item schema.
	 *
	 * Each list ability supplies its own `$properties` (orders and products fields
	 * differ), so this only pins the outer object closed
	 * (`additionalProperties: false`). No `required` key is declared: the orders and
	 * products rows share no single always-present identity field, and shipping
	 * `'required' => array()` is disallowed by the schema idioms — so `required` is
	 * omitted entirely rather than guessed.
	 *
	 * @param array<string,mixed> $properties The per-ability row properties map.
	 * @return array<string,mixed> A closed JSON-Schema object fragment.
	 */
	public static function analyticsItemSchema( array $properties ): array {
		return array(
			'type'                 => 'object',
			'properties'           => $properties,
			'additionalProperties' => false,
		);
	}

	/**
	 * Cast a raw value to the declared scalar type, with the type's empty default.
	 *
	 * A `null` value (an absent or nulled field) becomes the type default: `0` for
	 * `int`/`float`, `''` for `string`, `false` for `bool`.
	 *
	 * @param mixed  $value The raw value, or null when the field is absent.
	 * @param string $type  One of `int`, `float`, `string`, `bool`.
	 * @return float|int|string|bool The cast value.
	 */
	private static function castValue( $value, string $type ) {
		switch ( $type ) {
			case 'int':
				return (int) ( $value ?? 0 );
			case 'float':
				return (float) ( $value ?? 0 );
			case 'bool':
				return (bool) ( $value ?? false );
			case 'string':
			default:
				return (string) ( $value ?? '' );
		}
	}
}
