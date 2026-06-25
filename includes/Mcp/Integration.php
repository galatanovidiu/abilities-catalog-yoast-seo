<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogYoast\Mcp;

use GalatanOvidiu\AbilitiesCatalog\Mcp\Knowledge\KnowledgeBundle;
use GalatanOvidiu\AbilitiesCatalogYoast\Support\YoastPlugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plugs the Yoast SEO abilities into the Abilities Catalog MCP server.
 *
 * The catalog exposes one curated MCP tool per domain, not one per ability, and is
 * extensible through public filters. This class is the add-on's whole MCP surface: it
 * registers an `og-yoast` domain tool — its description and the `og-yoast/*` abilities
 * it owns, in one place — and contributes its own scanned OKF knowledge bundle (the
 * concepts under `includes/knowledge/`) to the cross-cutting `knowledge` tool.
 *
 * Every contribution is gated on {@see YoastPlugin::isActive()} at filter-run time
 * (filters fire while the server boots, after plugins load), so when Yoast SEO is
 * inactive the `og-yoast/*` abilities do not register and no empty `og-yoast` tool or
 * dangling concept appears. The filters are catalog hooks: when the catalog or its MCP
 * server is absent, nothing applies them and the add-on stays inert here — and the
 * catalog's {@see KnowledgeBundle} scanner is then loaded too, since it is the catalog
 * that fires the knowledge filter.
 *
 * @since 0.1.0
 */
final class Integration {

	/**
	 * The exact `og-yoast/*` ability names the `og-yoast` domain tool owns, in tool order.
	 *
	 * Each batch appends the names it lands here so the tool lists them in the order
	 * an agent should see them.
	 *
	 * @var list<string>
	 */
	private const ABILITIES = array(
		'og-yoast/get-post-seo',
		'og-yoast/get-post-score',
		'og-yoast/update-post-seo',
		'og-yoast/update-post-schema',
		'og-yoast/update-post-robots',
		'og-yoast/update-post-canonical',
		'og-yoast/get-term-seo',
		'og-yoast/update-term-seo',
		'og-yoast/update-term-robots',
		'og-yoast/update-term-canonical',
		'og-yoast/get-author-seo',
		'og-yoast/update-author-seo',
		'og-yoast/update-author-noindex',
	);

	/**
	 * Registers the MCP filter hooks.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_filter( 'abilities_catalog_mcp_domains', array( self::class, 'contributeDomain' ) );
		add_filter( 'abilities_catalog_mcp_knowledge', array( self::class, 'contributeKnowledge' ) );
	}

	/**
	 * Registers the `og-yoast` domain tool — its description and the abilities it owns.
	 *
	 * One call defines the whole tool: the server builds an `og-yoast` tool, routes the
	 * `og-yoast/*` abilities to it, and uses the description as the tool's routing blurb.
	 * Skipped when Yoast is inactive (the abilities are not registered then, so an empty
	 * tool would only confuse an agent).
	 *
	 * @param array<string, array{description: string, abilities: list<string>}> $domains Add-on domain slug => its tool descriptor.
	 * @return array<string, array{description: string, abilities: list<string>}> The map including the `og-yoast` tool.
	 */
	public static function contributeDomain( array $domains ): array {
		if ( ! YoastPlugin::isActive() ) {
			return $domains;
		}

		$domains['og-yoast'] = array(
			'description' => __( 'Manage Yoast SEO — read and write SEO metadata for posts, terms, and authors, manage the site SEO settings, and rebuild the SEO index.', 'abilities-catalog-yoast' ),
			'abilities'   => self::ABILITIES,
		);

		return $domains;
	}

	/**
	 * Contributes the add-on's scanned knowledge bundle to the `knowledge` tool.
	 *
	 * The bundle is the `includes/knowledge/` directory of OKF concepts. The catalog
	 * scanner reads this add-on's own directory and the catalog merges the returned
	 * bundle under the `og-yoast` slug. A failed scan ({@see KnowledgeBundle::fromDirectory()}
	 * returns a `WP_Error` on a missing or empty directory) is skipped, never pushed —
	 * the directory is empty until the knowledge concepts land, so the guard makes that
	 * a no-op. Skipped entirely when Yoast is inactive.
	 *
	 * @param array<int, mixed> $bundles The registered knowledge bundles.
	 * @return array<int, mixed> The bundles, including this add-on's when Yoast is active.
	 */
	public static function contributeKnowledge( array $bundles ): array {
		if ( ! YoastPlugin::isActive() ) {
			return $bundles;
		}

		$bundle = KnowledgeBundle::fromDirectory( dirname( __DIR__ ) . '/knowledge', 'og-yoast' );
		if ( ! is_wp_error( $bundle ) ) {
			$bundles[] = $bundle;
		}

		return $bundles;
	}
}
