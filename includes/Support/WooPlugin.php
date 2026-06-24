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
 * Feature detectors are added by the driver as pre-edits when the batches that
 * need them land (so they ship covered by tests):
 *
 * - `hasBrandsSupport(): bool` — whether the product Brands feature is present
 *   (detected via the `/wc/v3/products/brands` REST route the feature registers).
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
	 * Whether the WooCommerce product Brands feature is present.
	 *
	 * The Brands feature registers the `/wc/v3/products/brands` REST route on
	 * `rest_api_init` (it is not part of the core `wc/v3` server). The two brand
	 * abilities wrap exactly that route, so detecting the route — rather than a
	 * feature class name or a WooCommerce version — is the truthful guard: it is
	 * true precisely when the surface those abilities call exists. Calling
	 * {@see rest_get_server()} boots the server and fires `rest_api_init` if it has
	 * not run yet, so the route is registered by the time abilities resolve.
	 *
	 * When this returns false the brand abilities do not register, so they degrade
	 * cleanly (absent) rather than denying.
	 *
	 * @return bool True when the `/wc/v3/products/brands` route is registered.
	 */
	public static function hasBrandsSupport(): bool {
		if ( ! self::isActive() ) {
			return false;
		}

		$routes = rest_get_server()->get_routes();

		return isset( $routes['/wc/v3/products/brands'] );
	}

	/**
	 * Whether the WooCommerce Analytics feature is enabled.
	 *
	 * Unlike the Brands feature (which registers its REST route eagerly on
	 * `rest_api_init`, so {@see hasBrandsSupport()} detects it by route presence), the
	 * `wc-analytics` namespace is **lazy-loaded**: its controllers register on
	 * `rest_pre_dispatch` only when a `wc-analytics` route is actually dispatched
	 * (`Init::rest_api_init()` → `RestApiUtil::lazy_load_namespace()`). So at
	 * ability-registration / `isAvailable()` / `hasPermission()` time — a normal admin,
	 * cron, or page context with no in-flight analytics request — `get_routes()` does
	 * NOT contain the analytics routes even when the feature is on. A route-presence
	 * check would wrongly report "absent".
	 *
	 * The truthful detector is the exact gate that controls registration:
	 * `Features::is_enabled( 'analytics' )` (`Init::rest_api_init()` registers the
	 * analytics controllers only inside `if ( Features::is_enabled( 'analytics' ) )`).
	 * The `class_exists()` guard keeps a WooCommerce build without WC-Admin (or a
	 * stripped build) returning false cleanly rather than fataling.
	 *
	 * When this returns false the analytics abilities do not register, so they degrade
	 * cleanly (absent) rather than denying. Dispatching an analytics route via
	 * {@see rest_do_request()} triggers the lazy registration, so the route is present
	 * by the time an analytics ability's `execute()` runs.
	 *
	 * @return bool True when WooCommerce Analytics is active.
	 */
	public static function hasAnalytics(): bool {
		if ( ! self::isActive() ) {
			return false;
		}

		$features = '\\Automattic\\WooCommerce\\Admin\\Features\\Features';

		return class_exists( $features ) && $features::is_enabled( 'analytics' );
	}

	/**
	 * The signing-secret presence and delivery-failure count for a webhook.
	 *
	 * Neither value is in the `wc/v3` webhook REST response a read dispatches: the
	 * `secret` is `edit`-context only (a read runs in `view` context and never
	 * requests it), and `failure_count` lives on the {@see \WC_Webhook} object but no
	 * controller adds it to the response. So they come off this facade, read from the
	 * WooCommerce data layer. The secret is read ONLY to test it for emptiness — its
	 * value is never returned — so a webhook read reports "deliveries are signed"
	 * without ever exposing the signing key.
	 *
	 * Returns the safe default `{ has_secret: false, failure_count: 0 }` when
	 * WooCommerce is inactive or the id matches no webhook.
	 *
	 * @param int $id The webhook id.
	 * @return array{has_secret: bool, failure_count: int} The derived delivery status.
	 */
	public static function webhookDeliveryStatus( int $id ): array {
		$default = array(
			'has_secret'    => false,
			'failure_count' => 0,
		);

		if ( ! self::isActive() ) {
			return $default;
		}

		$webhook = wc_get_webhook( $id );
		if ( ! $webhook ) {
			return $default;
		}

		return array(
			'has_secret'    => '' !== (string) $webhook->get_secret(),
			'failure_count' => (int) $webhook->get_failure_count(),
		);
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
