<?php
/**
 * Integration tests for the og-yoast/update-post-seo ability.
 *
 * @package AbilitiesCatalogYoast\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogYoast\Tests\Integration\Abilities\Post;

use GalatanOvidiu\AbilitiesCatalogYoast\Support\YoastPlugin;
use GalatanOvidiu\AbilitiesCatalogYoast\Tests\TestCase;
use WPSEO_Meta;
use WPSEO_Options;

/**
 * Exercises the per-post Yoast SEO write end to end through the Abilities API.
 *
 * Fixtures are seeded through Yoast's own save path (`WPSEO_Meta::set_value`,
 * `WPSEO_Options::set`) and the result is read back through the ability so each
 * assertion sees real stored values. The whole class self-skips when Yoast SEO is
 * inactive, since the ability does not register then.
 */
final class UpdatePostSeoTest extends TestCase {

	private const ABILITY = 'og-yoast/update-post-seo';

	/**
	 * Skips the class when Yoast SEO is inactive.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		if ( ! YoastPlugin::isActive() ) {
			$this->markTestSkipped( 'Yoast SEO is not active; og-yoast/update-post-seo does not register.' );
		}
	}

	/**
	 * Creates a post and makes an administrator the current user.
	 *
	 * @return int The created post ID.
	 */
	private function seedPost(): int {
		$this->actingAs( 'administrator' );

		return self::factory()->post->create();
	}

	public function test_it_registers(): void {
		$this->assertNotNull(
			wp_get_ability( self::ABILITY ),
			'og-yoast/update-post-seo must resolve after wp_abilities_api_init.'
		);
	}

	public function test_happy_path_writes_basic_fields_and_returns_curated_shape_in_order(): void {
		$post_id = $this->seedPost();

		// Off so the result is the bare non-social shape (deterministic key order).
		WPSEO_Options::set( 'opengraph', false );
		WPSEO_Options::set( 'twitter', false );

		$result = wp_get_ability( self::ABILITY )->execute(
			array(
				'post_id'          => $post_id,
				'focus_keyphrase'  => 'orange widgets',
				'seo_title'        => 'Best Orange Widgets',
				'meta_description' => 'A guide to orange widgets.',
				'is_cornerstone'   => true,
			)
		);

		$this->assertIsArray( $result, 'A permitted write must return the SEO row, not a WP_Error.' );

		$this->assertSame(
			array(
				'post_id',
				'focus_keyphrase',
				'seo_title',
				'meta_description',
				'canonical',
				'robots_noindex',
				'robots_nofollow',
				'robots_advanced',
				'is_cornerstone',
				'breadcrumb_title',
				'schema_page_type',
				'schema_article_type',
			),
			array_keys( $result ),
			'The result must be the curated SEO row in the documented key order (social keys absent when their flags are off).'
		);

		$this->assertSame( $post_id, $result['post_id'] );
		$this->assertSame( 'orange widgets', $result['focus_keyphrase'] );
		$this->assertSame( 'Best Orange Widgets', $result['seo_title'] );
		$this->assertSame( 'A guide to orange widgets.', $result['meta_description'] );
		$this->assertTrue( $result['is_cornerstone'] );

		// The values must actually be stored through Yoast, not just echoed.
		$this->assertSame( 'orange widgets', WPSEO_Meta::get_value( 'focuskw', $post_id ) );
		$this->assertSame( '1', WPSEO_Meta::get_value( 'is_cornerstone', $post_id ) );
	}

