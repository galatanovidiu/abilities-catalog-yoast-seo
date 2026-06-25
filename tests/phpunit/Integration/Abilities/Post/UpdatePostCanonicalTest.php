<?php
/**
 * Integration tests for the og-yoast/update-post-canonical ability.
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
 * Exercises the per-post canonical write end to end through the Abilities API.
 *
 * Fixtures are seeded through Yoast's own save path (`WPSEO_Meta::set_value`). The
 * whole class self-skips when Yoast SEO is inactive, since the ability does not
 * register then.
 */
final class UpdatePostCanonicalTest extends TestCase {

	private const ABILITY = 'og-yoast/update-post-canonical';

	/**
	 * Skips the class when Yoast SEO is inactive.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		if ( ! YoastPlugin::isActive() ) {
			$this->markTestSkipped( 'Yoast SEO is not active; og-yoast/update-post-canonical does not register.' );
		}

		// Keep the bare non-social shape for the curated row assertions.
		WPSEO_Options::set( 'opengraph', false );
		WPSEO_Options::set( 'twitter', false );
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
			'og-yoast/update-post-canonical must resolve after wp_abilities_api_init.'
		);
	}

	public function test_happy_path_sets_canonical_and_returns_old_to_new_block(): void {
		$post_id = $this->seedPost();

		// Seed a prior canonical through Yoast's own save path.
		WPSEO_Meta::set_value( 'canonical', 'https://example.com/old', $post_id );

		$result = wp_get_ability( self::ABILITY )->execute(
			array(
				'post_id'   => $post_id,
				'canonical' => 'https://example.com/new',
			)
		);

		$this->assertIsArray( $result, 'A permitted write must return the SEO row, not a WP_Error.' );

		$this->assertIsArray( $result['canonical'], 'The canonical key must carry the old→new block.' );
		$this->assertSame( 'https://example.com/old', $result['canonical']['from'] );
		$this->assertSame( 'https://example.com/new', $result['canonical']['to'] );

		// The write actually stuck.
		$this->assertSame(
			'https://example.com/new',
			YoastPlugin::getPostMetaValue( 'canonical', $post_id ),
			'The new canonical must be stored.'
		);
	}

	public function test_curated_row_keeps_get_post_seo_key_order(): void {
		$post_id = $this->seedPost();

		$result = wp_get_ability( self::ABILITY )->execute(
			array(
				'post_id'   => $post_id,
				'canonical' => 'https://example.com/page',
			)
		);

		$this->assertIsArray( $result );

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
			'The curated row must match get-post-seo key order (social keys absent when their flags are off).'
		);
	}

	public function test_empty_string_clears_the_canonical(): void {
		$post_id = $this->seedPost();

		WPSEO_Meta::set_value( 'canonical', 'https://example.com/old', $post_id );

		$result = wp_get_ability( self::ABILITY )->execute(
			array(
				'post_id'   => $post_id,
				'canonical' => '',
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'https://example.com/old', $result['canonical']['from'] );
		$this->assertSame( '', $result['canonical']['to'], 'An empty string must clear the override.' );
		$this->assertSame(
			'',
			YoastPlugin::getPostMetaValue( 'canonical', $post_id ),
			'Writing the default deletes the row, so the re-read returns the empty default.'
		);
	}

	public function test_stored_value_is_esc_url_raw_sanitized(): void {
		$post_id = $this->seedPost();

		$result = wp_get_ability( self::ABILITY )->execute(
			array(
				'post_id'   => $post_id,
				'canonical' => 'javascript:alert(1)',
			)
		);

		$this->assertIsArray( $result );

		$stored = YoastPlugin::getPostMetaValue( 'canonical', $post_id );
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

		$admin_id = $this->actingAs( 'administrator' );
		$post_id  = self::factory()->post->create( array( 'post_author' => $admin_id ) );

		// A default administrator holds wpseo_manage_options but NOT the raw
		// wpseo_edit_advanced_metadata cap — so it may pass ONLY via the OR path.
		$this->assertFalse(
			current_user_can( 'wpseo_edit_advanced_metadata' ),
			'A default administrator must not hold the raw advanced cap (research-findings §8).'
		);
		$this->assertTrue( current_user_can( 'wpseo_manage_options' ) );

		$result = wp_get_ability( self::ABILITY )->execute(
			array(
				'post_id'   => $post_id,
				'canonical' => 'https://example.com/admin-set',
			)
		);

		$this->assertIsArray(
			$result,
			'A default admin must be allowed via the OR-wpseo_manage_options path, not blocked by a raw-cap-only check.'
		);
		$this->assertSame( 'https://example.com/admin-set', $result['canonical']['to'] );
	}

	public function test_missing_post_returns_typed_not_found_error(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( self::ABILITY )->execute(
			array(
				'post_id'   => 999999,
				'canonical' => 'https://example.com/page',
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
				'post_id'   => $post_id,
				'canonical' => 'https://example.com/page',
			)
		);

		$this->assertWPError( $result );
		$this->assertSame(
			'ability_invalid_permissions',
			$result->get_error_code(),
			'A caller without edit_post on the post must be denied by the Abilities API.'
		);
	}
}
