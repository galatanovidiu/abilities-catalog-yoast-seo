<?php
/**
 * Integration tests for the og-yoast/get-term-seo ability.
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
 * Exercises the per-term Yoast SEO read end to end through the Abilities API.
 *
 * Fixtures are seeded through Yoast's own save path
 * (`WPSEO_Taxonomy_Meta::set_values`, `WPSEO_Options::set`) so the ability reads
 * real stored values. The whole class self-skips when Yoast SEO is inactive,
 * since the ability does not register then.
 */
final class GetTermSeoTest extends TestCase {

	private const ABILITY = 'og-yoast/get-term-seo';

	/**
	 * Skips the class when Yoast SEO is inactive.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		if ( ! YoastPlugin::isActive() ) {
			$this->markTestSkipped( 'Yoast SEO is not active; og-yoast/get-term-seo does not register.' );
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
			'og-yoast/get-term-seo must resolve after wp_abilities_api_init.'
		);
	}

	public function test_happy_path_returns_flat_seeded_values_in_order(): void {
		$term_id = $this->seedTerm();

		// Yoast enables Open Graph and X/Twitter by default; turn them off so this
		// case asserts the bare non-social shape.
		WPSEO_Options::set( 'opengraph', false );
		WPSEO_Options::set( 'twitter', false );

		WPSEO_Taxonomy_Meta::set_values(
			$term_id,
			'category',
			array(
				'wpseo_title'          => 'Best Orange Widgets',
				'wpseo_desc'           => 'A guide to orange widgets.',
				'wpseo_focuskw'        => 'orange widgets',
				'wpseo_canonical'      => 'https://example.com/widgets',
				'wpseo_bctitle'        => 'Widgets',
				'wpseo_noindex'        => 'noindex',
				'wpseo_is_cornerstone' => '1',
			)
		);

		$result = wp_get_ability( self::ABILITY )->execute(
			array(
				'taxonomy' => 'category',
				'term_id'  => $term_id,
			)
		);

		$this->assertIsArray( $result, 'A permitted read must return the SEO row, not a WP_Error.' );

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
			'The flat row must carry the documented keys in order (social keys absent when their flags are off).'
		);

		$this->assertSame( 'category', $result['taxonomy'] );
		$this->assertSame( $term_id, $result['term_id'] );
		$this->assertSame( 'Best Orange Widgets', $result['title'] );
		$this->assertSame( 'A guide to orange widgets.', $result['description'] );
		$this->assertSame( 'orange widgets', $result['focus_keyphrase'] );
		$this->assertSame( 'https://example.com/widgets', $result['canonical'] );
		$this->assertSame( 'Widgets', $result['breadcrumb_title'] );
		$this->assertSame( 'noindex', $result['noindex'] );
		$this->assertTrue( $result['is_cornerstone'], 'The stored "1" cornerstone flag must surface as a bool true.' );
	}

	public function test_unset_fields_return_defaults(): void {
		$term_id = $this->seedTerm();

		WPSEO_Options::set( 'opengraph', false );
		WPSEO_Options::set( 'twitter', false );

		$result = wp_get_ability( self::ABILITY )->execute(
			array(
				'taxonomy' => 'category',
				'term_id'  => $term_id,
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( '', $result['title'] );
		$this->assertSame( 'default', $result['noindex'], 'An unset noindex defaults to "default" (inherits the taxonomy setting).' );
		$this->assertFalse( $result['is_cornerstone'], 'An unset cornerstone flag is false.' );
	}

	public function test_social_keys_present_when_flags_on(): void {
		$term_id = $this->seedTerm();

		WPSEO_Options::set( 'opengraph', true );
		WPSEO_Options::set( 'twitter', true );

		WPSEO_Taxonomy_Meta::set_values(
			$term_id,
			'category',
			array(
				'wpseo_opengraph-title' => 'OG title',
				'wpseo_twitter-title'   => 'X title',
			)
		);

		$result = wp_get_ability( self::ABILITY )->execute(
			array(
				'taxonomy' => 'category',
				'term_id'  => $term_id,
			)
		);

		$this->assertIsArray( $result );

		foreach ( array( 'opengraph_title', 'opengraph_description', 'opengraph_image', 'twitter_title', 'twitter_description', 'twitter_image' ) as $key ) {
			$this->assertArrayHasKey( $key, $result, $key . ' must be present when its social flag is on.' );
		}

		$this->assertSame( 'OG title', $result['opengraph_title'] );
		$this->assertSame( 'X title', $result['twitter_title'] );
	}

	public function test_social_keys_absent_when_flags_off(): void {
		$term_id = $this->seedTerm();

		WPSEO_Options::set( 'opengraph', false );
		WPSEO_Options::set( 'twitter', false );

		$result = wp_get_ability( self::ABILITY )->execute(
			array(
				'taxonomy' => 'category',
				'term_id'  => $term_id,
			)
		);

		$this->assertIsArray( $result );

		foreach ( array( 'opengraph_title', 'opengraph_description', 'opengraph_image', 'twitter_title', 'twitter_description', 'twitter_image' ) as $key ) {
			$this->assertArrayNotHasKey( $key, $result, $key . ' must be omitted when its social flag is off.' );
		}
	}

	public function test_missing_term_returns_typed_not_found_error(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( self::ABILITY )->execute(
			array(
				'taxonomy' => 'category',
				'term_id'  => 999999,
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

		// A subscriber cannot edit terms (lacks manage_categories).
		$this->actingAs( 'subscriber' );

		$result = wp_get_ability( self::ABILITY )->execute(
			array(
				'taxonomy' => 'category',
				'term_id'  => $term_id,
			)
		);

		$this->assertWPError( $result );
		$this->assertSame(
			'ability_invalid_permissions',
			$result->get_error_code(),
			'A caller without the taxonomy edit-terms cap must be denied by the Abilities API.'
		);
	}
}
