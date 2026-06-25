<?php
/**
 * Integration tests for the og-yoast/update-term-robots ability.
 *
 * @package AbilitiesCatalogYoast\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogYoast\Tests\Integration\Abilities\Term;

use GalatanOvidiu\AbilitiesCatalogYoast\Support\YoastPlugin;
use GalatanOvidiu\AbilitiesCatalogYoast\Tests\TestCase;
use WPSEO_Options;
use WPSEO_Taxonomy_Meta;

/**
 * Exercises the per-term Yoast robots write end to end through the Abilities API.
 *
 * Fixtures are seeded through Yoast's own save path (`WPSEO_Taxonomy_Meta::set_values`)
 * so the ability reads and writes real stored values. The whole class self-skips when
 * Yoast SEO is inactive, since the ability does not register then.
 */
final class UpdateTermRobotsTest extends TestCase {

	private const ABILITY = 'og-yoast/update-term-robots';

	/**
	 * Skips the class when Yoast SEO is inactive.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		if ( ! YoastPlugin::isActive() ) {
			$this->markTestSkipped( 'Yoast SEO is not active; og-yoast/update-term-robots does not register.' );
		}

		// The advanced-meta restriction is the default; assert the gate against it.
		WPSEO_Options::set( 'disableadvanced_meta', true );
	}

	/**
	 * Creates a category term while acting as a fresh administrator.
	 *
	 * @return int The created term ID.
	 */
	private function seedTerm(): int {
		$this->actingAs( 'administrator' );

		$term = self::factory()->term->create_and_get( array( 'taxonomy' => 'category' ) );

		return (int) $term->term_id;
	}

	public function test_it_registers(): void {
		$this->assertNotNull(
			wp_get_ability( self::ABILITY ),
			'og-yoast/update-term-robots must resolve after wp_abilities_api_init.'
		);
	}

	public function test_write_preserves_other_seo_fields(): void {
		$term_id = $this->seedTerm();

		// Seed text fields Yoast does NOT retain-on-missing; a single-key write would
		// wipe them. The robots write must read-merge-write to preserve them.
		WPSEO_Taxonomy_Meta::set_values(
			$term_id,
			'category',
			array(
				'wpseo_title'   => 'Seeded Term Title',
				'wpseo_focuskw' => 'seeded keyphrase',
				'wpseo_noindex' => 'index',
			)
		);

		wp_get_ability( self::ABILITY )->execute(
			array(
				'taxonomy' => 'category',
				'term_id'  => $term_id,
				'noindex'  => 'noindex',
			)
		);

		$meta = YoastPlugin::getTermMeta( $term_id, 'category' );

		$this->assertIsArray( $meta );
		$this->assertSame( 'noindex', $meta['wpseo_noindex'], 'The robots write must store the new noindex value.' );
		$this->assertSame( 'Seeded Term Title', $meta['wpseo_title'], 'A robots write must not wipe the unrelated title field.' );
		$this->assertSame( 'seeded keyphrase', $meta['wpseo_focuskw'], 'A robots write must not wipe the unrelated focus keyphrase.' );
	}

	public function test_happy_path_writes_noindex_and_returns_the_old_to_new_block(): void {
		$term_id = $this->seedTerm();

		// Seed a non-default starting state through Yoast's own save path.
		WPSEO_Taxonomy_Meta::set_values( $term_id, 'category', array( 'wpseo_noindex' => 'index' ) );

		$result = wp_get_ability( self::ABILITY )->execute(
			array(
				'taxonomy' => 'category',
				'term_id'  => $term_id,
				'noindex'  => 'noindex',
			)
		);

		$this->assertIsObject( $result, 'A permitted write must return the result object, not a WP_Error.' );

		$row = (array) $result;

		$this->assertSame(
			array( 'taxonomy', 'term_id', 'noindex' ),
			array_keys( $row ),
			'The result must carry taxonomy, term_id, then the noindex old->new block, in that order.'
		);

		$this->assertSame( 'category', $row['taxonomy'] );
		$this->assertSame( $term_id, $row['term_id'] );
		$this->assertSame(
			array(
				'from' => 'index',
				'to'   => 'noindex',
			),
			$row['noindex'],
			'The noindex block must report the value before and after the write.'
		);

		// The value was stored through Yoast's own API, not just echoed back.
		$this->assertSame(
			'noindex',
			YoastPlugin::getTermMeta( $term_id, 'category', 'noindex' ),
			'The directive must persist through Yoast\'s term-meta store.'
		);
	}

