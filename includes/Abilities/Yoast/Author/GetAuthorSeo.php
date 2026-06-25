<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogYoast\Abilities\Yoast\Author;

use GalatanOvidiu\AbilitiesCatalogYoast\Contracts\ConditionalAbility;
use GalatanOvidiu\AbilitiesCatalogYoast\Support\YoastFieldShaper;
use GalatanOvidiu\AbilitiesCatalogYoast\Support\YoastPlugin;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads one author's Yoast SEO settings.
 *
 * Returns a flat, closed row for a single WordPress user: the author SEO title,
 * the meta description, the per-author noindex flag, and whether author archives
 * are enabled. The last value tells a consumer whether the other three take effect
 * — when author archives are disabled Yoast neither renders nor applies them, so a
 * write to them would be moot.
 *
 * The three SEO values are plain core user-meta (`wpseo_title`, `wpseo_metadesc`,
 * `wpseo_noindex_author`), so this reads them with core `get_user_meta()` directly
 * (research-findings §5.1) — they are not Yoast symbols. The only Yoast access is
 * `author_archives_enabled`, which goes through
 * {@see \GalatanOvidiu\AbilitiesCatalogYoast\Support\YoastPlugin::authorArchivesEnabled()}
 * so the ability never names a `WPSEO_*` symbol itself.
 *
 * The noindex flag stores the literal string `'on'` when set; Yoast's frontend
 * checks it strictly with `=== 'on'` (research-findings §5.1), so this maps the
 * stored value to a boolean the same way.
 *
 * This is a {@see ConditionalAbility} gated on Yoast SEO being active, so it does
 * not register when Yoast is off.
 *
 * @since 0.6.0
 */
final class GetAuthorSeo implements ConditionalAbility {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-yoast/get-author-seo';
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
			'label'               => __( 'Get author SEO metadata', 'abilities-catalog-yoast' ),
			'description'         => __( 'Reads one author\'s Yoast SEO settings: the SEO title, the meta description, the per-author noindex flag, and whether author archives are enabled. When author archives are disabled the SEO title, description, and noindex are moot — Yoast neither renders nor applies them.', 'abilities-catalog-yoast' ),
			'category'            => 'og-yoast',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'author_id' ),
				'properties'           => array(
					'author_id' => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'The author (user) ID. Discover IDs with users/list-users.', 'abilities-catalog-yoast' ),
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'properties'           => array(
					'author_id'               => array(
						'type'        => 'integer',
						'description' => __( 'The author (user) ID this metadata belongs to.', 'abilities-catalog-yoast' ),
					),
					'seo_title'               => array(
						'type'        => 'string',
						'description' => __( 'The author SEO title (may contain Yoast replacement variables). Empty means the site default is used.', 'abilities-catalog-yoast' ),
					),
					'meta_description'        => array(
						'type'        => 'string',
						'description' => __( 'The author meta description. Empty means the site default is used.', 'abilities-catalog-yoast' ),
					),
					'noindex'                 => array(
						'type'        => 'boolean',
						'description' => __( 'Whether this author archive is hidden from search engines.', 'abilities-catalog-yoast' ),
					),
					'author_archives_enabled' => array(
						'type'        => 'boolean',
						'description' => __( 'Whether author archive pages are enabled. When false, the SEO title, description, and noindex above are moot.', 'abilities-catalog-yoast' ),
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
	 * Authorizes the read: Yoast active and the caller may edit this user.
	 *
	 * Object-level `edit_user` is stricter and more correct than Yoast's UI-level
	 * `edit_users` — it maps to `edit_users` plus ownership, so it covers self-edit
	 * and editing another author (research-findings §5.3, §8). Author SEO fields are
	 * not behind Yoast's advanced metabox tab, so there is no advanced-cap gate.
	 *
	 * When the user does not exist, the object-level `edit_user` check can never
	 * pass (the meta cap maps to `do_not_allow`), which would collapse a missing
	 * author into a permission error. To let {@see execute()} return the typed 404
	 * instead, a missing user falls back to the general `edit_users` cap: a caller
	 * who may edit users at all reaches the not-found error, while an
	 * under-privileged caller is still denied without learning the user is absent.
	 *
	 * @param array<string,mixed> $input The validated input. Expects `author_id`.
	 * @return bool True when Yoast is active and the caller may read this author's SEO.
	 */
	public function hasPermission( $input ): bool {
		if ( ! YoastPlugin::isActive() ) {
			return false;
		}

		$author_id = (int) ( $input['author_id'] ?? 0 );
		if ( $author_id <= 0 ) {
			return false;
		}

		if ( false === get_userdata( $author_id ) ) {
			return current_user_can( 'edit_users' );
		}

		return current_user_can( 'edit_user', $author_id );
	}

	/**
	 * Reads and returns the author's Yoast SEO settings as a flat row.
	 *
	 * @param array<string,mixed> $input The validated input. Expects `author_id`.
	 * @return array<string,mixed>|\WP_Error The flat author SEO row, or a typed error.
	 */
	public function execute( $input ) {
		if ( ! YoastPlugin::isActive() ) {
			return YoastPlugin::unavailable();
		}

		$author_id = (int) ( $input['author_id'] ?? 0 );

		if ( false === get_userdata( $author_id ) ) {
			return new WP_Error(
				'author_not_found',
				sprintf(
					/* translators: %d: author (user) ID. */
					__( 'Author %d not found. List users with users/list-users.', 'abilities-catalog-yoast' ),
					$author_id
				),
				array( 'status' => 404 )
			);
		}

		return YoastFieldShaper::curatedAuthorSeoRow( $author_id );
	}
}
