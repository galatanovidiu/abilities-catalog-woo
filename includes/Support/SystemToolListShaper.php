<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogWoo\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Projects raw `wc/v3` system-status-tool rows into flat, closed rows for the
 * catalog's list-system-tools and get-system-tool abilities.
 *
 * A `GET wc/v3/system_status/tools` row describes one WooCommerce maintenance tool
 * ({id,name,action,description}). The `action` is the button label — what running
 * the tool will do. This shaper copies that small fixed field set and casts each
 * value to the type the WC tools schema promises. It carries no credentials and
 * holds nothing secret; the same shape serves both `list-system-tools` (per row)
 * and `get-system-tool` (one row).
 *
 * {@see self::itemSchema()} pins the row closed so the runtime row and the declared
 * schema cannot drift.
 *
 * This is NOT under `includes/Abilities/`, so the Registry never treats it as an
 * ability. It performs no WooCommerce calls and holds no ability logic; it only
 * shapes rows and declares their schema.
 *
 * @since 0.1.0
 */
final class SystemToolListShaper {

	/**
	 * Flat summary row for a single `wc/v3` system-status-tool item.
	 *
	 * Each value is read with a null-coalescing default and cast to the type the WC
	 * tools schema guarantees. The tool `id` is a string slug, e.g. `clear_transients`.
	 *
	 * @param array<string,mixed> $row A single row from a `GET wc/v3/system_status/tools` response.
	 * @return array{id:string,name:string,action:string,description:string} The flat tool summary row.
	 */
	public static function summary( array $row ): array {
		return array(
			'id'          => (string) ( $row['id'] ?? '' ),
			'name'        => (string) ( $row['name'] ?? '' ),
			'action'      => (string) ( $row['action'] ?? '' ),
			'description' => (string) ( $row['description'] ?? '' ),
		);
	}

	/**
	 * The `output_schema` item definition matching {@see self::summary()}.
	 *
	 * Used by both `list-system-tools` (per row) and `get-system-tool` (one row).
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
					'description' => __( 'The tool ID, a string slug such as "clear_transients". Pass it to the get-system-tool ability to read one tool.', 'abilities-catalog-woo' ),
				),
				'name'        => array(
					'type'        => 'string',
					'description' => __( 'The human readable tool name shown in the admin.', 'abilities-catalog-woo' ),
				),
				'action'      => array(
					'type'        => 'string',
					'description' => __( 'The action label — what running the tool will do.', 'abilities-catalog-woo' ),
				),
				'description' => array(
					'type'        => 'string',
					'description' => __( 'A description of what the tool does.', 'abilities-catalog-woo' ),
				),
			),
			'additionalProperties' => false,
		);
	}
}
