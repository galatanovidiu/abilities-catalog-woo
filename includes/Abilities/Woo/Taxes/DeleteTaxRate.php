<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogWoo\Abilities\Woo\Taxes;

use GalatanOvidiu\AbilitiesCatalogWoo\Contracts\ConditionalAbility;
use GalatanOvidiu\AbilitiesCatalogWoo\Support\RestError;
use GalatanOvidiu\AbilitiesCatalogWoo\Support\WooPlugin;
use WP_REST_Request;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Destructive write ability: `og-wc-taxes/delete-tax-rate`.
 *
 * Wraps `DELETE wc/v3/taxes/<id>` via `rest_do_request()`. The id is a numeric path
 * segment, so it is concatenated into the route (not passed as a query param). The
 * delete is force-only: the route returns `woocommerce_rest_trash_not_supported`
 * (501) when `force` is false, so this always sends `force=true`, which calls
 * `WC_Tax::_delete_tax_rate()` — a permanent removal with no Trash and no restore.
 *
 * Before deleting, this reads the rate's display name with a `GET wc/v3/taxes/<id>`
 * so the result can confirm what was removed, AND so a missing rate surfaces the GET
 * route's clean `woocommerce_rest_invalid_id` 404 (the DELETE route returns the same
 * code but with status 400; the pre-read makes the missing-object error a 404).
 *
 * Only available when WooCommerce is active (it is a {@see ConditionalAbility}).
 *
 * @since 0.1.0
 */
final class DeleteTaxRate implements ConditionalAbility {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-wc-taxes/delete-tax-rate';
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
			'label'               => __( 'Delete Tax Rate', 'abilities-catalog-woo' ),
			'description'         => __( 'Permanently deletes a WooCommerce tax rate by ID. This cannot be undone: WooCommerce force-deletes the rate, bypassing the Trash, so there is no restore. This is a financial change: removing a rate alters what customers in the matched region are charged at checkout (one fewer rate applies there). Returns the deleted rate\'s display name for confirmation; no edit_link is returned because the rate no longer exists. Discover IDs with og-wc-taxes/list-tax-rates.', 'abilities-catalog-woo' ),
			'category'            => 'og-wc-taxes',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'id' ),
				'properties'           => array(
					'id' => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'The tax rate ID to permanently delete. Discover IDs with og-wc-taxes/list-tax-rates.', 'abilities-catalog-woo' ),
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'deleted', 'id' ),
				'properties'           => array(
					'deleted'   => array(
						'type'        => 'boolean',
						'description' => __( 'Whether the tax rate was permanently deleted.', 'abilities-catalog-woo' ),
					),
					'id'        => array(
						'type'        => 'integer',
						'description' => __( 'The deleted tax rate\'s ID.', 'abilities-catalog-woo' ),
					),
					'name'      => array(
						'type'        => 'string',
						'description' => __( 'The deleted rate\'s display name, captured before deletion so a human can confirm what was removed. May be empty if the rate had no name. No edit_link is returned because the rate no longer exists.', 'abilities-catalog-woo' ),
					),
					'permanent' => array(
						'type'        => 'boolean',
						'description' => __( 'Always true: tax rates have no Trash, so the deletion is permanent and cannot be undone.', 'abilities-catalog-woo' ),
					),
				),
				'additionalProperties' => false,
			),
			'execute_callback'    => array( $this, 'execute' ),
			'permission_callback' => array( $this, 'hasPermission' ),
			'meta'                => array(
				'annotations'  => array(
					'readonly'    => false,
					'destructive' => true,
					'idempotent'  => false,
				),
				'show_in_rest' => true,
				'screen'       => 'admin.php?page=wc-settings&tab=tax',
			),
		);
	}

	/**
	 * Permission check: WooCommerce's manager capability for settings.
	 *
	 * The wrapped `DELETE wc/v3/taxes/<id>` route gates on
	 * `wc_rest_check_manager_permissions( 'settings', 'delete' )`, which resolves to
	 * `manage_woocommerce`, so this mirrors that exact cap. This is a coarse,
	 * object-independent guard: the object-level decision (a missing rate) is
	 * deferred to the wrapped route, so execute() can surface the route's specific
	 * `woocommerce_rest_invalid_id` error instead of masking it as a permission
	 * denial.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may delete tax settings.
	 */
	public function hasPermission( $input ): bool {
		return WooPlugin::isActive() && current_user_can( 'manage_woocommerce' );
	}

	/**
	 * Executes the ability by dispatching the internal WooCommerce REST delete request.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The deleted flag, id, name, and permanent flag, or the REST error.
	 */
	public function execute( $input ) {
		if ( ! WooPlugin::isActive() ) {
			return WooPlugin::unavailable();
		}

		$input = is_array( $input ) ? $input : array();
		$id    = absint( $input['id'] ?? 0 );

		// Capture the name before the rate is gone; a missing rate 404s here.
		$before = rest_do_request( new WP_REST_Request( 'GET', '/wc/v3/taxes/' . $id ) );
		if ( $before->is_error() ) {
			return RestError::from( $before );
		}

		$before_data = rest_get_server()->response_to_data( $before, false );
		$name        = is_array( $before_data ) ? (string) ( $before_data['name'] ?? '' ) : '';

		$request = new WP_REST_Request( 'DELETE', '/wc/v3/taxes/' . $id );
		$request->set_param( 'force', true );
		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			return RestError::from( $response );
		}

		$data = rest_get_server()->response_to_data( $response, false );

		return array(
			'deleted'   => is_array( $data ) && isset( $data['id'] ) && (int) $data['id'] === $id,
			'id'        => $id,
			'name'      => $name,
			'permanent' => true,
		);
	}
}
