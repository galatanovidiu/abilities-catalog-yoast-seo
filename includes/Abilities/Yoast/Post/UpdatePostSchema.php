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
 * Write ability: `og-yoast/update-post-schema`.
 *
 * Sets a post's Schema.org page type, article type, and breadcrumb title. It
 * wraps Yoast's own `WPSEO_Meta::set_value()` (through
 * {@see \GalatanOvidiu\AbilitiesCatalogYoast\Support\YoastPlugin::setPostMetaValue()})
 * for the internal keys `schema_page_type`, `schema_article_type`, and `bctitle`
 * (research-findings §3.1, `class-wpseo-meta.php:167-190`; §3.2), so Yoast's own
 * sanitizers run and there is no raw `update_post_meta`. Send only the fields you
 * want to change.
 *
 * The schema-type enums are read from Yoast at build time
 * ({@see YoastPlugin::schemaPageTypes()} / {@see YoastPlugin::schemaArticleTypes()}
 * read `Schema_Types::PAGE_TYPES` / `ARTICLE_TYPES`), plus the empty string `''`
 * which means "use the post-type default" — that is a real value, not a no-op
 * (research-findings §3.1). Writing a field's default deletes the stored row, so a
 * schema type is reset by writing `''`.
 *
 * This is an advanced metadata field. The permission check enforces object-level
 * `edit_post` plus Yoast's advanced-cap OR-logic (research-findings §8): on a
 * default install a post editor must additionally hold
 * `wpseo_edit_advanced_metadata` OR `wpseo_manage_options`.
 *
 * Only available when Yoast SEO is active (it is a {@see ConditionalAbility}).
 *
 * @since 0.5.0
 */
final class UpdatePostSchema implements ConditionalAbility {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-yoast/update-post-schema';
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
			'label'               => __( 'Update post schema', 'abilities-catalog-yoast' ),
			'description'         => __( "Sets a post's Schema.org page type, article type, and breadcrumb title. Send only the fields you want to change. An empty string for a schema type means \"use the post type default\" (it clears any override), not \"leave unchanged\". Discover post IDs with content/list-posts; read the current values with og-yoast/get-post-seo.", 'abilities-catalog-yoast' ),
			'category'            => 'og-yoast',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'post_id' ),
				'properties'           => array(
					'post_id'             => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'The post ID to update. Discover IDs with content/list-posts.', 'abilities-catalog-yoast' ),
					),
					'schema_page_type'    => array(
						'type'        => 'string',
						'enum'        => array_merge( YoastPlugin::schemaPageTypes(), array( '' ) ),
						'description' => __( 'The Schema.org page type for the post. An empty string clears the override so the post type default applies.', 'abilities-catalog-yoast' ),
					),
					'schema_article_type' => array(
						'type'        => 'string',
						'enum'        => array_merge( YoastPlugin::schemaArticleTypes(), array( '' ) ),
						'description' => __( 'The Schema.org article type for the post. An empty string clears the override so the post type default applies.', 'abilities-catalog-yoast' ),
					),
					'breadcrumb_title'    => array(
						'type'        => 'string',
						'description' => __( 'The breadcrumb title override. An empty string clears the override so the post title is used.', 'abilities-catalog-yoast' ),
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => self::outputSchema(),
			'execute_callback'    => array( $this, 'execute' ),
			'permission_callback' => array( $this, 'hasPermission' ),
			'meta'                => array(
				'annotations'  => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => true,
				),
				'show_in_rest' => true,
			),
		);
	}

	/**
	 * Authorizes the write: Yoast active, object-level `edit_post`, and the
	 * advanced-cap OR-logic for advanced metadata.
	 *
	 * This is the verbatim advanced-write guard from the batch spec / research
	 * §8 (`class-wpseo-meta.php:362,389`; `capability-utils.php:20-26`). When
	 * Yoast's `disableadvanced_meta` restriction is on (its default), Yoast gates
	 * the advanced metabox behind `wpseo_edit_advanced_metadata` but also accepts
	 * `wpseo_manage_options` — so a default administrator (who holds
	 * `wpseo_manage_options`, not the raw advanced cap) still passes. Checking
	 * only `wpseo_edit_advanced_metadata` would wrongly block a default admin.
	 *
	 * @param mixed $input The validated input. Expects `post_id`.
	 * @return bool True when the caller may set this post's advanced schema fields.
	 */
	public function hasPermission( $input ): bool {
		if ( ! YoastPlugin::isActive() ) {
			return false;
		}

		$input   = is_array( $input ) ? $input : array();
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
			return false; // object-level guard first.
		}

		if ( (bool) YoastPlugin::getDisableAdvancedMeta() ) {
			// Yoast treats wpseo_manage_options as also granting advanced edit (capability-utils.php:20-26).
			return current_user_can( 'wpseo_edit_advanced_metadata' )
				|| current_user_can( 'wpseo_manage_options' );
		}

		return true; // restriction off -> any post-editor may set advanced fields.
	}

	/**
	 * Writes the supplied schema fields through Yoast, confirms each write stuck,
	 * and returns the post's updated curated SEO object.
	 *
	 * `WPSEO_Meta::set_value()` returns no success signal, so each written field is
	 * re-read; a stored value that does not match the intended value yields a typed
	 * `WP_Error` naming the field and post.
	 *
	 * @param mixed $input The validated input. Expects `post_id` plus optional fields.
	 * @return array<string,mixed>|\WP_Error The updated curated SEO object, or a typed error.
	 */
	public function execute( $input ) {
		if ( ! YoastPlugin::isActive() ) {
			return YoastPlugin::unavailable();
		}

		$input   = is_array( $input ) ? $input : array();
		$post_id = absint( $input['post_id'] ?? 0 );

		if ( null === get_post( $post_id ) ) {
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

		// Map each supplied input field to its internal Yoast meta key.
		$writes = array();
		if ( array_key_exists( 'schema_page_type', $input ) ) {
			$writes['schema_page_type'] = (string) $input['schema_page_type'];
		}
		if ( array_key_exists( 'schema_article_type', $input ) ) {
			$writes['schema_article_type'] = (string) $input['schema_article_type'];
		}
		if ( array_key_exists( 'breadcrumb_title', $input ) ) {
			$writes['bctitle'] = (string) $input['breadcrumb_title'];
		}

		foreach ( $writes as $key => $value ) {
			YoastPlugin::setPostMetaValue( $key, $value, $post_id );

			$stored = YoastPlugin::getPostMetaValue( $key, $post_id );
			if ( $stored !== $value ) {
				return new WP_Error(
					'yoast_post_schema_write_failed',
					sprintf(
						/* translators: 1: internal field key, 2: post ID. */
						__( 'Failed to store the "%1$s" schema field for post %2$d: the read-back value did not match what was written.', 'abilities-catalog-yoast' ),
						$key,
						$post_id
					),
					array( 'status' => 500 )
				);
			}
		}

		return YoastFieldShaper::curatedPostSeoRow( $post_id );
	}

	/**
	 * The curated SEO output schema — the identical shape `og-yoast/get-post-seo`
	 * returns (the spec requires the same shaping).
	 *
	 * @return array<string,mixed> A closed object schema describing the curated SEO row.
	 */
	private static function outputSchema(): array {
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
