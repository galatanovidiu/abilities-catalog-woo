<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogWoo\Abilities\Woo\Customers;

use GalatanOvidiu\AbilitiesCatalogWoo\Contracts\ConditionalAbility;
use GalatanOvidiu\AbilitiesCatalogWoo\Support\RestError;
use GalatanOvidiu\AbilitiesCatalogWoo\Support\WooPlugin;
use WP_REST_Request;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Destructive delete ability: `og-wc-customers/delete-customer`.
 *
 * Wraps `DELETE wc/v3/customers/<id>` via `rest_do_request()`. Before deleting it
 * reads the customer's `email` and `username` so the result can confirm which
 * account was removed (and so a missing customer 404s here with `wc_user_invalid_id`).
 *
 * PERMANENT — REMOVES A WordPress USER. WooCommerce customers do NOT support the
 * Trash: the route requires `force=true` and calls `wp_delete_user()`, deleting the
 * WordPress user account (login, profile, user meta) and, unless `reassign` is given,
 * the user's authored content. There is no restore. Because there is no soft-delete,
 * this ability hard-sets `force=true` server-side and does NOT expose `force` as input.
 *
 * The optional `reassign` input is a user id that the route's `delete_and_reassign`
 * uses to keep the deleted user's posts/content under another account instead of
 * deleting them. A `reassign` equal to the deleted id, or a non-existent user, is
 * rejected by the route with `woocommerce_rest_customer_invalid_reassign` 400.
 *
 * Only available when WooCommerce is active (it is a {@see ConditionalAbility}).
 *
 * @since 0.1.0
 */
