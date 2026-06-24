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
 * The catalog exposes one curated MCP tool per domain, not one per ability, and is
 * extensible through public filters. This class is the add-on's whole MCP surface:
 * it registers four domain tools — `store-catalog`, `store-orders`, `store-config`,
 * and `store-reports` — grouping the `wc-*` abilities a store operator reasons
 * about together. Each tool's description and the abilities it owns live in one
 * place.
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
	 * The `store-catalog` tool's ability names (wc-products), in tool order.
	 *
	 * @var list<string>
	 */
	private const STORE_CATALOG_ABILITIES = array(
		'wc-products/list-products',
		'wc-products/get-product',
		'wc-products/list-product-variations',
		'wc-products/get-product-variation',
		'wc-products/list-product-custom-field-names',
		'wc-products/list-product-attributes',
		'wc-products/get-product-attribute',
		'wc-products/list-attribute-terms',
		'wc-products/get-attribute-term',
		'wc-products/list-product-categories',
		'wc-products/get-product-category',
		'wc-products/list-product-tags',
		'wc-products/get-product-tag',
		'wc-products/list-product-brands',
		'wc-products/get-product-brand',
		'wc-products/list-shipping-classes',
		'wc-products/get-shipping-class',
		'wc-products/list-product-reviews',
		'wc-products/get-product-review',
		'wc-products/create-product',
		'wc-products/update-product',
		'wc-products/duplicate-product',
		'wc-products/create-product-variation',
		'wc-products/update-product-variation',
		'wc-products/generate-product-variations',
		'wc-products/create-product-attribute',
		'wc-products/update-product-attribute',
		'wc-products/create-attribute-term',
		'wc-products/update-attribute-term',
		'wc-products/create-product-category',
		'wc-products/update-product-category',
		'wc-products/create-product-tag',
		'wc-products/update-product-tag',
		'wc-products/create-product-brand',
		'wc-products/update-product-brand',
		'wc-products/create-shipping-class',
		'wc-products/update-shipping-class',
		'wc-products/create-product-review',
		'wc-products/update-product-review',
	);

	/**
	 * The `store-orders` tool's ability names (wc-orders + wc-customers + wc-coupons), in tool order.
	 *
	 * @var list<string>
	 */
	private const STORE_ORDERS_ABILITIES = array(
		'wc-orders/list-orders',
		'wc-orders/get-order',
		'wc-orders/list-order-statuses',
		'wc-orders/list-order-notes',
		'wc-orders/get-order-note',
		'wc-orders/list-order-refunds',
		'wc-orders/get-order-refund',
		'wc-orders/create-order',
		'wc-orders/update-order',
		'wc-orders/add-order-note',
		'wc-customers/list-customers',
		'wc-customers/get-customer',
		'wc-customers/list-customer-downloads',
		'wc-coupons/list-coupons',
		'wc-coupons/get-coupon',
	);

	/**
	 * The `store-config` tool's ability names (wc-settings + wc-shipping + wc-taxes
	 * + wc-payment-gateways + wc-webhooks + wc-system + wc-data), in tool order.
	 *
	 * @var list<string>
	 */
	private const STORE_CONFIG_ABILITIES = array(
		'wc-shipping/list-shipping-zones',
		'wc-shipping/get-shipping-zone',
		'wc-shipping/get-shipping-zone-locations',
		'wc-shipping/list-shipping-zone-methods',
		'wc-shipping/get-shipping-zone-method',
		'wc-shipping/list-shipping-methods',
		'wc-shipping/get-shipping-method',
		'wc-taxes/list-tax-rates',
		'wc-taxes/get-tax-rate',
		'wc-taxes/list-tax-classes',
		'wc-payment-gateways/list-payment-gateways',
		'wc-payment-gateways/get-payment-gateway',
		'wc-settings/list-setting-groups',
		'wc-settings/list-group-settings',
		'wc-settings/get-setting-option',
		'wc-webhooks/list-webhooks',
		'wc-webhooks/get-webhook',
		'wc-system/get-system-status',
		'wc-system/list-system-tools',
		'wc-system/get-system-tool',
		'wc-data/list-data-index',
		'wc-data/list-countries',
		'wc-data/get-country',
		'wc-data/list-currencies',
		'wc-data/get-current-currency',
		'wc-data/get-currency',
		'wc-data/list-continents',
		'wc-data/get-continent',
	);

	/**
	 * The `store-reports` tool's ability names (wc-reports, legacy + analytics), in tool order.
	 *
	 * @var list<string>
	 */
	private const STORE_REPORTS_ABILITIES = array(
		'wc-reports/get-sales-report',
		'wc-reports/get-top-sellers-report',
		'wc-reports/get-orders-totals',
		'wc-reports/get-products-totals',
		'wc-reports/get-customers-totals',
		'wc-reports/get-coupons-totals',
		'wc-reports/get-reviews-totals',
		'wc-reports/get-revenue-stats',
		'wc-reports/list-orders-analytics',
		'wc-reports/get-orders-stats',
		'wc-reports/list-products-analytics',
		'wc-reports/get-products-stats',
		'wc-reports/get-performance-indicators',
		'wc-reports/get-leaderboards',
		'wc-reports/list-categories-analytics',
		'wc-reports/list-coupons-analytics',
		'wc-reports/get-coupons-stats',
		'wc-reports/list-taxes-analytics',
		'wc-reports/get-taxes-stats',
		'wc-reports/list-customers-analytics',
		'wc-reports/get-customers-stats',
		'wc-reports/list-stock-analytics',
		'wc-reports/get-stock-stats',
		'wc-reports/list-variations-analytics',
		'wc-reports/get-variations-stats',
		'wc-reports/list-downloads-analytics',
		'wc-reports/get-downloads-stats',
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

		$domains['store-catalog'] = array(
			'description' => __( 'Manage the WooCommerce product catalog — list, read, create, update, and delete products and product taxonomies.', 'abilities-catalog-woo' ),
			'abilities'   => self::STORE_CATALOG_ABILITIES,
		);

		$domains['store-orders'] = array(
			'description' => __( 'Manage WooCommerce orders, customers, and coupons — list, read, create, update, and delete.', 'abilities-catalog-woo' ),
			'abilities'   => self::STORE_ORDERS_ABILITIES,
		);

		$domains['store-config'] = array(
			'description' => __( 'Configure the WooCommerce store — settings, shipping, taxes, payment gateways, webhooks, system status, and reference data.', 'abilities-catalog-woo' ),
			'abilities'   => self::STORE_CONFIG_ABILITIES,
		);

		$domains['store-reports'] = array(
			'description' => __( 'Read WooCommerce sales reports and analytics.', 'abilities-catalog-woo' ),
			'abilities'   => self::STORE_REPORTS_ABILITIES,
		);

		return $domains;
	}
}
