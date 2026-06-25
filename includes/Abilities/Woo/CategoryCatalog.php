<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogWoo\Abilities\Woo;

use GalatanOvidiu\AbilitiesCatalogWoo\Contracts\CategoryProvider;
use GalatanOvidiu\AbilitiesCatalogWoo\Support\WooPlugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Category catalog for the WooCommerce ability group.
 *
 * The {@see \GalatanOvidiu\AbilitiesCatalogWoo\Registry} discovers this provider
 * alongside the abilities and registers its categories on
 * `wp_abilities_api_categories_init`. Every Woo ability references one of the
 * `wc-*` categories through `args()['category']`.
 *
 * The group's abilities only register when WooCommerce is active (they are
 * {@see \GalatanOvidiu\AbilitiesCatalogWoo\Contracts\ConditionalAbility}s), so the
 * categories gate on the same condition — when WooCommerce is off there are no
 * abilities to categorize and the catalog leaves no WooCommerce footprint. The
 * check is safe here because categories register after plugins have loaded, never
 * at file load.
 *
 * The full set of categories is declared up front (one per `wc-*` namespace), so a
 * later ability's `category` slug always exists regardless of which ability files
 * are present yet.
 *
 * @since 0.1.0
 */
final class CategoryCatalog implements CategoryProvider {

	/**
	 * {@inheritDoc}
	 */
	public function categories(): array {
		if ( ! WooPlugin::isActive() ) {
			return array();
		}

		return array(
			'og-wc-products'         => array(
				'slug'        => 'og-wc-products',
				'label'       => __( 'WooCommerce Products', 'abilities-catalog-woo' ),
				'description' => __( 'Abilities that read and write WooCommerce products, variations, and product taxonomies.', 'abilities-catalog-woo' ),
			),
			'og-wc-orders'           => array(
				'slug'        => 'og-wc-orders',
				'label'       => __( 'WooCommerce Orders', 'abilities-catalog-woo' ),
				'description' => __( 'Abilities that read and write WooCommerce orders, line items, and order notes.', 'abilities-catalog-woo' ),
			),
			'og-wc-customers'        => array(
				'slug'        => 'og-wc-customers',
				'label'       => __( 'WooCommerce Customers', 'abilities-catalog-woo' ),
				'description' => __( 'Abilities that read and write WooCommerce customers and their billing and shipping details.', 'abilities-catalog-woo' ),
			),
			'og-wc-coupons'          => array(
				'slug'        => 'og-wc-coupons',
				'label'       => __( 'WooCommerce Coupons', 'abilities-catalog-woo' ),
				'description' => __( 'Abilities that read and write WooCommerce discount coupons.', 'abilities-catalog-woo' ),
			),
			'og-wc-shipping'         => array(
				'slug'        => 'og-wc-shipping',
				'label'       => __( 'WooCommerce Shipping', 'abilities-catalog-woo' ),
				'description' => __( 'Abilities that read and write WooCommerce shipping zones, methods, and shipping classes.', 'abilities-catalog-woo' ),
			),
			'og-wc-taxes'            => array(
				'slug'        => 'og-wc-taxes',
				'label'       => __( 'WooCommerce Taxes', 'abilities-catalog-woo' ),
				'description' => __( 'Abilities that read and write WooCommerce tax rates and tax classes.', 'abilities-catalog-woo' ),
			),
			'og-wc-payment-gateways' => array(
				'slug'        => 'og-wc-payment-gateways',
				'label'       => __( 'WooCommerce Payment Gateways', 'abilities-catalog-woo' ),
				'description' => __( 'Abilities that read and configure WooCommerce payment gateways.', 'abilities-catalog-woo' ),
			),
			'og-wc-settings'         => array(
				'slug'        => 'og-wc-settings',
				'label'       => __( 'WooCommerce Settings', 'abilities-catalog-woo' ),
				'description' => __( 'Abilities that read and write WooCommerce store settings and setting options.', 'abilities-catalog-woo' ),
			),
			'og-wc-reports'          => array(
				'slug'        => 'og-wc-reports',
				'label'       => __( 'WooCommerce Reports', 'abilities-catalog-woo' ),
				'description' => __( 'Abilities that read WooCommerce sales reports and analytics.', 'abilities-catalog-woo' ),
			),
			'og-wc-webhooks'         => array(
				'slug'        => 'og-wc-webhooks',
				'label'       => __( 'WooCommerce Webhooks', 'abilities-catalog-woo' ),
				'description' => __( 'Abilities that read and write WooCommerce webhooks.', 'abilities-catalog-woo' ),
			),
			'og-wc-system'           => array(
				'slug'        => 'og-wc-system',
				'label'       => __( 'WooCommerce System', 'abilities-catalog-woo' ),
				'description' => __( 'Abilities that read WooCommerce system status and run system tools.', 'abilities-catalog-woo' ),
			),
			'og-wc-data'             => array(
				'slug'        => 'og-wc-data',
				'label'       => __( 'WooCommerce Data', 'abilities-catalog-woo' ),
				'description' => __( 'Abilities that read WooCommerce reference data such as countries, currencies, and continents.', 'abilities-catalog-woo' ),
			),
		);
	}
}
