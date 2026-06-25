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
 * Reads Yoast SEO's social settings.
 *
 * Returns a flat, closed row curated from Yoast's `wpseo_social` option group: the
 * social profile URLs (Facebook, X/Twitter, Instagram, LinkedIn, Pinterest, YouTube,
 * Wikipedia, Mastodon, MySpace, and any extra profiles), the Open Graph and X/Twitter
 * output toggles, the site-wide Open Graph defaults, the front-page Open Graph
 * title/description/image, and the X/Twitter card type.
 *
 * The read curates Yoast's option array down to its own allow-list (research-findings
 * §6.2, `class-wpseo-option-social.php:29-49`), so a future Yoast key that is not on
 * the list is simply not surfaced. The Pinterest verification token (`pinterestverify`)
 * is a verification secret and is deliberately NOT on the allow-list — it never leaves
 * the ability.
 *
 * All Yoast access goes through
 * {@see \GalatanOvidiu\AbilitiesCatalogYoast\Support\YoastPlugin::getOptionGroup()}, so
 * the ability never names a `WPSEO_*` symbol itself.
 *
 * This is a {@see ConditionalAbility} gated on Yoast SEO being active, so it does not
 * register when Yoast is off.
 *
 * @since 0.7.0
 */
final class GetSocialSettings implements ConditionalAbility {

	/**
	 * The Yoast option group this read curates.
	 *
	 * @var string
	 */
	private const OPTION_GROUP = 'wpseo_social';

	/**
	 * The social allow-list: the exact `wpseo_social` keys this read surfaces.
	 *
	 * Deny-by-default — a key outside this list is not returned. `pinterestverify`
	 * (a verification token) is intentionally absent (research-findings §6.2).
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
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-yoast/get-social-settings';
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
			'label'               => __( 'Get social settings', 'abilities-catalog-yoast' ),
			'description'         => __( 'Reads the site-wide social settings: social profile URLs (Facebook, X/Twitter, Instagram, LinkedIn, Pinterest, YouTube, Wikipedia, Mastodon, MySpace, and extra profiles), the Open Graph and X/Twitter output toggles, the Open Graph default and front-page title/description/image, and the X/Twitter card type. The Pinterest verification token is never returned.', 'abilities-catalog-yoast' ),
			'category'            => 'og-yoast',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => (object) array(),
				'additionalProperties' => false,
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
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
				'show_in_rest' => true,
			),
		);
	}

	/**
	 * Authorizes the read: Yoast active and the caller may manage Yoast's settings.
	 *
	 * Social settings are site-global, so there is no object-level check. The cap is
	 * Yoast's own `wpseo_manage_options` — the same capability that gates the live
	 * settings page (research-findings §8, `settings-integration.php:406`) — never the
	 * core `manage_options`.
	 *
	 * @param array<string,mixed> $input The validated input (none).
	 * @return bool True when Yoast is active and the caller may manage Yoast settings.
	 */
	public function hasPermission( $input ): bool {
		if ( ! YoastPlugin::isActive() ) {
			return false;
		}

		return current_user_can( 'wpseo_manage_options' );
	}

	/**
	 * Reads and curates Yoast's social settings into a flat closed row.
	 *
	 * @param array<string,mixed> $input The validated input (none).
	 * @return array<string,mixed>|\WP_Error The curated social settings row, or a typed read error.
	 */
	public function execute( $input ) {
		if ( ! YoastPlugin::isActive() ) {
			return YoastPlugin::unavailable();
		}

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
