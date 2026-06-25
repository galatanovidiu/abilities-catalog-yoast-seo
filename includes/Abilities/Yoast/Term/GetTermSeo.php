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
 * Reads the full curated Yoast SEO meta for one taxonomy term.
 *
 * Returns the SEO title, meta description, focus keyphrase, canonical URL,
 * breadcrumb title, robots noindex directive, cornerstone flag, and the social
 * (Open Graph / X/Twitter) overrides for a single term — a flat, closed row.
 *
 * Per-term SEO has no REST route and is not stored in term meta: Yoast keeps it
 * all under the single `wpseo_taxonomy_meta` option, keyed by taxonomy and term.
 * This wraps {@see \GalatanOvidiu\AbilitiesCatalogYoast\Support\YoastPlugin::getTermMeta()}
 * (Yoast's own `WPSEO_Taxonomy_Meta::get_term_meta()`), which returns the stored
 * values merged over the per-term defaults, so it never reads the raw option
 * itself. The stored `wpseo_noindex` tri-state enum and the `'0'`/`'1'`
 * cornerstone flag are surfaced as their readable forms.
 *
 * The social fields are saved by Yoast only when the matching `wpseo_social`
 * flag is on. This read mirrors that: the `opengraph_*` keys are emitted only
 * when the `opengraph` flag is on and the `twitter_*` keys only when the
 * `twitter` flag is on — when a flag is off the keys are omitted from the
 * result, not returned as empty strings.
 *
 * This is a {@see ConditionalAbility} gated on Yoast SEO being active, so it does
 * not register when Yoast is off.
 *
 * @since 0.6.0
 */
final class GetTermSeo implements ConditionalAbility {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-yoast/get-term-seo';
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
			'label'               => __( 'Get term SEO metadata', 'abilities-catalog-yoast' ),
			'description'         => __( 'Reads the full Yoast SEO metadata stored for one taxonomy term: SEO title, meta description, focus keyphrase, canonical URL, breadcrumb title, robots noindex directive, cornerstone flag, and the social (Open Graph and X/Twitter) overrides. Social fields are returned only when their sharing options are enabled.', 'abilities-catalog-yoast' ),
			'category'            => 'og-yoast',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'taxonomy', 'term_id' ),
				'properties'           => array(
					'taxonomy' => array(
						'type'        => 'string',
						'description' => __( 'The taxonomy name, e.g. "category" or "post_tag".', 'abilities-catalog-yoast' ),
					),
					'term_id'  => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'The term ID within that taxonomy.', 'abilities-catalog-yoast' ),
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
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
						'description' => __( 'The SEO title template (may contain Yoast replacement variables). Empty means the site default is used.', 'abilities-catalog-yoast' ),
					),
					'description'           => array(
						'type'        => 'string',
						'description' => __( 'The meta description. Empty means the site default is used.', 'abilities-catalog-yoast' ),
					),
					'focus_keyphrase'       => array(
						'type'        => 'string',
						'description' => __( 'The focus keyphrase the term archive is optimized for.', 'abilities-catalog-yoast' ),
					),
					'canonical'             => array(
						'type'        => 'string',
						'description' => __( 'The canonical URL override. Empty means the term uses its own archive URL.', 'abilities-catalog-yoast' ),
					),
					'breadcrumb_title'      => array(
						'type'        => 'string',
						'description' => __( 'The breadcrumb title override. Empty means the term name is used.', 'abilities-catalog-yoast' ),
					),
					'noindex'               => array(
						'type'        => 'string',
						'enum'        => array( 'default', 'index', 'noindex' ),
						'description' => __( 'Search-engine indexing directive: "default" inherits the taxonomy setting, "index" forces the term archive in, "noindex" hides it.', 'abilities-catalog-yoast' ),
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
	 * Authorizes the read: Yoast active and the caller may edit terms in the taxonomy.
	 *
	 * Per-term SEO has no REST route, so this enforces the equivalent of WP's own
	 * term-edit gate (research-findings §4.4): the taxonomy's `edit_terms` cap
	 * (default `manage_categories`) plus object-level `edit_term`. An unknown
	 * taxonomy is denied outright.
	 *
	 * When the term does not exist, the object-level `edit_term` check can never
	 * pass (the meta cap maps to `do_not_allow`), which would collapse a missing
	 * term into a permission error. To let {@see execute()} return the typed 404
	 * instead, a missing term falls back to the already-passed general
	 * `edit_terms` cap: a caller who may edit terms reaches the not-found error,
	 * while an under-privileged caller is still denied.
	 *
	 * @param array<string,mixed> $input The validated input. Expects `taxonomy`, `term_id`.
	 * @return bool True when Yoast is active and the caller may read the term's SEO.
	 */
	public function hasPermission( $input ): bool {
		if ( ! YoastPlugin::isActive() ) {
			return false;
		}

		$taxonomy = (string) ( $input['taxonomy'] ?? '' );
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

		$term = get_term( $term_id, $taxonomy );
		if ( null === $term || is_wp_error( $term ) ) {
			return true;
		}

		return current_user_can( 'edit_term', $term_id );
	}

	/**
	 * Reads and returns the term's curated Yoast SEO meta as a flat row.
	 *
	 * @param array<string,mixed> $input The validated input. Expects `taxonomy`, `term_id`.
	 * @return array<string,mixed>|\WP_Error The flat SEO row, or a typed error.
	 */
	public function execute( $input ) {
		if ( ! YoastPlugin::isActive() ) {
			return YoastPlugin::unavailable();
		}

		$taxonomy = (string) ( $input['taxonomy'] ?? '' );
		$term_id  = absint( $input['term_id'] ?? 0 );

		$term = get_term( $term_id, $taxonomy );
		if ( null === $term || is_wp_error( $term ) ) {
			return $this->termNotFound( $taxonomy, $term_id );
		}

		$meta = YoastPlugin::getTermMeta( $term_id, $taxonomy );
		if ( ! is_array( $meta ) ) {
			return $this->termNotFound( $taxonomy, $term_id );
		}

		return YoastFieldShaper::curatedTermSeoRow( $meta, $taxonomy, $term_id );
	}

	/**
	 * Builds the typed not-found error for a missing or unresolvable term.
	 *
	 * @param string $taxonomy The taxonomy the term was looked up in.
	 * @param int    $term_id  The term ID that was not found.
	 * @return \WP_Error A `yoast_term_not_found` error with HTTP status 404.
	 */
	private function termNotFound( string $taxonomy, int $term_id ): WP_Error {
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
}
