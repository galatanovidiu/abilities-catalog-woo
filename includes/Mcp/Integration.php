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
	);

	/**
	 * The `store-orders` tool's ability names (wc-orders + wc-customers + wc-coupons), in tool order.
	 *
	 * @var list<string>
	 */
	private const STORE_ORDERS_ABILITIES = array();

	/**
	 * The `store-config` tool's ability names (wc-settings + wc-shipping + wc-taxes
	 * + wc-payment-gateways + wc-webhooks + wc-system + wc-data), in tool order.
	 *
	 * @var list<string>
	 */
	private const STORE_CONFIG_ABILITIES = array();

	/**
	 * The `store-reports` tool's ability names (wc-reports, legacy + analytics), in tool order.
	 *
	 * @var list<string>
	 */
	private const STORE_REPORTS_ABILITIES = array();

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
