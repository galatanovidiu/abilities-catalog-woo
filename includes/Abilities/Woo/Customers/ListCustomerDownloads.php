<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogWoo\Abilities\Woo\Customers;

use GalatanOvidiu\AbilitiesCatalogWoo\Contracts\ConditionalAbility;
use GalatanOvidiu\AbilitiesCatalogWoo\Support\CustomerDownloadListShaper;
use GalatanOvidiu\AbilitiesCatalogWoo\Support\RestError;
use GalatanOvidiu\AbilitiesCatalogWoo\Support\WooPlugin;
use WP_REST_Request;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read ability: `og-wc-customers/list-customer-downloads`.
 *
 * Wraps `GET wc/v3/customers/<customer_id>/downloads` via `rest_do_request()` and
 * returns the downloadable files a customer may download as flat summary rows
 * through {@see CustomerDownloadListShaper::summary()}, so a consumer can see what
 * a customer can download without the raw row.
 *
 * Only available when WooCommerce is active (it is a {@see ConditionalAbility}).
 * The WC customer-downloads route returns a bare array with no pagination headers,
 * so `total` is the number of rows returned.
 *
 * SECURITY: the raw row carries `download_url`, `order_key`, `email`,
 * `access_expires_gmt`, and a raw `file` block. The `download_url` is an
 * unauthenticated bearer link to the file that embeds the customer email and the
 * order key, so the shaper REDACTS all five fields; only the seven safe
 * identifying fields are returned. `list_users` is the hard server-side guard, and
 * the wrapped route additionally object-checks the customer (404 on a missing one).
 *
 * @since 0.1.0
 */
final class ListCustomerDownloads implements ConditionalAbility {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-wc-customers/list-customer-downloads';
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
			'label'               => __( 'List Customer Downloads', 'abilities-catalog-woo' ),
			'description'         => __( 'Returns the downloadable files a WooCommerce customer has access to as flat summary rows, each with the download_id, product (id and name), download_name, the order_id that granted access, downloads_remaining, and access_expires. Pass the customer\'s user ID; discover IDs with og-wc-customers/list-customers. Read-only. For privacy and security the result deliberately omits the raw download URL, order key, customer email, and file block: the download URL is an unauthenticated bearer link to the file, so it is never surfaced.', 'abilities-catalog-woo' ),
			'category'            => 'og-wc-customers',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'customer_id' ),
				'properties'           => array(
					'customer_id' => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'The customer\'s WordPress user ID. Discover IDs with og-wc-customers/list-customers. A missing customer returns a 404 (wc_user_invalid_id).', 'abilities-catalog-woo' ),
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'customer_id', 'items', 'total' ),
				'properties'           => array(
					'customer_id' => array(
						'type'        => 'integer',
						'description' => __( 'The customer user ID the downloads belong to, echoed from the request.', 'abilities-catalog-woo' ),
					),
					'items'       => array(
						'type'        => 'array',
						'description' => __( 'The customer\'s available downloads as flat summary rows. The raw download URL, order key, customer email, and file block are omitted by design.', 'abilities-catalog-woo' ),
						'items'       => CustomerDownloadListShaper::itemSchema(),
					),
					'total'       => array(
						'type'        => 'integer',
						'description' => __( 'The number of downloads returned. This route returns a bare array with no pagination headers, so this counts the returned rows.', 'abilities-catalog-woo' ),
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
	 * Encodes the catalog baseline for `og-wc-customers/list-customer-downloads`: the
	 * `list_users` capability, which is what `wc_rest_check_user_permissions( 'read' )`
	 * resolves to on the wrapped customer routes. This is the coarse,
	 * object-independent guard; the wrapped route additionally object-checks the
	 * customer and surfaces the specific `wc_user_invalid_id` 404 for a missing one
	 * via {@see RestError::from()}, so doing the object-level check here would mask
	 * that as a generic permission denial. The explicit activity guard keeps the
	 * denial clean when WooCommerce is inactive and the capability is unmapped.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may read customers.
	 */
	public function hasPermission( $input ): bool {
		return WooPlugin::isActive() && current_user_can( 'list_users' );
	}

	/**
	 * Executes the ability by dispatching the internal WooCommerce REST request.
	 *
	 * The route is built by string concatenation, never `set_param`, so the
	 * `customer_id` path segment is preserved.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The customer's downloads, or the REST error.
	 */
	public function execute( $input ) {
		if ( ! WooPlugin::isActive() ) {
			return WooPlugin::unavailable();
		}

		$input       = is_array( $input ) ? $input : array();
		$customer_id = absint( $input['customer_id'] ?? 0 );

		$request  = new WP_REST_Request( 'GET', '/wc/v3/customers/' . $customer_id . '/downloads' );
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

			$rows[] = CustomerDownloadListShaper::summary( $item );
		}

		return array(
			'customer_id' => $customer_id,
			'items'       => $rows,
			'total'       => count( $rows ),
		);
	}
}
