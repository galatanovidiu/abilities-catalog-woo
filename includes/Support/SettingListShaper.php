<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogWoo\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Projects raw `wc/v3` settings-group and setting-option rows into flat, closed
 * rows for the catalog's settings list and get-setting abilities.
 *
 * A `GET wc/v3/settings` row is a settings group ({id,label,description,parent_id,
 * sub_groups}); a `GET wc/v3/settings/{group}` row is a setting option
 * ({id,group_id,label,description,value,default,tip,placeholder,type[,options]}).
 * Some setting options hold live credentials — API keys, secrets, tokens. This
 * shaper makes it physically impossible for a read to leak one.
 *
 * REDACTION RULE: a setting option is treated as secret-bearing when its `type` is
 * `password` OR its `id` contains (case-insensitive) any of the secret substrings
 * in {@see self::SECRET_SUBSTRINGS}. The `value` of a secret-bearing option is NEVER
 * copied; it is replaced wholesale by {@see self::REDACTED}. Every option `value`
 * emitted by this shaper passes through {@see self::isSecret()} first.
 *
 * The redaction helper here is a small, deliberate duplicate of the one in
 * {@see PaymentGatewayShaper}: the same marker and the same predicate, kept local
 * rather than hoisted into a shared utility.
 *
 * Setting-option `value` and `default` are `mixed` in the WC schema (a setting can
 * hold a string, number, bool, or array). For a closed string schema this shaper
 * casts both to string.
 *
 * {@see self::groupItemSchema()}, {@see self::optionItemSchema()}, and
 * {@see self::optionDetailSchema()} pin the rows closed so the runtime row and the
 * declared schema cannot drift.
 *
 * This is NOT under `includes/Abilities/`, so the Registry never treats it as an
 * ability. It performs no WooCommerce calls and holds no ability logic; it only
 * shapes rows and declares their schema.
 *
 * @since 0.1.0
 */
final class SettingListShaper {

	/**
	 * The placeholder emitted in place of a secret-bearing option value.
	 *
	 * @var string
	 */
	public const REDACTED = '••• (hidden)';

	/**
	 * Case-insensitive substrings that mark a setting option id as secret-bearing.
	 *
	 * @var array<int,string>
	 */
	private const SECRET_SUBSTRINGS = array(
		'secret',
		'password',
		'passwd',
		'api_key',
		'apikey',
		'token',
		'private_key',
		'client_secret',
		'webhook_secret',
		'access_key',
		'consumer_secret',
	);

