<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogYoast\Support;

use WPSEO_Meta;
use WPSEO_Options;
use WPSEO_Rank;
use WPSEO_Taxonomy_Meta;
use WP_Error;
use Yoast\WP\SEO\Config\Schema_Types;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The add-on's only gateway to Yoast SEO symbols.
 *
 * Yoast SEO is an optional third-party dependency that may be inactive. Every Yoast
 * symbol the add-on touches passes through this facade, so the rest of the code never
 * references a `WPSEO_*` class or the `YoastSEO()` container directly. That keeps two
 * concerns in one place:
 *
 * 1. The availability guard. {@see isActive()} is the single source of truth for
 *    "Yoast SEO is installed and enabled at a supported version". The Yoast abilities
 *    are {@see \GalatanOvidiu\AbilitiesCatalogYoast\Contracts\ConditionalAbility}s gated
 *    on it, so they do not register when Yoast is off; the abilities and the MCP
 *    integration also call the helpers below only after confirming it.
 * 2. The Yoast reads/writes the core REST API does not expose. Per-post SEO meta and
 *    the numeric-score-to-rank translation come straight off Yoast's own PHP API, so
 *    an ability never names a `WPSEO_*` symbol itself.
 *
 * This is NOT under `includes/Abilities/`, so the Registry never treats it as an
 * ability.
 *
 * @since 0.1.0
 */
final class YoastPlugin {

	/**
	 * The minimum Yoast SEO version the add-on supports.
	 *
	 * @var string
	 */
	private const MIN_VERSION = '24.0';

	/**
	 * Whether Yoast SEO is installed, active, and at a supported version.
	 *
	 * Detects Yoast by the symbols its main init loads (`wpseo_init` on
	 * `plugins_loaded:14`), so this is safe to call whether or not Yoast is active.
	 * It is the gate every Yoast ability and helper checks before touching a Yoast
	 * symbol.
	 *
	 * @return bool True when Yoast's API is loaded at version {@see MIN_VERSION} or newer.
	 */
	public static function isActive(): bool {
		return defined( 'WPSEO_VERSION' )
			&& class_exists( 'WPSEO_Meta' )
			&& class_exists( 'WPSEO_Options' )
			&& class_exists( 'WPSEO_Taxonomy_Meta' )
			&& version_compare( (string) WPSEO_VERSION, self::MIN_VERSION, '>=' );
	}

	/**
	 * The typed error an ability returns if asked to run while Yoast is inactive.
	 *
	 * The Yoast abilities do not register when Yoast is off, so the Abilities API
	 * never routes a call here. This covers the defensive path of an ability
	 * instantiated and executed directly: it returns a clear, stable error rather
	 * than touching an undefined Yoast symbol.
	 *
	 * @return \WP_Error A `yoast_inactive` error with HTTP status 409.
	 */
	public static function unavailable(): WP_Error {
		return new WP_Error(
			'yoast_inactive',
			__( 'Yoast SEO is not active.', 'abilities-catalog-yoast' ),
			array( 'status' => 409 )
		);
	}

	/**
	 * Reads one stored per-post SEO meta value through Yoast's own getter.
	 *
	 * Wraps `WPSEO_Meta::get_value()`, which returns the stored string for the
	 * `_yoast_wpseo_<key>` post meta or the field's registered default when unset.
	 * The `$key` is the internal name without the `_yoast_wpseo_` prefix (e.g.
	 * `'focuskw'`, `'metadesc'`, `'meta-robots-noindex'`).
	 *
	 * @param string $key     The internal meta key, without the `_yoast_wpseo_` prefix.
	 * @param int    $post_id The post ID to read from.
	 * @return string The stored value, or the field's default when unset.
	 */
	public static function getPostMetaValue( string $key, int $post_id ): string {
		return (string) WPSEO_Meta::get_value( $key, $post_id );
	}

