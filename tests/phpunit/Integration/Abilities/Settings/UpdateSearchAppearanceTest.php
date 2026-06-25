<?php
/**
 * Integration tests for the og-yoast/update-search-appearance ability.
 *
 * @package AbilitiesCatalogYoast\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogYoast\Tests\Integration\Abilities\Settings;

use GalatanOvidiu\AbilitiesCatalogYoast\Support\YoastPlugin;
use GalatanOvidiu\AbilitiesCatalogYoast\Tests\TestCase;
use WPSEO_Options;

/**
 * Exercises the search-appearance settings write end to end through the Abilities API.
 *
 * Settings live in Yoast's `wpseo_titles` option. Fixtures are seeded with Yoast's
 * own `WPSEO_Options::set` save path, and the write is confirmed by re-reading the
 * group. The whole class self-skips when Yoast SEO is inactive, since the ability
 * does not register then.
 */
final class UpdateSearchAppearanceTest extends TestCase {

	private const ABILITY = 'og-yoast/update-search-appearance';

	/**
	 * Skips the class when Yoast SEO is inactive.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		if ( ! YoastPlugin::isActive() ) {
			$this->markTestSkipped( 'Yoast SEO is not active; og-yoast/update-search-appearance does not register.' );
		}
	}

	public function test_it_registers(): void {
		$this->assertNotNull(
			wp_get_ability( self::ABILITY ),
			'og-yoast/update-search-appearance must resolve after wp_abilities_api_init.'
		);
	}

	public function test_happy_path_writes_and_returns_curated_object(): void {
		$this->actingAs( 'administrator' );

		WPSEO_Options::set( 'separator', 'sc-dash', 'wpseo_titles' );

		$result = wp_get_ability( self::ABILITY )->execute(
			array(
				'separator'        => 'sc-pipe',
				'title-home-wpseo' => 'Home — %%sitename%%',
			)
		);

		$this->assertIsArray( $result, 'A permitted write must return the settings row, not a WP_Error.' );

		// The updated object mirrors get-search-appearance: static keys first in
		// declared order, then the resolved glyph, then the per-type map.
		$this->assertSame(
			array(
				'separator',
				'forcerewritetitle',
				'title-home-wpseo',
				'title-author-wpseo',
				'title-archive-wpseo',
				'title-search-wpseo',
				'title-404-wpseo',
				'metadesc-home-wpseo',
				'metadesc-author-wpseo',
				'metadesc-archive-wpseo',
				'rssbefore',
				'rssafter',
				'separator_glyph',
				'per_type_templates',
			),
			array_keys( $result ),
			'The updated row must carry the documented keys in order, matching get-search-appearance.'
		);

		$this->assertSame( 'sc-pipe', $result['separator'], 'The written separator slug must surface in the result.' );
		$this->assertSame( '|', $result['separator_glyph'], 'The resolved glyph must track the written slug.' );
		$this->assertSame( 'Home — %%sitename%%', $result['title-home-wpseo'] );
	}

	public function test_write_confirms_via_re_read(): void {
		$this->actingAs( 'administrator' );

		wp_get_ability( self::ABILITY )->execute(
			array( 'title-search-wpseo' => 'Search: %%searchphrase%%' ),
		);

		// Re-read the live option independently to prove the value actually stuck.
		$option = WPSEO_Options::get_option( 'wpseo_titles' );
		$this->assertSame(
			'Search: %%searchphrase%%',
			$option['title-search-wpseo'] ?? null,
			'The written template must round-trip to Yoast\'s stored option.'
		);
	}

	public function test_boolean_key_writes(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( self::ABILITY )->execute(
			array( 'forcerewritetitle' => true ),
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['forcerewritetitle'], 'The boolean flag must surface as the written value.' );
		$this->assertIsBool( $result['forcerewritetitle'] );
	}

	public function test_dynamic_per_type_key_is_accepted_and_written(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( self::ABILITY )->execute(
			array(
				'per_type_templates' => array(
					'title-post' => 'Post: %%title%%',
				),
			),
		);

		$this->assertIsArray( $result, 'A registered public post type key must be accepted, not rejected.' );

		$map = (array) $result['per_type_templates'];
		$this->assertArrayHasKey( 'title-post', $map, 'A written per-type key must surface in the result map.' );
		$this->assertSame( 'Post: %%title%%', $map['title-post'] );

		$option = WPSEO_Options::get_option( 'wpseo_titles' );
		$this->assertSame( 'Post: %%title%%', $option['title-post'] ?? null, 'The per-type template must round-trip.' );
	}

	public function test_out_of_list_dynamic_key_is_rejected_not_written(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( self::ABILITY )->execute(
			array(
				'per_type_templates' => array(
					// A type that is not a registered public post type.
					'title-not-a-real-type' => 'should not be written',
				),
			),
		);

		$this->assertWPError( $result );
		$this->assertSame(
			'og_yoast_unknown_setting_key',
			$result->get_error_code(),
			'An out-of-list key must surface the typed unknown-key error, not a permission error.'
		);
		$this->assertSame( 400, $result->get_error_data()['status'] ?? null );

		$option = WPSEO_Options::get_option( 'wpseo_titles' );
		$this->assertArrayNotHasKey(
			'title-not-a-real-type',
			$option,
			'A rejected key must not be written to the option.'
		);
	}

	public function test_under_privileged_user_is_denied(): void {
		// A subscriber lacks wpseo_manage_options (Yoast grants it to administrators).
		$this->actingAs( 'subscriber' );

		$result = wp_get_ability( self::ABILITY )->execute(
			array( 'separator' => 'sc-pipe' ),
		);

		$this->assertWPError( $result );
		$this->assertSame(
			'ability_invalid_permissions',
			$result->get_error_code(),
			'A caller without wpseo_manage_options must be denied by the Abilities API.'
		);
	}
}
