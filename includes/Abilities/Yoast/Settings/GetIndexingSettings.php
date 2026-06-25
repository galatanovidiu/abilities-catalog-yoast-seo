<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogYoast\Abilities\Yoast\Settings;

use GalatanOvidiu\AbilitiesCatalogYoast\Contracts\ConditionalAbility;
use GalatanOvidiu\AbilitiesCatalogYoast\Support\YoastPlugin;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads Yoast SEO's site-indexing settings.
 *
 * Returns a flat, closed row describing what Yoast excludes from search: the
 * author, date, post-format, and attachment archive toggles, the author and
 * special-archive noindex flags, and which post types, post-type archives, and
 * taxonomies are de-indexed. An agent inspects this before any indexing write
 * (the dangerous `update-indexing-settings` write lives in a later batch) so it
 * can see the current exclusions first.
 *
 * The static toggles surface as named booleans. The dynamic per-type and
 * per-taxonomy `noindex-*` keys are grouped under one `per_type_noindex` map,
 * keyed by their full Yoast option key, so the schema stays closed while still
 * covering a key space that depends on the site's registered public post types
 * and taxonomies.
 *
 * All Yoast access goes through
 * {@see \GalatanOvidiu\AbilitiesCatalogYoast\Support\YoastPlugin::getOptionGroup()},
 * which reads the whole `wpseo_titles` option group; this ability curates that
 * array down to the `site_indexing` allow-list (research-findings §6.2) and never
 * names a `WPSEO_*` symbol itself. It is a
 * {@see \GalatanOvidiu\AbilitiesCatalogYoast\Contracts\ConditionalAbility} gated on
 * Yoast SEO being active, so it does not register when Yoast is off.
 *
 * @since 0.6.0
 */
final class GetIndexingSettings implements ConditionalAbility {

	/**
	 * The Yoast option group these settings live in.
	 *
	 * @var string
	 */
	private const OPTION_GROUP = 'wpseo_titles';

