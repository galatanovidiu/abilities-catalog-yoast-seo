<?php
/**
 * Integration tests for the og-yoast/update-author-noindex ability.
 *
 * @package AbilitiesCatalogYoast\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogYoast\Tests\Integration\Abilities\Author;

use GalatanOvidiu\AbilitiesCatalogYoast\Support\YoastPlugin;
use GalatanOvidiu\AbilitiesCatalogYoast\Tests\TestCase;
use WPSEO_Options;

/**
 * Exercises the per-author noindex write end to end through the Abilities API.
 *
 * The flag is plain `wpseo_noindex_author` user-meta, so fixtures are seeded with
 * core `update_user_meta` (Yoast's own stored key) and assertions read it back the
 * same way. The whole class self-skips when Yoast SEO is inactive, since the
 * ability does not register then.
 */
final class UpdateAuthorNoindexTest extends TestCase {

	private const ABILITY = 'og-yoast/update-author-noindex';

	private const META_KEY = 'wpseo_noindex_author';

	/**
	 * Skips the class when Yoast SEO is inactive.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		if ( ! YoastPlugin::isActive() ) {
			$this->markTestSkipped( 'Yoast SEO is not active; og-yoast/update-author-noindex does not register.' );
		}

		// Author archives default enabled; pin it so author_archives_enabled assertions are load-bearing.
		WPSEO_Options::set( 'disable-author', false );
	}

	/**
	 * Creates an author user while acting as a fresh administrator.
	 *
	 * @return int The created author (user) ID.
	 */
	private function seedAuthor(): int {
		$this->actingAs( 'administrator' );

		return (int) self::factory()->user->create( array( 'role' => 'author' ) );
	}

	public function test_it_registers(): void {
		$this->assertNotNull(
			wp_get_ability( self::ABILITY ),
			'og-yoast/update-author-noindex must resolve after wp_abilities_api_init.'
		);
	}

	public function test_setting_noindex_true_stores_on_and_returns_old_to_new_block(): void {
		$author_id = $this->seedAuthor();

		$result = wp_get_ability( self::ABILITY )->execute(
			array(
				'author_id' => $author_id,
				'noindex'   => true,
			)
		);

		$this->assertIsObject( $result, 'A permitted write must return the result object, not a WP_Error.' );

		$row = (array) $result;

		$this->assertSame(
			array( 'author_id', 'noindex', 'author_archives_enabled' ),
			array_keys( $row ),
			'The result must carry author_id, the noindex old->new block, then author_archives_enabled, in that order.'
		);

		$this->assertSame( $author_id, $row['author_id'] );
		$this->assertSame(
			array(
				'from' => false,
				'to'   => true,
			),
			(array) $row['noindex'],
			'Setting noindex on a previously-indexed author must report from=false, to=true.'
		);
		$this->assertTrue( $row['author_archives_enabled'], 'author_archives_enabled must reflect the enabled option.' );

		// The string 'on' was stored through core user-meta under Yoast's own key.
		$this->assertSame(
			'on',
			get_user_meta( $author_id, self::META_KEY, true ),
			'Setting noindex true must store the literal string "on".'
		);
	}

	public function test_clearing_noindex_false_deletes_the_meta_and_returns_old_to_new_block(): void {
		$author_id = $this->seedAuthor();

		// Seed a previously-on state through Yoast's own stored key.
		update_user_meta( $author_id, self::META_KEY, 'on' );

		$result = wp_get_ability( self::ABILITY )->execute(
			array(
				'author_id' => $author_id,
				'noindex'   => false,
			)
		);

		$this->assertIsObject( $result );

		$row = (array) $result;

		$this->assertSame(
			array(
				'from' => true,
				'to'   => false,
			),
			(array) $row['noindex'],
			'Clearing noindex on a previously-noindexed author must report from=true, to=false.'
		);

		// Clearing is a delete, not an empty write: the row must be absent.
		$this->assertSame(
			'',
			get_user_meta( $author_id, self::META_KEY, true ),
			'Clearing noindex must delete the meta row, leaving the read default empty.'
		);
		$this->assertEmpty(
			get_user_meta( $author_id, self::META_KEY ),
			'No wpseo_noindex_author meta row may remain after a clear.'
		);
	}

	public function test_author_archives_disabled_is_surfaced_and_write_is_not_silent(): void {
		$author_id = $this->seedAuthor();

		// Disable author archives: the write is moot, but must surface that, not be silent.
		WPSEO_Options::set( 'disable-author', true );

		$result = wp_get_ability( self::ABILITY )->execute(
			array(
				'author_id' => $author_id,
				'noindex'   => true,
			)
		);

		$this->assertIsObject( $result );
		$this->assertFalse(
			( (array) $result )['author_archives_enabled'],
			'When author archives are disabled the result must surface author_archives_enabled=false.'
		);
	}

	public function test_missing_author_returns_typed_not_found_error(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( self::ABILITY )->execute(
			array(
				'author_id' => 999999,
				'noindex'   => true,
			)
		);

		$this->assertWPError( $result, 'A missing author must surface a typed error, not a row.' );
		$this->assertSame(
			'author_not_found',
			$result->get_error_code(),
			'The typed not-found code must surface, not be collapsed into a permission error.'
		);
		$this->assertSame( 404, $result->get_error_data()['status'] ?? null );
	}

	public function test_under_privileged_user_is_denied(): void {
		$author_id = $this->seedAuthor();

		// A subscriber cannot edit another user.
		$this->actingAs( 'subscriber' );

		$result = wp_get_ability( self::ABILITY )->execute(
			array(
				'author_id' => $author_id,
				'noindex'   => true,
			)
		);

		$this->assertWPError( $result );
		$this->assertSame(
			'ability_invalid_permissions',
			$result->get_error_code(),
			'A caller without edit_user on the target must be denied by the Abilities API.'
		);
	}
}
