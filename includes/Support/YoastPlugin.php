<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogYoast\Support;

use WPSEO_Meta;
use WPSEO_Options;
use WPSEO_Rank;
use WP_Error;

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
}
