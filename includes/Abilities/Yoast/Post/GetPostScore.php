<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogYoast\Abilities\Yoast\Post;

use GalatanOvidiu\AbilitiesCatalogYoast\Contracts\ConditionalAbility;
use GalatanOvidiu\AbilitiesCatalogYoast\Support\YoastPlugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read ability: `og-yoast/get-post-score`.
 *
 * Returns the SEO, readability, and inclusive-language scores Yoast has already
 * STORED for one post, each as a `{ value, rank }` pair. The numeric value is the
 * stored 0–100 score; the rank is Yoast's traffic-light slug (`na`/`bad`/`ok`/`good`).
 *
 * Yoast computes these scores in the block/classic editor (client-side) and stores
 * the result as hidden post meta — there is no server-side analyze entry point. This
 * ability therefore READS the stored values only; it never (re)computes, refreshes,
 * or analyzes a post. An unanalyzed post reports `value` 0 and rank `na`.
 *
 * Wraps {@see YoastPlugin::getPostMetaValue()} (Yoast's `WPSEO_Meta::get_value()`)
 * for the three stored numbers and {@see YoastPlugin::rankFromScore()} (Yoast's
 * `WPSEO_Rank::from_numeric_score()`) for the rank. The three Yoast keys map to:
 * `linkdex` → `seo_score`, `content_score` → `readability_score`,
 * `inclusive_language_score` → `inclusive_language_score`.
 *
 * Only available when Yoast SEO is active (it is a {@see ConditionalAbility}).
 *
 * @since 0.4.0
 */
final class GetPostScore implements ConditionalAbility {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-yoast/get-post-score';
	}

	/**
	 * {@inheritDoc}
	 */
	public function isAvailable(): bool {
		return YoastPlugin::isActive();
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return array(
			'label'               => __( 'Get Post SEO Scores', 'abilities-catalog-yoast' ),
			'description'         => __( 'Returns the stored SEO, readability, and inclusive-language scores for one post, each with its numeric value and traffic-light rank (na, bad, ok, good). Reads the scores already saved by the editor; it does not analyze or recompute. A post that has never been analyzed reports value 0 and rank na. Discover post IDs with content/list-posts.', 'abilities-catalog-yoast' ),
			'category'            => 'og-yoast',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'post_id' ),
				'properties'           => array(
					'post_id' => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'The post ID. Discover IDs with content/list-posts.', 'abilities-catalog-yoast' ),
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'post_id', 'seo_score', 'readability_score', 'inclusive_language_score' ),
				'properties'           => array(
					'post_id'                  => array(
						'type'        => 'integer',
						'description' => __( 'The post ID the scores belong to.', 'abilities-catalog-yoast' ),
					),
					'seo_score'                => self::scoreSchema( __( 'The stored SEO score and its rank.', 'abilities-catalog-yoast' ) ),
					'readability_score'        => self::scoreSchema( __( 'The stored readability score and its rank.', 'abilities-catalog-yoast' ) ),
					'inclusive_language_score' => self::scoreSchema( __( 'The stored inclusive-language score and its rank.', 'abilities-catalog-yoast' ) ),
				),
				'additionalProperties' => false,
			),
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
	 * The closed schema for one `{ value, rank }` score object.
	 *
	 * @param string $description The score object's description.
	 * @return array<string,mixed> A closed object schema with `value` and `rank`.
	 */
	private static function scoreSchema( string $description ): array {
		return array(
			'type'                 => 'object',
			'description'          => $description,
			'required'             => array( 'value', 'rank' ),
			'properties'           => array(
				'value' => array(
					'type'        => array( 'integer', 'null' ),
					'description' => __( 'The stored numeric score (0–100), or null when never analyzed.', 'abilities-catalog-yoast' ),
				),
				'rank'  => array(
					'type'        => 'string',
					'enum'        => array( 'na', 'bad', 'ok', 'good' ),
					'description' => __( 'Yoast traffic-light rank: na (not analyzed), bad, ok, or good.', 'abilities-catalog-yoast' ),
				),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * Permission check: Yoast active and object-level edit on the post.
	 *
	 * The score is per-post SEO data, so a reader must be able to edit that post.
	 * The check mirrors Yoast's own per-post meta gate (`edit_post`, object-level).
	 *
	 * When the post does not exist, the object-level `edit_post` check can never
	 * pass (the meta cap maps to `do_not_allow`), which would collapse a missing
	 * post into a permission error. To let {@see execute()} return the typed 404
	 * instead, a missing post falls back to the general `edit_posts` cap: a caller
	 * who may edit posts at all reaches the not-found error, while an
	 * under-privileged caller is still denied without learning the post is absent.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if Yoast is active and the current user may read this post's score.
	 */
	public function hasPermission( $input ): bool {
		if ( ! YoastPlugin::isActive() ) {
			return false;
		}

		$input   = is_array( $input ) ? $input : array();
		$post_id = absint( $input['post_id'] ?? 0 );
		if ( $post_id <= 0 ) {
			return false;
		}

		if ( null === get_post( $post_id ) ) {
			return current_user_can( 'edit_posts' );
		}

		return current_user_can( 'edit_post', $post_id );
	}

	/**
	 * Executes the ability by reading the three stored scores.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The post's stored scores, or a typed error.
	 */
	public function execute( $input ) {
		if ( ! YoastPlugin::isActive() ) {
			return YoastPlugin::unavailable();
		}

		$input   = is_array( $input ) ? $input : array();
		$post_id = absint( $input['post_id'] ?? 0 );

		if ( null === get_post( $post_id ) ) {
			return new \WP_Error(
				'yoast_post_not_found',
				sprintf(
					/* translators: %d: the post ID that was not found. */
					__( 'Post %d not found. List posts with content/list-posts or read one with content/get-post.', 'abilities-catalog-yoast' ),
					$post_id
				),
				array( 'status' => 404 )
			);
		}

		return array(
			'post_id'                  => $post_id,
			'seo_score'                => (object) YoastPlugin::rankFromScore(
				YoastPlugin::getPostMetaValue( 'linkdex', $post_id )
			),
			'readability_score'        => (object) YoastPlugin::rankFromScore(
				YoastPlugin::getPostMetaValue( 'content_score', $post_id )
			),
			'inclusive_language_score' => (object) YoastPlugin::rankFromScore(
				YoastPlugin::getPostMetaValue( 'inclusive_language_score', $post_id )
			),
		);
	}
}
