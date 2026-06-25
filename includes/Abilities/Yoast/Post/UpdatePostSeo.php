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
 * Updates a post's basic Yoast SEO fields.
 *
 * Writes the focus keyphrase, SEO title, meta description, cornerstone flag, and
 * the social (Open Graph / X-Twitter) overrides for one post. Only the fields
 * supplied in the input are written; the rest are left untouched, so this is a
 * partial update — send only what you want to change.
 *
 * Every field is written through Yoast's own setter
 * {@see \GalatanOvidiu\AbilitiesCatalogYoast\Support\YoastPlugin::setPostMetaValue()}
 * (Yoast's `WPSEO_Meta::set_value()`), so Yoast's per-field sanitizers run on every
 * write. None of these fields is routed through the core `/wp/v2` REST API. Writing
 * a field's default value (an empty string for the text fields, `false` for
 * cornerstone) deletes the stored row and resets the field to its default — so an
 * empty string is a reset, not a no-op.
 *
 * `set_value()` returns no success signal, so after writing each field the ability
 * re-reads it through {@see YoastPlugin::getPostMetaValue()} and returns a typed
 * error if the stored value does not match what was sent. The result is the full
 * curated SEO object in the same shape and key order as `og-yoast/get-post-seo`,
 * built by the shared {@see YoastFieldShaper::curatedPostSeoRow()} so the read and
 * the writes never drift.
 *
 * This updates only the basic SEO fields, so it carries no advanced-cap gate; the
 * advanced robots / canonical / schema fields live in their own abilities. It is a
 * {@see ConditionalAbility} gated on Yoast SEO being active.
 *
 * @since 0.5.0
 */
final class UpdatePostSeo implements ConditionalAbility {

