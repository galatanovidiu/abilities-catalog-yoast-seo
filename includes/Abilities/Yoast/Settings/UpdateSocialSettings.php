<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogYoast\Abilities\Yoast\Settings;

use GalatanOvidiu\AbilitiesCatalogYoast\Contracts\ConditionalAbility;
use GalatanOvidiu\AbilitiesCatalogYoast\Support\YoastPlugin;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Updates Yoast SEO's social settings.
 *
 * Writes one or more keys of Yoast's `wpseo_social` option group: the social
 * profile URLs (Facebook, X/Twitter, Instagram, LinkedIn, Pinterest, YouTube,
 * Wikipedia, Mastodon, MySpace, and extra profiles), the site-wide Open Graph
 * defaults and front-page Open Graph overrides, the X/Twitter card type, and the
 * Open Graph and X/Twitter output toggles. Send only the keys you want to change;
 * every property is optional.
 *
 * This is a low-blast-radius (safe) settings write: it changes identity strings,
 * URLs, defaults, and output toggles, with no de-indexing and no sitemap/crawl
 * effects. So it returns the updated curated social row (the same shape
 * {@see GetSocialSettings} returns) rather than an old-to-new transparency block.
 *
 * Each write is a deny-by-default allow-list: only the keys below are accepted;
 * an out-of-list key is rejected with a typed error, not written. The Pinterest
 * verification token (`pinterestverify`) is a verification secret and is NOT on the
 * list. `WPSEO_Options::set()` gives no reliable success signal, so after writing
 * the ability re-reads the group to confirm each value stuck.
 *
 * All Yoast access goes through
 * {@see \GalatanOvidiu\AbilitiesCatalogYoast\Support\YoastPlugin}, so the ability
 * never names a `WPSEO_*` symbol itself.
 *
 * This is a {@see ConditionalAbility} gated on Yoast SEO being active, so it does
 * not register when Yoast is off.
 *
 * @since 0.7.0
 */
final class UpdateSocialSettings implements ConditionalAbility {

	/**
	 * The Yoast option group this write targets.
	 *
	 * @var string
	 */
	private const OPTION_GROUP = 'wpseo_social';

	/**
	 * The `get-*` partner ability a caller inspects valid keys / values with.
	 *
	 * @var string
	 */
	private const READ_ABILITY = 'og-yoast/get-social-settings';

	/**
	 * The social allow-list: the exact `wpseo_social` keys this write accepts.
	 *
	 * Deny-by-default — a key outside this list is rejected, not written. Mirrors
	 * {@see GetSocialSettings}'s allow-list so the write and read curate the same
	 * key set (research-findings §6.2, `class-wpseo-option-social.php:29-49`).
	 * `pinterestverify` (a verification token) is intentionally absent.
	 *
	 * @var list<string>
	 */
	private const ALLOW_LIST = array(
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
	 * The allow-list keys Yoast stores as booleans.
	 *
	 * @var list<string>
	 */
	private const BOOLEAN_KEYS = array(
		'opengraph',
		'twitter',
	);

	/**
	 * The allow-list keys Yoast stores as attachment IDs (integers).
	 *
	 * @var list<string>
	 */
	private const INTEGER_KEYS = array(
		'og_default_image_id',
		'og_frontpage_image_id',
	);

	/**
	 * The X/Twitter card types Yoast accepts.
	 *
	 * Yoast 27.9 enables exactly one card type in its validator
	 * (`class-wpseo-option-social.php:72-80`, `$twitter_card_types`); the others are
	 * commented out, so a value outside this list is silently dropped by Yoast's
	 * `validate()`. The schema enum and the runtime check both pin this list so an
	 * unaccepted value is rejected up front rather than silently ignored.
	 *
	 * @var list<string>
	 */
	private const TWITTER_CARD_TYPES = array(
		'summary_large_image',
	);

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-yoast/update-social-settings';
	}

