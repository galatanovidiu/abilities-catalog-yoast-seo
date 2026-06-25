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
 * Write ability: `og-yoast/update-post-canonical`.
 *
 * Sets a post's canonical URL — the `<link rel="canonical">` Yoast emits for the
 * post. Wraps {@see \GalatanOvidiu\AbilitiesCatalogYoast\Support\YoastPlugin::setPostMetaValue()}
 * (Yoast's own `WPSEO_Meta::set_value()`) for the internal `canonical` key, so it
 * never writes raw `_yoast_wpseo_canonical` post meta. The URL is sanitized with
 * `esc_url_raw()` before it reaches the facade. An empty string clears the override
 * (Yoast deletes the row, so the post falls back to its own permalink).
 *
 * High blast radius: a canonical pointing at another URL tells search engines this
 * page is a duplicate of that URL, bleeding its link equity away — and the change is
 * invisible until someone re-inspects the rendered canonical tag. To make the change
 * auditable, the result returns the canonical value BEFORE and AFTER this write as a
 * `canonical` old→new block, mirroring how the Contact Form 7 add-on surfaces a
 * rerouted mail recipient in its result.
 *
 * Because Yoast's setter returns no success signal, this re-reads the field after
 * writing and returns a typed error if the stored value does not match what was sent.
 *
 * This is an advanced post field, so the permission check honors Yoast's advanced-meta
 * gate (object-level `edit_post` AND, when `disableadvanced_meta` is on, the
 * `wpseo_edit_advanced_metadata` OR `wpseo_manage_options` cap).
 *
 * This is a {@see ConditionalAbility} gated on Yoast SEO being active, so it does not
 * register when Yoast is off.
 *
 * @since 0.5.0
 */
final class UpdatePostCanonical implements ConditionalAbility {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-yoast/update-post-canonical';
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
			'label'               => __( 'Set post canonical URL', 'abilities-catalog-yoast' ),
			'description'         => __( 'Sets the canonical URL Yoast SEO emits for one post (the rel="canonical" link). HIGH IMPACT: a canonical pointing at another URL tells search engines this page is a duplicate of that URL and transfers its ranking signals away, which is invisible until the canonical tag is re-inspected. Send an empty string to clear the override so the post uses its own permalink. The result returns the canonical value before and after the change for review. Discover post IDs with content/list-posts.', 'abilities-catalog-yoast' ),
			'category'            => 'og-yoast',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'post_id' ),
				'properties'           => array(
					'post_id'   => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'The post ID to set the canonical URL on. Discover IDs with content/list-posts.', 'abilities-catalog-yoast' ),
					),
					'canonical' => array(
						'type'        => 'string',
						'description' => __( 'The canonical URL to emit for this post. Send an empty string to clear the override and fall back to the post permalink.', 'abilities-catalog-yoast' ),
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
	 * Authorizes the write: Yoast active, object-level `edit_post`, and the
	 * advanced-meta gate.
	 *
	 * The canonical is an advanced per-post field. Yoast suppresses the advanced
	 * metabox tab unless the user can edit advanced metadata when the
	 * `disableadvanced_meta` restriction is on (its default). Yoast treats
	 * `wpseo_manage_options` as also granting advanced edit
	 * (research-findings §8, `capability-utils.php:20-26`), so a default
	 * administrator — who holds `wpseo_manage_options` but not the raw
	 * `wpseo_edit_advanced_metadata` cap — must still pass. A check of only
	 * `wpseo_edit_advanced_metadata` would wrongly block that admin.
	 *
	 * @param array<string,mixed> $input The validated input. Expects `post_id`.
	 * @return bool True when the caller may set this post's canonical URL.
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
	 * Sets the post's canonical URL and returns the updated SEO row plus the
	 * old→new canonical block.
	 *
	 * Reads the current canonical first (for the transparency block and to surface a
	 * missing-post 404), sanitizes the input with `esc_url_raw()`, writes it through
	 * the facade, then re-reads to confirm the write stuck before shaping the result.
	 *
	 * @param array<string,mixed> $input The validated input. Expects `post_id`; optional `canonical`.
	 * @return array<string,mixed>|\WP_Error The updated curated SEO row with the canonical old→new block, or a typed error.
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

		// Snapshot the canonical before the write for the old→new transparency block.
		$from = YoastPlugin::getPostMetaValue( 'canonical', $post_id );

		// Sanitize the URL before it reaches Yoast. An empty string clears the override.
		$canonical = esc_url_raw( (string) ( $input['canonical'] ?? '' ) );

		YoastPlugin::setPostMetaValue( 'canonical', $canonical, $post_id );

		// Yoast's setter has no success signal; re-read to confirm the write stuck.
		$to = YoastPlugin::getPostMetaValue( 'canonical', $post_id );
		if ( $to !== $canonical ) {
			return new WP_Error(
				'yoast_post_seo_write_failed',
				sprintf(
					/* translators: %d: post ID. */
					__( 'The canonical URL for post %d did not store as sent. Re-read it with og-yoast/get-post-seo and retry.', 'abilities-catalog-yoast' ),
					$post_id
				),
				array( 'status' => 500 )
			);
		}

		$result              = YoastFieldShaper::curatedPostSeoRow( $post_id );
		$result['canonical'] = array(
			'from' => $from,
			'to'   => $to,
		);

		return $result;
	}

	/**
	 * The output schema: the curated SEO row plus the canonical old→new block.
	 *
	 * Matches `og-yoast/get-post-seo`'s curated object (social keys included as
	 * optional, present only when their flag is on) and adds a `canonical` object
	 * carrying the value before and after this write.
	 *
	 * @return array<string,mixed> The closed output schema.
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
					'description' => __( 'The SEO title template. Empty means the site default is used.', 'abilities-catalog-yoast' ),
				),
				'meta_description'      => array(
					'type'        => 'string',
					'description' => __( 'The meta description. Empty means the site default is used.', 'abilities-catalog-yoast' ),
				),
				'canonical'             => array(
					'type'                 => 'object',
					'description'          => __( 'The canonical URL before and after this write.', 'abilities-catalog-yoast' ),
					'properties'           => array(
						'from' => array(
							'type'        => 'string',
							'description' => __( 'The canonical URL before this write. Empty means none was set.', 'abilities-catalog-yoast' ),
						),
						'to'   => array(
							'type'        => 'string',
							'description' => __( 'The canonical URL now stored (esc_url_raw-sanitized). Empty means the override was cleared.', 'abilities-catalog-yoast' ),
						),
					),
					'additionalProperties' => false,
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