	/**
	 * Maps each input field to its internal Yoast meta key.
	 *
	 * The internal keys carry no `_yoast_wpseo_` prefix — {@see YoastPlugin}
	 * adds it. Values are scalar text/flag fields written verbatim through Yoast's
	 * sanitizer; the boolean `is_cornerstone` is normalized separately.
	 *
	 * @var array<string,string>
	 */
	private const FIELD_MAP = array(
		'focus_keyphrase'       => 'focuskw',
		'seo_title'             => 'title',
		'meta_description'      => 'metadesc',
		'opengraph_title'       => 'opengraph-title',
		'opengraph_description' => 'opengraph-description',
		'opengraph_image'       => 'opengraph-image',
		'twitter_title'         => 'twitter-title',
		'twitter_description'   => 'twitter-description',
		'twitter_image'         => 'twitter-image',
	);

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-yoast/update-post-seo';
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
			'label'               => __( 'Update post SEO metadata', 'abilities-catalog-yoast' ),
			'description'         => __( 'Updates a post\'s basic Yoast SEO fields: focus keyphrase, SEO title, meta description, cornerstone flag, and the social (Open Graph and X/Twitter) overrides. Send only the fields you want to change; the rest are left as they are. Writing an empty string (or false for cornerstone) resets that field to its default. Returns the full SEO row after the write. Advanced robots, canonical, and schema fields have their own abilities.', 'abilities-catalog-yoast' ),
			'category'            => 'og-yoast',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'post_id' ),
				'properties'           => array(
					'post_id'               => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'The post ID to update. Discover IDs with content/list-posts.', 'abilities-catalog-yoast' ),
					),
					'focus_keyphrase'       => array(
						'type'        => 'string',
						'description' => __( 'The focus keyphrase the post is optimized for. An empty string clears it.', 'abilities-catalog-yoast' ),
					),
					'seo_title'             => array(
						'type'        => 'string',
						'description' => __( 'The SEO title template (may contain Yoast replacement variables). An empty string resets it to the site default.', 'abilities-catalog-yoast' ),
					),
					'meta_description'      => array(
						'type'        => 'string',
						'description' => __( 'The meta description. An empty string resets it to the site default.', 'abilities-catalog-yoast' ),
					),
					'is_cornerstone'        => array(
						'type'        => 'boolean',
						'description' => __( 'Whether the post is cornerstone content. false resets the flag to its default (off).', 'abilities-catalog-yoast' ),
					),
					'opengraph_title'       => array(
						'type'        => 'string',
						'description' => __( 'Open Graph title override. An empty string clears it.', 'abilities-catalog-yoast' ),
					),
					'opengraph_description' => array(
						'type'        => 'string',
						'description' => __( 'Open Graph description override. An empty string clears it.', 'abilities-catalog-yoast' ),
					),
					'opengraph_image'       => array(
						'type'        => 'string',
						'description' => __( 'Open Graph image URL override. An empty string clears it.', 'abilities-catalog-yoast' ),
					),
					'twitter_title'         => array(
						'type'        => 'string',
						'description' => __( 'X/Twitter title override. An empty string clears it.', 'abilities-catalog-yoast' ),
					),
					'twitter_description'   => array(
						'type'        => 'string',
						'description' => __( 'X/Twitter description override. An empty string clears it.', 'abilities-catalog-yoast' ),
					),
					'twitter_image'         => array(
						'type'        => 'string',
						'description' => __( 'X/Twitter image URL override. An empty string clears it.', 'abilities-catalog-yoast' ),
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => $this->outputSchema(),
			'execute_callback'    => array( $this, 'execute' ),
			'permission_callback' => array( $this, 'hasPermission' ),
			'meta'                => array(
				'annotations'  => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => false,
				),
				'show_in_rest' => true,
			),
		);
	}

	/**
	 * Authorizes the write: Yoast active and the caller may edit this post.
	 *
	 * Object-level `edit_post` mirrors Yoast's own auth on its three exposed SEO
	 * fields (research-findings §8, `class-wpseo-meta.php:301-303`). The basic SEO
	 * fields carry no advanced-cap gate — that gate guards the robots / canonical /
	 * schema fields, which live in their own abilities.
	 *
	 * When the post does not exist, the object-level `edit_post` check can never
	 * pass (the meta cap maps to `do_not_allow`), which would collapse a missing
	 * post into a permission error. To let {@see execute()} return the typed 404
	 * instead, a missing post falls back to the general `edit_posts` cap: a caller
	 * who may edit posts at all reaches the not-found error, while an
	 * under-privileged caller is still denied without learning the post is absent.
	 *
	 * @param array<string,mixed> $input The validated input. Expects `post_id`.
	 * @return bool True when Yoast is active and the caller may edit the post.
	 */
	public function hasPermission( $input ): bool {
		if ( ! YoastPlugin::isActive() ) {
			return false;
		}

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
	 * Writes the supplied basic SEO fields, then returns the full curated SEO row.
	 *
	 * Each supplied field is written through Yoast's setter and re-read to confirm
	 * the write stuck (`set_value()` gives no return value). A read-back mismatch
	 * yields a typed `yoast_post_seo_write_failed` error naming the field and post.
	 *
	 * @param array<string,mixed> $input The validated input. Expects `post_id` plus any basic fields.
	 * @return array<string,mixed>|\WP_Error The full curated SEO row, or a typed error.
	 */
	public function execute( $input ) {
		if ( ! YoastPlugin::isActive() ) {
			return YoastPlugin::unavailable();
		}

		$post_id = absint( $input['post_id'] ?? 0 );

		if ( null === get_post( $post_id ) ) {
			return new WP_Error(
				'yoast_post_not_found',
				sprintf(
					/* translators: %d: post ID. */
					__( 'Post %d not found. List posts with content/list-posts or read one with content/get-post.', 'abilities-catalog-yoast' ),
					$post_id
				),
				array( 'status' => 404 )
			);
		}

		foreach ( self::FIELD_MAP as $input_key => $meta_key ) {
			if ( ! array_key_exists( $input_key, $input ) ) {
				continue;
			}

			$value = (string) $input[ $input_key ];

			$error = $this->writeField( $meta_key, $value, $value, $post_id, $input_key );
			if ( $error instanceof WP_Error ) {
				return $error;
			}
		}

		if ( array_key_exists( 'is_cornerstone', $input ) ) {
			// Yoast stores cornerstone as '1' (on) or 'false' (default, deletes the row).
			$intended = $input['is_cornerstone'] ? '1' : 'false';

			$error = $this->writeField( 'is_cornerstone', $intended, $intended, $post_id, 'is_cornerstone' );
			if ( $error instanceof WP_Error ) {
				return $error;
			}
		}

		// Build the result from the shared shaper so the shape and key order stay
		// identical to og-yoast/get-post-seo (single source of truth).
		return YoastFieldShaper::curatedPostSeoRow( $post_id );
	}

	/**
	 * Writes one field through Yoast and confirms the stored value by re-reading.
	 *
	 * `WPSEO_Meta::set_value()` returns no success signal, so the only way to
	 * confirm a write is to read the field back. The expected read-back value can
	 * differ from the value sent (Yoast's `'true'`→`'1'` cornerstone normalization),
	 * hence the separate `$expected_read` argument.
	 *
	 * @param string $meta_key      The internal Yoast meta key (no `_yoast_wpseo_` prefix).
	 * @param string $value         The value to write.
	 * @param string $expected_read The value the re-read must return for the write to count.
	 * @param int    $post_id       The post being written.
	 * @param string $input_field   The input field name, for the error message.
	 * @return \WP_Error|null A typed error when the read-back does not match, else null.
	 */
	private function writeField( string $meta_key, string $value, string $expected_read, int $post_id, string $input_field ): ?WP_Error {
		YoastPlugin::setPostMetaValue( $meta_key, $value, $post_id );

		if ( YoastPlugin::getPostMetaValue( $meta_key, $post_id ) !== $expected_read ) {
			return new WP_Error(
				'yoast_post_seo_write_failed',
				sprintf(
					/* translators: 1: input field name, 2: post ID. */
					__( 'Could not store the "%1$s" field on post %2$d: the value did not persist. Re-read the post with og-yoast/get-post-seo and retry.', 'abilities-catalog-yoast' ),
					$input_field,
					$post_id
				),
				array( 'status' => 500 )
			);
		}

		return null;
	}

	/**
	 * The output schema — the full curated SEO object, identical to get-post-seo.
	 *
	 * The shape and key order match `og-yoast/get-post-seo` because the result is
	 * built by the shared {@see YoastFieldShaper::curatedPostSeoRow()}. The row carries
	 * only string keys, so it always serializes as a JSON object.
	 *
	 * @return array<string,mixed> The output JSON schema.
	 */
	private function outputSchema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'post_id'               => array(
					'type'        => 'integer',
					'description' => __( 'The post ID this metadata belongs to.', 'abilities-catalog-yoast' ),
				),
				'focus_keyphrase'       => array(
					'type'        => 'string',
					'description' => __( 'The focus keyphrase the post is optimized for.', 'abilities-catalog-yoast' ),
				),
				'seo_title'             => array(
					'type'        => 'string',
					'description' => __( 'The SEO title template (may contain Yoast replacement variables). Empty means the site default is used.', 'abilities-catalog-yoast' ),
				),
				'meta_description'      => array(
					'type'        => 'string',
					'description' => __( 'The meta description. Empty means the site default is used.', 'abilities-catalog-yoast' ),
				),
				'canonical'             => array(
					'type'        => 'string',
					'description' => __( 'The canonical URL override. Empty means the post uses its own permalink.', 'abilities-catalog-yoast' ),
				),
				'robots_noindex'        => array(
					'type'        => 'string',
					'enum'        => array( 'default', 'noindex', 'index' ),
					'description' => __( 'Search-engine indexing directive: "default" inherits the post type setting, "noindex" hides the post, "index" forces indexing.', 'abilities-catalog-yoast' ),
				),
				'robots_nofollow'       => array(
					'type'        => 'string',
					'enum'        => array( 'follow', 'nofollow' ),
					'description' => __( 'Link-following directive: "follow" or "nofollow".', 'abilities-catalog-yoast' ),
				),
				'robots_advanced'       => array(
					'type'        => 'array',
					'items'       => array(
						'type' => 'string',
						'enum' => array( 'noimageindex', 'noarchive', 'nosnippet' ),
					),
					'description' => __( 'Advanced robots directives in effect for the post.', 'abilities-catalog-yoast' ),
				),
				'is_cornerstone'        => array(
					'type'        => 'boolean',
					'description' => __( 'Whether the post is flagged as cornerstone content.', 'abilities-catalog-yoast' ),
				),
				'breadcrumb_title'      => array(
					'type'        => 'string',
					'description' => __( 'The breadcrumb title override. Empty means the post title is used.', 'abilities-catalog-yoast' ),
				),
				'schema_page_type'      => array(
					'type'        => 'string',
					'description' => __( 'The schema.org page type. An empty string means the post type default is used.', 'abilities-catalog-yoast' ),
				),
				'schema_article_type'   => array(
					'type'        => 'string',
					'description' => __( 'The schema.org article type. An empty string means the post type default is used.', 'abilities-catalog-yoast' ),
				),
				'opengraph_title'       => array(
					'type'        => 'string',
					'description' => __( 'Open Graph title override. Present only when Open Graph data is enabled.', 'abilities-catalog-yoast' ),
				),
				'opengraph_description' => array(
					'type'        => 'string',
					'description' => __( 'Open Graph description override. Present only when Open Graph data is enabled.', 'abilities-catalog-yoast' ),
				),
				'opengraph_image'       => array(
					'type'        => 'string',
					'description' => __( 'Open Graph image URL override. Present only when Open Graph data is enabled.', 'abilities-catalog-yoast' ),
				),
				'opengraph_image_id'    => array(
					'type'        => 'string',
					'description' => __( 'Open Graph image attachment ID override. Present only when Open Graph data is enabled.', 'abilities-catalog-yoast' ),
				),
				'twitter_title'         => array(
					'type'        => 'string',
					'description' => __( 'X/Twitter title override. Present only when X/Twitter data is enabled.', 'abilities-catalog-yoast' ),
				),
				'twitter_description'   => array(
					'type'        => 'string',
					'description' => __( 'X/Twitter description override. Present only when X/Twitter data is enabled.', 'abilities-catalog-yoast' ),
				),
				'twitter_image'         => array(
					'type'        => 'string',
					'description' => __( 'X/Twitter image URL override. Present only when X/Twitter data is enabled.', 'abilities-catalog-yoast' ),
				),
				'twitter_image_id'      => array(
					'type'        => 'string',
					'description' => __( 'X/Twitter image attachment ID override. Present only when X/Twitter data is enabled.', 'abilities-catalog-yoast' ),
				),
			),
			'additionalProperties' => false,
		);
	}
}
