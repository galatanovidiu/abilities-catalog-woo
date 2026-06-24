<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogWoo\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Projects raw `wc/v3` order-note rows into flat, closed summary rows for the
 * catalog's order-note list ability.
 *
 * A WooCommerce order note is a WordPress comment on an order. A
 * `GET wc/v3/orders/{id}/notes` row carries the note content, its author, and
 * whether it is a customer-facing note. This shaper copies that small fixed field
 * set, casts each value to the type the WC notes schema promises, and treats
 * `customer_note` as a boolean (true when the note is shown to the customer,
 * false when it is for admin reference only). {@see self::itemSchema()} pins the
 * row closed so the runtime row and the declared schema cannot drift.
 *
 * This is NOT under `includes/Abilities/`, so the Registry never treats it as an
 * ability. It performs no WooCommerce calls and holds no ability logic; it only
 * shapes rows and declares their schema.
 *
 * @since 0.1.0
 */
final class OrderNoteListShaper {

	/**
	 * Flat summary row for a single `wc/v3` order-note list item.
	 *
	 * Each value is read with a null-coalescing default and cast to the type the
	 * WC notes schema guarantees. `author` is the note author name, or the literal
	 * `system` for notes WooCommerce records automatically. `customer_note` is
	 * cast to `bool`.
	 *
	 * @param array<string,mixed> $row A single row from a `GET wc/v3/orders/{id}/notes` response.
	 * @return array{
	 *     id:int,
	 *     author:string,
	 *     note:string,
	 *     customer_note:bool,
	 *     date_created:string
	 * } The flat note summary row.
	 */
	public static function summary( array $row ): array {
		return array(
			'id'            => (int) ( $row['id'] ?? 0 ),
			'author'        => (string) ( $row['author'] ?? '' ),
			'note'          => (string) ( $row['note'] ?? '' ),
			'customer_note' => (bool) ( $row['customer_note'] ?? false ),
			'date_created'  => (string) ( $row['date_created'] ?? '' ),
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
				'id'            => array(
					'type'        => 'integer',
					'description' => __( 'The order note ID.', 'abilities-catalog-woo' ),
				),
				'author'        => array(
					'type'        => 'string',
					'description' => __( 'The note author name, or "system" for notes WooCommerce records automatically.', 'abilities-catalog-woo' ),
				),
				'note'          => array(
					'type'        => 'string',
					'description' => __( 'The note content.', 'abilities-catalog-woo' ),
				),
				'customer_note' => array(
					'type'        => 'boolean',
					'description' => __( 'Whether the note is shown to the customer (true) or kept for admin reference only (false).', 'abilities-catalog-woo' ),
				),
				'date_created'  => array(
					'type'        => 'string',
					'description' => __( 'The creation date as an ISO-8601 date-time string in the site timezone.', 'abilities-catalog-woo' ),
				),
			),
			'additionalProperties' => false,
		);
	}
}