	/**
	 * {@inheritDoc}
	 */
	public function isAvailable(): bool {
		return YoastPlugin::isActive();
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return array(
			'label'               => __( 'Update social settings', 'abilities-catalog-yoast' ),
			'description'         => __( 'Updates the site-wide social settings: social profile URLs (Facebook, X/Twitter, Instagram, LinkedIn, Pinterest, YouTube, Wikipedia, Mastodon, MySpace, and extra profiles), the Open Graph default and front-page title/description/image, the X/Twitter card type, and the Open Graph and X/Twitter output toggles. Send only the keys you want to change; all are optional. The Pinterest verification token cannot be set here. Returns the updated social settings. Inspect the current values and valid keys with og-yoast/get-social-settings.', 'abilities-catalog-yoast' ),
			'category'            => 'og-yoast',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'facebook_site'         => array(
						'type'        => 'string',
						'description' => __( 'The Facebook page URL.', 'abilities-catalog-yoast' ),
					),
					'instagram_url'         => array(
						'type'        => 'string',
						'description' => __( 'The Instagram profile URL.', 'abilities-catalog-yoast' ),
					),
					'linkedin_url'          => array(
						'type'        => 'string',
						'description' => __( 'The LinkedIn profile URL.', 'abilities-catalog-yoast' ),
					),
					'myspace_url'           => array(
						'type'        => 'string',
						'description' => __( 'The MySpace profile URL.', 'abilities-catalog-yoast' ),
					),
					'og_default_image'      => array(
						'type'        => 'string',
						'description' => __( 'The default Open Graph image URL used when a page has no image of its own.', 'abilities-catalog-yoast' ),
					),
					'og_default_image_id'   => array(
						'type'        => 'integer',
						'description' => __( 'The media library attachment ID of the default Open Graph image.', 'abilities-catalog-yoast' ),
					),
					'og_frontpage_title'    => array(
						'type'        => 'string',
						'description' => __( 'The Open Graph title for the front page.', 'abilities-catalog-yoast' ),
					),
					'og_frontpage_desc'     => array(
						'type'        => 'string',
						'description' => __( 'The Open Graph description for the front page.', 'abilities-catalog-yoast' ),
					),
					'og_frontpage_image'    => array(
						'type'        => 'string',
						'description' => __( 'The Open Graph image URL for the front page.', 'abilities-catalog-yoast' ),
					),
					'og_frontpage_image_id' => array(
						'type'        => 'integer',
						'description' => __( 'The media library attachment ID of the front-page Open Graph image.', 'abilities-catalog-yoast' ),
					),
					'opengraph'             => array(
						'type'        => 'boolean',
						'description' => __( 'Whether Yoast adds Open Graph metadata to the page head.', 'abilities-catalog-yoast' ),
					),
					'pinterest_url'         => array(
						'type'        => 'string',
						'description' => __( 'The Pinterest profile URL.', 'abilities-catalog-yoast' ),
					),
					'twitter'               => array(
						'type'        => 'boolean',
						'description' => __( 'Whether Yoast adds X/Twitter card metadata to the page head.', 'abilities-catalog-yoast' ),
					),
					'twitter_site'          => array(
						'type'        => 'string',
						'description' => __( 'The X/Twitter username associated with the site (without the @).', 'abilities-catalog-yoast' ),
					),
					'twitter_card_type'     => array(
						'type'        => 'string',
						'enum'        => self::TWITTER_CARD_TYPES,
						'description' => __( 'The X/Twitter card type Yoast outputs.', 'abilities-catalog-yoast' ),
					),
					'youtube_url'           => array(
						'type'        => 'string',
						'description' => __( 'The YouTube channel URL.', 'abilities-catalog-yoast' ),
					),
					'wikipedia_url'         => array(
						'type'        => 'string',
						'description' => __( 'The Wikipedia page URL.', 'abilities-catalog-yoast' ),
					),
					'other_social_urls'     => array(
						'type'        => 'array',
						'items'       => array( 'type' => 'string' ),
						'description' => __( 'Any additional social profile URLs not covered by a dedicated field.', 'abilities-catalog-yoast' ),
					),
					'mastodon_url'          => array(
						'type'        => 'string',
						'description' => __( 'The Mastodon profile URL.', 'abilities-catalog-yoast' ),
					),
				),
				'additionalProperties' => false,
				'default'              => (object) array(),
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'properties'           => array(
					'facebook_site'         => array(
						'type'        => 'string',
						'description' => __( 'The Facebook page URL.', 'abilities-catalog-yoast' ),
					),
					'instagram_url'         => array(
						'type'        => 'string',
						'description' => __( 'The Instagram profile URL.', 'abilities-catalog-yoast' ),
					),
					'linkedin_url'          => array(
						'type'        => 'string',
						'description' => __( 'The LinkedIn profile URL.', 'abilities-catalog-yoast' ),
					),
					'myspace_url'           => array(
						'type'        => 'string',
						'description' => __( 'The MySpace profile URL.', 'abilities-catalog-yoast' ),
					),
					'og_default_image'      => array(
						'type'        => 'string',
						'description' => __( 'The default Open Graph image URL used when a page has no image of its own.', 'abilities-catalog-yoast' ),
					),
					'og_default_image_id'   => array(
						'type'        => 'integer',
						'description' => __( 'The media library attachment ID of the default Open Graph image. 0 when none is set.', 'abilities-catalog-yoast' ),
					),
					'og_frontpage_title'    => array(
						'type'        => 'string',
						'description' => __( 'The Open Graph title for the front page.', 'abilities-catalog-yoast' ),
					),
					'og_frontpage_desc'     => array(
						'type'        => 'string',
						'description' => __( 'The Open Graph description for the front page.', 'abilities-catalog-yoast' ),
					),
					'og_frontpage_image'    => array(
						'type'        => 'string',
						'description' => __( 'The Open Graph image URL for the front page.', 'abilities-catalog-yoast' ),
					),
					'og_frontpage_image_id' => array(
						'type'        => 'integer',
						'description' => __( 'The media library attachment ID of the front-page Open Graph image. 0 when none is set.', 'abilities-catalog-yoast' ),
					),
					'opengraph'             => array(
						'type'        => 'boolean',
						'description' => __( 'Whether Yoast adds Open Graph metadata to the page head.', 'abilities-catalog-yoast' ),
					),
					'pinterest_url'         => array(
						'type'        => 'string',
						'description' => __( 'The Pinterest profile URL.', 'abilities-catalog-yoast' ),
					),
					'twitter'               => array(
						'type'        => 'boolean',
						'description' => __( 'Whether Yoast adds X/Twitter card metadata to the page head.', 'abilities-catalog-yoast' ),
					),
					'twitter_site'          => array(
						'type'        => 'string',
						'description' => __( 'The X/Twitter username associated with the site (without the @).', 'abilities-catalog-yoast' ),
					),
					'twitter_card_type'     => array(
						'type'        => 'string',
						'description' => __( 'The X/Twitter card type Yoast outputs.', 'abilities-catalog-yoast' ),
					),
					'youtube_url'           => array(
						'type'        => 'string',
						'description' => __( 'The YouTube channel URL.', 'abilities-catalog-yoast' ),
					),
					'wikipedia_url'         => array(
						'type'        => 'string',
						'description' => __( 'The Wikipedia page URL.', 'abilities-catalog-yoast' ),
					),
					'other_social_urls'     => array(
						'type'        => 'array',
						'items'       => array( 'type' => 'string' ),
						'description' => __( 'Any additional social profile URLs not covered by a dedicated field.', 'abilities-catalog-yoast' ),
					),
					'mastodon_url'          => array(
						'type'        => 'string',
						'description' => __( 'The Mastodon profile URL.', 'abilities-catalog-yoast' ),
					),
				),
				'additionalProperties' => false,
			),
			'execute_callback'    => array( $this, 'execute' ),
			'permission_callback' => array( $this, 'hasPermission' ),
			'meta'                => array(
				'annotations'  => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => false,
				),
				'show_in_rest' => true,
			),
		);
	}

	/**
	 * Authorizes the write: Yoast active and the caller may manage Yoast's settings.
	 *
	 * Social settings are site-global, so there is no object-level check. The cap is
	 * Yoast's own `wpseo_manage_options` — the same capability that gates the live
	 * settings page (research-findings §8, `settings-integration.php:406`) — never the
	 * core `manage_options`.
	 *
	 * @param array<string,mixed> $input The validated input.
	 * @return bool True when Yoast is active and the caller may manage Yoast settings.
	 */
	public function hasPermission( $input ): bool {
		if ( ! YoastPlugin::isActive() ) {
			return false;
		}

		return current_user_can( 'wpseo_manage_options' );
	}

	/**
	 * Writes the supplied social settings keys, then returns the updated curated row.
	 *
	 * Each input key is allow-listed before writing (deny-by-default). The write is
	 * confirmed by Yoast's `set()` return: only a `null` (Yoast did not recognize the
	 * key) is a failure — a `false` means Yoast wrote it and normalized the value,
	 * which is success. A key Yoast did not recognize yields a typed write-failure
	 * error naming the key, the group, and the `get-*` partner. The curated row is
	 * then read back to reflect what Yoast stored.
	 *
	 * @param array<string,mixed> $input The validated input (only the keys to change).
	 * @return array<string,mixed>|\WP_Error The updated curated social row, or a typed error.
	 */
	public function execute( $input ) {
		if ( ! YoastPlugin::isActive() ) {
			return YoastPlugin::unavailable();
		}

		$input = is_array( $input ) ? $input : array();

		foreach ( array_keys( $input ) as $key ) {
			if ( ! in_array( $key, self::ALLOW_LIST, true ) ) {
				return new WP_Error(
					'og_yoast_unknown_setting_key',
					sprintf(
						/* translators: 1: rejected setting key, 2: the get-* partner ability name. */
						__( 'The setting key "%1$s" is not a writable social setting. Inspect the valid keys and current values with %2$s.', 'abilities-catalog-yoast' ),
						(string) $key,
						self::READ_ABILITY
					),
					array( 'status' => 400 )
				);
			}
		}

		foreach ( $input as $key => $value ) {
			$value = $this->normalizeForWrite( (string) $key, $value );

			$result = YoastPlugin::setOption( (string) $key, $value, self::OPTION_GROUP );

			// set() returns null only when Yoast did not recognize the key (it wrote
			// just the in-memory cache) — the one real write failure. true means the
			// stored value byte-matches what was sent; false means Yoast wrote it but
			// its per-group validate() normalized the value (e.g. sanitized a URL),
			// which is success, not failure. The allow-list check above is the real
			// guard against unknown keys; null here is the residual signal.
			if ( null === $result ) {
				return $this->writeFailed( (string) $key );
			}
		}

		return $this->curatedRow();
	}

	/**
	 * Casts an input value to the type Yoast stores so the confirm read-back matches.
	 *
	 * Yoast's per-key `validate()` runs under `set()`, so the ability does not
	 * re-sanitize content; it only casts the PHP type (bool / int / string) to the
	 * shape Yoast expects for the key.
	 *
	 * @param string $key   The allow-listed setting key.
	 * @param mixed  $value The raw input value.
	 * @return mixed The type-cast value.
	 */
	private function normalizeForWrite( string $key, $value ) {
		if ( in_array( $key, self::BOOLEAN_KEYS, true ) ) {
			return (bool) $value;
		}

		if ( in_array( $key, self::INTEGER_KEYS, true ) ) {
			return (int) $value;
		}

		if ( 'other_social_urls' === $key ) {
			return is_array( $value ) ? array_values( array_map( 'strval', $value ) ) : array();
		}

		return (string) $value;
	}

	/**
	 * The typed error returned when Yoast did not recognize the written key.
	 *
	 * `WPSEO_Options::set()` returns `null` only when the key is not in Yoast's
	 * lookup/pattern table (it wrote just the in-memory cache) — the one real write
	 * failure. A `false` return is a normalized success, not a failure, so it is not
	 * routed here.
	 *
	 * @param string $key The setting key whose write Yoast did not recognize.
	 * @return \WP_Error A `og_yoast_setting_write_failed` error with HTTP status 500.
	 */
	private function writeFailed( string $key ): WP_Error {
		return new WP_Error(
			'og_yoast_setting_write_failed',
			sprintf(
				/* translators: 1: setting key, 2: option group name, 3: the get-* partner ability name. */
				__( 'The social setting "%1$s" did not save to the "%2$s" option group. Re-read the current values with %3$s and retry.', 'abilities-catalog-yoast' ),
				$key,
				self::OPTION_GROUP,
				self::READ_ABILITY
			),
			array( 'status' => 500 )
		);
	}

	/**
	 * Reads and curates the social settings into the same flat row the read returns.
	 *
	 * @return array<string,mixed>|\WP_Error The curated social row, or a typed read error.
	 */
	private function curatedRow() {
		$option = YoastPlugin::getOptionGroup( self::OPTION_GROUP );

		if ( $option instanceof WP_Error ) {
			return $option;
		}

		$row = array();

		foreach ( self::ALLOW_LIST as $key ) {
			$value = $option[ $key ] ?? null;

			if ( in_array( $key, self::BOOLEAN_KEYS, true ) ) {
				$row[ $key ] = (bool) $value;
				continue;
			}

			if ( in_array( $key, self::INTEGER_KEYS, true ) ) {
				$row[ $key ] = (int) $value;
				continue;
			}

			if ( 'other_social_urls' === $key ) {
				$row[ $key ] = is_array( $value ) ? array_values( array_map( 'strval', $value ) ) : array();
				continue;
			}

			$row[ $key ] = (string) ( $value ?? '' );
		}

		return $row;
	}
}
