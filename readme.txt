=== Abilities Catalog — WooCommerce ===
Contributors: ovidiu-galatan
Tags: abilities-api, woocommerce, ai, mcp
Requires at least: 7.0
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 0.1.0
License: MIT
License URI: https://opensource.org/license/mit

Registers WooCommerce store operations as Abilities API abilities. An add-on for Abilities Catalog.

== Description ==

This plugin registers WooCommerce store operations on the WordPress Abilities API
so any Abilities API consumer can read and write products, orders, customers,
coupons, settings, reports, and more, each gated by WooCommerce's own capabilities.

It is **consumer-agnostic** and works standalone on the core Abilities API — it does
not require Abilities Catalog. When the optional Abilities Catalog MCP server is
active, this add-on contributes curated WooCommerce domain tools (store-catalog,
store-orders, store-config, store-reports) through the catalog's public filters. No
core files of Abilities Catalog are modified.

WooCommerce is a hard runtime dependency: while WooCommerce is inactive the
WooCommerce abilities do not register at all (they are absent from the Abilities API
rather than registered-and-denying), and the domain tools do not appear.

== Installation ==

1. Install and activate WooCommerce.
2. Install and activate this plugin. The WooCommerce abilities register automatically.
3. Optional: install Abilities Catalog and enable its MCP server to expose the
   WooCommerce domain tools.

== Changelog ==

= 0.1.0 =
* Initial scaffold: the add-on infrastructure (contracts, registry, the WooCommerce
  dependency facade, the category catalog, and the MCP domain tools). Abilities are
  added in later releases.
