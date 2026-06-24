<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogWoo\Support;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The catalog's only gateway to WooCommerce symbols.
 *
 * WooCommerce is an optional third-party dependency that may be inactive. Every
 * WooCommerce symbol the catalog touches passes through this facade, so the rest
 * of the code never references a `wc_*` function or `WC_*` class directly. That
 * keeps the availability guard and the WC-specific reads in one place:
 *
 * 1. The availability guard. {@see isActive()} is the single source of truth for
 *    "WooCommerce is installed and enabled". The WooCommerce abilities are
 *    {@see \GalatanOvidiu\AbilitiesCatalogWoo\Contracts\ConditionalAbility}s gated on
 *    it, so they do not register when WooCommerce is off.
 * 2. WooCommerce reads its REST routes do not expose come straight off this facade
 *    so an ability never holds a WooCommerce object.
 *
 * This is NOT under `includes/Abilities/`, so the Registry never treats it as an
 * ability.
 *
 * Two feature detectors are added later by the driver as pre-edits when the
 * batches that need them land (so they ship covered by tests), NOT now:
 *
 * - `hasBrandsSupport(): bool` — whether the product Brands feature is present
 *   (detected via the `/products/brands` REST route or the Brands feature class).
 *   Added with batch 03.
 * - `hasAnalytics(): bool` — whether WooCommerce Analytics is present (detected via
 *   the `wc-analytics` namespace / the Analytics feature). Added with batches 11–13.
 *
 * @since 0.1.0
 */
final class WooPlugin {

	/**
	 * Whether WooCommerce is installed and active.
	 *
	 * Detects WooCommerce by its main class, which loads with the plugin, so this
	 * is safe to call whether or not WooCommerce is active. It is the gate every
	 * WooCommerce ability and helper checks before touching a WooCommerce symbol.
	 *
	 * @return bool True when WooCommerce's main class is loaded.
	 */
	public static function isActive(): bool {
		return class_exists( 'WooCommerce' );
	}

	/**
	 * The typed error an ability returns if asked to run while WooCommerce is inactive.
	 *
	 * The WooCommerce abilities do not register when WooCommerce is off, so the
	 * Abilities API never routes a call here. This covers the defensive path of an
	 * ability instantiated and executed directly: it returns a clear, stable error
	 * rather than touching an undefined WooCommerce symbol.
	 *
	 * @return \WP_Error A `woocommerce_inactive` error with HTTP status 409.
	 */
	public static function unavailable(): WP_Error {
		return new WP_Error(
			'woocommerce_inactive',
			__( 'WooCommerce is not active, so WooCommerce abilities are unavailable.', 'abilities-catalog-woo' ),
			array( 'status' => 409 )
		);
	}
}
