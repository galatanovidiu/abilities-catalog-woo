<?php
/**
 * Integration tests for the wc-webhooks/get-webhook ability.
 *
 * @package AbilitiesCatalogWoo\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogWoo\Tests\Integration\Abilities\Webhooks;

use GalatanOvidiu\AbilitiesCatalogWoo\Tests\TestCase;
use WC_Webhook;
use WP_Error;

/**
 * Exercises wc-webhooks/get-webhook: the shaped detail row on a seeded webhook,
 * the webhook-secret redaction (no `secret` key, the raw secret never appears,
 * and `has_secret` is true), the missing-webhook 404 that must not collapse to a
 * permission error, the wrong-capability denial, and the exact closed output
 * shape.
 *
 * The webhook is seeded through WooCommerce's runtime object API with a KNOWN
 * secret, so the redaction assertion is real: the raw key is stored, and the
 * ability output must still never carry it.
 */
final class GetWebhookTest extends TestCase {

	/**
	 * The full closed key set the ability returns for one webhook.
	 *
	 * @var list<string>
	 */
	private const EXPECTED_KEYS = array(
		'id',
		'name',
		'status',
		'topic',
		'delivery_url',
		'date_created',
		'resource',
		'event',
		'hooks',
		'failure_count',
		'date_modified',
		'has_secret',
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
		$webhook->set_name( 'Test' );
		$webhook->set_topic( 'order.created' );
		$webhook->set_delivery_url( 'https://example.com/hook' );
		$webhook->set_secret( self::SECRET_VALUE );
		$webhook->set_status( 'active' );

		return (int) $webhook->save();
	}

	public function test_ability_is_registered(): void {
		$ability = wp_get_ability( 'wc-webhooks/get-webhook' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'wc-webhooks/get-webhook', $ability->get_name() );
	}

	public function test_admin_reads_seeded_webhook(): void {
		$this->actingAs( 'administrator' );
		$id = $this->seedWebhook();

		$result = wp_get_ability( 'wc-webhooks/get-webhook' )->execute( array( 'id' => $id ) );

		$this->assertIsArray( $result );
		$this->assertSame( $id, $result['id'] );
		$this->assertSame( 'Test', $result['name'] );
		$this->assertSame( 'active', $result['status'] );
		$this->assertSame( 'order.created', $result['topic'] );
		$this->assertSame( 'order', $result['resource'] );
		$this->assertSame( 'created', $result['event'] );
		$this->assertSame( 'https://example.com/hook', $result['delivery_url'] );
		$this->assertIsArray( $result['hooks'] );
		$this->assertIsInt( $result['failure_count'] );
	}

	public function test_secret_is_redacted_and_has_secret_is_true(): void {
		$this->actingAs( 'administrator' );
		$id = $this->seedWebhook();

		$result = wp_get_ability( 'wc-webhooks/get-webhook' )->execute( array( 'id' => $id ) );

		$this->assertIsArray( $result );

		// The raw signing key is never carried under any key.
		$this->assertArrayNotHasKey( 'secret', $result );

		// The known secret never appears anywhere in the serialized output.
		$this->assertStringNotContainsString( self::SECRET_VALUE, (string) wp_json_encode( $result ) );

		// The configured-signing signal is present and true.
		$this->assertArrayHasKey( 'has_secret', $result );
		$this->assertTrue( $result['has_secret'] );
	}

	public function test_output_shape_is_exact_and_closed(): void {
		$this->actingAs( 'administrator' );
		$id = $this->seedWebhook();

		$result = wp_get_ability( 'wc-webhooks/get-webhook' )->execute( array( 'id' => $id ) );

		$this->assertIsArray( $result );
		$this->assertSame( self::EXPECTED_KEYS, array_keys( $result ) );

		// No raw REST body fields and no GMT-date noise leak through.
		$this->assertArrayNotHasKey( '_links', $result );
		$this->assertArrayNotHasKey( 'secret', $result );
		$this->assertArrayNotHasKey( 'date_created_gmt', $result );
		$this->assertArrayNotHasKey( 'date_modified_gmt', $result );

		$this->assertIsInt( $result['id'] );
		$this->assertIsString( $result['name'] );
		$this->assertIsString( $result['status'] );
		$this->assertIsString( $result['topic'] );
		$this->assertIsString( $result['delivery_url'] );
		$this->assertIsString( $result['date_created'] );
		$this->assertIsString( $result['resource'] );
		$this->assertIsString( $result['event'] );
		$this->assertIsArray( $result['hooks'] );
		$this->assertIsInt( $result['failure_count'] );
		$this->assertIsString( $result['date_modified'] );
		$this->assertIsBool( $result['has_secret'] );
	}

	public function test_missing_webhook_returns_404_not_permission_error(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'wc-webhooks/get-webhook' )->execute( array( 'id' => 99999999 ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'woocommerce_rest_shop_webhook_invalid_id', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] );
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_subscriber_is_denied(): void {
		$this->actingAs( 'subscriber' );
		$id = $this->seedWebhook();

		$ability = wp_get_ability( 'wc-webhooks/get-webhook' );

		$this->assertFalse( $ability->check_permissions( array( 'id' => $id ) ) );

		$result = $ability->execute( array( 'id' => $id ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}
}
