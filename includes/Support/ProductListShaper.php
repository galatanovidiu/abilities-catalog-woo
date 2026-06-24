<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogWoo\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Projects raw `wc/v3` product and product-variation list rows into flat,
 * closed summary rows for the catalog's list abilities.
 *
 * A `GET wc/v3/products` row carries dozens of nested fields — images, meta,
 * dimensions, tax classes — most of which a list consumer never reads. This
 * shaper copies the small fixed field set a consumer needs to scan, place, and
 * follow up on a product, casts each value to the type the WC schema promises
 * (prices are STRINGS in wc/v3; `stock_quantity` is `integer|null`), and ADDS a
 * ready-to-use `edit_link` to the wp-admin editor. {@see self::itemSchema()} and
 * {@see self::variationItemSchema()} pin the rows closed so the runtime row and
 * the declared schema cannot drift.
 *
 * This is NOT under `includes/Abilities/`, so the Registry never treats it as an
 * ability. It performs no WooCommerce calls and holds no ability logic; it only
 * shapes rows and declares their schema.
 *
 * @since 0.1.0
 */
final class ProductListShaper {

	/**
	 * Flat summary row for a single `wc/v3` product list item.
	 *
	 * Each value is read with a null-coalescing default and cast to the type the
	 * WC products schema guarantees. `stock_quantity` is kept as `null` when the
	 * product does not manage stock, and cast to `int` otherwise.
	 *
	 * @param array<string,mixed> $row A single row from a `GET wc/v3/products` response.
	 * @return array{
	 *     id:int,
	 *     name:string,
	 *     type:string,
	 *     status:string,
	 *     sku:string,
	 *     price:string,
	 *     regular_price:string,
	 *     sale_price:string,
	 *     stock_status:string,
	 *     stock_quantity:int|null,
	 *     catalog_visibility:string,
	 *     permalink:string,
	 *     date_created:string,
	 *     edit_link:string
	 * } The flat summary row.
	 */
	public static function summary( array $row ): array {
		$id             = (int) ( $row['id'] ?? 0 );
		$stock_quantity = $row['stock_quantity'] ?? null;

		return array(
			'id'                 => $id,
			'name'               => (string) ( $row['name'] ?? '' ),
			'type'               => (string) ( $row['type'] ?? '' ),
			'status'             => (string) ( $row['status'] ?? '' ),
			'sku'                => (string) ( $row['sku'] ?? '' ),
			'price'              => (string) ( $row['price'] ?? '' ),
			'regular_price'      => (string) ( $row['regular_price'] ?? '' ),
			'sale_price'         => (string) ( $row['sale_price'] ?? '' ),
			'stock_status'       => (string) ( $row['stock_status'] ?? '' ),
			'stock_quantity'     => null === $stock_quantity ? null : (int) $stock_quantity,
			'catalog_visibility' => (string) ( $row['catalog_visibility'] ?? '' ),
			'permalink'          => (string) ( $row['permalink'] ?? '' ),
			'date_created'       => (string) ( $row['date_created'] ?? '' ),
			'edit_link'          => admin_url( 'post.php?post=' . $id . '&action=edit' ),
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
				'id'                 => array(
					'type'        => 'integer',
					'description' => __( 'The product ID. Read the full product with wc-products/get-product.', 'abilities-catalog-woo' ),
				),
				'name'               => array(
					'type'        => 'string',
					'description' => __( 'The product name shown to shoppers.', 'abilities-catalog-woo' ),
				),
				'type'               => array(
					'type'        => 'string',
					'description' => __( 'The product type, e.g. simple, variable, grouped, or external.', 'abilities-catalog-woo' ),
				),
				'status'             => array(
					'type'        => 'string',
					'description' => __( 'The post status, e.g. publish, draft, pending, or private.', 'abilities-catalog-woo' ),
				),
				'sku'                => array(
					'type'        => 'string',
					'description' => __( 'The stock keeping unit, or an empty string when none is set.', 'abilities-catalog-woo' ),
				),
				'price'              => array(
					'type'        => 'string',
					'description' => __( 'The current price as a decimal string in the store currency; empty when no price is set.', 'abilities-catalog-woo' ),
				),
				'regular_price'      => array(
					'type'        => 'string',
					'description' => __( 'The regular (non-sale) price as a decimal string; empty when none is set.', 'abilities-catalog-woo' ),
				),
				'sale_price'         => array(
					'type'        => 'string',
					'description' => __( 'The sale price as a decimal string, or an empty string when the product is not on sale.', 'abilities-catalog-woo' ),
				),
				'stock_status'       => array(
					'type'        => 'string',
					'description' => __( 'The stock status: instock, outofstock, or onbackorder.', 'abilities-catalog-woo' ),
				),
				'stock_quantity'     => array(
					'type'        => array( 'integer', 'null' ),
					'description' => __( 'The managed stock count as an integer, or null when the product does not manage stock.', 'abilities-catalog-woo' ),
				),
				'catalog_visibility' => array(
					'type'        => 'string',
					'description' => __( 'Where the product appears: visible, catalog, search, or hidden.', 'abilities-catalog-woo' ),
				),
				'permalink'          => array(
					'type'        => 'string',
					'description' => __( 'The public product URL.', 'abilities-catalog-woo' ),
				),
				'date_created'       => array(
					'type'        => 'string',
					'description' => __( 'The creation date as an ISO-8601 date-time string in the site timezone.', 'abilities-catalog-woo' ),
				),
				'edit_link'          => array(
					'type'        => 'string',
					'description' => __( 'The wp-admin URL for editing this product. Open it to edit the product in the dashboard.', 'abilities-catalog-woo' ),
				),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * Flat summary row for a single `wc/v3` product-variation list item.
	 *
	 * Each value is read with a null-coalescing default and cast to the type the
	 * WC variations schema guarantees. `stock_quantity` is kept as `null` when the
	 * variation does not manage stock. The raw variation attributes are reduced to
	 * `name`/`option` pairs — the two fields a consumer needs to read the chosen
	 * option — with both cast to string.
	 *
	 * @param array<string,mixed> $row A single row from a `GET wc/v3/products/{id}/variations` response.
	 * @return array{
	 *     id:int,
	 *     sku:string,
	 *     price:string,
	 *     regular_price:string,
	 *     sale_price:string,
	 *     stock_status:string,
	 *     stock_quantity:int|null,
	 *     status:string,
	 *     attributes:list<array{name:string,option:string}>,
	 *     permalink:string,
	 *     edit_link:string
	 * } The flat variation summary row.
	 */
	public static function variationSummary( array $row ): array {
		$id             = (int) ( $row['id'] ?? 0 );
		$stock_quantity = $row['stock_quantity'] ?? null;

		$attributes = array();
		foreach ( (array) ( $row['attributes'] ?? array() ) as $attribute ) {
			$attribute    = (array) $attribute;
			$attributes[] = array(
				'name'   => (string) ( $attribute['name'] ?? '' ),
				'option' => (string) ( $attribute['option'] ?? '' ),
			);
		}

		return array(
			'id'             => $id,
			'sku'            => (string) ( $row['sku'] ?? '' ),
			'price'          => (string) ( $row['price'] ?? '' ),
			'regular_price'  => (string) ( $row['regular_price'] ?? '' ),
			'sale_price'     => (string) ( $row['sale_price'] ?? '' ),
			'stock_status'   => (string) ( $row['stock_status'] ?? '' ),
			'stock_quantity' => null === $stock_quantity ? null : (int) $stock_quantity,
			'status'         => (string) ( $row['status'] ?? '' ),
			'attributes'     => $attributes,
			'permalink'      => (string) ( $row['permalink'] ?? '' ),
			'edit_link'      => admin_url( 'post.php?post=' . $id . '&action=edit' ),
		);
	}

	/**
	 * The `output_schema` item definition matching {@see self::variationSummary()}.
	 *
	 * @return array<string,mixed> A JSON-Schema object fragment.
	 */
	public static function variationItemSchema(): array {
		return array(
			'type'                 => 'object',
			'required'             => array( 'id' ),
			'properties'           => array(
				'id'             => array(
					'type'        => 'integer',
					'description' => __( 'The variation ID. Read the full variation with wc-products/get-product-variation.', 'abilities-catalog-woo' ),
				),
				'sku'            => array(
					'type'        => 'string',
					'description' => __( 'The variation stock keeping unit, or an empty string when none is set.', 'abilities-catalog-woo' ),
				),
				'price'          => array(
					'type'        => 'string',
					'description' => __( 'The current variation price as a decimal string; empty when no price is set.', 'abilities-catalog-woo' ),
				),
				'regular_price'  => array(
					'type'        => 'string',
					'description' => __( 'The regular (non-sale) variation price as a decimal string; empty when none is set.', 'abilities-catalog-woo' ),
				),
				'sale_price'     => array(
					'type'        => 'string',
					'description' => __( 'The variation sale price as a decimal string, or an empty string when not on sale.', 'abilities-catalog-woo' ),
				),
				'stock_status'   => array(
					'type'        => 'string',
					'description' => __( 'The variation stock status: instock, outofstock, or onbackorder.', 'abilities-catalog-woo' ),
				),
				'stock_quantity' => array(
					'type'        => array( 'integer', 'null' ),
					'description' => __( 'The managed stock count as an integer, or null when the variation does not manage stock.', 'abilities-catalog-woo' ),
				),
				'status'         => array(
					'type'        => 'string',
					'description' => __( 'The variation post status, e.g. publish or private.', 'abilities-catalog-woo' ),
				),
				'attributes'     => array(
					'type'        => 'array',
					'description' => __( 'The attribute selections that define this variation, e.g. {name: Color, option: Red}.', 'abilities-catalog-woo' ),
					'items'       => array(
						'type'                 => 'object',
						'properties'           => array(
							'name'   => array(
								'type'        => 'string',
								'description' => __( 'The attribute name, e.g. Color.', 'abilities-catalog-woo' ),
							),
							'option' => array(
								'type'        => 'string',
								'description' => __( 'The chosen option for this attribute, e.g. Red.', 'abilities-catalog-woo' ),
							),
						),
						'additionalProperties' => false,
					),
				),
				'permalink'      => array(
					'type'        => 'string',
					'description' => __( 'The public URL for this variation.', 'abilities-catalog-woo' ),
				),
				'edit_link'      => array(
					'type'        => 'string',
					'description' => __( 'The wp-admin URL for editing this variation\'s parent product. Open it to edit in the dashboard.', 'abilities-catalog-woo' ),
				),
			),
			'additionalProperties' => false,
		);
	}
}
