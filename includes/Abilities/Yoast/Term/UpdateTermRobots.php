<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogYoast\Abilities\Yoast\Term;

use GalatanOvidiu\AbilitiesCatalogYoast\Contracts\ConditionalAbility;
use GalatanOvidiu\AbilitiesCatalogYoast\Support\YoastPlugin;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Write ability: `og-yoast/update-term-robots`.
 *
 * Sets a taxonomy term's robots indexing directive — whether the term archive
 * (e.g. a category or tag listing page) is indexed by search engines. Wraps
 * {@see \GalatanOvidiu\AbilitiesCatalogYoast\Support\YoastPlugin::setTermValues()}
 * (Yoast's own `WPSEO_Taxonomy_Meta::set_value()`) for the `noindex` key, so it
 * never writes the `wpseo_taxonomy_meta` option directly. The value is the enum
 * `default` / `index` / `noindex`: `default` inherits the taxonomy-level setting,
 * `index` forces the archive into search results, `noindex` forces it out.
 *
 * High blast radius: setting `noindex` silently de-indexes the term archive —
 * it flows into Yoast's indexable, marks the archive non-public, and removes it
 * from search engines, invisible until someone re-inspects the robots tag. The
 * change is reversible: setting `noindex` back to `default` restores the
 * taxonomy-level behavior. To make the change auditable, the result returns the
 * `noindex` value BEFORE and AFTER this write as a `noindex` old→new block,
 * mirroring how the Contact Form 7 add-on surfaces a rerouted mail recipient in
 * its result.
 *
 * Because Yoast's setter returns no success signal, this reads the value first
 * (for the transparency block), writes, then re-reads to confirm the value stuck
 * and returns a typed error if it did not.
 *
 * `noindex` is an advanced per-term field, so the permission check honors Yoast's
 * advanced-meta gate (object-level edit-term AND, when `disableadvanced_meta` is
 * on, the `wpseo_edit_advanced_metadata` OR `wpseo_manage_options` cap).
 *
 * This is a {@see ConditionalAbility} gated on Yoast SEO being active, so it does
 * not register when Yoast is off.
 *
 * @since 0.6.0
 */
final class UpdateTermRobots implements ConditionalAbility {

	/**
	 * The closed set of robots indexing values Yoast accepts for a term.
	 *
	 * Validated against `WPSEO_Taxonomy_Meta::$no_index_options`
	 * (research-findings §4.3, `class-wpseo-taxonomy-meta.php:87-91,205-215`).
	 *
	 * @var array<int,string>
	 */
	private const NOINDEX_VALUES = array( 'default', 'index', 'noindex' );

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-yoast/update-term-robots';
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
			'label'               => __( 'Set term robots indexing', 'abilities-catalog-yoast' ),
			'description'         => __( 'Sets a taxonomy term\'s robots indexing directive — whether its term archive (the category or tag listing page) is indexed by search engines. "default" inherits the taxonomy setting, "index" forces the archive into search results, "noindex" forces it out. HIGH IMPACT: setting "noindex" silently de-indexes the term archive, removing it from search engines, invisible until the robots tag is re-inspected. It is reversible by setting "default". The result returns the value before and after the change for review.', 'abilities-catalog-yoast' ),
			'category'            => 'og-yoast',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'taxonomy', 'term_id', 'noindex' ),
				'properties'           => array(
					'taxonomy' => array(
						'type'        => 'string',
						'description' => __( 'The taxonomy the term belongs to, e.g. "category" or "post_tag".', 'abilities-catalog-yoast' ),
					),
					'term_id'  => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'The term ID. List terms in the taxonomy first to discover valid IDs.', 'abilities-catalog-yoast' ),
					),
					'noindex'  => array(
						'type'        => 'string',
						'enum'        => self::NOINDEX_VALUES,
						'description' => __( 'The robots indexing directive. "default" inherits the taxonomy setting, "index" forces indexing, "noindex" hides the term archive from search engines.', 'abilities-catalog-yoast' ),
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'properties'           => array(
					'taxonomy' => array(
						'type'        => 'string',
						'description' => __( 'The taxonomy the term belongs to.', 'abilities-catalog-yoast' ),
					),
					'term_id'  => array(
						'type'        => 'integer',
						'description' => __( 'The term ID this directive belongs to.', 'abilities-catalog-yoast' ),
					),
					'noindex'  => array(
						'type'                 => 'object',
						'description'          => __( 'The robots indexing directive before and after this write.', 'abilities-catalog-yoast' ),
						'properties'           => array(
							'from' => array(
								'type'        => 'string',
								'enum'        => self::NOINDEX_VALUES,
								'description' => __( 'The directive before this write.', 'abilities-catalog-yoast' ),
							),
							'to'   => array(
								'type'        => 'string',
								'enum'        => self::NOINDEX_VALUES,
								'description' => __( 'The directive now stored.', 'abilities-catalog-yoast' ),
							),
						),
						'additionalProperties' => false,
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
			),
		);
	}

	/**
	 * Authorizes the write: Yoast active, object-level edit-term, and the
	 * advanced-meta gate.
	 *
	 * The taxonomy's edit-terms cap (default `manage_categories`) and object-level
	 * `edit_term` are the base guard. `noindex` is a Yoast advanced per-term field
	 * (Yoast only renders it under `wpseo_edit_advanced_metadata`,
	 * `class-taxonomy-metabox.php:99`), so when `disableadvanced_meta` is on (its
	 * default) the caller must additionally hold `wpseo_edit_advanced_metadata` or
	 * `wpseo_manage_options`. Yoast treats `wpseo_manage_options` as also granting
	 * advanced edit (research-findings §8, `capability-utils.php:20-26`), so a
	 * default administrator — who holds `wpseo_manage_options` but not the raw
	 * `wpseo_edit_advanced_metadata` cap — must still pass. A check of only
	 * `wpseo_edit_advanced_metadata` would wrongly block that admin.
	 * `wpseo_manage_options` is never substituted with `manage_options`.
	 *
	 * @param array<string,mixed> $input The validated input. Expects `taxonomy` and `term_id`.
	 * @return bool True when the caller may set this term's robots directive.
	 */
	public function hasPermission( $input ): bool {
		if ( ! YoastPlugin::isActive() ) {
			return false;
		}

		$taxonomy = (string) ( $input['taxonomy'] ?? '' );
		$term_id  = (int) ( $input['term_id'] ?? 0 );

		$tax = get_taxonomy( $taxonomy );
		if ( ! $tax ) {
			return false;
		}

		if ( ! current_user_can( $tax->cap->edit_terms ) ) {
			return false;
		}

		// A missing term can never pass the object-level edit_term check, which would
		// collapse it into a permission error; the general edit_terms cap above already
		// passed, so let execute() surface the typed 404 to a caller who may edit terms.
		$term = get_term( $term_id, $taxonomy );
		if ( null === $term || is_wp_error( $term ) ) {
			return true;
		}

		if ( ! current_user_can( 'edit_term', $term_id ) ) {
			return false;
		}

		if ( YoastPlugin::getDisableAdvancedMeta() ) {
			return current_user_can( 'wpseo_edit_advanced_metadata' )
				|| current_user_can( 'wpseo_manage_options' );
		}

		return true;
	}

	/**
	 * Sets the term's robots directive and returns the old→new transparency block.
	 *
	 * Reads the current `noindex` first (for the transparency block and to surface a
	 * missing-term 404), writes the requested value through the facade, then re-reads
	 * to confirm the write stuck before shaping the result. Yoast silently retains the
	 * old value on an invalid input, so the re-read is the only success signal.
	 *
	 * @param array<string,mixed> $input The validated input. Expects `taxonomy`, `term_id`, `noindex`.
	 * @return object|\WP_Error The taxonomy, term_id, and the noindex old→new block, or a typed error.
	 */
	public function execute( $input ) {
		if ( ! YoastPlugin::isActive() ) {
			return YoastPlugin::unavailable();
		}

		$input    = is_array( $input ) ? $input : array();
		$taxonomy = (string) ( $input['taxonomy'] ?? '' );
		$term_id  = absint( $input['term_id'] ?? 0 );
		$noindex  = (string) ( $input['noindex'] ?? '' );

		// Re-validate the enum before calling: Yoast silently retains the old value on an
		// out-of-set input, so an unchecked value would read back as a confusing mismatch.
		if ( ! in_array( $noindex, self::NOINDEX_VALUES, true ) ) {
			return new WP_Error(
				'yoast_term_invalid_noindex',
				sprintf(
					/* translators: %s: comma-separated list of allowed values. */
					__( 'Invalid noindex value. Use one of: %s.', 'abilities-catalog-yoast' ),
					implode( ', ', self::NOINDEX_VALUES )
				),
				array( 'status' => 400 )
			);
		}

		$from = $this->readNoindex( $term_id, $taxonomy );
		if ( null === $from ) {
			return $this->termNotFound( $term_id, $taxonomy );
		}

		// set_values() resets the no-retain fields (title/desc/focuskw/canonical) unless
		// they are resent, so overlay noindex onto the current full meta and write the
		// whole set — Yoast's own metabox submits every field the same way.
		$current                 = YoastPlugin::getTermMeta( $term_id, $taxonomy );
		$values                  = is_array( $current ) ? $current : array();
		$values['wpseo_noindex'] = $noindex;
		YoastPlugin::setTermValues( $term_id, $taxonomy, $values );

		// Yoast's setter returns void; re-read to confirm the write stuck.
		$to = $this->readNoindex( $term_id, $taxonomy );
		if ( $to !== $noindex ) {
			return new WP_Error(
				'yoast_term_update_failed',
				sprintf(
					/* translators: 1: term ID, 2: taxonomy name. */
					__( 'The robots directive for term %1$d in taxonomy "%2$s" did not store as sent. Re-read it with og-yoast/get-term-seo and retry.', 'abilities-catalog-yoast' ),
					$term_id,
					$taxonomy
				),
				array( 'status' => 500 )
			);
		}

		return (object) array(
			'taxonomy' => $taxonomy,
			'term_id'  => $term_id,
			'noindex'  => array(
				'from' => $from,
				'to'   => $to,
			),
		);
	}

	/**
	 * Reads a term's stored `noindex` directive through the facade.
	 *
	 * `getTermMeta` returns `false` when the term cannot be resolved; this normalizes
	 * that to `null` so the caller can raise the typed not-found error. Otherwise it
	 * returns the stored enum string (`default` for an unset field).
	 *
	 * @param int    $term_id  The term ID to read.
	 * @param string $taxonomy The taxonomy the term belongs to.
	 * @return string|null The stored directive, or null when the term is unresolvable.
	 */
	private function readNoindex( int $term_id, string $taxonomy ): ?string {
		$value = YoastPlugin::getTermMeta( $term_id, $taxonomy, 'noindex' );
		if ( false === $value ) {
			return null;
		}

		return (string) $value;
	}

	/**
	 * Builds the typed not-found error for a missing or unresolvable term.
	 *
	 * @param int    $term_id  The term ID that was not found.
	 * @param string $taxonomy The taxonomy searched.
	 * @return \WP_Error A `yoast_term_not_found` error with HTTP status 404.
	 */
	private function termNotFound( int $term_id, string $taxonomy ): WP_Error {
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
