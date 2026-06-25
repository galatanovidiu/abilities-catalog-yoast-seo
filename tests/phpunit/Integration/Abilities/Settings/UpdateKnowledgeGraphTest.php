<?php
/**
 * Integration tests for the og-yoast/update-knowledge-graph ability.
 *
 * @package AbilitiesCatalogYoast\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogYoast\Tests\Integration\Abilities\Settings;

use GalatanOvidiu\AbilitiesCatalogYoast\Abilities\Yoast\Settings\UpdateKnowledgeGraph;
use GalatanOvidiu\AbilitiesCatalogYoast\Support\YoastPlugin;
use GalatanOvidiu\AbilitiesCatalogYoast\Tests\TestCase;
use WPSEO_Options;

/**
 * Exercises the site-identity (knowledge graph) write end to end through the Abilities API.
 *
 * The knowledge-graph identity keys live in the `wpseo_titles` option group, seeded and
 * confirmed here through Yoast's own `WPSEO_Options::set` / `get_option`. The whole class
 * self-skips when Yoast SEO is inactive, since the ability does not register then.
 */
final class UpdateKnowledgeGraphTest extends TestCase {

	private const ABILITY = 'og-yoast/update-knowledge-graph';

	/**
	 * The full output key order the updated row must carry.
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
			$this->markTestSkipped( 'Yoast SEO is not active; og-yoast/update-knowledge-graph does not register.' );
		}
	}

	public function test_it_registers(): void {
		$this->assertNotNull(
			wp_get_ability( self::ABILITY ),
			'og-yoast/update-knowledge-graph must resolve after wp_abilities_api_init.'
		);
	}

	public function test_happy_path_writes_and_returns_row_in_key_order(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( self::ABILITY )->execute(
			array(
				'company_or_person' => 'company',
				'company_name'      => 'Acme Corporation',
				'org-email'         => 'hello@acme.example',
			)
		);

		$this->assertIsArray( $result, 'A permitted write must return the identity row, not a WP_Error.' );

		$this->assertSame(
			self::EXPECTED_KEYS,
			array_keys( $result ),
			'The updated row must carry the documented allow-list keys in order.'
		);

		$this->assertSame( 'company', $result['company_or_person'] );
		$this->assertSame( 'Acme Corporation', $result['company_name'] );
		$this->assertSame( 'hello@acme.example', $result['org-email'] );
	}

	public function test_write_round_trips_to_yoast_store(): void {
		$this->actingAs( 'administrator' );

		wp_get_ability( self::ABILITY )->execute(
			array(
				'company_name' => 'Confirmed Co',
			)
		);

		// Re-read through Yoast's own store to prove the write stuck, not just the return.
		$stored = WPSEO_Options::get_option( 'wpseo_titles' );
		$this->assertSame(
			'Confirmed Co',
			$stored['company_name'],
			'The written value must round-trip into the wpseo_titles option.'
		);
	}

	public function test_integer_key_is_stored_as_integer(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( self::ABILITY )->execute(
			array(
				'company_logo_id' => 42,
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( 42, $result['company_logo_id'], 'An attachment-ID key must be returned as an integer.' );
	}

	public function test_unset_keys_read_as_typed_defaults(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( self::ABILITY )->execute(
			array(
				'website_name' => 'Example Site',
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( '', $result['org-phone'], 'An unset string key must read as an empty string.' );
		$this->assertSame( 0, $result['person_logo_id'], 'An unset integer key must read as 0.' );
		$this->assertIsObject( $result['company_logo_meta'], 'A meta key must serialize as an object, even when empty.' );
	}

	public function test_unknown_key_is_rejected_not_written(): void {
		$this->actingAs( 'administrator' );

		$before = WPSEO_Options::get_option( 'wpseo_titles' );

		// The closed schema rejects an out-of-list key at the Abilities API boundary, so to
		// exercise the ability's own runtime allow-list — the second, load-bearing
		// deny-by-default guard — call execute() directly.
		$result = ( new UpdateKnowledgeGraph() )->execute(
			array(
				'noindex-author-wpseo' => true,
			)
		);

		$this->assertWPError( $result, 'A key outside the knowledge-graph allow-list must be rejected.' );
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

	public function test_company_or_person_enum_rejects_out_of_enum_value(): void {
		$this->actingAs( 'administrator' );

		// The closed schema rejects an off-enum value at the API boundary; call execute()
		// directly to exercise the ability's own runtime enum guard that backs it up.
		$result = ( new UpdateKnowledgeGraph() )->execute(
			array(
				'company_or_person' => 'robot',
			)
		);

		$this->assertWPError( $result, 'An out-of-enum company_or_person value must be rejected.' );
		$this->assertSame(
			'og_yoast_unknown_setting_key',
			$result->get_error_code(),
			'The off-enum value must surface the typed 400 guard, not a silent write.'
		);
		$this->assertSame( 400, $result->get_error_data()['status'] ?? null );
	}

	public function test_under_privileged_user_is_denied(): void {
		// A subscriber lacks wpseo_manage_options.
		$this->actingAs( 'subscriber' );

		$result = wp_get_ability( self::ABILITY )->execute(
			array(
				'company_name' => 'Should Not Save',
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
