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
 * Destructive write ability: `og-wc-taxes/delete-tax-class`.
 *
 * Wraps `DELETE wc/v3/taxes/classes/<slug>` via `rest_do_request()` with
 * `force=true`. The slug is a STRING route segment, so the path is built with
 * `rawurlencode( $slug )` (it is not an integer ID). With `force=false` the route
 * returns a `woocommerce_rest_trash_not_supported` 501 (tax classes do not support
 * trashing), so `force=true` is mandatory and the delete is permanent.
 *
 * No pre-read: the tax-class single-item route registers ONLY a DELETE method —
 * there is no GET single route — so the slug and name come from the DELETE response
 * (`prepare_item_for_response` returns `{ slug, name }`). An unknown slug surfaces
 * the route's own `woocommerce_rest_tax_class_invalid_slug` 404 via
 * {@see RestError::from()}, so no separate existence read is needed.
 *
 * Financial / cascade: `WC_Tax::delete_tax_class_by()` deletes the class AND every
 * tax rate assigned to it (and orphaned rate locations). Products using the class
 * fall back to the standard rate. The built-in `standard` class is virtual (not a
 * database row) and cannot be deleted — the route returns
 * `woocommerce_rest_tax_class_invalid_slug` for it. There is no update route for
 * tax classes; to rename one, delete it and recreate it with
 * `og-wc-taxes/create-tax-class`.
 *
 * Returns no `edit_link` — the class no longer exists.
 *
 * Only available when WooCommerce is active (it is a {@see ConditionalAbility}).
 *
 * @since 0.1.0
 */
final class DeleteTaxClass implements ConditionalAbility {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-wc-taxes/delete-tax-class';
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
			'label'               => __( 'Delete Tax Class', 'abilities-catalog-woo' ),
			'description'         => __( 'Permanently deletes one WooCommerce tax class by its slug and returns the deleted slug and name for confirmation. This cannot be undone: tax classes have no Trash, so there is no restore. Financial cascade: deleting a class also deletes every tax rate assigned to it, so products and orders that used the class fall back to the standard rate — this changes what customers are charged at checkout. The built-in "standard" class cannot be deleted (it is a virtual class and the route rejects it with a "woocommerce_rest_tax_class_invalid_slug" 404); the built-in "reduced-rate" and "zero-rate" classes and any custom class can be deleted. There is no update route for tax classes — to rename a class, delete it and recreate it with og-wc-taxes/create-tax-class. No edit_link is returned because the class no longer exists. Discover slugs with og-wc-taxes/list-tax-classes.', 'abilities-catalog-woo' ),
			'category'            => 'og-wc-taxes',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'slug' ),
				'properties'           => array(
					'slug' => array(
						'type'        => 'string',
						'minLength'   => 1,
						'description' => __( 'The tax-class slug to permanently delete, e.g. "reduced-rate". Discover slugs with og-wc-taxes/list-tax-classes. The built-in "standard" class cannot be deleted.', 'abilities-catalog-woo' ),
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'deleted', 'slug' ),
				'properties'           => array(
					'deleted'   => array(
						'type'        => 'boolean',
						'description' => __( 'Whether the tax class was permanently deleted.', 'abilities-catalog-woo' ),
					),
					'slug'      => array(
						'type'        => 'string',
						'description' => __( 'The slug of the deleted tax class, taken from the delete response so a human can confirm what was removed. No edit_link is returned because the class no longer exists.', 'abilities-catalog-woo' ),
					),
					'name'      => array(
						'type'        => 'string',
						'description' => __( 'The display name of the deleted tax class, taken from the delete response.', 'abilities-catalog-woo' ),
					),
					'permanent' => array(
						'type'        => 'boolean',
						'description' => __( 'Always true: tax classes have no Trash, so the deletion is permanent and cannot be undone.', 'abilities-catalog-woo' ),
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
	 * Permission check: WooCommerce's manager capability for store settings.
	 *
	 * Encodes the catalog capability for `og-wc-taxes/delete-tax-class`:
	 * `manage_woocommerce`, which is what
	 * `wc_rest_check_manager_permissions( 'settings', 'delete' )` resolves to on the
	 * wrapped `DELETE wc/v3/taxes/classes/<slug>` route (the helper ignores the
	 * context argument and maps the `'settings'` object to `manage_woocommerce`).
	 * This is a coarse, object-INDEPENDENT guard; the wrapped route surfaces the
	 * specific `woocommerce_rest_tax_class_invalid_slug` 404 for an unknown or
	 * built-in `standard` slug via {@see RestError::from()} rather than collapsing it
	 * to a permission denial. The explicit activity guard keeps the denial clean when
	 * WooCommerce is inactive and the capability is unmapped.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may delete tax classes.
	 */
	public function hasPermission( $input ): bool {
		return WooPlugin::isActive() && current_user_can( 'manage_woocommerce' );
	}

	/**
	 * Executes the ability by dispatching the internal `wc/v3` REST delete request.
	 *
	 * The slug is a string route segment, so the path is built with
	 * `rawurlencode()`. The delete response carries `{ slug, name }`; if either is
	 * absent the input slug is echoed back as the confirmation slug.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The deleted flag, slug, name, and permanent
	 *                                        flag, or the REST error (e.g.
	 *                                        `woocommerce_rest_tax_class_invalid_slug`
	 *                                        404 for an unknown or built-in slug).
	 */
	public function execute( $input ) {
		if ( ! WooPlugin::isActive() ) {
			return WooPlugin::unavailable();
		}

		$input = is_array( $input ) ? $input : array();
		$slug  = (string) ( $input['slug'] ?? '' );

		// The tax-class route has no GET single method, so the slug + name come from
		// the DELETE response. The slug is a string segment: rawurlencode, not int.
		$request = new WP_REST_Request( 'DELETE', '/wc/v3/taxes/classes/' . rawurlencode( $slug ) );
		$request->set_param( 'force', true );

		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			return RestError::from( $response );
		}

		$data = rest_get_server()->response_to_data( $response, false );
		$data = is_array( $data ) ? $data : array();

		$response_slug = (string) ( $data['slug'] ?? '' );

		return array(
			'deleted'   => true,
			'slug'      => '' !== $response_slug ? $response_slug : $slug,
			'name'      => (string) ( $data['name'] ?? '' ),
			'permanent' => true,
		);
	}
}
