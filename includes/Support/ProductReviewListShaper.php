<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogWoo\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Projects raw `wc/v3` product-review rows into flat, closed summary rows for
 * the catalog's review list ability.
 *
 * A WooCommerce product review is a WordPress comment on a product
 * (`comment_type = 'review'`). A `GET wc/v3/products/reviews` row carries the
 * fields a moderator needs plus rendered HTML and avatar noise. This shaper
 * copies the small fixed field set a moderator needs to triage and act on a
 * review, casts each value to the type the WC reviews schema promises, and
 * reduces `review` to a plain-text excerpt. {@see self::reviewItemSchema()} pins
 * the row closed so the runtime row and the declared schema cannot drift.
 *
 * PII: `reviewer` (the reviewer name) and `reviewer_email` are INCLUDED by
 * design. These reads are an admin moderation tool whose hard guard is the
 * `moderate_comments` capability — the exact capability WordPress's own Comments
 * screen requires to view a commenter's email. Redacting here would break the
 * moderation workflow (identify, contact, or block a reviewer) without
 * protecting anything the capability does not already expose. `reviewer_avatar_urls`
 * is omitted as noise, and `comment_author_IP` is omitted because the route
 * never exposes it.
 *
 * This is NOT under `includes/Abilities/`, so the Registry never treats it as an
 * ability. It performs no WooCommerce calls and holds no ability logic; it only
 * shapes rows and declares their schema.
 *
 * @since 0.1.0
 */
final class ProductReviewListShaper {

	/**
	 * Flat summary row for a single `wc/v3` product-review list item.
	 *
	 * Each value is read with a null-coalescing default and cast to the type the
	 * WC reviews schema guarantees. `review` is reduced to a plain-text excerpt:
	 * the controller returns the review as `wpautop()`'d HTML in view context, so
	 * `wp_strip_all_tags()` removes the markup and collapses it to plain text. The
	 * text is NOT HTML-encoded and NOT truncated — tags are only stripped.
	 *
	 * @param array<string,mixed> $row A single row from a `GET wc/v3/products/reviews` response.
	 * @return array{
	 *     id:int,
	 *     product_id:int,
	 *     product_name:string,
	 *     status:string,
	 *     reviewer:string,
	 *     reviewer_email:string,
	 *     rating:int,
	 *     review:string,
	 *     verified:bool,
	 *     date_created:string
	 * } The flat review summary row.
	 */
	public static function reviewSummary( array $row ): array {
		return array(
			'id'             => (int) ( $row['id'] ?? 0 ),
			'product_id'     => (int) ( $row['product_id'] ?? 0 ),
			'product_name'   => (string) ( $row['product_name'] ?? '' ),
			'status'         => (string) ( $row['status'] ?? '' ),
			'reviewer'       => (string) ( $row['reviewer'] ?? '' ),
			'reviewer_email' => (string) ( $row['reviewer_email'] ?? '' ),
			'rating'         => (int) ( $row['rating'] ?? 0 ),
			'review'         => wp_strip_all_tags( (string) ( $row['review'] ?? '' ) ),
			'verified'       => (bool) ( $row['verified'] ?? false ),
			'date_created'   => (string) ( $row['date_created'] ?? '' ),
		);
	}

	/**
	 * The `output_schema` item definition matching {@see self::reviewSummary()}.
	 *
	 * @return array<string,mixed> A JSON-Schema object fragment.
	 */
	public static function reviewItemSchema(): array {
		return array(
			'type'                 => 'object',
			'required'             => array( 'id' ),
			'properties'           => array(
				'id'             => array(
					'type'        => 'integer',
					'description' => __( 'The review ID, used to moderate this review.', 'abilities-catalog-woo' ),
				),
				'product_id'     => array(
					'type'        => 'integer',
					'description' => __( 'The ID of the product this review belongs to.', 'abilities-catalog-woo' ),
				),
				'product_name'   => array(
					'type'        => 'string',
					'description' => __( 'The name of the reviewed product.', 'abilities-catalog-woo' ),
				),
				'status'         => array(
					'type'        => 'string',
					'description' => __( 'The moderation status: approved, hold, spam, or trash.', 'abilities-catalog-woo' ),
				),
				'reviewer'       => array(
					'type'        => 'string',
					'description' => __( 'The reviewer name. Shown so a moderator can identify the author.', 'abilities-catalog-woo' ),
				),
				'reviewer_email' => array(
					'type'        => 'string',
					'description' => __( 'The reviewer email. Shown so a moderator can contact or block the author; visible only with the moderate_comments capability.', 'abilities-catalog-woo' ),
				),
				'rating'         => array(
					'type'        => 'integer',
					'description' => __( 'The star rating from 0 to 5.', 'abilities-catalog-woo' ),
				),
				'review'         => array(
					'type'        => 'string',
					'description' => __( 'The review text as a plain-text excerpt with all HTML tags stripped.', 'abilities-catalog-woo' ),
				),
				'verified'       => array(
					'type'        => 'boolean',
					'description' => __( 'Whether the reviewer is a verified buyer of the product.', 'abilities-catalog-woo' ),
				),
				'date_created'   => array(
					'type'        => 'string',
					'description' => __( 'The creation date as an ISO-8601 date-time string in the site timezone.', 'abilities-catalog-woo' ),
				),
			),
			'additionalProperties' => false,
		);
	}
}
