<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogWoo\Abilities\Woo\Customers;

use GalatanOvidiu\AbilitiesCatalogWoo\Contracts\ConditionalAbility;
use GalatanOvidiu\AbilitiesCatalogWoo\Support\CustomerListShaper;
use GalatanOvidiu\AbilitiesCatalogWoo\Support\RestError;
use GalatanOvidiu\AbilitiesCatalogWoo\Support\WooPlugin;
use WP_REST_Request;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Write ability: `wc-customers/update-customer`.
 *
 * Wraps `PUT wc/v3/customers/<id>` via `rest_do_request()`, updating an existing
 * WooCommerce customer (a WordPress user with the `customer` role). The id is
 * built into the route path; the writable fields are forwarded inline on key
 * presence, so the caller sends only the fields to change. The WC update route
 * calls `WC_Customer::save()` unconditionally, so a plain PUT persists — no
 * `context=save` flag is needed.
 *
 * The result is the shaped, updated customer via {@see CustomerListShaper::detail()}
 * plus an `edit_link`, the same projection `wc-customers/get-customer` returns.
 *
 * Credential-sensitive: `password` is a writable INPUT (forwarded to the route,
 * which calls `WC_Customer::set_password()`), but it is NEVER returned. The WC
 * customer response carries no `password` key, the shaper never exposes one, and
 * the output schema does not declare one.
 *
 * Username is NOT editable: the route rejects a changed `username` with
 * `woocommerce_rest_customer_invalid_argument` (400), so this ability does not
 * expose `username` as a writable input.
 *
 * Only available when WooCommerce is active (it is a {@see ConditionalAbility}).
 *
 * @since 0.1.0
 */
final class UpdateCustomer implements ConditionalAbility {

	/**
	 * The writable customer fields forwarded to the route on key presence.
	 *
	 * The nested `billing` / `shipping` address objects are forwarded whole when
	 * present. `password` is forwarded here too but is never read back or returned.
	 *
	 * @var array<int,string>
	 */
	private const WRITABLE_FIELDS = array(
		'email',
		'first_name',
		'last_name',
		'password',
		'billing',
		'shipping',
	);

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'wc-customers/update-customer';
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
		$output_schema                            = CustomerListShaper::detailSchema();
		$output_schema['required']                = array( 'id' );
		$output_schema['properties']['edit_link'] = array(
			'type'        => 'string',
			'description' => __( 'The wp-admin URL to edit the customer user account. Surface this so a human can review the change.', 'abilities-catalog-woo' ),
		);

