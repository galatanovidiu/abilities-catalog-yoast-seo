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
 * Updates an author's Yoast SEO title and/or meta description.
 *
 * Writes the per-author SEO title and meta description for one user. Both are plain
 * WordPress user-meta (`wpseo_title` and `wpseo_metadesc`), not a Yoast PHP symbol, so
 * the write goes straight through core `update_user_meta()` with the same sanitizer
 * Yoast's own profile save uses: `sanitize_text_field()` for BOTH fields, including the
 * meta description (Yoast's user-meta integration runs `sanitize_text_field()` on the
 * metadesc textarea, not `sanitize_textarea_field()` —
 * `wordpress-seo/src/integrations/admin/custom-meta-integration.php:78`).
 *
 * Only the fields supplied in the input are written; the rest are left untouched, so
 * this is a partial update. Presence is tested with `array_key_exists()`, not
 * truthiness: an explicit empty string is a valid value that clears the field (Yoast
 * allows an empty title/metadesc), not a skip. The fields are stored under the meta
 * keys `wpseo_title` / `wpseo_metadesc`, never under the form-field ids
 * `wpseo_author_title` / `wpseo_author_metadesc` (those are POST names only).
 *
 * Low blast radius — this is snippet copy only and changes no indexing behaviour — so
 * it is a safe write (`destructive=false`) with no old→new transparency block. After
 * the write the ability re-reads every field through core `get_user_meta()` and returns
 * the full author SEO row, the same shape as `og-yoast/get-author-seo`, so the result
 * reflects the stored state. `update_user_meta()` returns `false` on an unchanged value
 * as well as on failure, so that return is ignored; the re-read is the confirmation.
 *
 * This is a {@see ConditionalAbility} gated on Yoast SEO being active. The author keys
 * are core user-meta and do not themselves need Yoast loaded, but the ability degrades
 * the same way the rest of the catalog does when Yoast is off.
 *
 * @since 0.5.0
 */
final class UpdateAuthorSeo implements ConditionalAbility {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-yoast/update-author-seo';
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
			'label'               => __( 'Update author SEO metadata', 'abilities-catalog-yoast' ),
			'description'         => __( 'Updates an author\'s Yoast SEO title and/or meta description — the snippet copy shown for the author archive. Send only the fields you want to change; omit a field to leave it unchanged, or send an empty string to clear it. Returns the full author SEO row after the write.', 'abilities-catalog-yoast' ),
			'category'            => 'og-yoast',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'author_id' ),
				'properties'           => array(
					'author_id'        => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'The author (user) ID to update. Discover IDs with users/list-users.', 'abilities-catalog-yoast' ),
					),
					'seo_title'        => array(
						'type'        => 'string',
						'description' => __( 'A new Yoast SEO title for this author. Omit to leave it unchanged. Send "" to clear it.', 'abilities-catalog-yoast' ),
					),
					'meta_description' => array(
						'type'        => 'string',
						'description' => __( 'A new meta description for this author. Omit to leave it unchanged. Send "" to clear it.', 'abilities-catalog-yoast' ),
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
				'screen'       => 'user-edit.php?user_id={author_id}',
			),
		);
	}

	/**
	 * Authorizes the write: Yoast active and the caller may edit this user.
	 *
	 * Object-level `edit_user` is stricter and more correct than Yoast's UI-level
	 * `edit_users`: it maps to `edit_users` plus ownership, so it covers both self-edit
	 * and editing another author (research-findings §5.3, §8). Author fields are not
	 * behind Yoast's advanced metabox tab, so there is no advanced-cap gate.
	 *
	 * When the user does not exist, the object-level `edit_user` check can never pass
	 * (the meta cap maps to `do_not_allow`), which would collapse a missing user into a
	 * permission error. To let {@see execute()} return the typed 404 instead, a missing
	 * user falls back to the general `edit_users` cap: a caller who may edit users at all
	 * reaches the not-found error, while an under-privileged caller is still denied
	 * without learning the user is absent.
	 *
	 * @param array<string,mixed> $input The validated input. Expects `author_id`.
	 * @return bool True when Yoast is active and the caller may edit the user.
	 */
	public function hasPermission( $input ): bool {
		if ( ! YoastPlugin::isActive() ) {
			return false;
		}

		$author_id = absint( $input['author_id'] ?? 0 );
		if ( $author_id <= 0 ) {
			return false;
		}

		if ( false === get_userdata( $author_id ) ) {
			return current_user_can( 'edit_users' );
		}

		return current_user_can( 'edit_user', $author_id );
	}

	/**
	 * Writes the supplied SEO fields, then returns the full author SEO row.
	 *
	 * Each supplied field is sanitized with `sanitize_text_field()` (matching Yoast's
	 * own save path for both the title and the meta description) and stored through
	 * core `update_user_meta()`. The fields are then re-read so the returned row
	 * reflects the stored state.
	 *
	 * @param array<string,mixed> $input The validated input. Expects `author_id` plus any field(s).
	 * @return array<string,mixed>|\WP_Error The full author SEO row, or a typed error.
	 */
	public function execute( $input ) {
		if ( ! YoastPlugin::isActive() ) {
			return YoastPlugin::unavailable();
		}

		$author_id = absint( $input['author_id'] ?? 0 );

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

		if ( array_key_exists( 'seo_title', $input ) ) {
			update_user_meta( $author_id, 'wpseo_title', sanitize_text_field( (string) $input['seo_title'] ) );
		}

		if ( array_key_exists( 'meta_description', $input ) ) {
			update_user_meta( $author_id, 'wpseo_metadesc', sanitize_text_field( (string) $input['meta_description'] ) );
		}

		return YoastFieldShaper::curatedAuthorSeoRow( $author_id );
	}

	/**
	 * The output schema — the full author SEO row, identical to get-author-seo.
	 *
	 * @return array<string,mixed> The output JSON schema.
	 */
	private function outputSchema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'author_id'               => array(
					'type'        => 'integer',
					'description' => __( 'The author (user) ID this metadata belongs to.', 'abilities-catalog-yoast' ),
				),
				'seo_title'               => array(
					'type'        => 'string',
					'description' => __( 'The Yoast SEO title for the author archive. Empty means the site default is used.', 'abilities-catalog-yoast' ),
				),
				'meta_description'        => array(
					'type'        => 'string',
					'description' => __( 'The meta description for the author archive. Empty means the site default is used.', 'abilities-catalog-yoast' ),
				),
				'noindex'                 => array(
					'type'        => 'boolean',
					'description' => __( 'Whether this author archive is hidden from search engines.', 'abilities-catalog-yoast' ),
				),
				'author_archives_enabled' => array(
					'type'        => 'boolean',
					'description' => __( 'Whether author archives are enabled site-wide. When false, these values do not take effect.', 'abilities-catalog-yoast' ),
				),
			),
			'additionalProperties' => false,
		);
	}
}