	/**
	 * Translates a stored numeric SEO/readability score into Yoast's rank label.
	 *
	 * Wraps `WPSEO_Rank::from_numeric_score()`, which maps a 0–100 score onto the
	 * four ranks Yoast shows in its editor. An empty score (`''` or `'0'`, the
	 * stored default for an unanalyzed post) is reported as the `na` rank — Yoast
	 * never computes a score server-side, so a missing score means "not analyzed",
	 * not "bad".
	 *
	 * @param int|string|null $numeric_score The stored numeric score, or empty when unanalyzed.
	 * @return array{value: int, rank: string} The numeric value and its rank slug (`na`/`bad`/`ok`/`good`).
	 */
	public static function rankFromScore( $numeric_score ): array {
		if ( null === $numeric_score || '' === (string) $numeric_score || '0' === (string) $numeric_score ) {
			return array(
				'value' => 0,
				'rank'  => 'na',
			);
		}

		$value = (int) $numeric_score;
		$rank  = WPSEO_Rank::from_numeric_score( $value );

		return array(
			'value' => $value,
			'rank'  => (string) $rank->get_rank(),
		);
	}

	/**
	 * Reads one Yoast option value through Yoast's own option store.
	 *
	 * Wraps `WPSEO_Options::get()`, which resolves an option key against Yoast's
	 * registered option groups (e.g. `wpseo_social`, `wpseo_titles`) and returns the
	 * stored value or the supplied default when unset. Pass `$groups` to scope the
	 * lookup to a specific option group when a key name is not globally unique;
	 * Yoast expects an array of group names, so a single group name is wrapped here.
	 *
	 * @param string      $key           The option key to read.
	 * @param mixed       $default_value The value returned when the key is unset. Default null.
	 * @param string|null $groups        The option group to scope the lookup to, or null for all. Default null.
	 * @return mixed The stored option value, or `$default_value` when unset.
	 */
	public static function getOption( string $key, $default_value = null, ?string $groups = null ) {
		return WPSEO_Options::get( $key, $default_value, null === $groups ? array() : array( $groups ) );
	}

	/**
	 * Writes one per-post SEO meta value through Yoast's own setter.
	 *
	 * Wraps `WPSEO_Meta::set_value()`, which slashes the value and stores it as the
	 * `_yoast_wpseo_<key>` post meta after running Yoast's per-field sanitizer
	 * (enum / URL / integer / CSV rules). Writing a field's registered default
	 * deletes the row, so a field is reset by writing its default. The `$key` is the
	 * internal name without the `_yoast_wpseo_` prefix (e.g. `focuskw`, `canonical`,
	 * `meta-robots-noindex`).
	 *
	 * @param string $key     The internal meta key, without the `_yoast_wpseo_` prefix.
	 * @param mixed  $value   The value to store; Yoast sanitizes it per field.
	 * @param int    $post_id The post ID to write to.
	 * @return void
	 */
	public static function setPostMetaValue( string $key, $value, int $post_id ): void {
		WPSEO_Meta::set_value( $key, $value, $post_id );
	}

	/**
	 * Deletes one per-post SEO meta row through Yoast's own deleter.
	 *
	 * Wraps `WPSEO_Meta::delete()`. Writing a field's default through
	 * {@see setPostMetaValue()} already removes the row, so this exists for parity
	 * with the field-reset semantics; either path resets the field to its default.
	 *
	 * @param string $key     The internal meta key, without the `_yoast_wpseo_` prefix.
	 * @param int    $post_id The post ID to clear the field on.
	 * @return void
	 */
	public static function deletePostMetaValue( string $key, int $post_id ): void {
		WPSEO_Meta::delete( $key, $post_id );
	}

	/**
	 * Whether Yoast restricts who may edit the advanced per-post SEO fields.
	 *
	 * Reads the `disableadvanced_meta` option (Yoast default `true`). When true,
	 * Yoast gates the advanced metabox tab behind `wpseo_edit_advanced_metadata`
	 * (which it also grants to `wpseo_manage_options` holders), so the advanced
	 * post writes mirror that OR-logic in their permission callbacks.
	 *
	 * @return bool True when the advanced-meta restriction is on (the default).
	 */
	public static function getDisableAdvancedMeta(): bool {
		return (bool) WPSEO_Options::get( 'disableadvanced_meta', true, array( 'wpseo' ) );
	}

