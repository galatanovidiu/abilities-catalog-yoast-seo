<?php
/**
 * Integration tests for the og-yoast/update-general-settings ability.
 *
 * @package AbilitiesCatalogYoast\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogYoast\Tests\Integration\Abilities\Settings;

use GalatanOvidiu\AbilitiesCatalogYoast\Abilities\Yoast\Settings\UpdateGeneralSettings;
use GalatanOvidiu\AbilitiesCatalogYoast\Support\YoastPlugin;
use GalatanOvidiu\AbilitiesCatalogYoast\Tests\TestCase;
use WPSEO_Options;

/**
 * Exercises the general-settings write end to end through the Abilities API.
 *
 * Fixtures are seeded with Yoast's own writer (`WPSEO_Options::set(..., 'wpseo')`),
 * then the ability writes through the facade and the group is re-read to confirm. The
 * write returns an old-to-new {changed, unchanged, rejected} block (it is a dangerous
 * write), so the tests assert that shape and the {from, to} per changed key. The whole
 * class self-skips when Yoast SEO is inactive, since the ability does not register then.
 */
final class UpdateGeneralSettingsTest extends TestCase {

	private const ABILITY = 'og-yoast/update-general-settings';

	/**
	 * The exact output key order the result block must carry.
	 *
	 * @var list<string>
	 */
	private const EXPECTED_KEYS = array(
		'changed',
		'unchanged',
		'rejected',
	);

	/**
	 * Skips the class when Yoast SEO is inactive.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		if ( ! YoastPlugin::isActive() ) {
			$this->markTestSkipped( 'Yoast SEO is not active; og-yoast/update-general-settings does not register.' );
		}
	}

	public function test_it_registers(): void {
		$this->assertNotNull(
			wp_get_ability( self::ABILITY ),
			'og-yoast/update-general-settings must resolve after wp_abilities_api_init.'
		);
	}

	public function test_happy_path_writes_and_returns_change_block_in_key_order(): void {
		$this->actingAs( 'administrator' );

		// Seed the opposite value so the from side of the change block is non-trivial.
		WPSEO_Options::set( 'enable_xml_sitemap', true, 'wpseo' );

		$result = wp_get_ability( self::ABILITY )->execute(
			array( 'enable_xml_sitemap' => false )
		);

		$this->assertIsArray( $result, 'A permitted write must return the change block, not a WP_Error.' );

		$this->assertSame(
			self::EXPECTED_KEYS,
			array_keys( $result ),
			'The result must carry changed, unchanged, rejected in order.'
		);

		$changed = (array) $result['changed'];

		$this->assertArrayHasKey(
			'enable_xml_sitemap',
			$changed,
			'enable_xml_sitemap must be reported as changed.'
		);
		$this->assertSame(
			array(
				'from' => true,
				'to'   => false,
			),
			$changed['enable_xml_sitemap'],
			'The change block must carry the old-to-new value.'
		);

		$this->assertSame( array(), $result['unchanged'] );
		$this->assertSame( array(), $result['rejected'] );
	}

	public function test_write_confirm_persists_to_the_option_group(): void {
		$this->actingAs( 'administrator' );

		WPSEO_Options::set( 'enable_xml_sitemap', true, 'wpseo' );

		wp_get_ability( self::ABILITY )->execute(
			array( 'enable_xml_sitemap' => false )
		);

		$stored = WPSEO_Options::get_option( 'wpseo' );

		$this->assertFalse(
			(bool) $stored['enable_xml_sitemap'],
			'The write must round-trip into the wpseo option group.'
		);
	}

	public function test_unchanged_key_is_reported_not_changed(): void {
		$this->actingAs( 'administrator' );

		// Seed a known value, then submit the same value: it must be a no-op.
		WPSEO_Options::set( 'enable_cornerstone_content', true, 'wpseo' );

		$result = wp_get_ability( self::ABILITY )->execute(
			array( 'enable_cornerstone_content' => true )
		);

		$this->assertIsArray( $result );
		$this->assertSame( array(), (array) $result['changed'], 'A same-value submit must not be reported as changed.' );
		$this->assertSame( array( 'enable_cornerstone_content' ), $result['unchanged'] );
	}

	public function test_verification_code_string_writes_through(): void {
		$this->actingAs( 'administrator' );

		WPSEO_Options::set( 'googleverify', '', 'wpseo' );

		$result = wp_get_ability( self::ABILITY )->execute(
			array( 'googleverify' => 'abc123googletoken' )
		);

		$this->assertIsArray( $result );

		$changed = (array) $result['changed'];
		$this->assertArrayHasKey( 'googleverify', $changed );
		$this->assertSame( '', $changed['googleverify']['from'] );
		$this->assertSame( 'abc123googletoken', $changed['googleverify']['to'] );
	}

	public function test_secret_key_runtime_guard_rejects_and_does_not_write(): void {
		$this->actingAs( 'administrator' );

		// semrush_tokens is a wpseo secret/token key off the general_features allow-list.
		// The closed schema blocks it at the API boundary, so to exercise the ability's
		// own deny-by-default runtime guard, call execute() directly on a fresh instance.
		$result = ( new UpdateGeneralSettings() )->execute(
			array( 'semrush_tokens' => 'secret-semrush-token' )
		);

		$this->assertWPError( $result );
		$this->assertSame(
			'og_yoast_unknown_setting_key',
			$result->get_error_code(),
			'A secret key must surface the typed unknown-key error, not a permission error.'
		);
		$this->assertSame(
			400,
			$result->get_error_data()['status'] ?? null,
			'The unknown-key error must carry HTTP status 400.'
		);

		$stored = WPSEO_Options::get_option( 'wpseo' );
		$this->assertNotSame(
			'secret-semrush-token',
			$stored['semrush_tokens'] ?? '',
			'A rejected secret key must not be written.'
		);
	}

	public function test_secret_key_through_the_api_is_not_collapsed_to_permission(): void {
		$this->actingAs( 'administrator' );

		// Through the public API the closed schema rejects the off-list key first; the
		// key point is it surfaces as an input-validation error, never as permission.
		$result = wp_get_ability( self::ABILITY )->execute(
			array( 'semrush_tokens' => 'secret-semrush-token' )
		);

		$this->assertWPError( $result );
		$this->assertNotSame(
			'ability_invalid_permissions',
			$result->get_error_code(),
			'An off-list key must not be masked as a permission failure.'
		);

		$stored = WPSEO_Options::get_option( 'wpseo' );
		$this->assertNotSame(
			'secret-semrush-token',
			$stored['semrush_tokens'] ?? '',
			'A rejected secret key must not be written.'
		);
	}

	public function test_empty_input_surfaces_no_writable_keys(): void {
		$this->actingAs( 'administrator' );

		$result = ( new UpdateGeneralSettings() )->execute( array() );

		$this->assertWPError( $result );
		$this->assertSame(
			'og_yoast_no_writable_keys',
			$result->get_error_code(),
			'Zero writable keys must surface the typed no-writable-keys error.'
		);
	}

	public function test_under_privileged_user_is_denied(): void {
		// A subscriber lacks wpseo_manage_options (Yoast grants it to administrators).
		$this->actingAs( 'subscriber' );

		$result = wp_get_ability( self::ABILITY )->execute(
			array( 'enable_xml_sitemap' => false )
		);

		$this->assertWPError( $result );
		$this->assertSame(
			'ability_invalid_permissions',
			$result->get_error_code(),
			'A caller without wpseo_manage_options must be denied by the Abilities API.'
		);
	}
}
