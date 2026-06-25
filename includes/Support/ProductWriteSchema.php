<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogWoo\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shared writable JSON-Schema property fragments for the WooCommerce product
 * write abilities (batch 14, namespace `wc-products`).
 *
 * The six write abilities (create/update/duplicate product, create/update/
 * generate variation) expose the SAME small writable subset of the ~120-field
 * `wc/v3` product and variation schemas. Centralizing those fragments here keeps
 * the abilities in sync and pins the subset to fields confirmed present and
 * non-readonly in WC's own REST controllers. Each method returns a map of
 * property-name => JSON-Schema fragment; an ability merges it with its own
 * required keys (`name`, `id`, `product_id`) and sets `additionalProperties` on
 * the enclosing input object.
 *
 * Two deliberate shape choices, both source-verified:
 *
 * 1. The product `images` field is an ARRAY of image objects, but the variation
 *    `image` field is a SINGLE image object (not an array) — confirmed in
 *    class-wc-rest-product-variations-controller.php:814. {@see
 *    self::writableProperties()} vs {@see self::writableVariationProperties()}.
 * 2. The variation `status` enum is narrowed to draft/pending/private/publish.
 *    WC's variation schema declares `array_keys( get_post_statuses() )`
 *    (variations-controller.php:654-662), which does NOT include
 *    future/auto-draft/trash (unlike the product `status` enum); the explicit
 *    list here matches the spec's source-verified subset.
 *
 * Nested item objects (`{ id }` / `{ src }`) and the `dimensions` object close
 * with `additionalProperties => false` over the catalog's own subset, so a
 * caller passes only the fields the catalog drives. WC accepts JSON booleans, so
 * boolean fields pass through without coercion.
 *
 * This is NOT under `includes/Abilities/`, so the Registry never treats it as an
 * ability.
 *
 * @since 0.1.0
 */
final class ProductWriteSchema {

	/**
	 * The writable product input fields shared by create/update/duplicate.
	 *
	 * Excludes `name` (required on create, declared by each ability) and `id`
	 * (update only). Every field is confirmed non-readonly in
	 * class-wc-rest-products-controller.php.
	 *
	 * @return array<string,mixed> Property-name => JSON-Schema fragment.
	 */
	public static function writableProperties(): array {
		return array(
			'type'               => array(
				'type'        => 'string',
				'enum'        => array( 'simple', 'grouped', 'external', 'variable' ),
				'description' => __( 'The product type. Defaults to simple. Use variable to add variations afterward with og-wc-products/create-product-variation.', 'abilities-catalog-woo' ),
			),
			'status'             => array(
				'type'        => 'string',
				'enum'        => array( 'draft', 'pending', 'private', 'publish' ),
				'description' => __( 'The post status. publish makes the product live; draft keeps it hidden.', 'abilities-catalog-woo' ),
			),
			'catalog_visibility' => array(
				'type'        => 'string',
				'enum'        => array( 'visible', 'catalog', 'search', 'hidden' ),
				'description' => __( 'Where the product appears: visible (shop + search), catalog (shop only), search (search only), or hidden.', 'abilities-catalog-woo' ),
			),
			'description'        => array(
				'type'        => 'string',
				'description' => __( 'The full product description (HTML allowed).', 'abilities-catalog-woo' ),
			),
			'short_description'  => array(
				'type'        => 'string',
				'description' => __( 'The short product description shown near the price (HTML allowed).', 'abilities-catalog-woo' ),
			),
			'sku'                => array(
				'type'        => 'string',
				'description' => __( 'The stock keeping unit. Must be unique across products; a duplicate is rejected by the route.', 'abilities-catalog-woo' ),
			),
			'regular_price'      => array(
				'type'        => 'string',
				'description' => __( 'The regular (non-sale) price as a decimal string, e.g. "19.99".', 'abilities-catalog-woo' ),
			),
			'sale_price'         => array(
				'type'        => 'string',
				'description' => __( 'The sale price as a decimal string, e.g. "14.99". Leave empty to clear a sale.', 'abilities-catalog-woo' ),
			),
			'manage_stock'       => array(
				'type'        => 'boolean',
				'description' => __( 'Whether WooCommerce tracks a stock quantity for this product. Set true to use stock_quantity.', 'abilities-catalog-woo' ),
			),
			'stock_quantity'     => array(
				'type'        => 'integer',
				'description' => __( 'The managed stock count. Only used when manage_stock is true.', 'abilities-catalog-woo' ),
			),
			'stock_status'       => array(
				'type'        => 'string',
				'enum'        => array( 'instock', 'outofstock', 'onbackorder' ),
				'description' => __( 'The stock status when stock is not managed: instock, outofstock, or onbackorder.', 'abilities-catalog-woo' ),
			),
			'backorders'         => array(
				'type'        => 'string',
				'enum'        => array( 'no', 'notify', 'yes' ),
				'description' => __( 'Whether backorders are allowed when managing stock: no, notify (allow but warn), or yes.', 'abilities-catalog-woo' ),
			),
			'virtual'            => array(
				'type'        => 'boolean',
				'description' => __( 'Whether the product is virtual (no shipping).', 'abilities-catalog-woo' ),
			),
			'downloadable'       => array(
				'type'        => 'boolean',
				'description' => __( 'Whether the product is downloadable.', 'abilities-catalog-woo' ),
			),
			'weight'             => array(
				'type'        => 'string',
				'description' => __( 'The product weight as a decimal string in the store weight unit.', 'abilities-catalog-woo' ),
			),
			'dimensions'         => self::dimensionsSchema(),
			'categories'         => array(
				'type'        => 'array',
				'description' => __( 'The product categories, each given by its term ID. Discover category IDs with the store catalog reads. Replaces the current category set.', 'abilities-catalog-woo' ),
				'items'       => self::termRefSchema( __( 'The category term ID.', 'abilities-catalog-woo' ) ),
			),
			'tags'               => array(
				'type'        => 'array',
				'description' => __( 'The product tags, each given by its term ID. Replaces the current tag set.', 'abilities-catalog-woo' ),
				'items'       => self::termRefSchema( __( 'The tag term ID.', 'abilities-catalog-woo' ) ),
			),
			'images'             => array(
				'type'        => 'array',
				'description' => __( 'The product images, in gallery order; the first is the featured image. Each item is either { "id": <attachment ID> } for an existing media item or { "src": "<image URL>" } to sideload from a URL. Replaces the current gallery.', 'abilities-catalog-woo' ),
				'items'       => self::imageRefSchema(),
			),
		);
	}

