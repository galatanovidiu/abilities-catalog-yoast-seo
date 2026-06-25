<?php
/**
 * Integration tests for the og-yoast/update-post-schema ability.
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
 * Exercises the per-post Yoast schema write end to end through the Abilities API.
 *
 * Fixtures are seeded through Yoast's own save path (`WPSEO_Meta::set_value`) so
 * the ability reads and writes real stored values. The whole class self-skips when
 * Yoast SEO is inactive, since the ability does not register then.
 */
final class UpdatePostSchemaTest extends TestCase {

	private const ABILITY = 'og-yoast/update-post-schema';

	/**
	 * Skips the class when Yoast SEO is inactive.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		if ( ! YoastPlugin::isActive() ) {
			$this->markTestSkipped( 'Yoast SEO is not active; og-yoast/update-post-schema does not register.' );
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
			'og-yoast/update-post-schema must resolve after wp_abilities_api_init.'
		);
	}

	public function test_happy_path_sets_schema_types_and_breadcrumb(): void {
		$post_id = $this->seedPost();

		// Yoast enables Open Graph and X/Twitter by default; turn them off so this
		// case asserts the bare non-social curated shape.
		WPSEO_Options::set( 'opengraph', false );
		WPSEO_Options::set( 'twitter', false );

		// Use enum members straight off the facade so the test tracks Yoast's own list.
		$page_type    = YoastPlugin::schemaPageTypes()[0];
		$article_type = YoastPlugin::schemaArticleTypes()[0];

		$result = wp_get_ability( self::ABILITY )->execute(
			array(
				'post_id'             => $post_id,
				'schema_page_type'    => $page_type,
				'schema_article_type' => $article_type,
				'breadcrumb_title'    => 'My Crumb',
			)
		);

		$this->assertIsArray( $result, 'A permitted write must return the curated SEO object, not a WP_Error.' );

		$row = (array) $result;

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
			array_keys( $row ),
			'The curated object must carry the get-post-seo key order (social keys absent when their flags are off).'
		);

		$this->assertSame( $page_type, $row['schema_page_type'] );
		$this->assertSame( $article_type, $row['schema_article_type'] );
		$this->assertSame( 'My Crumb', $row['breadcrumb_title'] );

		// The values were stored through Yoast's own API, not just echoed back.
		$this->assertSame( $page_type, YoastPlugin::getPostMetaValue( 'schema_page_type', $post_id ) );
		$this->assertSame( $article_type, YoastPlugin::getPostMetaValue( 'schema_article_type', $post_id ) );
		$this->assertSame( 'My Crumb', YoastPlugin::getPostMetaValue( 'bctitle', $post_id ) );
	}

	public function test_empty_schema_type_clears_the_override(): void {
		$post_id = $this->seedPost();

		$page_type = YoastPlugin::schemaPageTypes()[0];

		// Seed a non-default page type through Yoast's own save path.
		WPSEO_Meta::set_value( 'schema_page_type', $page_type, $post_id );
		$this->assertSame( $page_type, YoastPlugin::getPostMetaValue( 'schema_page_type', $post_id ) );

		$result = wp_get_ability( self::ABILITY )->execute(
			array(
				'post_id'          => $post_id,
				'schema_page_type' => '',
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( '', ( (array) $result )['schema_page_type'], 'An empty schema_page_type clears the override (use type default).' );
		$this->assertSame( '', YoastPlugin::getPostMetaValue( 'schema_page_type', $post_id ) );
	}

	public function test_out_of_enum_schema_value_is_rejected(): void {
		$post_id = $this->seedPost();

		$result = wp_get_ability( self::ABILITY )->execute(
			array(
				'post_id'          => $post_id,
				'schema_page_type' => 'NotARealSchemaType',
			)
		);

		$this->assertWPError( $result, 'A schema_page_type outside the closed enum must be rejected by validation.' );
		$this->assertSame(
			'ability_invalid_input',
			$result->get_error_code(),
			'An out-of-enum value must fail input validation, not be written.'
		);
	}

	/**
	 * A default-install administrator holds `edit_post` and `wpseo_manage_options`
	 * but NOT the raw `wpseo_edit_advanced_metadata` cap, with `disableadvanced_meta`
	 * at its `true` default. The write must still be permitted — only via the
	 * OR-`wpseo_manage_options` path (research-findings §8).
	 *
	 * @return void
	 */
	public function test_advanced_cap_default_admin_allowed_only_via_manage_options(): void {
		$post_id = $this->seedPost();

		// Confirm the test's premise: the default admin lacks the raw advanced cap,
		// the restriction is on, but holds wpseo_manage_options.
		$this->assertTrue( (bool) YoastPlugin::getDisableAdvancedMeta(), 'disableadvanced_meta must default true for this assertion to be load-bearing.' );
		$this->assertFalse( current_user_can( 'wpseo_edit_advanced_metadata' ), 'A default administrator must NOT hold the raw advanced cap.' );
		$this->assertTrue( current_user_can( 'wpseo_manage_options' ), 'A default administrator must hold wpseo_manage_options.' );

		$page_type = YoastPlugin::schemaPageTypes()[0];

		$result = wp_get_ability( self::ABILITY )->execute(
			array(
				'post_id'          => $post_id,
				'schema_page_type' => $page_type,
			)
		);

		$this->assertIsArray( $result, 'A default admin must be allowed to write advanced schema via the OR-wpseo_manage_options path.' );
		$this->assertSame( $page_type, ( (array) $result )['schema_page_type'] );
	}

	public function test_under_privileged_user_is_denied(): void {
		$author_id = $this->actingAs( 'administrator' );
		$post_id   = self::factory()->post->create( array( 'post_author' => $author_id ) );

		// A subscriber cannot edit this post.
		$this->actingAs( 'subscriber' );

		$result = wp_get_ability( self::ABILITY )->execute(
			array(
				'post_id'          => $post_id,
				'schema_page_type' => YoastPlugin::schemaPageTypes()[0],
			)
		);

		$this->assertWPError( $result );
		$this->assertSame(
			'ability_invalid_permissions',
			$result->get_error_code(),
			'A caller without edit_post on the post must be denied by the Abilities API.'
		);
	}

	public function test_missing_post_returns_typed_not_found_error(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( self::ABILITY )->execute(
			array(
				'post_id'          => 999999,
				'schema_page_type' => YoastPlugin::schemaPageTypes()[0],
			)
		);

		$this->assertWPError( $result, 'A missing post must surface a typed error.' );
		$this->assertSame( 'yoast_post_not_found', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] ?? null );
	}
}
