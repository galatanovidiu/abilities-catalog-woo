<?php
/**
 * Integration tests for the og-wc-webhooks/delete-webhook ability.
 *
 * @package AbilitiesCatalogWoo\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogWoo\Tests\Integration\Abilities\Webhooks;

use GalatanOvidiu\AbilitiesCatalogWoo\Tests\TestCase;
use WC_Webhook;
use WP_Error;

/**
 * Exercises og-wc-webhooks/delete-webhook: the permanent force delete of a seeded
 * webhook with a follow-up assertion that the webhook is actually GONE, the
 * missing-webhook 404 that must not collapse to a permission error, the
 * wrong-capability denial, and the exact closed output shape (no edit_link, no
 * secret leak).
 *
 * The webhook is seeded through WooCommerce's runtime object API.
 */
final class DeleteWebhookTest extends TestCase {

	/**
	 * The full closed key set the ability returns on a delete.
	 *
	 * @var list<string>
	 */
	private const EXPECTED_KEYS = array(
		'deleted',
		'id',
		'name',
		'permanent',
	);

	/**
	 * The known signing secret stored on the seeded webhook.
	 *
	 * @var string
	 */
	private const SECRET_VALUE = 'shhh-super-secret';

	/**
	 * Seeds one active webhook with a known secret.
	 *
	 * @return int The seeded webhook ID.
	 */
	private function seedWebhook(): int {
		$webhook = new WC_Webhook();
		$webhook->set_name( 'Order Hook' );
		$webhook->set_topic( 'order.created' );
		$webhook->set_delivery_url( 'https://example.com/hook' );
		$webhook->set_secret( self::SECRET_VALUE );
		$webhook->set_status( 'active' );

		return (int) $webhook->save();
	}

	public function test_ability_is_registered(): void {
		$ability = wp_get_ability( 'og-wc-webhooks/delete-webhook' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'og-wc-webhooks/delete-webhook', $ability->get_name() );
	}

	public function test_admin_deletes_webhook_and_it_is_gone(): void {
		$this->actingAs( 'administrator' );
		$id = $this->seedWebhook();

		$result = wp_get_ability( 'og-wc-webhooks/delete-webhook' )->execute( array( 'id' => $id ) );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['deleted'] );
		$this->assertSame( $id, $result['id'] );
		$this->assertSame( 'Order Hook', $result['name'] );
		$this->assertTrue( $result['permanent'] );

		// The webhook is actually gone — never trust the deleted flag alone.
		$this->assertNull( wc_get_webhook( $id ) );
	}

	public function test_output_shape_is_exact_and_closed(): void {
		$this->actingAs( 'administrator' );
		$id = $this->seedWebhook();

		$result = wp_get_ability( 'og-wc-webhooks/delete-webhook' )->execute( array( 'id' => $id ) );

		$this->assertIsArray( $result );
		$this->assertSame( self::EXPECTED_KEYS, array_keys( $result ) );

		// A delete returns no dead-end edit link and never leaks the signing secret.
		$this->assertArrayNotHasKey( 'edit_link', $result );
		$this->assertArrayNotHasKey( 'secret', $result );
		$this->assertStringNotContainsString( self::SECRET_VALUE, (string) wp_json_encode( $result ) );

		$this->assertIsBool( $result['deleted'] );
		$this->assertIsInt( $result['id'] );
		$this->assertIsString( $result['name'] );
		$this->assertIsBool( $result['permanent'] );
	}

	public function test_missing_webhook_returns_404_not_permission_error(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'og-wc-webhooks/delete-webhook' )->execute( array( 'id' => 99999999 ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'woocommerce_rest_shop_webhook_invalid_id', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] );
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_missing_required_id_is_rejected(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'og-wc-webhooks/delete-webhook' )->execute( array() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_input', $result->get_error_code() );
	}

	public function test_subscriber_is_denied_and_webhook_survives(): void {
		$this->actingAs( 'subscriber' );
		$id = $this->seedWebhook();

		$ability = wp_get_ability( 'og-wc-webhooks/delete-webhook' );

		$this->assertFalse( $ability->check_permissions( array( 'id' => $id ) ) );

		$result = $ability->execute( array( 'id' => $id ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );

		// The denied write left the webhook untouched.
		$this->assertNotNull( wc_get_webhook( $id ) );
	}
}
