<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogYoast\Abilities\Yoast\Author;

use GalatanOvidiu\AbilitiesCatalogYoast\Contracts\ConditionalAbility;
use GalatanOvidiu\AbilitiesCatalogYoast\Support\YoastPlugin;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Write ability: `og-yoast/update-author-noindex`.
 *
 * Sets or clears the per-author noindex flag — when on, the author's archive page
 * is removed from search engines. The flag is the plain `wpseo_noindex_author`
 * WordPress user-meta (core, not a Yoast symbol): `true` stores the string `'on'`
 * via `update_user_meta`, `false` deletes the row via `delete_user_meta`. Yoast's
 * own save path deletes rather than stores an empty value because the field's
 * `is_empty_allowed` is false (research-findings §5.2,
 * `noindex-author.php:82-84`), so the clear path mirrors that as a delete.
 *
 * HIGH IMPACT: storing `'on'` flows author user-meta → Yoast's author indexable
 * builder → `is_public = false`, silently removing the author archive URL from
 * search engines, invisible until someone re-inspects the robots tag
 * (research-findings §5, §9.1). It is the per-author analogue of the Contact Form
 * 7 mail-redirect. It stays low-tier (one URL, reversible by clearing) but is
 * flagged: the result returns the noindex value BEFORE and AFTER this write as an
 * old→new block so the change is auditable, mirroring how the Contact Form 7
 * add-on surfaces a rerouted mail recipient.
 *
 * When author archives are disabled (Yoast's `disable-author` option), Yoast
 * neither renders nor applies the per-author fields and this write is moot
 * (research-findings §5.3). Rather than silently writing a no-op, the result
 * surfaces `author_archives_enabled` so the moot state is visible to the consumer.
 *
 * The author keys are plain user-meta, but this is a {@see ConditionalAbility}
 * gated on Yoast SEO being active so the add-on degrades the same way the rest of
 * the catalog does — it does not register when Yoast is off.
 *
 * @since 0.7.0
 */
final class UpdateAuthorNoindex implements ConditionalAbility {

	/**
	 * The user-meta key Yoast stores the per-author noindex flag under.
	 *
	 * Stored under `wpseo_noindex_author` (research-findings §5.1,
	 * `noindex-author.php:44-46`); value `'on'` means noindex, absence means index.
	 *
	 * @var string
	 */
	private const META_KEY = 'wpseo_noindex_author';

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-yoast/update-author-noindex';
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
			'label'               => __( 'Set author noindex', 'abilities-catalog-yoast' ),
			'description'         => __( 'Sets or clears the per-author noindex flag. HIGH IMPACT: setting it on silently removes this author\'s archive page from search engines — it stays invisible until the robots tag is re-inspected. It is reversible by setting it off, which re-indexes the archive. The result returns the value before and after the change for review. When author archives are disabled site-wide the write is moot; the result surfaces author_archives_enabled so that state is visible.', 'abilities-catalog-yoast' ),
			'category'            => 'og-yoast',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'author_id', 'noindex' ),
				'properties'           => array(
					'author_id' => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'The author (user) ID. Discover IDs with users/list-users.', 'abilities-catalog-yoast' ),
					),
					'noindex'   => array(
						'type'        => 'boolean',
						'description' => __( 'true removes this author archive from search engines; false re-indexes it.', 'abilities-catalog-yoast' ),
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'properties'           => array(
					'author_id'               => array(
						'type'        => 'integer',
						'description' => __( 'The author (user) ID this directive belongs to.', 'abilities-catalog-yoast' ),
					),
					'noindex'                 => array(
						'type'                 => 'object',
						'description'          => __( 'The noindex flag before and after this write.', 'abilities-catalog-yoast' ),
						'properties'           => array(
							'from' => array(
								'type'        => 'boolean',
								'description' => __( 'The flag before this write.', 'abilities-catalog-yoast' ),
							),
							'to'   => array(
								'type'        => 'boolean',
								'description' => __( 'The flag now stored.', 'abilities-catalog-yoast' ),
							),
						),
						'additionalProperties' => false,
					),
					'author_archives_enabled' => array(
						'type'        => 'boolean',
						'description' => __( 'Whether author archives are enabled site-wide. When false, this write is moot — Yoast neither renders nor applies the per-author noindex.', 'abilities-catalog-yoast' ),
					),
				),
				'additionalProperties' => false,
			),
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
	 * Authorizes the write: Yoast active and object-level edit-user on the author.
	 *
	 * The hard guard is object-level `current_user_can('edit_user', $author_id)` —
	 * stricter than Yoast's UI-level `edit_users` and covering self-edit and editing
	 * another author (research-findings §5.3, §8). There is no advanced-cap gate:
	 * the per-author fields are not behind Yoast's advanced metabox tab.
	 *
	 * A missing author can never pass the object-level `edit_user` check
	 * (`map_meta_cap` returns `do_not_allow`), which would collapse a bad id into a
	 * permission error and hide the typed 404. So when the user does not exist, fall
	 * back to the general `edit_users` cap, letting a privileged caller reach the
	 * `author_not_found` error in execute() while an under-privileged caller is still
	 * denied (CHECKLIST §12).
	 *
	 * @param array<string,mixed> $input The validated input. Expects `author_id`.
	 * @return bool True when the caller may set this author's noindex flag.
	 */
	public function hasPermission( $input ): bool {
		if ( ! YoastPlugin::isActive() ) {
			return false;
		}

		$input     = is_array( $input ) ? $input : array();
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
	 * Sets or clears the author noindex flag and returns the old→new transparency block.
	 *
	 * Reads the current flag first (for the transparency block and to surface a
	 * missing-author 404), writes the requested state (store `'on'` when true, delete
	 * the row when false), then surfaces whether author archives are enabled so a moot
	 * write is visible.
	 *
	 * @param array<string,mixed> $input The validated input. Expects `author_id`, `noindex`.
	 * @return object|\WP_Error The author_id, the noindex old→new block, and author_archives_enabled, or a typed error.
	 */
	public function execute( $input ) {
		if ( ! YoastPlugin::isActive() ) {
			return YoastPlugin::unavailable();
		}

		$input     = is_array( $input ) ? $input : array();
		$author_id = absint( $input['author_id'] ?? 0 );
		$noindex   = (bool) ( $input['noindex'] ?? false );

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

		// Snapshot the current flag BEFORE the write so the result is auditable; Yoast's
		// frontend treats strictly 'on' as noindex (research-findings §5.1).
		$from = 'on' === get_user_meta( $author_id, self::META_KEY, true );

		if ( $noindex ) {
			update_user_meta( $author_id, self::META_KEY, 'on' );
		} else {
			// is_empty_allowed is false for this field, so clearing is a delete, not an
			// empty write (research-findings §5.2).
			delete_user_meta( $author_id, self::META_KEY );
		}

		return (object) array(
			'author_id'               => $author_id,
			'noindex'                 => (object) array(
				'from' => $from,
				'to'   => $noindex,
			),
			'author_archives_enabled' => YoastPlugin::authorArchivesEnabled(),
		);
	}
}