	/**
	 * Whether a setting option is secret-bearing and must be redacted.
	 *
	 * True when the option `type` is `password`, or when the option `id` contains
	 * (case-insensitive) any substring in {@see self::SECRET_SUBSTRINGS}.
	 *
	 * @param array<string,mixed> $field A single setting option descriptor.
	 * @return bool True when the option's value must be redacted.
	 */
	private static function isSecret( array $field ): bool {
		if ( 'password' === ( $field['type'] ?? '' ) ) {
			return true;
		}

		$id = (string) ( $field['id'] ?? '' );
		foreach ( self::SECRET_SUBSTRINGS as $needle ) {
			if ( false !== stripos( $id, $needle ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Flat summary row for a single `wc/v3` settings-group list item.
	 *
	 * Each value is read with a null-coalescing default and cast to string. The raw
	 * `sub_groups` array is dropped.
	 *
	 * @param array<string,mixed> $row A single row from a `GET wc/v3/settings` response.
	 * @return array{id:string,label:string,description:string,parent_id:string} The flat group summary row.
	 */
	public static function groupSummary( array $row ): array {
		return array(
			'id'          => (string) ( $row['id'] ?? '' ),
			'label'       => (string) ( $row['label'] ?? '' ),
			'description' => (string) ( $row['description'] ?? '' ),
			'parent_id'   => (string) ( $row['parent_id'] ?? '' ),
		);
	}

	/**
	 * The `output_schema` item definition matching {@see self::groupSummary()}.
	 *
	 * @return array<string,mixed> A JSON-Schema object fragment.
	 */
	public static function groupItemSchema(): array {
		return array(
			'type'                 => 'object',
			'required'             => array( 'id' ),
			'properties'           => array(
				'id'          => array(
					'type'        => 'string',
					'description' => __( 'The settings group ID. Read the group\'s options with the list-setting-options ability.', 'abilities-catalog-woo' ),
				),
				'label'       => array(
					'type'        => 'string',
					'description' => __( 'The human readable group label shown in the admin, e.g. General.', 'abilities-catalog-woo' ),
				),
				'description' => array(
					'type'        => 'string',
					'description' => __( 'A short description of the group, or an empty string when none is set.', 'abilities-catalog-woo' ),
				),
				'parent_id'   => array(
					'type'        => 'string',
					'description' => __( 'The parent group ID, or an empty string for a top-level group.', 'abilities-catalog-woo' ),
				),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * Flat summary row for a single `wc/v3` setting-option list item.
	 *
	 * Each value is read with a null-coalescing default. `value` and `default` are
	 * `mixed` in the WC schema and are cast to string. The `value` is the raw value
	 * ONLY when the option is not secret-bearing — otherwise it is
	 * {@see self::REDACTED}. A raw secret value is never copied.
	 *
	 * @param array<string,mixed> $row A single row from a `GET wc/v3/settings/{group}` response.
	 * @return array{id:string,label:string,type:string,value:string,default:string} The flat option summary row.
	 */
	public static function optionSummary( array $row ): array {
		return array(
			'id'      => (string) ( $row['id'] ?? '' ),
			'label'   => (string) ( $row['label'] ?? '' ),
			'type'    => (string) ( $row['type'] ?? '' ),
			'value'   => self::isSecret( $row ) ? self::REDACTED : (string) ( $row['value'] ?? '' ),
			'default' => (string) ( $row['default'] ?? '' ),
		);
	}

	/**
	 * The `output_schema` item definition matching {@see self::optionSummary()}.
	 *
	 * @return array<string,mixed> A JSON-Schema object fragment.
	 */
	public static function optionItemSchema(): array {
		return array(
			'type'                 => 'object',
			'required'             => array( 'id' ),
			'properties'           => array(
				'id'      => array(
					'type'        => 'string',
					'description' => __( 'The setting option ID within its group.', 'abilities-catalog-woo' ),
				),
				'label'   => array(
					'type'        => 'string',
					'description' => __( 'The human readable option label shown in the admin.', 'abilities-catalog-woo' ),
				),
				'type'    => array(
					'type'        => 'string',
					'description' => __( 'The option field type, e.g. text, select, checkbox, or password.', 'abilities-catalog-woo' ),
				),
				'value'   => array(
					'type'        => 'string',
					'description' => __( 'The configured value as a string, or a hidden marker when the option is credential-bearing.', 'abilities-catalog-woo' ),
				),
				'default' => array(
					'type'        => 'string',
					'description' => __( 'The default value as a string, or an empty string when none is set.', 'abilities-catalog-woo' ),
				),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * Flat detail row for a single `wc/v3` setting option, for the get-setting ability.
	 *
	 * `value` and `default` are `mixed` in the WC schema and are cast to string.
	 * `has_value` records whether a non-empty value is configured. The `value` is the
	 * raw value ONLY when the option is not secret-bearing — otherwise it is
	 * {@see self::REDACTED}. A raw secret value is never copied.
	 *
	 * @param array<string,mixed> $row A single row from a `GET wc/v3/settings/{group}/{id}` response.
	 * @return array{
	 *     id:string,
	 *     group_id:string,
	 *     label:string,
	 *     description:string,
	 *     type:string,
	 *     value:string,
	 *     default:string,
	 *     has_value:bool
	 * } The flat option detail row.
	 */
	public static function optionDetail( array $row ): array {
		$raw_value = (string) ( $row['value'] ?? '' );

		return array(
			'id'          => (string) ( $row['id'] ?? '' ),
			'group_id'    => (string) ( $row['group_id'] ?? '' ),
			'label'       => (string) ( $row['label'] ?? '' ),
			'description' => (string) ( $row['description'] ?? '' ),
			'type'        => (string) ( $row['type'] ?? '' ),
			'value'       => self::isSecret( $row ) ? self::REDACTED : $raw_value,
			'default'     => (string) ( $row['default'] ?? '' ),
			'has_value'   => ( '' !== $raw_value ),
		);
	}

	/**
	 * The `output_schema` definition matching {@see self::optionDetail()}.
	 *
	 * @return array<string,mixed> A JSON-Schema object fragment.
	 */
	public static function optionDetailSchema(): array {
		return array(
			'type'                 => 'object',
			'required'             => array( 'id' ),
			'properties'           => array(
				'id'          => array(
					'type'        => 'string',
					'description' => __( 'The setting option ID within its group.', 'abilities-catalog-woo' ),
				),
				'group_id'    => array(
					'type'        => 'string',
					'description' => __( 'The ID of the group this option belongs to.', 'abilities-catalog-woo' ),
				),
				'label'       => array(
					'type'        => 'string',
					'description' => __( 'The human readable option label shown in the admin.', 'abilities-catalog-woo' ),
				),
				'description' => array(
					'type'        => 'string',
					'description' => __( 'A short description of the option, or an empty string when none is set.', 'abilities-catalog-woo' ),
				),
				'type'        => array(
					'type'        => 'string',
					'description' => __( 'The option field type, e.g. text, select, checkbox, or password.', 'abilities-catalog-woo' ),
				),
				'value'       => array(
					'type'        => 'string',
					'description' => __( 'The configured value as a string, or a hidden marker when the option is credential-bearing.', 'abilities-catalog-woo' ),
				),
				'default'     => array(
					'type'        => 'string',
					'description' => __( 'The default value as a string, or an empty string when none is set.', 'abilities-catalog-woo' ),
				),
				'has_value'   => array(
					'type'        => 'boolean',
					'description' => __( 'Whether a non-empty value is configured for this option.', 'abilities-catalog-woo' ),
				),
			),
			'additionalProperties' => false,
		);
	}
}