	/**
	 * The closed set of Schema.org page-type slugs Yoast accepts for a post.
	 *
	 * Reads the slugs straight off `Schema_Types::PAGE_TYPES` (the keys; the values
	 * are display labels), so the `update-post-schema` enum tracks Yoast's own list
	 * rather than a hardcoded copy.
	 *
	 * @return list<string> The page-type slugs (e.g. `WebPage`, `FAQPage`).
	 */
	public static function schemaPageTypes(): array {
		return array_map( 'strval', array_keys( Schema_Types::PAGE_TYPES ) );
	}

	/**
	 * The closed set of Schema.org article-type slugs Yoast accepts for a post.
	 *
	 * Reads the slugs off `Schema_Types::ARTICLE_TYPES` (the keys), including
	 * `None`, so the `update-post-schema` enum tracks Yoast's own list.
	 *
	 * @return list<string> The article-type slugs (e.g. `Article`, `NewsArticle`, `None`).
	 */
	public static function schemaArticleTypes(): array {
		return array_map( 'strval', array_keys( Schema_Types::ARTICLE_TYPES ) );
	}

	/**
	 * Reads a taxonomy term's Yoast SEO meta through Yoast's own getter.
	 *
	 * Wraps `WPSEO_Taxonomy_Meta::get_term_meta()`. Per-term SEO lives in the single
	 * `wpseo_taxonomy_meta` option, keyed by taxonomy and term, never in term meta and
	 * with no REST route — so this is the read path. With `$meta` null it returns the
	 * full merged array (the stored values merged over the per-term defaults). It
	 * returns `false` when the term cannot be resolved.
	 *
	 * @param int         $term_id  The term ID to read.
	 * @param string      $taxonomy The taxonomy the term belongs to.
	 * @param string|null $meta     A single key (without the `wpseo_` prefix) to read, or null for the whole array. Default null.
	 * @return ($meta is null ? array<string,mixed>|false : string|false) The merged meta array, a single value, or false when unresolvable.
	 */
	public static function getTermMeta( int $term_id, string $taxonomy, ?string $meta = null ) {
		return WPSEO_Taxonomy_Meta::get_term_meta( $term_id, $taxonomy, $meta );
	}

	/**
	 * Writes several Yoast SEO meta values for a taxonomy term through Yoast's setter.
	 *
	 * Wraps `WPSEO_Taxonomy_Meta::set_values()` — the same validated path Yoast's own
	 * term-metabox save uses. The `$values` keys carry the full `wpseo_` prefix (e.g.
	 * `wpseo_title`, `wpseo_focuskw`). It returns no success signal, so callers re-read
	 * with {@see getTermMeta()} to confirm the write stuck.
	 *
	 * IMPORTANT: this is NOT a partial merge. Yoast retains the old value only for a
	 * few keys; the text fields (`wpseo_title`, `wpseo_desc`, `wpseo_focuskw`,
	 * `wpseo_canonical`) RESET to their default when absent from `$values`. Yoast's own
	 * metabox always submits every field, so callers here must do the same: read the
	 * current full meta, overlay the change, and pass the complete set — never a single
	 * field on its own, or the other fields are wiped.
	 *
	 * @param int                 $term_id  The term ID to write.
	 * @param string              $taxonomy The taxonomy the term belongs to.
	 * @param array<string,mixed> $values   The complete `wpseo_*`-prefixed value set to store.
	 * @return void
	 */
	public static function setTermValues( int $term_id, string $taxonomy, array $values ): void {
		WPSEO_Taxonomy_Meta::set_values( $term_id, $taxonomy, $values );
	}

	/**
	 * Whether Yoast leaves author archive pages enabled.
	 *
	 * Reads the `disable-author` option from Yoast's `wpseo_titles` group (default
	 * `false`, i.e. archives enabled) and returns its negation, so a `true` result
	 * means author archives are live. When archives are disabled, the per-author SEO
	 * fields (title / meta description / noindex) are moot — Yoast neither renders nor
	 * applies them — so the author abilities surface this so a no-op write is visible.
	 *
	 * @return bool True when author archives are enabled (the default).
	 */
	public static function authorArchivesEnabled(): bool {
		return ! (bool) WPSEO_Options::get( 'disable-author', false, array( 'wpseo_titles' ) );
	}
}
