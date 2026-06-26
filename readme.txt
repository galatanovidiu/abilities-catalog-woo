=== Abilities Catalog — WooCommerce ===
Contributors: ovidiu-galatan
Tags: abilities-api, woocommerce, ai, mcp, agents
Requires at least: 6.9
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 0.1.0
License: MIT
License URI: https://opensource.org/license/mit

Registers 142 WooCommerce store operations on the WordPress Abilities API, so any Abilities API consumer can run them.

== Description ==

This add-on registers 142 WooCommerce store operations on the WordPress Abilities
API (products, orders, customers, coupons, settings, reports, and more), so any
Abilities API consumer can run them. When the [Abilities Catalog](https://github.com/galatanovidiu/abilities-catalog)
MCP server is active, agents reach these abilities through the same search-based
surface as core abilities — no separate server per plugin.

It works standalone on the core Abilities API. Abilities Catalog is optional. When
the catalog is present and its MCP server is on, the WooCommerce abilities become
discoverable through the catalog's search server, and the add-on also contributes
four curated WooCommerce domain tools. It edits no catalog files: it plugs in
through the catalog's public filters.

WooCommerce is a hard runtime dependency. While WooCommerce is inactive the
abilities do not register at all — they are absent from the Abilities API, not
registered-and-denying.

**What it registers**

142 abilities across 12 WooCommerce domains: products (48), reports (27), orders
(15), shipping (14), reference data (8), taxes (8), customers (6), coupons (5),
settings (3), system (3), webhooks (3), and payment gateways (2).

Ability names use a `domain/verb-noun` shape, for example `og-wc-products/list-products`,
`og-wc-orders/create-order`, and `og-wc-coupons/update-coupon`. Each ability wraps a
WooCommerce REST route, declares an input and output schema, points at a category,
enforces a server-side `permission_callback`, and carries risk annotations. Of the
142 abilities, 86 are read-only and 19 are destructive (permanent deletes); the rest
are non-destructive writes.

**How agents reach these abilities**

When the catalog's MCP server is enabled, the WooCommerce abilities are indexed
alongside core abilities. An agent searches by task, describes one ability, and
executes it through one search endpoint. Discovery cost tracks the result set, not
the total catalog size, so adding 142 abilities does not bloat an agent's tool list.
This is the recommended surface for new clients.

The add-on also contributes four curated WooCommerce domain tools on the catalog's
domain server through the `abilities_catalog_mcp_domains` filter: `woocommerce-catalog`
(products and product taxonomies), `woocommerce-orders` (orders, customers, coupons),
`woocommerce-config` (settings, shipping, taxes, payment gateways, webhooks, system
status, reference data), and `woocommerce-reports` (sales reports and analytics). Each
supports `list`, `describe`, and `execute`.

**Safety**

Two layers gate every ability, the same as core. The capability is the hard guard:
every ability's `permission_callback` calls `current_user_can()` with the matching
WooCommerce capability on every execution. The MCP exposure gate adds a second layer:
when the catalog's MCP server is on, every ability starts disabled for MCP execution
until an administrator enables it at Settings → MCP Server.

An MCP client acts as the authenticated WordPress user. Enabling write or destructive
WooCommerce abilities lets the client change real store data — products, orders,
customers. Back up the store before enabling high-risk abilities, and enable only what
the agent needs.

**Where this is going**

These abilities are not meant to replace WooCommerce's own. They are a working bridge
until WooCommerce ships official abilities on the Abilities API. As WooCommerce adds
its own abilities, the duplicated ones in this add-on will be removed to make room for
the WooCommerce-owned definitions.

== Installation ==

1. Install and activate WooCommerce.
2. Install and activate this plugin. The WooCommerce abilities register automatically — no build step.
3. Optional: install Abilities Catalog and enable its MCP server. The WooCommerce
   abilities then appear through the catalog's search server, and as curated domain tools.

== Changelog ==

= 0.1.0 =
* Initial release: 142 WooCommerce abilities across 12 domains on the WordPress
  Abilities API, with the add-on infrastructure (contracts, registry, the WooCommerce
  dependency facade, the category catalog) and the optional MCP integration (search
  server indexing plus four curated WooCommerce domain tools).
