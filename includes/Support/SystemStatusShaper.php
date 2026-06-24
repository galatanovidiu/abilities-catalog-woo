<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogWoo\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Projects the raw `wc/v3` system-status payload into a small, closed store-health
 * subset for the catalog's get-system-status ability.
 *
 * The raw `GET wc/v3/system_status` payload is a large information-disclosure
 * document. Its `environment` block alone discloses `home_url`, `site_url`,
 * `store_id`, `log_directory`, and full server paths; `database.database_tables`
 * is a complete map of every table; `active_plugins` / `inactive_plugins` list the
 * whole plugin set (a precise fingerprint of the install); and `settings`, `pages`,
 * and `post_type_counts` add still more. Returned wholesale, that payload is a
 * reconnaissance gift.
 *
 * {@see self::subset()} returns ONLY a curated store-health summary. It reads a
 * fixed allow-list of keys and never copies `home_url`, `site_url`, `store_id`,
 * `log_directory`, the `settings` block, the `active_plugins` / `inactive_plugins`
 * lists (only their count), the `database_tables` map (only its count), `pages`, or
 * `post_type_counts`. {@see self::schema()} pins every level closed
 * (`additionalProperties: false`) so the runtime subset and the declared schema
 * cannot drift, and so no disclosure field can leak in.
 *
 * Field notes: the REST `environment.version` is the WooCommerce version, exposed
 * here as `wc_version`. `wp_memory_limit` is an integer in the WC schema
 * (system-status-v2:192-197); `wp_debug_mode` is a boolean (system-status-v2:198-203).
 * `table_count` is `count( database.database_tables )` — the count, never the map.
 * `active_plugins_count` is `count( active_plugins )` — the count, never the list.
 *
 * This is NOT under `includes/Abilities/`, so the Registry never treats it as an
 * ability. It performs no WooCommerce calls and holds no ability logic; it only
 * shapes the subset and declares its schema.
 *
 * @since 0.1.0
 */
final class SystemStatusShaper {

	/**
	 * The curated store-health subset of a `wc/v3` system-status payload.
	 *
	 * Reads ONLY a fixed allow-list of keys from `$data`, each with a null-coalescing
	 * default and a cast to the type the WC system-status schema guarantees. The
	 * `environment.version` value is exposed as `wc_version` (it is the WooCommerce
	 * version). `table_count` and `active_plugins_count` are counts only — never the
	 * `database_tables` map or the `active_plugins` list. No request URL, store id,
	 * log directory, settings block, or plugin list is ever copied.
	 *
	 * @param array<string,mixed> $data The full `rest_get_server()->response_to_data()`
	 *                                   of a `GET wc/v3/system_status` response.
	 * @return array{
	 *     environment:array{
	 *         wc_version:string,
	 *         wp_version:string,
	 *         php_version:string,
	 *         server_info:string,
	 *         wp_memory_limit:int,
	 *         wp_debug_mode:bool
	 *     },
	 *     database:array{wc_database_version:string,table_count:int},
	 *     theme:array{name:string,version:string,is_child_theme:bool},
	 *     active_plugins_count:int,
	 *     security:array{secure_connection:bool,hide_errors:bool}
	 * } The curated store-health subset.
	 */
	public static function subset( array $data ): array {
		$environment = (array) ( $data['environment'] ?? array() );
		$database    = (array) ( $data['database'] ?? array() );
		$theme       = (array) ( $data['theme'] ?? array() );
		$security    = (array) ( $data['security'] ?? array() );

		return array(
			'environment'          => array(
				'wc_version'      => (string) ( $environment['version'] ?? '' ),
				'wp_version'      => (string) ( $environment['wp_version'] ?? '' ),
				'php_version'     => (string) ( $environment['php_version'] ?? '' ),
				'server_info'     => (string) ( $environment['server_info'] ?? '' ),
				'wp_memory_limit' => (int) ( $environment['wp_memory_limit'] ?? 0 ),
				'wp_debug_mode'   => (bool) ( $environment['wp_debug_mode'] ?? false ),
			),
			'database'             => array(
				'wc_database_version' => (string) ( $database['wc_database_version'] ?? '' ),
				'table_count'         => count( (array) ( $database['database_tables'] ?? array() ) ),
			),
			'theme'                => array(
				'name'           => (string) ( $theme['name'] ?? '' ),
				'version'        => (string) ( $theme['version'] ?? '' ),
				'is_child_theme' => (bool) ( $theme['is_child_theme'] ?? false ),
			),
			'active_plugins_count' => count( (array) ( $data['active_plugins'] ?? array() ) ),
			'security'             => array(
				'secure_connection' => (bool) ( $security['secure_connection'] ?? false ),
				'hide_errors'       => (bool) ( $security['hide_errors'] ?? false ),
			),
		);
	}

