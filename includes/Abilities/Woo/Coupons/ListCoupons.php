<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogWoo\Abilities\Woo\Coupons;

use GalatanOvidiu\AbilitiesCatalogWoo\Contracts\ConditionalAbility;
use GalatanOvidiu\AbilitiesCatalogWoo\Support\CouponListShaper;
use GalatanOvidiu\AbilitiesCatalogWoo\Support\RestError;
use GalatanOvidiu\AbilitiesCatalogWoo\Support\WooPlugin;
use WP_REST_Request;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read ability: `wc-coupons/list-coupons`.
 *
 * Wraps `GET wc/v3/coupons` via `rest_do_request()` and returns each coupon as a
 * flat summary row (id, code, amount, discount type, expiry, usage, individual-use
 * flag) via {@see CouponListShaper::summary()}. The raw wc/v3 row carries usage
 * restrictions, email restrictions, the list of users who have used the coupon,
 * and category lists that a consumer never needs to scan a coupon list, so the
 * shaper exposes only the small fixed field set and pins the schema closed.
 *
 * Only available when WooCommerce is active (it is a {@see ConditionalAbility}).
 * The wrapped route is paginated, so `total` is read from the `X-WP-Total`
 * response header — it reflects the full matching set, not just the returned page.
 *
 * @since 0.1.0
 */
final class ListCoupons implements ConditionalAbility {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'wc-coupons/list-coupons';
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
			'label'               => __( 'List Coupons', 'abilities-catalog-woo' ),
			'description'         => __( 'Returns the store\'s WooCommerce coupons as flat summary rows, each with its id, code, discount amount, discount type, expiry date, usage count, usage limit, and individual-use flag. Filter by code for an exact-code lookup, or by a free-text search. Use wc-coupons/get-coupon for one coupon\'s full detail (description, product include/exclude lists, and amount thresholds). Read-only.', 'abilities-catalog-woo' ),
			'category'            => 'wc-coupons',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'search'   => array(
						'type'        => 'string',
						'description' => __( 'Limit results to coupons matching a free-text search term.', 'abilities-catalog-woo' ),
					),
					'code'     => array(
						'type'        => 'string',
						'description' => __( 'Limit results to the coupon with this exact code. Coupon codes are unique, so this resolves to at most one coupon.', 'abilities-catalog-woo' ),
					),
					'per_page' => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'maximum'     => 100,
						'default'     => 100,
						'description' => __( 'Maximum number of coupons to return (1-100). Defaults to 100, which covers every coupon on a typical store.', 'abilities-catalog-woo' ),
					),
					'page'     => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'default'     => 1,
						'description' => __( 'Page of results to return, for paging past the first per_page coupons.', 'abilities-catalog-woo' ),
					),
					'orderby'  => array(
						'type'        => 'string',
						'enum'        => array( 'date', 'id', 'include', 'title', 'slug', 'modified' ),
						'default'     => 'date',
						'description' => __( 'Coupon attribute to sort by: "date" (creation date), "id", "include" (the requested id order), "title", "slug", or "modified". Defaults to "date".', 'abilities-catalog-woo' ),
					),
					'order'    => array(
						'type'        => 'string',
						'enum'        => array( 'asc', 'desc' ),
						'default'     => 'desc',
						'description' => __( 'Sort direction: "asc" (ascending) or "desc" (descending). Defaults to "desc".', 'abilities-catalog-woo' ),
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
						'description' => __( 'The coupons as flat summary rows. Use wc-coupons/get-coupon for a single coupon\'s full detail.', 'abilities-catalog-woo' ),
						'items'       => CouponListShaper::itemSchema(),
					),
					'total' => array(
						'type'        => 'integer',
						'description' => __( 'The total number of coupons matching the query across all pages, read from the X-WP-Total response header. May exceed the number of returned rows when paging.', 'abilities-catalog-woo' ),
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
	 * Permission check: WooCommerce's read capability for coupons.
	 *
	 * Encodes the catalog baseline for `wc-coupons/list-coupons`: the
	 * `read_private_shop_coupons` capability, which is what
	 * `wc_rest_check_post_permissions( 'shop_coupon', 'read' )` resolves to on the
	 * wrapped `GET wc/v3/coupons` route (the shop_coupon post type maps the `read`
	 * context to its `read_private_posts` cap, i.e. `read_private_shop_coupons`).
	 * This is a coarse, object-independent guard. The explicit activity guard keeps
	 * the denial clean when WooCommerce is inactive and the capability is unmapped.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may read the coupon list.
	 */
	public function hasPermission( $input ): bool {
		return WooPlugin::isActive() && current_user_can( 'read_private_shop_coupons' );
	}

	/**
	 * Executes the ability by dispatching the internal WooCommerce REST request.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The list of coupons, or the REST error.
	 */
	public function execute( $input ) {
		if ( ! WooPlugin::isActive() ) {
			return WooPlugin::unavailable();
		}

		$input = is_array( $input ) ? $input : array();

		$request = new WP_REST_Request( 'GET', '/wc/v3/coupons' );

		if ( ! empty( $input['search'] ) ) {
			$request->set_param( 'search', (string) $input['search'] );
		}
		if ( ! empty( $input['code'] ) ) {
			$request->set_param( 'code', (string) $input['code'] );
		}
		$request->set_param( 'per_page', max( 1, min( 100, absint( $input['per_page'] ?? 100 ) ) ) );
		$request->set_param( 'page', max( 1, absint( $input['page'] ?? 1 ) ) );
		$request->set_param( 'orderby', (string) ( $input['orderby'] ?? 'date' ) );
		$request->set_param( 'order', (string) ( $input['order'] ?? 'desc' ) );

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

			$rows[] = CouponListShaper::summary( $item );
		}

		$headers = $response->get_headers();
		$total   = isset( $headers['X-WP-Total'] ) ? (int) $headers['X-WP-Total'] : count( $rows );

		return array(
			'items' => $rows,
			'total' => $total,
		);
	}
}
