<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogWoo\Abilities\Woo\Shipping;

use GalatanOvidiu\AbilitiesCatalogWoo\Contracts\ConditionalAbility;
use GalatanOvidiu\AbilitiesCatalogWoo\Support\RestError;
use GalatanOvidiu\AbilitiesCatalogWoo\Support\ShippingZoneListShaper;
use GalatanOvidiu\AbilitiesCatalogWoo\Support\WooPlugin;
use WP_REST_Request;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Write ability: `og-wc-shipping/create-shipping-zone`.
 *
 * Wraps `POST wc/v3/shipping/zones` via `rest_do_request()`, creating a new
 * WooCommerce shipping zone. The result is shaped through
 * {@see ShippingZoneListShaper::summary()} into the same flat, closed row the
 * batch-06 zone reads return (id, name, order), so no raw zone fields leak.
 *
 * A new zone starts empty: it has no regions (locations) and no shipping methods
 * yet, so it matches no carts until both are added. After creating, attach regions
 * with `og-wc-shipping/update-shipping-zone-locations` and add a rate with
 * `og-wc-shipping/create-shipping-zone-method`.
 *
 * Persists on a plain POST dispatch — unlike the CF7 routes, the WooCommerce
 * shipping route needs no `context=save` flag.
 *
 * Only available when WooCommerce is active (it is a {@see ConditionalAbility}).
 *
 * @since 0.1.0
 */
final class CreateShippingZone implements ConditionalAbility {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-wc-shipping/create-shipping-zone';
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
			'label'               => __( 'Create Shipping Zone', 'abilities-catalog-woo' ),
			'description'         => __( 'Creates a new WooCommerce shipping zone and returns it as a flat row: id, name, and order. Only name is required. A new zone starts EMPTY — it has no regions (locations) and no shipping methods, so it matches no carts until you add both: attach regions with og-wc-shipping/update-shipping-zone-locations and add a rate with og-wc-shipping/create-shipping-zone-method. This adds a shipping configuration only; it does not change products, orders, or money, and is reversible by deleting the zone. Use this to make a brand-new zone; to rename or re-order an existing one use og-wc-shipping/update-shipping-zone instead.', 'abilities-catalog-woo' ),
			'category'            => 'og-wc-shipping',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'name' ),
				'properties'           => array(
					'name'  => array(
						'type'        => 'string',
						'description' => __( 'The shipping zone name shown to store staff, e.g. "Europe". Required.', 'abilities-catalog-woo' ),
					),
					'order' => array(
						'type'        => 'integer',
						'minimum'     => 0,
						'description' => __( 'The zone sort position. Zones are matched against a cart in ascending order, so a lower number is checked first. Optional; defaults to 0.', 'abilities-catalog-woo' ),
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => ShippingZoneListShaper::itemSchema(),
			'execute_callback'    => array( $this, 'execute' ),
			'permission_callback' => array( $this, 'hasPermission' ),
			'meta'                => array(
				'annotations'  => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => false,
				),
				'show_in_rest' => true,
				'screen'       => 'admin.php?page=wc-settings&tab=shipping',
			),
		);
	}

	/**
	 * Permission check: WooCommerce's store-management capability.
	 *
	 * Encodes the catalog capability for `og-wc-shipping/create-shipping-zone`:
	 * `manage_woocommerce`, which is what `wc_rest_check_manager_permissions(
	 * 'settings', 'create' )` resolves to on the wrapped `POST wc/v3/shipping/zones`
	 * route — the helper ignores its `$context` argument and maps the `settings`
	 * object to `manage_woocommerce`. Coarse and object-independent: there is no
	 * per-object check to do on a create. The explicit activity guard keeps the
	 * denial clean when WooCommerce is inactive and the cap is unmapped.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may create shipping zones.
	 */
	public function hasPermission( $input ): bool {
		return WooPlugin::isActive() && current_user_can( 'manage_woocommerce' );
	}

	/**
	 * Executes the ability by dispatching the internal `wc/v3` REST create request.
	 *
	 * Forwards `name` always and `order` only when present, then shapes the created
	 * zone through {@see ShippingZoneListShaper::summary()}.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The shaped created zone row, or the REST error.
	 */
	public function execute( $input ) {
		if ( ! WooPlugin::isActive() ) {
			return WooPlugin::unavailable();
		}

		$input = is_array( $input ) ? $input : array();

		$request = new WP_REST_Request( 'POST', '/wc/v3/shipping/zones' );

		$request->set_param( 'name', (string) ( $input['name'] ?? '' ) );

		if ( array_key_exists( 'order', $input ) ) {
			$request->set_param( 'order', absint( $input['order'] ) );
		}

		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			return RestError::from( $response );
		}

		$data = rest_get_server()->response_to_data( $response, false );
		$data = is_array( $data ) ? $data : array();

		return ShippingZoneListShaper::summary( $data );
	}
}
