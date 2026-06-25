<?php
/**
 * Integration tests for the og-yoast/get-social-settings ability.
 *
 * @package AbilitiesCatalogYoast\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogYoast\Tests\Integration\Abilities\Settings;

use GalatanOvidiu\AbilitiesCatalogYoast\Support\YoastPlugin;
use GalatanOvidiu\AbilitiesCatalogYoast\Tests\TestCase;
use WPSEO_Options;

/**
 * Exercises the social-settings read end to end through the Abilities API.
 *
 * Settings are seeded with Yoast's own writer (`WPSEO_Options::set(..., 'wpseo_social')`),
 * then read back through the ability. The whole class self-skips when Yoast SEO is
 * inactive, since the ability does not register then.
 */
final class GetSocialSettingsTest extends TestCase {

	private const ABILITY = 'og-yoast/get-social-settings';

	/**
	 * The exact output key order the flat row must carry.
	 *
	 * @var list<string>
	 */
	private const EXPECTED_KEYS = array(
		'facebook_site',
		'instagram_url',
		'linkedin_url',
		'myspace_url',
		'og_default_image',
		'og_default_image_id',
		'og_frontpage_title',
		'og_frontpage_desc',
		'og_frontpage_image',
		'og_frontpage_image_id',
		'opengraph',
		'pinterest_url',
		'twitter',
		'twitter_site',
		'twitter_card_type',
		'youtube_url',
		'wikipedia_url',
		'other_social_urls',
		'mastodon_url',
	);

	/**
	 * Skips the class when Yoast SEO is inactive.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		if ( ! YoastPlugin::isActive() ) {
			$this->markTestSkipped( 'Yoast SEO is not active; og-yoast/get-social-settings does not register.' );
		}
	}

	public function test_it_registers(): void {
		$this->assertNotNull(
			wp_get_ability( self::ABILITY ),
			'og-yoast/get-social-settings must resolve after wp_abilities_api_init.'
		);
	}

	public function test_happy_path_returns_curated_row_in_key_order(): void {
		$this->actingAs( 'administrator' );

		WPSEO_Options::set( 'twitter_card_type', 'summary_large_image', 'wpseo_social' );
		WPSEO_Options::set( 'facebook_site', 'https://facebook.com/example', 'wpseo_social' );

		$result = wp_get_ability( self::ABILITY )->execute( array() );

		$this->assertIsArray( $result, 'A permitted read must return the social settings row, not a WP_Error.' );

		$this->assertSame(
			self::EXPECTED_KEYS,
			array_keys( $result ),
			'The flat row must carry the allow-list keys in order.'
		);

		$this->assertSame( 'summary_large_image', $result['twitter_card_type'] );
		$this->assertSame( 'https://facebook.com/example', $result['facebook_site'] );
	}

	public function test_typed_fields_have_the_documented_types(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( self::ABILITY )->execute( array() );

		$this->assertIsArray( $result );
		$this->assertIsBool( $result['opengraph'], 'opengraph must surface as a boolean.' );
		$this->assertIsBool( $result['twitter'], 'twitter must surface as a boolean.' );
		$this->assertIsInt( $result['og_default_image_id'], 'og_default_image_id must surface as an integer.' );
		$this->assertIsInt( $result['og_frontpage_image_id'], 'og_frontpage_image_id must surface as an integer.' );
		$this->assertIsArray( $result['other_social_urls'], 'other_social_urls must surface as an array.' );
	}

	public function test_pinterestverify_is_never_in_the_output_even_when_set(): void {
		$this->actingAs( 'administrator' );

		// Seed the Pinterest verification token; it must never reach the output.
		WPSEO_Options::set( 'pinterestverify', 'secret-pinterest-token', 'wpseo_social' );

		$result = wp_get_ability( self::ABILITY )->execute( array() );

		$this->assertIsArray( $result );
		$this->assertArrayNotHasKey(
			'pinterestverify',
			$result,
			'The Pinterest verification token must never appear in the output.'
		);
		$this->assertNotContains(
			'secret-pinterest-token',
			$result,
			'The raw Pinterest verification token must not leak under any key.'
		);
	}

	public function test_read_triggers_no_write(): void {
		$this->actingAs( 'administrator' );

		WPSEO_Options::set( 'twitter_site', 'examplehandle', 'wpseo_social' );

		$before = WPSEO_Options::get_option( 'wpseo_social' );

		wp_get_ability( self::ABILITY )->execute( array() );

		$after = WPSEO_Options::get_option( 'wpseo_social' );

		$this->assertSame(
			$before,
			$after,
			'A read must not mutate the wpseo_social option group.'
		);
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
