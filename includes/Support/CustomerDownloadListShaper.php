<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogWoo\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Projects raw `wc/v3` customer-download rows into flat, closed summary rows for
 * the catalog's customer-downloads list ability.
 *
 * A `GET wc/v3/customers/{id}/downloads` row describes one downloadable file a
 * customer has access to: the product, the file name, how many downloads remain,
 * and when access expires. This shaper copies ONLY the seven safe identifying
 * fields a consumer needs to read what a customer can download.
 *
 * SECURITY — load-bearing redaction. This shaper MUST OMIT, and never copy, five
 * fields the controller puts on the raw row:
 * - `download_url` — the raw download URL is an UNAUTHENTICATED bearer link to
 *   the file. It embeds the customer email, the order key, and the download key
 *   as query parameters, so anyone holding the URL can download the file and can
 *   read the email and order key from it, with no further authentication. It is
 *   never safe to surface through an ability result.
 * - `order_key` — the order's secret key, part of the bearer link above and a
 *   secret used to look up the order without authentication.
 * - `email` — the customer email, also embedded in the bearer link.
 * - `access_expires_gmt` — redundant with `access_expires`; omitted as noise.
 * - `file` — the raw file block, which carries the direct file URL/path.
 *
 * Because the redaction is load-bearing, {@see self::summary()} builds its return
 * array from ONLY the seven named keys read explicitly off the row. It NEVER
 * spreads or merges the raw row, so a new field added upstream cannot leak
 * through. {@see self::itemSchema()} pins the row closed to exactly those seven
 * fields so the runtime row and the declared schema cannot drift.
 *
 * This is NOT under `includes/Abilities/`, so the Registry never treats it as an
 * ability. It performs no WooCommerce calls and holds no ability logic; it only
 * shapes rows and declares their schema.
 *
 * @since 0.1.0
 */
final class CustomerDownloadListShaper {

	/**
	 * Flat summary row for a single `wc/v3` customer-download list item.
	 *
	 * Each value is read with a null-coalescing default and cast to the type the
	 * WC customer-downloads schema guarantees. The return array is built from ONLY
	 * the seven named, non-sensitive keys; the raw row is never spread or merged,
	 * so the redacted fields documented on this class cannot leak through.
	 * `downloads_remaining` keeps the controller's string (the controller returns
	 * the literal 'unlimited' when there is no limit); `access_expires` keeps the
	 * controller's string (the controller returns the literal 'never' when access
	 * does not expire).
	 *
	 * @param array<string,mixed> $row A single row from a `GET wc/v3/customers/{id}/downloads` response.
	 * @return array{
	 *     download_id:string,
	 *     product_id:int,
	 *     product_name:string,
	 *     download_name:string,
	 *     order_id:int,
	 *     downloads_remaining:string,
	 *     access_expires:string
	 * } The flat summary row.
	 */
	public static function summary( array $row ): array {
		return array(
			'download_id'         => (string) ( $row['download_id'] ?? '' ),
			'product_id'          => (int) ( $row['product_id'] ?? 0 ),
			'product_name'        => (string) ( $row['product_name'] ?? '' ),
			'download_name'       => (string) ( $row['download_name'] ?? '' ),
			'order_id'            => (int) ( $row['order_id'] ?? 0 ),
			'downloads_remaining' => (string) ( $row['downloads_remaining'] ?? '' ),
			'access_expires'      => (string) ( $row['access_expires'] ?? '' ),
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
			'required'             => array( 'download_id' ),
			'properties'           => array(
				'download_id'         => array(
					'type'        => 'string',
					'description' => __( 'The download permission ID, used to identify this download grant.', 'abilities-catalog-woo' ),
				),
				'product_id'          => array(
					'type'        => 'integer',
					'description' => __( 'The downloadable product ID. Read the full product with og-wc-products/get-product.', 'abilities-catalog-woo' ),
				),
				'product_name'        => array(
					'type'        => 'string',
					'description' => __( 'The name of the downloadable product.', 'abilities-catalog-woo' ),
				),
				'download_name'       => array(
					'type'        => 'string',
					'description' => __( 'The name of the downloadable file.', 'abilities-catalog-woo' ),
				),
				'order_id'            => array(
					'type'        => 'integer',
					'description' => __( 'The order ID that granted this download. Read the full order with og-wc-orders/get-order.', 'abilities-catalog-woo' ),
				),
				'downloads_remaining' => array(
					'type'        => 'string',
					'description' => __( 'The number of downloads left as a string, or the literal "unlimited" when there is no limit.', 'abilities-catalog-woo' ),
				),
				'access_expires'      => array(
					'type'        => 'string',
					'description' => __( 'The access expiry date as an ISO-8601 date-time string in the site timezone, or the literal "never" when access does not expire.', 'abilities-catalog-woo' ),
				),
			),
			'additionalProperties' => false,
		);
	}
}
