<?php
/**
 * Integration tests for the og-yoast/update-social-settings ability.
 *
 * @package AbilitiesCatalogYoast\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogYoast\Tests\Integration\Abilities\Settings;

use GalatanOvidiu\AbilitiesCatalogYoast\Abilities\Yoast\Settings\UpdateSocialSettings;
use GalatanOvidiu\AbilitiesCatalogYoast\Support\YoastPlugin;
use GalatanOvidiu\AbilitiesCatalogYoast\Tests\TestCase;
use WPSEO_Options;

/**
 * Exercises the social-settings write end to end through the Abilities API.
 *
 * Fixtures are seeded with Yoast's own writer (`WPSEO_Options::set(..., 'wpseo_social')`),
 * then the ability writes through the facade and the group is re-read to confirm. The
 * whole class self-skips when Yoast SEO is inactive, since the ability does not register
 * then.
 */
final class UpdateSocialSettingsTest extends TestCase {

	private const ABILITY = 'og-yoast/update-social-settings';

	/**
	 * The exact output key order the updated row must carry.
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
			$this->markTestSkipped( 'Yoast SEO is not active; og-yoast/update-social-settings does not register.' );
		}
	}

	public function test_it_registers(): void {
		$this->assertNotNull(
			wp_get_ability( self::ABILITY ),
			'og-yoast/update-social-settings must resolve after wp_abilities_api_init.'
		);
	}

	public function test_happy_path_writes_and_returns_curated_row_in_key_order(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( self::ABILITY )->execute(
			array(
				'facebook_site'     => 'https://facebook.com/example',
				'twitter_card_type' => 'summary_large_image',
				'twitter_site'      => 'examplehandle',
			)
		);

		$this->assertIsArray( $result, 'A permitted write must return the updated social row, not a WP_Error.' );

		$this->assertSame(
			self::EXPECTED_KEYS,
			array_keys( $result ),
			'The updated row must carry the allow-list keys in order.'
		);

		$this->assertSame( 'https://facebook.com/example', $result['facebook_site'] );
		$this->assertSame( 'summary_large_image', $result['twitter_card_type'] );
		$this->assertSame( 'examplehandle', $result['twitter_site'] );
	}

	public function test_write_confirm_persists_to_the_option_group(): void {
		$this->actingAs( 'administrator' );

		wp_get_ability( self::ABILITY )->execute(
			array( 'instagram_url' => 'https://instagram.com/example' )
		);

		$stored = WPSEO_Options::get_option( 'wpseo_social' );

		$this->assertSame(
			'https://instagram.com/example',
			$stored['instagram_url'],
			'The write must round-trip into the wpseo_social option group.'
		);
	}

	public function test_boolean_toggle_writes_through(): void {
		$this->actingAs( 'administrator' );

		// Seed opengraph on, then turn it off through the ability.
		WPSEO_Options::set( 'opengraph', true, 'wpseo_social' );

		$result = wp_get_ability( self::ABILITY )->execute(
			array( 'opengraph' => false )
		);

		$this->assertIsArray( $result );
		$this->assertFalse( $result['opengraph'], 'opengraph must surface as the written boolean.' );

		$stored = WPSEO_Options::get_option( 'wpseo_social' );
		$this->assertFalse( (bool) $stored['opengraph'], 'opengraph false must round-trip into the option group.' );
	}

	public function test_unknown_key_surfaces_the_typed_allow_list_error(): void {
		$this->actingAs( 'administrator' );

		// The closed schema (additionalProperties:false) rejects an out-of-list key at
		// the Abilities API boundary, so to exercise the ability's own runtime allow-list
		// — the second, load-bearing deny-by-default guard — call execute() directly.
		$result = ( new UpdateSocialSettings() )->execute(
			array( 'not_a_real_social_key' => 'value' )
		);

		$this->assertWPError( $result );
		$this->assertSame(
			'og_yoast_unknown_setting_key',
			$result->get_error_code(),
			'An out-of-list key must surface the typed unknown-key error, not a permission error.'
		);
	}

	public function test_unknown_key_through_the_api_is_rejected_not_collapsed_to_permission(): void {
		$this->actingAs( 'administrator' );

		// Through the public API the closed schema rejects an unknown key first; the key
		// point is it surfaces as an input-validation error, never collapsed to permission.
		$result = wp_get_ability( self::ABILITY )->execute(
			array( 'not_a_real_social_key' => 'value' )
		);

		$this->assertWPError( $result );
		$this->assertNotSame(
			'ability_invalid_permissions',
			$result->get_error_code(),
			'An unknown key must not be masked as a permission failure.'
		);
	}

	public function test_pinterestverify_runtime_guard_rejects_the_token(): void {
		$this->actingAs( 'administrator' );

		// pinterestverify is off the allow-list; the runtime guard rejects it with the
		// typed unknown-key error (the schema also blocks it at the API boundary).
		$result = ( new UpdateSocialSettings() )->execute(
			array( 'pinterestverify' => 'secret-pinterest-token' )
		);

		$this->assertWPError( $result );
		$this->assertSame(
			'og_yoast_unknown_setting_key',
			$result->get_error_code(),
			'pinterestverify is a verification token and must be rejected as an unknown write key.'
		);

		$stored = WPSEO_Options::get_option( 'wpseo_social' );
		$this->assertNotSame(
			'secret-pinterest-token',
			$stored['pinterestverify'] ?? '',
			'A rejected pinterestverify must not be written.'
		);
	}

	public function test_pinterestverify_is_not_an_accepted_write_key_through_the_api(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( self::ABILITY )->execute(
			array( 'pinterestverify' => 'secret-pinterest-token' )
		);

		$this->assertWPError(
			$result,
			'The closed schema must reject pinterestverify at the API boundary.'
		);

		$stored = WPSEO_Options::get_option( 'wpseo_social' );
		$this->assertNotSame(
			'secret-pinterest-token',
			$stored['pinterestverify'] ?? '',
			'A rejected pinterestverify must not be written.'
		);
	}

	public function test_out_of_enum_twitter_card_type_is_rejected_at_schema_validation(): void {
		$this->actingAs( 'administrator' );

		// Going through the Abilities API enforces the closed enum, so an out-of-enum
		// value is rejected with a validation error rather than written.
		$result = wp_get_ability( self::ABILITY )->execute(
			array( 'twitter_card_type' => 'player' )
		);

		$this->assertWPError(
			$result,
			'A twitter_card_type outside Yoast\'s accepted list must be rejected.'
		);
		$this->assertNotSame(
			'ability_invalid_permissions',
			$result->get_error_code(),
			'An out-of-enum value must be an input-validation failure, not a permission failure.'
		);

		$stored = WPSEO_Options::get_option( 'wpseo_social' );
		$this->assertNotSame(
			'player',
			$stored['twitter_card_type'] ?? '',
			'A rejected twitter_card_type must not be written.'
		);
	}

	public function test_under_privileged_user_is_denied(): void {
		// A subscriber lacks wpseo_manage_options (Yoast grants it to administrators).
		$this->actingAs( 'subscriber' );

		$result = wp_get_ability( self::ABILITY )->execute(
			array( 'facebook_site' => 'https://facebook.com/example' )
		);

		$this->assertWPError( $result );
		$this->assertSame(
			'ability_invalid_permissions',
			$result->get_error_code(),
			'A caller without wpseo_manage_options must be denied by the Abilities API.'
		);
	}
}
