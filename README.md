# Abilities Catalog — WooCommerce

**This add-on registers 142 WooCommerce store operations on the WordPress
Abilities API, so any Abilities API consumer can run them. When the
[Abilities Catalog](https://github.com/galatanovidiu/abilities-catalog) MCP
server is active, agents reach these abilities through the same search-based
surface as core abilities — no separate server per plugin.**

It works standalone on the core Abilities API. Abilities Catalog is optional.
When the catalog is present and its MCP server is on, the WooCommerce abilities
become discoverable through the catalog's search server, and the add-on also
contributes four curated WooCommerce domain tools. It edits no catalog files: it
plugs in through the catalog's public filters.

WooCommerce is a hard runtime dependency. While WooCommerce is inactive the
abilities do not register at all — they are absent from the Abilities API, not
registered-and-denying.

## Requirements

- WordPress 6.9 or later, with the Abilities API in core.
- PHP 8.1 or later.
- WooCommerce, active.
- Optional: Abilities Catalog, for the MCP surface.

## Installation

1. Install and activate WooCommerce.
2. Install and activate this plugin. The WooCommerce abilities register
   automatically — no build step.
3. Optional: install Abilities Catalog and enable its MCP server. The
   WooCommerce abilities then appear through the catalog's search server, and as
   curated domain tools.

## What it registers

142 abilities across 12 WooCommerce domains:

| Domain | Abilities | Covers |
|---|---:|---|
| `og-wc-products` | 48 | products, variations, attributes, terms, categories, tags, brands, shipping classes, reviews |
| `og-wc-reports` | 27 | sales reports and analytics |
| `og-wc-orders` | 15 | orders, order notes, refunds, statuses, emails |
| `og-wc-shipping` | 14 | shipping zones, methods, locations, classes |
| `og-wc-data` | 8 | reference data: countries, currencies, continents |
| `og-wc-taxes` | 8 | tax rates and tax classes |
| `og-wc-customers` | 6 | customers and their downloads |
| `og-wc-coupons` | 5 | discount coupons |
| `og-wc-settings` | 3 | store settings and setting options |
| `og-wc-system` | 3 | system status and system tools |
| `og-wc-webhooks` | 3 | webhooks |
| `og-wc-payment-gateways` | 2 | payment gateways |

Ability names use a `domain/verb-noun` shape, for example:

- `og-wc-products/list-products`
- `og-wc-orders/create-order`
- `og-wc-coupons/update-coupon`

Each ability wraps a WooCommerce REST route, declares an input and output
schema, points at a category, enforces a server-side `permission_callback`, and
carries risk annotations. Of the 142 abilities, 86 are read-only and 19 are
destructive (permanent deletes); the rest are non-destructive writes.

## How agents reach these abilities

The add-on registers on the same Abilities API as the core catalog, so it rides
the catalog's MCP surfaces. There is no separate server per plugin.

### Search server (primary)

When the catalog's MCP server is enabled, the WooCommerce abilities are indexed
alongside core abilities. An agent searches by task, describes one ability, and
executes it through the one search endpoint:

```text
/wp-json/abilities-catalog/v1/mcp-search
```

Discovery cost tracks the result set, not the total catalog size, so adding 142
WooCommerce abilities does not bloat an agent's tool list. This is the
recommended surface for new clients.

### Curated domain tools

On the catalog's curated domain server, the add-on also contributes four
WooCommerce domain tools through the `abilities_catalog_mcp_domains` filter:

- `woocommerce-catalog` — products and product taxonomies.
- `woocommerce-orders` — orders, customers, coupons.
- `woocommerce-config` — settings, shipping, taxes, payment gateways, webhooks,
  system status, reference data.
- `woocommerce-reports` — sales reports and analytics.

Each tool supports `list`, `describe`, and `execute`. This is the older,
hand-curated surface; prefer the search server for large catalogs.

## Safety

Two layers gate every ability, the same as core:

- **Capability is the hard guard.** Every ability's `permission_callback` calls
  `current_user_can()` with the matching WooCommerce capability — for example
  `publish_products`, `delete_products`, `publish_shop_orders`. This runs on
  every execution, independent of any MCP client.
- **MCP exposure gate.** When the catalog's MCP server is on, every ability —
  including these — starts disabled for MCP execution. Discovery can show it;
  execution is refused until an administrator enables it at
  **Settings → MCP Server**.

> [!WARNING]
> An MCP client acts as the authenticated WordPress user. Enabling write or
> destructive WooCommerce abilities lets the client change real store data —
> products, orders, customers. Back up the store before enabling high-risk
> abilities, and enable only what the agent needs.

## Standalone and decoupled

This is a separate plugin, not part of the core catalog. It works on the bare
Abilities API with no catalog present: the `og-wc-*` abilities still register and
run for any consumer. The MCP integration is filter-based and inert when the
catalog is absent — no catalog class is referenced and no catalog file is edited.

See
[Building an Abilities Catalog add-on](https://github.com/galatanovidiu/abilities-catalog/blob/main/docs/building-add-ons.md)
for the extension pattern.

## Where this is going

These abilities are not meant to replace WooCommerce's own. They are a working
bridge until WooCommerce ships official abilities on the Abilities API. As
WooCommerce adds its own abilities, the duplicated ones in this add-on will be
removed to make room for the WooCommerce-owned definitions.

## Development

Static checks run on the host (need `composer install`):

```bash
composer lint      # phpcs (VIP + Slevomat, .phpcs.xml.dist)
composer format    # phpcbf — auto-fix
composer phpstan   # phpstan analyse
```

Tests run inside wp-env (Docker WordPress with WooCommerce installed), not on
the host:

```bash
npm run wp-env:test start  # bring up the container
npm run test:php:setup     # composer install inside the container (run once)
npm run test:php           # full PHPUnit suite
```

## License

MIT — see [LICENSE](LICENSE).
