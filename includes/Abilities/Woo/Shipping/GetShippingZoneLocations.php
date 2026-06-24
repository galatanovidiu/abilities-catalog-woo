<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogWoo\Abilities\Woo\Shipping;

use GalatanOvidiu\AbilitiesCatalogWoo\Contracts\ConditionalAbility;
use GalatanOvidiu\AbilitiesCatalogWoo\Support\RestError;
use GalatanOvidiu\AbilitiesCatalogWoo\Support\WooPlugin;
use WP_REST_Request;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read ability: `wc-shipping/get-shipping-zone-locations`.
 *
 * Wraps `GET wc/v3/shipping/zones/<id>/locations` via `rest_do_request()` and
 * returns the geographic match rules attached to one shipping zone. Each location
 * is a `{ code, type }` rule (country, state, postcode, or continent) that makes a
 * cart match the zone. The `id` route segment is the zone id, echoed back on the
 * result so the caller knows which zone the rules belong to.
 *
 * The location list is the zone's full set — there is no paging. An empty
 * `locations` array means the zone matches no region (common for a fresh zone, and
 * always the case for zone 0 "Rest of the World", the catch-all that matches
 * everything not claimed by another zone).
 *
 * Only available when WooCommerce is active (it is a {@see ConditionalAbility}).
 *
 * @since 0.1.0
 */
final class GetShippingZoneLocations implements ConditionalAbility {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'wc-shipping/get-shipping-zone-locations';
	}

	/**
	 * {@inheritDoc}
	 */
	public function isAvailable(): bool {
		return WooPlugin::isActive();
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return array(
			'label'               => __( 'Get Shipping Zone Locations', 'abilities-catalog-woo' ),
			'description'         => __( 'Returns the geographic match rules of one WooCommerce shipping zone by zone ID: each location is a { code, type } rule where type is one of country, state, postcode, or continent, and code is the matching region code. A location attaches the zone to a region; an empty locations array means the zone matches no region (common for a fresh zone, and always so for zone 0 "Rest of the World", the always-present catch-all). Discover the zone ID with wc-shipping/list-shipping-zones. Use wc-shipping/list-shipping-zone-methods for the zone\'s configured shipping methods.', 'abilities-catalog-woo' ),
			'category'            => 'wc-shipping',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'id' ),
				'properties'           => array(
					'id' => array(
						'type'        => 'integer',
						'minimum'     => 0,
						'description' => __( 'The shipping zone ID whose locations to read. Discover IDs with wc-shipping/list-shipping-zones; id 0 is the "Rest of the World" zone, which has no locations.', 'abilities-catalog-woo' ),
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'id', 'locations', 'total' ),
				'properties'           => array(
					'id'        => array(
						'type'        => 'integer',
						'description' => __( 'The shipping zone ID the locations belong to (echoes the input).', 'abilities-catalog-woo' ),
					),
					'locations' => array(
						'type'        => 'array',
						'description' => __( 'The zone\'s geographic match rules, each a { code, type } pair. An empty array means the zone matches no region.', 'abilities-catalog-woo' ),
						'items'       => self::locationItemSchema(),
					),
					'total'     => array(
						'type'        => 'integer',
						'description' => __( 'The number of location rules on the zone, read from the X-WP-Total response header (or the row count if the header is absent). Equals the length of locations because the list is not paged.', 'abilities-catalog-woo' ),
					),
				),
				'additionalProperties' => false,
			),
			'execute_callback'    => array( $this, 'execute' ),
			'permission_callback' => array( $this, 'hasPermission' ),
			'meta'                => array(
				'annotations'  => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
				'show_in_rest' => true,
			),
		);
	}

	/**
	 * Permission check: WooCommerce's shipping-settings manager capability.
	 *
	 * Encodes the catalog baseline for `wc-shipping/get-shipping-zone-locations`:
	 * `manage_woocommerce`, which is what `wc_rest_check_manager_permissions( 'settings', 'read' )`
	 * resolves to on the wrapped `GET wc/v3/shipping/zones/<id>/locations` route — the
	 * helper ignores the context and maps the `settings` object to `manage_woocommerce`.
	 * This is a coarse, object-independent guard; the wrapped route surfaces the
	 * specific 404 (`woocommerce_rest_shipping_zone_invalid`) for a missing zone via
	 * {@see RestError::from()}, so doing the zone-level check here would mask it as a
	 * permission denial. The explicit activity guard keeps the denial clean when
	 * WooCommerce is inactive and the capability is unmapped.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may read shipping zone locations.
	 */
	public function hasPermission( $input ): bool {
		return WooPlugin::isActive() && current_user_can( 'manage_woocommerce' );
	}

	/**
	 * Executes the ability by dispatching the internal WooCommerce REST request.
	 *
	 * The zone id is a route segment, so it is concatenated into the path (cast to
	 * int first) rather than set as a query param.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The zone's locations, or the REST error.
	 */
	public function execute( $input ) {
		if ( ! WooPlugin::isActive() ) {
			return WooPlugin::unavailable();
		}

		$input   = is_array( $input ) ? $input : array();
		$zone_id = absint( $input['id'] ?? 0 );

		$request  = new WP_REST_Request( 'GET', '/wc/v3/shipping/zones/' . $zone_id . '/locations' );
		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			return RestError::from( $response );
		}

		$data = rest_get_server()->response_to_data( $response, false );

		$rows = array();
		foreach ( is_array( $data ) ? $data : array() as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$rows[] = self::locationSummary( $item );
		}

		$headers = $response->get_headers();
		$total   = isset( $headers['X-WP-Total'] ) ? (int) $headers['X-WP-Total'] : count( $rows );

		return array(
			'id'        => $zone_id,
			'locations' => $rows,
			'total'     => $total,
		);
	}

	/**
	 * Flat summary row for a single `wc/v3` shipping-zone location.
	 *
	 * A location carries only the two fields the WC schema declares: the region
	 * `code` and its `type` (postcode/state/country/continent). Each is read with a
	 * null-coalescing default and cast to string.
	 *
	 * @param array<string,mixed> $row A single location from a `GET wc/v3/shipping/zones/<id>/locations` response.
	 * @return array{code:string,type:string} The flat location row.
	 */
	private static function locationSummary( array $row ): array {
		return array(
			'code' => (string) ( $row['code'] ?? '' ),
			'type' => (string) ( $row['type'] ?? '' ),
		);
	}

	/**
	 * The `output_schema` item definition matching {@see self::locationSummary()}.
	 *
	 * @return array<string,mixed> A JSON-Schema object fragment.
	 */
	private static function locationItemSchema(): array {
		return array(
			'type'                 => 'object',
			'required'             => array( 'code', 'type' ),
			'properties'           => array(
				'code' => array(
					'type'        => 'string',
					'description' => __( 'The region code this rule matches, e.g. "US" for a country or "US:CA" for a state.', 'abilities-catalog-woo' ),
				),
				'type' => array(
					'type'        => 'string',
					'enum'        => array( 'postcode', 'state', 'country', 'continent' ),
					'description' => __( 'The kind of region the code names: "postcode", "state", "country", or "continent".', 'abilities-catalog-woo' ),
				),
			),
			'additionalProperties' => false,
		);
	}
}