	/**
	 * The static `site_indexing` allow-list keys, surfaced as named booleans.
	 *
	 * The exact key list from research-findings §6.2
	 * (`class-wpseo-option-titles.php:56-63`). A key outside this list (and the
	 * prefix-matched per-type keys built in {@see execute()}) is never surfaced.
	 *
	 * @var list<string>
	 */
	private const STATIC_KEYS = array(
		'noindex-author-wpseo',
		'noindex-author-noposts-wpseo',
		'noindex-archive-wpseo',
		'disable-author',
		'disable-date',
		'disable-post_format',
		'disable-attachment',
	);

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-yoast/get-indexing-settings';
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
			'label'               => __( 'Get indexing settings', 'abilities-catalog-yoast' ),
			'description'         => __( 'Reads the site-indexing settings: which post types, post-type archives, and taxonomies are hidden from search engines, plus the author, date, post-format, and attachment archive toggles. Use this to inspect what is excluded from search before changing any indexing setting.', 'abilities-catalog-yoast' ),
			'category'            => 'og-yoast',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => (object) array(),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'properties'           => array(
					'noindex-author-wpseo'         => array(
						'type'        => 'boolean',
						'description' => __( 'Whether author archives are hidden from search engines.', 'abilities-catalog-yoast' ),
					),
					'noindex-author-noposts-wpseo' => array(
						'type'        => 'boolean',
						'description' => __( 'Whether archives for authors with no posts are hidden from search engines.', 'abilities-catalog-yoast' ),
					),
					'noindex-archive-wpseo'        => array(
						'type'        => 'boolean',
						'description' => __( 'Whether date-based archives are hidden from search engines.', 'abilities-catalog-yoast' ),
					),
					'disable-author'               => array(
						'type'        => 'boolean',
						'description' => __( 'Whether author archive pages are disabled entirely.', 'abilities-catalog-yoast' ),
					),
					'disable-date'                 => array(
						'type'        => 'boolean',
						'description' => __( 'Whether date-based archive pages are disabled entirely.', 'abilities-catalog-yoast' ),
					),
					'disable-post_format'          => array(
						'type'        => 'boolean',
						'description' => __( 'Whether post-format archive pages are disabled entirely.', 'abilities-catalog-yoast' ),
					),
					'disable-attachment'           => array(
						'type'        => 'boolean',
						'description' => __( 'Whether attachment (media) pages redirect to the file, disabling them as pages.', 'abilities-catalog-yoast' ),
					),
					'per_type_noindex'             => array(
						'type'                 => 'object',
						'description'          => __( 'The per-post-type, per-post-type-archive, and per-taxonomy noindex flags, keyed by full Yoast option key (e.g. "noindex-post", "noindex-ptarchive-post", "noindex-tax-category"). Each value is true when that object set is hidden from search engines. Only keys Yoast has stored for the site\'s registered public post types and taxonomies appear.', 'abilities-catalog-yoast' ),
						'additionalProperties' => array( 'type' => 'boolean' ),
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
	 * Authorizes the read: Yoast active and the caller may manage Yoast settings.
	 *
	 * Site-indexing settings are site-global, so there is no object-level check —
	 * the guard is Yoast's own settings capability `wpseo_manage_options`
	 * (research-findings §8; the live settings page checks the same cap). This is
	 * never `manage_options`.
	 *
	 * @param array<string,mixed> $input The validated input (no fields).
	 * @return bool True when Yoast is active and the caller may read these settings.
	 */
	public function hasPermission( $input ): bool {
		if ( ! YoastPlugin::isActive() ) {
			return false;
		}

		return current_user_can( 'wpseo_manage_options' );
	}

	/**
	 * Reads and returns the site-indexing settings as a flat, curated row.
	 *
	 * @param array<string,mixed> $input The validated input (no fields).
	 * @return array<string,mixed>|\WP_Error The flat indexing-settings row, or a typed error.
	 */
	public function execute( $input ) {
		if ( ! YoastPlugin::isActive() ) {
			return YoastPlugin::unavailable();
		}

		$option = YoastPlugin::getOptionGroup( self::OPTION_GROUP );
		if ( $option instanceof WP_Error ) {
			return $option;
		}

		$row = array();
		foreach ( self::STATIC_KEYS as $key ) {
			$row[ $key ] = (bool) ( $option[ $key ] ?? false );
		}

		$row['per_type_noindex'] = (object) $this->perTypeNoindex( $option );

		return $row;
	}

	/**
	 * Collects the per-type, per-archive, and per-taxonomy noindex flags.
	 *
	 * Iterates the site's registered public post types and public taxonomies and,
	 * for each, reads the three prefix-matched allow-list keys (research-findings
	 * §6.2): `noindex-<type>`, `noindex-ptarchive-<type>`, and `noindex-tax-<tax>`.
	 * Only keys Yoast actually stored for the group are surfaced — an absent key is
	 * skipped, not defaulted, so the map reflects what Yoast holds.
	 *
	 * @param array<string,mixed> $option The full `wpseo_titles` option array.
	 * @return array<string,bool> The noindex flags keyed by full Yoast option key.
	 */
	private function perTypeNoindex( array $option ): array {
		$map = array();

		$post_types = get_post_types( array( 'public' => true ), 'names' );
		foreach ( $post_types as $type ) {
			$this->copyFlag( $option, $map, 'noindex-' . $type );
			$this->copyFlag( $option, $map, 'noindex-ptarchive-' . $type );
		}

		$taxonomies = get_taxonomies( array( 'public' => true ), 'names' );
		foreach ( $taxonomies as $taxonomy ) {
			$this->copyFlag( $option, $map, 'noindex-tax-' . $taxonomy );
		}

		return $map;
	}

	/**
	 * Copies one stored noindex flag into the map when Yoast has it.
	 *
	 * @param array<string,mixed> $option The full `wpseo_titles` option array.
	 * @param array<string,bool>  $map    The map to populate, by reference.
	 * @param string              $key    The full Yoast option key to read.
	 * @return void
	 */
	private function copyFlag( array $option, array &$map, string $key ): void {
		if ( ! array_key_exists( $key, $option ) ) {
			return;
		}

		$map[ $key ] = (bool) $option[ $key ];
	}
}
