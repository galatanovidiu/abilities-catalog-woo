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
 * Read ability: `og-wc-customers/list-customers`.
 *
 * Wraps `GET wc/v3/customers` via `rest_do_request()` and returns each customer as
 * a flat summary row through {@see CustomerListShaper::summary()}, so a consumer
 * scans the customer base without the raw customer body (billing/shipping blocks,
 * meta data, avatar URL, and the is_paying_customer flag are dropped). Exposes a
 * minimal, useful subset of the controller's collection params (search, paging,
 * role, email, ordering) — not every filter.
 *
 * PII: the returned rows carry personally identifiable information — each row
 * includes the customer email and name. The capability the permission check
 * enforces is the hard guard on who may run this read.
 *
 * Only available when WooCommerce is active (it is a {@see ConditionalAbility}).
 * The WC customers list route is a WP_User_Query-backed collection that sends
 * pagination headers, so `total` is the full matching count from `X-WP-Total`,
 * not just the number of rows on this page.
 *
 * @since 0.1.0
 */
final class ListCustomers implements ConditionalAbility {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-wc-customers/list-customers';
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
			'label'               => __( 'List Customers', 'abilities-catalog-woo' ),
			'description'         => __( 'Returns the store\'s WooCommerce customers as flat summary rows, each with its id, email, first and last name, username, role, registration date, orders_count, and total_spent. Filter by search term, exact email, or role, and sort with orderby/order. By default only the "customer" role is returned; pass role "all" to widen to every role. Use og-wc-customers/get-customer for one customer\'s full detail including billing and shipping addresses. Read-only. Note: the result contains personally identifiable information (customer email and name).', 'abilities-catalog-woo' ),
			'category'            => 'og-wc-customers',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'search'   => array(
						'type'        => 'string',
						'description' => __( 'Limit results to customers matching a search term (matches name, email, and username).', 'abilities-catalog-woo' ),
					),
					'per_page' => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'maximum'     => 100,
						'default'     => 100,
						'description' => __( 'Maximum number of customers to return (1-100). Defaults to 100.', 'abilities-catalog-woo' ),
					),
					'page'     => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'default'     => 1,
						'description' => __( 'The page of results to return, starting at 1. Use total to compute how many pages exist.', 'abilities-catalog-woo' ),
					),
					'role'     => array(
						'type'        => 'string',
						'default'     => 'customer',
						'description' => __( 'Limit results to users with a specific role. Defaults to "customer"; pass "all" to widen to every role.', 'abilities-catalog-woo' ),
					),
					'email'    => array(
						'type'        => 'string',
						'format'      => 'email',
						'description' => __( 'Limit results to the customer with this exact email address.', 'abilities-catalog-woo' ),
					),
					'orderby'  => array(
						'type'        => 'string',
						'enum'        => array( 'id', 'include', 'name', 'registered_date' ),
						'default'     => 'name',
						'description' => __( 'Sort the result set by this attribute. Defaults to "name".', 'abilities-catalog-woo' ),
					),
					'order'    => array(
						'type'        => 'string',
						'enum'        => array( 'asc', 'desc' ),
						'default'     => 'asc',
						'description' => __( 'Sort direction: "asc" (ascending) or "desc" (descending). Defaults to "asc".', 'abilities-catalog-woo' ),
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'items', 'total' ),
				'properties'           => array(
					'items' => array(
						'type'        => 'array',
						'description' => __( 'The customers as flat summary rows. Use og-wc-customers/get-customer for a single customer\'s full detail. Rows contain personally identifiable information (email and name).', 'abilities-catalog-woo' ),
						'items'       => CustomerListShaper::itemSchema(),
					),
					'total' => array(
						'type'        => 'integer',
						'description' => __( 'The total number of customers matching the query across all pages, read from the X-WP-Total response header. May exceed the number of returned rows when paging.', 'abilities-catalog-woo' ),
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
	 * Permission check: WooCommerce's read capability for customers.
	 *
	 * Encodes the catalog baseline for `og-wc-customers/list-customers`: the
	 * `list_users` capability, which is what `wc_rest_check_user_permissions(
	 * 'read' )` resolves to on the wrapped `GET wc/v3/customers` route. This is a
	 * coarse, object-independent guard; the wrapped route applies any per-customer
	 * visibility. The explicit activity guard keeps the denial clean when
	 * WooCommerce is inactive and the REST routes are unregistered.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may read the customer base.
	 */
	public function hasPermission( $input ): bool {
		return WooPlugin::isActive() && current_user_can( 'list_users' );
	}

	/**
	 * Executes the ability by dispatching the internal WooCommerce REST request.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The list of customers, or the REST error.
	 */
	public function execute( $input ) {
		if ( ! WooPlugin::isActive() ) {
			return WooPlugin::unavailable();
		}

		$input = is_array( $input ) ? $input : array();

		$request = new WP_REST_Request( 'GET', '/wc/v3/customers' );

		if ( ! empty( $input['search'] ) ) {
			$request->set_param( 'search', (string) $input['search'] );
		}
		$request->set_param( 'per_page', max( 1, min( 100, absint( $input['per_page'] ?? 100 ) ) ) );
		$request->set_param( 'page', max( 1, absint( $input['page'] ?? 1 ) ) );
		$request->set_param( 'role', (string) ( $input['role'] ?? 'customer' ) );
		if ( ! empty( $input['email'] ) ) {
			$request->set_param( 'email', (string) $input['email'] );
		}
		$request->set_param( 'orderby', (string) ( $input['orderby'] ?? 'name' ) );
		$request->set_param( 'order', (string) ( $input['order'] ?? 'asc' ) );

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

			$rows[] = CustomerListShaper::summary( $item );
		}

		$headers = $response->get_headers();
		$total   = isset( $headers['X-WP-Total'] ) ? (int) $headers['X-WP-Total'] : count( $rows );

		return array(
			'items' => $rows,
			'total' => $total,
		);
	}
}
