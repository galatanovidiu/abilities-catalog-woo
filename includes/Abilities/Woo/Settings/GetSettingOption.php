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
 * Read ability: `wc-settings/get-setting-option`.
 *
 * Wraps `GET wc/v3/settings/<group>/<id>` via `rest_do_request()` and returns one
 * WooCommerce setting option as a flat, closed record: its id, group_id, label,
 * description, field type, value, default, and a `has_value` flag. The `<group>`
 * and `<id>` are STRING path segments (not query params), so the route is built by
 * concatenation; an unknown group or option surfaces the route's own 404
 * (`rest_setting_setting_group_invalid` / `rest_setting_setting_invalid`) via
 * {@see RestError::from()} rather than collapsing to a permission error.
 *
 * SECRET REDACTION: a setting option can hold a live credential (a field whose
 * `type` is `password`, or whose id matches a known secret key such as
 * `*_secret`/`*_token`/`*_api_key`). The wrapped `wc/v3` body returns the raw stored
 * value, so this ability routes the row through {@see SettingListShaper::optionDetail()},
 * which replaces a secret-bearing `value` with a hidden marker and sets `has_value`
 * so an agent reads "configured but hidden", not "missing". No raw credential value
 * is ever echoed by this ability.
 *
 * Only available when WooCommerce is active (it is a {@see ConditionalAbility}).
 *
 * @since 0.1.0
 */
final class GetSettingOption implements ConditionalAbility {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'wc-settings/get-setting-option';
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
			'label'               => __( 'Get Setting Option', 'abilities-catalog-woo' ),
			'description'         => __( 'Returns one WooCommerce setting option by group and id: its label, description, field type, configured value, default, and a has_value flag. Use this for a single option\'s detail; discover the group with wc-settings/list-setting-groups, then the option id with wc-settings/list-group-settings. Credential-bearing values (a password-type field or a known secret key) are masked with a hidden marker, so a masked value means "configured but hidden", not "missing" — use has_value to tell configured from empty.', 'abilities-catalog-woo' ),
			'category'            => 'wc-settings',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'group', 'id' ),
				'properties'           => array(
					'group' => array(
						'type'        => 'string',
						'minLength'   => 1,
						'description' => __( 'The settings group ID, e.g. "general". Discover it with wc-settings/list-setting-groups.', 'abilities-catalog-woo' ),
					),
					'id'    => array(
						'type'        => 'string',
						'minLength'   => 1,
						'description' => __( 'The setting option ID within the group. Discover it with wc-settings/list-group-settings.', 'abilities-catalog-woo' ),
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => SettingListShaper::optionDetailSchema(),
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
	 * Permission check: WooCommerce's settings manager capability.
	 *
	 * The wrapped `wc/v3` settings route gates reads on
	 * `wc_rest_check_manager_permissions( 'settings', 'read' )`, which resolves to
	 * `manage_woocommerce`. This mirrors that exact coarse, object-independent cap;
	 * the wrapped route surfaces the specific 404 for an unknown group or option, so
	 * doing an object-level check here would mask a missing target as a permission
	 * denial. The activity guard keeps the denial clean when WooCommerce is inactive.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may read WooCommerce settings.
	 */
	public function hasPermission( $input ): bool {
		return WooPlugin::isActive() && current_user_can( 'manage_woocommerce' );
	}

	/**
	 * Executes the ability by dispatching the internal `wc/v3` REST request.
	 *
	 * The group and option ids are STRING path segments, so the route is built by
	 * concatenation rather than `set_param()`. The shaped detail row redacts any
	 * secret-bearing value, so no raw credential reaches the caller.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The shaped setting option, or the REST error.
	 */
	public function execute( $input ) {
		if ( ! WooPlugin::isActive() ) {
			return WooPlugin::unavailable();
		}

		$input = is_array( $input ) ? $input : array();
		$group = (string) ( $input['group'] ?? '' );
		$id    = (string) ( $input['id'] ?? '' );

		$request  = new WP_REST_Request( 'GET', '/wc/v3/settings/' . $group . '/' . $id );
		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			return RestError::from( $response );
		}

		$data = rest_get_server()->response_to_data( $response, false );

		return SettingListShaper::optionDetail( is_array( $data ) ? $data : array() );
	}
}
