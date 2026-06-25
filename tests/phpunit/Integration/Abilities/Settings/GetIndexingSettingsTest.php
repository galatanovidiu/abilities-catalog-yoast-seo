<?php
/**
 * Integration tests for the og-yoast/get-indexing-settings ability.
 *
 * @package AbilitiesCatalogYoast\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogYoast\Tests\Integration\Abilities\Settings;

use GalatanOvidiu\AbilitiesCatalogYoast\Support\YoastPlugin;
use GalatanOvidiu\AbilitiesCatalogYoast\Tests\TestCase;
use WPSEO_Options;

/**
 * Exercises the site-indexing settings read end to end through the Abilities API.
 *
 * Settings are seeded through Yoast's own `WPSEO_Options::set` save path on the
 * `wpseo_titles` group, so the read reflects values Yoast actually stored. The
 * whole class self-skips when Yoast SEO is inactive, since the ability does not
 * register then.
 */
final class GetIndexingSettingsTest extends TestCase {

	private const ABILITY = 'og-yoast/get-indexing-settings';

	/**
	 * Skips the class when Yoast SEO is inactive.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		if ( ! YoastPlugin::isActive() ) {
			$this->markTestSkipped( 'Yoast SEO is not active; og-yoast/get-indexing-settings does not register.' );
		}
	}

	public function test_it_registers(): void {
		$this->assertNotNull(
			wp_get_ability( self::ABILITY ),
			'og-yoast/get-indexing-settings must resolve after wp_abilities_api_init.'
		);
	}

	public function test_happy_path_returns_flat_row_with_seeded_values(): void {
		$this->actingAs( 'administrator' );

		WPSEO_Options::set( 'noindex-author-wpseo', true );
		WPSEO_Options::set( 'noindex-post', true );

		$result = wp_get_ability( self::ABILITY )->execute( array() );

		$this->assertIsArray( $result, 'A permitted read must return the indexing-settings row, not a WP_Error.' );

		$this->assertSame(
			array(
				'noindex-author-wpseo',
				'noindex-author-noposts-wpseo',
				'noindex-archive-wpseo',
				'disable-author',
				'disable-date',
				'disable-post_format',
				'disable-attachment',
				'per_type_noindex',
			),
			array_keys( $result ),
			'The flat row must carry the static toggle keys then the per_type_noindex map, in order.'
		);

		$this->assertTrue( $result['noindex-author-wpseo'], 'Seeded noindex-author-wpseo must surface as true.' );
		$this->assertIsBool( $result['disable-author'], 'Static toggles must be typed booleans.' );
	}

	public function test_per_type_noindex_surfaces_seeded_post_type_key_as_bool(): void {
		$this->actingAs( 'administrator' );

		WPSEO_Options::set( 'noindex-post', true );

		$result = wp_get_ability( self::ABILITY )->execute( array() );

		$this->assertIsArray( $result );

		$map = (array) $result['per_type_noindex'];
		$this->assertArrayHasKey(
			'noindex-post',
			$map,
			'A stored noindex-<type> key must appear in the per_type_noindex map keyed by its full Yoast key.'
		);
		$this->assertTrue( $map['noindex-post'], 'Seeded noindex-post must surface as true.' );
	}

	public function test_read_triggers_no_write(): void {
		$this->actingAs( 'administrator' );

		WPSEO_Options::set( 'noindex-author-wpseo', true );

		$before = WPSEO_Options::get_option( 'wpseo_titles' );

		wp_get_ability( self::ABILITY )->execute( array() );

		$after = WPSEO_Options::get_option( 'wpseo_titles' );

		$this->assertSame(
			$before,
			$after,
			'A read must not mutate the wpseo_titles option group.'
		);
	}

	public function test_under_privileged_user_is_denied(): void {
		// A subscriber lacks wpseo_manage_options.
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
