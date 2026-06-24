<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogWoo\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Projects raw `wc/v3` taxonomy rows — product attribute terms, categories,
 * tags, and the attribute definitions themselves — into flat, closed summary
 * rows for the catalog's term and attribute list abilities.
 *
 * The four taxonomy endpoints (`products/attributes`,
 * `products/attributes/{id}/terms`, `products/categories`, `products/tags`)
 * share a row shape, so one TERM pair serves attribute-terms, categories, and
 * tags, while a second ATTRIBUTE pair serves the attribute definitions. Each
 * summary copies the small fixed field set a consumer needs to scan and filter
 * by taxonomy, and casts each value to the type the WC schema promises.
 * {@see self::termItemSchema()} and {@see self::attributeItemSchema()} pin the
 * rows closed so the runtime row and the declared schema cannot drift.
 *
 * This is NOT under `includes/Abilities/`, so the Registry never treats it as an
 * ability. It performs no WooCommerce calls and holds no ability logic; it only
 * shapes rows and declares their schema.
 *
 * @since 0.1.0
 */
final class ProductTermListShaper {

	/**
	 * Flat summary row for a single `wc/v3` taxonomy term list item.
	 *
	 * Serves attribute terms, product categories, and product tags. Each value is
	 * read with a null-coalescing default and cast to the type the WC schema
	 * guarantees. `parent` is the parent term id for hierarchical categories and
	 * is `0` for flat taxonomies (tags and attribute terms).
	 *
	 * @param array<string,mixed> $row A single row from a `GET wc/v3` terms response (attribute terms, categories, or tags).
	 * @return array{
	 *     id:int,
	 *     name:string,
	 *     slug:string,
	 *     parent:int,
	 *     count:int,
	 *     description:string
	 * } The flat term summary row.
	 */
	public static function termSummary( array $row ): array {
		return array(
			'id'          => (int) ( $row['id'] ?? 0 ),
			'name'        => (string) ( $row['name'] ?? '' ),
			'slug'        => (string) ( $row['slug'] ?? '' ),
			'parent'      => (int) ( $row['parent'] ?? 0 ),
			'count'       => (int) ( $row['count'] ?? 0 ),
			'description' => (string) ( $row['description'] ?? '' ),
		);
	}

	/**
	 * The `output_schema` item definition matching {@see self::termSummary()}.
	 *
	 * @return array<string,mixed> A JSON-Schema object fragment.
	 */
	public static function termItemSchema(): array {
		return array(
			'type'                 => 'object',
			'required'             => array( 'id' ),
			'properties'           => array(
				'id'          => array(
					'type'        => 'integer',
					'description' => __( 'The term ID. Use it to filter products by this category, tag, or attribute term.', 'abilities-catalog-woo' ),
				),
				'name'        => array(
					'type'        => 'string',
					'description' => __( 'The term name shown to shoppers.', 'abilities-catalog-woo' ),
				),
				'slug'        => array(
					'type'        => 'string',
					'description' => __( 'The term slug used in URLs and queries.', 'abilities-catalog-woo' ),
				),
				'parent'      => array(
					'type'        => 'integer',
					'description' => __( 'The parent term id, or 0 for top-level terms and flat taxonomies like tags and attribute terms.', 'abilities-catalog-woo' ),
				),
				'count'       => array(
					'type'        => 'integer',
					'description' => __( 'The number of published products assigned to this term.', 'abilities-catalog-woo' ),
				),
				'description' => array(
					'type'        => 'string',
					'description' => __( 'The term description, or an empty string when none is set.', 'abilities-catalog-woo' ),
				),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * Flat summary row for a single `wc/v3` product attribute definition.
	 *
	 * Each value is read with a null-coalescing default and cast to the type the
	 * WC attributes schema guarantees. `has_archives` is cast to `bool`.
	 *
	 * @param array<string,mixed> $row A single row from a `GET wc/v3/products/attributes` response.
	 * @return array{
	 *     id:int,
	 *     name:string,
	 *     slug:string,
	 *     type:string,
	 *     order_by:string,
	 *     has_archives:bool
	 * } The flat attribute summary row.
	 */
	public static function attributeSummary( array $row ): array {
		return array(
			'id'           => (int) ( $row['id'] ?? 0 ),
			'name'         => (string) ( $row['name'] ?? '' ),
			'slug'         => (string) ( $row['slug'] ?? '' ),
			'type'         => (string) ( $row['type'] ?? '' ),
			'order_by'     => (string) ( $row['order_by'] ?? '' ),
			'has_archives' => (bool) ( $row['has_archives'] ?? false ),
		);
	}

	/**
	 * The `output_schema` item definition matching {@see self::attributeSummary()}.
	 *
	 * @return array<string,mixed> A JSON-Schema object fragment.
	 */
	public static function attributeItemSchema(): array {
		return array(
			'type'                 => 'object',
			'required'             => array( 'id' ),
			'properties'           => array(
				'id'           => array(
					'type'        => 'integer',
					'description' => __( 'The attribute ID. Read its terms with the attribute-terms list ability.', 'abilities-catalog-woo' ),
				),
				'name'         => array(
					'type'        => 'string',
					'description' => __( 'The attribute name shown to shoppers, e.g. Color.', 'abilities-catalog-woo' ),
				),
				'slug'         => array(
					'type'        => 'string',
					'description' => __( 'The attribute slug used in queries, e.g. pa_color.', 'abilities-catalog-woo' ),
				),
				'type'         => array(
					'type'        => 'string',
					'description' => __( 'The attribute input type, e.g. select.', 'abilities-catalog-woo' ),
				),
				'order_by'     => array(
					'type'        => 'string',
					'description' => __( 'How the attribute terms are sorted: menu_order, name, name_num, or id.', 'abilities-catalog-woo' ),
				),
				'has_archives' => array(
					'type'        => 'boolean',
					'description' => __( 'Whether this attribute has public archive pages.', 'abilities-catalog-woo' ),
				),
			),
			'additionalProperties' => false,
		);
	}
}
