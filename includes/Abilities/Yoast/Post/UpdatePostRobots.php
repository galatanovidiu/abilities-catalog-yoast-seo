<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogYoast\Abilities\Yoast\Post;

use GalatanOvidiu\AbilitiesCatalogYoast\Contracts\ConditionalAbility;
use GalatanOvidiu\AbilitiesCatalogYoast\Support\YoastFieldShaper;
use GalatanOvidiu\AbilitiesCatalogYoast\Support\YoastPlugin;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sets a post's Yoast robots directives: noindex, nofollow, and advanced flags.
 *
 * Writes three advanced per-post fields through Yoast's own setter (via the
 * {@see YoastPlugin} facade), never raw `update_post_meta`:
 *
 * - `meta-robots-noindex` — the tri-state search-indexing directive (`default`
 *   inherits the post type setting, `noindex` hides the URL, `index` forces it in).
 * - `meta-robots-nofollow` — whether links in the post are followed.
 * - `meta-robots-adv` — the advanced flags `noimageindex` / `noarchive` /
 *   `nosnippet` (the only three Yoast SEO free stores per post).
 *
 * Send only the fields you want to change; an omitted field is left untouched.
 *
 * This is a flagged write with HIGH SEO blast radius. Setting `robots_noindex`
 * to `noindex` flows from post meta into Yoast's indexable, marks the URL
 * non-public, and silently removes it from search engines — invisible until
 * someone re-inspects the robots tag. The change is reversible: setting
 * `robots_noindex` back to `default` deletes the override and restores the post
 * type behavior. To surface what changed, the result carries a `changes` block
 * with the `{ from, to }` of every flag this call actually moved.
 *
 * It is advanced-gated: in addition to object-level `edit_post`, when Yoast's
 * `disableadvanced_meta` restriction is on (its default) the caller must hold
 * `wpseo_edit_advanced_metadata` or `wpseo_manage_options` (the cap a default
 * administrator carries — research-findings §8).
 *
 * This is a {@see ConditionalAbility} gated on Yoast SEO being active, so it does
 * not register when Yoast is off.
 *
 * @since 0.5.0
 */
final class UpdatePostRobots implements ConditionalAbility {

	/**
	 * Maps the readable noindex enum to Yoast's stored tri-state code.
	 *
	 * @var array<string,string>
	 */
	private const NOINDEX_TO_CODE = array(
		'default' => '0',
		'noindex' => '1',
		'index'   => '2',
	);

