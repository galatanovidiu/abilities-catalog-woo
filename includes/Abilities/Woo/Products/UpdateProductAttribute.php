<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogWoo\Abilities\Woo\Products;

use GalatanOvidiu\AbilitiesCatalogWoo\Contracts\ConditionalAbility;
use GalatanOvidiu\AbilitiesCatalogWoo\Support\BooleanInput;
use GalatanOvidiu\AbilitiesCatalogWoo\Support\ProductTermListShaper;
use GalatanOvidiu\AbilitiesCatalogWoo\Support\RestError;
use GalatanOvidiu\AbilitiesCatalogWoo\Support\WooPlugin;
use WP_REST_Request;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Write ability: `wc-products/update-product-attribute`.
 *
 * Wraps `PUT wc/v3/products/attributes/<id>` via `rest_do_request()`, updating one
 * global product attribute definition (a row in the
 * `woocommerce_attribute_taxonomies` table, not a WordPress term). The `id` is a
 * route segment, so it is concatenated into the path rather than set as a query
 * param. Every editable field is optional and forwarded only when present, so an
 * omitted field keeps its current value. The one exception is `slug`: the wrapped
 * route never treats it as optional, so when the caller omits it this forwards the
 * attribute's current slug (a no-op) — see {@see UpdateProductAttribute::execute()}.
 *
 * The wrapped route's `update_item_permissions_check()` resolves the `id` to a
 * taxonomy first and returns `woocommerce_rest_taxonomy_invalid` 404 for an
 * unknown id — that check fires before the handler, so a missing attribute
 * surfaces that code (not the handler's `woocommerce_rest_attribute_invalid`) via
 * {@see RestError::from()}, never a permission collapse.
 *
 * Reversible: the edit changes only this catalog attribute definition (it does not
 * touch orders or money) and can be reverted by another update with the prior
 * values. The result is one flat, closed attribute row from
 * {@see ProductTermListShaper::attributeSummary()}; attributes carry no
 * transparency-sensitive field, so no `previous_*` is captured.
 *
 * Only available when WooCommerce is active (it is a {@see ConditionalAbility}).
 *
 * @since 0.1.0
 */
final class UpdateProductAttribute implements ConditionalAbility {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'wc-products/update-product-attribute';
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
			'label'               => __( 'Update Product Attribute', 'abilities-catalog-woo' ),
			'description'         => __( 'Updates one WooCommerce global product attribute by ID and returns the updated attribute as a flat row (id, name, slug, type, order_by, has_archives). Send only the fields you want to change; an omitted field keeps its current value. This edits the global attribute definition (e.g. Color, Size), not a product\'s per-product attribute selection. Reversible: it changes only the catalog attribute, not orders or money, and can be reverted with another update. An unknown id returns a woocommerce_rest_taxonomy_invalid 404. Discover IDs with wc-products/list-product-attributes.', 'abilities-catalog-woo' ),
			'category'            => 'wc-products',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'id' ),
				'properties'           => array(
					'id'           => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'The global attribute ID to update. Discover IDs with wc-products/list-product-attributes.', 'abilities-catalog-woo' ),
					),
					'name'         => array(
						'type'        => 'string',
						'description' => __( 'A new attribute name shown to shoppers, e.g. Color. Omit to keep the current name.', 'abilities-catalog-woo' ),
					),
					'slug'         => array(
						'type'        => 'string',
						'description' => __( 'A new attribute slug used in queries; WooCommerce normalizes it to the pa_ form (e.g. pa_color). Omit to keep the current slug.', 'abilities-catalog-woo' ),
					),
					'type'         => array(
						'type'        => 'string',
						'description' => __( 'The attribute input type. WooCommerce ships "select"; other plugins may register more types. Omit to keep the current type.', 'abilities-catalog-woo' ),
					),
					'order_by'     => array(
						'type'        => 'string',
						'enum'        => array( 'menu_order', 'name', 'name_num', 'id' ),
						'description' => __( 'How this attribute\'s terms are sorted: "menu_order" (custom order), "name", "name_num" (name treated numerically), or "id". Omit to keep the current order.', 'abilities-catalog-woo' ),
					),
					'has_archives' => array(
						'type'        => 'boolean',
						'description' => __( 'Whether this attribute has public archive pages. Omit to keep the current setting.', 'abilities-catalog-woo' ),
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => ProductTermListShaper::attributeItemSchema(),
			'execute_callback'    => array( $this, 'execute' ),
			'permission_callback' => array( $this, 'hasPermission' ),
			'meta'                => array(
				'annotations'  => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => false,
				),
				'show_in_rest' => true,
				'screen'       => 'edit.php?post_type=product&page=product_attributes&edit={id}',
			),
		);
	}

	/**
	 * Permission check: WooCommerce's manage capability for product attributes.
	 *
	 * Mirrors the wrapped `wc/v3` route's `wc_rest_check_manager_permissions(
	 * 'attributes', 'edit' )`, which resolves to the `manage_product_terms`
	 * capability (the helper ignores the create/edit context for attributes). This
	 * is a coarse, object-INDEPENDENT type-level guard: the per-object decision is
	 * deferred to the wrapped route, so a missing attribute surfaces its specific
	 * `woocommerce_rest_taxonomy_invalid` 404 via {@see RestError::from()} instead
	 * of collapsing to a generic permission denial. The explicit activity guard
	 * keeps the denial clean when WooCommerce is inactive and the capability is
	 * unmapped.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may manage product attributes.
	 */
	public function hasPermission( $input ): bool {
		return WooPlugin::isActive() && current_user_can( 'manage_product_terms' );
	}

	/**
	 * Executes the ability by dispatching the internal `wc/v3` REST update request.
	 *
	 * The `id` is a route segment, so it is concatenated into the path. Each
	 * editable field is forwarded only when present (key presence), so an omitted
	 * field keeps its current value.
	 *
	 * The `slug` field is the exception: the wrapped V1 `update_item()` always feeds
	 * `wc_sanitize_taxonomy_name( stripslashes( $request['slug'] ) )` into
	 * `wc_update_attribute()`, so a missing `slug` becomes `''` — which (being
	 * non-null) defeats that function's `?? $attribute->slug` fallback and silently
	 * regenerates the slug from the name, besides raising a PHP 8.1 `stripslashes(
	 * null )` deprecation. To keep an omitted `slug` a true no-op, this pre-reads the
	 * attribute and forwards its current `pa_`-prefixed slug, which round-trips to
	 * the stored value unchanged (`wc_create_attribute()` strips the `pa_` prefix).
	 * The pre-read GET also makes a missing id surface its 404 here.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The shaped attribute record, or the REST error
	 *                                        (e.g. `woocommerce_rest_taxonomy_invalid` 404).
	 */
	public function execute( $input ) {
		if ( ! WooPlugin::isActive() ) {
			return WooPlugin::unavailable();
		}

		$input = is_array( $input ) ? $input : array();
		$id    = absint( $input['id'] ?? 0 );

		$request = new WP_REST_Request( 'PUT', '/wc/v3/products/attributes/' . $id );

		if ( array_key_exists( 'name', $input ) ) {
			$request->set_param( 'name', (string) $input['name'] );
		}
		if ( array_key_exists( 'slug', $input ) ) {
			$request->set_param( 'slug', (string) $input['slug'] );
		} else {
			// The caller omitted `slug`, but the wrapped route never treats it as
			// optional. Forward the current slug so the update leaves it unchanged.
			$current = rest_do_request( new WP_REST_Request( 'GET', '/wc/v3/products/attributes/' . $id ) );
			if ( $current->is_error() ) {
				return RestError::from( $current );
			}

			$current_data = rest_get_server()->response_to_data( $current, false );
			$request->set_param( 'slug', (string) ( is_array( $current_data ) ? ( $current_data['slug'] ?? '' ) : '' ) );
		}
		if ( array_key_exists( 'type', $input ) ) {
			$request->set_param( 'type', (string) $input['type'] );
		}
		if ( array_key_exists( 'order_by', $input ) ) {
			$request->set_param( 'order_by', (string) $input['order_by'] );
		}
		if ( array_key_exists( 'has_archives', $input ) ) {
			$request->set_param( 'has_archives', BooleanInput::sanitize( $input['has_archives'] ) );
		}

		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			return RestError::from( $response );
		}

		$data = rest_get_server()->response_to_data( $response, false );
		$data = is_array( $data ) ? $data : array();

		return ProductTermListShaper::attributeSummary( $data );
	}
}
