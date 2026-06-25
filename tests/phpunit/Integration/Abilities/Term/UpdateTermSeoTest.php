<?php
/**
 * Integration tests for the og-yoast/update-term-seo ability.
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
 * Exercises the per-term Yoast SEO write end to end through the Abilities API.
 *
 * Fixtures are seeded through Yoast's own save path
 * (`WPSEO_Taxonomy_Meta::set_values`, `WPSEO_Options::set`) and the result is read back
 * through the ability so each assertion sees real stored values. The whole class
 * self-skips when Yoast SEO is inactive, since the ability does not register then.
 */
final class UpdateTermSeoTest extends TestCase {

	private const ABILITY = 'og-yoast/update-term-seo';

	/**
	 * Skips the class when Yoast SEO is inactive.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		if ( ! YoastPlugin::isActive() ) {
			$this->markTestSkipped( 'Yoast SEO is not active; og-yoast/update-term-seo does not register.' );
		}
	}

	/**
	 * Creates a category term and makes an administrator the current user.
	 *
	 * @return int The created term ID.
	 */
	private function seedTerm(): int {
		$this->actingAs( 'administrator' );

		return self::factory()->term->create( array( 'taxonomy' => 'category' ) );
	}

	public function test_it_registers(): void {
		$this->assertNotNull(
			wp_get_ability( self::ABILITY ),
			'og-yoast/update-term-seo must resolve after wp_abilities_api_init.'
		);
	}

	public function test_happy_path_writes_basic_fields_and_returns_curated_shape_in_order(): void {
		$term_id = $this->seedTerm();

		// Off so the result is the bare non-social shape (deterministic key order).
		WPSEO_Options::set( 'opengraph', false );
		WPSEO_Options::set( 'twitter', false );

		$result = wp_get_ability( self::ABILITY )->execute(
			array(
				'taxonomy'         => 'category',
				'term_id'          => $term_id,
				'title'            => 'Best Orange Widgets',
				'description'      => 'A guide to orange widgets.',
				'focus_keyphrase'  => 'orange widgets',
				'is_cornerstone'   => true,
				'breadcrumb_title' => 'Orange Widgets',
			)
		);

		$this->assertIsArray( $result, 'A permitted write must return the SEO row, not a WP_Error.' );

		$this->assertSame(
			array(
				'taxonomy',
				'term_id',
				'title',
				'description',
				'focus_keyphrase',
				'canonical',
				'breadcrumb_title',
				'noindex',
				'is_cornerstone',
			),
			array_keys( $result ),
			'The result must be the curated term SEO row in the documented key order (social keys absent when their flags are off).'
		);

		$this->assertSame( 'category', $result['taxonomy'] );
		$this->assertSame( $term_id, $result['term_id'] );
		$this->assertSame( 'Best Orange Widgets', $result['title'] );
		$this->assertSame( 'A guide to orange widgets.', $result['description'] );
		$this->assertSame( 'orange widgets', $result['focus_keyphrase'] );
		$this->assertSame( 'Orange Widgets', $result['breadcrumb_title'] );
		$this->assertSame( 'default', $result['noindex'], 'noindex is untouched and stays default.' );
		$this->assertTrue( $result['is_cornerstone'] );

		// The values must actually be stored through Yoast, not just echoed.
		$this->assertSame(
			'orange widgets',
			WPSEO_Taxonomy_Meta::get_term_meta( $term_id, 'category', 'focuskw' )
		);
		$this->assertSame(
			'1',
			WPSEO_Taxonomy_Meta::get_term_meta( $term_id, 'category', 'is_cornerstone' )
		);
	}

	public function test_write_then_read_returns_new_values(): void {
		$term_id = $this->seedTerm();

		// Seed an existing value through Yoast's own save path.
		WPSEO_Taxonomy_Meta::set_values(
			$term_id,
			'category',
			array( 'wpseo_title' => 'Seeded Title' )
		);

		// Change only the focus keyphrase; the seeded title must survive (partial update).
		$result = wp_get_ability( self::ABILITY )->execute(
			array(
				'taxonomy'        => 'category',
				'term_id'         => $term_id,
				'focus_keyphrase' => 'new keyphrase',
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'new keyphrase', $result['focus_keyphrase'], 'The re-read must reflect the new value (void-return re-read path).' );
		$this->assertSame( 'Seeded Title', $result['title'], 'An unsent field must be left untouched.' );
	}

	public function test_writing_default_resets_the_field(): void {
		$term_id = $this->seedTerm();

		WPSEO_Taxonomy_Meta::set_values(
			$term_id,
			'category',
			array( 'wpseo_title' => 'Seeded Title' )
		);

		$result = wp_get_ability( self::ABILITY )->execute(
			array(
				'taxonomy' => 'category',
				'term_id'  => $term_id,
				'title'    => '',
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( '', $result['title'], 'Writing the default resets the field; the re-read returns the default.' );
	}

	public function test_missing_term_returns_typed_not_found_error(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( self::ABILITY )->execute(
			array(
				'taxonomy'        => 'category',
				'term_id'         => 999999,
				'focus_keyphrase' => 'irrelevant',
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

		// A subscriber cannot edit terms (no manage_categories cap).
		$this->actingAs( 'subscriber' );

		$result = wp_get_ability( self::ABILITY )->execute(
			array(
				'taxonomy'        => 'category',
				'term_id'         => $term_id,
				'focus_keyphrase' => 'denied',
			)
		);

		$this->assertWPError( $result );
		$this->assertSame(
			'ability_invalid_permissions',
			$result->get_error_code(),
			'A caller without the edit-terms cap must be denied by the Abilities API.'
		);
	}

	public function test_read_back_failure_surfaces_its_own_code(): void {
		$term_id = $this->seedTerm();

		// Force the re-read of the term meta to never reflect what was written, so the
		// write-confirmation fails. The ability re-reads through
		// WPSEO_Taxonomy_Meta::get_term_meta(), which reads the wpseo_taxonomy_meta
		// option, so the filter mutates that option's value out from under the read.
		$mutator = static function ( $value ) use ( $term_id ) {
			if ( is_array( $value ) && isset( $value['category'][ $term_id ] ) && is_array( $value['category'][ $term_id ] ) ) {
				$value['category'][ $term_id ]['wpseo_focuskw'] = 'mutated-elsewhere';
			}

			return $value;
		};
		add_filter( 'option_wpseo_taxonomy_meta', $mutator );

		$result = wp_get_ability( self::ABILITY )->execute(
			array(
				'taxonomy'        => 'category',
				'term_id'         => $term_id,
				'focus_keyphrase' => 'will-not-stick',
			)
		);

		remove_filter( 'option_wpseo_taxonomy_meta', $mutator );

		$this->assertWPError( $result, 'A read-back mismatch must surface a typed error.' );
		$this->assertSame(
			'yoast_term_update_failed',
			$result->get_error_code(),
			'The read-back-failure code must surface, not be collapsed into a permission error.'
		);
	}
}
