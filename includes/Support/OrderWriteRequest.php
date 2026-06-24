<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogWoo\Support;

use WP_REST_Request;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shared request-forwarding and result-shaping for the WooCommerce order write
 * abilities (batch 17, namespace `wc-orders`): `create-order` and `update-order`.
 *
 * Both abilities forward the same writable subset onto a `wc/v3` order REST
 * request and project the result through the batch-04 {@see OrderListShaper} in
 * its get-order DETAIL shape, so that logic lives here once.
 *
 * {@see self::fill()} forwards every PRESENT writable field from the validated
 * input onto the request via `set_param()`, skipping absent keys. The writable
 * key set is read from {@see OrderWriteSchema::writableProperties()} PLUS
 * `status`: `status` is forwarded only when present, so create-order (whose input
 * schema merges in {@see OrderWriteSchema::createStatusProperty()}) can pass it,
 * while update-order — whose input schema never declares `status` — simply never
 * has it to forward. Values pass through unchanged: WC accepts JSON strings,
 * numbers, booleans, and arrays, so no coercion is needed at fill time.
 *
 * Result shaping reuses the EXISTING {@see OrderListShaper::detail()} /
 * {@see OrderListShaper::detailSchema()} — the same get-order detail shape the
 * read abilities use — so the write result never leaks the raw ~100-field order
 * or its `meta_data`. This class does NOT duplicate the shaper's field logic.
 *
 * This is NOT under `includes/Abilities/`, so the Registry never treats it as an
 * ability.
 *
 * @since 0.1.0
 */
final class OrderWriteRequest {

	/**
	 * Forwards the present writable fields from validated input onto the request.
	 *
	 * The forwarded key set is {@see OrderWriteSchema::writableProperties()} plus
	 * `status`. Only keys present in `$input` are forwarded; an absent key is left
	 * untouched so an update changes only what the caller sent. `status` is
	 * forwarded when present (create-order may send it); update-order never
	 * declares `status` in its input schema, so it is never present there. The
	 * route-segment id (`id`) is set on the request URL by the ability, not here.
	 *
	 * @param \WP_REST_Request<array<string,mixed>> $request The save request to populate.
	 * @param array<string,mixed>                  $input   The validated ability input.
	 * @return void
	 */
	public static function fill( WP_REST_Request $request, array $input ): void {
		$fields   = array_keys( OrderWriteSchema::writableProperties() );
		$fields[] = 'status';

		foreach ( $fields as $field ) {
			if ( ! array_key_exists( $field, $input ) ) {
				continue;
			}

			$request->set_param( $field, $input[ $field ] );
		}
	}

	/**
	 * Projects a created/updated order REST response into the shaped result.
	 *
	 * Reuses {@see OrderListShaper::detail()} so the write result matches the
	 * get-order read shape (the summary fields plus `line_items`, the trimmed
	 * `billing`/`shipping` blocks, totals, and `edit_link`).
	 *
	 * @param array<string,mixed> $data The decoded `wc/v3` order REST response.
	 * @return array<string,mixed> The flat shaped order, per {@see OrderListShaper::detail()}.
	 */
	public static function shapeResult( array $data ): array {
		return OrderListShaper::detail( $data );
	}

	/**
	 * The output schema shared by create-order and update-order.
	 *
	 * Reuses {@see OrderListShaper::detailSchema()} so the declared output schema
	 * and the shaped row from {@see self::shapeResult()} cannot drift.
	 *
	 * @return array<string,mixed> A JSON-Schema object fragment.
	 */
	public static function outputSchema(): array {
		return OrderListShaper::detailSchema();
	}
}
