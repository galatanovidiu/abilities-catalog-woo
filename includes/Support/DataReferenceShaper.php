<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogWoo\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Projects raw `wc/v3/data` reference rows into flat, closed rows for the
 * catalog's reference-data list abilities and their by-code/get siblings.
 *
 * WooCommerce ships three static reference tables on the `wc/v3/data` surface —
 * countries, currencies, and continents. Each shape is read by BOTH a list
 * ability and a single-resource sibling (and the currency shape by three
 * abilities: list-currencies, get-current-currency, get-currency). Pinning ONE
 * closed schema per shape here keeps the runtime row and the declared schema
 * from drifting across those callers.
 *
 * Each `*Summary()` method copies the small fixed field set a consumer needs and
 * casts every value to the type the WC data schema promises: codes, names, and
 * symbols are strings; the nested `states`/`countries` arrays are lists of
 * `{code,name}` string pairs. The matching `*ItemSchema()` method declares the
 * same closed shape (`additionalProperties: false`).
 *
 * This is NOT under `includes/Abilities/`, so the Registry never treats it as an
 * ability. It performs no WooCommerce calls and holds no ability logic; it only
 * shapes rows and declares their schema.
 *
 * @since 0.1.0
 */
final class DataReferenceShaper {

	/**
	 * Flat summary row for a single `wc/v3/data/countries` item.
	 *
	 * Used by BOTH `list-countries` rows and `get-country`. The list route maps
	 * every country through the same `get_country()` projection, so the list row
	 * and the detail row share this shape. Each `states` entry is cast to a
	 * `{code,name}` string pair.
	 *
	 * @param array<string,mixed> $row A single country row from a
	 *                                 `GET wc/v3/data/countries` (or `/{code}`) response.
	 * @return array{
	 *     code:string,
	 *     name:string,
	 *     states:array<int,array{code:string,name:string}>
	 * } The flat country summary row.
	 */
	public static function countrySummary( array $row ): array {
		$states = array();
		foreach ( (array) ( $row['states'] ?? array() ) as $state ) {
			$state = (array) $state;

			$states[] = array(
				'code' => (string) ( $state['code'] ?? '' ),
				'name' => (string) ( $state['name'] ?? '' ),
			);
		}

		return array(
			'code'   => (string) ( $row['code'] ?? '' ),
			'name'   => (string) ( $row['name'] ?? '' ),
			'states' => $states,
		);
	}

