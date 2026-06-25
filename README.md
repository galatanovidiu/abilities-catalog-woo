# Abilities Catalog — WooCommerce

Registers [WooCommerce](https://wordpress.org/plugins/woocommerce/) store operations on the WordPress **Abilities API** (ships in WP 7.0 core), so any Abilities API consumer can read and write products, orders, customers, coupons, settings, reports, and more — each gated by WooCommerce's own capabilities.

It is **consumer-agnostic** and works standalone on the core Abilities API. The [Abilities Catalog](https://github.com/galatanovidiu/abilities-catalog) is optional: when its MCP server is active, this add-on contributes curated WooCommerce **domain tools** (`woocommerce-catalog`, `woocommerce-orders`, `woocommerce-config`, `woocommerce-reports`) through the catalog's public filters. No core files of Abilities Catalog are modified.

WooCommerce is a hard runtime dependency: while WooCommerce is inactive the WooCommerce abilities do not register at all (they are absent from the Abilities API, not registered-and-denying), and the domain tools do not appear.

## Requirements

- WordPress 7.0+ (for the core Abilities API)
- PHP 8.1+
- WooCommerce (active)
- Optional: Abilities Catalog, for the MCP domain tools

## Installation

1. Install and activate WooCommerce.
2. Install and activate this plugin. The WooCommerce abilities register automatically — no build step.
3. Optional: install Abilities Catalog and enable its MCP server to expose the WooCommerce domain tools.

## Development

Lint, static analysis, and format run on the host (need `composer install`):

```bash
composer lint      # phpcs (VIP + slevomat, .phpcs.xml.dist)
composer format    # phpcbf — auto-fix
composer phpstan   # phpstan analyse
```

Tests run inside wp-env (Docker WordPress with WooCommerce installed), not on the host:

```bash
npm run wp-env:test start  # bring up the container
npm run test:php:setup     # composer install inside the container (run once)
npm run test:php           # full PHPUnit suite
```

## License

MIT — see [LICENSE](LICENSE).
