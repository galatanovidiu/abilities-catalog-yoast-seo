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
 * Updates the site search-appearance settings.
 *
 * Writes one or more keys of the `search_appearance` concern in Yoast's
 * `wpseo_titles` option (research-findings §6.2,
 * `class-wpseo-option-titles.php:33-54,152-167,312-359`): the title separator,
 * the force-rewrite-title flag, the homepage / author / archive / search / 404
 * title and meta-description templates, the RSS before/after strings, and the
 * per-post-type and per-taxonomy title, meta-description, and schema templates.
 * Send only the keys you want to change.
 *
 * Every key is written through {@see YoastPlugin::setOption()} (Yoast's
 * `WPSEO_Options::set()`), so Yoast's per-group `validate()` runs on every write.
 * This is a deny-by-default allow-list: a key is written only when it is one of
 * the static `search_appearance` keys, or matches a per-type/per-taxonomy prefix
 * for a registered public post type or taxonomy. Any other key is rejected with a
 * typed error before any write. The static keys are listed in the input schema;
 * the dynamic prefix keys cannot be enumerated statically, so the schema also
 * accepts a `per_type_templates` map and the runtime allow-list validates each of
 * its keys against the registered public objects.
 *
 * `WPSEO_Options::set()` returns no reliable success signal (it returns `null`
 * for an unknown key after writing only the in-memory cache — research-findings
 * §6.1, §12.2), so after each write the ability re-reads the group through
 * {@see YoastPlugin::getOptionGroup()} and returns a typed error if the value did
 * not stick. The result is the updated curated search-appearance object in the
 * same shape and key order as `og-yoast/get-search-appearance` so the caller sees
 * what was stored.
 *
 * These are templates, identity strings, and the title separator — low blast
 * radius, no de-indexing or crawl toggles — so this is a safe write
 * (`destructive=false`, no `dangerous`, no old→new transparency block). It is a
 * {@see ConditionalAbility} gated on Yoast SEO being active.
 *
 * @since 0.7.0
 */
final class UpdateSearchAppearance implements ConditionalAbility {

