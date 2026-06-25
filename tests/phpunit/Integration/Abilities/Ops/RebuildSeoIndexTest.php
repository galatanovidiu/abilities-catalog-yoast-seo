<?php
/**
 * Integration tests for the og-yoast/rebuild-seo-index ability.
 *
 * @package AbilitiesCatalogYoast\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogYoast\Tests\Integration\Abilities\Ops;

use GalatanOvidiu\AbilitiesCatalogYoast\Support\YoastPlugin;
use GalatanOvidiu\AbilitiesCatalogYoast\Tests\TestCase;

/**
 * Exercises the indexable-rebuild op end to end through the Abilities API.
 *
 * Yoast only builds its index on a production environment, so the happy-path tests add
 * the `Yoast\WP\SEO\should_index_indexables` filter (preferred over forcing the
 * `WP_ENVIRONMENT_TYPE` global) to flip `should_index_indexables()` to true; without it
 * the rebuild is a silent no-op (research-findings §7.2, §12.6). The whole class
 * self-skips when Yoast SEO is inactive, since the ability does not register then.
 */
final class RebuildSeoIndexTest extends TestCase {

	private const ABILITY = 'og-yoast/rebuild-seo-index';

	/**
	 * Whether the production-env filter is currently forcing indexing on.
	 *
	 * @var bool
	 */
	private bool $forcing_production = false;

	/**
	 * Skips the class when Yoast SEO is inactive.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		if ( ! YoastPlugin::isActive() ) {
			$this->markTestSkipped( 'Yoast SEO is not active; og-yoast/rebuild-seo-index does not register.' );
		}
	}

	/**
	 * Removes the production-env filter if a test added it.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		if ( $this->forcing_production ) {
			remove_filter( 'Yoast\WP\SEO\should_index_indexables', '__return_true' );
			$this->forcing_production = false;
		}

		parent::tear_down();
	}

	/**
	 * Forces Yoast to treat this environment as one where it should build indexables.
	 *
	 * @return void
	 */
	private function forceProductionIndexing(): void {
		add_filter( 'Yoast\WP\SEO\should_index_indexables', '__return_true' );
		$this->forcing_production = true;
	}

	public function test_it_registers(): void {
		$this->assertNotNull(
			wp_get_ability( self::ABILITY ),
			'og-yoast/rebuild-seo-index must resolve after wp_abilities_api_init.'
		);
	}

	public function test_happy_path_returns_the_counts_shape(): void {
		$this->actingAs( 'administrator' );
		$this->forceProductionIndexing();

		// Seed a post and a term so there is something to index.
		self::factory()->post->create();
		self::factory()->term->create( array( 'taxonomy' => 'category' ) );

		$result = wp_get_ability( self::ABILITY )->execute( array() );

		$this->assertIsArray( $result, 'A permitted rebuild must return the counts, not a WP_Error.' );

		$this->assertSame(
			array( 'actions', 'total_indexed', 'total_remaining' ),
			array_keys( $result ),
			'The result must carry actions, total_indexed, total_remaining in order.'
		);

		$this->assertIsInt( $result['total_indexed'] );
		$this->assertIsInt( $result['total_remaining'] );

		// actions is cast to an object so it serializes as a JSON object.
		$actions = (array) $result['actions'];
		$this->assertNotEmpty( $actions, 'The actions map must carry one entry per indexation action.' );

		foreach ( $actions as $counts ) {
			$counts = (array) $counts;
			$this->assertArrayHasKey( 'indexed', $counts );
			$this->assertArrayHasKey( 'remaining', $counts );
			$this->assertIsInt( $counts['indexed'] );
			$this->assertIsInt( $counts['remaining'] );
		}
	}

	public function test_drains_to_zero_remaining_on_a_small_site(): void {
		$this->actingAs( 'administrator' );
		$this->forceProductionIndexing();

		self::factory()->post->create();

		$result = wp_get_ability( self::ABILITY )->execute( array() );

		$this->assertIsArray( $result );
		$this->assertSame(
			0,
			$result['total_remaining'],
			'A small site must fully drain in one request (total_remaining 0).'
		);
	}

	public function test_additive_rerun_does_not_clear_existing_indexables(): void {
		$this->actingAs( 'administrator' );
		$this->forceProductionIndexing();

		self::factory()->post->create();

		// First pass indexes the seeded objects.
		$first = wp_get_ability( self::ABILITY )->execute( array() );
		$this->assertIsArray( $first );

		// A second pass is additive: nothing left to index, so it indexes nothing more
		// and leaves nothing remaining. It does not clear (no --reindex) and re-build.
		$second = wp_get_ability( self::ABILITY )->execute( array() );
		$this->assertIsArray( $second );
		$this->assertSame(
			0,
			$second['total_indexed'],
			'A re-run on an already-indexed site indexes nothing — it is additive, not a clear-and-rebuild.'
		);
		$this->assertSame( 0, $second['total_remaining'] );
	}

	public function test_non_production_env_is_refused_with_its_own_code(): void {
		$this->actingAs( 'administrator' );

		// No production filter / env: should_index_indexables() is false, so the ability
		// refuses up front rather than running a silent no-op.
		$result = wp_get_ability( self::ABILITY )->execute( array() );

		$this->assertWPError( $result );
		$this->assertSame(
			'og_yoast_not_production',
			$result->get_error_code(),
			'A non-production environment must surface the typed not-production error, not a permission error.'
		);
	}

	public function test_under_privileged_user_is_denied(): void {
		// A subscriber lacks wpseo_manage_options (Yoast grants it to administrators).
		$this->actingAs( 'subscriber' );
		$this->forceProductionIndexing();

		$result = wp_get_ability( self::ABILITY )->execute( array() );

		$this->assertWPError( $result );
		$this->assertSame(
			'ability_invalid_permissions',
			$result->get_error_code(),
			'A caller without wpseo_manage_options must be denied by the Abilities API.'
		);
	}
}
