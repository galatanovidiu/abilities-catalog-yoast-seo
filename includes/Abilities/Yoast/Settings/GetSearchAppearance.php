<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogYoast\Abilities\Yoast\Settings;

use GalatanOvidiu\AbilitiesCatalogYoast\Contracts\ConditionalAbility;
use GalatanOvidiu\AbilitiesCatalogYoast\Support\YoastFieldShaper;
use GalatanOvidiu\AbilitiesCatalogYoast\Support\YoastPlugin;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads Yoast's search-appearance settings.
 *
 * Returns a flat, closed row of the search-appearance concern from Yoast's
 * `wpseo_titles` option, curated to that concern's allow-list (research-findings
 * §6.2, `class-wpseo-option-titles.php:33-54,152-167,312-359`): the title
 * separator, the force-rewrite-title flag, the homepage / author / archive /
 * search / 404 title and meta-description templates, and the RSS before/after
 * strings. The per-post-type and per-taxonomy title / meta-description / schema
 * templates live under one `per_type_templates` map keyed by the full Yoast key,
 * so the schema stays closed (`additionalProperties => false`) while still
 * covering a dynamic key space.
 *
 * The separator is stored as a slug (e.g. `sc-dash`), not the glyph it renders.
 * This surfaces both: `separator` (the raw slug Yoast stored) and
 * `separator_glyph` (the resolved display character, via
 * {@see YoastFieldShaper::separatorGlyph()}, empty when the slug is unknown).
 *
 * All Yoast access goes through {@see YoastPlugin::getOptionGroup()}; the ability
 * never names a `WPSEO_*` symbol itself. It is a {@see ConditionalAbility} gated
 * on Yoast SEO being active, so it does not register when Yoast is off.
 *
 * @since 0.6.0
 */
final class GetSearchAppearance implements ConditionalAbility {

	/**
	 * The static search-appearance keys, surfaced as named scalar properties.
	 *
	 * These are the non-prefixed `wpseo_titles` keys of the `search_appearance`
	 * concern (research-findings §6.2). The per-type/per-taxonomy template keys
	 * are resolved dynamically in {@see execute()} and grouped under
	 * `per_type_templates`, so they are not listed here.
	 *
	 * @var list<string>
	 */
	private const STATIC_KEYS = array(
		'separator',
		'forcerewritetitle',
		'title-home-wpseo',
		'title-author-wpseo',
		'title-archive-wpseo',
		'title-search-wpseo',
		'title-404-wpseo',
		'metadesc-home-wpseo',
		'metadesc-author-wpseo',
		'metadesc-archive-wpseo',
		'rssbefore',
		'rssafter',
	);

