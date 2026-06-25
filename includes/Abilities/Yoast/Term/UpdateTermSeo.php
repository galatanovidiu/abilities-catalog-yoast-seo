<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogYoast\Abilities\Yoast\Term;

use GalatanOvidiu\AbilitiesCatalogYoast\Contracts\ConditionalAbility;
use GalatanOvidiu\AbilitiesCatalogYoast\Support\YoastFieldShaper;
use GalatanOvidiu\AbilitiesCatalogYoast\Support\YoastPlugin;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Updates a taxonomy term's basic Yoast SEO fields.
 *
 * Writes the SEO title, meta description, focus keyphrase, cornerstone flag, and
 * breadcrumb title for one taxonomy term. Only the fields supplied in the input are
 * written; the rest are left untouched, so this is a partial update — send only what
 * you want to change.
 *
 * Per-term SEO lives in the single `wpseo_taxonomy_meta` option, keyed by taxonomy and
 * term, with no per-term post meta and no REST route. Every field is written through
 * Yoast's own setter
 * {@see \GalatanOvidiu\AbilitiesCatalogYoast\Support\YoastPlugin::setTermValues()}
 * (Yoast's `WPSEO_Taxonomy_Meta::set_values()`), the same validated path Yoast's own
 * term-metabox save uses, so Yoast's per-field sanitizers run on every write. The keys
 * handed to `set_values()` carry the full `wpseo_` prefix.
 *
 * `set_values()` returns no success signal, so after writing the ability re-reads the
 * term meta through {@see YoastPlugin::getTermMeta()} and returns a typed error if the
 * stored values do not reflect what was sent. The result is the full curated SEO object
 * in the same shape and key order as `og-yoast/get-term-seo`.
 *
 * This updates only the basic SEO fields (title, description, focus keyphrase,
 * cornerstone, breadcrumb title), so it carries no advanced-cap gate; the advanced
 * robots and canonical fields live in their own abilities. It is a
 * {@see ConditionalAbility} gated on Yoast SEO being active.
 *
 * @since 0.6.0
 */
final class UpdateTermSeo implements ConditionalAbility {

	/**
	 * Maps each text input field to its full `wpseo_`-prefixed stored key.
	 *
	 * `WPSEO_Taxonomy_Meta::set_values()` expects keys carrying the full `wpseo_`
	 * prefix (research-findings §4.3). The boolean `is_cornerstone` is mapped and
	 * normalized separately.
	 *
	 * @var array<string,string>
	 */
	private const FIELD_MAP = array(
		'title'            => 'wpseo_title',
		'description'      => 'wpseo_desc',
		'focus_keyphrase'  => 'wpseo_focuskw',
		'breadcrumb_title' => 'wpseo_bctitle',
	);

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-yoast/update-term-seo';
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
			'label'               => __( 'Update term SEO metadata', 'abilities-catalog-yoast' ),
			'description'         => __( 'Updates a taxonomy term\'s basic Yoast SEO fields: SEO title, meta description, focus keyphrase, cornerstone flag, and breadcrumb title. Send only the fields you want to change; the rest are left as they are. Writing an empty string resets that field to its default. Returns the full SEO row after the write. Advanced robots and canonical fields have their own abilities.', 'abilities-catalog-yoast' ),
			'category'            => 'og-yoast',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'taxonomy', 'term_id' ),
				'properties'           => array(
					'taxonomy'         => array(
						'type'        => 'string',
						'description' => __( 'The taxonomy name, e.g. "category" or "post_tag".', 'abilities-catalog-yoast' ),
					),
					'term_id'          => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'The term ID to update. Discover IDs with the term-listing tools.', 'abilities-catalog-yoast' ),
					),
					'title'            => array(
						'type'        => 'string',
						'description' => __( 'The SEO title template (may contain Yoast replacement variables). An empty string resets it to the taxonomy default.', 'abilities-catalog-yoast' ),
					),
					'description'      => array(
						'type'        => 'string',
						'description' => __( 'The meta description for the term archive. An empty string resets it to the taxonomy default.', 'abilities-catalog-yoast' ),
					),
					'focus_keyphrase'  => array(
						'type'        => 'string',
						'description' => __( 'The focus keyphrase the term archive is optimized for. An empty string clears it.', 'abilities-catalog-yoast' ),
					),
					'is_cornerstone'   => array(
						'type'        => 'boolean',
						'description' => __( 'Whether the term archive is cornerstone content. false resets the flag to its default (off).', 'abilities-catalog-yoast' ),
					),
					'breadcrumb_title' => array(
						'type'        => 'string',
						'description' => __( 'The breadcrumb title for the term archive. An empty string resets it to the term name.', 'abilities-catalog-yoast' ),
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
			),
		);
	}

	/**
	 * Authorizes the write: Yoast active and the caller may edit this term.
	 *
	 * The taxonomy's edit-term cap (default `manage_categories`) plus the object-level
	 * `edit_term` mirror Yoast's own term-metabox save flow, which relies on WordPress
	 * having already checked the edit-term cap before firing `edit_term`
	 * (research-findings §4.4, §8, `class-taxonomy.php:221-245`). The basic SEO fields
	 * carry no advanced-cap gate — that gate guards the robots / canonical fields, which
	 * live in their own abilities.
	 *
	 * When the term does not exist, the object-level `edit_term` check can never pass
	 * (the meta cap maps to `do_not_allow`), which would collapse a missing term into a
	 * permission error. The general edit-terms cap already passed, so a missing term
	 * returns true here and lets {@see execute()} return the typed 404 instead.
	 *
	 * @param array<string,mixed> $input The validated input. Expects `taxonomy` and `term_id`.
	 * @return bool True when Yoast is active and the caller may edit the term.
	 */
	public function hasPermission( $input ): bool {
		if ( ! YoastPlugin::isActive() ) {
			return false;
		}

		$taxonomy = isset( $input['taxonomy'] ) ? (string) $input['taxonomy'] : '';
		$term_id  = absint( $input['term_id'] ?? 0 );
		if ( '' === $taxonomy || $term_id <= 0 ) {
			return false;
		}

		$tax = get_taxonomy( $taxonomy );
		if ( ! $tax ) {
			return false;
		}

		if ( ! current_user_can( $tax->cap->edit_terms ) ) {
			return false;
		}

		// Missing-term fallback: the general edit-terms cap above passed, so let
		// execute() surface the typed not-found error rather than collapsing it into a
		// permission error (the object-level check can never pass for an absent term).
		$term = get_term( $term_id, $taxonomy );
		if ( null === $term || is_wp_error( $term ) ) {
			return true;
		}

		return current_user_can( 'edit_term', $term_id );
	}

	/**
	 * Writes the supplied basic SEO fields, then returns the full curated SEO row.
	 *
	 * The supplied fields are written in one `set_values()` call, then the term meta is
	 * re-read to confirm the write stuck (`set_values()` gives no return value). A
	 * read-back mismatch yields a typed `yoast_term_update_failed` error naming the term
	 * and taxonomy.
	 *
	 * @param array<string,mixed> $input The validated input. Expects `taxonomy`, `term_id`, plus any basic fields.
	 * @return array<string,mixed>|\WP_Error The full curated SEO row, or a typed error.
	 */
	public function execute( $input ) {
		if ( ! YoastPlugin::isActive() ) {
			return YoastPlugin::unavailable();
		}

		$taxonomy = isset( $input['taxonomy'] ) ? (string) $input['taxonomy'] : '';
		$term_id  = absint( $input['term_id'] ?? 0 );

		$not_found = $this->resolveTerm( $term_id, $taxonomy );
		if ( $not_found instanceof WP_Error ) {
			return $not_found;
		}

		// set_values() resets the no-retain text fields (title/desc/focuskw/canonical)
		// to their default when they are absent, so start from the current full meta and
		// overlay only the requested changes — Yoast's own term metabox submits the whole
		// set the same way. Sending a partial set here would wipe the unsent fields.
		$current  = YoastPlugin::getTermMeta( $term_id, $taxonomy );
		$values   = is_array( $current ) ? $current : array();
		$expected = array();

		foreach ( self::FIELD_MAP as $input_key => $wpseo_key ) {
			if ( ! array_key_exists( $input_key, $input ) ) {
				continue;
			}

			$value                  = (string) $input[ $input_key ];
			$values[ $wpseo_key ]   = $value;
			$expected[ $wpseo_key ] = $value;
		}

		if ( array_key_exists( 'is_cornerstone', $input ) ) {
			// Yoast stores cornerstone as the string '1' (on) or '0' (default, off).
			$flag                             = $input['is_cornerstone'] ? '1' : '0';
			$values['wpseo_is_cornerstone']   = $flag;
			$expected['wpseo_is_cornerstone'] = $flag;
		}

		if ( array() !== $expected ) {
			YoastPlugin::setTermValues( $term_id, $taxonomy, $values );
		}

		$meta = YoastPlugin::getTermMeta( $term_id, $taxonomy );
		if ( ! is_array( $meta ) ) {
			return $this->updateFailed( $term_id, $taxonomy );
		}

		// set_values() returns no success signal, so confirm each requested change
		// actually persisted; if any did not, surface a typed write failure.
		foreach ( $expected as $wpseo_key => $expected_value ) {
			if ( ( $meta[ $wpseo_key ] ?? '' ) !== $expected_value ) {
				return $this->updateFailed( $term_id, $taxonomy );
			}
		}

		return YoastFieldShaper::curatedTermSeoRow( $meta, $taxonomy, $term_id );
	}

	/**
	 * Confirms the term resolves, returning a typed not-found error otherwise.
	 *
	 * @param int    $term_id  The term ID to resolve.
	 * @param string $taxonomy The taxonomy the term should belong to.
	 * @return \WP_Error|null A typed 404 when the term does not resolve, else null.
	 */
	private function resolveTerm( int $term_id, string $taxonomy ): ?WP_Error {
		$term = get_term( $term_id, $taxonomy );
		if ( null !== $term && ! is_wp_error( $term ) ) {
			return null;
		}

		return new WP_Error(
			'yoast_term_not_found',
			sprintf(
				/* translators: 1: term ID, 2: taxonomy name. */
				__( 'Term %1$d not found in taxonomy "%2$s". List available terms first, then retry with a valid term_id.', 'abilities-catalog-yoast' ),
				$term_id,
				$taxonomy
			),
			array( 'status' => 404 )
		);
	}

	/**
	 * Builds the typed error for a write whose change did not persist.
	 *
	 * @param int    $term_id  The term that was written.
	 * @param string $taxonomy The taxonomy the term belongs to.
	 * @return \WP_Error A `yoast_term_update_failed` error with HTTP status 500.
	 */
	private function updateFailed( int $term_id, string $taxonomy ): WP_Error {
		return new WP_Error(
			'yoast_term_update_failed',
			sprintf(
				/* translators: 1: term ID, 2: taxonomy name. */
				__( 'Could not store the SEO fields on term %1$d in taxonomy "%2$s": the values did not persist. Re-read the term with og-yoast/get-term-seo and retry.', 'abilities-catalog-yoast' ),
				$term_id,
				$taxonomy
			),
			array( 'status' => 500 )
		);
	}


	/**
	 * The output schema — the full curated term SEO object, identical to get-term-seo.
	 *
	 * The row carries only string keys, so it always serializes as a JSON object. The
	 * social keys are present only when their `wpseo_social` flag is on; the schema
	 * still declares them so a consumer knows their shape.
	 *
	 * @return array<string,mixed> The output JSON schema.
	 */
	private function outputSchema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'taxonomy'              => array(
					'type'        => 'string',
					'description' => __( 'The taxonomy the term belongs to.', 'abilities-catalog-yoast' ),
				),
				'term_id'               => array(
					'type'        => 'integer',
					'description' => __( 'The term ID this metadata belongs to.', 'abilities-catalog-yoast' ),
				),
				'title'                 => array(
					'type'        => 'string',
					'description' => __( 'The SEO title template (may contain Yoast replacement variables). Empty means the taxonomy default is used.', 'abilities-catalog-yoast' ),
				),
				'description'           => array(
					'type'        => 'string',
					'description' => __( 'The meta description for the term archive. Empty means the taxonomy default is used.', 'abilities-catalog-yoast' ),
				),
				'focus_keyphrase'       => array(
					'type'        => 'string',
					'description' => __( 'The focus keyphrase the term archive is optimized for.', 'abilities-catalog-yoast' ),
				),
				'canonical'             => array(
					'type'        => 'string',
					'description' => __( 'The canonical URL override for the term archive. Empty means the term uses its own URL.', 'abilities-catalog-yoast' ),
				),
				'breadcrumb_title'      => array(
					'type'        => 'string',
					'description' => __( 'The breadcrumb title for the term archive. Empty means the term name is used.', 'abilities-catalog-yoast' ),
				),
				'noindex'               => array(
					'type'        => 'string',
					'enum'        => array( 'default', 'index', 'noindex' ),
					'description' => __( 'Search-engine indexing directive: "default" inherits the taxonomy setting, "noindex" hides the term archive, "index" forces indexing.', 'abilities-catalog-yoast' ),
				),
				'is_cornerstone'        => array(
					'type'        => 'boolean',
					'description' => __( 'Whether the term archive is flagged as cornerstone content.', 'abilities-catalog-yoast' ),
				),
				'opengraph_title'       => array(
					'type'        => 'string',
					'description' => __( 'Open Graph title override. Present only when Open Graph data is enabled.', 'abilities-catalog-yoast' ),
				),
				'opengraph_description' => array(
					'type'        => 'string',
					'description' => __( 'Open Graph description override. Present only when Open Graph data is enabled.', 'abilities-catalog-yoast' ),
				),
				'opengraph_image'       => array(
					'type'        => 'string',
					'description' => __( 'Open Graph image URL override. Present only when Open Graph data is enabled.', 'abilities-catalog-yoast' ),
				),
				'twitter_title'         => array(
					'type'        => 'string',
					'description' => __( 'X/Twitter title override. Present only when X/Twitter data is enabled.', 'abilities-catalog-yoast' ),
				),
				'twitter_description'   => array(
					'type'        => 'string',
					'description' => __( 'X/Twitter description override. Present only when X/Twitter data is enabled.', 'abilities-catalog-yoast' ),
				),
				'twitter_image'         => array(
					'type'        => 'string',
					'description' => __( 'X/Twitter image URL override. Present only when X/Twitter data is enabled.', 'abilities-catalog-yoast' ),
				),
			),
			'additionalProperties' => false,
		);
	}
}
