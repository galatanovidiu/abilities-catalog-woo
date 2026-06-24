<?php
/**
 * Base test case for the Abilities Catalog test suite.
 *
 * @package AbilitiesCatalogWoo\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogWoo\Tests;

use WP_UnitTestCase;

/**
 * Shared base class.
 *
 * The plugin self-registers its abilities during the test bootstrap (the plugin
 * file is loaded on `muplugins_loaded`, which wires the Abilities API hooks).
 * This base class only provides user/role helpers that ability tests need to
 * exercise capability-gated execution.
 */
abstract class TestCase extends WP_UnitTestCase {

	/**
	 * Creates a user with the given role and makes it the current user.
	 *
	 * @param string $role WordPress role slug. Defaults to administrator.
	 * @return int The created user ID.
	 */
	protected function actingAs(string $role = 'administrator'): int {
		$user_id = self::factory()->user->create(array('role' => $role));
		wp_set_current_user($user_id);

		return $user_id;
	}

	/**
	 * Creates an administrator, grants super admin, and makes it the current user.
	 *
	 * Multisite only — call after a markTestSkipped guard on single-site.
	 *
	 * @return int The created user ID.
	 */
	protected function actingAsSuperAdmin(): int {
		$user_id = $this->actingAs('administrator');
		grant_super_admin($user_id);

		return $user_id;
	}

	/**
	 * Enables WooCommerce coupons and registers the `shop_coupon` post type.
	 *
	 * WooCommerce registers the `shop_coupon` post type ONLY when the option
	 * `woocommerce_enable_coupons` is `'yes'` (see WC `class-wc-post-types.php`
	 * `register_post_types()`). The test bootstrap leaves that option unset, so
	 * `shop_coupon` is never registered and the wrapped `/wc/v3/coupons` route's
	 * permission check fails for everyone (its `wc_rest_check_post_permissions()`
	 * returns false when `get_post_type_object('shop_coupon')` is null). A real
	 * store with coupons enabled always has the post type, so any coupon test must
	 * call this first. The registration mirrors WooCommerce's own args so
	 * `cap->read_private_posts` resolves to `read_private_shop_coupons`.
	 *
	 * @return void
	 */
	protected function enableCoupons(): void {
		update_option('woocommerce_enable_coupons', 'yes');

		if (!post_type_exists('shop_coupon')) {
			register_post_type(
				'shop_coupon',
				array(
					'public'          => false,
					'show_ui'         => true,
					'capability_type' => 'shop_coupon',
					'map_meta_cap'    => true,
					'supports'        => array('title'),
				)
			);
		}
	}

	/**
	 * Creates a global product attribute and registers its `pa_*` taxonomy.
	 *
	 * Global attributes live in the custom `{$wpdb->prefix}woocommerce_attribute_taxonomies`
	 * table, which the WP_UnitTestCase transaction does NOT roll back, and
	 * `wc_create_attribute()` does not register the new `pa_*` taxonomy for the
	 * current request. This helper mirrors WooCommerce core's own test helper
	 * (`WC_Helper_Product::create_attribute()`): it clears the attribute caches,
	 * creates the attribute, fails loudly on a WP_Error (the per-test cleanup in
	 * tear_down() removes leaked rows so slug collisions cannot happen), then
	 * registers the `pa_*` taxonomy so `wp_insert_term()` and the wrapped REST
	 * query can see it.
	 *
	 * @param string $name The attribute name, e.g. "Color".
	 * @param string $type The attribute type. Defaults to "select".
	 * @return array{id:int,taxonomy:string} The attribute id and its `pa_*` taxonomy name.
	 */
	protected function createGlobalAttribute(string $name, string $type = 'select'): array {
		// Mirror core's helper: start from clean attribute caches.
		delete_transient('wc_attribute_taxonomies');
		if (method_exists('WC_Cache_Helper', 'invalidate_cache_group')) {
			\WC_Cache_Helper::invalidate_cache_group('woocommerce-attributes');
		}

		$slug     = wc_sanitize_taxonomy_name($name);
		$taxonomy = wc_attribute_taxonomy_name($slug);

		// Deregister any taxonomy a prior test may have left registered in-request.
		unregister_taxonomy($taxonomy);

		$attribute_id = wc_create_attribute(
			array(
				'name'         => $name,
				'slug'         => $slug,
				'type'         => $type,
				'order_by'     => 'menu_order',
				'has_archives' => 0,
			)
		);

		// Fail loudly: cleanup in tear_down() should make slug collisions impossible.
		$this->assertNotWPError($attribute_id, 'Failed to create global attribute "' . $name . '".');

		// Register the pa_* taxonomy in-request, as core's helper does.
		register_taxonomy(
			$taxonomy,
			apply_filters('woocommerce_taxonomy_objects_' . $taxonomy, array('product')),
			apply_filters(
				'woocommerce_taxonomy_args_' . $taxonomy,
				array(
					'labels'       => array('name' => $name),
					'hierarchical' => false,
					'show_ui'      => false,
					'query_var'    => true,
					'rewrite'      => false,
				)
			)
		);

		delete_transient('wc_attribute_taxonomies');
		if (method_exists('WC_Cache_Helper', 'invalidate_cache_group')) {
			\WC_Cache_Helper::invalidate_cache_group('woocommerce-attributes');
		}

		return array(
			'id'       => (int) $attribute_id,
			'taxonomy' => $taxonomy,
		);
	}

	/**
	 * Deletes every global product attribute.
	 *
	 * Global attributes are stored outside the test transaction, so rows leak
	 * across tests and runs. Calling this before each test guarantees a zero-
	 * attribute baseline, which stops "slug already exists" collisions and stale
	 * cached attribute lists. Mirrors the cleanup WooCommerce core does in its
	 * own test helper.
	 *
	 * @return void
	 */
	private function deleteAllGlobalAttributes(): void {
		foreach (wc_get_attribute_taxonomies() as $taxonomy) {
			wc_delete_attribute((int) $taxonomy->attribute_id);
			unregister_taxonomy(wc_attribute_taxonomy_name($taxonomy->attribute_name));
		}

		delete_transient('wc_attribute_taxonomies');
		if (method_exists('WC_Cache_Helper', 'invalidate_cache_group')) {
			\WC_Cache_Helper::invalidate_cache_group('woocommerce-attributes');
		}
	}

	/**
	 * Clears leaked global attributes before each test.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		$this->deleteAllGlobalAttributes();
	}

	/**
	 * Resets the current user and clears global attributes after each test.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		$this->deleteAllGlobalAttributes();
		wp_set_current_user(0);
		parent::tear_down();
	}
}
