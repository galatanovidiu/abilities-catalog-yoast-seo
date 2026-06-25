<?php
/**
 * Integration tests for the og-yoast/update-post-robots ability.
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
 * Exercises the per-post Yoast robots write end to end through the Abilities API.
 *
 * Fixtures are seeded through Yoast's own save path (`WPSEO_Meta::set_value`) so the
 * ability reads and writes real stored values. The whole class self-skips when Yoast
 * SEO is inactive, since the ability does not register then.
 */
final class UpdatePostRobotsTest extends TestCase {

	private const ABILITY = 'og-yoast/update-post-robots';

	/**
	 * Skips the class when Yoast SEO is inactive.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		if ( ! YoastPlugin::isActive() ) {
			$this->markTestSkipped( 'Yoast SEO is not active; og-yoast/update-post-robots does not register.' );
		}

		// The advanced-meta restriction is the default; assert the gate against it.
		WPSEO_Options::set( 'disableadvanced_meta', true );
	}

	/**
	 * Creates a post owned by a fresh administrator and makes it the current user.
	 *
	 * @return int The created post ID.
	 */
	private function seedPost(): int {
		$author_id = $this->actingAs( 'administrator' );

		return self::factory()->post->create( array( 'post_author' => $author_id ) );
	}

	public function test_it_registers(): void {
		$this->assertNotNull(
			wp_get_ability( self::ABILITY ),
			'og-yoast/update-post-robots must resolve after wp_abilities_api_init.'
		);
	}

