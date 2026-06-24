<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogWoo\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Projects raw `wc/v3` webhook rows into flat, closed rows for the catalog's
 * webhook list and get-webhook abilities.
 *
 * A webhook's `secret` is the HMAC key that signs every payload WooCommerce
 * delivers to the webhook's `delivery_url`; it is a stored credential. In the
 * `view`-context REST response WooCommerce already omits `secret` (the field is
 * declared `'context' => array( 'edit' )` only — webhooks-v2:148-152), but this
 * shaper does not rely on that: it builds its output field-by-field from a FIXED
 * allow-list and simply NEVER reads `$row['secret']`. The shaper makes it
 * physically impossible for a read to leak the key, regardless of the dispatch
 * context.
 *
 * {@see self::summary()} OMITS every detail field — a list row is summary-thin and
 * carries no credential at all. {@see self::detail()} adds the detail fields and,
 * in place of the secret, a `has_secret` boolean so an agent can tell "this
 * webhook signs its deliveries" from "unsigned" WITHOUT seeing the key.
 *
 * `has_secret`/`failure_count` derivation: neither value is present in the
 * dispatched `view`-context response (`secret` is `edit`-context only;
 * `failure_count` is on `WC_Webhook::get_failure_count()` but is added to NO REST
 * response). {@see self::detail()} therefore re-fetches the webhook with
 * `wc_get_webhook( (int) $row['id'] )` and derives `has_secret` from
 * `'' !== (string) $webhook->get_secret()` and `failure_count` from
 * `(int) $webhook->get_failure_count()`. This is a non-route core read used ONLY
 * to derive a boolean and a count — it tests the secret for emptiness and never
 * copies the value into the output. When `wc_get_webhook()` returns a falsy value
 * (e.g. `null`/`false` for a missing webhook), `has_secret` is `false` and
 * `failure_count` is `0`.
 *
 * {@see self::itemSchema()} and {@see self::detailSchema()} pin the rows closed so
 * the runtime row and the declared schema cannot drift.
 *
 * This is NOT under `includes/Abilities/`, so the Registry never treats it as an
 * ability. It performs no WooCommerce route calls and holds no ability logic; it
 * only shapes rows, derives `has_secret`/`failure_count`, and declares the schema.
 *
 * @since 0.1.0
 */
final class WebhookListShaper {

	/**
	 * Flat summary row for a single `wc/v3` webhook list item.
	 *
	 * Each value is read with a null-coalescing default and cast to the type the WC
	 * webhooks schema guarantees. Built from a FIXED allow-list; it NEVER reads
	 * `$row['secret']`, so a list row carries no credential at all.
	 *
	 * @param array<string,mixed> $row A single row from a `GET wc/v3/webhooks` response.
	 * @return array{
	 *     id:int,
	 *     name:string,
	 *     status:string,
	 *     topic:string,
	 *     delivery_url:string,
	 *     date_created:string
	 * } The flat webhook summary row, with no secret.
	 */
	public static function summary( array $row ): array {
		return array(
			'id'           => (int) ( $row['id'] ?? 0 ),
			'name'         => (string) ( $row['name'] ?? '' ),
			'status'       => (string) ( $row['status'] ?? '' ),
			'topic'        => (string) ( $row['topic'] ?? '' ),
			'delivery_url' => (string) ( $row['delivery_url'] ?? '' ),
			'date_created' => (string) ( $row['date_created'] ?? '' ),
		);
	}

