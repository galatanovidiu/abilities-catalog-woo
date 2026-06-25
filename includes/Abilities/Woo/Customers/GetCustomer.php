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
 * Read ability: `og-wc-customers/get-customer`.
 *
 * Wraps `GET wc/v3/customers/<id>` via `rest_do_request()` and returns one
 * customer as a flat, closed record: the {@see CustomerListShaper::summary()}
 * fields (id, email, names, username, role, registration date, orders_count,
 * total_spent) plus the trimmed billing and shipping address blocks a single
 * customer view needs, and an `edit_link`. The raw `wc/v3` customer body carries
 * `meta_data`, the avatar URL, and the `is_paying_customer` flag a consumer never
 * reads; this projects only the useful subset via the shaper.
 *
 * PII: a customer record carries personal data — email, names, and the billing
 * and shipping addresses. The capability (`list_users`) is the hard server-side
 * guard; the shaper exposes a fixed subset and never the raw customer object,
 * `meta_data`, or `avatar_url`.
 *
 * Only available when WooCommerce is active (it is a {@see ConditionalAbility}).
 *
 * @since 0.1.0
 */
final class GetCustomer implements ConditionalAbility {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-wc-customers/get-customer';
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
			'description' => __( 'The wp-admin URL to edit the customer user account. Surface this so a human can review the customer.', 'abilities-catalog-woo' ),
		);

		return array(
			'label'               => __( 'Get Customer', 'abilities-catalog-woo' ),
			'description'         => __( 'Returns one WooCommerce customer by ID: id, email, first and last name, username, role, registration date, orders_count, total_spent, the billing block (name, company, address, email, and phone), the shipping block (name, company, address, and phone; the shipping address has no email), and an edit_link. Use og-wc-customers/list-customers to scan customers and discover IDs; use this for one customer\'s full detail. Returns personal data (the customer\'s name, email, and billing/shipping addresses): visible only with the list_users capability.', 'abilities-catalog-woo' ),
			'category'            => 'og-wc-customers',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'id' ),
				'properties'           => array(
					'id' => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'The customer ID. Discover IDs with og-wc-customers/list-customers.', 'abilities-catalog-woo' ),
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => $output_schema,
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
	 * Permission check: WooCommerce's read capability for customers.
	 *
	 * Mirrors `wc_rest_check_user_permissions( 'read' )`, which the wrapped `wc/v3`
	 * customers GET route enforces as `list_users` — the baseline a successful
	 * caller must hold. This is a coarse, object-INDEPENDENT type-level guard: the
	 * per-object decision is deferred to the wrapped route, so a missing customer
	 * surfaces its specific `wc_user_invalid_id` 404 via {@see RestError::from()}
	 * instead of collapsing to a generic permission denial. Customers carry PII, so
	 * this cap is the real protection. The explicit activity guard keeps the denial
	 * clean when WooCommerce is inactive.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may read customers.
	 */
	public function hasPermission( $input ): bool {
		return WooPlugin::isActive() && current_user_can( 'list_users' );
	}

	/**
	 * Executes the ability by dispatching the internal `wc/v3` REST request.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The shaped customer record, or the REST error
	 *                                        (e.g. `wc_user_invalid_id` 404).
	 */
	public function execute( $input ) {
		if ( ! WooPlugin::isActive() ) {
			return WooPlugin::unavailable();
		}

		$input = is_array( $input ) ? $input : array();
		$id    = absint( $input['id'] ?? 0 );

		$request  = new WP_REST_Request( 'GET', '/wc/v3/customers/' . $id );
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
