<?php
/**
 * Integration tests for the og-yoast/get-author-seo ability.
 *
 * @package AbilitiesCatalogYoast\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogYoast\Tests\Integration\Abilities\Author;

use GalatanOvidiu\AbilitiesCatalogYoast\Support\YoastPlugin;
use GalatanOvidiu\AbilitiesCatalogYoast\Tests\TestCase;
use WPSEO_Options;

/**
 * Exercises the per-author Yoast SEO read end to end through the Abilities API.
 *
 * The three SEO values are plain core user-meta, seeded with `update_user_meta`
 * under Yoast's own keys (`wpseo_title`, `wpseo_metadesc`, `wpseo_noindex_author`).
 * `author_archives_enabled` is driven by the `disable-author` option through
 * `WPSEO_Options::set`. The whole class self-skips when Yoast SEO is inactive,
 * since the ability does not register then.
 */
final class GetAuthorSeoTest extends TestCase {

	private const ABILITY = 'og-yoast/get-author-seo';

	/**
	 * Skips the class when Yoast SEO is inactive.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		if ( ! YoastPlugin::isActive() ) {
			$this->markTestSkipped( 'Yoast SEO is not active; og-yoast/get-author-seo does not register.' );
		}

		// Author archives are enabled by default; make that explicit so a stray
		// disable-author from another test never leaks into the baseline cases.
		WPSEO_Options::set( 'disable-author', false );
	}

	/**
	 * Creates an author and makes an administrator the current user.
	 *
	 * @return int The created author (user) ID.
	 */
	private function seedAuthor(): int {
		$author_id = self::factory()->user->create( array( 'role' => 'author' ) );
		$this->actingAs( 'administrator' );

		return $author_id;
	}

	public function test_it_registers(): void {
		$this->assertNotNull(
			wp_get_ability( self::ABILITY ),
			'og-yoast/get-author-seo must resolve after wp_abilities_api_init.'
		);
	}

	public function test_happy_path_returns_flat_seeded_values_in_key_order(): void {
		$author_id = $this->seedAuthor();

		update_user_meta( $author_id, 'wpseo_title', 'Jane Doe — Senior Editor' );
		update_user_meta( $author_id, 'wpseo_metadesc', 'Articles by Jane Doe.' );

		$result = wp_get_ability( self::ABILITY )->execute( array( 'author_id' => $author_id ) );

		$this->assertIsArray( $result, 'A permitted read must return the author SEO row, not a WP_Error.' );

		$this->assertSame(
			array(
				'author_id',
				'seo_title',
				'meta_description',
				'noindex',
				'author_archives_enabled',
			),
			array_keys( $result ),
			'The flat row must carry the documented keys in order.'
		);

		$this->assertSame( $author_id, $result['author_id'] );
		$this->assertSame( 'Jane Doe — Senior Editor', $result['seo_title'] );
		$this->assertSame( 'Articles by Jane Doe.', $result['meta_description'] );
		$this->assertFalse( $result['noindex'], 'Unseeded noindex meta must read as false.' );
		$this->assertTrue( $result['author_archives_enabled'] );
	}

	public function test_unset_meta_reads_as_empty_strings(): void {
		$author_id = $this->seedAuthor();

		$result = wp_get_ability( self::ABILITY )->execute( array( 'author_id' => $author_id ) );

		$this->assertIsArray( $result );
		$this->assertSame( '', $result['seo_title'], 'Unset wpseo_title must read as an empty string.' );
		$this->assertSame( '', $result['meta_description'], 'Unset wpseo_metadesc must read as an empty string.' );
	}

	public function test_noindex_is_true_when_meta_is_on(): void {
		$author_id = $this->seedAuthor();

		update_user_meta( $author_id, 'wpseo_noindex_author', 'on' );

		$result = wp_get_ability( self::ABILITY )->execute( array( 'author_id' => $author_id ) );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['noindex'], 'wpseo_noindex_author === "on" must surface as noindex true.' );
	}

	public function test_noindex_is_false_when_meta_is_not_on(): void {
		$author_id = $this->seedAuthor();

		// Yoast stores only the literal 'on'; any other stored value reads as not noindex.
		update_user_meta( $author_id, 'wpseo_noindex_author', '' );

		$result = wp_get_ability( self::ABILITY )->execute( array( 'author_id' => $author_id ) );

		$this->assertIsArray( $result );
		$this->assertFalse( $result['noindex'], 'A non-"on" wpseo_noindex_author must surface as noindex false.' );
	}

	public function test_author_archives_enabled_reflects_disable_author_option(): void {
		$author_id = $this->seedAuthor();

		WPSEO_Options::set( 'disable-author', true );

		$result = wp_get_ability( self::ABILITY )->execute( array( 'author_id' => $author_id ) );

		$this->assertIsArray( $result );
		$this->assertFalse(
			$result['author_archives_enabled'],
			'disable-author = true must surface author_archives_enabled false.'
		);
	}

	public function test_missing_author_returns_typed_not_found_error(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( self::ABILITY )->execute( array( 'author_id' => 999999 ) );

		$this->assertWPError( $result, 'A missing author must surface a typed error, not a row.' );
		$this->assertSame(
			'author_not_found',
			$result->get_error_code(),
			'The typed not-found code must surface, not be collapsed into a permission error.'
		);
		$this->assertSame( 404, $result->get_error_data()['status'] ?? null );
	}

	public function test_under_privileged_user_is_denied(): void {
		$author_id = self::factory()->user->create( array( 'role' => 'author' ) );

		// A subscriber cannot edit another user.
		$this->actingAs( 'subscriber' );

		$result = wp_get_ability( self::ABILITY )->execute( array( 'author_id' => $author_id ) );

		$this->assertWPError( $result );
		$this->assertSame(
			'ability_invalid_permissions',
			$result->get_error_code(),
			'A caller without edit_user on the target must be denied by the Abilities API.'
		);
	}
}
