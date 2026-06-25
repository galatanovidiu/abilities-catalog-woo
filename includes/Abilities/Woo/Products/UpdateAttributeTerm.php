<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogWoo\Abilities\Woo\Products;

use GalatanOvidiu\AbilitiesCatalogWoo\Contracts\ConditionalAbility;
use GalatanOvidiu\AbilitiesCatalogWoo\Support\ProductTermListShaper;
use GalatanOvidiu\AbilitiesCatalogWoo\Support\RestError;
use GalatanOvidiu\AbilitiesCatalogWoo\Support\WooPlugin;
use WP_REST_Request;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Write ability: `og-wc-products/update-attribute-term`.
 *
 * Wraps `PUT wc/v3/products/attributes/<attribute_id>/terms/<id>` via
 * `rest_do_request()`, editing one term of a global product attribute (e.g.
 * renaming "Red" under the "Color" attribute). Both ids are route segments:
 * `attribute_id` selects the global attribute (its `pa_*` taxonomy) and `id`
 * selects the term inside it; neither is a body field. Only the fields you send
 * are changed — an omitted field keeps its current value. The result is the
 * updated term as a flat, closed summary row through
 * {@see ProductTermListShaper::termSummary()} (id, name, slug, parent, count,
 * description), not the raw `wc/v3` term body.
 *
 * This edits the catalog taxonomy only; it does not touch orders, prices, or
 * money, and is reversible by another update. It is a {@see ConditionalAbility}
 * available only when WooCommerce is active.
 *
 * @since 0.1.0
 */
final class UpdateAttributeTerm implements ConditionalAbility {

	/**
	 * The optional editable fields forwarded to the wrapped route on key presence.
	 *
	 * `name`, `slug`, and `description` are strings; `menu_order` is an integer.
	 * Forwarding only present keys means an omitted field keeps its current value.
	 *
	 * @var list<string>
	 */
	private const EDITABLE_FIELDS = array( 'name', 'slug', 'description', 'menu_order' );

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-wc-products/update-attribute-term';
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
		$output_schema             = ProductTermListShaper::termItemSchema();
		$output_schema['required'] = array( 'id' );

		return array(
			'label'               => __( 'Update Attribute Term', 'abilities-catalog-woo' ),
			'description'         => __( 'Updates one term of a global product attribute by ID (for example renaming "Red" under the "Color" attribute) and returns the updated term as a flat row: id, name, slug, parent (always 0 for attribute terms), product count, and description. Send only the fields you want to change; an omitted field keeps its current value. Provide attribute_id (the parent attribute) and id (the term) — both identify the term and are not body fields. Discover attribute_id with og-wc-products/list-product-attributes and id with og-wc-products/list-attribute-terms. Setting slug to a value already used by another term on this attribute returns a term_exists 400 error. A missing term returns woocommerce_rest_term_invalid 404; an unknown attribute_id returns woocommerce_rest_taxonomy_invalid 404. This edits the catalog taxonomy only (not orders or prices) and is reversible by another update.', 'abilities-catalog-woo' ),
			'category'            => 'og-wc-products',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'attribute_id', 'id' ),
				'properties'           => array(
					'attribute_id' => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'The parent attribute ID (the global attribute the term belongs to). Discover IDs with og-wc-products/list-product-attributes.', 'abilities-catalog-woo' ),
					),
					'id'           => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'The attribute term ID to update. Discover IDs with og-wc-products/list-attribute-terms.', 'abilities-catalog-woo' ),
					),
					'name'         => array(
						'type'        => 'string',
						'description' => __( 'A new term name shown to shoppers, e.g. "Crimson". Omit to keep the current name.', 'abilities-catalog-woo' ),
					),
					'slug'         => array(
						'type'        => 'string',
						'description' => __( 'A new URL slug for the term. A slug already used by another term on this attribute returns a term_exists 400 error. Omit to keep the current slug.', 'abilities-catalog-woo' ),
					),
					'description'  => array(
						'type'        => 'string',
						'description' => __( 'A new term description (HTML allowed; sanitized by WordPress). Send an empty string to clear it; omit to keep the current description.', 'abilities-catalog-woo' ),
					),
					'menu_order'   => array(
						'type'        => 'integer',
						'description' => __( 'A new sort position for the term within its attribute (lower sorts first). Omit to keep the current order.', 'abilities-catalog-woo' ),
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
			),
		);
	}

	/**
	 * Permission check: WooCommerce's edit capability for product terms.
	 *
	 * The wrapped `wc/v3` term PUT route runs `check_permissions( $request, 'edit' )`
	 * → `wc_rest_check_product_term_permissions( $taxonomy, 'edit', $term_id )`,
	 * which resolves the `pa_*` taxonomy's `edit_terms` meta-cap to
	 * `edit_product_terms` — the baseline every successful caller must hold. (This
	 * is the *write* cap, which differs from the read abilities' `manage_product_terms`:
	 * the read context maps to `manage_terms` while the write context maps to
	 * `edit_terms`.) This is a coarse, object-INDEPENDENT type-level guard: the
	 * per-object decision is deferred to the wrapped route, so a missing term
	 * surfaces its specific `woocommerce_rest_term_invalid` 404 and a bad
	 * attribute_id surfaces `woocommerce_rest_taxonomy_invalid` 404 via
	 * {@see RestError::from()} instead of collapsing to a generic permission denial.
	 * The explicit activity guard keeps the denial clean when WooCommerce is
	 * inactive and the capability is unmapped.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may edit product attribute terms.
	 */
	public function hasPermission( $input ): bool {
		return WooPlugin::isActive() && current_user_can( 'edit_product_terms' );
	}

	/**
	 * Executes the ability by dispatching the internal `wc/v3` REST update request.
	 *
	 * The `attribute_id` and `id` are concatenated into the route path because both
	 * are route segments, not body params. Editable fields are forwarded on key
	 * presence so an omitted field keeps its current value.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The shaped updated term row, or the REST error
	 *                                        (`woocommerce_rest_taxonomy_invalid` 404 for a bad
	 *                                        attribute_id, `woocommerce_rest_term_invalid` 404 for a
	 *                                        missing term, `term_exists` 400 for a duplicate slug).
	 */
	public function execute( $input ) {
		if ( ! WooPlugin::isActive() ) {
			return WooPlugin::unavailable();
		}

		$input        = is_array( $input ) ? $input : array();
		$attribute_id = absint( $input['attribute_id'] ?? 0 );
		$id           = absint( $input['id'] ?? 0 );

		$request = new WP_REST_Request( 'PUT', '/wc/v3/products/attributes/' . $attribute_id . '/terms/' . $id );

		foreach ( self::EDITABLE_FIELDS as $field ) {
			if ( ! array_key_exists( $field, $input ) ) {
				continue;
			}

			if ( 'menu_order' === $field ) {
				$request->set_param( $field, (int) $input[ $field ] );
			} else {
				$request->set_param( $field, (string) $input[ $field ] );
			}
		}

		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			return RestError::from( $response );
		}

		$data = rest_get_server()->response_to_data( $response, false );
		$data = is_array( $data ) ? $data : array();

		return ProductTermListShaper::termSummary( $data );
	}
}