	/**
	 * The writable variation input fields shared by create/update variation.
	 *
	 * Excludes `id` and the route-segment `product_id`, declared by each ability.
	 * Every field is confirmed non-readonly in
	 * class-wc-rest-product-variations-controller.php. The variation `image` is a
	 * SINGLE object (not an array), and the `status` enum omits
	 * future/auto-draft/trash.
	 *
	 * @return array<string,mixed> Property-name => JSON-Schema fragment.
	 */
	public static function writableVariationProperties(): array {
		return array(
			'description'    => array(
				'type'        => 'string',
				'description' => __( 'The variation description (HTML allowed).', 'abilities-catalog-woo' ),
			),
			'sku'            => array(
				'type'        => 'string',
				'description' => __( 'The variation stock keeping unit. Must be unique; a duplicate is rejected by the route.', 'abilities-catalog-woo' ),
			),
			'regular_price'  => array(
				'type'        => 'string',
				'description' => __( 'The regular (non-sale) variation price as a decimal string, e.g. "19.99".', 'abilities-catalog-woo' ),
			),
			'sale_price'     => array(
				'type'        => 'string',
				'description' => __( 'The variation sale price as a decimal string. Leave empty to clear a sale.', 'abilities-catalog-woo' ),
			),
			'status'         => array(
				'type'        => 'string',
				'enum'        => array( 'draft', 'pending', 'private', 'publish' ),
				'description' => __( 'The variation post status: publish makes it purchasable; draft keeps it hidden.', 'abilities-catalog-woo' ),
			),
			'virtual'        => array(
				'type'        => 'boolean',
				'description' => __( 'Whether the variation is virtual (no shipping).', 'abilities-catalog-woo' ),
			),
			'downloadable'   => array(
				'type'        => 'boolean',
				'description' => __( 'Whether the variation is downloadable.', 'abilities-catalog-woo' ),
			),
			'manage_stock'   => array(
				'type'        => 'boolean',
				'description' => __( 'Whether WooCommerce tracks a stock quantity for this variation. Set true to use stock_quantity.', 'abilities-catalog-woo' ),
			),
			'stock_quantity' => array(
				'type'        => 'integer',
				'description' => __( 'The managed stock count for this variation. Only used when manage_stock is true.', 'abilities-catalog-woo' ),
			),
			'stock_status'   => array(
				'type'        => 'string',
				'enum'        => array( 'instock', 'outofstock', 'onbackorder' ),
				'description' => __( 'The variation stock status when stock is not managed: instock, outofstock, or onbackorder.', 'abilities-catalog-woo' ),
			),
			'weight'         => array(
				'type'        => 'string',
				'description' => __( 'The variation weight as a decimal string in the store weight unit.', 'abilities-catalog-woo' ),
			),
			'dimensions'     => self::dimensionsSchema(),
			'image'          => array_merge(
				self::imageRefSchema(),
				array(
					'description' => __( 'The variation image: a SINGLE object (not an array), either { "id": <attachment ID> } for an existing media item or { "src": "<image URL>" } to sideload from a URL.', 'abilities-catalog-woo' ),
				)
			),
			'attributes'     => array(
				'type'        => 'array',
				'description' => __( 'The attribute selections that define this variation, e.g. {"name": "Color", "option": "Red"}. Identify each attribute by its name (or numeric id for a global attribute) and give the chosen option value.', 'abilities-catalog-woo' ),
				'items'       => self::variationAttributeSchema(),
			),
		);
	}