	/**
	 * The `output_schema` item definition matching {@see self::countrySummary()}.
	 *
	 * Closed object with `code`, `name`, and a closed `states` list whose items
	 * are closed `{code,name}` objects.
	 *
	 * @return array<string,mixed> A JSON-Schema object fragment.
	 */
	public static function countryItemSchema(): array {
		return array(
			'type'                 => 'object',
			'required'             => array( 'code' ),
			'properties'           => array(
				'code'   => array(
					'type'        => 'string',
					'description' => __( 'The ISO-3166 alpha-2 country code, e.g. US. Read the full country with the get-country ability.', 'abilities-catalog-woo' ),
				),
				'name'   => array(
					'type'        => 'string',
					'description' => __( 'The full name of the country.', 'abilities-catalog-woo' ),
				),
				'states' => array(
					'type'        => 'array',
					'description' => __( 'The states, provinces, or regions of the country, or an empty list when the country has none.', 'abilities-catalog-woo' ),
					'items'       => array(
						'type'                 => 'object',
						'properties'           => array(
							'code' => array(
								'type'        => 'string',
								'description' => __( 'The state code.', 'abilities-catalog-woo' ),
							),
							'name' => array(
								'type'        => 'string',
								'description' => __( 'The full name of the state.', 'abilities-catalog-woo' ),
							),
						),
						'additionalProperties' => false,
					),
				),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * Flat summary row for a single `wc/v3/data/currencies` item.
	 *
	 * Used by `list-currencies` rows, `get-current-currency`, and `get-currency`.
	 * `symbol` is kept exactly as the route returns it: an HTML entity string
	 * (e.g. `&#36;` for the dollar sign). It is NOT decoded — emit the raw entity
	 * so the consumer renders it in the right context.
	 *
	 * @param array<string,mixed> $row A single currency row from a
	 *                                 `GET wc/v3/data/currencies` (or `/current`, `/{code}`) response.
	 * @return array{
	 *     code:string,
	 *     name:string,
	 *     symbol:string
	 * } The flat currency summary row.
	 */
	public static function currencySummary( array $row ): array {
		return array(
			'code'   => (string) ( $row['code'] ?? '' ),
			'name'   => (string) ( $row['name'] ?? '' ),
			'symbol' => (string) ( $row['symbol'] ?? '' ),
		);
	}

	/**
	 * The `output_schema` item definition matching {@see self::currencySummary()}.
	 *
	 * @return array<string,mixed> A JSON-Schema object fragment.
	 */
	public static function currencyItemSchema(): array {
		return array(
			'type'                 => 'object',
			'required'             => array( 'code' ),
			'properties'           => array(
				'code'   => array(
					'type'        => 'string',
					'description' => __( 'The ISO-4217 3-letter currency code, e.g. USD. Read a specific currency with the get-currency ability.', 'abilities-catalog-woo' ),
				),
				'name'   => array(
					'type'        => 'string',
					'description' => __( 'The full name of the currency.', 'abilities-catalog-woo' ),
				),
				'symbol' => array(
					'type'        => 'string',
					'description' => __( 'The currency symbol as an HTML entity string, e.g. &#36; for the dollar sign.', 'abilities-catalog-woo' ),
				),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * Flat summary row for a single `wc/v3/data/continents` item.
	 *
	 * Used by BOTH `list-continents` rows and `get-continent`. The raw continent
	 * row nests FAT country objects carrying per-country locale detail
	 * (`currency_code`, `currency_pos`, `decimal_sep`, `dimension_unit`,
	 * `num_decimals`, `thousand_sep`, `weight_unit`) plus a `states` list. This
	 * shaper DROPS all of that locale detail and summarizes each country to
	 * `{code,name}` only — the per-country detail is reachable via
	 * `wc-data/get-country`.
	 *
	 * @param array<string,mixed> $row A single continent row from a
	 *                                 `GET wc/v3/data/continents` (or `/{code}`) response.
	 * @return array{
	 *     code:string,
	 *     name:string,
	 *     countries:array<int,array{code:string,name:string}>
	 * } The flat continent summary row.
	 */
	public static function continentSummary( array $row ): array {
		$countries = array();
		foreach ( (array) ( $row['countries'] ?? array() ) as $country ) {
			$country = (array) $country;

			$countries[] = array(
				'code' => (string) ( $country['code'] ?? '' ),
				'name' => (string) ( $country['name'] ?? '' ),
			);
		}

		return array(
			'code'      => (string) ( $row['code'] ?? '' ),
			'name'      => (string) ( $row['name'] ?? '' ),
			'countries' => $countries,
		);
	}

	/**
	 * The `output_schema` item definition matching {@see self::continentSummary()}.
	 *
	 * Closed object with `code`, `name`, and a closed `countries` list whose items
	 * are closed `{code,name}` objects — the locale detail the raw route nests is
	 * intentionally absent.
	 *
	 * @return array<string,mixed> A JSON-Schema object fragment.
	 */
	public static function continentItemSchema(): array {
		return array(
			'type'                 => 'object',
			'required'             => array( 'code' ),
			'properties'           => array(
				'code'      => array(
					'type'        => 'string',
					'description' => __( 'The 2-letter continent code, e.g. NA. Read the full continent with the get-continent ability.', 'abilities-catalog-woo' ),
				),
				'name'      => array(
					'type'        => 'string',
					'description' => __( 'The full name of the continent.', 'abilities-catalog-woo' ),
				),
				'countries' => array(
					'type'        => 'array',
					'description' => __( 'The countries on the continent, each as a code and name only. Read a country\'s full detail (states, locale) with the get-country ability.', 'abilities-catalog-woo' ),
					'items'       => array(
						'type'                 => 'object',
						'properties'           => array(
							'code' => array(
								'type'        => 'string',
								'description' => __( 'The ISO-3166 alpha-2 country code, e.g. US.', 'abilities-catalog-woo' ),
							),
							'name' => array(
								'type'        => 'string',
								'description' => __( 'The full name of the country.', 'abilities-catalog-woo' ),
							),
						),
						'additionalProperties' => false,
					),
				),
			),
			'additionalProperties' => false,
		);
	}
}
