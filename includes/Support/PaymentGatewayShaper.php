<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogWoo\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Projects raw `wc/v3` payment-gateway rows into flat, closed rows for the
 * catalog's payment-gateway list and get-gateway abilities.
 *
 * A `GET wc/v3/payment_gateways` row carries a per-field `settings` map — an object
 * keyed by field id where each entry is the whole
 * {id,label,description,type,value,default,tip,placeholder[,options]} descriptor.
 * That map routinely holds live credentials: API keys, secrets, tokens, webhook
 * secrets. This shaper makes it physically impossible for a read to leak one.
 *
 * REDACTION RULE: a gateway settings field is treated as secret-bearing when its
 * `type` is `password` OR its `id` contains (case-insensitive) any of the secret
 * substrings in {@see self::SECRET_SUBSTRINGS}. The value of a secret-bearing field
 * is NEVER copied; it is replaced wholesale by {@see self::REDACTED}. Every value in
 * the detail output passes through {@see self::isSecret()} before it is emitted.
 *
 * {@see self::summary()} OMITS the settings map ENTIRELY — a list row carries NO
 * credentials at all, redacted or otherwise. Only {@see self::detail()} emits the
 * settings, and even then each value is gated.
 *
 * {@see self::itemSchema()} and {@see self::detailSchema()} pin the rows closed so
 * the runtime row and the declared schema cannot drift.
 *
 * This is NOT under `includes/Abilities/`, so the Registry never treats it as an
 * ability. It performs no WooCommerce calls and holds no ability logic; it only
 * shapes rows and declares their schema.
 *
 * @since 0.1.0
 */
final class PaymentGatewayShaper {

	/**
	 * The placeholder emitted in place of a secret-bearing field value.
	 *
	 * @var string
	 */
	public const REDACTED = '••• (hidden)';

	/**
	 * Case-insensitive substrings that mark a settings field id as secret-bearing.
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
	 * Whether a gateway settings field is secret-bearing and must be redacted.
	 *
	 * True when the field `type` is `password`, or when the field `id` contains
	 * (case-insensitive) any substring in {@see self::SECRET_SUBSTRINGS}.
	 *
	 * @param array<string,mixed> $field A single settings field descriptor.
	 * @return bool True when the field's value must be redacted.
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
	 * Flat summary row for a single `wc/v3` payment-gateway list item.
	 *
	 * Each value is read with a null-coalescing default and cast to the type the WC
	 * payment-gateways schema guarantees. This row OMITS the settings map entirely:
	 * a list row carries no credentials.
	 *
	 * @param array<string,mixed> $row A single row from a `GET wc/v3/payment_gateways` response.
	 * @return array{
	 *     id:string,
	 *     title:string,
	 *     description:string,
	 *     enabled:bool,
	 *     method_title:string,
	 *     order:int
	 * } The flat summary row, with no settings.
	 */
	public static function summary( array $row ): array {
		return array(
			'id'           => (string) ( $row['id'] ?? '' ),
			'title'        => (string) ( $row['title'] ?? '' ),
			'description'  => (string) ( $row['description'] ?? '' ),
			'enabled'      => (bool) ( $row['enabled'] ?? false ),
			'method_title' => (string) ( $row['method_title'] ?? '' ),
			'order'        => (int) ( $row['order'] ?? 0 ),
		);
	}

