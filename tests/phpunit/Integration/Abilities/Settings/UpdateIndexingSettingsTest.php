<?php
/**
 * Integration tests for the og-yoast/update-indexing-settings ability.
 *
 * @package AbilitiesCatalogYoast\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogYoast\Tests\Integration\Abilities\Settings;

use GalatanOvidiu\AbilitiesCatalogYoast\Abilities\Yoast\Settings\UpdateIndexingSettings;
use GalatanOvidiu\AbilitiesCatalogYoast\Support\YoastPlugin;
use GalatanOvidiu\AbilitiesCatalogYoast\Tests\TestCase;
use WPSEO_Options;

/**
 * Exercises the dangerous site-indexing write end to end through the Abilities API.
 *
 * Fixtures are seeded with Yoast's own writer (`WPSEO_Options::set(..., 'wpseo_titles')`),
 * then the ability writes through the facade and the group is re-read to confirm. The
 * whole class self-skips when Yoast SEO is inactive, since the ability does not register
 * then.
 */
final class UpdateIndexingSettingsTest extends TestCase {

	private const ABILITY = 'og-yoast/update-indexing-settings';

	/**
	 * The exact output key order the result must carry.
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
			$this->markTestSkipped( 'Yoast SEO is not active; og-yoast/update-indexing-settings does not register.' );
		}
	}

	public function test_it_registers(): void {
		$this->assertNotNull(
			wp_get_ability( self::ABILITY ),
			'og-yoast/update-indexing-settings must resolve after wp_abilities_api_init.'
		);
	}

	public function test_happy_path_returns_old_to_new_block_in_key_order(): void {
		$this->actingAs( 'administrator' );

		// Seed the opposite value so `from` is non-trivial.
		WPSEO_Options::set( 'noindex-archive-wpseo', false, 'wpseo_titles' );

		$result = wp_get_ability( self::ABILITY )->execute(
			array( 'noindex-archive-wpseo' => true )
		);

		$this->assertIsArray( $result, 'A permitted write must return the transparency block, not a WP_Error.' );

		$this->assertSame(
			self::EXPECTED_KEYS,
			array_keys( $result ),
			'The result must carry changed/unchanged/rejected in order.'
		);

		$changed = (array) $result['changed'];

		$this->assertArrayHasKey(
			'noindex-archive-wpseo',
			$changed,
			'The changed key noindex-archive-wpseo must carry an old-to-new block.'
		);
		$this->assertSame(
			array(
				'from' => false,
				'to'   => true,
			),
			$changed['noindex-archive-wpseo'],
			'The old-to-new block must report the seeded false changing to true.'
		);

		$stored = WPSEO_Options::get_option( 'wpseo_titles' );
		$this->assertTrue(
			(bool) $stored['noindex-archive-wpseo'],
			'noindex-archive-wpseo true must round-trip into the wpseo_titles option group.'
		);
	}

	public function test_already_matching_value_is_reported_unchanged_not_changed(): void {
		$this->actingAs( 'administrator' );

		WPSEO_Options::set( 'noindex-archive-wpseo', true, 'wpseo_titles' );

		$result = wp_get_ability( self::ABILITY )->execute(
			array( 'noindex-archive-wpseo' => true )
		);

		$this->assertIsArray( $result );
		$this->assertSame( array(), (array) $result['changed'], 'A no-op write must not appear in changed.' );
		$this->assertContains(
			'noindex-archive-wpseo',
			$result['unchanged'],
			'A submitted value that already matched must be reported unchanged.'
		);
	}

	public function test_unknown_post_type_is_rejected_not_written_and_not_a_hard_error(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( self::ABILITY )->execute(
			array(
				'noindex-archive-wpseo' => true,
				'post_type_noindex'     => array( 'not_a_real_post_type' => true ),
			)
		);

		$this->assertIsArray( $result, 'An unknown post type must not make the whole call a hard error.' );
		$this->assertContains(
			'post_type_noindex.not_a_real_post_type',
			$result['rejected'],
			'An unknown post type must be reported in rejected[].'
		);

		$stored = WPSEO_Options::get_option( 'wpseo_titles' );
		$this->assertArrayNotHasKey(
			'noindex-not_a_real_post_type',
			$stored,
			'A rejected post type must not be written to the option group.'
		);
	}

	public function test_known_post_type_writes_under_the_prefixed_key(): void {
		$this->actingAs( 'administrator' );

		WPSEO_Options::set( 'noindex-post', false, 'wpseo_titles' );

		$result = wp_get_ability( self::ABILITY )->execute(
			array( 'post_type_noindex' => array( 'post' => true ) )
		);

		$this->assertIsArray( $result );
		$this->assertArrayHasKey(
			'noindex-post',
			(array) $result['changed'],
			'A public post type must write under the noindex-<post_type> key.'
		);

		$stored = WPSEO_Options::get_option( 'wpseo_titles' );
		$this->assertTrue( (bool) $stored['noindex-post'], 'noindex-post must round-trip into the option group.' );
	}

	public function test_zero_writable_keys_surfaces_the_no_writable_keys_error(): void {
		$this->actingAs( 'administrator' );

		// Every submitted object is rejected and no static key is sent — direct execute()
		// to bypass the closed schema (which permits the object map shape but holds no
		// valid object keys at runtime).
		$result = ( new UpdateIndexingSettings() )->execute(
			array( 'taxonomy_noindex' => array( 'not_a_real_taxonomy' => true ) )
		);

		$this->assertWPError( $result );
		$this->assertSame(
			'og_yoast_no_writable_keys',
			$result->get_error_code(),
			'An input resolving to zero writable keys must surface the typed no-writable-keys error.'
		);
	}

	public function test_unknown_top_level_key_surfaces_the_typed_allow_list_error(): void {
		$this->actingAs( 'administrator' );

		// The closed schema (additionalProperties:false) rejects an out-of-list key at the
		// Abilities API boundary, so to exercise the ability's own runtime allow-list — the
		// load-bearing deny-by-default guard — call execute() directly.
		$result = ( new UpdateIndexingSettings() )->execute(
			array( 'not_a_real_indexing_key' => true )
		);

		$this->assertWPError( $result );
		$this->assertSame(
			'og_yoast_unknown_setting_key',
			$result->get_error_code(),
			'An out-of-list key must surface the typed unknown-key error, not a permission error.'
		);
	}

	public function test_unknown_top_level_key_through_the_api_is_not_collapsed_to_permission(): void {
		$this->actingAs( 'administrator' );

		// Through the public API the closed schema rejects an unknown key first; the key
		// point is it surfaces as an input-validation error, never collapsed to permission.
		$result = wp_get_ability( self::ABILITY )->execute(
			array( 'not_a_real_indexing_key' => true )
		);

		$this->assertWPError( $result );
		$this->assertNotSame(
			'ability_invalid_permissions',
			$result->get_error_code(),
			'An unknown key must not be masked as a permission failure.'
		);
	}

	public function test_under_privileged_user_is_denied(): void {
		// A subscriber lacks wpseo_manage_options (Yoast grants it to administrators).
		$this->actingAs( 'subscriber' );

		$result = wp_get_ability( self::ABILITY )->execute(
			array( 'noindex-archive-wpseo' => true )
		);

		$this->assertWPError( $result );
		$this->assertSame(
			'ability_invalid_permissions',
			$result->get_error_code(),
			'A caller without wpseo_manage_options must be denied by the Abilities API.'
		);
	}
}
