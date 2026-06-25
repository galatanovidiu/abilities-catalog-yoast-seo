<?php
/**
 * Integration tests for the og-yoast/get-breadcrumbs ability.
 *
 * @package AbilitiesCatalogYoast\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogYoast\Tests\Integration\Abilities\Settings;

use GalatanOvidiu\AbilitiesCatalogYoast\Support\YoastPlugin;
use GalatanOvidiu\AbilitiesCatalogYoast\Tests\TestCase;
use WPSEO_Options;

/**
 * Exercises the breadcrumbs settings read end to end through the Abilities API.
 *
 * Settings are seeded through Yoast's own `WPSEO_Options::set` save path (group
 * `wpseo_titles`). The whole class self-skips when Yoast SEO is inactive, since
 * the ability does not register then.
 */
final class GetBreadcrumbsTest extends TestCase {

	private const ABILITY = 'og-yoast/get-breadcrumbs';

	/**
	 * Skips the class when Yoast SEO is inactive.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		if ( ! YoastPlugin::isActive() ) {
			$this->markTestSkipped( 'Yoast SEO is not active; og-yoast/get-breadcrumbs does not register.' );
		}
	}

	public function test_it_registers(): void {
		$this->assertNotNull(
			wp_get_ability( self::ABILITY ),
			'og-yoast/get-breadcrumbs must resolve after wp_abilities_api_init.'
		);
	}

	public function test_happy_path_returns_flat_seeded_values_in_key_order(): void {
		$this->actingAs( 'administrator' );

		WPSEO_Options::set( 'breadcrumbs-enable', true, 'wpseo_titles' );
		WPSEO_Options::set( 'breadcrumbs-home', 'Start', 'wpseo_titles' );
		WPSEO_Options::set( 'breadcrumbs-sep', '»', 'wpseo_titles' );

		$result = wp_get_ability( self::ABILITY )->execute( array() );

		$this->assertIsArray( $result, 'A permitted read must return the breadcrumbs row, not a WP_Error.' );

		$this->assertSame(
			array(
				'breadcrumbs-enable',
				'breadcrumbs-boldlast',
				'breadcrumbs-display-blog-page',
				'breadcrumbs-404crumb',
				'breadcrumbs-home',
				'breadcrumbs-prefix',
				'breadcrumbs-searchprefix',
				'breadcrumbs-archiveprefix',
				'breadcrumbs-sep',
			),
			array_keys( $result ),
			'The flat row must carry the breadcrumbs allow-list keys in order.'
		);

		$this->assertTrue( $result['breadcrumbs-enable'], 'Seeded breadcrumbs-enable must surface as boolean true.' );
		$this->assertSame( 'Start', $result['breadcrumbs-home'], 'Seeded breadcrumbs-home must surface verbatim.' );
		$this->assertSame( '»', $result['breadcrumbs-sep'], 'Seeded breadcrumbs-sep must surface verbatim.' );
	}

	public function test_boolean_keys_are_typed_booleans(): void {
		$this->actingAs( 'administrator' );

		WPSEO_Options::set( 'breadcrumbs-boldlast', true, 'wpseo_titles' );

		$result = wp_get_ability( self::ABILITY )->execute( array() );

		$this->assertIsArray( $result );
		$this->assertIsBool( $result['breadcrumbs-enable'], 'breadcrumbs-enable must be a boolean.' );
		$this->assertIsBool( $result['breadcrumbs-boldlast'], 'breadcrumbs-boldlast must be a boolean.' );
		$this->assertTrue( $result['breadcrumbs-boldlast'], 'Seeded breadcrumbs-boldlast must surface as true.' );
		$this->assertIsString( $result['breadcrumbs-home'], 'breadcrumbs-home must be a string.' );
	}

	public function test_read_triggers_no_write(): void {
		$this->actingAs( 'administrator' );

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