	public function test_partial_update_leaves_unsent_fields_untouched(): void {
		$post_id = $this->seedPost();

		WPSEO_Meta::set_value( 'focuskw', 'seeded keyphrase', $post_id );
		WPSEO_Meta::set_value( 'title', 'Seeded Title', $post_id );

		// Send only the meta description; the seeded fields must survive.
		$result = wp_get_ability( self::ABILITY )->execute(
			array(
				'post_id'          => $post_id,
				'meta_description' => 'New description.',
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'seeded keyphrase', $result['focus_keyphrase'] );
		$this->assertSame( 'Seeded Title', $result['seo_title'] );
		$this->assertSame( 'New description.', $result['meta_description'] );
	}

	public function test_social_overrides_write_when_flags_on(): void {
		$post_id = $this->seedPost();

		WPSEO_Options::set( 'opengraph', true );
		WPSEO_Options::set( 'twitter', true );

		$result = wp_get_ability( self::ABILITY )->execute(
			array(
				'post_id'         => $post_id,
				'opengraph_title' => 'OG title',
				'twitter_title'   => 'X title',
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'OG title', $result['opengraph_title'] );
		$this->assertSame( 'X title', $result['twitter_title'] );
		$this->assertSame( 'OG title', WPSEO_Meta::get_value( 'opengraph-title', $post_id ) );
	}

	public function test_writing_default_resets_the_field(): void {
		$post_id = $this->seedPost();

		// Seed a value, then write its default ('' for the SEO title) to reset it.
		WPSEO_Meta::set_value( 'title', 'Seeded Title', $post_id );
		$this->assertSame( 'Seeded Title', WPSEO_Meta::get_value( 'title', $post_id ) );

		$result = wp_get_ability( self::ABILITY )->execute(
			array(
				'post_id'   => $post_id,
				'seo_title' => '',
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( '', $result['seo_title'], 'Writing the default resets the field; the re-read returns the default.' );
		$this->assertSame( '', WPSEO_Meta::get_value( 'title', $post_id ), 'The stored row must be reset to the default.' );
	}

	public function test_cornerstone_false_resets_the_flag(): void {
		$post_id = $this->seedPost();

		WPSEO_Meta::set_value( 'is_cornerstone', '1', $post_id );

		$result = wp_get_ability( self::ABILITY )->execute(
			array(
				'post_id'        => $post_id,
				'is_cornerstone' => false,
			)
		);

		$this->assertIsArray( $result );
		$this->assertFalse( $result['is_cornerstone'], 'is_cornerstone=false resets the flag to its default (off).' );
	}

	public function test_missing_post_returns_typed_not_found_error(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( self::ABILITY )->execute(
			array(
				'post_id'         => 999999,
				'focus_keyphrase' => 'irrelevant',
			)
		);

		$this->assertWPError( $result, 'A missing post must surface a typed error, not a row.' );
		$this->assertSame(
			'yoast_post_not_found',
			$result->get_error_code(),
			'The typed not-found code must surface, not be collapsed into a permission error.'
		);
		$this->assertSame( 404, $result->get_error_data()['status'] ?? null );
	}

	public function test_under_privileged_user_is_denied(): void {
		$author_id = $this->actingAs( 'administrator' );
		$post_id   = self::factory()->post->create( array( 'post_author' => $author_id ) );

		// A subscriber cannot edit this post.
		$this->actingAs( 'subscriber' );

		$result = wp_get_ability( self::ABILITY )->execute(
			array(
				'post_id'         => $post_id,
				'focus_keyphrase' => 'denied',
			)
		);

		$this->assertWPError( $result );
		$this->assertSame(
			'ability_invalid_permissions',
			$result->get_error_code(),
			'A caller without edit_post on the post must be denied by the Abilities API.'
		);
	}

	public function test_read_back_failure_surfaces_its_own_code(): void {
		$post_id = $this->seedPost();

		// Force the read-back of focuskw to never match what was sent, so the
		// write-confirmation fails. This simulates a sanitizer/storage layer mutating
		// the value out from under the write. WPSEO_Meta::get_value() reads through
		// get_post_custom() (an all-meta read with an empty meta key), so the filter
		// intercepts that read and mutates just the focuskw entry — removing itself
		// while it fetches the real meta to avoid recursing.
		$mutator = function ( $check, $object_id, $meta_key ) use ( $post_id, &$mutator ) {
			if ( $object_id === $post_id && '' === $meta_key ) {
				remove_filter( 'get_post_metadata', $mutator, 10 );
				$all = get_post_meta( $post_id );
				add_filter( 'get_post_metadata', $mutator, 10, 3 );

				$all['_yoast_wpseo_focuskw'] = array( 'mutated-elsewhere' );

				return $all;
			}

			return $check;
		};
		add_filter( 'get_post_metadata', $mutator, 10, 3 );

		$result = wp_get_ability( self::ABILITY )->execute(
			array(
				'post_id'         => $post_id,
				'focus_keyphrase' => 'will-not-stick',
			)
		);

		$this->assertWPError( $result, 'A read-back mismatch must surface a typed error.' );
		$this->assertSame(
			'yoast_post_seo_write_failed',
			$result->get_error_code(),
			'The read-back-failure code must surface, not be collapsed into a permission error.'
		);
	}
}
