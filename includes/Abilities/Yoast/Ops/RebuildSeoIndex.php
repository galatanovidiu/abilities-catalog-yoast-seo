<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogYoast\Abilities\Yoast\Ops;

use GalatanOvidiu\AbilitiesCatalogYoast\Contracts\ConditionalAbility;
use GalatanOvidiu\AbilitiesCatalogYoast\Support\YoastPlugin;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Rebuilds Yoast SEO's indexable cache for the current site, additively, in one call.
 *
 * Runs Yoast's own six indexation actions to completion in a single server-side
 * request — the same work as the `wp yoast index` command, but WITHOUT the
 * destructive `--reindex` clear. It is additive: existing indexables are kept and
 * only the missing ones are built, so it never tears down and rebuilds from scratch.
 * Re-running it on a fully indexed site is a no-op, which makes it idempotent.
 *
 * Blast radius: it walks every post, term, post-type archive, and link on the site,
 * so on a very large site it may exceed one request. A non-zero `total_remaining` in
 * the result says another pass is needed; for those sites Yoast's JS background
 * indexer or the `wp yoast index` CLI command is the escape hatch.
 *
 * The output is the transparency: per-action `indexed` and `remaining` counts so the
 * caller sees exactly what moved, instead of an old-to-new value block (this is not a
 * field-value edit).
 *
 * On a non-production environment Yoast skips indexing entirely, so the ability checks
 * {@see YoastPlugin::shouldIndexIndexables()} first and refuses with a clear, typed
 * error rather than running a silent no-op.
 *
 * All Yoast access goes through
 * {@see \GalatanOvidiu\AbilitiesCatalogYoast\Support\YoastPlugin}, so the ability never
 * names a `WPSEO_*` symbol or the `YoastSEO()` container itself.
 *
 * This is a {@see ConditionalAbility} gated on Yoast SEO being active, so it does not
 * register when Yoast is off.
 *
 * @since 0.8.0
 */
final class RebuildSeoIndex implements ConditionalAbility {

	/**
	 * The `get-*` partner a caller inspects current indexing state with.
	 *
	 * @var string
	 */
	private const INDEXING_SETTINGS_ABILITY = 'og-yoast/get-indexing-settings';

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-yoast/rebuild-seo-index';
	}

	/**
	 * Whether Yoast SEO is active at a supported version and the DI container is reachable.
	 *
	 * The rebuild resolves the indexation actions from Yoast's DI container, so on top
	 * of the standard symbol + version detection it additionally needs the `YoastSEO()`
	 * container accessor (the function the facade pulls the actions through). Detection
	 * only — no Yoast method is called here.
	 *
	 * @return bool True when Yoast is active and its container accessor exists.
	 */
	public function isAvailable(): bool {
		return YoastPlugin::isActive() && function_exists( 'YoastSEO' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return array(
			'label'               => __( 'Rebuild SEO index', 'abilities-catalog-yoast' ),
			'description'         => __( 'Rebuilds the SEO indexable cache for the site by running all six indexation actions (posts, terms, post-type archives, general objects, post links, term links) to completion in one call. This is additive — it keeps existing indexables and only fills in what is missing; it does NOT clear and rebuild from scratch. Returns the per-action indexed and remaining counts. On a very large site the work may exceed one request: a non-zero total_remaining means another pass is needed, for which the background indexer or the wp yoast index command is the escape hatch. Only runs on a production environment; otherwise the indexing engine is a no-op.', 'abilities-catalog-yoast' ),
			'category'            => 'og-yoast',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => (object) array(),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'properties'           => array(
					'actions'         => array(
						'type'                 => 'object',
						'description'          => __( 'Per-action counts, keyed by indexation action name. Each value is an object with the number indexed this run and the number still remaining.', 'abilities-catalog-yoast' ),
						'additionalProperties' => array(
							'type'                 => 'object',
							'properties'           => array(
								'indexed'   => array(
									'type'        => 'integer',
									'description' => __( 'The number of objects this action indexed during this run.', 'abilities-catalog-yoast' ),
								),
								'remaining' => array(
									'type'        => 'integer',
									'description' => __( 'The number of objects this action still has unindexed after this run (0 on a fully drained site).', 'abilities-catalog-yoast' ),
								),
							),
							'additionalProperties' => false,
						),
					),
					'total_indexed'   => array(
						'type'        => 'integer',
						'description' => __( 'The total number of objects indexed across all actions this run.', 'abilities-catalog-yoast' ),
					),
					'total_remaining' => array(
						'type'        => 'integer',
						'description' => __( 'The total number of objects still unindexed across all actions. Non-zero means a large site needs another pass.', 'abilities-catalog-yoast' ),
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
					'idempotent'  => true,
					'dangerous'   => true,
				),
				'show_in_rest' => true,
			),
		);
	}

	/**
	 * Authorizes the rebuild: Yoast active and the caller may manage Yoast's settings.
	 *
	 * The cap is Yoast's own `wpseo_manage_options` — stricter than Yoast's own indexing
	 * route gate (`edit_posts`), which is the safe direction for a full-site write
	 * (research-findings §7.3, `indexing-route.php:406-408`). Never the core
	 * `manage_options`. There is no object-level check — the rebuild is site-wide.
	 *
	 * @param array<string,mixed> $input The validated input.
	 * @return bool True when Yoast is active and the caller may manage Yoast settings.
	 */
	public function hasPermission( $input ): bool {
		if ( ! YoastPlugin::isActive() ) {
			return false;
		}

		return current_user_can( 'wpseo_manage_options' );
	}

	/**
	 * Guards the environment, then drains every indexation action and returns the counts.
	 *
	 * On a non-production environment Yoast's indexing engine is a silent no-op, so the
	 * ability refuses up front with a typed error naming `WP_ENVIRONMENT_TYPE` and the
	 * override filter rather than running and reporting zero work. A failure the facade
	 * surfaces as a `WP_Error` is returned as-is (not collapsed into a permission error).
	 *
	 * @param array<string,mixed> $input The validated input (no fields).
	 * @return array<string,mixed>|\WP_Error The per-action and total counts, or a typed error.
	 */
	public function execute( $input ) {
		if ( ! YoastPlugin::isActive() ) {
			return YoastPlugin::unavailable();
		}

		if ( ! YoastPlugin::shouldIndexIndexables() ) {
			return new WP_Error(
				'og_yoast_not_production',
				sprintf(
					/* translators: 1: the WP_ENVIRONMENT_TYPE constant name, 2: the Yoast override filter name, 3: the get-* partner ability name. */
					__( 'Yoast only builds its SEO index on a production environment, so the rebuild would be a no-op here. Set %1$s to "production" (or add the %2$s filter returning true), then retry. Inspect the current indexing state with %3$s.', 'abilities-catalog-yoast' ),
					'WP_ENVIRONMENT_TYPE',
					'Yoast\\WP\\SEO\\should_index_indexables',
					self::INDEXING_SETTINGS_ABILITY
				),
				array( 'status' => 409 )
			);
		}

		$summary = YoastPlugin::rebuildIndexableIndex();

		if ( $summary instanceof WP_Error ) {
			return $summary;
		}

		return array(
			'actions'         => (object) $summary['actions'],
			'total_indexed'   => (int) $summary['total_indexed'],
			'total_remaining' => (int) $summary['total_remaining'],
		);
	}
}
