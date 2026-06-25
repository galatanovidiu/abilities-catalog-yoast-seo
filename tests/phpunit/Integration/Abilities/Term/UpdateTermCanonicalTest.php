<?php
/**
 * Integration tests for the og-yoast/update-term-canonical ability.
 *
 * @package AbilitiesCatalogYoast\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogYoast\Tests\Integration\Abilities\Term;

use GalatanOvidiu\AbilitiesCatalogYoast\Support\YoastPlugin;
use GalatanOvidiu\AbilitiesCatalogYoast\Tests\TestCase;
use WPSEO_Taxonomy_Meta;

/**
 * Exercises the per-term canonical write end to end through the Abilities API.
 *
 * Fixtures are seeded through Yoast's own save path (`WPSEO_Taxonomy_Meta::set_values`).
 * The whole class self-skips when Yoast SEO is inactive, since the ability does not
 * register then.
 */
final class UpdateTermCanonicalTest extends TestCase {

	private const ABILITY = 'og-yoast/update-term-canonical';

	/**
	 * Skips the class when Yoast SEO is inactive.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		if ( ! YoastPlugin::isActive() ) {
			$this->markTestSkipped( 'Yoast SEO is not active; og-yoast/update-term-canonical does not register.' );
		}
	}

	/**
	 * Creates a category term and makes an administrator the current user.
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
			'og-yoast/update-term-canonical must resolve after wp_abilities_api_init.'
		);
	}

	public function test_write_preserves_other_seo_fields(): void {
		$term_id = $this->seedTerm();

		// Seed text fields Yoast does NOT retain-on-missing; a single-key write would
		// wipe them. The canonical write must read-merge-write to preserve them.
		WPSEO_Taxonomy_Meta::set_values(
			$term_id,
			'category',
			array(
				'wpseo_title'   => 'Seeded Term Title',
				'wpseo_focuskw' => 'seeded keyphrase',
			)
		);

		wp_get_ability( self::ABILITY )->execute(
			array(
				'taxonomy'  => 'category',
				'term_id'   => $term_id,
				'canonical' => 'https://example.com/new',
			)
		);

		$meta = YoastPlugin::getTermMeta( $term_id, 'category' );

		$this->assertIsArray( $meta );
		$this->assertSame( 'https://example.com/new', $meta['wpseo_canonical'], 'The canonical write must store the new URL.' );
		$this->assertSame( 'Seeded Term Title', $meta['wpseo_title'], 'A canonical write must not wipe the unrelated title field.' );
		$this->assertSame( 'seeded keyphrase', $meta['wpseo_focuskw'], 'A canonical write must not wipe the unrelated focus keyphrase.' );
	}

	public function test_happy_path_sets_canonical_and_returns_old_to_new_block(): void {
		$term_id = $this->seedTerm();

		// Seed a prior canonical through Yoast's own save path (full wpseo_ prefix).
		WPSEO_Taxonomy_Meta::set_values( $term_id, 'category', array( 'wpseo_canonical' => 'https://example.com/old' ) );

		$result = wp_get_ability( self::ABILITY )->execute(
			array(
				'taxonomy'  => 'category',
				'term_id'   => $term_id,
				'canonical' => 'https://example.com/new',
			)
		);

		$this->assertIsArray( $result, 'A permitted write must return the result row, not a WP_Error.' );

		$this->assertSame(
			array( 'taxonomy', 'term_id', 'canonical' ),
			array_keys( $result ),
			'The output must be the closed taxonomy/term_id/canonical row.'
		);
		$this->assertSame( 'category', $result['taxonomy'] );
		$this->assertSame( $term_id, $result['term_id'] );

		$this->assertIsArray( $result['canonical'], 'The canonical key must carry the old→new block.' );
		$this->assertSame( 'https://example.com/old', $result['canonical']['from'] );
		$this->assertSame( 'https://example.com/new', $result['canonical']['to'] );

		// The write actually stuck (re-read through Yoast's getter).
		$this->assertSame(
			'https://example.com/new',
			(string) YoastPlugin::getTermMeta( $term_id, 'category', 'canonical' ),
			'The new canonical must be stored.'
		);
	}

	public function test_empty_string_clears_the_canonical(): void {
		$term_id = $this->seedTerm();

		WPSEO_Taxonomy_Meta::set_values( $term_id, 'category', array( 'wpseo_canonical' => 'https://example.com/old' ) );

		$result = wp_get_ability( self::ABILITY )->execute(
			array(
				'taxonomy'  => 'category',
				'term_id'   => $term_id,
				'canonical' => '',
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'https://example.com/old', $result['canonical']['from'] );
		$this->assertSame( '', $result['canonical']['to'], 'An empty string must clear the override.' );
		$this->assertSame(
			'',
			(string) YoastPlugin::getTermMeta( $term_id, 'category', 'canonical' ),
			'Clearing the canonical resets it to the empty default.'
		);
	}

	public function test_stored_value_is_esc_url_raw_sanitized(): void {
		$term_id = $this->seedTerm();

		$result = wp_get_ability( self::ABILITY )->execute(
			array(
				'taxonomy'  => 'category',
				'term_id'   => $term_id,
				'canonical' => 'javascript:alert(1)',
			)
		);

		$this->assertIsArray( $result );

		$stored = (string) YoastPlugin::getTermMeta( $term_id, 'category', 'canonical' );
		$this->assertStringNotContainsString(
			'javascript:',
			$stored,
			'A script-y URL must be sanitized by esc_url_raw, not stored raw.'
		);
		$this->assertSame( $stored, $result['canonical']['to'], 'The old→new "to" must be the sanitized stored value.' );
	}

	public function test_default_admin_passes_advanced_gate_via_wpseo_manage_options(): void {
		// Default install: disableadvanced_meta is on, so the advanced gate is live.
		$this->assertTrue(
			YoastPlugin::getDisableAdvancedMeta(),
			'This assertion only holds while the advanced-meta restriction is at its default (on).'
		);

		$term_id = $this->seedTerm();

		// A default administrator holds wpseo_manage_options and the edit-term cap but
		// NOT the raw wpseo_edit_advanced_metadata cap — so it may pass ONLY via the OR path.
		$this->assertFalse(
			current_user_can( 'wpseo_edit_advanced_metadata' ),
			'A default administrator must not hold the raw advanced cap (research-findings §8).'
		);
		$this->assertTrue( current_user_can( 'wpseo_manage_options' ) );
		$this->assertTrue( current_user_can( 'edit_term', $term_id ) );

		$result = wp_get_ability( self::ABILITY )->execute(
			array(
				'taxonomy'  => 'category',
				'term_id'   => $term_id,
				'canonical' => 'https://example.com/admin-set',
			)
		);

		$this->assertIsArray(
			$result,
			'A default admin must be allowed via the OR-wpseo_manage_options path, not blocked by a raw-cap-only check.'
		);
		$this->assertSame( 'https://example.com/admin-set', $result['canonical']['to'] );
	}

	public function test_missing_term_returns_typed_not_found_error(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( self::ABILITY )->execute(
			array(
				'taxonomy'  => 'category',
				'term_id'   => 999999,
				'canonical' => 'https://example.com/page',
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

	public function test_under_privileged_user_is_denied(): void {
		$term_id = $this->seedTerm();

		// A subscriber cannot edit terms.
		$this->actingAs( 'subscriber' );

		$result = wp_get_ability( self::ABILITY )->execute(
			array(
				'taxonomy'  => 'category',
				'term_id'   => $term_id,
				'canonical' => 'https://example.com/page',
			)
		);

		$this->assertWPError( $result );
		$this->assertSame(
			'ability_invalid_permissions',
			$result->get_error_code(),
			'A caller without the taxonomy edit-term cap must be denied by the Abilities API.'
		);
	}
}
