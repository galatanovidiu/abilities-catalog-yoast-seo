<?php
/**
 * Integration tests for the og-yoast/update-breadcrumbs ability.
 *
 * @package AbilitiesCatalogYoast\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogYoast\Tests\Integration\Abilities\Settings;

use GalatanOvidiu\AbilitiesCatalogYoast\Abilities\Yoast\Settings\UpdateBreadcrumbs;
use GalatanOvidiu\AbilitiesCatalogYoast\Support\YoastPlugin;
use GalatanOvidiu\AbilitiesCatalogYoast\Tests\TestCase;
use WPSEO_Options;

/**
 * Exercises the breadcrumbs settings write end to end through the Abilities API.
 *
 * The breadcrumb keys live in the `wpseo_titles` option group, seeded and confirmed here
 * through Yoast's own `WPSEO_Options::set` / `get_option`. The whole class self-skips when
 * Yoast SEO is inactive, since the ability does not register then.
 */
final class UpdateBreadcrumbsTest extends TestCase {

	private const ABILITY = 'og-yoast/update-breadcrumbs';

	/**
	 * The full output key order the updated row must carry.
	 *
	 * @var list<string>
	 */
	private const EXPECTED_KEYS = array(
		'breadcrumbs-enable',
		'breadcrumbs-boldlast',
		'breadcrumbs-display-blog-page',
		'breadcrumbs-404crumb',
		'breadcrumbs-home',
		'breadcrumbs-prefix',
		'breadcrumbs-searchprefix',
		'breadcrumbs-archiveprefix',
		'breadcrumbs-sep',
	);

	/**
	 * Skips the class when Yoast SEO is inactive.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		if ( ! YoastPlugin::isActive() ) {
			$this->markTestSkipped( 'Yoast SEO is not active; og-yoast/update-breadcrumbs does not register.' );
		}
	}

	public function test_it_registers(): void {
		$this->assertNotNull(
			wp_get_ability( self::ABILITY ),
			'og-yoast/update-breadcrumbs must resolve after wp_abilities_api_init.'
		);
	}

	public function test_happy_path_writes_and_returns_row_in_key_order(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( self::ABILITY )->execute(
			array(
				'breadcrumbs-enable' => true,
				'breadcrumbs-home'   => 'Start',
				'breadcrumbs-sep'    => '/',
			)
		);

		$this->assertIsArray( $result, 'A permitted write must return the breadcrumbs row, not a WP_Error.' );

		$this->assertSame(
			self::EXPECTED_KEYS,
			array_keys( $result ),
			'The updated row must carry the documented allow-list keys in order.'
		);

		$this->assertTrue( $result['breadcrumbs-enable'] );
		$this->assertSame( 'Start', $result['breadcrumbs-home'] );
		$this->assertSame( '/', $result['breadcrumbs-sep'] );
	}

	public function test_write_round_trips_to_yoast_store(): void {
		$this->actingAs( 'administrator' );

		wp_get_ability( self::ABILITY )->execute(
			array(
				'breadcrumbs-home' => 'Confirmed Home',
			)
		);

		// Re-read through Yoast's own store to prove the write stuck, not just the return.
		$stored = WPSEO_Options::get_option( 'wpseo_titles' );
		$this->assertSame(
			'Confirmed Home',
			$stored['breadcrumbs-home'],
			'The written value must round-trip into the wpseo_titles option.'
		);
	}

	public function test_boolean_key_is_returned_as_boolean(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( self::ABILITY )->execute(
			array(
				'breadcrumbs-boldlast' => true,
			)
		);

		$this->assertIsArray( $result );
		$this->assertIsBool( $result['breadcrumbs-boldlast'], 'A toggle key must be returned as a boolean.' );
		$this->assertTrue( $result['breadcrumbs-boldlast'] );
	}

	public function test_unset_keys_read_as_typed_defaults(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( self::ABILITY )->execute(
			array(
				'breadcrumbs-home' => 'Example Home',
			)
		);

		$this->assertIsArray( $result );
		$this->assertIsBool( $result['breadcrumbs-enable'], 'An unset toggle key must read as a boolean.' );
		$this->assertIsString( $result['breadcrumbs-prefix'], 'An unset label key must read as a string.' );
	}

	public function test_unknown_key_is_rejected_not_written(): void {
		$this->actingAs( 'administrator' );

		$before = WPSEO_Options::get_option( 'wpseo_titles' );

		// The closed schema (additionalProperties:false) rejects an out-of-list key at the
		// Abilities API boundary, so to exercise the ability's own runtime allow-list — the
		// second, load-bearing deny-by-default guard — call execute() directly.
		$result = ( new UpdateBreadcrumbs() )->execute(
			array(
				'noindex-author-wpseo' => true,
			)
		);

		$this->assertWPError( $result, 'A key outside the breadcrumbs allow-list must be rejected.' );
		$this->assertSame(
			'og_yoast_unknown_setting_key',
			$result->get_error_code(),
			'An out-of-list key must surface the typed unknown-key error, not a permission error.'
		);
		$this->assertSame( 400, $result->get_error_data()['status'] ?? null );

		$after = WPSEO_Options::get_option( 'wpseo_titles' );
		$this->assertSame(
			$before['noindex-author-wpseo'] ?? null,
			$after['noindex-author-wpseo'] ?? null,
			'An out-of-list key must not be written.'
		);
	}

	public function test_under_privileged_user_is_denied(): void {
		// A subscriber lacks wpseo_manage_options.
		$this->actingAs( 'subscriber' );

		$result = wp_get_ability( self::ABILITY )->execute(
			array(
				'breadcrumbs-home' => 'Should Not Save',
			)
		);

		$this->assertWPError( $result );
		$this->assertSame(
			'ability_invalid_permissions',
			$result->get_error_code(),
			'A caller without wpseo_manage_options must be denied by the Abilities API.'
		);
	}
}
