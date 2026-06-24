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
 * Read ability: `wc-settings/list-group-settings`.
 *
 * Wraps `GET wc/v3/settings/<group>` via `rest_do_request()` and returns the
 * group's setting options as flat summary rows. The `<group>` is a string path
 * segment built into the route by concatenation (e.g. `general`, `tax`), not a
 * query parameter, so an unknown group surfaces the route's own
 * `rest_setting_setting_group_invalid` 404 via {@see RestError::from()} rather
 * than collapsing into a permission error.
 *
 * Each row is projected through {@see SettingListShaper::optionSummary()}, which
 * REDACTS credential-bearing values: a `password`-type option, or one whose id
 * matches a known secret key, has its `value` replaced by the hidden marker. The
 * raw secret never reaches the consumer — the redaction lives in the shaper, so
 * this read physically cannot leak one.
 *
 * Only available when WooCommerce is active (it is a {@see ConditionalAbility}).
 * The WC settings-group route returns a bare array with no pagination headers, so
 * `total` is the number of rows returned.
 *
 * @since 0.1.0
 */
final class ListGroupSettings implements ConditionalAbility {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'wc-settings/list-group-settings';
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
			'label'               => __( 'List Group Settings', 'abilities-catalog-woo' ),
			'description'         => __( 'Returns one WooCommerce settings group\'s options as flat summary rows, each with its id, label, type, value, and default. Discover the group with wc-settings/list-setting-groups; use wc-settings/get-setting-option for one option\'s full detail. Credential-bearing values (a password-type option, or one whose id is a known secret key) are masked with a hidden marker, so a masked value means "configured but hidden", not missing.', 'abilities-catalog-woo' ),
			'category'            => 'wc-settings',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'group' ),
				'properties'           => array(
					'group' => array(
						'type'        => 'string',
						'minLength'   => 1,
						'description' => __( 'The settings group ID, e.g. "general" or "tax". Discover valid group IDs with wc-settings/list-setting-groups.', 'abilities-catalog-woo' ),
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'group', 'items', 'total' ),
				'properties'           => array(
					'group' => array(
						'type'        => 'string',
						'description' => __( 'The settings group ID these options belong to, echoing the requested group.', 'abilities-catalog-woo' ),
					),
					'items' => array(
						'type'        => 'array',
						'description' => __( 'The group\'s setting options as flat summary rows. Use wc-settings/get-setting-option for a single option\'s full detail. Credential-bearing values are masked.', 'abilities-catalog-woo' ),
						'items'       => SettingListShaper::optionItemSchema(),
					),
					'total' => array(
						'type'        => 'integer',
						'description' => __( 'The number of option rows returned. The WC settings-group route exposes no total header, so this counts the returned rows.', 'abilities-catalog-woo' ),
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
	 * Permission check: WooCommerce's manage capability for settings.
	 *
	 * The wrapped route gates on `wc_rest_check_manager_permissions( 'settings',
	 * 'read' )`, which resolves to `manage_woocommerce`, so this ability mirrors
	 * that exact capability. This is the coarse, object-independent guard; the
	 * wrapped route surfaces the specific 404 (`rest_setting_setting_group_invalid`)
	 * for an unknown group rather than masking it as a permission denial. The
	 * explicit activity guard keeps the denial clean when WooCommerce is inactive.
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
	 * @return array<string,mixed>|\WP_Error The group's setting options, or the REST error.
	 */
	public function execute( $input ) {
		if ( ! WooPlugin::isActive() ) {
			return WooPlugin::unavailable();
		}

		$input = is_array( $input ) ? $input : array();
		$group = (string) ( $input['group'] ?? '' );

		// The group is a path segment, built by concatenation, not a query param.
		$request  = new WP_REST_Request( 'GET', '/wc/v3/settings/' . $group );
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

			$rows[] = SettingListShaper::optionSummary( $item );
		}

		return array(
			'group' => $group,
			'items' => $rows,
			'total' => count( $rows ),
		);
	}
}
