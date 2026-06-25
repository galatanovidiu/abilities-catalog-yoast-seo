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
 * Reads the full curated Yoast SEO meta for one post.
 *
 * Returns the focus keyphrase, SEO title, meta description, canonical, robots
 * directives, cornerstone flag, breadcrumb title, schema types, and the social
 * (Open Graph / Twitter) overrides for a single post — a flat, closed row.
 *
 * It wraps {@see \GalatanOvidiu\AbilitiesCatalogYoast\Support\YoastPlugin::getPostMetaValue()}
 * (Yoast's own `WPSEO_Meta::get_value()`) per curated field, so it never reads raw
 * `_yoast_wpseo_*` post meta itself. The robots noindex tri-state is mapped to a
 * readable label by {@see YoastFieldShaper::robotsNoindexLabel()}.
 *
 * The social fields are registered by Yoast only when the matching `wpseo_social`
 * flag is on. This read mirrors that: the `opengraph_*` keys are emitted only when
 * the `opengraph` flag is on and the `twitter_*` keys only when the `twitter` flag
 * is on — when a flag is off the keys are omitted from the result, not returned as
 * empty strings.
 *
 * This is a {@see ConditionalAbility} gated on Yoast SEO being active, so it does
 * not register when Yoast is off.
 *
 * @since 0.4.0
 */
final class GetPostSeo implements ConditionalAbility {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-yoast/get-post-seo';
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
			'label'               => __( 'Get post SEO metadata', 'abilities-catalog-yoast' ),
			'description'         => __( 'Reads the full Yoast SEO metadata stored for one post: focus keyphrase, SEO title, meta description, canonical URL, robots directives, cornerstone flag, breadcrumb title, schema types, and the social (Open Graph and X/Twitter) overrides. Social fields are returned only when their sharing options are enabled.', 'abilities-catalog-yoast' ),
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
	 * Authorizes the read: Yoast active and the caller may edit this post.
	 *
	 * Object-level `edit_post` mirrors Yoast's own REST auth on its three exposed
	 * SEO fields (research-findings §8, `class-wpseo-meta.php:301-303`). No
	 * advanced-cap gate: Yoast suppresses the advanced metabox UI, not a
	 * programmatic read.
	 *
	 * When the post does not exist, the object-level `edit_post` check can never
	 * pass (the meta cap maps to `do_not_allow`), which would collapse a missing
	 * post into a permission error. To let {@see execute()} return the typed 404
	 * instead, a missing post falls back to the general `edit_posts` cap: a caller
	 * who may edit posts at all reaches the not-found error, while an
	 * under-privileged caller is still denied without learning the post is absent.
	 *
	 * @param array<string,mixed> $input The validated input. Expects `post_id`.
	 * @return bool True when Yoast is active and the caller may read the post's SEO.
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
	 * Reads and returns the post's curated Yoast SEO meta as a flat row.
	 *
	 * @param array<string,mixed> $input The validated input. Expects `post_id`.
	 * @return array<string,mixed>|\WP_Error The flat SEO row, or a typed error.
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

		return YoastFieldShaper::curatedPostSeoRow( $post_id );
	}
}
