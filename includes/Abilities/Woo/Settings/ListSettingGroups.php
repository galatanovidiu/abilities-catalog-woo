<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogWoo\Abilities\Woo\Settings;

use GalatanOvidiu\AbilitiesCatalogWoo\Contracts\ConditionalAbility;
use GalatanOvidiu\AbilitiesCatalogWoo\Support\RestError;
use GalatanOvidiu\AbilitiesCatalogWoo\Support\SettingListShaper;
use GalatanOvidiu\AbilitiesCatalogWoo\Support\WooPlugin;
use WP_REST_Request;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read ability: `og-wc-settings/list-setting-groups`.
 *
 * Wraps `GET wc/v3/settings` via `rest_do_request()` and returns WooCommerce's
 * top-level settings groups (the tabs of the WooCommerce settings screen, e.g.
 * General, Products, Tax, Shipping) as flat `{ id, label, description, parent_id }`
 * rows via {@see SettingListShaper::groupSummary()}. This is the discovery step:
 * pass a row's `id` to the list-group-settings ability to read that group's actual
 * options.
 *
 * Groups are pure metadata — no setting values, and therefore no credentials, are
 * exposed here. Secret redaction applies only to the option-level reads
 * (list-group-settings, get-setting-option), where field values can be present.
 *
 * Only available when WooCommerce is active (it is a {@see ConditionalAbility}).
 * The route returns a bare array with no pagination header, so `total` is the
 * number of groups returned.
 *
 * @since 0.1.0
 */
final class ListSettingGroups implements ConditionalAbility {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-wc-settings/list-setting-groups';
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
			'label'               => __( 'List Setting Groups', 'abilities-catalog-woo' ),
			'description'         => __( 'Returns WooCommerce\'s top-level settings groups (the tabs of the WooCommerce settings screen, e.g. General, Products, Tax, Shipping) as flat { id, label, description, parent_id } rows, plus the total count. Read-only discovery step: pass a row\'s id to og-wc-settings/list-group-settings to read that group\'s actual options. Groups are metadata only — they carry no setting values and no credentials.', 'abilities-catalog-woo' ),
			'category'            => 'og-wc-settings',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => (object) array(),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'items', 'total' ),
				'properties'           => array(
					'items' => array(
						'type'        => 'array',
						'description' => __( 'The settings groups as flat summary rows. Pass a row\'s id to og-wc-settings/list-group-settings to read that group\'s options.', 'abilities-catalog-woo' ),
						'items'       => SettingListShaper::groupItemSchema(),
					),
					'total' => array(
						'type'        => 'integer',
						'description' => __( 'The number of groups returned. This route exposes no total header, so it counts the returned rows.', 'abilities-catalog-woo' ),
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
	 * Permission check: WooCommerce's settings-manager capability.
	 *
	 * The wrapped `/wc/v3/settings` route gates reads on
	 * `wc_rest_check_manager_permissions( 'settings', 'read' )`, which resolves to
	 * `manage_woocommerce`. This ability mirrors that exact cap — never wider — and
	 * the activity guard keeps the denial clean when WooCommerce is inactive and the
	 * capability is unmapped. This is a coarse type-level guard; the wrapped route
	 * surfaces any specific error.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may read WooCommerce settings.
	 */
	public function hasPermission( $input ): bool {
		return WooPlugin::isActive() && current_user_can( 'manage_woocommerce' );
	}

	/**
	 * Executes the ability by dispatching the internal WooCommerce REST request.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The settings groups and total, or the REST error.
	 */
	public function execute( $input ) {
		if ( ! WooPlugin::isActive() ) {
			return WooPlugin::unavailable();
		}

		$input = is_array( $input ) ? $input : array();

		$request  = new WP_REST_Request( 'GET', '/wc/v3/settings' );
		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			return RestError::from( $response );
		}

		$data = rest_get_server()->response_to_data( $response, false );

		$rows = array();
		foreach ( is_array( $data ) ? $data : array() as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$rows[] = SettingListShaper::groupSummary( $row );
		}

		return array(
			'items' => $rows,
			'total' => count( $rows ),
		);
	}
}
