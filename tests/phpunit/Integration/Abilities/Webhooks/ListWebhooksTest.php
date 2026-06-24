<?php
/**
 * Integration tests for the `wc-webhooks/list-webhooks` ability.
 *
 * @package AbilitiesCatalogWoo\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogWoo\Tests\Integration\Abilities\Webhooks;

use GalatanOvidiu\AbilitiesCatalogWoo\Tests\TestCase;
use WC_Webhook;
use WP_Error;

/**
 * @covers \GalatanOvidiu\AbilitiesCatalogWoo\Abilities\Woo\Webhooks\ListWebhooks
 */
final class ListWebhooksTest extends TestCase {

	private const ABILITY = 'wc-webhooks/list-webhooks';

	/**
	 * The exact keys a shaped webhook summary row exposes.
	 *
	 * Asserting against this fixed set proves the raw webhook body — and in
	 * particular the signing `secret` — is never leaked: only these projected
	 * fields reach the consumer.
	 *
	 * @var list<string>
	 */
	private const ROW_KEYS = array(
		'id',
		'name',
		'status',
		'topic',
		'delivery_url',
		'date_created',
	);

	public function test_registered(): void {
		$ability = wp_get_ability( self::ABILITY );

		$this->assertNotNull( $ability );
		$this->assertSame( self::ABILITY, $ability->get_name() );
	}

	public function test_happy_path_returns_shaped_rows(): void {
		$this->seedWebhook( 'List Test', 'order.created', 'active' );
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( self::ABILITY )->execute( array() );

		$this->assertIsArray( $result );
		$this->assertSame( array( 'items', 'total' ), array_keys( $result ) );
		$this->assertIsArray( $result['items'] );
		$this->assertIsInt( $result['total'] );
		$this->assertGreaterThanOrEqual( 1, $result['total'] );
		$this->assertNotEmpty( $result['items'] );

		$row = $result['items'][0];
		$this->assertSame( self::ROW_KEYS, array_keys( $row ) );
		$this->assertIsInt( $row['id'] );
		$this->assertIsString( $row['name'] );
		$this->assertIsString( $row['status'] );
		$this->assertIsString( $row['topic'] );
		$this->assertIsString( $row['delivery_url'] );
		$this->assertIsString( $row['date_created'] );
	}

	public function test_status_filter_narrows_results(): void {
		$active = $this->seedWebhook( 'Active Hook', 'order.created', 'active' );
		$paused = $this->seedWebhook( 'Paused Hook', 'order.updated', 'paused' );
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( self::ABILITY )->execute( array( 'status' => 'paused' ) );

		$ids = wp_list_pluck( $result['items'], 'id' );
		$this->assertContains( $paused, $ids );
		$this->assertNotContains( $active, $ids );

		foreach ( $result['items'] as $row ) {
			$this->assertSame( 'paused', $row['status'] );
		}
	}

	public function test_output_does_not_leak_the_webhook_secret(): void {
		$this->seedWebhook( 'Secret Hook', 'order.created', 'active' );
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( self::ABILITY )->execute( array() );

		$this->assertNotEmpty( $result['items'] );
		foreach ( $result['items'] as $row ) {
			$this->assertSame( self::ROW_KEYS, array_keys( $row ) );
			$this->assertArrayNotHasKey( 'secret', $row );
			$this->assertArrayNotHasKey( '_links', $row );
			$this->assertArrayNotHasKey( 'date_created_gmt', $row );
		}

		$serialized = (string) wp_json_encode( $result );
		$this->assertStringNotContainsString( 'shhh-super-secret', $serialized );
		$this->assertStringNotContainsString( '"secret"', $serialized );
	}

	public function test_wrong_capability_is_denied(): void {
		$this->seedWebhook( 'Denied Hook', 'order.created', 'active' );
		$this->actingAs( 'subscriber' );

		$ability = wp_get_ability( self::ABILITY );

		$this->assertFalse( $ability->check_permissions( array() ) );

		$result = $ability->execute( array() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_logged_out_user_is_denied(): void {
		$this->seedWebhook( 'Logged Out Hook', 'order.created', 'active' );
		wp_set_current_user( 0 );

		$result = wp_get_ability( self::ABILITY )->execute( array() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	/**
	 * Seeds a WooCommerce webhook with a known secret and returns its ID.
	 *
	 * Uses the WooCommerce runtime data layer (NOT the deferred create-webhook
	 * ability) so the redaction assertion is real: the seeded secret is a fixed,
	 * searchable string the output must never contain.
	 *
	 * @param string $name   The webhook name.
	 * @param string $topic  The webhook topic, e.g. order.created.
	 * @param string $status The webhook status: active, paused, or disabled.
	 * @return int The new webhook ID.
	 */
	private function seedWebhook( string $name, string $topic, string $status ): int {
		$webhook = new WC_Webhook();
		$webhook->set_name( $name );
		$webhook->set_topic( $topic );
		$webhook->set_delivery_url( 'https://example.com/hook' );
		$webhook->set_secret( 'shhh-super-secret' );
		$webhook->set_status( $status );

		return (int) $webhook->save();
	}
}
