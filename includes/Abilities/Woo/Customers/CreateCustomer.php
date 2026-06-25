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
 * Write ability: `og-wc-customers/create-customer`.
 *
 * Wraps `POST wc/v3/customers` via `rest_do_request()`, creating a new WooCommerce
 * customer (a WordPress user with the `customer` role) and returning it shaped
 * through {@see CustomerListShaper::detail()} plus an `edit_link`. The WC create
 * route calls `WC_Customer::save()` unconditionally, so a plain POST persists; no
 * `context=save` flag is needed (that is a CF7-only requirement).
 *
 * Credential-sensitive: `password` is a writable INPUT — it is forwarded to the
 * request when present so the new account has a password — but it is NEVER
 * returned. The WC customer response carries no `password` key, the shaper never
 * copies one, and the output schema does not declare one. The password is never
 * logged or read back.
 *
 * PII: the result carries personal data (email, names, and the billing/shipping
 * address blocks). The capability (`create_customers`) is the hard server-side
 * guard.
 *
 * Only available when WooCommerce is active (it is a {@see ConditionalAbility}).
 *
 * @since 0.1.0
 */
final class CreateCustomer implements ConditionalAbility {

	/**
	 * The writable address sub-fields shared by the billing and shipping blocks.
	 * Billing additionally accepts `email`; shipping does not (the WooCommerce
	 * shipping address has no email field).
	 *
	 * @var list<string>
	 */
	private const ADDRESS_FIELDS = array(
		'first_name',
		'last_name',
		'company',
		'address_1',
		'address_2',
		'city',
		'state',
		'postcode',
		'country',
	);

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-wc-customers/create-customer';
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
			'description' => __( 'The wp-admin URL to edit the new customer user account. Surface this so a human can review the customer.', 'abilities-catalog-woo' ),
		);

		return array(
			'label'               => __( 'Create Customer', 'abilities-catalog-woo' ),
			'description'         => __( 'Creates a new WooCommerce customer (a WordPress user with the customer role) and returns the shaped record: id, email, first and last name, username, role, registration date, orders_count, total_spent, the billing and shipping address blocks, and an edit_link. Only email is required; WooCommerce generates a username when none is given. Use this to create; use og-wc-customers/update-customer to change an existing customer (discover IDs with og-wc-customers/list-customers). The result contains personal data (the customer\'s name, email, and addresses). password is write-only: set it to give the account a password, but it is never returned in the result. After creating, surface edit_link so a human can review the account.', 'abilities-catalog-woo' ),
			'category'            => 'og-wc-customers',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'email' ),
				'properties'           => array(
					'email'      => array(
						'type'        => 'string',
						'format'      => 'email',
						'description' => __( 'The customer account email address. Required and must not already be in use by another account.', 'abilities-catalog-woo' ),
					),
					'first_name' => array(
						'type'        => 'string',
						'description' => __( 'The customer first name.', 'abilities-catalog-woo' ),
					),
					'last_name'  => array(
						'type'        => 'string',
						'description' => __( 'The customer last name.', 'abilities-catalog-woo' ),
					),
					'username'   => array(
						'type'        => 'string',
						'description' => __( 'The customer login name. Omit to let WooCommerce generate one from the email.', 'abilities-catalog-woo' ),
					),
					'password'   => array(
						'type'        => 'string',
						'description' => __( 'The account password. Write-only: it is set on the new account but is never returned in the result. Omit to leave WooCommerce to handle the password.', 'abilities-catalog-woo' ),
					),
					'billing'    => array(
						'type'                 => 'object',
						'description'          => __( 'The billing address to set on the new customer. The billing address has an email sub-field; the shipping address does not.', 'abilities-catalog-woo' ),
						'properties'           => $this->addressProperties( true ),
						'additionalProperties' => false,
					),
					'shipping'   => array(
						'type'                 => 'object',
						'description'          => __( 'The shipping address to set on the new customer. The WooCommerce shipping address has no email sub-field.', 'abilities-catalog-woo' ),
						'properties'           => $this->addressProperties( false ),
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
	 * Permission check: WooCommerce's create capability for customers.
	 *
	 * Mirrors `wc_rest_check_user_permissions( 'create' )`, which the wrapped
	 * `wc/v3` customers POST route enforces as `create_customers` — the baseline a
	 * successful caller must hold. This is a coarse, object-INDEPENDENT type-level
	 * guard: any per-request validation (a duplicate or malformed email) is deferred
	 * to the wrapped route so it surfaces its specific 400 via {@see RestError::from()}
	 * rather than collapsing to a generic permission denial. The explicit activity
	 * guard keeps the denial clean when WooCommerce is inactive.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may create customers.
	 */
	public function hasPermission( $input ): bool {
		return WooPlugin::isActive() && current_user_can( 'create_customers' );
	}

	/**
	 * Executes the ability by dispatching the internal `wc/v3` REST create request.
	 *
	 * Forwards the writable fields present in the input (including `password`, which
	 * is set but never read back), then shapes the created customer through the
	 * shaper and appends `edit_link`.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The shaped created customer, or the REST
	 *                                        error (e.g. `woocommerce_rest_customer_invalid_email` 400).
	 */
	public function execute( $input ) {
		if ( ! WooPlugin::isActive() ) {
			return WooPlugin::unavailable();
		}

		$input = is_array( $input ) ? $input : array();

		$request = new WP_REST_Request( 'POST', '/wc/v3/customers' );

		// Forward the scalar writable fields present in the input. Empty strings are
		// skipped so an omitted-but-present blank does not overwrite a generated value;
		// password is forwarded when present but never read back from the response.
		foreach ( array( 'email', 'first_name', 'last_name', 'username', 'password' ) as $field ) {
			if ( ! isset( $input[ $field ] ) || '' === $input[ $field ] ) {
				continue;
			}

			$request->set_param( $field, $input[ $field ] );
		}

		foreach ( array( 'billing', 'shipping' ) as $block ) {
			if ( ! isset( $input[ $block ] ) || ! is_array( $input[ $block ] ) ) {
				continue;
			}

			$request->set_param( $block, $input[ $block ] );
		}

		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			return RestError::from( $response );
		}

		$data = rest_get_server()->response_to_data( $response, false );
		$data = is_array( $data ) ? $data : array();

		$result              = CustomerListShaper::detail( $data );
		$result['edit_link'] = admin_url( 'user-edit.php?user_id=' . (int) ( $data['id'] ?? 0 ) );

		return $result;
	}

	/**
	 * The writable property fragment for an address block.
	 *
	 * @param bool $with_email Whether to include the `email` sub-field (billing only).
	 * @return array<string,array<string,string>> A JSON-Schema `properties` fragment.
	 */
	private function addressProperties( bool $with_email ): array {
		$properties = array();

		foreach ( self::ADDRESS_FIELDS as $field ) {
			$properties[ $field ] = array(
				'type'        => 'string',
				'description' => sprintf(
					/* translators: %s: address field name, e.g. address_1. */
					__( 'The %s for the address.', 'abilities-catalog-woo' ),
					$field
				),
			);
		}

		if ( $with_email ) {
			$properties['email'] = array(
				'type'        => 'string',
				'format'      => 'email',
				'description' => __( 'The billing email address.', 'abilities-catalog-woo' ),
			);
		}

		$properties['phone'] = array(
			'type'        => 'string',
			'description' => __( 'The phone number for the address.', 'abilities-catalog-woo' ),
		);

		return $properties;
	}
}
