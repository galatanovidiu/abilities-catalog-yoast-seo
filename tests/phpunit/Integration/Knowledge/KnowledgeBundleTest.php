<?php
/**
 * Tests that the shipped knowledge directory scans into the expected OKF bundle.
 *
 * @package AbilitiesCatalogYoast\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogYoast\Tests\Integration\Knowledge;

use GalatanOvidiu\AbilitiesCatalog\Mcp\Knowledge\KnowledgeBundle;
use GalatanOvidiu\AbilitiesCatalogYoast\Tests\TestCase;

/**
 * The add-on ships three OKF concepts under `includes/knowledge/`. The catalog scanner
 * folds them into the cross-cutting `knowledge` tool, so a wrong filename, missing
 * frontmatter `type`, or a stray file would silently drop or add a concept. This pins the
 * bundle to exactly the three expected concepts with their declared types, using the same
 * catalog {@see KnowledgeBundle} scanner the integration uses.
 *
 * The directory is resolved from the scaffold's `ABILITIES_CATALOG_YOAST_DIR` constant, not
 * a hardcoded absolute path.
 */
final class KnowledgeBundleTest extends TestCase {

	/**
	 * Scans the shipped `includes/knowledge/` directory under the `og-yoast` slug.
	 *
	 * @return KnowledgeBundle The scanned bundle (asserted not a WP_Error first).
	 */
	private function scanBundle(): KnowledgeBundle {
		$bundle = KnowledgeBundle::fromDirectory(
			ABILITIES_CATALOG_YOAST_DIR . 'includes/knowledge',
			'og-yoast'
		);

		$this->assertNotInstanceOf(
			\WP_Error::class,
			$bundle,
			'The shipped knowledge directory must scan into a bundle, not a WP_Error.'
		);

		return $bundle;
	}

	/**
	 * The bundle carries exactly the three shipped concepts — no stray or missing file.
	 *
	 * @return void
	 */
	public function test_bundle_carries_exactly_three_concepts(): void {
		$bundle = $this->scanBundle();

		$this->assertSame( 'og-yoast', $bundle->slug() );
		$this->assertCount(
			3,
			$bundle->children(),
			'The bundle must carry exactly the three shipped concepts.'
		);
	}

	/**
	 * The optimize-post-seo concept is a Skill with the expected URI and a non-empty body.
	 *
	 * @return void
	 */
	public function test_optimize_post_seo_concept(): void {
		$concept = $this->scanBundle()->concept( 'optimize-post-seo' );

		$this->assertNotNull( $concept );
		$this->assertSame( 'Skill', $concept->type() );
		$this->assertSame( 'og-yoast/optimize-post-seo', $concept->uri() );
		$this->assertNotEmpty( $concept->body() );
	}

	/**
	 * The audit-site-seo concept is a Skill.
	 *
	 * @return void
	 */
	public function test_audit_site_seo_concept(): void {
		$concept = $this->scanBundle()->concept( 'audit-site-seo' );

		$this->assertNotNull( $concept );
		$this->assertSame( 'Skill', $concept->type() );
	}

	/**
	 * The seo-safety concept is a Guideline.
	 *
	 * @return void
	 */
	public function test_seo_safety_concept(): void {
		$concept = $this->scanBundle()->concept( 'seo-safety' );

		$this->assertNotNull( $concept );
		$this->assertSame( 'Guideline', $concept->type() );
	}
}
