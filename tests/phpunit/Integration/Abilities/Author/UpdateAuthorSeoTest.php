<?php
/**
 * Integration tests for the og-yoast/update-author-seo ability.
 *
 * @package AbilitiesCatalogYoast\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogYoast\Tests\Integration\Abilities\Author;

use GalatanOvidiu\AbilitiesCatalogYoast\Support\YoastPlugin;
use GalatanOvidiu\AbilitiesCatalogYoast\Tests\TestCase;

/**
 * Exercises the per-author Yoast SEO write end to end through the Abilities API.
 *
 * The title and meta description are core user-meta, so fixtures are seeded and
 * asserted through `update_user_meta` / `get_user_meta` (the stored keys
 * `wpseo_title` / `wpseo_metadesc`, not the form-field ids). The whole class
 * self-skips when Yoast SEO is inactive, since the ability does not register then.
 */
final class UpdateAuthorSeoTest extends TestCase {

	private const ABILITY = 'og-yoast/update-author-seo';

	/**
	 * Skips the class when Yoast SEO is inactive.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		if ( ! YoastPlugin::isActive() ) {
			$this->markTestSkipped( 'Yoast SEO is not active; og-yoast/update-author-seo does not register.' );
		}
	}

	/**
	 * Creates an author and makes an administrator the current user.
	 *
	 * @return int The created author (user) ID.
	 */
	private function seedAuthor(): int {
		$this->actingAs( 'administrator' );

		return self::factory()->user->create( array( 'role' => 'author' ) );
	}

	public function test_it_registers(): void {
		$this->assertNotNull(
			wp_get_ability( self::ABILITY ),
			'og-yoast/update-author-seo must resolve after wp_abilities_api_init.'
		);
	}

	public function test_happy_path_writes_both_fields_under_stored_keys_and_returns_row_in_order(): void {
		$author_id = $this->seedAuthor();

		$result = wp_get_ability( self::ABILITY )->execute(
			array(
				'author_id'        => $author_id,
				'seo_title'        => 'Jane Doe, Widget Expert',
				'meta_description' => 'Articles about widgets by Jane Doe.',
			)
		);

		$this->assertIsArray( $result, 'A permitted write must return the SEO row, not a WP_Error.' );

		$this->assertSame(
			array(
				'author_id',
				'seo_title',
				'meta_description',
				'noindex',
				'author_archives_enabled',
			),
			array_keys( $result ),
			'The result must be the curated author SEO row in the documented key order.'
		);

		$this->assertSame( $author_id, $result['author_id'] );
		$this->assertSame( 'Jane Doe, Widget Expert', $result['seo_title'] );
		$this->assertSame( 'Articles about widgets by Jane Doe.', $result['meta_description'] );

		// The values must be stored under the stored keys, not the form-field ids.
		$this->assertSame( 'Jane Doe, Widget Expert', get_user_meta( $author_id, 'wpseo_title', true ) );
		$this->assertSame( 'Articles about widgets by Jane Doe.', get_user_meta( $author_id, 'wpseo_metadesc', true ) );
		$this->assertSame( '', get_user_meta( $author_id, 'wpseo_author_title', true ), 'Must not store under the form-field id.' );
		$this->assertSame( '', get_user_meta( $author_id, 'wpseo_author_metadesc', true ), 'Must not store under the form-field id.' );
	}

	public function test_empty_seo_title_clears_the_field(): void {
		$author_id = $this->seedAuthor();

		update_user_meta( $author_id, 'wpseo_title', 'Seeded Title' );

		$result = wp_get_ability( self::ABILITY )->execute(
			array(
				'author_id' => $author_id,
				'seo_title' => '',
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( '', $result['seo_title'], 'An explicit empty string clears the title, it does not throw.' );
		$this->assertSame( '', get_user_meta( $author_id, 'wpseo_title', true ), 'The stored title must be cleared.' );
	}

	public function test_omitting_a_field_leaves_it_unchanged(): void {
		$author_id = $this->seedAuthor();

		update_user_meta( $author_id, 'wpseo_title', 'Seeded Title' );
		update_user_meta( $author_id, 'wpseo_metadesc', 'Seeded description.' );

		// Send only the title; the seeded meta description must survive.
		$result = wp_get_ability( self::ABILITY )->execute(
			array(
				'author_id' => $author_id,
				'seo_title' => 'New Title',
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'New Title', $result['seo_title'] );
		$this->assertSame( 'Seeded description.', $result['meta_description'], 'An omitted field is left unchanged.' );
		$this->assertSame( 'Seeded description.', get_user_meta( $author_id, 'wpseo_metadesc', true ) );
	}

	public function test_missing_author_returns_typed_not_found_error(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( self::ABILITY )->execute(
			array(
				'author_id' => 999999,
				'seo_title' => 'irrelevant',
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
				'seo_title' => 'denied',
			)
		);

		$this->assertWPError( $result );
		$this->assertSame(
			'ability_invalid_permissions',
			$result->get_error_code(),
			'A caller without edit_user on the author must be denied by the Abilities API.'
		);
	}
}
