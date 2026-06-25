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
 * Write ability: `og-yoast/update-term-canonical`.
 *
 * Sets a taxonomy term's canonical URL — the URL search engines should treat as the
 * authoritative version of the term archive. Wraps
 * {@see \GalatanOvidiu\AbilitiesCatalogYoast\Support\YoastPlugin::setTermValues()}
 * (Yoast's own `WPSEO_Taxonomy_Meta::set_value()`) for the `canonical` key, so it never
 * writes the raw `wpseo_taxonomy_meta` option. Yoast's URL validation runs inside
 * `set_values`; the input is sanitized with `esc_url_raw()` before it reaches the
 * facade. An empty string clears the override back to the term's own archive URL.
 *
 * High blast radius: a canonical pointing at another URL tells search engines this term
 * archive is a duplicate of that URL, bleeding its link equity away — and the change is
 * silent and persistent, invisible until the rendered canonical tag is re-inspected.
 * To make it auditable, the result returns the canonical value BEFORE and AFTER this
 * write as a `canonical` old→new block, mirroring how the Contact Form 7 add-on surfaces
 * a rerouted mail recipient in its result. Reversible by clearing the canonical.
 *
 * Because Yoast's setter returns no success signal, this re-reads the field after
 * writing and returns a typed error if the stored value does not match what was sent.
 *
 * The canonical is an advanced per-term field, so the permission check honors Yoast's
 * advanced-meta gate (the taxonomy edit-term cap, object-level, AND — when
 * `disableadvanced_meta` is on — the `wpseo_edit_advanced_metadata` OR
 * `wpseo_manage_options` cap).
 *
 * This is a {@see ConditionalAbility} gated on Yoast SEO being active, so it does not
 * register when Yoast is off.
 *
 * @since 0.6.0
 */
final class UpdateTermCanonical implements ConditionalAbility {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-yoast/update-term-canonical';
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
			'label'               => __( 'Set term canonical URL', 'abilities-catalog-yoast' ),
			'description'         => __( 'Sets the canonical URL Yoast SEO emits for one taxonomy term archive (the rel="canonical" link). HIGH IMPACT: a canonical pointing at another URL tells search engines this term archive is a duplicate of that URL and transfers its ranking signals away, which is silent and persistent — invisible until the canonical tag is re-inspected. Send an empty string to clear the override so the term uses its own archive URL. The result returns the canonical value before and after the change for review.', 'abilities-catalog-yoast' ),
			'category'            => 'og-yoast',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'taxonomy', 'term_id', 'canonical' ),
				'properties'           => array(
					'taxonomy'  => array(
						'type'        => 'string',
						'description' => __( 'The taxonomy the term belongs to, e.g. "category" or "post_tag".', 'abilities-catalog-yoast' ),
					),
					'term_id'   => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'The term ID to set the canonical URL on.', 'abilities-catalog-yoast' ),
					),
					'canonical' => array(
						'type'        => 'string',
						'description' => __( 'The canonical URL to emit for this term archive. Send an empty string to clear the override and fall back to the term archive URL.', 'abilities-catalog-yoast' ),
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
	 * Authorizes the write: Yoast active, the taxonomy edit-term cap object-level, and
	 * the advanced-meta gate.
	 *
	 * The canonical is an advanced per-term field. Yoast renders it in the term metabox
	 * only when the user can edit advanced metadata, and treats `wpseo_manage_options` as
	 * also granting that edit (research-findings §4.4, §8). So a default administrator —
	 * who holds `wpseo_manage_options` but not the raw `wpseo_edit_advanced_metadata`
	 * cap — must still pass; a check of only `wpseo_edit_advanced_metadata` would wrongly
	 * block that admin.
	 *
	 * A missing term can never pass the object-level `edit_term` check, which would
	 * collapse a bad term_id into a permission error; when the term does not resolve, the
	 * general edit-terms cap (already passed) lets `execute()` return the typed 404.
	 *
	 * @param array<string,mixed> $input The validated input. Expects `taxonomy`, `term_id`.
	 * @return bool True when the caller may set this term's canonical URL.
	 */
	public function hasPermission( $input ): bool {
		if ( ! YoastPlugin::isActive() ) {
			return false;
		}

		$input    = is_array( $input ) ? $input : array();
		$taxonomy = (string) ( $input['taxonomy'] ?? '' );
		$term_id  = (int) ( $input['term_id'] ?? 0 );

		$tax = get_taxonomy( $taxonomy );
		if ( ! $tax ) {
			return false;
		}

		if ( ! current_user_can( $tax->cap->edit_terms ) ) {
			return false;
		}

		// Missing-term fallback: edit_term can never pass for a non-existent term, which
		// would collapse a missing term into a permission error. The general edit-terms
		// cap above already passed, so let execute() return the typed 404.
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
	 * Sets the term's canonical URL and returns the old→new canonical block.
	 *
	 * Reads the current canonical first (for the transparency block and to surface a
	 * missing-term 404), sanitizes the input with `esc_url_raw()`, writes it through the
	 * facade, then re-reads to confirm the write stuck before shaping the result.
	 *
	 * @param array<string,mixed> $input The validated input. Expects `taxonomy`, `term_id`, `canonical`.
	 * @return array<string,mixed>|\WP_Error The taxonomy, term_id, and canonical old→new block, or a typed error.
	 */
	public function execute( $input ) {
		if ( ! YoastPlugin::isActive() ) {
			return YoastPlugin::unavailable();
		}

		$input    = is_array( $input ) ? $input : array();
		$taxonomy = (string) ( $input['taxonomy'] ?? '' );
		$term_id  = absint( $input['term_id'] ?? 0 );

		$term = get_term( $term_id, $taxonomy );
		if ( null === $term || is_wp_error( $term ) ) {
			return $this->notFound( $taxonomy, $term_id );
		}

		// Snapshot the canonical before the write for the old→new transparency block.
		$from = (string) YoastPlugin::getTermMeta( $term_id, $taxonomy, 'canonical' );

		// Sanitize the URL before it reaches Yoast. An empty string clears the override.
		$canonical = esc_url_raw( (string) ( $input['canonical'] ?? '' ) );

		// set_values() resets the no-retain fields (title/desc/focuskw) unless they are
		// resent, so overlay canonical onto the current full meta and write the whole set
		// — Yoast's own metabox submits every field the same way.
		$current                   = YoastPlugin::getTermMeta( $term_id, $taxonomy );
		$values                    = is_array( $current ) ? $current : array();
		$values['wpseo_canonical'] = $canonical;
		YoastPlugin::setTermValues( $term_id, $taxonomy, $values );

		// Yoast's setter has no success signal; re-read to confirm the write stuck.
		$to = (string) YoastPlugin::getTermMeta( $term_id, $taxonomy, 'canonical' );
		if ( $to !== $canonical ) {
			return new WP_Error(
				'yoast_term_update_failed',
				sprintf(
					/* translators: 1: taxonomy name, 2: term ID. */
					__( 'The canonical URL for term %2$d in taxonomy "%1$s" did not store as sent. Re-read it with og-yoast/get-term-seo and retry.', 'abilities-catalog-yoast' ),
					$taxonomy,
					$term_id
				),
				array( 'status' => 500 )
			);
		}

		return array(
			'taxonomy'  => $taxonomy,
			'term_id'   => $term_id,
			'canonical' => array(
				'from' => $from,
				'to'   => $to,
			),
		);
	}

	/**
	 * The typed "term not found" error, naming the taxonomy and term plus the recovery step.
	 *
	 * @param string $taxonomy The taxonomy that was looked up.
	 * @param int    $term_id  The term ID that did not resolve.
	 * @return \WP_Error A `yoast_term_not_found` error with HTTP status 404.
	 */
	private function notFound( string $taxonomy, int $term_id ): WP_Error {
		return new WP_Error(
			'yoast_term_not_found',
			sprintf(
				/* translators: 1: term ID, 2: taxonomy name. */
				__( 'Term %1$d not found in taxonomy "%2$s"; list available terms first, then retry with a valid term_id.', 'abilities-catalog-yoast' ),
				$term_id,
				$taxonomy
			),
			array( 'status' => 404 )
		);
	}

	/**
	 * The output schema: taxonomy, term_id, and the canonical old→new block.
	 *
	 * @return array<string,mixed> The closed output schema.
	 */
	private function outputSchema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'taxonomy'  => array(
					'type'        => 'string',
					'description' => __( 'The taxonomy the term belongs to.', 'abilities-catalog-yoast' ),
				),
				'term_id'   => array(
					'type'        => 'integer',
					'description' => __( 'The term ID this canonical URL belongs to.', 'abilities-catalog-yoast' ),
				),
				'canonical' => array(
					'type'                 => 'object',
					'description'          => __( 'The canonical URL before and after this write.', 'abilities-catalog-yoast' ),
					'properties'           => array(
						'from' => array(
							'type'        => 'string',
							'description' => __( 'The canonical URL before this write. Empty means none was set.', 'abilities-catalog-yoast' ),
						),
						'to'   => array(
							'type'        => 'string',
							'description' => __( 'The canonical URL now stored (esc_url_raw-sanitized). Empty means the override was cleared.', 'abilities-catalog-yoast' ),
						),
					),
					'additionalProperties' => false,
				),
			),
			'additionalProperties' => false,
		);
	}
}
