<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogWoo\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Projects raw `wc/v3` shipping-zone-method rows into flat, closed rows for the
 * catalog's zone-method list and get-zone-method abilities.
 *
 * A `GET wc/v3/shipping/zones/{id}/methods` row carries a full per-setting
 * `settings` block — an object keyed by setting id where each entry is the whole
 * {id,label,description,type,value,default,tip,placeholder[,options]} field
 * descriptor — which a list consumer never reads in full. This shaper exposes two
 * shapes: {@see self::summary()} copies the small fixed field set a consumer needs
 * to scan a zone's methods and collapses `settings` to a compact
 * `settings_summary` map of setting-id => configured value, and
 * {@see self::detail()} adds the method type's `method_title` and
 * `method_description` for reading one configured method in full. Each value is
 * cast to the type the WC shipping-zone-methods schema promises.
 * {@see self::itemSchema()} and {@see self::detailSchema()} pin the rows closed so
 * the runtime row and the declared schema cannot drift.
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
final class ShippingZoneMethodListShaper {

	/**
	 * Flat summary row for a single `wc/v3` shipping-zone-method list item.
	 *
	 * Each value is read with a null-coalescing default and cast to the type the
	 * WC shipping-zone-methods schema guarantees. `method_id` is the method TYPE
	 * slug (e.g. flat_rate), `instance_id` is this configured instance.
	 *
	 * The raw `settings` block is an object keyed by setting id where each entry is
	 * a full field descriptor with a `value`. This shaper collapses it to a compact
	 * `settings_summary`: a map of setting-id => the entry's `value` only, dropping
	 * the label, description, type, default, tip, placeholder, and options. A
	 * consumer reads what the method is configured to, without the schema noise.
	 *
	 * @param array<string,mixed> $row A single row from a `GET wc/v3/shipping/zones/{id}/methods` response.
	 * @return array{
	 *     instance_id:int,
	 *     method_id:string,
	 *     title:string,
	 *     enabled:bool,
	 *     order:int,
	 *     settings_summary:object
	 * } The flat summary row.
	 */
	public static function summary( array $row ): array {
		$summary = array();
		foreach ( (array) ( $row['settings'] ?? array() ) as $key => $setting ) {
			$setting         = (array) $setting;
			$summary[ $key ] = $setting['value'] ?? null;
		}

		return array(
			'instance_id'      => (int) ( $row['instance_id'] ?? 0 ),
			'method_id'        => (string) ( $row['method_id'] ?? '' ),
			'title'            => (string) ( $row['title'] ?? '' ),
			'enabled'          => (bool) ( $row['enabled'] ?? false ),
			'order'            => (int) ( $row['order'] ?? 0 ),
			'settings_summary' => (object) $summary,
		);
	}

	/**
	 * The `output_schema` item definition matching {@see self::summary()}.
	 *
	 * The top-level object is closed (`additionalProperties: false`). The single
	 * exception is `settings_summary`, declared `additionalProperties: true`: it is
	 * a free-form map of setting-id => scalar value whose keys vary by method type,
	 * so its members cannot be enumerated up front. This is the ONE object in this
	 * shaper that is intentionally open.
	 *
	 * @return array<string,mixed> A JSON-Schema object fragment.
	 */
	public static function itemSchema(): array {
		return array(
			'type'                 => 'object',
			'required'             => array( 'instance_id' ),
			'properties'           => array(
				'instance_id'      => array(
					'type'        => 'integer',
					'description' => __( 'The configured method instance ID within the zone. Read the full method with the get-shipping-zone-method ability.', 'abilities-catalog-woo' ),
				),
				'method_id'        => array(
					'type'        => 'string',
					'description' => __( 'The method type slug this instance is, e.g. flat_rate, free_shipping, or local_pickup.', 'abilities-catalog-woo' ),
				),
				'title'            => array(
					'type'        => 'string',
					'description' => __( 'The method title shown to shoppers at checkout for this instance.', 'abilities-catalog-woo' ),
				),
				'enabled'          => array(
					'type'        => 'boolean',
					'description' => __( 'Whether this method is enabled and offered to shoppers in the zone.', 'abilities-catalog-woo' ),
				),
				'order'            => array(
					'type'        => 'integer',
					'description' => __( 'The method sort position within the zone, in ascending sequence.', 'abilities-catalog-woo' ),
				),
				'settings_summary' => array(
					'type'                 => 'object',
					'description'          => __( 'A compact map of setting id to its configured value, e.g. {cost: "5.00", title: "Flat rate"}. Keys vary by method type, so this object is intentionally open.', 'abilities-catalog-woo' ),
					'additionalProperties' => true,
				),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * Flat detail row for a single `wc/v3` shipping-zone-method, for the
	 * get-shipping-zone-method ability.
	 *
	 * Returns every field from {@see self::summary()} — including the collapsed
	 * `settings_summary` — plus the method type's `method_title` and
	 * `method_description`, the two extra fields a consumer needs to read one
	 * configured method in full.
	 *
	 * @param array<string,mixed> $row A single row from a `GET wc/v3/shipping/zones/{id}/methods/{instance_id}` response.
	 * @return array<string,mixed> The flat detail row: the summary fields plus
	 *                             `method_title` and `method_description`.
	 */
	public static function detail( array $row ): array {
		return array_merge(
			self::summary( $row ),
			array(
				'method_title'       => (string) ( $row['method_title'] ?? '' ),
				'method_description' => (string) ( $row['method_description'] ?? '' ),
			)
		);
	}

	/**
	 * The `output_schema` definition matching {@see self::detail()}.
	 *
	 * Reuses {@see self::itemSchema()} for the summary fields and adds the two
	 * method-type string fields. The top-level object stays closed
	 * (`additionalProperties: false`); only the inherited `settings_summary` is open.
	 *
	 * @return array<string,mixed> A JSON-Schema object fragment.
	 */
	public static function detailSchema(): array {
		$schema = self::itemSchema();

		$schema['properties']['method_title'] = array(
			'type'        => 'string',
			'description' => __( 'The method type title, e.g. Flat rate.', 'abilities-catalog-woo' ),
		);

		$schema['properties']['method_description'] = array(
			'type'        => 'string',
			'description' => __( 'A short description of what the method type does, or an empty string when none is set.', 'abilities-catalog-woo' ),
		);

		return $schema;
	}
}