	/**
	 * The `dimensions` object fragment (length/width/height as strings).
	 *
	 * Matches WC's product/variation `dimensions` object
	 * (products-controller.php:1474, variations-controller.php:778), closed over
	 * the three writable dimension strings.
	 *
	 * @return array<string,mixed> A JSON-Schema object fragment.
	 */
	private static function dimensionsSchema(): array {
		return array(
			'type'                 => 'object',
			'description'          => __( 'The product dimensions as decimal strings in the store dimension unit.', 'abilities-catalog-woo' ),
			'properties'           => array(
				'length' => array(
					'type'        => 'string',
					'description' => __( 'The length as a decimal string.', 'abilities-catalog-woo' ),
				),
				'width'  => array(
					'type'        => 'string',
					'description' => __( 'The width as a decimal string.', 'abilities-catalog-woo' ),
				),
				'height' => array(
					'type'        => 'string',
					'description' => __( 'The height as a decimal string.', 'abilities-catalog-woo' ),
				),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * A `{ id }` term-reference item object (category / tag).
	 *
	 * @param string $description The `id` field description.
	 * @return array<string,mixed> A JSON-Schema object fragment.
	 */
	private static function termRefSchema( string $description ): array {
		return array(
			'type'                 => 'object',
			'required'             => array( 'id' ),
			'properties'           => array(
				'id' => array(
					'type'        => 'integer',
					'description' => $description,
				),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * An image-reference item object accepting `{ id }` or `{ src }`.
	 *
	 * Both keys are optional in the fragment so a caller may pass either an
	 * existing attachment `id` or a `src` URL to sideload; the object is closed
	 * to those two keys.
	 *
	 * @return array<string,mixed> A JSON-Schema object fragment.
	 */
	private static function imageRefSchema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'id'  => array(
					'type'        => 'integer',
					'description' => __( 'An existing media-library attachment ID.', 'abilities-catalog-woo' ),
				),
				'src' => array(
					'type'        => 'string',
					'format'      => 'uri',
					'description' => __( 'An image URL to sideload into the media library.', 'abilities-catalog-woo' ),
				),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * A variation `attributes` item object: `{ id|name, option }`.
	 *
	 * Identify the attribute by numeric `id` (a global attribute) or string
	 * `name` (a custom attribute), and give the chosen `option`. Matches
	 * variations-controller.php:875.
	 *
	 * @return array<string,mixed> A JSON-Schema object fragment.
	 */
	private static function variationAttributeSchema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'id'     => array(
					'type'        => 'integer',
					'description' => __( 'The global attribute ID. Use this OR name.', 'abilities-catalog-woo' ),
				),
				'name'   => array(
					'type'        => 'string',
					'description' => __( 'The attribute name (for a custom, product-level attribute). Use this OR id.', 'abilities-catalog-woo' ),
				),
				'option' => array(
					'type'        => 'string',
					'description' => __( 'The chosen option value for this attribute, e.g. "Red".', 'abilities-catalog-woo' ),
				),
			),
			'additionalProperties' => false,
		);
	}
}