		return array(
			'label'               => __( 'Update Customer', 'abilities-catalog-woo' ),
			'description'         => __( 'Updates an existing WooCommerce customer by ID and returns the shaped customer (id, email, names, role, billing and shipping blocks) plus edit_link; send only the fields you want to change. Use wc-customers/create-customer to add a new customer; discover IDs with wc-customers/list-customers. The result contains personal data (email, names, billing/shipping addresses). password is write-only: set it on input to change the login password, but it is never returned. The username cannot be changed — sending a different username is rejected. With the shop_manager role you may edit only customer-role users; editing an administrator or other non-customer user is refused by the route.', 'abilities-catalog-woo' ),
			'category'            => 'wc-customers',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'id' ),
				'properties'           => array(
					'id'         => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'The customer ID to update. Discover IDs with wc-customers/list-customers.', 'abilities-catalog-woo' ),
					),
					'email'      => array(
						'type'        => 'string',
						'format'      => 'email',
						'description' => __( 'A new account email address. Rejected if it already belongs to another user.', 'abilities-catalog-woo' ),
					),
					'first_name' => array(
						'type'        => 'string',
						'description' => __( 'The customer first name.', 'abilities-catalog-woo' ),
					),
					'last_name'  => array(
						'type'        => 'string',
						'description' => __( 'The customer last name.', 'abilities-catalog-woo' ),
					),
					'password'   => array(
						'type'        => 'string',
						'description' => __( 'A new login password (write-only). Set it to change the password; it is never returned in the result.', 'abilities-catalog-woo' ),
					),
					'billing'    => array(
						'type'                 => 'object',
						'description'          => __( 'The billing address to set. Send the whole block; the billing address has an email field.', 'abilities-catalog-woo' ),
						'properties'           => array(
							'first_name' => array(
								'type'        => 'string',
								'description' => __( 'The billing first name.', 'abilities-catalog-woo' ),
							),
							'last_name'  => array(
								'type'        => 'string',
								'description' => __( 'The billing last name.', 'abilities-catalog-woo' ),
							),
							'company'    => array(
								'type'        => 'string',
								'description' => __( 'The billing company name.', 'abilities-catalog-woo' ),
							),
							'address_1'  => array(
								'type'        => 'string',
								'description' => __( 'The billing street address line 1.', 'abilities-catalog-woo' ),
							),
							'address_2'  => array(
								'type'        => 'string',
								'description' => __( 'The billing street address line 2.', 'abilities-catalog-woo' ),
							),
							'city'       => array(
								'type'        => 'string',
								'description' => __( 'The billing city.', 'abilities-catalog-woo' ),
							),
							'state'      => array(
								'type'        => 'string',
								'description' => __( 'The billing state, province, or district as an ISO code or name.', 'abilities-catalog-woo' ),
							),
							'postcode'   => array(
								'type'        => 'string',
								'description' => __( 'The billing postal code.', 'abilities-catalog-woo' ),
							),
							'country'    => array(
								'type'        => 'string',
								'description' => __( 'The billing country code in ISO 3166-1 alpha-2 format, e.g. US.', 'abilities-catalog-woo' ),
							),
							'email'      => array(
								'type'        => 'string',
								'format'      => 'email',
								'description' => __( 'The billing email address.', 'abilities-catalog-woo' ),
							),
							'phone'      => array(
								'type'        => 'string',
								'description' => __( 'The billing phone number.', 'abilities-catalog-woo' ),
							),
						),
						'additionalProperties' => false,
					),
					'shipping'   => array(
						'type'                 => 'object',
						'description'          => __( 'The shipping address to set. Send the whole block; the shipping address has no email field.', 'abilities-catalog-woo' ),
						'properties'           => array(
							'first_name' => array(
								'type'        => 'string',
								'description' => __( 'The shipping first name.', 'abilities-catalog-woo' ),
							),
							'last_name'  => array(
								'type'        => 'string',
								'description' => __( 'The shipping last name.', 'abilities-catalog-woo' ),
							),
							'company'    => array(
								'type'        => 'string',
								'description' => __( 'The shipping company name.', 'abilities-catalog-woo' ),
							),
							'address_1'  => array(
								'type'        => 'string',
								'description' => __( 'The shipping street address line 1.', 'abilities-catalog-woo' ),
							),
							'address_2'  => array(
								'type'        => 'string',
								'description' => __( 'The shipping street address line 2.', 'abilities-catalog-woo' ),
							),
							'city'       => array(
								'type'        => 'string',
								'description' => __( 'The shipping city.', 'abilities-catalog-woo' ),
							),
							'state'      => array(
								'type'        => 'string',
								'description' => __( 'The shipping state, province, or district as an ISO code or name.', 'abilities-catalog-woo' ),
							),
							'postcode'   => array(
								'type'        => 'string',
								'description' => __( 'The shipping postal code.', 'abilities-catalog-woo' ),
							),
							'country'    => array(
								'type'        => 'string',
								'description' => __( 'The shipping country code in ISO 3166-1 alpha-2 format, e.g. US.', 'abilities-catalog-woo' ),
							),
							'phone'      => array(
								'type'        => 'string',
								'description' => __( 'The shipping phone number.', 'abilities-catalog-woo' ),
							),
						),
						'additionalProperties' => false,
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => $output_schema,
			'execute_callback'    => array( $this, 'execute' ),
			'permission_callback' => array( $this, 'hasPermission' ),
			'meta'                => array(
				'annotations'  => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => false,
				),
				'show_in_rest' => true,
				'screen'       => 'user-edit.php?user_id={id}',
			),
		);
	}

	/**
	 * Permission check: WooCommerce's edit capability for customers.
	 *
	 * Mirrors `wc_rest_check_user_permissions( 'edit', $id )`, which the wrapped
	 * `wc/v3` customers PUT route enforces as the coarse `edit_users` capability.
	 * This is the type-level, object-INDEPENDENT guard; the per-object decision is
	 * deferred to the wrapped route, which resolves the object-level `edit_user`
	 * meta-cap AND enforces that a `shop_manager` may edit only `customer`-role
	 * users. Doing the object-level check here would mask a missing customer as a
	 * permission denial; deferring to the route lets execute() surface the route's
	 * specific error (`wc_user_invalid_id` 404, `woocommerce_rest_cannot_edit`
	 * 403) via {@see RestError::from()}. Customers carry PII, so this cap is the real
	 * protection. The explicit activity guard keeps the denial clean when WooCommerce
	 * is inactive.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may edit customers.
	 */
	public function hasPermission( $input ): bool {
		return WooPlugin::isActive() && current_user_can( 'edit_users' );
	}

	/**
	 * Executes the ability by dispatching the internal `wc/v3` REST update request.
	 *
	 * The id is concatenated into the route path. Writable fields are forwarded on
	 * key presence so the caller changes only what it sends. `password` is forwarded
	 * but never read back or returned.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The shaped updated customer, or the REST
	 *                                        error (e.g. `wc_user_invalid_id` 404).
	 */
	public function execute( $input ) {
		if ( ! WooPlugin::isActive() ) {
			return WooPlugin::unavailable();
		}

		$input = is_array( $input ) ? $input : array();
		$id    = absint( $input['id'] ?? 0 );

		$request = new WP_REST_Request( 'PUT', '/wc/v3/customers/' . $id );

		foreach ( self::WRITABLE_FIELDS as $field ) {
			if ( ! array_key_exists( $field, $input ) ) {
				continue;
			}

			$request->set_param( $field, $input[ $field ] );
		}

		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			return RestError::from( $response );
		}

		$data = rest_get_server()->response_to_data( $response, false );
		$data = is_array( $data ) ? $data : array();

		$result              = CustomerListShaper::detail( $data );
		$result['edit_link'] = admin_url( 'user-edit.php?user_id=' . $id );

		return $result;
	}
}