	/**
	 * The `output_schema` item definition matching {@see self::summary()}.
	 *
	 * Closed object with the six summary fields and NO secret property.
	 *
	 * @return array<string,mixed> A JSON-Schema object fragment.
	 */
	public static function itemSchema(): array {
		return array(
			'type'                 => 'object',
			'required'             => array( 'id' ),
			'properties'           => array(
				'id'           => array(
					'type'        => 'integer',
					'description' => __( 'The webhook ID. Read the full webhook with the get-webhook ability.', 'abilities-catalog-woo' ),
				),
				'name'         => array(
					'type'        => 'string',
					'description' => __( 'A friendly name for the webhook.', 'abilities-catalog-woo' ),
				),
				'status'       => array(
					'type'        => 'string',
					'description' => __( 'The webhook status: active, paused, or disabled.', 'abilities-catalog-woo' ),
				),
				'topic'        => array(
					'type'        => 'string',
					'description' => __( 'The webhook topic, e.g. order.created, that decides which store event fires a delivery.', 'abilities-catalog-woo' ),
				),
				'delivery_url' => array(
					'type'        => 'string',
					'description' => __( 'The URL where the webhook payload is delivered.', 'abilities-catalog-woo' ),
				),
				'date_created' => array(
					'type'        => 'string',
					'description' => __( 'The creation date as an ISO-8601 date-time string in the site timezone.', 'abilities-catalog-woo' ),
				),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * Flat detail row for a single `wc/v3` webhook, for the get-webhook ability.
	 *
	 * Returns every field from {@see self::summary()} plus `resource`, `event`,
	 * `hooks` (the WooCommerce action names that fire the delivery), `failure_count`,
	 * `date_modified`, and a `has_secret` boolean. It STILL never reads
	 * `$row['secret']`: the raw signing key is never copied into the output.
	 *
	 * `has_secret` and `failure_count` are not present in the dispatched
	 * `view`-context response, so this method reads them off the WooCommerce facade
	 * via {@see WooPlugin::webhookDeliveryStatus()} (the one place this plugin touches
	 * a WooCommerce symbol directly). The secret is tested only for emptiness there;
	 * its value is never returned. When the id matches no webhook, `has_secret` is
	 * `false` and `failure_count` is `0`.
	 *
	 * @param array<string,mixed> $row A single row from a `GET wc/v3/webhooks/{id}` response.
	 * @return array<string,mixed> The flat detail row: the summary fields plus
	 *                             `resource`, `event`, `hooks`, `failure_count`,
	 *                             `date_modified`, and `has_secret` — never the secret.
	 */
	public static function detail( array $row ): array {
		$hooks = array();
		foreach ( (array) ( $row['hooks'] ?? array() ) as $hook ) {
			$hooks[] = (string) $hook;
		}

		$delivery = WooPlugin::webhookDeliveryStatus( (int) ( $row['id'] ?? 0 ) );

		return array_merge(
			self::summary( $row ),
			array(
				'resource'      => (string) ( $row['resource'] ?? '' ),
				'event'         => (string) ( $row['event'] ?? '' ),
				'hooks'         => $hooks,
				'failure_count' => $delivery['failure_count'],
				'date_modified' => (string) ( $row['date_modified'] ?? '' ),
				'has_secret'    => $delivery['has_secret'],
			)
		);
	}

	/**
	 * The `output_schema` definition matching {@see self::detail()}.
	 *
	 * Reuses {@see self::itemSchema()} for the summary fields and adds `resource`,
	 * `event`, the closed `hooks` list, `failure_count`, `date_modified`, and the
	 * `has_secret` boolean. There is NO secret property: the signing key is never
	 * exposed, only the `has_secret` derivation. The top-level object is closed
	 * (`additionalProperties: false`).
	 *
	 * @return array<string,mixed> A JSON-Schema object fragment.
	 */
	public static function detailSchema(): array {
		$schema = self::itemSchema();

		$schema['properties']['resource']      = array(
			'type'        => 'string',
			'description' => __( 'The webhook resource, e.g. order, derived from the topic.', 'abilities-catalog-woo' ),
		);
		$schema['properties']['event']         = array(
			'type'        => 'string',
			'description' => __( 'The webhook event, e.g. created, derived from the topic.', 'abilities-catalog-woo' ),
		);
		$schema['properties']['hooks']         = array(
			'type'        => 'array',
			'description' => __( 'The WooCommerce action names that trigger a delivery for this webhook.', 'abilities-catalog-woo' ),
			'items'       => array(
				'type' => 'string',
			),
		);
		$schema['properties']['failure_count'] = array(
			'type'        => 'integer',
			'description' => __( 'How many consecutive deliveries have failed. WooCommerce disables a webhook once this reaches its failure threshold.', 'abilities-catalog-woo' ),
		);
		$schema['properties']['date_modified'] = array(
			'type'        => 'string',
			'description' => __( 'The last-modified date as an ISO-8601 date-time string in the site timezone.', 'abilities-catalog-woo' ),
		);
		$schema['properties']['has_secret']    = array(
			'type'        => 'boolean',
			'description' => __( 'Whether the webhook has a signing secret configured. True means deliveries are signed (their payloads carry an HMAC signature); the secret key itself is never exposed.', 'abilities-catalog-woo' ),
		);

		return $schema;
	}
}
