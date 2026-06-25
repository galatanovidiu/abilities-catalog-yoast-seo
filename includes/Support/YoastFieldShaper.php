<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogYoast\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pure value transforms for Yoast SEO field data.
 *
 * Yoast stores several fields as terse internal codes (a tri-state `'0'`/`'1'`/`'2'`
 * for robots noindex, separator slugs, and so on). This utility maps those codes to
 * the human-readable labels an ability surfaces in its result, so the mapping lives
 * in one tested place instead of being inlined per ability.
 *
 * It holds no Yoast symbols — only literal value maps — so it is safe to call whether
 * or not Yoast is active. Yoast access stays in {@see YoastPlugin}.
 *
 * @since 0.1.0
 */
final class YoastFieldShaper {

	/**
	 * Labels the per-post/term robots-noindex tri-state code.
	 *
	 * Yoast stores `_yoast_wpseo_meta-robots-noindex` as a three-value string:
	 * `'0'` inherits the post type's default, `'1'` forces noindex, `'2'` forces
	 * index. An unknown code is reported as `default`, the safe inherit value.
	 *
	 * @param string $tri The stored tri-state code (`'0'`, `'1'`, or `'2'`).
	 * @return string The label: `default`, `noindex`, or `index`.
	 */
	public static function robotsNoindexLabel( string $tri ): string {
		switch ( $tri ) {
			case '1':
				return 'noindex';
			case '2':
				return 'index';
			default:
				return 'default';
		}
	}

	/**
	 * Reads one post's full curated Yoast SEO row, the single source of the shape.
	 *
	 * The `og-yoast/get-post-seo` read and every per-post write return the IDENTICAL
	 * flat row — focus keyphrase, SEO title, meta description, canonical, robots
	 * directives, cornerstone flag, breadcrumb title, schema types, and the social
	 * (Open Graph / X-Twitter) overrides. Building it in one place keeps the read and
	 * the writes from drifting apart and avoids each ability duplicating the field
	 * list (the shaper the batch-03 spec alignment note calls for).
	 *
	 * Every field is read through {@see YoastPlugin::getPostMetaValue()} so the row
	 * reflects the stored state. The robots tri-state is resolved to its readable
	 * label, and the social keys are emitted only when their `wpseo_social` flag is
	 * on — exactly mirroring how Yoast registers those fields. Schema-type fields stay
	 * `''` when unset, meaning "use the post-type default" (not resolved here).
	 *
	 * @param int $post_id The post ID to read. The caller validates it exists.
	 * @return array<string,mixed> The flat curated SEO row, in canonical key order.
	 */
	public static function curatedPostSeoRow( int $post_id ): array {
		$result = array(
			'post_id'             => $post_id,
			'focus_keyphrase'     => YoastPlugin::getPostMetaValue( 'focuskw', $post_id ),
			'seo_title'           => YoastPlugin::getPostMetaValue( 'title', $post_id ),
			'meta_description'    => YoastPlugin::getPostMetaValue( 'metadesc', $post_id ),
			'canonical'           => YoastPlugin::getPostMetaValue( 'canonical', $post_id ),
			'robots_noindex'      => self::robotsNoindexLabel( YoastPlugin::getPostMetaValue( 'meta-robots-noindex', $post_id ) ),
			'robots_nofollow'     => '1' === YoastPlugin::getPostMetaValue( 'meta-robots-nofollow', $post_id ) ? 'nofollow' : 'follow',
			'robots_advanced'     => self::splitAdvancedRobots( YoastPlugin::getPostMetaValue( 'meta-robots-adv', $post_id ) ),
			'is_cornerstone'      => '1' === YoastPlugin::getPostMetaValue( 'is_cornerstone', $post_id ),
			'breadcrumb_title'    => YoastPlugin::getPostMetaValue( 'bctitle', $post_id ),
			'schema_page_type'    => YoastPlugin::getPostMetaValue( 'schema_page_type', $post_id ),
			'schema_article_type' => YoastPlugin::getPostMetaValue( 'schema_article_type', $post_id ),
		);

		if ( (bool) YoastPlugin::getOption( 'opengraph', false, 'wpseo_social' ) ) {
			$result['opengraph_title']       = YoastPlugin::getPostMetaValue( 'opengraph-title', $post_id );
			$result['opengraph_description'] = YoastPlugin::getPostMetaValue( 'opengraph-description', $post_id );
			$result['opengraph_image']       = YoastPlugin::getPostMetaValue( 'opengraph-image', $post_id );
			$result['opengraph_image_id']    = YoastPlugin::getPostMetaValue( 'opengraph-image-id', $post_id );
		}

		if ( (bool) YoastPlugin::getOption( 'twitter', false, 'wpseo_social' ) ) {
			$result['twitter_title']       = YoastPlugin::getPostMetaValue( 'twitter-title', $post_id );
			$result['twitter_description'] = YoastPlugin::getPostMetaValue( 'twitter-description', $post_id );
			$result['twitter_image']       = YoastPlugin::getPostMetaValue( 'twitter-image', $post_id );
			$result['twitter_image_id']    = YoastPlugin::getPostMetaValue( 'twitter-image-id', $post_id );
		}

		return $result;
	}

	/**
	 * Splits the stored advanced-robots CSV into a list of known directive tokens.
	 *
	 * Yoast free stores only `noimageindex`, `noarchive`, and `nosnippet` in
	 * `meta-robots-adv` (research-findings §3.1, `class-wpseo-meta.php:158-166`); any
	 * other token is dropped. An empty stored value yields an empty list.
	 *
	 * @param string $csv The stored `meta-robots-adv` CSV value.
	 * @return list<string> The directive tokens, empty when none are set.
	 */
	public static function splitAdvancedRobots( string $csv ): array {
		$csv = trim( $csv );
		if ( '' === $csv ) {
			return array();
		}

		return array_values(
			array_filter(
				array_map( 'trim', explode( ',', $csv ) ),
				static function ( string $token ): bool {
					return in_array( $token, array( 'noimageindex', 'noarchive', 'nosnippet' ), true );
				}
			)
		);
	}
}
