<?php
/**
 * Integration tests for the og-yoast/get-general-settings ability.
 *
 * @package AbilitiesCatalogYoast\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogYoast\Tests\Integration\Abilities\Settings;

use GalatanOvidiu\AbilitiesCatalogYoast\Support\YoastPlugin;
use GalatanOvidiu\AbilitiesCatalogYoast\Tests\TestCase;
use WPSEO_Options;

/**
 * Exercises the general-features settings read end to end through the Abilities API.
 *
 * Settings are seeded through Yoast's own option store (`WPSEO_Options::set`) on the
 * `wpseo` group. The load-bearing case is the secret-exclusion test: a verification
 * code and the API tokens stored alongside it must never surface — only a masked
 * `<key>_has_value` boolean for the five `*verify` codes. The whole class self-skips
 * when Yoast SEO is inactive, since the ability does not register then.
 */
final class GetGeneralSettingsTest extends TestCase {

	private const ABILITY = 'og-yoast/get-general-settings';

	/**
	 * Skips the class when Yoast SEO is inactive.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		if ( ! YoastPlugin::isActive() ) {
			$this->markTestSkipped( 'Yoast SEO is not active; og-yoast/get-general-settings does not register.' );
		}
	}

	public function test_it_registers(): void {
		$this->assertNotNull(
			wp_get_ability( self::ABILITY ),
			'og-yoast/get-general-settings must resolve after wp_abilities_api_init.'
		);
	}

	public function test_happy_path_returns_curated_keys_in_order(): void {
		$this->actingAs( 'administrator' );

		WPSEO_Options::set( 'enable_xml_sitemap', true, 'wpseo' );
		WPSEO_Options::set( 'keyword_analysis_active', true, 'wpseo' );

		$result = wp_get_ability( self::ABILITY )->execute( array() );

		$this->assertIsArray( $result, 'A permitted read must return the settings row, not a WP_Error.' );

		$this->assertSame(
			array(
				'enable_xml_sitemap',
				'enable_index_now',
				'enable_llms_txt',
				'enable_cornerstone_content',
				'enable_schema',
				'enable_text_link_counter',
				'enable_admin_bar_menu',
				'content_analysis_active',
				'keyword_analysis_active',
				'inclusive_language_analysis_active',
				'deny_search_crawling',
				'deny_wp_json_crawling',
				'deny_adsbot_crawling',
				'deny_ccbot_crawling',
				'deny_google_extended_crawling',
				'deny_gptbot_crawling',
				'disableadvanced_meta',
				'baiduverify_has_value',
				'googleverify_has_value',
				'msverify_has_value',
				'yandexverify_has_value',
				'ahrefsverify_has_value',
			),
			array_keys( $result ),
			'The flat row must carry the curated allow-list keys in order.'
		);

		$this->assertTrue( $result['enable_xml_sitemap'], 'A seeded enable_xml_sitemap = true must surface true.' );
		$this->assertTrue( $result['keyword_analysis_active'], 'A seeded keyword_analysis_active = true must surface true.' );
	}

	public function test_every_value_is_a_boolean(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( self::ABILITY )->execute( array() );

		$this->assertIsArray( $result );
		foreach ( $result as $key => $value ) {
			$this->assertIsBool( $value, sprintf( 'Curated key %s must surface as a boolean.', $key ) );
		}
	}

	/**
	 * The load-bearing case: secrets and raw verification codes never leave the ability.
	 *
	 * @return void
	 */
	public function test_secrets_are_excluded_and_verification_codes_are_masked(): void {
		$this->actingAs( 'administrator' );

		WPSEO_Options::set( 'googleverify', 'goog-secret-code-123', 'wpseo' );
		WPSEO_Options::set( 'index_now_key', 'ink-secret-key-456', 'wpseo' );
		WPSEO_Options::set( 'semrush_tokens', array( 'access_token' => 'sr-secret-789' ), 'wpseo' );

		$result = wp_get_ability( self::ABILITY )->execute( array() );

		$this->assertIsArray( $result );

		// The verification code is masked to a boolean, present and true.
		$this->assertArrayHasKey( 'googleverify_has_value', $result );
		$this->assertTrue(
			$result['googleverify_has_value'],
			'A set googleverify code must surface as googleverify_has_value === true.'
		);

		// No raw verification code under any key.
		$this->assertNotContains(
			'goog-secret-code-123',
			$result,
			'The raw googleverify code must never appear in the output.'
		);

		// The raw *verify keys themselves are never surfaced — only the masked variants.
		$this->assertArrayNotHasKey( 'googleverify', $result, 'The raw googleverify key must not be surfaced.' );
		$this->assertArrayNotHasKey( 'baiduverify', $result );
		$this->assertArrayNotHasKey( 'msverify', $result );
		$this->assertArrayNotHasKey( 'yandexverify', $result );
		$this->assertArrayNotHasKey( 'ahrefsverify', $result );

		// The token secrets that sit in the wpseo option are not on the allow-list,
		// so no key for any of them appears, and no raw value leaks.
		foreach ( array( 'index_now_key', 'semrush_tokens', 'wincher_tokens', 'myyoast-oauth' ) as $secret_key ) {
			$this->assertArrayNotHasKey(
				$secret_key,
				$result,
				sprintf( 'The secret %s must not be surfaced under its own key.', $secret_key )
			);
		}

		$this->assertNotContains(
			'ink-secret-key-456',
			$result,
			'The raw index_now_key value must never appear in the output.'
		);
	}

	public function test_unset_verification_code_masks_to_false(): void {
		$this->actingAs( 'administrator' );

		WPSEO_Options::set( 'baiduverify', '', 'wpseo' );

		$result = wp_get_ability( self::ABILITY )->execute( array() );

		$this->assertIsArray( $result );
		$this->assertFalse(
			$result['baiduverify_has_value'],
			'An empty baiduverify code must surface as baiduverify_has_value === false.'
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

	public function test_read_triggers_no_write(): void {
		$this->actingAs( 'administrator' );

		$wrote = false;
		$probe = static function ( $value ) use ( &$wrote ) {
			$wrote = true;
			return $value;
		};
		add_filter( 'pre_update_option_wpseo', $probe );

		wp_get_ability( self::ABILITY )->execute( array() );

		remove_filter( 'pre_update_option_wpseo', $probe );

		$this->assertFalse( $wrote, 'A read must not write the wpseo option.' );
	}
}
