<?php
/**
 * Integration tests for the `wc-customers/list-customer-downloads` ability.
 *
 * @package AbilitiesCatalogWoo\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogWoo\Tests\Integration\Abilities\Customers;

use Automattic\WooCommerce\Internal\ProductDownloads\ApprovedDirectories\Register;
use GalatanOvidiu\AbilitiesCatalogWoo\Tests\TestCase;
use WC_Order;
use WC_Product_Download;
use WC_Product_Simple;
use WP_Error;

/**
 * @covers \GalatanOvidiu\AbilitiesCatalogWoo\Abilities\Woo\Customers\ListCustomerDownloads
 */
final class ListCustomerDownloadsTest extends TestCase {

	private const ABILITY = 'wc-customers/list-customer-downloads';

	/**
	 * The exact keys a shaped download summary row exposes.
	 *
	 * Asserting against this fixed set proves the raw row — which carries
	 * `download_url`, `order_key`, `email` (in the URL), `access_expires_gmt`, and a
	 * `file` block — never leaks through. The redaction is load-bearing.
	 *
	 * @var list<string>
	 */
	private const ROW_KEYS = array(
		'download_id',
		'product_id',
		'product_name',
		'download_name',
		'order_id',
		'downloads_remaining',
		'access_expires',
	);

	public function test_registered(): void {
		$ability = wp_get_ability( self::ABILITY );

		$this->assertNotNull( $ability );
		$this->assertSame( self::ABILITY, $ability->get_name() );
	}

	public function test_happy_path_returns_shaped_rows(): void {
		$this->actingAs( 'administrator' );
		$customer_id = $this->seedCustomer();
		$this->seedDownloadForCustomer( $customer_id );

		$result = wp_get_ability( self::ABILITY )->execute( array( 'customer_id' => $customer_id ) );

		$this->assertIsArray( $result );
		$this->assertSame( array( 'customer_id', 'items', 'total' ), array_keys( $result ) );
		$this->assertSame( $customer_id, $result['customer_id'] );
		$this->assertIsInt( $result['customer_id'] );
		$this->assertIsArray( $result['items'] );
		$this->assertIsInt( $result['total'] );
		$this->assertGreaterThanOrEqual( 1, $result['total'] );
		$this->assertNotEmpty( $result['items'] );

		$row = $result['items'][0];
		$this->assertSame( self::ROW_KEYS, array_keys( $row ) );
		$this->assertIsString( $row['download_id'] );
		$this->assertIsInt( $row['product_id'] );
		$this->assertIsString( $row['product_name'] );
		$this->assertIsString( $row['download_name'] );
		$this->assertIsInt( $row['order_id'] );
		$this->assertIsString( $row['downloads_remaining'] );
		$this->assertIsString( $row['access_expires'] );

		$this->assertSame( 'Test File', $row['download_name'] );
	}

	public function test_output_shape_redacts_sensitive_fields(): void {
		$this->actingAs( 'administrator' );
		$customer_id = $this->seedCustomer();
		$this->seedDownloadForCustomer( $customer_id );

		$result = wp_get_ability( self::ABILITY )->execute( array( 'customer_id' => $customer_id ) );

		$this->assertSame( array( 'customer_id', 'items', 'total' ), array_keys( $result ) );
		$row = $result['items'][0];
		$this->assertSame( self::ROW_KEYS, array_keys( $row ) );

		// The redaction is load-bearing: the download URL is an unauthenticated
		// bearer link that embeds the order key and customer email.
		$this->assertArrayNotHasKey( 'download_url', $row );
		$this->assertArrayNotHasKey( 'order_key', $row );
		$this->assertArrayNotHasKey( 'email', $row );
		$this->assertArrayNotHasKey( 'access_expires_gmt', $row );
		$this->assertArrayNotHasKey( 'file', $row );
	}

	public function test_missing_customer_returns_404_not_permission_collapse(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( self::ABILITY )->execute( array( 'customer_id' => 99999999 ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wc_user_invalid_id', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] );
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_missing_required_customer_id_is_rejected(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( self::ABILITY )->execute( array() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_input', $result->get_error_code() );
	}

	public function test_wrong_capability_is_denied(): void {
		$customer_id = $this->seedCustomer();
		$this->seedDownloadForCustomer( $customer_id );
		// A subscriber lacks list_users.
		$this->actingAs( 'subscriber' );

		$result = wp_get_ability( self::ABILITY )->execute( array( 'customer_id' => $customer_id ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_logged_out_user_is_denied(): void {
		$customer_id = $this->seedCustomer();
		$this->seedDownloadForCustomer( $customer_id );
		wp_set_current_user( 0 );

		$result = wp_get_ability( self::ABILITY )->execute( array( 'customer_id' => $customer_id ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	/**
	 * Seeds a customer via the core WP factory.
	 *
	 * The distributed `woocommerce.zip` ships no `tests/` framework, so the
	 * `WC_Helper_Customer` factory does not exist; the core WP user factory does and
	 * a `customer`-role user is a WooCommerce customer.
	 *
	 * @return int The created customer (user) ID.
	 */
	private function seedCustomer(): int {
		return (int) self::factory()->user->create(
			array(
				'role'       => 'customer',
				'user_email' => 'buyer@example.org',
				'first_name' => 'Ada',
				'last_name'  => 'Lovelace',
			)
		);
	}

	/**
	 * Seeds a downloadable product, a completed order for the customer, and grants
	 * the download permission rows the route reads.
	 *
	 * All runtime objects/functions (the distributed zip has no `WC_Helper_*`).
	 * `wc_downloadable_product_permissions( $order_id, true )` grants the permission
	 * rows; `force = true` runs it even if already processed.
	 *
	 * WooCommerce's Approved Download Directories feature rejects a product save
	 * whose downloadable file URL is not under an approved directory. The runtime
	 * `WC_Product_Download::approved_directory_checks()` skips that validation
	 * entirely unless the feature mode is `enabled`, so this forces the mode to
	 * `disabled` on the shared `Register` service before saving (verified in WC
	 * `includes/class-wc-product-download.php`, which early-returns when
	 * `get_mode() !== MODE_ENABLED`).
	 *
	 * @param int $customer_id The customer (user) ID to grant the download to.
	 * @return int The created order ID.
	 */
	private function seedDownloadForCustomer( int $customer_id ): int {
		wc_get_container()->get( Register::class )->set_mode( Register::MODE_DISABLED );

		$file = new WC_Product_Download();
		$file->set_id( 'file-1' );
		$file->set_name( 'Test File' );
		$file->set_file( 'https://example.org/file.pdf' );

		$product = new WC_Product_Simple();
		$product->set_name( 'Downloadable' );
		$product->set_downloadable( true );
		$product->set_regular_price( '10.00' );
		$product->set_downloads( array( $file ) );
		$product->save();

		$order = new WC_Order();
		$order->set_customer_id( $customer_id );
		$order->add_product( wc_get_product( $product->get_id() ), 1 );
		$order->set_status( 'completed' );
		$order->calculate_totals();
		$order_id = (int) $order->save();

		wc_downloadable_product_permissions( $order_id, true );

		return $order_id;
	}
}
