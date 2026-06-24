<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogWoo\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Projects raw `wc/v3` shipping-method-type rows into flat, closed summary rows
 * for the catalog's shipping-method list ability.
 *
 * A `GET wc/v3/shipping_methods` row describes an available method TYPE — the
 * kind of shipping a zone can offer, e.g. flat_rate or free_shipping — not a
 * configured instance. Each row carries three fields: a string `id` slug, a
 * `title`, and a `description`. This shaper copies that fixed field set and casts
 * each value to string. {@see self::itemSchema()} pins the row closed so the
 * runtime row and the declared schema cannot drift.
 *
 * Shipping is configured under a WooCommerce settings tab rather than per-row
 * edit screens, so this shaper adds NO `edit_link`.
 *
 * This is NOT under `includes/Abilities/`, so the Registry never treats it as an
 * ability. It performs no WooCommerce calls and holds no ability logic; it only
 * shapes rows and declares their schema.
 *
 * @since 0.1.0
 */
final class ShippingMethodListShaper {

	/**
	 * Flat summary row for a single `wc/v3` shipping-method-type list item.
	 *
	 * Each value is read with a null-coalescing default and cast to string. The
	 * `id` is the method-type slug (e.g. flat_rate), NOT a numeric identifier.
	 *
	 * @param array<string,mixed> $row A single row from a `GET wc/v3/shipping_methods` response.
	 * @return array{
	 *     id:string,
	 *     title:string,
	 *     description:string
	 * } The flat summary row.
	 */
	public static function summary( array $row ): array {
		return array(
			'id'          => (string) ( $row['id'] ?? '' ),
			'title'       => (string) ( $row['title'] ?? '' ),
			'description' => (string) ( $row['description'] ?? '' ),
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
				'id'          => array(
					'type'        => 'string',
					'description' => __( 'The shipping method type slug, e.g. flat_rate, free_shipping, or local_pickup. Use it when adding a method to a zone.', 'abilities-catalog-woo' ),
				),
				'title'       => array(
					'type'        => 'string',
					'description' => __( 'The method type title shown to store staff, e.g. Flat rate.', 'abilities-catalog-woo' ),
				),
				'description' => array(
					'type'        => 'string',
					'description' => __( 'A short description of what the method type does, or an empty string when none is set.', 'abilities-catalog-woo' ),
				),
			),
			'additionalProperties' => false,
		);
	}
}
