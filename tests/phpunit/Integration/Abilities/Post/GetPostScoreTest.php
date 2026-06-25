<?php
/**
 * Integration tests for the og-yoast/get-post-score ability.
 *
 * @package AbilitiesCatalogYoast\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogYoast\Tests\Integration\Abilities\Post;

use GalatanOvidiu\AbilitiesCatalogYoast\Support\YoastPlugin;
use GalatanOvidiu\AbilitiesCatalogYoast\Tests\TestCase;
use WP_Error;
use WPSEO_Meta;

/**
 * Exercises og-yoast/get-post-score: the stored SEO/readability/inclusive-language
 * scores translated to Yoast's ranks, the read-only (never-analyze) contract, the
 * missing-post 404 that must not collapse to a permission error, and the
 * under-privileged denial.
 *
 * The whole class skips when Yoast SEO is inactive (the conditional ability does not
 * register without it). Fixtures are seeded through Yoast's own save path
 * (`WPSEO_Meta::set_value`).
 */
final class GetPostScoreTest extends TestCase {

	/**
	 * Skips the class when Yoast SEO is inactive.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		if ( ! YoastPlugin::isActive() ) {
			$this->markTestSkipped( 'Yoast SEO is not active; og-yoast/get-post-score does not register.' );
		}
	}

	/**
	 * Creates a post and seeds its Yoast scores through Yoast's own setter.
	 *
	 * @param array<string,int|string> $scores Yoast meta key => numeric score.
	 * @return int The created post ID.
	 */
	private function seedPostWithScores( array $scores = array() ): int {
		$post_id = self::factory()->post->create();

		foreach ( $scores as $key => $value ) {
			WPSEO_Meta::set_value( $key, (string) $value, $post_id );
		}

		return $post_id;
	}

	public function test_ability_is_registered(): void {
		$ability = wp_get_ability( 'og-yoast/get-post-score' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'og-yoast/get-post-score', $ability->get_name() );
	}

	public function test_admin_reads_stored_scores_with_ranks_and_key_order(): void {
		$this->actingAs( 'administrator' );
		$post_id = $this->seedPostWithScores(
			array(
				'linkdex'                  => 90,
				'content_score'            => 50,
				'inclusive_language_score' => 30,
			)
		);

		$result = wp_get_ability( 'og-yoast/get-post-score' )->execute( array( 'post_id' => $post_id ) );

		$this->assertIsArray( $result );
		$this->assertSame(
			array( 'post_id', 'seo_score', 'readability_score', 'inclusive_language_score' ),
			array_keys( $result )
		);
		$this->assertSame( $post_id, $result['post_id'] );

		// Each score is an object with the seeded value and the expected rank band.
		$seo = (array) $result['seo_score'];
		$this->assertSame( 90, $seo['value'] );
		$this->assertSame( 'good', $seo['rank'] );

		$readability = (array) $result['readability_score'];
		$this->assertSame( 50, $readability['value'] );
		$this->assertSame( 'ok', $readability['rank'] );

		$inclusive = (array) $result['inclusive_language_score'];
		$this->assertSame( 30, $inclusive['value'] );
		$this->assertSame( 'bad', $inclusive['rank'] );
	}

	public function test_unscored_post_returns_na_without_triggering_analysis(): void {
		$this->actingAs( 'administrator' );
		$post_id = $this->seedPostWithScores(); // No scores seeded.

		$result = wp_get_ability( 'og-yoast/get-post-score' )->execute( array( 'post_id' => $post_id ) );

		$this->assertIsArray( $result );

		// Stored values only: an unanalyzed post reports na, value 0/null — proving no
		// analysis was triggered (Yoast never computes server-side).
		foreach ( array( 'seo_score', 'readability_score', 'inclusive_language_score' ) as $key ) {
			$score = (array) $result[ $key ];
			$this->assertSame( 'na', $score['rank'], $key . ' on an unscored post must rank na.' );
			$this->assertTrue(
				null === $score['value'] || 0 === $score['value'],
				$key . ' on an unscored post must report a null/0 value, not a computed score.'
			);
		}

		// The stored meta is still empty/default after the read — nothing was written.
		$this->assertSame( '0', (string) WPSEO_Meta::get_value( 'linkdex', $post_id ) );
	}

	public function test_missing_post_returns_404_not_permission_error(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'og-yoast/get-post-score' )->execute( array( 'post_id' => 99999999 ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'yoast_post_not_found', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] );
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_logged_out_user_is_denied(): void {
		$post_id = $this->seedPostWithScores( array( 'linkdex' => 90 ) );
		wp_set_current_user( 0 );

		$result = wp_get_ability( 'og-yoast/get-post-score' )->execute( array( 'post_id' => $post_id ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_subscriber_is_denied(): void {
		$post_id = $this->seedPostWithScores( array( 'linkdex' => 90 ) );
		$this->actingAs( 'subscriber' );

		$result = wp_get_ability( 'og-yoast/get-post-score' )->execute( array( 'post_id' => $post_id ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}
}