	/**
	 * The static keys that store a boolean flag rather than a string template.
	 *
	 * @var list<string>
	 */
	private const BOOLEAN_KEYS = array(
		'forcerewritetitle',
	);

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-yoast/get-search-appearance';
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
			'label'               => __( 'Get search appearance settings', 'abilities-catalog-yoast' ),
			'description'         => __( 'Reads the site search-appearance settings: the title separator, the force-rewrite-title flag, the homepage, author, archive, search, and 404 title and meta-description templates, the RSS before/after strings, and the per-post-type and per-taxonomy title, meta-description, and schema templates. Templates may contain replacement variables (e.g. %%title%%). Inspect these before changing search-appearance settings.', 'abilities-catalog-yoast' ),
			'category'            => 'og-yoast',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => (object) array(),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'properties'           => array(
					'separator'              => array(
						'type'        => 'string',
						'description' => __( 'The title separator, as the slug Yoast stores (e.g. "sc-dash"), not the rendered character.', 'abilities-catalog-yoast' ),
					),
					'separator_glyph'        => array(
						'type'        => 'string',
						'description' => __( 'The rendered separator character resolved from the slug (e.g. "-"). Empty when the slug is not a known Yoast separator.', 'abilities-catalog-yoast' ),
					),
					'forcerewritetitle'      => array(
						'type'        => 'boolean',
						'description' => __( 'Whether Yoast force-rewrites the document title tag.', 'abilities-catalog-yoast' ),
					),
					'title-home-wpseo'       => array(
						'type'        => 'string',
						'description' => __( 'The homepage title template.', 'abilities-catalog-yoast' ),
					),
					'title-author-wpseo'     => array(
						'type'        => 'string',
						'description' => __( 'The author archive title template.', 'abilities-catalog-yoast' ),
					),
					'title-archive-wpseo'    => array(
						'type'        => 'string',
						'description' => __( 'The date archive title template.', 'abilities-catalog-yoast' ),
					),
					'title-search-wpseo'     => array(
						'type'        => 'string',
						'description' => __( 'The search results title template.', 'abilities-catalog-yoast' ),
					),
					'title-404-wpseo'        => array(
						'type'        => 'string',
						'description' => __( 'The 404 page title template.', 'abilities-catalog-yoast' ),
					),
					'metadesc-home-wpseo'    => array(
						'type'        => 'string',
						'description' => __( 'The homepage meta-description template.', 'abilities-catalog-yoast' ),
					),
					'metadesc-author-wpseo'  => array(
						'type'        => 'string',
						'description' => __( 'The author archive meta-description template.', 'abilities-catalog-yoast' ),
					),
					'metadesc-archive-wpseo' => array(
						'type'        => 'string',
						'description' => __( 'The date archive meta-description template.', 'abilities-catalog-yoast' ),
					),
					'rssbefore'              => array(
						'type'        => 'string',
						'description' => __( 'Content Yoast prepends to each post in the RSS feed.', 'abilities-catalog-yoast' ),
					),
					'rssafter'               => array(
						'type'        => 'string',
						'description' => __( 'Content Yoast appends to each post in the RSS feed.', 'abilities-catalog-yoast' ),
					),
					'per_type_templates'     => array(
						'type'                 => 'object',
						'description'          => __( 'Per-post-type and per-taxonomy templates, keyed by the full Yoast key (e.g. "title-post", "metadesc-tax-category", "schema-page-type-page"). Only keys Yoast has stored for a registered public type or taxonomy appear.', 'abilities-catalog-yoast' ),
						'additionalProperties' => array( 'type' => 'string' ),
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
	 * Authorizes the read: Yoast active and the caller may manage Yoast options.
	 *
	 * Search-appearance settings are site-global, so there is no object-level
	 * check. The capability is Yoast's own settings capability
	 * `wpseo_manage_options` — the same cap that gates the live settings page
	 * (research-findings §8) — never the generic `manage_options`.
	 *
	 * @param array<string,mixed> $input The validated input (none for this read).
	 * @return bool True when Yoast is active and the caller may manage Yoast options.
	 */
	public function hasPermission( $input ): bool {
		if ( ! YoastPlugin::isActive() ) {
			return false;
		}

		return current_user_can( 'wpseo_manage_options' );
	}

	/**
	 * Reads and returns the curated search-appearance row.
	 *
	 * @param array<string,mixed> $input The validated input (none for this read).
	 * @return array<string,mixed>|\WP_Error The flat curated row, or a typed error.
	 */
	public function execute( $input ) {
		if ( ! YoastPlugin::isActive() ) {
			return YoastPlugin::unavailable();
		}

		$option = YoastPlugin::getOptionGroup( 'wpseo_titles' );
		if ( $option instanceof WP_Error ) {
			return $option;
		}

		$row = array();
		foreach ( self::STATIC_KEYS as $key ) {
			if ( in_array( $key, self::BOOLEAN_KEYS, true ) ) {
				$row[ $key ] = (bool) ( $option[ $key ] ?? false );
				continue;
			}
			$row[ $key ] = (string) ( $option[ $key ] ?? '' );
		}

		// Surface the separator both as the stored slug and the resolved glyph.
		$separator_slug         = (string) ( $option['separator'] ?? '' );
		$row['separator']       = $separator_slug;
		$row['separator_glyph'] = YoastFieldShaper::separatorGlyph( $separator_slug );

		$row['per_type_templates'] = (object) $this->perTypeTemplates( $option );

		return $row;
	}

	/**
	 * Collects the per-post-type and per-taxonomy template keys Yoast has stored.
	 *
	 * Iterates the registered public post types and public taxonomies and, for
	 * each, reads only the prefix-matched keys that exist in the option array
	 * (research-findings §6.2). An absent key is skipped, not emitted as an empty
	 * string, so the map reflects exactly what Yoast stored.
	 *
	 * @param array<string,mixed> $option The full `wpseo_titles` option array.
	 * @return array<string,string> The per-type/per-taxonomy templates, keyed by full Yoast key.
	 */
	private function perTypeTemplates( array $option ): array {
		$map = array();

		$post_types = get_post_types( array( 'public' => true ), 'names' );
		foreach ( $post_types as $type ) {
			$keys = array(
				'title-' . $type,
				'metadesc-' . $type,
				'title-ptarchive-' . $type,
				'metadesc-ptarchive-' . $type,
				'bctitle-ptarchive-' . $type,
				'schema-page-type-' . $type,
				'schema-article-type-' . $type,
			);
			foreach ( $keys as $key ) {
				if ( ! array_key_exists( $key, $option ) ) {
					continue;
				}

				$map[ $key ] = (string) $option[ $key ];
			}
		}

		$taxonomies = get_taxonomies( array( 'public' => true ), 'names' );
		foreach ( $taxonomies as $taxonomy ) {
			$keys = array(
				'title-tax-' . $taxonomy,
				'metadesc-tax-' . $taxonomy,
			);
			foreach ( $keys as $key ) {
				if ( ! array_key_exists( $key, $option ) ) {
					continue;
				}

				$map[ $key ] = (string) $option[ $key ];
			}
		}

		return $map;
	}
}
