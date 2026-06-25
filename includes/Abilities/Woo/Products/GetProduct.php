<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogWoo\Abilities\Woo\Products;

use GalatanOvidiu\AbilitiesCatalogWoo\Contracts\ConditionalAbility;
use GalatanOvidiu\AbilitiesCatalogWoo\Support\ProductListShaper;
use GalatanOvidiu\AbilitiesCatalogWoo\Support\RestError;
use GalatanOvidiu\AbilitiesCatalogWoo\Support\WooPlugin;
use WP_REST_Request;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read ability: `og-wc-products/get-product`.
 *
 * Wraps `GET wc/v3/products/<id>` via `rest_do_request()` and returns one
 * product as a flat, closed record: the {@see ProductListShaper::summary()}
 * fields (name, type, status, sku, the price set, stock, visibility, permalink,
 * date) plus the detail a single-product view needs — the long and short
 * descriptions, the assigned categories and tags, the product images, the
 * attribute selections, and an `edit_link`. The raw `wc/v3` body carries dozens
 * of fields a consumer never reads; this projects only the useful subset.
 *
 * Only available when WooCommerce is active (it is a {@see ConditionalAbility}).
 *
 * @since 0.1.0
 */
final class GetProduct implements ConditionalAbility {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-wc-products/get-product';
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
			'label'               => __( 'Get Product', 'abilities-catalog-woo' ),
			'description'         => __( 'Returns one WooCommerce product by ID: its name, type, status, sku, prices, stock, visibility, permalink, the full and short descriptions, its categories and tags, images, attribute selections, and an edit_link. Use og-wc-products/list-products to scan products and discover IDs; use this for one product\'s full detail. Read-only: does not return variations (use og-wc-products/list-product-variations for a variable product\'s variations).', 'abilities-catalog-woo' ),
			'category'            => 'og-wc-products',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'id' ),
				'properties'           => array(
					'id' => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'The product ID. Discover IDs with og-wc-products/list-products.', 'abilities-catalog-woo' ),
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => $this->outputSchema(),
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
	 * Permission check: WooCommerce's read capability for products.
	 *
	 * Mirrors `wc_rest_check_post_permissions( 'product', 'read' )`, which maps
	 * the product post type's `read_private_posts` meta-cap to
	 * `read_private_products` — the baseline the wrapped `wc/v3` GET route
	 * enforces. This is a coarse, object-INDEPENDENT type-level guard: the
	 * per-object decision is deferred to the wrapped route, so a missing product
	 * surfaces its specific `woocommerce_rest_product_invalid_id` 404 via
	 * {@see RestError::from()} instead of collapsing to a generic permission
	 * denial. The explicit activity guard keeps the denial clean when WooCommerce
	 * is inactive.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may read products.
	 */
	public function hasPermission( $input ): bool {
		return WooPlugin::isActive() && current_user_can( 'read_private_products' );
	}

	/**
	 * Executes the ability by dispatching the internal `wc/v3` REST request.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The shaped product record, or the REST error
	 *                                        (e.g. `woocommerce_rest_product_invalid_id` 404).
	 */
	public function execute( $input ) {
		if ( ! WooPlugin::isActive() ) {
			return WooPlugin::unavailable();
		}

		$input = is_array( $input ) ? $input : array();
		$id    = absint( $input['id'] ?? 0 );

		$request  = new WP_REST_Request( 'GET', '/wc/v3/products/' . $id );
		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			return RestError::from( $response );
		}

		$data = rest_get_server()->response_to_data( $response, false );
		$data = is_array( $data ) ? $data : array();

		$summary = ProductListShaper::summary( $data );

		$summary['description']       = (string) ( $data['description'] ?? '' );
		$summary['short_description'] = (string) ( $data['short_description'] ?? '' );
		$summary['categories']        = $this->terms( $data['categories'] ?? array() );
		$summary['tags']              = $this->terms( $data['tags'] ?? array() );
		$summary['images']            = $this->images( $data['images'] ?? array() );
		$summary['attributes']        = $this->attributes( $data['attributes'] ?? array() );

		return $summary;
	}

	/**
	 * Projects a raw `categories`/`tags` array into flat `{ id, name, slug }` rows.
	 *
	 * @param mixed $raw The raw term list from the product body.
	 * @return list<array{id:int,name:string,slug:string}> The flat term rows.
	 */
	private function terms( $raw ): array {
		$rows = array();
		foreach ( (array) $raw as $term ) {
			$term   = (array) $term;
			$rows[] = array(
				'id'   => (int) ( $term['id'] ?? 0 ),
				'name' => (string) ( $term['name'] ?? '' ),
				'slug' => (string) ( $term['slug'] ?? '' ),
			);
		}

		return $rows;
	}

	/**
	 * Projects the raw `images` array into flat `{ id, src, alt }` rows.
	 *
	 * @param mixed $raw The raw image list from the product body.
	 * @return list<array{id:int,src:string,alt:string}> The flat image rows.
	 */
	private function images( $raw ): array {
		$rows = array();
		foreach ( (array) $raw as $image ) {
			$image  = (array) $image;
			$rows[] = array(
				'id'  => (int) ( $image['id'] ?? 0 ),
				'src' => (string) ( $image['src'] ?? '' ),
				'alt' => (string) ( $image['alt'] ?? '' ),
			);
		}

		return $rows;
	}

	/**
	 * Projects the raw `attributes` array into flat `{ id, name, options }` rows.
	 *
	 * @param mixed $raw The raw attribute list from the product body.
	 * @return list<array{id:int,name:string,options:list<string>}> The flat attribute rows.
	 */
	private function attributes( $raw ): array {
		$rows = array();
		foreach ( (array) $raw as $attribute ) {
			$attribute = (array) $attribute;

			$options = array();
			foreach ( (array) ( $attribute['options'] ?? array() ) as $option ) {
				$options[] = (string) $option;
			}

			$rows[] = array(
				'id'      => (int) ( $attribute['id'] ?? 0 ),
				'name'    => (string) ( $attribute['name'] ?? '' ),
				'options' => $options,
			);
		}

		return $rows;
	}

	/**
	 * Builds the closed output schema: the product summary fields plus the
	 * single-product detail fields.
	 *
	 * @return array<string,mixed> A JSON-Schema object fragment.
	 */
	private function outputSchema(): array {
		$summary = ProductListShaper::itemSchema();

		$summary['required']                        = array( 'id' );
		$summary['properties']['description']       = array(
			'type'        => 'string',
			'description' => __( 'The full product description as HTML.', 'abilities-catalog-woo' ),
		);
		$summary['properties']['short_description'] = array(
			'type'        => 'string',
			'description' => __( 'The short product description (excerpt) as HTML.', 'abilities-catalog-woo' ),
		);
		$summary['properties']['categories']        = array(
			'type'        => 'array',
			'description' => __( 'The product categories as flat term rows.', 'abilities-catalog-woo' ),
			'items'       => $this->termItemSchema( __( 'The category term ID.', 'abilities-catalog-woo' ), __( 'The category name.', 'abilities-catalog-woo' ), __( 'The category slug.', 'abilities-catalog-woo' ) ),
		);
		$summary['properties']['tags']              = array(
			'type'        => 'array',
			'description' => __( 'The product tags as flat term rows.', 'abilities-catalog-woo' ),
			'items'       => $this->termItemSchema( __( 'The tag term ID.', 'abilities-catalog-woo' ), __( 'The tag name.', 'abilities-catalog-woo' ), __( 'The tag slug.', 'abilities-catalog-woo' ) ),
		);
		$summary['properties']['images']            = array(
			'type'        => 'array',
			'description' => __( 'The product images. The first image is the featured image.', 'abilities-catalog-woo' ),
			'items'       => array(
				'type'                 => 'object',
				'properties'           => array(
					'id'  => array(
						'type'        => 'integer',
						'description' => __( 'The attachment ID of the image.', 'abilities-catalog-woo' ),
					),
					'src' => array(
						'type'        => 'string',
						'description' => __( 'The public image URL.', 'abilities-catalog-woo' ),
					),
					'alt' => array(
						'type'        => 'string',
						'description' => __( 'The image alt text, or an empty string when none is set.', 'abilities-catalog-woo' ),
					),
				),
				'additionalProperties' => false,
			),
		);
		$summary['properties']['attributes']        = array(
			'type'        => 'array',
			'description' => __( 'The product attributes, each with its available options.', 'abilities-catalog-woo' ),
			'items'       => array(
				'type'                 => 'object',
				'properties'           => array(
					'id'      => array(
						'type'        => 'integer',
						'description' => __( 'The global attribute taxonomy ID, or 0 for a custom (product-local) attribute.', 'abilities-catalog-woo' ),
					),
					'name'    => array(
						'type'        => 'string',
						'description' => __( 'The attribute name, e.g. Color.', 'abilities-catalog-woo' ),
					),
					'options' => array(
						'type'        => 'array',
						'description' => __( 'The available option term names for this attribute, e.g. Red, Blue.', 'abilities-catalog-woo' ),
						'items'       => array(
							'type' => 'string',
						),
					),
				),
				'additionalProperties' => false,
			),
		);

		return $summary;
	}

	/**
	 * The closed item schema for a `{ id, name, slug }` term row.
	 *
	 * @param string $id_description   Description for the `id` field.
	 * @param string $name_description Description for the `name` field.
	 * @param string $slug_description Description for the `slug` field.
	 * @return array<string,mixed> A JSON-Schema object fragment.
	 */
	private function termItemSchema( string $id_description, string $name_description, string $slug_description ): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'id'   => array(
					'type'        => 'integer',
					'description' => $id_description,
				),
				'name' => array(
					'type'        => 'string',
					'description' => $name_description,
				),
				'slug' => array(
					'type'        => 'string',
					'description' => $slug_description,
				),
			),
			'additionalProperties' => false,
		);
	}
}