	public function test_happy_path_writes_each_flag_and_returns_the_curated_object(): void {
		$post_id = $this->seedPost();

		$result = wp_get_ability( self::ABILITY )->execute(
			array(
				'post_id'         => $post_id,
				'robots_noindex'  => 'noindex',
				'robots_nofollow' => true,
				'robots_advanced' => array( 'noimageindex', 'nosnippet' ),
			)
		);

		$this->assertIsObject( $result, 'A permitted write must return the curated SEO object, not a WP_Error.' );

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
				'changes',
			),
			array_keys( $row ),
			'The curated object must carry the get-post-seo key order, plus the trailing changes block.'
		);

		$this->assertSame( 'noindex', $row['robots_noindex'] );
		$this->assertSame( 'nofollow', $row['robots_nofollow'] );
		$this->assertSame( array( 'noimageindex', 'nosnippet' ), $row['robots_advanced'] );

		// The values were stored through Yoast's own API, not just echoed back.
		$this->assertSame( '1', YoastPlugin::getPostMetaValue( 'meta-robots-noindex', $post_id ) );
		$this->assertSame( '1', YoastPlugin::getPostMetaValue( 'meta-robots-nofollow', $post_id ) );
	}

	public function test_changes_block_reports_old_to_new_for_each_changed_flag(): void {
		$post_id = $this->seedPost();

		// Seed a starting state so each flag has a non-default "from".
		WPSEO_Meta::set_value( 'meta-robots-noindex', '2', $post_id ); // index
		WPSEO_Meta::set_value( 'meta-robots-nofollow', '0', $post_id ); // follow
		WPSEO_Meta::set_value( 'meta-robots-adv', 'noarchive', $post_id );

		$result = wp_get_ability( self::ABILITY )->execute(
			array(
				'post_id'         => $post_id,
				'robots_noindex'  => 'noindex',
				'robots_nofollow' => true,
				'robots_advanced' => array( 'nosnippet' ),
			)
		);

		$this->assertIsObject( $result );

		$changes = (array) ( (array) $result )['changes'];

		$this->assertSame(
			array(
				'from' => 'index',
				'to'   => 'noindex',
			),
			$changes['robots_noindex'],
			'The noindex change must report readable from/to labels.'
		);
		$this->assertSame(
			array(
				'from' => 'follow',
				'to'   => 'nofollow',
			),
			$changes['robots_nofollow']
		);
		$this->assertSame(
			array(
				'from' => array( 'noarchive' ),
				'to'   => array( 'nosnippet' ),
			),
			$changes['robots_advanced']
		);
	}

	public function test_unchanged_flag_is_absent_from_the_changes_block(): void {
		$post_id = $this->seedPost();

		// Already noindex; re-applying noindex must not record a change for it.
		WPSEO_Meta::set_value( 'meta-robots-noindex', '1', $post_id );

		$result = wp_get_ability( self::ABILITY )->execute(
			array(
				'post_id'         => $post_id,
				'robots_noindex'  => 'noindex',
				'robots_nofollow' => true,
			)
		);

		$this->assertIsObject( $result );

		$changes = (array) ( (array) $result )['changes'];

		$this->assertArrayNotHasKey( 'robots_noindex', $changes, 'An unchanged noindex must not appear in changes.' );
		$this->assertArrayHasKey( 'robots_nofollow', $changes, 'A flag that moved must appear in changes.' );
	}

	public function test_setting_noindex_back_to_default_clears_the_override(): void {
		$post_id = $this->seedPost();

		WPSEO_Meta::set_value( 'meta-robots-noindex', '1', $post_id ); // noindex

		$result = wp_get_ability( self::ABILITY )->execute(
			array(
				'post_id'        => $post_id,
				'robots_noindex' => 'default',
			)
		);

		$this->assertIsObject( $result );

		$row     = (array) $result;
		$changes = (array) $row['changes'];

		$this->assertSame( 'default', $row['robots_noindex'], 'Writing the default resets the field to "default".' );
		$this->assertSame(
			array(
				'from' => 'noindex',
				'to'   => 'default',
			),
			$changes['robots_noindex']
		);
		$this->assertSame( '0', YoastPlugin::getPostMetaValue( 'meta-robots-noindex', $post_id ), 'The override row is reset to the tri-state default "0".' );
	}

	public function test_robots_advanced_rejects_a_value_outside_the_allowed_set(): void {
		$post_id = $this->seedPost();

		$result = wp_get_ability( self::ABILITY )->execute(
			array(
				'post_id'         => $post_id,
				'robots_advanced' => array( 'noimageindex', 'max-snippet' ),
			)
		);

		$this->assertWPError( $result, 'An advanced flag outside {noimageindex,noarchive,nosnippet} must be rejected by validation.' );
		$this->assertSame(
			'ability_invalid_input',
			$result->get_error_code(),
			'An out-of-enum advanced flag must fail input validation, not be written.'
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

		$this->assertTrue( (bool) YoastPlugin::getDisableAdvancedMeta(), 'disableadvanced_meta must default true for this assertion to be load-bearing.' );
		$this->assertFalse( current_user_can( 'wpseo_edit_advanced_metadata' ), 'A default administrator must NOT hold the raw advanced cap.' );
		$this->assertTrue( current_user_can( 'wpseo_manage_options' ), 'A default administrator must hold wpseo_manage_options.' );

		$result = wp_get_ability( self::ABILITY )->execute(
			array(
				'post_id'        => $post_id,
				'robots_noindex' => 'noindex',
			)
		);

		$this->assertIsObject( $result, 'A default admin must be allowed to write advanced robots via the OR-wpseo_manage_options path.' );
		$this->assertSame( 'noindex', ( (array) $result )['robots_noindex'] );
	}

	public function test_under_privileged_user_is_denied(): void {
		$author_id = $this->actingAs( 'administrator' );
		$post_id   = self::factory()->post->create( array( 'post_author' => $author_id ) );

		// A subscriber cannot edit this post.
		$this->actingAs( 'subscriber' );

		$result = wp_get_ability( self::ABILITY )->execute(
			array(
				'post_id'        => $post_id,
				'robots_noindex' => 'noindex',
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
				'post_id'        => 999999,
				'robots_noindex' => 'noindex',
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
}
