<?php
/**
 * Integration tests for the og-yoast/get-post-seo ability.
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
 * Exercises the per-post Yoast SEO read end to end through the Abilities API.
 *
 * Fixtures are seeded through Yoast's own save path (`WPSEO_Meta::set_value`,
 * `WPSEO_Options::set`) so the ability reads real stored values. The whole class
 * self-skips when Yoast SEO is inactive, since the ability does not register then.
 */
final class GetPostSeoTest extends TestCase {

	private const ABILITY = 'og-yoast/get-post-seo';

	/**
	 * Skips the class when Yoast SEO is inactive.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		if ( ! YoastPlugin::isActive() ) {
			$this->markTestSkipped( 'Yoast SEO is not active; og-yoast/get-post-seo does not register.' );
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
			'og-yoast/get-post-seo must resolve after wp_abilities_api_init.'
		);
	}

	public function test_happy_path_returns_flat_seeded_values_with_readable_robots_label(): void {
		$post_id = $this->seedPost();

		// Yoast enables Open Graph and X/Twitter by default; turn them off so this
		// case asserts the bare non-social shape.
		WPSEO_Options::set( 'opengraph', false );
		WPSEO_Options::set( 'twitter', false );

		WPSEO_Meta::set_value( 'focuskw', 'orange widgets', $post_id );
		WPSEO_Meta::set_value( 'title', 'Best Orange Widgets', $post_id );
		WPSEO_Meta::set_value( 'metadesc', 'A guide to orange widgets.', $post_id );
		WPSEO_Meta::set_value( 'canonical', 'https://example.com/widgets', $post_id );
		WPSEO_Meta::set_value( 'meta-robots-noindex', '1', $post_id );
		WPSEO_Meta::set_value( 'meta-robots-nofollow', '1', $post_id );
		WPSEO_Meta::set_value( 'is_cornerstone', '1', $post_id );
		WPSEO_Meta::set_value( 'bctitle', 'Widgets', $post_id );

		$result = wp_get_ability( self::ABILITY )->execute( array( 'post_id' => $post_id ) );

		$this->assertIsArray( $result, 'A permitted read must return the SEO row, not a WP_Error.' );

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
			'The flat row must carry the documented keys in order (social keys absent when their flags are off).'
		);

		$this->assertSame( $post_id, $result['post_id'] );
		$this->assertSame( 'orange widgets', $result['focus_keyphrase'] );
		$this->assertSame( 'Best Orange Widgets', $result['seo_title'] );
		$this->assertSame( 'A guide to orange widgets.', $result['meta_description'] );
		$this->assertSame( 'https://example.com/widgets', $result['canonical'] );
		$this->assertSame( 'noindex', $result['robots_noindex'], 'The tri-state "1" must surface as the readable "noindex" label.' );
		$this->assertSame( 'nofollow', $result['robots_nofollow'] );
		$this->assertTrue( $result['is_cornerstone'] );
		$this->assertSame( 'Widgets', $result['breadcrumb_title'] );
		$this->assertSame( array(), $result['robots_advanced'] );
	}

	public function test_robots_advanced_csv_is_split_to_a_token_list(): void {
		$post_id = $this->seedPost();

		WPSEO_Meta::set_value( 'meta-robots-adv', 'noimageindex,nosnippet', $post_id );

		$result = wp_get_ability( self::ABILITY )->execute( array( 'post_id' => $post_id ) );

		$this->assertIsArray( $result );
		$this->assertSame( array( 'noimageindex', 'nosnippet' ), $result['robots_advanced'] );
	}

	public function test_social_keys_present_when_flags_on(): void {
		$post_id = $this->seedPost();

		WPSEO_Options::set( 'opengraph', true );
		WPSEO_Options::set( 'twitter', true );

		WPSEO_Meta::set_value( 'opengraph-title', 'OG title', $post_id );
		WPSEO_Meta::set_value( 'twitter-title', 'X title', $post_id );

		$result = wp_get_ability( self::ABILITY )->execute( array( 'post_id' => $post_id ) );

		$this->assertIsArray( $result );

		foreach ( array( 'opengraph_title', 'opengraph_description', 'opengraph_image', 'opengraph_image_id', 'twitter_title', 'twitter_description', 'twitter_image', 'twitter_image_id' ) as $key ) {
			$this->assertArrayHasKey( $key, $result, $key . ' must be present when its social flag is on.' );
		}

		$this->assertSame( 'OG title', $result['opengraph_title'] );
		$this->assertSame( 'X title', $result['twitter_title'] );
	}

	public function test_social_keys_absent_when_flags_off(): void {
		$post_id = $this->seedPost();

		WPSEO_Options::set( 'opengraph', false );
		WPSEO_Options::set( 'twitter', false );

		$result = wp_get_ability( self::ABILITY )->execute( array( 'post_id' => $post_id ) );

		$this->assertIsArray( $result );

		foreach ( array( 'opengraph_title', 'opengraph_description', 'opengraph_image', 'opengraph_image_id', 'twitter_title', 'twitter_description', 'twitter_image', 'twitter_image_id' ) as $key ) {
			$this->assertArrayNotHasKey( $key, $result, $key . ' must be omitted when its social flag is off.' );
		}
	}

	public function test_unset_schema_page_type_returns_empty_string(): void {
		$post_id = $this->seedPost();

		$result = wp_get_ability( self::ABILITY )->execute( array( 'post_id' => $post_id ) );

		$this->assertIsArray( $result );
		$this->assertSame( '', $result['schema_page_type'], 'An unset schema_page_type means "use type default" — returned as an empty string, not resolved.' );
		$this->assertSame( '', $result['schema_article_type'] );
	}

	public function test_missing_post_returns_typed_not_found_error(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( self::ABILITY )->execute( array( 'post_id' => 999999 ) );

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

		$result = wp_get_ability( self::ABILITY )->execute( array( 'post_id' => $post_id ) );

		$this->assertWPError( $result );
		$this->assertSame(
			'ability_invalid_permissions',
			$result->get_error_code(),
			'A caller without edit_post on the post must be denied by the Abilities API.'
		);
	}
}
