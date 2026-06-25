<?php
/**
 * Integration tests for the og-yoast/get-knowledge-graph ability.
 *
 * @package AbilitiesCatalogYoast\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogYoast\Tests\Integration\Abilities\Settings;

use GalatanOvidiu\AbilitiesCatalogYoast\Support\YoastPlugin;
use GalatanOvidiu\AbilitiesCatalogYoast\Tests\TestCase;
use WPSEO_Options;

/**
 * Exercises the site-identity (knowledge graph) read end to end through the Abilities API.
 *
 * The knowledge-graph identity keys live in the `wpseo_titles` option group, seeded
 * here through Yoast's own `WPSEO_Options::set`. The whole class self-skips when Yoast
 * SEO is inactive, since the ability does not register then.
 */
final class GetKnowledgeGraphTest extends TestCase {

	private const ABILITY = 'og-yoast/get-knowledge-graph';

	/**
	 * The full output key order the row must carry.
	 *
	 * @var list<string>
	 */
	private const EXPECTED_KEYS = array(
		'company_or_person',
		'company_or_person_user_id',
		'website_name',
		'alternate_website_name',
		'person_name',
		'person_logo',
		'person_logo_id',
		'person_logo_meta',
		'company_name',
		'company_alternate_name',
		'company_logo',
		'company_logo_id',
		'company_logo_meta',
		'org-description',
		'org-email',
		'org-phone',
		'org-legal-name',
		'org-founding-date',
		'org-number-employees',
		'org-vat-id',
		'org-tax-id',
		'org-iso',
		'org-duns',
		'org-leicode',
		'org-naics',
	);

	/**
	 * Skips the class when Yoast SEO is inactive.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		if ( ! YoastPlugin::isActive() ) {
			$this->markTestSkipped( 'Yoast SEO is not active; og-yoast/get-knowledge-graph does not register.' );
		}
	}

	public function test_it_registers(): void {
		$this->assertNotNull(
			wp_get_ability( self::ABILITY ),
			'og-yoast/get-knowledge-graph must resolve after wp_abilities_api_init.'
		);
	}

	public function test_happy_path_returns_flat_seeded_values_in_key_order(): void {
		$this->actingAs( 'administrator' );

		WPSEO_Options::set( 'company_or_person', 'company', 'wpseo_titles' );
		WPSEO_Options::set( 'company_name', 'Acme Corporation', 'wpseo_titles' );

		$result = wp_get_ability( self::ABILITY )->execute( array() );

		$this->assertIsArray( $result, 'A permitted read must return the identity row, not a WP_Error.' );

		$this->assertSame(
			self::EXPECTED_KEYS,
			array_keys( $result ),
			'The flat row must carry the documented allow-list keys in order.'
		);

		$this->assertSame( 'company', $result['company_or_person'] );
		$this->assertSame( 'Acme Corporation', $result['company_name'] );
	}

	public function test_unset_keys_read_as_typed_defaults(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( self::ABILITY )->execute( array() );

		$this->assertIsArray( $result );
		$this->assertSame( '', $result['org-email'], 'An unset string identity key must read as an empty string.' );
		$this->assertSame( 0, $result['person_logo_id'], 'An unset integer key must read as 0.' );
		$this->assertIsObject( $result['person_logo_meta'], 'A meta key must serialize as an object, even when empty.' );
	}

	public function test_company_or_person_selector_surfaces_person(): void {
		$this->actingAs( 'administrator' );

		WPSEO_Options::set( 'company_or_person', 'person', 'wpseo_titles' );
		WPSEO_Options::set( 'person_name', 'Jane Doe', 'wpseo_titles' );

		$result = wp_get_ability( self::ABILITY )->execute( array() );

		$this->assertIsArray( $result );
		$this->assertSame( 'person', $result['company_or_person'] );
		$this->assertSame( 'Jane Doe', $result['person_name'] );
	}

	public function test_read_triggers_no_write(): void {
		$this->actingAs( 'administrator' );

		WPSEO_Options::set( 'company_name', 'Acme Corporation', 'wpseo_titles' );

		$before = get_option( 'wpseo_titles' );

		wp_get_ability( self::ABILITY )->execute( array() );

		$after = get_option( 'wpseo_titles' );

		$this->assertSame(
			$before,
			$after,
			'A read must not change any stored Yoast option.'
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
