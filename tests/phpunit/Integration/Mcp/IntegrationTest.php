<?php
/**
 * Tests the MCP integration filters.
 *
 * @package AbilitiesCatalogYoast\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogYoast\Tests\Integration\Mcp;

use GalatanOvidiu\AbilitiesCatalog\Mcp\Knowledge\KnowledgeBundle;
use GalatanOvidiu\AbilitiesCatalogYoast\Mcp\Integration;
use GalatanOvidiu\AbilitiesCatalogYoast\Support\YoastPlugin;
use GalatanOvidiu\AbilitiesCatalogYoast\Tests\TestCase;

/**
 * The integration registers an `og-yoast` domain tool and contributes a knowledge bundle
 * to the catalog's MCP server through public filters. These assert the descriptor shape,
 * the exact 26-name list in tool order (the contract an agent sees), and — the point of the
 * guard — that every ability the tool lists is actually registered, so the hand-kept name
 * list cannot drift away from the ability classes.
 *
 * The Yoast-3 fold-in (Yoast SEO's own score abilities) is presence-guarded: it is asserted
 * per name, branching on `wp_has_ability()`, so the suite passes whether or not Yoast's own
 * abilities are present and proves the fold-in is guarded rather than hardcoded.
 *
 * The knowledge filter is the cross-repo contract: it carries scanned {@see KnowledgeBundle}
 * objects, a catalog class, so these tests only pass with the catalog loaded (the test
 * bootstrap requires it).
 */
final class IntegrationTest extends TestCase {

	/**
	 * The 26 own ability names the `og-yoast` tool lists, in the exact tool order.
	 *
	 * Order is the contract: it is what an agent sees, so the test pins the first 26
	 * entries to this exact sequence (catalog build/tool order — reads first, then writes
	 * per object kind, settings, then ops).
	 *
	 * @var list<string>
	 */
	private const OWN_ABILITIES = array(
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
		'og-yoast/get-search-appearance',
		'og-yoast/update-search-appearance',
		'og-yoast/get-breadcrumbs',
		'og-yoast/update-breadcrumbs',
		'og-yoast/get-knowledge-graph',
		'og-yoast/update-knowledge-graph',
		'og-yoast/get-social-settings',
		'og-yoast/update-social-settings',
		'og-yoast/get-indexing-settings',
		'og-yoast/update-indexing-settings',
		'og-yoast/get-general-settings',
		'og-yoast/update-general-settings',
		'og-yoast/rebuild-seo-index',
	);

	/**
	 * Yoast SEO's own score abilities, folded into the tool only when Yoast registered them.
	 *
	 * @var list<string>
	 */
	private const YOAST_SCORE_ABILITIES = array(
		'yoast-seo/get-seo-scores',
		'yoast-seo/get-readability-scores',
		'yoast-seo/get-inclusive-language-scores',
	);

	/**
	 * The abilities only register when Yoast SEO is active, so skip otherwise.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		if ( ! YoastPlugin::isActive() ) {
			$this->markTestSkipped( 'Yoast SEO is not active.' );
		}
	}

	/**
	 * contributeDomain registers a described `og-yoast` tool with a non-empty ability list.
	 *
	 * @return void
	 */
	public function test_contribute_domain_registers_a_described_tool(): void {
		$domains = Integration::contributeDomain( array() );

		$this->assertArrayHasKey( 'og-yoast', $domains );
		$this->assertIsString( $domains['og-yoast']['description'] );
		$this->assertNotEmpty( $domains['og-yoast']['description'] );
		$this->assertNotEmpty( $domains['og-yoast']['abilities'] );
	}

	/**
	 * The tool lists the 26 own abilities first, in the exact tool order (the contract).
	 *
	 * @return void
	 */
	public function test_lists_the_twenty_six_own_abilities_in_tool_order(): void {
		$domains   = Integration::contributeDomain( array() );
		$abilities = $domains['og-yoast']['abilities'];

		$this->assertSame(
			self::OWN_ABILITIES,
			array_slice( $abilities, 0, count( self::OWN_ABILITIES ) ),
			'The first 26 listed abilities must equal the tool-order contract.'
		);
	}

	/**
	 * Every ability the `og-yoast` tool lists is a registered ability (no name drift).
	 *
	 * @return void
	 */
	public function test_every_listed_ability_is_registered(): void {
		$domains = Integration::contributeDomain( array() );

		foreach ( $domains['og-yoast']['abilities'] as $name ) {
			$this->assertTrue(
				wp_has_ability( $name ),
				sprintf( 'The og-yoast tool lists "%s", which is not a registered ability.', $name )
			);
		}
	}

	/**
	 * The Yoast-3 score fold-in is presence-guarded: listed when registered, absent otherwise.
	 *
	 * Branches per name on `wp_has_ability()` rather than skipping the test, so it passes
	 * whether or not Yoast registered its own score abilities, and proves the fold-in is
	 * guarded — never hard-listed when Yoast did not register it.
	 *
	 * @return void
	 */
	public function test_yoast_score_fold_in_is_presence_guarded(): void {
		$domains   = Integration::contributeDomain( array() );
		$abilities = $domains['og-yoast']['abilities'];

		foreach ( self::YOAST_SCORE_ABILITIES as $score_ability ) {
			if ( wp_has_ability( $score_ability ) ) {
				$this->assertContains(
					$score_ability,
					$abilities,
					sprintf( 'Yoast registered "%s", so the tool must fold it in.', $score_ability )
				);
				continue;
			}

			$this->assertNotContains(
				$score_ability,
				$abilities,
				sprintf( 'Yoast did not register "%s", so the tool must not list it.', $score_ability )
			);
		}
	}

	/**
	 * contributeKnowledge appends exactly one scanned `og-yoast` bundle.
	 *
	 * @return void
	 */
	public function test_contribute_knowledge_adds_the_bundle(): void {
		$bundles = Integration::contributeKnowledge( array() );

		$this->assertCount( 1, $bundles );
		$bundle = $bundles[0];
		$this->assertInstanceOf( KnowledgeBundle::class, $bundle );
		$this->assertSame( 'og-yoast', $bundle->slug() );
	}
}