	/**
	 * The static search-appearance keys (research-findings §6.2).
	 *
	 * The non-prefixed `wpseo_titles` keys of the `search_appearance` concern.
	 * The per-type / per-taxonomy template keys are dynamic and validated at
	 * runtime, so they are not listed here.
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
	 * The Yoast option group the search-appearance keys live in.
	 *
	 * @var string
	 */
	private const GROUP = 'wpseo_titles';

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-yoast/update-search-appearance';
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
			'label'               => __( 'Update search appearance settings', 'abilities-catalog-yoast' ),
			'description'         => __( 'Updates the site search-appearance settings: the title separator, the force-rewrite-title flag, the homepage, author, archive, search, and 404 title and meta-description templates, the RSS before/after strings, and the per-post-type and per-taxonomy title, meta-description, and schema templates. Send only the keys you want to change; the rest are left as they are. Templates may contain replacement variables (e.g. %%title%%). Per-type and per-taxonomy templates go under per_type_templates, keyed by the full Yoast key (e.g. "title-post", "metadesc-tax-category", "schema-page-type-page"). Returns the updated settings. Read the current settings first with get-search-appearance.', 'abilities-catalog-yoast' ),
			'category'            => 'og-yoast',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'separator'              => array(
						'type'        => 'string',
						'description' => __( 'The title separator, as the slug Yoast stores (e.g. "sc-dash"), not the rendered character.', 'abilities-catalog-yoast' ),
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
						'description'          => __( 'Per-post-type and per-taxonomy templates to set, keyed by the full Yoast key (e.g. "title-post", "metadesc-tax-category", "schema-page-type-page"). Each key must belong to a registered public post type or taxonomy; an unknown key is rejected.', 'abilities-catalog-yoast' ),
						'additionalProperties' => array( 'type' => 'string' ),
					),
				),
				'additionalProperties' => false,
				'default'              => (object) array(),
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
	 * Authorizes the write: Yoast active and the caller may manage Yoast options.
	 *
	 * Search-appearance settings are site-global, so there is no object-level
	 * check and no advanced-cap gate (that gate guards only per-post / per-term
	 * advanced writes — research-findings §8). The capability is Yoast's own
	 * settings capability `wpseo_manage_options` — the same cap that gates the live
	 * settings page — never the generic `manage_options`.
	 *
	 * @param array<string,mixed> $input The validated input.
	 * @return bool True when Yoast is active and the caller may manage Yoast options.
	 */
	public function hasPermission( $input ): bool {
		if ( ! YoastPlugin::isActive() ) {
			return false;
		}

		return current_user_can( 'wpseo_manage_options' );
	}

	/**
	 * Writes the supplied search-appearance keys, then returns the curated object.
	 *
	 * Each supplied key is validated against the deny-by-default allow-list, written
	 * through Yoast's option store, and confirmed by re-reading the group. An
	 * out-of-list key yields a typed `og_yoast_unknown_setting_key` error; a write
	 * that does not stick yields `og_yoast_setting_write_failed`.
	 *
	 * @param array<string,mixed> $input The validated input — any subset of the allow-listed keys.
	 * @return array<string,mixed>|\WP_Error The updated curated search-appearance row, or a typed error.
	 */
	public function execute( $input ) {
		if ( ! YoastPlugin::isActive() ) {
			return YoastPlugin::unavailable();
		}

		// Flatten the static keys and the per_type_templates map into one write set
		// keyed by the full Yoast option key, with normalized scalar values.
		$writes = $this->collectWrites( $input );
		if ( $writes instanceof WP_Error ) {
			return $writes;
		}

		foreach ( $writes as $key => $value ) {
			$error = $this->writeKey( $key, $value );
			if ( $error instanceof WP_Error ) {
				return $error;
			}
		}

		return $this->curatedRow();
	}

	/**
	 * Builds the full write set from the input, enforcing the allow-list.
	 *
	 * Static keys are taken from the top-level input; per-type / per-taxonomy keys
	 * are taken from the `per_type_templates` map and accepted only when they match
	 * a prefix for a registered public post type or taxonomy. An unrecognized key
	 * in `per_type_templates` is rejected with a typed error — the load-bearing
	 * runtime guard behind the closed schema.
	 *
	 * @param array<string,mixed> $input The validated input.
	 * @return array<string,mixed>|\WP_Error The full Yoast-keyed write set, or a typed error on an unknown key.
	 */
	private function collectWrites( array $input ) {
		$writes = array();

		foreach ( self::STATIC_KEYS as $key ) {
			if ( ! array_key_exists( $key, $input ) ) {
				continue;
			}

			if ( in_array( $key, self::BOOLEAN_KEYS, true ) ) {
				$writes[ $key ] = (bool) $input[ $key ];
				continue;
			}

			$writes[ $key ] = (string) $input[ $key ];
		}

		if ( array_key_exists( 'per_type_templates', $input ) && is_array( $input['per_type_templates'] ) ) {
			$dynamic_allow_list = $this->dynamicKeyAllowList();

			foreach ( $input['per_type_templates'] as $key => $value ) {
				$key = (string) $key;

				if ( ! in_array( $key, $dynamic_allow_list, true ) ) {
					return $this->unknownKeyError( $key );
				}

				$writes[ $key ] = (string) $value;
			}
		}

		return $writes;
	}

	/**
	 * Writes one allow-listed key and confirms Yoast recognized it.
	 *
	 * `WPSEO_Options::set()` returns `null` only when the key is not in Yoast's
	 * lookup/pattern table (it wrote just the in-memory cache) — the one real write
	 * failure. A `true` return means the stored value byte-matches what was sent; a
	 * `false` return means Yoast wrote it but its per-group `validate()` normalized
	 * the value (e.g. trimmed or sanitized a template), which is success, not
	 * failure. So `null` is the only write-failed trigger; the allow-list check that
	 * ran before this is the real guard against unknown keys.
	 *
	 * @param string $key   The full Yoast option key to write.
	 * @param mixed  $value The normalized value to store.
	 * @return \WP_Error|null A typed error when the write did not stick, else null.
	 */
	private function writeKey( string $key, $value ): ?WP_Error {
		$result = YoastPlugin::setOption( $key, $value, self::GROUP );

		if ( null === $result ) {
			return $this->writeFailedError( $key );
		}

		return null;
	}

	/**
	 * Returns the updated curated search-appearance row.
	 *
	 * Built from the same `wpseo_titles` option, the same static-key list, and the
	 * same per-type/per-taxonomy scan as `og-yoast/get-search-appearance`, so the
	 * shape and key order match its read exactly.
	 *
	 * @return array<string,mixed>|\WP_Error The curated row, or a typed read error.
	 */
	private function curatedRow() {
		$option = YoastPlugin::getOptionGroup( self::GROUP );
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

		$separator_slug         = (string) ( $option['separator'] ?? '' );
		$row['separator']       = $separator_slug;
		$row['separator_glyph'] = YoastFieldShaper::separatorGlyph( $separator_slug );

		$row['per_type_templates'] = (object) $this->perTypeTemplates( $option );

		return $row;
	}

	/**
	 * The dynamic per-type / per-taxonomy keys allowed for a write.
	 *
	 * Mirrors the prefix set in research-findings §6.2 against every registered
	 * public post type and public taxonomy, so a write may target only a key Yoast
	 * itself would recognize for a registered object.
	 *
	 * @return list<string> The accepted full Yoast keys.
	 */
	private function dynamicKeyAllowList(): array {
		$keys = array();

		$post_types = get_post_types( array( 'public' => true ), 'names' );
		foreach ( $post_types as $type ) {
			$keys[] = 'title-' . $type;
			$keys[] = 'metadesc-' . $type;
			$keys[] = 'title-ptarchive-' . $type;
			$keys[] = 'metadesc-ptarchive-' . $type;
			$keys[] = 'bctitle-ptarchive-' . $type;
			$keys[] = 'schema-page-type-' . $type;
			$keys[] = 'schema-article-type-' . $type;
		}

		$taxonomies = get_taxonomies( array( 'public' => true ), 'names' );
		foreach ( $taxonomies as $taxonomy ) {
			$keys[] = 'title-tax-' . $taxonomy;
			$keys[] = 'metadesc-tax-' . $taxonomy;
		}

		return $keys;
	}

	/**
	 * Collects the stored per-post-type and per-taxonomy template keys.
	 *
	 * Mirrors `og-yoast/get-search-appearance`: iterates registered public post
	 * types and taxonomies and emits only the prefix-matched keys Yoast has stored,
	 * so the returned map reflects exactly what is stored.
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

	/**
	 * The typed error for a key outside the search-appearance allow-list.
	 *
	 * @param string $key The rejected key.
	 * @return \WP_Error A `og_yoast_unknown_setting_key` error with HTTP status 400.
	 */
	private function unknownKeyError( string $key ): WP_Error {
		return new WP_Error(
			'og_yoast_unknown_setting_key',
			sprintf(
				/* translators: %s: the rejected setting key. */
				__( 'The key "%s" is not a search-appearance setting (or names a post type / taxonomy that is not registered and public), so it was not written. Inspect the valid keys with og-yoast/get-search-appearance.', 'abilities-catalog-yoast' ),
				$key
			),
			array( 'status' => 400 )
		);
	}

	/**
	 * The typed error for a write that did not stick.
	 *
	 * @param string $key The key whose write was not confirmed on re-read.
	 * @return \WP_Error A `og_yoast_setting_write_failed` error with HTTP status 500.
	 */
	private function writeFailedError( string $key ): WP_Error {
		return new WP_Error(
			'og_yoast_setting_write_failed',
			sprintf(
				/* translators: 1: the setting key, 2: the Yoast option group. */
				__( 'Could not store the "%1$s" search-appearance setting in the "%2$s" option: the value did not persist. Re-read the settings with og-yoast/get-search-appearance and retry.', 'abilities-catalog-yoast' ),
				$key,
				self::GROUP
			),
			array( 'status' => 500 )
		);
	}

	/**
	 * The output schema — the updated curated search-appearance object.
	 *
	 * Identical in shape and key order to `og-yoast/get-search-appearance`.
	 *
	 * @return array<string,mixed> The output JSON schema.
	 */
	private function outputSchema(): array {
		return array(
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
		);
	}
}
