<?php
/**
 * Integration tests for the og-yoast/get-search-appearance ability.
 *
 * @package AbilitiesCatalogYoast\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogYoast\Tests\Integration\Abilities\Settings;

use GalatanOvidiu\AbilitiesCatalogYoast\Support\YoastPlugin;
use GalatanOvidiu\AbilitiesCatalogYoast\Tests\TestCase;
use WPSEO_Options;

/**
 * Exercises the search-appearance settings read end to end through the Abilities API.
 *
 * Settings live in Yoast's `wpseo_titles` option, seeded with Yoast's own
 * `WPSEO_Options::set` save path so the read sees real stored values. The whole
 * class self-skips when Yoast SEO is inactive, since the ability does not register
 * then.
 */
final class GetSearchAppearanceTest extends TestCase {

	private const ABILITY = 'og-yoast/get-search-appearance';

	/**
	 * Skips the class when Yoast SEO is inactive.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		if ( ! YoastPlugin::isActive() ) {
			$this->markTestSkipped( 'Yoast SEO is not active; og-yoast/get-search-appearance does not register.' );
		}
	}

	public function test_it_registers(): void {
		$this->assertNotNull(
			wp_get_ability( self::ABILITY ),
			'og-yoast/get-search-appearance must resolve after wp_abilities_api_init.'
		);
	}

	public function test_happy_path_returns_seeded_static_keys_and_per_type_map(): void {
		$this->actingAs( 'administrator' );

		WPSEO_Options::set( 'separator', 'sc-dash', 'wpseo_titles' );
		WPSEO_Options::set( 'title-home-wpseo', 'Home — %%sitename%%', 'wpseo_titles' );
		WPSEO_Options::set( 'title-post', 'Post: %%title%%', 'wpseo_titles' );

		$result = wp_get_ability( self::ABILITY )->execute( array() );

		$this->assertIsArray( $result, 'A permitted read must return the settings row, not a WP_Error.' );

		// The static keys come first, in declared order, then the per-type map.
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
			'The flat row must carry the documented keys in order.'
		);

		$this->assertSame( 'sc-dash', $result['separator'], 'The raw separator slug must surface verbatim.' );
		$this->assertSame( 'Home — %%sitename%%', $result['title-home-wpseo'] );
		$this->assertIsBool( $result['forcerewritetitle'], 'forcerewritetitle must surface as a boolean.' );
	}

	public function test_separator_glyph_resolves_known_slug(): void {
		$this->actingAs( 'administrator' );

		WPSEO_Options::set( 'separator', 'sc-pipe', 'wpseo_titles' );

		$result = wp_get_ability( self::ABILITY )->execute( array() );

		$this->assertIsArray( $result );
		$this->assertSame( 'sc-pipe', $result['separator'], 'The raw slug stays as Yoast stored it.' );
		$this->assertSame( '|', $result['separator_glyph'], 'A known separator slug must resolve to its glyph.' );
	}

	public function test_per_type_templates_carries_a_seeded_type_key(): void {
		$this->actingAs( 'administrator' );

		WPSEO_Options::set( 'title-post', 'Post: %%title%%', 'wpseo_titles' );

		$result = wp_get_ability( self::ABILITY )->execute( array() );

		$this->assertIsArray( $result );
		$this->assertIsObject( $result['per_type_templates'], 'The per-type map must serialize as an object.' );

		$map = (array) $result['per_type_templates'];
		$this->assertArrayHasKey( 'title-post', $map, 'A stored per-type key must surface under its full Yoast key.' );
		$this->assertSame( 'Post: %%title%%', $map['title-post'] );
	}

	public function test_read_triggers_no_write(): void {
		$this->actingAs( 'administrator' );

		WPSEO_Options::set( 'separator', 'sc-dash', 'wpseo_titles' );

		$writes = 0;
		$spy    = static function ( $value ) use ( &$writes ) {
			++$writes;

			return $value;
		};
		add_filter( 'pre_update_option_wpseo_titles', $spy );

		wp_get_ability( self::ABILITY )->execute( array() );

		remove_filter( 'pre_update_option_wpseo_titles', $spy );

		$this->assertSame( 0, $writes, 'A read must not write the wpseo_titles option.' );
	}

	public function test_under_privileged_user_is_denied(): void {
		// A subscriber lacks wpseo_manage_options (Yoast grants it to administrators).
		$this->actingAs( 'subscriber' );

		$result = wp_get_ability( self::ABILITY )->execute( array() );

		$this->assertWPError( $result );
		$this->assertSame(
			'ability_invalid_permissions',
			$result->get_error_code(),
			'A caller without wpseo_manage_options must be denied by the Abilities API.'
		);
	}
}