	/**
	 * The closed set of advanced robots flags Yoast SEO free stores per post.
	 *
	 * @var array<int,string>
	 */
	private const ADVANCED_FLAGS = array( 'noimageindex', 'noarchive', 'nosnippet' );

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-yoast/update-post-robots';
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
			'label'               => __( 'Update post robots directives', 'abilities-catalog-yoast' ),
			'description'         => __( 'Sets a post\'s robots directives: search-engine indexing (noindex), link-following (nofollow), and advanced flags (no-image-index, no-archive, no-snippet). Send only the fields you want to change. HIGH blast radius: setting noindex hides the post\'s URL from search engines site-wide and silently, invisible until the robots tag is re-inspected; it is reversible by setting noindex back to "default". The result returns a "changes" block with the old and new value of every flag that actually moved.', 'abilities-catalog-yoast' ),
			'category'            => 'og-yoast',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'post_id' ),
				'properties'           => array(
					'post_id'         => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'The post ID. Discover IDs with content/list-posts.', 'abilities-catalog-yoast' ),
					),
					'robots_noindex'  => array(
						'type'        => 'string',
						'enum'        => array( 'default', 'noindex', 'index' ),
						'description' => __( 'Search-engine indexing directive. "default" inherits the post type setting (and clears any override), "noindex" hides the post from search engines, "index" forces indexing.', 'abilities-catalog-yoast' ),
					),
					'robots_nofollow' => array(
						'type'        => 'boolean',
						'description' => __( 'Whether links in the post are nofollow. true sets nofollow; false sets follow (the default).', 'abilities-catalog-yoast' ),
					),
					'robots_advanced' => array(
						'type'        => 'array',
						'items'       => array(
							'type' => 'string',
							'enum' => array( 'noimageindex', 'noarchive', 'nosnippet' ),
						),
						'description' => __( 'The advanced robots flags to set. Replaces the stored list; an empty array clears all advanced flags.', 'abilities-catalog-yoast' ),
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'properties'           => array(
					'post_id'             => array(
						'type'        => 'integer',
						'description' => __( 'The post ID this metadata belongs to.', 'abilities-catalog-yoast' ),
					),
					'focus_keyphrase'     => array(
						'type'        => 'string',
						'description' => __( 'The focus keyphrase the post is optimized for.', 'abilities-catalog-yoast' ),
					),
					'seo_title'           => array(
						'type'        => 'string',
						'description' => __( 'The SEO title template. Empty means the site default is used.', 'abilities-catalog-yoast' ),
					),
					'meta_description'    => array(
						'type'        => 'string',
						'description' => __( 'The meta description. Empty means the site default is used.', 'abilities-catalog-yoast' ),
					),
					'canonical'           => array(
						'type'        => 'string',
						'description' => __( 'The canonical URL override. Empty means the post uses its own permalink.', 'abilities-catalog-yoast' ),
					),
					'robots_noindex'      => array(
						'type'        => 'string',
						'enum'        => array( 'default', 'noindex', 'index' ),
						'description' => __( 'Search-engine indexing directive in effect for the post.', 'abilities-catalog-yoast' ),
					),
					'robots_nofollow'     => array(
						'type'        => 'string',
						'enum'        => array( 'follow', 'nofollow' ),
						'description' => __( 'Link-following directive in effect for the post.', 'abilities-catalog-yoast' ),
					),
					'robots_advanced'     => array(
						'type'        => 'array',
						'items'       => array(
							'type' => 'string',
							'enum' => array( 'noimageindex', 'noarchive', 'nosnippet' ),
						),
						'description' => __( 'Advanced robots directives in effect for the post.', 'abilities-catalog-yoast' ),
					),
					'is_cornerstone'      => array(
						'type'        => 'boolean',
						'description' => __( 'Whether the post is flagged as cornerstone content.', 'abilities-catalog-yoast' ),
					),
					'breadcrumb_title'    => array(
						'type'        => 'string',
						'description' => __( 'The breadcrumb title override. Empty means the post title is used.', 'abilities-catalog-yoast' ),
					),
					'schema_page_type'    => array(
						'type'        => 'string',
						'description' => __( 'The schema.org page type. An empty string means the post type default is used.', 'abilities-catalog-yoast' ),
					),
					'schema_article_type' => array(
						'type'        => 'string',
						'description' => __( 'The schema.org article type. An empty string means the post type default is used.', 'abilities-catalog-yoast' ),
					),
					'changes'             => array(
						'type'                 => 'object',
						'description'          => __( 'The old and new value of every robots flag this call actually changed. A flag that did not move (or was not supplied) is absent.', 'abilities-catalog-yoast' ),
						'properties'           => array(
							'robots_noindex'  => $this->changeBlockSchema( __( 'The indexing directive before and after this write.', 'abilities-catalog-yoast' ) ),
							'robots_nofollow' => $this->changeBlockSchema( __( 'The link-following directive before and after this write.', 'abilities-catalog-yoast' ) ),
							'robots_advanced' => array(
								'type'                 => 'object',
								'description'          => __( 'The advanced flag list before and after this write.', 'abilities-catalog-yoast' ),
								'properties'           => array(
									'from' => array(
										'type'  => 'array',
										'items' => array( 'type' => 'string' ),
									),
									'to'   => array(
										'type'  => 'array',
										'items' => array( 'type' => 'string' ),
									),
								),
								'additionalProperties' => false,
							),
						),
						'additionalProperties' => false,
					),
				),
				'additionalProperties' => false,
			),
			'execute_callback'    => array( $this, 'execute' ),
			'permission_callback' => array( $this, 'hasPermission' ),
			'meta'                => array(
				'annotations'  => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => false,
				),
				'screen'       => 'post.php?post={id}&action=edit',
				'show_in_rest' => true,
			),
		);
	}

	/**
	 * Authorizes the write: Yoast active, object-level `edit_post`, and — when the
	 * advanced-meta restriction is on — the advanced-edit cap.
	 *
	 * Robots fields are Yoast "advanced" metadata. When `disableadvanced_meta` is
	 * on (its default), Yoast gates the advanced metabox behind
	 * `wpseo_edit_advanced_metadata`, which it also grants to
	 * `wpseo_manage_options` holders (a default administrator has the latter, not
	 * the former — research-findings §8, `capability-utils.php:20-26`). A check of
	 * only `wpseo_edit_advanced_metadata` would wrongly block a default admin, so
	 * the OR-logic is load-bearing. `wpseo_manage_options` is never substituted
	 * with `manage_options`.
	 *
	 * @param array<string,mixed> $input The validated input. Expects `post_id`.
	 * @return bool True when the caller may set this post's advanced robots fields.
	 */
	public function hasPermission( $input ): bool {
		if ( ! YoastPlugin::isActive() ) {
			return false;
		}

		$post_id = (int) ( $input['post_id'] ?? 0 );
		if ( $post_id <= 0 ) {
			return false;
		}

		// A missing post can never pass the object-level edit_post check, which would
		// collapse it into a permission error; fall back to the general edit_posts cap
		// so execute() surfaces the typed 404 to a caller who may edit posts at all.
		if ( null === get_post( $post_id ) ) {
			return current_user_can( 'edit_posts' );
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return false;
		}

		if ( YoastPlugin::getDisableAdvancedMeta() ) {
			return current_user_can( 'wpseo_edit_advanced_metadata' )
				|| current_user_can( 'wpseo_manage_options' );
		}

		return true;
	}

	/**
	 * Writes the supplied robots fields and returns the updated curated SEO row.
	 *
	 * Reads each robots field before writing so the result can report the
	 * `{ from, to }` of every flag that actually moved. Each write goes through
	 * Yoast's own setter; after writing, the field is re-read to confirm the value
	 * stuck (Yoast's setter returns no success signal) and a typed error is
	 * returned naming the field if it did not.
	 *
	 * @param array<string,mixed> $input The validated input. Expects `post_id`.
	 * @return object|\WP_Error The updated curated SEO object, or a typed error.
	 */
	public function execute( $input ) {
		if ( ! YoastPlugin::isActive() ) {
			return YoastPlugin::unavailable();
		}

		$post_id = absint( $input['post_id'] ?? 0 );

		if ( null === get_post( $post_id ) ) {
			return $this->postNotFound( $post_id );
		}

		$changes = array();

		if ( array_key_exists( 'robots_noindex', $input ) ) {
			$result = $this->writeNoindex( $post_id, (string) $input['robots_noindex'], $changes );
			if ( $result instanceof WP_Error ) {
				return $result;
			}
		}

		if ( array_key_exists( 'robots_nofollow', $input ) ) {
			$result = $this->writeNofollow( $post_id, (bool) $input['robots_nofollow'], $changes );
			if ( $result instanceof WP_Error ) {
				return $result;
			}
		}

		if ( array_key_exists( 'robots_advanced', $input ) ) {
			$result = $this->writeAdvanced( $post_id, (array) $input['robots_advanced'], $changes );
			if ( $result instanceof WP_Error ) {
				return $result;
			}
		}

		$row            = $this->readCuratedRow( $post_id );
		$row['changes'] = (object) $changes;

		return (object) $row;
	}

	/**
	 * Writes the noindex tri-state, recording the change if it moved.
	 *
	 * @param int                                       $post_id The post ID.
	 * @param string                                    $label   The readable enum value.
	 * @param array<string,array{from:mixed,to:mixed}> &$changes The change accumulator.
	 * @return true|\WP_Error True on success, or a typed write-failure error.
	 */
	private function writeNoindex( int $post_id, string $label, array &$changes ) {
		$from = YoastFieldShaper::robotsNoindexLabel( YoastPlugin::getPostMetaValue( 'meta-robots-noindex', $post_id ) );

		// The schema's closed enum guarantees $label is one of the mapped keys.
		$code = self::NOINDEX_TO_CODE[ $label ];
		YoastPlugin::setPostMetaValue( 'meta-robots-noindex', $code, $post_id );

		$to = YoastFieldShaper::robotsNoindexLabel( YoastPlugin::getPostMetaValue( 'meta-robots-noindex', $post_id ) );
		if ( $to !== $label ) {
			return $this->writeFailed( $post_id, 'robots_noindex' );
		}

		if ( $from !== $to ) {
			$changes['robots_noindex'] = array(
				'from' => $from,
				'to'   => $to,
			);
		}

		return true;
	}

	/**
	 * Writes the nofollow flag, recording the change if it moved.
	 *
	 * @param int                                       $post_id  The post ID.
	 * @param bool                                      $nofollow Whether links are nofollow.
	 * @param array<string,array{from:mixed,to:mixed}> &$changes The change accumulator.
	 * @return true|\WP_Error True on success, or a typed write-failure error.
	 */
	private function writeNofollow( int $post_id, bool $nofollow, array &$changes ) {
		$from = $this->nofollowLabel( YoastPlugin::getPostMetaValue( 'meta-robots-nofollow', $post_id ) );

		$code = $nofollow ? '1' : '0';
		YoastPlugin::setPostMetaValue( 'meta-robots-nofollow', $code, $post_id );

		$to = $this->nofollowLabel( YoastPlugin::getPostMetaValue( 'meta-robots-nofollow', $post_id ) );
		if ( $to !== ( $nofollow ? 'nofollow' : 'follow' ) ) {
			return $this->writeFailed( $post_id, 'robots_nofollow' );
		}

		if ( $from !== $to ) {
			$changes['robots_nofollow'] = array(
				'from' => $from,
				'to'   => $to,
			);
		}

		return true;
	}

	/**
	 * Writes the advanced-flag list as CSV, recording the change if it moved.
	 *
	 * @param int                                       $post_id  The post ID.
	 * @param array<int,mixed>                          $flags    The requested flags (schema-validated).
	 * @param array<string,array{from:mixed,to:mixed}> &$changes The change accumulator.
	 * @return true|\WP_Error True on success, or a typed write-failure error.
	 */
	private function writeAdvanced( int $post_id, array $flags, array &$changes ) {
		$from = $this->splitAdvanced( YoastPlugin::getPostMetaValue( 'meta-robots-adv', $post_id ) );

		$wanted = $this->normalizeAdvanced( $flags );
		YoastPlugin::setPostMetaValue( 'meta-robots-adv', implode( ',', $wanted ), $post_id );

		$to = $this->splitAdvanced( YoastPlugin::getPostMetaValue( 'meta-robots-adv', $post_id ) );
		if ( $to !== $wanted ) {
			return $this->writeFailed( $post_id, 'robots_advanced' );
		}

		if ( $from !== $to ) {
			$changes['robots_advanced'] = array(
				'from' => $from,
				'to'   => $to,
			);
		}

		return true;
	}

	/**
	 * Reads the curated per-post SEO row (the get-post-seo non-social shape).
	 *
	 * Mirrors the key order `get-post-seo` produces for the non-social fields, so
	 * the write returns the same curated object shape. The social overrides are
	 * outside this ability's concern and are not surfaced here.
	 *
	 * @param int $post_id The post ID to read.
	 * @return array<string,mixed> The curated SEO row.
	 */
	private function readCuratedRow( int $post_id ): array {
		return array(
			'post_id'             => $post_id,
			'focus_keyphrase'     => YoastPlugin::getPostMetaValue( 'focuskw', $post_id ),
			'seo_title'           => YoastPlugin::getPostMetaValue( 'title', $post_id ),
			'meta_description'    => YoastPlugin::getPostMetaValue( 'metadesc', $post_id ),
			'canonical'           => YoastPlugin::getPostMetaValue( 'canonical', $post_id ),
			'robots_noindex'      => YoastFieldShaper::robotsNoindexLabel( YoastPlugin::getPostMetaValue( 'meta-robots-noindex', $post_id ) ),
			'robots_nofollow'     => $this->nofollowLabel( YoastPlugin::getPostMetaValue( 'meta-robots-nofollow', $post_id ) ),
			'robots_advanced'     => $this->splitAdvanced( YoastPlugin::getPostMetaValue( 'meta-robots-adv', $post_id ) ),
			'is_cornerstone'      => '1' === YoastPlugin::getPostMetaValue( 'is_cornerstone', $post_id ),
			'breadcrumb_title'    => YoastPlugin::getPostMetaValue( 'bctitle', $post_id ),
			'schema_page_type'    => YoastPlugin::getPostMetaValue( 'schema_page_type', $post_id ),
			'schema_article_type' => YoastPlugin::getPostMetaValue( 'schema_article_type', $post_id ),
		);
	}

	/**
	 * Labels the stored nofollow code.
	 *
	 * @param string $code The stored `meta-robots-nofollow` value (`'1'` or `'0'`).
	 * @return string `nofollow` for `'1'`, otherwise `follow`.
	 */
	private function nofollowLabel( string $code ): string {
		return '1' === $code ? 'nofollow' : 'follow';
	}

	/**
	 * Splits the stored advanced-robots CSV into a sorted, de-duplicated token list.
	 *
	 * Only the three flags Yoast SEO free stores survive
	 * (research-findings §3.1, `class-wpseo-meta.php:158-166`). The list is sorted
	 * so the before/after comparison is order-independent.
	 *
	 * @param string $csv The stored `meta-robots-adv` CSV value.
	 * @return array<int,string> The directive tokens, empty when none are set.
	 */
	private function splitAdvanced( string $csv ): array {
		$csv = trim( $csv );
		if ( '' === $csv ) {
			return array();
		}

		return $this->normalizeAdvanced( array_map( 'trim', explode( ',', $csv ) ) );
	}

	/**
	 * Filters a flag list to the allowed set, de-duplicates, and sorts it.
	 *
	 * @param array<int,mixed> $flags The candidate flags.
	 * @return array<int,string> The normalized list.
	 */
	private function normalizeAdvanced( array $flags ): array {
		$clean = array_values(
			array_unique(
				array_filter(
					array_map( 'strval', $flags ),
					static function ( string $flag ): bool {
						return in_array( $flag, self::ADVANCED_FLAGS, true );
					}
				)
			)
		);

		sort( $clean );

		return $clean;
	}

	/**
	 * Builds the typed not-found error for a missing post.
	 *
	 * @param int $post_id The post ID that was not found.
	 * @return \WP_Error A `yoast_post_not_found` error with HTTP status 404.
	 */
	private function postNotFound( int $post_id ): WP_Error {
		return new WP_Error(
			'yoast_post_not_found',
			sprintf(
				/* translators: %d: post ID. */
				__( 'Post %d not found. List posts with content/list-posts, then retry with a valid post_id.', 'abilities-catalog-yoast' ),
				$post_id
			),
			array( 'status' => 404 )
		);
	}

	/**
	 * Builds the typed write-failure error for a field whose read-back mismatched.
	 *
	 * @param int    $post_id The post ID written to.
	 * @param string $field   The curated field name that failed to persist.
	 * @return \WP_Error A `yoast_post_robots_write_failed` error.
	 */
	private function writeFailed( int $post_id, string $field ): WP_Error {
		return new WP_Error(
			'yoast_post_robots_write_failed',
			sprintf(
				/* translators: 1: field name, 2: post ID. */
				__( 'Failed to write the robots field "%1$s" for post %2$d: the stored value did not match the requested value.', 'abilities-catalog-yoast' ),
				$field,
				$post_id
			),
			array( 'status' => 500 )
		);
	}

	/**
	 * The shared schema for a readable `{ from, to }` change block.
	 *
	 * @param string $description The block description.
	 * @return array<string,mixed> The JSON-schema fragment.
	 */
	private function changeBlockSchema( string $description ): array {
		return array(
			'type'                 => 'object',
			'description'          => $description,
			'properties'           => array(
				'from' => array( 'type' => 'string' ),
				'to'   => array( 'type' => 'string' ),
			),
			'additionalProperties' => false,
		);
	}
}
