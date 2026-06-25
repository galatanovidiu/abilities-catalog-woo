<?php
/**
 * Integration tests for the og-wc-reports/get-reviews-totals ability.
 *
 * @package AbilitiesCatalogWoo\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogWoo\Tests\Integration\Abilities\Reports;

use GalatanOvidiu\AbilitiesCatalogWoo\Tests\TestCase;

/**
 * Exercises og-wc-reports/get-reviews-totals: the five rating buckets returned for
 * the legacy wc/v3 reviews-totals report, the wrong-capability denial, and the
 * exact closed row shape (no raw report fields or _links leak; total is an int).
 */
final class GetReviewsTotalsTest extends TestCase {

	/**
	 * The full closed key set the ability returns for one totals row.
	 *
	 * @var list<string>
	 */
	private const ROW_KEYS = array( 'slug', 'name', 'total' );

	/**
	 * The five rating-bucket slugs the report always returns.
	 *
	 * @var list<string>
	 */
	private const RATED_SLUGS = array(
		'rated_1_out_of_5',
		'rated_2_out_of_5',
		'rated_3_out_of_5',
		'rated_4_out_of_5',
		'rated_5_out_of_5',
	);

	public function test_ability_is_registered(): void {
		$ability = wp_get_ability( 'og-wc-reports/get-reviews-totals' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'og-wc-reports/get-reviews-totals', $ability->get_name() );
	}

	public function test_admin_gets_the_five_rated_rows(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'og-wc-reports/get-reviews-totals' )->execute( array() );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'items', $result );
		$this->assertArrayHasKey( 'total', $result );
		$this->assertIsArray( $result['items'] );
		$this->assertIsInt( $result['total'] );
		$this->assertSame( 5, $result['total'] );
		$this->assertCount( 5, $result['items'] );

		$slugs = array_column( $result['items'], 'slug' );
		$this->assertSame( self::RATED_SLUGS, $slugs );
	}

	public function test_row_shape_is_exact_and_closed(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'og-wc-reports/get-reviews-totals' )->execute( array() );

		$this->assertIsArray( $result );
		$this->assertNotEmpty( $result['items'] );

		$row = $result['items'][0];

		$this->assertSame( self::ROW_KEYS, array_keys( $row ) );

		// No raw report fields or links leak through.
		$this->assertArrayNotHasKey( '_links', $row );
		$this->assertArrayNotHasKey( 'description', $row );

		$this->assertIsString( $row['slug'] );
		$this->assertIsString( $row['name'] );
		$this->assertIsInt( $row['total'] );
		$this->assertGreaterThanOrEqual( 0, $row['total'] );
	}

	public function test_subscriber_is_denied(): void {
		$this->actingAs( 'subscriber' );

		$ability = wp_get_ability( 'og-wc-reports/get-reviews-totals' );

		$this->assertFalse( $ability->check_permissions( array() ) );
	}
}