	/**
	 * The `output_schema` item definition matching {@see self::summary()}.
	 *
	 * Closed object with the six summary fields and NO settings property.
	 *
	 * @return array<string,mixed> A JSON-Schema object fragment.
	 */
	public static function itemSchema(): array {
		return array(
			'type'                 => 'object',
			'required'             => array( 'id' ),
			'properties'           => array(
				'id'           => array(
					'type'        => 'string',
					'description' => __( 'The payment gateway ID. Read the full gateway with the get-payment-gateway ability.', 'abilities-catalog-woo' ),
				),
				'title'        => array(
					'type'        => 'string',
					'description' => __( 'The gateway title shown to shoppers at checkout.', 'abilities-catalog-woo' ),
				),
				'description'  => array(
					'type'        => 'string',
					'description' => __( 'The gateway description shown to shoppers at checkout.', 'abilities-catalog-woo' ),
				),
				'enabled'      => array(
					'type'        => 'boolean',
					'description' => __( 'Whether the gateway is enabled and offered to shoppers.', 'abilities-catalog-woo' ),
				),
				'method_title' => array(
					'type'        => 'string',
					'description' => __( 'The gateway method title shown in the admin, e.g. PayPal.', 'abilities-catalog-woo' ),
				),
				'order'        => array(
					'type'        => 'integer',
					'description' => __( 'The gateway sort position at checkout, in ascending sequence.', 'abilities-catalog-woo' ),
				),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * Flat detail row for a single `wc/v3` payment-gateway, for the get-gateway ability.
	 *
	 * Returns every field from {@see self::summary()} plus the gateway's
	 * `method_description` and a redacted `settings` list. The settings map is
	 * reduced to a list of {id,label,type,value,has_value} field objects. For each
	 * field, the raw value is read, `has_value` records whether a non-empty value is
	 * configured, and `value` is the raw value ONLY when the field is not
	 * secret-bearing — otherwise it is {@see self::REDACTED}. A raw secret value is
	 * never copied into the output.
	 *
	 * @param array<string,mixed> $row A single row from a `GET wc/v3/payment_gateways/{id}` response.
	 * @return array<string,mixed> The flat detail row: the summary fields plus
	 *                             `method_description` and the redacted `settings` list.
	 */
	public static function detail( array $row ): array {
		$settings = array();
		foreach ( (array) ( $row['settings'] ?? array() ) as $field ) {
			$field = (array) $field;

			$raw_value = (string) ( $field['value'] ?? '' );
			$has_value = ( '' !== $raw_value );

			$settings[] = array(
				'id'        => (string) ( $field['id'] ?? '' ),
				'label'     => (string) ( $field['label'] ?? '' ),
				'type'      => (string) ( $field['type'] ?? '' ),
				'value'     => self::isSecret( $field ) ? self::REDACTED : $raw_value,
				'has_value' => $has_value,
			);
		}

		return array_merge(
			self::summary( $row ),
			array(
				'method_description' => (string) ( $row['method_description'] ?? '' ),
				'settings'           => $settings,
			)
		);
	}

	/**
	 * The `output_schema` definition matching {@see self::detail()}.
	 *
	 * Reuses {@see self::itemSchema()} for the summary fields and adds the
	 * `method_description` string and the closed `settings` list. The top-level
	 * object and every settings field object are closed
	 * (`additionalProperties: false`).
	 *
	 * @return array<string,mixed> A JSON-Schema object fragment.
	 */
	public static function detailSchema(): array {
		$schema = self::itemSchema();

		$schema['properties']['method_description'] = array(
			'type'        => 'string',
			'description' => __( 'A short description of what the gateway does, or an empty string when none is set.', 'abilities-catalog-woo' ),
		);

		$schema['properties']['settings'] = array(
			'type'        => 'array',
			'description' => __( 'The gateway settings fields. The value of any credential-bearing field (e.g. an API key, secret, token, or password) is redacted and replaced with a hidden marker; has_value still reports whether one is configured.', 'abilities-catalog-woo' ),
			'items'       => array(
				'type'                 => 'object',
				'properties'           => array(
					'id'        => array(
						'type'        => 'string',
						'description' => __( 'The setting field id.', 'abilities-catalog-woo' ),
					),
					'label'     => array(
						'type'        => 'string',
						'description' => __( 'A human readable label for the setting.', 'abilities-catalog-woo' ),
					),
					'type'      => array(
						'type'        => 'string',
						'description' => __( 'The setting field type, e.g. text, select, checkbox, or password.', 'abilities-catalog-woo' ),
					),
					'value'     => array(
						'type'        => 'string',
						'description' => __( 'The configured value, or the hidden marker when the field is credential-bearing.', 'abilities-catalog-woo' ),
					),
					'has_value' => array(
						'type'        => 'boolean',
						'description' => __( 'Whether a non-empty value is configured for this setting.', 'abilities-catalog-woo' ),
					),
				),
				'additionalProperties' => false,
			),
		);

		return $schema;
	}
}
