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
 * Write ability: `wc-products/create-product-attribute`.
 *
 * Wraps `POST wc/v3/products/attributes` via `rest_do_request()`, creating a new
 * GLOBAL product attribute definition (a row in the `woocommerce_attribute_taxonomies`
 * table that also registers a `pa_<slug>` taxonomy), and returns the created
 * attribute as one flat, closed row through
 * {@see ProductTermListShaper::attributeSummary()} — id, name, slug, type,
 * order_by, has_archives. It never returns the raw `wc/v3` attribute body.
 *
 * Attributes are NOT WordPress terms here, so there is no `term_exists`
 * duplicate-slug path: a create that WooCommerce refuses surfaces the route's
 * `woocommerce_rest_cannot_create` 400 verbatim via {@see RestError::from()},
 * never collapsed into a generic permission denial. The edit is reversible with
 * `wc-products/update-product-attribute` and affects only the catalog taxonomy
 * layer — never orders, money, or stored secrets.
 *
 * Only available when WooCommerce is active (it is a {@see ConditionalAbility}).
 *
 * @since 0.1.0
 */
final class CreateProductAttribute implements ConditionalAbility {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'wc-products/create-product-attribute';
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
			'label'               => __( 'Create Product Attribute', 'abilities-catalog-woo' ),
			'description'         => __( 'Creates a new WooCommerce global product attribute (e.g. Color or Size) and returns its id, name, slug, type, order_by, and has_archives. A global attribute defines a reusable pa_<slug> taxonomy whose values are managed with wc-products/create-attribute-term. Only name is required; WooCommerce derives the slug from the name when you omit it. This is a catalog-taxonomy edit, reversible with wc-products/update-product-attribute and affecting no orders or money. Use wc-products/list-product-attributes to discover existing attributes before creating a duplicate.', 'abilities-catalog-woo' ),
			'category'            => 'wc-products',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'name' ),
				'properties'           => array(
					'name'         => array(
						'type'        => 'string',
						'description' => __( 'The attribute name shown to shoppers, e.g. "Color". Required.', 'abilities-catalog-woo' ),
					),
					'slug'         => array(
						'type'        => 'string',
						'description' => __( 'The attribute slug (the pa_<slug> taxonomy identifier). Omit to let WooCommerce derive it from the name.', 'abilities-catalog-woo' ),
					),
					'type'         => array(
						'type'        => 'string',
						'default'     => 'select',
						'description' => __( 'The attribute input type. "select" (a dropdown of terms) is the default and is always available; other types (e.g. "button", "color", "image") appear only when the store enables them. WooCommerce validates the value — an unavailable type is rejected by the wrapped route.', 'abilities-catalog-woo' ),
					),
					'order_by'     => array(
						'type'        => 'string',
						'enum'        => array( 'menu_order', 'name', 'name_num', 'id' ),
						'default'     => 'menu_order',
						'description' => __( 'How this attribute\'s terms are sorted by default: "menu_order" (the default, custom order), "name", "name_num" (name treated as a number), or "id".', 'abilities-catalog-woo' ),
					),
					'has_archives' => array(
						'type'        => 'boolean',
						'default'     => false,
						'description' => __( 'Whether the attribute gets public archive pages (one URL per term). Defaults to false.', 'abilities-catalog-woo' ),
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
	 * Permission check: WooCommerce's manager capability for product attributes.
	 *
	 * Encodes the catalog capability for `wc-products/create-product-attribute`:
	 * `manage_product_terms`, which is what
	 * `wc_rest_check_manager_permissions( 'attributes', 'create' )` resolves to on
	 * the wrapped `POST wc/v3/products/attributes` route (the helper ignores the
	 * context argument for attributes and checks `manage_product_terms`). This is
	 * coarse and object-independent; the wrapped route re-checks the same cap and
	 * surfaces its own `woocommerce_rest_cannot_create` 400 on a refused create.
	 * The explicit activity guard keeps the denial clean when WooCommerce is
	 * inactive and the capability is unmapped.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may create product attributes.
	 */
	public function hasPermission( $input ): bool {
		return WooPlugin::isActive() && current_user_can( 'manage_product_terms' );
	}

	/**
	 * Executes the ability by dispatching the internal `wc/v3` REST create request.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The shaped attribute row, or the REST error
	 *                                        (e.g. `woocommerce_rest_cannot_create` 400).
	 */
	public function execute( $input ) {
		if ( ! WooPlugin::isActive() ) {
			return WooPlugin::unavailable();
		}

		$input = is_array( $input ) ? $input : array();

		$request = new WP_REST_Request( 'POST', '/wc/v3/products/attributes' );

		$request->set_param( 'name', (string) ( $input['name'] ?? '' ) );

		// The wrapped `create_item()` feeds both params through `stripslashes()`
		// unconditionally, so a null becomes a PHP 8.1 deprecation. Always pass
		// non-null strings:
		//   - `slug`: the caller's value, or '' so `wc_create_attribute()` derives
		//     it from the name (the documented "omit to derive from name" path).
		//   - `generate_slug`: '' (never 'true'), so the route does NOT force a
		//     unique generated slug and the derive-from-name behavior is preserved.
		$slug = isset( $input['slug'] ) && '' !== $input['slug'] ? (string) $input['slug'] : '';
		$request->set_param( 'slug', $slug );
		$request->set_param( 'generate_slug', '' );

		if ( isset( $input['type'] ) && '' !== $input['type'] ) {
			$request->set_param( 'type', (string) $input['type'] );
		}
		if ( isset( $input['order_by'] ) && '' !== $input['order_by'] ) {
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

		return ProductTermListShaper::attributeSummary( is_array( $data ) ? $data : array() );
	}
}
