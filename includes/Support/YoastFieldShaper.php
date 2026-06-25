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
}
