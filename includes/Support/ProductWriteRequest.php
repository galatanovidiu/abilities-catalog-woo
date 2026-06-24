<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogWoo\Support;

use WP_REST_Request;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shared request-forwarding and result-shaping for the WooCommerce product write
 * abilities (batch 14, namespace `wc-products`).
 *
 * All six write abilities forward the same writable subset onto a `wc/v3`
 * product/variation REST request and project the result through the batch-01
 * {@see ProductListShaper}, so that logic lives here once.
 *
 * {@see self::fill()} forwards every PRESENT writable field from the validated
 * input onto the request via `set_param()`, skipping absent keys. The writable
 * key set is read from {@see ProductWriteSchema} (product vs variation), so the
 * forwarder stays in lockstep with the declared input schema. Values pass
 * through unchanged — WC accepts JSON strings, numbers, booleans, and arrays, so
 * no coercion is needed at fill time.
 *
 * Result shaping reuses {@see ProductListShaper::summary()} /
 * {@see ProductListShaper::variationSummary()}. The `previous_status` slot for
 * update-product is added by UpdateProduct itself (it owns the pre-read), not
 * here.
 *
 * This is NOT under `includes/Abilities/`, so the Registry never treats it as an
 * ability.
 *
 * @since 0.1.0
 */
final class ProductWriteRequest {

	/**
	 * Forwards the present writable fields from validated input onto the request.
	 *
	 * The writable key set comes from {@see ProductWriteSchema}: the variation
	 * subset when `$is_variation` is true, the product subset otherwise. Only
	 * keys present in `$input` are forwarded; an absent key is left untouched so
	 * an update changes only what the caller sent. The route-segment ids
	 * (`id`, `product_id`) are set on the request URL by the ability, not here.
	 *
	 * @param \WP_REST_Request<array<string,mixed>> $request      The save request to populate.
	 * @param array<string,mixed>                  $input        The validated ability input.
	 * @param bool                                 $is_variation True to use the variation writable subset; false for the product subset.
	 * @return void
	 */
	public static function fill( WP_REST_Request $request, array $input, bool $is_variation ): void {
		$writable = $is_variation
			? ProductWriteSchema::writableVariationProperties()
			: ProductWriteSchema::writableProperties();

		foreach ( array_keys( $writable ) as $field ) {
			if ( ! array_key_exists( $field, $input ) ) {
				continue;
			}

			$request->set_param( $field, $input[ $field ] );
		}
	}

	/**
	 * Projects a created/updated product REST response into the shaped result.
	 *
	 * Reuses {@see ProductListShaper::summary()} so the write result matches the
	 * read shape (`id`, `name`, `type`, `status`, `sku`, prices, stock,
	 * `permalink`, `edit_link`). The `previous_status` slot, when wanted, is added
	 * by the calling ability.
	 *
	 * @param array<string,mixed> $data The decoded `wc/v3` product REST response.
	 * @return array<string,mixed> The flat shaped product, per {@see ProductListShaper::summary()}.
	 */
	public static function shapeResult( array $data ): array {
		return ProductListShaper::summary( $data );
	}

	/**
	 * Projects a created/updated variation REST response into the shaped result.
	 *
	 * Reuses {@see ProductListShaper::variationSummary()} and adds the parent
	 * `product_id` (the route segment the variation belongs to), since the
	 * variation payload itself does not carry it.
	 *
	 * @param array<string,mixed> $data       The decoded `wc/v3` variation REST response.
	 * @param int                 $product_id The parent product ID.
	 * @return array<string,mixed> The flat shaped variation plus a `product_id` entry.
	 */
	public static function shapeVariationResult( array $data, int $product_id ): array {
		$row               = ProductListShaper::variationSummary( $data );
		$row['product_id'] = $product_id;

		return $row;
	}
}
