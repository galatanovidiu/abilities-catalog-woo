<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogWoo\Mcp;

use GalatanOvidiu\AbilitiesCatalogWoo\Support\WooPlugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plugs the WooCommerce abilities into the Abilities Catalog MCP server.
 *
 * The catalog's search server already indexes these abilities from the live registry,
 * so this class is not what makes them reachable — it adds the *curated domain server*
 * surface on top. The curated server exposes one MCP tool per domain, not one per
 * ability, and is extensible through public filters. This class registers four domain
 * tools — `woocommerce-catalog`, `woocommerce-orders`, `woocommerce-config`, and
 * `woocommerce-reports` — grouping the `wc-*` abilities a store operator reasons about
 * together. Each tool's description and the abilities it owns live in one place.
 *
 * The abilities arrays start empty; the driver appends ability names to each tool
 * as the matching batch lands. A tool with an empty abilities array contributes no
 * routable abilities yet but reserves its slug and description.
 *
 * Every contribution is gated on {@see WooPlugin::isActive()} at filter-run time
 * (filters fire while the server boots, after plugins load), so when WooCommerce is
 * inactive the `wc-*` abilities do not register and no empty WooCommerce tool
 * appears. The filters are catalog hooks: when the catalog or its MCP server is
 * absent, nothing applies them and the add-on stays inert here.
 *
 * @since 0.1.0
 */
final class Integration {

	/**
	 * The `woocommerce-catalog` tool's ability names (wc-products), in tool order.
	 *
	 * @var list<string>
	 */
	private const WOOCOMMERCE_CATALOG_ABILITIES = array(
		'og-wc-products/list-products',
		'og-wc-products/get-product',
		'og-wc-products/list-product-variations',
		'og-wc-products/get-product-variation',
		'og-wc-products/list-product-custom-field-names',
		'og-wc-products/list-product-attributes',
		'og-wc-products/get-product-attribute',
		'og-wc-products/list-attribute-terms',
		'og-wc-products/get-attribute-term',
		'og-wc-products/list-product-categories',
		'og-wc-products/get-product-category',
		'og-wc-products/list-product-tags',
		'og-wc-products/get-product-tag',
		'og-wc-products/list-product-brands',
		'og-wc-products/get-product-brand',
		'og-wc-products/list-shipping-classes',
		'og-wc-products/get-shipping-class',
		'og-wc-products/list-product-reviews',
		'og-wc-products/get-product-review',
		'og-wc-products/create-product',
		'og-wc-products/update-product',
		'og-wc-products/duplicate-product',
		'og-wc-products/create-product-variation',
		'og-wc-products/update-product-variation',
		'og-wc-products/generate-product-variations',
		'og-wc-products/create-product-attribute',
		'og-wc-products/update-product-attribute',
		'og-wc-products/create-attribute-term',
		'og-wc-products/update-attribute-term',
		'og-wc-products/create-product-category',
		'og-wc-products/update-product-category',
		'og-wc-products/create-product-tag',
		'og-wc-products/update-product-tag',
		'og-wc-products/create-product-brand',
		'og-wc-products/update-product-brand',
		'og-wc-products/create-shipping-class',
		'og-wc-products/update-shipping-class',
		'og-wc-products/create-product-review',
		'og-wc-products/update-product-review',
		'og-wc-products/delete-product',
		'og-wc-products/delete-product-variation',
		'og-wc-products/delete-product-attribute',
		'og-wc-products/delete-attribute-term',
		'og-wc-products/delete-product-category',
		'og-wc-products/delete-product-tag',
		'og-wc-products/delete-product-brand',
		'og-wc-products/delete-shipping-class',
		'og-wc-products/delete-product-review',
	);

	/**
	 * The `woocommerce-orders` tool's ability names (wc-orders + wc-customers + wc-coupons), in tool order.
	 *
	 * @var list<string>
	 */
	private const WOOCOMMERCE_ORDERS_ABILITIES = array(
		'og-wc-orders/list-orders',
		'og-wc-orders/get-order',
		'og-wc-orders/list-order-statuses',
		'og-wc-orders/list-order-notes',
		'og-wc-orders/get-order-note',
		'og-wc-orders/list-order-refunds',
		'og-wc-orders/get-order-refund',
		'og-wc-orders/create-order',
		'og-wc-orders/update-order',
		'og-wc-orders/add-order-note',
		'og-wc-customers/list-customers',
		'og-wc-customers/get-customer',
		'og-wc-customers/list-customer-downloads',
		'og-wc-coupons/list-coupons',
		'og-wc-coupons/get-coupon',
		'og-wc-customers/create-customer',
		'og-wc-customers/update-customer',
		'og-wc-coupons/create-coupon',
		'og-wc-coupons/update-coupon',
		'og-wc-orders/delete-order',
		'og-wc-orders/delete-order-note',
		'og-wc-orders/delete-order-refund',
		'og-wc-coupons/delete-coupon',
		'og-wc-customers/delete-customer',
		'og-wc-orders/update-order-status',
		'og-wc-orders/send-order-email',
	);

