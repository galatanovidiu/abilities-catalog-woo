<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogWoo\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Projects raw `wc/v3` tax-rate and tax-class rows into flat, closed rows for the
 * catalog's tax list abilities.
 *
 * A `GET wc/v3/taxes` row carries the full per-rate descriptor — including the raw
 * `postcodes`, `cities`, and `order` fields a list consumer never reads — while a
 * `GET wc/v3/taxes/classes` row is the small `{slug, name}` pair. This shaper
 * exposes two shapes for rates ({@see self::summary()} / {@see self::itemSchema()})
 * and two for classes ({@see self::classSummary()} / {@see self::classItemSchema()}).
 * It copies the fixed field set a consumer needs to read a tax setup, casts each
 * value to the type the WC taxes schema promises (the `rate` is a decimal STRING in
 * wc/v3, kept as a string), and drops the raw postcodes, cities, and order columns.
 * {@see self::itemSchema()} and {@see self::classItemSchema()} pin the rows closed
 * so the runtime row and the declared schema cannot drift.
 *
 * This shaper carries NO secrets — tax rates and classes hold no credentials.
 *
 * This is NOT under `includes/Abilities/`, so the Registry never treats it as an
 * ability. It performs no WooCommerce calls and holds no ability logic; it only
 * shapes rows and declares their schema.
 *
 * @since 0.1.0
 */
final class TaxRateListShaper {

	/**
	 * Flat summary row for a single `wc/v3` tax-rate list item.
	 *
	 * Each value is read with a null-coalescing default and cast to the type the WC
	 * taxes schema guarantees. The `rate` is kept as a decimal string. The raw
	 * `postcodes`, `cities`, and `order` columns are dropped.
	 *
	 * @param array<string,mixed> $row A single row from a `GET wc/v3/taxes` response.
	 * @return array{
	 *     id:int,
	 *     country:string,
	 *     state:string,
	 *     rate:string,
	 *     name:string,
	 *     priority:int,
	 *     compound:bool,
	 *     shipping:bool,
	 *     class:string
	 * } The flat summary row.
	 */
	public static function summary( array $row ): array {
		return array(
			'id'       => (int) ( $row['id'] ?? 0 ),
			'country'  => (string) ( $row['country'] ?? '' ),
			'state'    => (string) ( $row['state'] ?? '' ),
			'rate'     => (string) ( $row['rate'] ?? '' ),
			'name'     => (string) ( $row['name'] ?? '' ),
			'priority' => (int) ( $row['priority'] ?? 0 ),
			'compound' => (bool) ( $row['compound'] ?? false ),
			'shipping' => (bool) ( $row['shipping'] ?? false ),
			'class'    => (string) ( $row['class'] ?? '' ),
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
				'id'       => array(
					'type'        => 'integer',
					'description' => __( 'The tax rate ID.', 'abilities-catalog-woo' ),
				),
				'country'  => array(
					'type'        => 'string',
					'description' => __( 'The country code this rate applies to in ISO 3166-1 alpha-2 format, e.g. US; empty when the rate applies to all countries.', 'abilities-catalog-woo' ),
				),
				'state'    => array(
					'type'        => 'string',
					'description' => __( 'The state, province, or district code this rate applies to, or an empty string for all.', 'abilities-catalog-woo' ),
				),
				'rate'     => array(
					'type'        => 'string',
					'description' => __( 'The tax rate as a decimal string percentage, e.g. 7.0000.', 'abilities-catalog-woo' ),
				),
				'name'     => array(
					'type'        => 'string',
					'description' => __( 'The tax rate name shown to staff, e.g. VAT.', 'abilities-catalog-woo' ),
				),
				'priority' => array(
					'type'        => 'integer',
					'description' => __( 'The rate priority; only one matching rate per priority is applied.', 'abilities-catalog-woo' ),
				),
				'compound' => array(
					'type'        => 'boolean',
					'description' => __( 'Whether this is a compound rate, applied on top of other taxes.', 'abilities-catalog-woo' ),
				),
				'shipping' => array(
					'type'        => 'boolean',
					'description' => __( 'Whether this rate is also applied to shipping costs.', 'abilities-catalog-woo' ),
				),
				'class'    => array(
					'type'        => 'string',
					'description' => __( 'The tax class slug this rate belongs to, e.g. standard, reduced-rate, or zero-rate.', 'abilities-catalog-woo' ),
				),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * Flat summary row for a single `wc/v3` tax-class list item.
	 *
	 * Each value is read with a null-coalescing default and cast to string.
	 *
	 * @param array<string,mixed> $row A single row from a `GET wc/v3/taxes/classes` response.
	 * @return array{slug:string,name:string} The flat tax-class summary row.
	 */
	public static function classSummary( array $row ): array {
		return array(
			'slug' => (string) ( $row['slug'] ?? '' ),
			'name' => (string) ( $row['name'] ?? '' ),
		);
	}

	/**
	 * The `output_schema` item definition matching {@see self::classSummary()}.
	 *
	 * @return array<string,mixed> A JSON-Schema object fragment.
	 */
	public static function classItemSchema(): array {
		return array(
			'type'                 => 'object',
			'required'             => array( 'slug' ),
			'properties'           => array(
				'slug' => array(
					'type'        => 'string',
					'description' => __( 'The tax class slug, e.g. standard, reduced-rate, or zero-rate. Use it to filter rates by class.', 'abilities-catalog-woo' ),
				),
				'name' => array(
					'type'        => 'string',
					'description' => __( 'The human readable tax class name, e.g. Reduced rate.', 'abilities-catalog-woo' ),
				),
			),
			'additionalProperties' => false,
		);
	}
}