final class DeleteCustomer implements ConditionalAbility {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-wc-customers/delete-customer';
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
			'label'               => __( 'Delete Customer', 'abilities-catalog-woo' ),
			'description'         => __( 'Permanently deletes a WooCommerce customer by ID and returns the deleted account\'s email and username for confirmation. This REMOVES the underlying WordPress user account (login, profile, and user meta) and, unless reassign is given, the user\'s authored content. It is irreversible: customers do not support the Trash, so there is no restore. Pass reassign (a different user ID) to reassign the deleted customer\'s posts and content to that user instead of deleting them; reassigned_to in the result echoes that ID, or is null when the content was deleted with the account. A reassign equal to the deleted ID, or pointing at a non-existent user, returns a "woocommerce_rest_customer_invalid_reassign" 400 error. The result contains the customer\'s email (PII) so a human can confirm the right account was removed. Discover IDs with og-wc-customers/list-customers.', 'abilities-catalog-woo' ),
			'category'            => 'og-wc-customers',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'id' ),
				'properties'           => array(
					'id'       => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'The customer (WordPress user) ID to permanently delete. Discover IDs with og-wc-customers/list-customers.', 'abilities-catalog-woo' ),
					),
					'reassign' => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'Optional. A different user ID to reassign the deleted customer\'s posts and content to, instead of deleting that content. Omit it to delete the content along with the account. Must not equal id and must be an existing user, or the route returns a "woocommerce_rest_customer_invalid_reassign" 400 error. Discover IDs with og-wc-customers/list-customers.', 'abilities-catalog-woo' ),
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'deleted', 'id', 'email', 'username', 'reassigned_to', 'force_used', 'permanent' ),
				'properties'           => array(
					'deleted'       => array(
						'type'        => 'boolean',
						'description' => __( 'Whether the customer was permanently deleted.', 'abilities-catalog-woo' ),
					),
					'id'            => array(
						'type'        => 'integer',
						'description' => __( 'The deleted customer\'s (WordPress user) ID.', 'abilities-catalog-woo' ),
					),
					'email'         => array(
						'type'        => 'string',
						'description' => __( 'The deleted customer\'s email address, so a human can confirm which account was removed. This is personal data (PII).', 'abilities-catalog-woo' ),
					),
					'username'      => array(
						'type'        => 'string',
						'description' => __( 'The deleted customer\'s username (login), for confirmation.', 'abilities-catalog-woo' ),
					),
					'reassigned_to' => array(
						'type'        => array( 'integer', 'null' ),
						'description' => __( 'The user ID the deleted customer\'s content was reassigned to, or null when the content was deleted along with the account.', 'abilities-catalog-woo' ),
					),
					'force_used'    => array(
						'type'        => 'boolean',
						'description' => __( 'Always true: customers have no Trash, so the delete is always a permanent force delete.', 'abilities-catalog-woo' ),
					),
					'permanent'     => array(
						'type'        => 'boolean',
						'description' => __( 'Always true: the WordPress user account is gone and cannot be recovered. No edit_link is returned because the user no longer exists.', 'abilities-catalog-woo' ),
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
				'screen'       => 'users.php',
			),
		);
	}

	/**
	 * Permission check: WordPress's primitive capability for deleting users.
	 *
	 * Encodes the catalog capability for `og-wc-customers/delete-customer`:
	 * `delete_users`, which is what `wc_rest_check_user_permissions( 'delete' )`
	 * resolves to on the wrapped `DELETE wc/v3/customers/<id>` route. This is a
	 * coarse, object-INDEPENDENT guard; the wrapped route applies the finer,
	 * object-level checks and surfaces the specific `wc_user_invalid_id` 404 for a
	 * missing customer (and the `woocommerce_rest_customer_invalid_reassign` 400 for
	 * a bad reassign target) via {@see RestError::from()} rather than collapsing them
	 * to a permission denial. The explicit activity guard keeps the denial clean when
	 * WooCommerce is inactive.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may delete users.
	 */
	public function hasPermission( $input ): bool {
		return WooPlugin::isActive() && current_user_can( 'delete_users' );
	}

	/**
	 * Executes the ability by dispatching the internal `wc/v3` REST delete request.
	 *
	 * Reads the customer's `email` and `username` first (a missing customer 404s
	 * here), then dispatches the force delete. The WooCommerce delete response is the
	 * prepared customer object, which has no `deleted` field, so the success of a
	 * non-error response is synthesized into `deleted => true`.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The deletion envelope, or the REST error
	 *                                        (`wc_user_invalid_id` 404 for a missing
	 *                                        customer, `woocommerce_rest_customer_invalid_reassign`
	 *                                        400 for a bad reassign target).
	 */
	public function execute( $input ) {
		if ( ! WooPlugin::isActive() ) {
			return WooPlugin::unavailable();
		}

		$input    = is_array( $input ) ? $input : array();
		$id       = absint( $input['id'] ?? 0 );
		$reassign = isset( $input['reassign'] ) ? absint( $input['reassign'] ) : 0;

		// Capture the email and username before the account is gone; a missing
		// customer 404s here with wc_user_invalid_id.
		$before = rest_do_request( new WP_REST_Request( 'GET', '/wc/v3/customers/' . $id ) );
		if ( $before->is_error() ) {
			return RestError::from( $before );
		}

		$before_data = rest_get_server()->response_to_data( $before, false );
		$before_data = is_array( $before_data ) ? $before_data : array();
		$email       = (string) ( $before_data['email'] ?? '' );
		$username    = (string) ( $before_data['username'] ?? '' );

		// Customers have no Trash, so force=true is mandatory and hard-set here.
		$request = new WP_REST_Request( 'DELETE', '/wc/v3/customers/' . $id );
		$request->set_param( 'force', true );
		if ( $reassign > 0 ) {
			$request->set_param( 'reassign', $reassign );
		}

		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			return RestError::from( $response );
		}

		return array(
			'deleted'       => true,
			'id'            => $id,
			'email'         => $email,
			'username'      => $username,
			'reassigned_to' => $reassign > 0 ? $reassign : null,
			'force_used'    => true,
			'permanent'     => true,
		);
	}
}