	/**
	 * The `woocommerce-config` tool's ability names (wc-settings + wc-shipping + wc-taxes
	 * + wc-payment-gateways + wc-webhooks + wc-system + wc-data), in tool order.
	 *
	 * @var list<string>
	 */
	private const WOOCOMMERCE_CONFIG_ABILITIES = array(
		'og-wc-shipping/list-shipping-zones',
		'og-wc-shipping/get-shipping-zone',
		'og-wc-shipping/get-shipping-zone-locations',
		'og-wc-shipping/list-shipping-zone-methods',
		'og-wc-shipping/get-shipping-zone-method',
		'og-wc-shipping/list-shipping-methods',
		'og-wc-shipping/get-shipping-method',
		'og-wc-taxes/list-tax-rates',
		'og-wc-taxes/get-tax-rate',
		'og-wc-taxes/list-tax-classes',
		'og-wc-payment-gateways/list-payment-gateways',
		'og-wc-payment-gateways/get-payment-gateway',
		'og-wc-settings/list-setting-groups',
		'og-wc-settings/list-group-settings',
		'og-wc-settings/get-setting-option',
		'og-wc-webhooks/list-webhooks',
		'og-wc-webhooks/get-webhook',
		'og-wc-system/get-system-status',
		'og-wc-system/list-system-tools',
		'og-wc-system/get-system-tool',
		'og-wc-data/list-data-index',
		'og-wc-data/list-countries',
		'og-wc-data/get-country',
		'og-wc-data/list-currencies',
		'og-wc-data/get-current-currency',
		'og-wc-data/get-currency',
		'og-wc-data/list-continents',
		'og-wc-data/get-continent',
		'og-wc-shipping/create-shipping-zone',
		'og-wc-shipping/update-shipping-zone',
		'og-wc-shipping/update-shipping-zone-locations',
		'og-wc-shipping/create-shipping-zone-method',
		'og-wc-shipping/update-shipping-zone-method',
		'og-wc-taxes/create-tax-rate',
		'og-wc-taxes/update-tax-rate',
		'og-wc-taxes/create-tax-class',
		'og-wc-shipping/delete-shipping-zone',
		'og-wc-shipping/delete-shipping-zone-method',
		'og-wc-taxes/delete-tax-rate',
		'og-wc-taxes/delete-tax-class',
		'og-wc-webhooks/delete-webhook',
	);

	/**
	 * The `woocommerce-reports` tool's ability names (wc-reports, legacy + analytics), in tool order.
	 *
	 * @var list<string>
	 */
	private const WOOCOMMERCE_REPORTS_ABILITIES = array(
		'og-wc-reports/get-sales-report',
		'og-wc-reports/get-top-sellers-report',
		'og-wc-reports/get-orders-totals',
		'og-wc-reports/get-products-totals',
		'og-wc-reports/get-customers-totals',
		'og-wc-reports/get-coupons-totals',
		'og-wc-reports/get-reviews-totals',
		'og-wc-reports/get-revenue-stats',
		'og-wc-reports/list-orders-analytics',
		'og-wc-reports/get-orders-stats',
		'og-wc-reports/list-products-analytics',
		'og-wc-reports/get-products-stats',
		'og-wc-reports/get-performance-indicators',
		'og-wc-reports/get-leaderboards',
		'og-wc-reports/list-categories-analytics',
		'og-wc-reports/list-coupons-analytics',
		'og-wc-reports/get-coupons-stats',
		'og-wc-reports/list-taxes-analytics',
		'og-wc-reports/get-taxes-stats',
		'og-wc-reports/list-customers-analytics',
		'og-wc-reports/get-customers-stats',
		'og-wc-reports/list-stock-analytics',
		'og-wc-reports/get-stock-stats',
		'og-wc-reports/list-variations-analytics',
		'og-wc-reports/get-variations-stats',
		'og-wc-reports/list-downloads-analytics',
		'og-wc-reports/get-downloads-stats',
	);

	/**
	 * Registers the MCP filter hooks.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_filter( 'abilities_catalog_mcp_domains', array( self::class, 'contributeDomain' ) );
	}

	/**
	 * Registers the WooCommerce domain tools — their descriptions and the abilities they own.
	 *
	 * One call defines all four tools: the server builds them, routes each tool's
	 * abilities to it, and uses the description as the tool's routing blurb. Skipped
	 * when WooCommerce is inactive (the abilities are not registered then, so empty
	 * tools would only confuse an agent). Existing entries are preserved — this
	 * adds, it does not replace.
	 *
	 * @param array<string, array{description: string, abilities: list<string>}> $domains Add-on domain slug => its tool descriptor.
	 * @return array<string, array{description: string, abilities: list<string>}> The map including the WooCommerce tools.
	 */
	public static function contributeDomain( array $domains ): array {
		if ( ! WooPlugin::isActive() ) {
			return $domains;
		}

		$domains['woocommerce-catalog'] = array(
			'description' => __( 'Manage the WooCommerce product catalog — list, read, create, update, and delete products and product taxonomies.', 'abilities-catalog-woo' ),
			'abilities'   => self::WOOCOMMERCE_CATALOG_ABILITIES,
		);

		$domains['woocommerce-orders'] = array(
			'description' => __( 'Manage WooCommerce orders, customers, and coupons — list, read, create, update, and delete.', 'abilities-catalog-woo' ),
			'abilities'   => self::WOOCOMMERCE_ORDERS_ABILITIES,
		);

		$domains['woocommerce-config'] = array(
			'description' => __( 'Configure the WooCommerce store — settings, shipping, taxes, payment gateways, webhooks, system status, and reference data.', 'abilities-catalog-woo' ),
			'abilities'   => self::WOOCOMMERCE_CONFIG_ABILITIES,
		);

		$domains['woocommerce-reports'] = array(
			'description' => __( 'Read WooCommerce sales reports and analytics.', 'abilities-catalog-woo' ),
			'abilities'   => self::WOOCOMMERCE_REPORTS_ABILITIES,
		);

		return $domains;
	}
}
