<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogWoo\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Projects raw `wc/v3` coupon rows into flat, closed rows for the catalog's
 * coupon list and get-coupon abilities.
 *
 * A `GET wc/v3/coupons` row carries the usage restrictions, email restrictions,
 * the list of users who have used the coupon, and category lists — most of which
 * a consumer never reads to scan or act on a coupon. This shaper exposes two
 * shapes: {@see self::summary()} copies the small fixed field set a consumer
 * needs to scan a list of coupons, and {@see self::detail()} adds the
 * description, the product include/exclude ID lists, and the amount thresholds a
 * consumer needs to read one coupon in full. Each value is cast to the type the
 * WC coupons schema promises (amounts are decimal STRINGS in wc/v3;
 * `usage_limit` is `integer|null`). {@see self::itemSchema()} and
 * {@see self::detailSchema()} pin the rows closed so the runtime row and the
 * declared schema cannot drift.
 *
 * Field-name notes from the wc/v3 coupons controller (the V2 controller builds the
 * V3 response row; V1's older `expiry_date`/`exclude_product_ids` names do NOT apply):
 * - The REST response date key is `date_expires` (V2 controller line ~190). This
 *   shaper reads `date_expires` and exposes it under the same name.
 * - The REST response excludes key is `excluded_product_ids` (V2 controller line
 *   ~195). This shaper reads `excluded_product_ids` and exposes it under the stable
 *   name `exclude_product_ids`.
 *
 * This is NOT under `includes/Abilities/`, so the Registry never treats it as an
 * ability. It performs no WooCommerce calls and holds no ability logic; it only
 * shapes rows and declares their schema.
 *
 * @since 0.1.0
 */
final class CouponListShaper {

	/**
	 * Flat summary row for a single `wc/v3` coupon list item.
	 *
	 * Each value is read with a null-coalescing default and cast to the type the
	 * WC coupons schema guarantees. `amount` is kept as a string because wc/v3
	 * returns it as a decimal string. `usage_limit` is kept as `null` when the
	 * coupon has no usage limit, and cast to `int` otherwise.
	 *
	 * @param array<string,mixed> $row A single row from a `GET wc/v3/coupons` response.
	 * @return array{
	 *     id:int,
	 *     code:string,
	 *     amount:string,
	 *     discount_type:string,
	 *     date_expires:string,
	 *     usage_count:int,
	 *     usage_limit:int|null,
	 *     individual_use:bool
	 * } The flat summary row.
	 */
	public static function summary( array $row ): array {
		$usage_limit = $row['usage_limit'] ?? null;

		return array(
			'id'             => (int) ( $row['id'] ?? 0 ),
			'code'           => (string) ( $row['code'] ?? '' ),
			'amount'         => (string) ( $row['amount'] ?? '' ),
			'discount_type'  => (string) ( $row['discount_type'] ?? '' ),
			'date_expires'   => (string) ( $row['date_expires'] ?? '' ),
			'usage_count'    => (int) ( $row['usage_count'] ?? 0 ),
			'usage_limit'    => null === $usage_limit ? null : (int) $usage_limit,
			'individual_use' => (bool) ( $row['individual_use'] ?? false ),
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
				'id'             => array(
					'type'        => 'integer',
					'description' => __( 'The coupon ID. Read the full coupon with wc-coupons/get-coupon.', 'abilities-catalog-woo' ),
				),
				'code'           => array(
					'type'        => 'string',
					'description' => __( 'The coupon code shoppers enter at checkout.', 'abilities-catalog-woo' ),
				),
				'amount'         => array(
					'type'        => 'string',
					'description' => __( 'The discount amount as a decimal string; a currency value for fixed discounts, or a percentage for percent discounts.', 'abilities-catalog-woo' ),
				),
				'discount_type'  => array(
					'type'        => 'string',
					'description' => __( 'The discount type, e.g. percent, fixed_cart, or fixed_product.', 'abilities-catalog-woo' ),
				),
				'date_expires'   => array(
					'type'        => 'string',
					'description' => __( 'The expiry date as an ISO-8601 date-time string, or an empty string when the coupon never expires.', 'abilities-catalog-woo' ),
				),
				'usage_count'    => array(
					'type'        => 'integer',
					'description' => __( 'The number of times the coupon has already been used.', 'abilities-catalog-woo' ),
				),
				'usage_limit'    => array(
					'type'        => array( 'integer', 'null' ),
					'description' => __( 'The total number of times the coupon can be used, or null when there is no limit.', 'abilities-catalog-woo' ),
				),
				'individual_use' => array(
					'type'        => 'boolean',
					'description' => __( 'Whether the coupon can only be used alone, removing other applied coupons from the cart.', 'abilities-catalog-woo' ),
				),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * Flat detail row for a single `wc/v3` coupon, for the get-coupon ability.
	 *
	 * Returns every field from {@see self::summary()} plus the coupon description,
	 * the product include/exclude ID lists, and the amount thresholds. Each ID in
	 * the product lists is cast to `int`; the amount thresholds are kept as
	 * strings, matching the wc/v3 schema.
	 *
	 * @param array<string,mixed> $row A single row from a `GET wc/v3/coupons/{id}` response.
	 * @return array<string,mixed> The flat detail row: the summary fields plus
	 *                             `description`, `product_ids`, `exclude_product_ids`,
	 *                             `minimum_amount`, and `maximum_amount`.
	 */
	public static function detail( array $row ): array {
		$product_ids = array();
		foreach ( (array) ( $row['product_ids'] ?? array() ) as $product_id ) {
			$product_ids[] = (int) $product_id;
		}

		$exclude_product_ids = array();
		foreach ( (array) ( $row['excluded_product_ids'] ?? array() ) as $product_id ) {
			$exclude_product_ids[] = (int) $product_id;
		}

		return array_merge(
			self::summary( $row ),
			array(
				'description'         => (string) ( $row['description'] ?? '' ),
				'product_ids'         => $product_ids,
				'exclude_product_ids' => $exclude_product_ids,
				'minimum_amount'      => (string) ( $row['minimum_amount'] ?? '' ),
				'maximum_amount'      => (string) ( $row['maximum_amount'] ?? '' ),
			)
		);
	}

	/**
	 * The `output_schema` definition matching {@see self::detail()}.
	 *
	 * Reuses {@see self::itemSchema()} for the summary fields and adds the
	 * description, the product include/exclude ID arrays, and the amount
	 * thresholds for the detail fields.
	 *
	 * @return array<string,mixed> A JSON-Schema object fragment.
	 */
	public static function detailSchema(): array {
		$schema = self::itemSchema();

		$schema['properties']['description'] = array(
			'type'        => 'string',
			'description' => __( 'The internal coupon description, or an empty string when none is set.', 'abilities-catalog-woo' ),
		);

		$schema['properties']['product_ids'] = array(
			'type'        => 'array',
			'description' => __( 'The list of product IDs the coupon can be used on. Empty when the coupon applies to all products.', 'abilities-catalog-woo' ),
			'items'       => array(
				'type' => 'integer',
			),
		);

		$schema['properties']['exclude_product_ids'] = array(
			'type'        => 'array',
			'description' => __( 'The list of product IDs the coupon cannot be used on.', 'abilities-catalog-woo' ),
			'items'       => array(
				'type' => 'integer',
			),
		);

		$schema['properties']['minimum_amount'] = array(
			'type'        => 'string',
			'description' => __( 'The minimum order amount required before the coupon applies, as a decimal string; empty when none is set.', 'abilities-catalog-woo' ),
		);

		$schema['properties']['maximum_amount'] = array(
			'type'        => 'string',
			'description' => __( 'The maximum order amount allowed when using the coupon, as a decimal string; empty when none is set.', 'abilities-catalog-woo' ),
		);

		return $schema;
	}
}
