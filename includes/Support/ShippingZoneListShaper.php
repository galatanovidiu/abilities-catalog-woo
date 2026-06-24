<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogWoo\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Projects raw `wc/v3` shipping-zone rows into flat, closed summary rows for the
 * catalog's shipping-zone list ability.
 *
 * A `GET wc/v3/shipping/zones` row carries only three fields a consumer reads to
 * scan and order the store's shipping zones: `id`, `name`, and `order`. This
 * shaper copies that fixed field set and casts each value to the type the WC
 * shipping-zones schema promises. {@see self::itemSchema()} pins the row closed
 * so the runtime row and the declared schema cannot drift.
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
final class ShippingZoneListShaper {

	/**
	 * Flat summary row for a single `wc/v3` shipping-zone list item.
	 *
	 * Each value is read with a null-coalescing default and cast to the type the
	 * WC shipping-zones schema guarantees. `order` is the zone's sort position.
	 *
	 * @param array<string,mixed> $row A single row from a `GET wc/v3/shipping/zones` response.
	 * @return array{
	 *     id:int,
	 *     name:string,
	 *     order:int
	 * } The flat summary row.
	 */
	public static function summary( array $row ): array {
		return array(
			'id'    => (int) ( $row['id'] ?? 0 ),
			'name'  => (string) ( $row['name'] ?? '' ),
			'order' => (int) ( $row['order'] ?? 0 ),
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
				'id'    => array(
					'type'        => 'integer',
					'description' => __( 'The shipping zone ID. Use it to list the zone\'s shipping methods.', 'abilities-catalog-woo' ),
				),
				'name'  => array(
					'type'        => 'string',
					'description' => __( 'The shipping zone name shown to store staff, e.g. Domestic.', 'abilities-catalog-woo' ),
				),
				'order' => array(
					'type'        => 'integer',
					'description' => __( 'The zone sort position; zones are matched against an order in ascending sequence.', 'abilities-catalog-woo' ),
				),
			),
			'additionalProperties' => false,
		);
	}
}