	/**
	 * The `output_schema` definition matching {@see self::subset()}.
	 *
	 * A closed object whose nested `environment`, `database`, `theme`, and `security`
	 * objects are each closed too (`additionalProperties: false` at every level), so
	 * none of the raw payload's disclosure fields can leak through.
	 *
	 * @return array<string,mixed> A JSON-Schema object fragment.
	 */
	public static function schema(): array {
		return array(
			'type'                 => 'object',
			'required'             => array( 'environment', 'database', 'theme', 'active_plugins_count', 'security' ),
			'properties'           => array(
				'environment'          => array(
					'type'                 => 'object',
					'description'          => __( 'Core environment facts about the store.', 'abilities-catalog-woo' ),
					'properties'           => array(
						'wc_version'      => array(
							'type'        => 'string',
							'description' => __( 'The installed WooCommerce version.', 'abilities-catalog-woo' ),
						),
						'wp_version'      => array(
							'type'        => 'string',
							'description' => __( 'The installed WordPress version.', 'abilities-catalog-woo' ),
						),
						'php_version'     => array(
							'type'        => 'string',
							'description' => __( 'The server PHP version.', 'abilities-catalog-woo' ),
						),
						'server_info'     => array(
							'type'        => 'string',
							'description' => __( 'The web server software string, e.g. Apache or nginx.', 'abilities-catalog-woo' ),
						),
						'wp_memory_limit' => array(
							'type'        => 'integer',
							'description' => __( 'The WordPress memory limit in bytes.', 'abilities-catalog-woo' ),
						),
						'wp_debug_mode'   => array(
							'type'        => 'boolean',
							'description' => __( 'Whether WordPress debug mode is active.', 'abilities-catalog-woo' ),
						),
					),
					'additionalProperties' => false,
				),
				'database'             => array(
					'type'                 => 'object',
					'description'          => __( 'Database health facts. The table map is never returned, only its count.', 'abilities-catalog-woo' ),
					'properties'           => array(
						'wc_database_version' => array(
							'type'        => 'string',
							'description' => __( 'The WooCommerce database schema version.', 'abilities-catalog-woo' ),
						),
						'table_count'         => array(
							'type'        => 'integer',
							'description' => __( 'How many WooCommerce database tables the store reports.', 'abilities-catalog-woo' ),
						),
					),
					'additionalProperties' => false,
				),
				'theme'                => array(
					'type'                 => 'object',
					'description'          => __( 'The active theme.', 'abilities-catalog-woo' ),
					'properties'           => array(
						'name'           => array(
							'type'        => 'string',
							'description' => __( 'The active theme name.', 'abilities-catalog-woo' ),
						),
						'version'        => array(
							'type'        => 'string',
							'description' => __( 'The active theme version.', 'abilities-catalog-woo' ),
						),
						'is_child_theme' => array(
							'type'        => 'boolean',
							'description' => __( 'Whether the active theme is a child theme.', 'abilities-catalog-woo' ),
						),
					),
					'additionalProperties' => false,
				),
				'active_plugins_count' => array(
					'type'        => 'integer',
					'description' => __( 'How many active plugins the store reports. The plugin list itself is never returned.', 'abilities-catalog-woo' ),
				),
				'security'             => array(
					'type'                 => 'object',
					'description'          => __( 'Store security posture.', 'abilities-catalog-woo' ),
					'properties'           => array(
						'secure_connection' => array(
							'type'        => 'boolean',
							'description' => __( 'Whether the store is served over HTTPS.', 'abilities-catalog-woo' ),
						),
						'hide_errors'       => array(
							'type'        => 'boolean',
							'description' => __( 'Whether PHP errors are hidden from visitors.', 'abilities-catalog-woo' ),
						),
					),
					'additionalProperties' => false,
				),
			),
			'additionalProperties' => false,
		);
	}
}
