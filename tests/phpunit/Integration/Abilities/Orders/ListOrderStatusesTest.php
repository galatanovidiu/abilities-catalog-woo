<?php
/**
 * Integration tests for the `og-wc-orders/list-order-statuses` ability.
 *
 * @package AbilitiesCatalogWoo\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogWoo\Tests\Integration\Abilities\Orders;

use GalatanOvidiu\AbilitiesCatalogWoo\Tests\TestCase;
use WP_Error;

/**
 * @covers \GalatanOvidiu\AbilitiesCatalogWoo\Abilities\Woo\Orders\ListOrderStatuses
 */
final class ListOrderStatusesTest extends TestCase {

	private const ABILITY = 'og-wc-orders/list-order-statuses';

	/**
	 * The seven order statuses WooCommerce ships with, slugs with the wc- prefix stripped.
	 */
	private const CORE_STATUS_SLUGS = array(
		'pending',
		'processing',
		'on-hold',
		'completed',
		'cancelled',
		'refunded',
		'failed',
	);

	public function test_registered(): void {
		$ability = wp_get_ability( self::ABILITY );

		$this->assertNotNull( $ability );
		$this->assertSame( self::ABILITY, $ability->get_name() );
	}

	public function test_happy_path_returns_core_statuses(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( self::ABILITY )->execute( array() );

		$this->assertIsArray( $result );
		$this->assertIsArray( $result['statuses'] );
		$this->assertIsInt( $result['total'] );
		$this->assertSame( count( $result['statuses'] ), $result['total'] );

		$slugs = array_column( $result['statuses'], 'slug' );
		foreach ( self::CORE_STATUS_SLUGS as $expected ) {
			$this->assertContains( $expected, $slugs );
		}
	}

	public function test_each_row_is_exactly_slug_and_name(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( self::ABILITY )->execute( array() );

		$this->assertNotEmpty( $result['statuses'] );
		foreach ( $result['statuses'] as $row ) {
			$this->assertSame( array( 'slug', 'name' ), array_keys( $row ) );
			$this->assertIsString( $row['slug'] );
			$this->assertIsString( $row['name'] );
			$this->assertStringStartsNotWith( 'wc-', $row['slug'] );
		}
	}

	public function test_output_shape_is_exactly_statuses_and_total(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( self::ABILITY )->execute( array() );

		$this->assertSame( array( 'statuses', 'total' ), array_keys( $result ) );
	}

	public function test_wrong_capability_is_denied(): void {
		$this->actingAs( 'subscriber' );

		$result = wp_get_ability( self::ABILITY )->execute( array() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}
}
