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
 * Write ability: `wc-shipping/update-shipping-zone-locations`.
 *
 * Wraps `PUT wc/v3/shipping/zones/<id>/locations` via `rest_do_request()` and
 * returns the zone's resulting full location set as `{ id, locations, total }`.
 *
 * FULL-REPLACE semantics: the wrapped route rebuilds the whole location array from
 * the request body and calls `WC_Shipping_Zone::set_locations()` with exactly what
 * it received, so any location the caller omits is dropped. The route reads the
 * locations from the raw JSON body (a top-level array), so this dispatch sends the
 * `locations` array as the request body with a JSON content-type rather than as a
 * named param. The `id` route segment is the zone id, concatenated into the path.
 *
 * Idempotent: re-sending the same `locations` set yields the same stored state.
 *
 * Only available when WooCommerce is active (it is a {@see ConditionalAbility}).
 *
 * @since 0.1.0
 */
final class UpdateShippingZoneLocations implements ConditionalAbility {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'wc-shipping/update-shipping-zone-locations';
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
			'label'               => __( 'Update Shipping Zone Locations', 'abilities-catalog-woo' ),
			'description'         => __( 'Replaces the geographic match rules of one WooCommerce shipping zone by zone ID and returns the resulting full set as { id, locations, total }, each location a { code, type } rule. FULL REPLACE — this is a footgun: WooCommerce sets the zone\'s locations to EXACTLY the array you send, so any location currently on the zone that is NOT in your locations array is DROPPED. To add or change one location without losing the others, first read the current set with wc-shipping/get-shipping-zone-locations, modify the list, and send the COMPLETE desired set back. Sending an empty locations array CLEARS the zone (it then matches no region). Discover the zone ID with wc-shipping/list-shipping-zones. Zone 0 ("Rest of the World"), the always-present catch-all, cannot be edited: the route returns a 403. Use wc-shipping/update-shipping-zone to rename or reorder a zone.', 'abilities-catalog-woo' ),
			'category'            => 'wc-shipping',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'id', 'locations' ),
				'properties'           => array(
					'id'        => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'The shipping zone ID whose locations to replace. Discover IDs with wc-shipping/list-shipping-zones. Zone 0 ("Rest of the World") cannot be edited.', 'abilities-catalog-woo' ),
					),
					'locations' => array(
						'type'        => 'array',
						'description' => __( 'The COMPLETE desired set of geographic match rules for the zone. This REPLACES the zone\'s current locations entirely: any location not in this array is dropped, and an empty array clears the zone. To add one location, read the current set with wc-shipping/get-shipping-zone-locations first, then send the whole list back.', 'abilities-catalog-woo' ),
						'items'       => array(
							'type'                 => 'object',
							'required'             => array( 'code', 'type' ),
							'properties'           => array(
								'code' => array(
									'type'        => 'string',
									'description' => __( 'The region code this rule matches, e.g. "US" for a country, "US:CA" for a state, a postcode, or a continent code.', 'abilities-catalog-woo' ),
								),
								'type' => array(
									'type'        => 'string',
									'enum'        => array( 'postcode', 'state', 'country', 'continent' ),
									'description' => __( 'The kind of region the code names: "postcode", "state", "country", or "continent".', 'abilities-catalog-woo' ),
								),
							),
							'additionalProperties' => false,
						),
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
						'description' => __( 'The zone\'s resulting geographic match rules after the replace, each a { code, type } pair. An empty array means the zone now matches no region.', 'abilities-catalog-woo' ),
						'items'       => self::locationItemSchema(),
					),
					'total'     => array(
						'type'        => 'integer',
						'description' => __( 'The number of location rules now on the zone, read from the X-WP-Total response header (or the row count if the header is absent). Equals the length of locations because the list is not paged.', 'abilities-catalog-woo' ),
					),
				),
				'additionalProperties' => false,
			),
			'execute_callback'    => array( $this, 'execute' ),
			'permission_callback' => array( $this, 'hasPermission' ),
			'meta'                => array(
				'annotations'  => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => true,
				),
				'show_in_rest' => true,
				'screen'       => 'admin.php?page=wc-settings&tab=shipping',
			),
		);
	}

	/**
	 * Permission check: WooCommerce's shipping-settings manager capability.
	 *
	 * Encodes the catalog baseline for `wc-shipping/update-shipping-zone-locations`:
	 * `manage_woocommerce`, which is what `wc_rest_check_manager_permissions( 'settings', 'edit' )`
	 * resolves to on the wrapped `PUT wc/v3/shipping/zones/<id>/locations` route — the
	 * helper ignores the context and maps the `settings` object to `manage_woocommerce`.
	 * This is a coarse, object-independent guard; the wrapped route surfaces the specific
	 * 404 (`woocommerce_rest_shipping_zone_invalid`) for a missing zone and the 403
	 * (`woocommerce_rest_shipping_zone_locations_invalid_zone`) for zone 0 via
	 * {@see RestError::from()}, so doing the zone-level check here would mask either as a
	 * permission denial. The explicit activity guard keeps the denial clean when
	 * WooCommerce is inactive and the capability is unmapped.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may edit shipping zone locations.
	 */
	public function hasPermission( $input ): bool {
		return WooPlugin::isActive() && current_user_can( 'manage_woocommerce' );
	}

	/**
	 * Executes the ability by dispatching the internal WooCommerce REST request.
	 *
	 * The zone id is a route segment, so it is concatenated into the path (cast to
	 * int first). The route reads the new locations from the raw JSON body via
	 * `WP_REST_Request::get_json_params()`, so the `locations` array is sent as the
	 * request body with a JSON content-type — not as a named param.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The zone's resulting locations, or the REST error.
	 */
	public function execute( $input ) {
		if ( ! WooPlugin::isActive() ) {
			return WooPlugin::unavailable();
		}

		$input   = is_array( $input ) ? $input : array();
		$zone_id = absint( $input['id'] ?? 0 );

		$locations = array();
		foreach ( is_array( $input['locations'] ?? null ) ? $input['locations'] : array() as $location ) {
			if ( ! is_array( $location ) ) {
				continue;
			}

			$locations[] = array(
				'code' => (string) ( $location['code'] ?? '' ),
				'type' => (string) ( $location['type'] ?? '' ),
			);
		}

		$request = new WP_REST_Request( 'PUT', '/wc/v3/shipping/zones/' . $zone_id . '/locations' );

		// The route reads the locations from the raw JSON body (a top-level array),
		// so the body is set directly with a JSON content-type rather than via set_param().
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( (string) wp_json_encode( $locations ) );

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
	 * null-coalescing default and cast to string. Matches the row shape
	 * `wc-shipping/get-shipping-zone-locations` returns.
	 *
	 * @param array<string,mixed> $row A single location from the `PUT` response.
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