	public function test_setting_noindex_back_to_default_restores_the_taxonomy_behavior(): void {
		$term_id = $this->seedTerm();

		WPSEO_Taxonomy_Meta::set_values( $term_id, 'category', array( 'wpseo_noindex' => 'noindex' ) );

		$result = wp_get_ability( self::ABILITY )->execute(
			array(
				'taxonomy' => 'category',
				'term_id'  => $term_id,
				'noindex'  => 'default',
			)
		);

		$this->assertIsObject( $result );

		$row = (array) $result;

		$this->assertSame(
			array(
				'from' => 'noindex',
				'to'   => 'default',
			),
			$row['noindex'],
			'Writing "default" must report the move back to the inherited setting.'
		);
		$this->assertSame( 'default', YoastPlugin::getTermMeta( $term_id, 'category', 'noindex' ) );
	}

	/**
	 * A default-install administrator holds the term edit cap and
	 * `wpseo_manage_options` but NOT the raw `wpseo_edit_advanced_metadata` cap,
	 * with `disableadvanced_meta` at its `true` default. The write must still be
	 * permitted — only via the OR-`wpseo_manage_options` path (research-findings §8).
	 *
	 * @return void
	 */
	public function test_advanced_cap_default_admin_allowed_only_via_manage_options(): void {
		$term_id = $this->seedTerm();

		$this->assertTrue( (bool) YoastPlugin::getDisableAdvancedMeta(), 'disableadvanced_meta must default true for this assertion to be load-bearing.' );
		$this->assertTrue( current_user_can( 'manage_categories' ), 'A default administrator must hold the term edit cap.' );
		$this->assertFalse( current_user_can( 'wpseo_edit_advanced_metadata' ), 'A default administrator must NOT hold the raw advanced cap.' );
		$this->assertTrue( current_user_can( 'wpseo_manage_options' ), 'A default administrator must hold wpseo_manage_options.' );

		$result = wp_get_ability( self::ABILITY )->execute(
			array(
				'taxonomy' => 'category',
				'term_id'  => $term_id,
				'noindex'  => 'noindex',
			)
		);

		$this->assertIsObject( $result, 'A default admin must be allowed to write the advanced noindex field via the OR-wpseo_manage_options path.' );
		$this->assertSame( 'noindex', ( (array) $result )['noindex']['to'] );
	}

	public function test_under_privileged_user_is_denied(): void {
		$term_id = $this->seedTerm();

		// A subscriber cannot edit terms in the category taxonomy.
		$this->actingAs( 'subscriber' );

		$result = wp_get_ability( self::ABILITY )->execute(
			array(
				'taxonomy' => 'category',
				'term_id'  => $term_id,
				'noindex'  => 'noindex',
			)
		);

		$this->assertWPError( $result );
		$this->assertSame(
			'ability_invalid_permissions',
			$result->get_error_code(),
			'A caller without the term edit cap must be denied by the Abilities API.'
		);
	}

	public function test_missing_term_returns_typed_not_found_error(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( self::ABILITY )->execute(
			array(
				'taxonomy' => 'category',
				'term_id'  => 999999,
				'noindex'  => 'noindex',
			)
		);

		$this->assertWPError( $result, 'A missing term must surface a typed error, not a row.' );
		$this->assertSame(
			'yoast_term_not_found',
			$result->get_error_code(),
			'The typed not-found code must surface, not be collapsed into a permission error.'
		);
		$this->assertSame( 404, $result->get_error_data()['status'] ?? null );
	}
}
