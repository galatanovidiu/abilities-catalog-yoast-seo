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
 * Reads Yoast's general feature settings.
 *
 * Returns a flat, closed row curated to the `general_features` allow-list of the
 * `wpseo` option group (research-findings §6.2, `class-wpseo-option-wpseo.php:43-160`):
 * the feature toggles (XML sitemap, IndexNow, llms.txt, cornerstone, schema, link
 * counter, admin-bar menu), the three analysis toggles, the crawl-cleanup deny flags,
 * the advanced-meta restriction flag, and — for each of the five site-verification
 * codes — only whether a value is set, never the code itself.
 *
 * The read is an ALLOW-LIST, not a deny-list: a key outside the curated set is never
 * surfaced, so a future Yoast token key stays excluded by default. The verification
 * secrets that DO sit in `wpseo` (`myyoast-oauth`, `semrush_tokens`, `wincher_tokens`,
 * `index_now_key`) are absent from the allow-list, so the allow-list already excludes
 * them — they are never special-cased back in (research-findings §6.3). The five
 * `*verify` codes are masked: the ability emits a `<key>_has_value` boolean instead of
 * the raw code, so a verification token never leaves the ability.
 *
 * All Yoast access goes through {@see YoastPlugin::getOptionGroup()} so the ability
 * never names a `WPSEO_*` symbol itself. It is a {@see ConditionalAbility} gated on
 * Yoast SEO being active, so it does not register when Yoast is off.
 *
 * @since 0.7.0
 */
final class GetGeneralSettings implements ConditionalAbility {

	/**
	 * The Yoast option group these settings live in.
	 *
	 * @var string
	 */
	private const OPTION_GROUP = 'wpseo';

	/**
	 * Allow-listed boolean feature/toggle/deny keys, surfaced under their own names.
	 *
	 * @var list<string>
	 */
	private const BOOLEAN_KEYS = array(
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
	);

	/**
	 * Allow-listed site-verification code keys, surfaced ONLY as masked `_has_value` booleans.
	 *
	 * @var list<string>
	 */
	private const VERIFY_KEYS = array(
		'baiduverify',
		'googleverify',
		'msverify',
		'yandexverify',
		'ahrefsverify',
	);

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-yoast/get-general-settings';
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
			'label'               => __( 'Get general SEO settings', 'abilities-catalog-yoast' ),
			'description'         => __( 'Reads Yoast SEO general feature settings: the feature toggles (XML sitemap, IndexNow, llms.txt, cornerstone content, schema output, text-link counter, admin-bar menu), the content / keyword / inclusive-language analysis toggles, the crawl-cleanup deny flags, the advanced-meta restriction flag, and — for each of the five site-verification services (Baidu, Google, Bing, Yandex, Ahrefs) — only whether a verification code is set, never the code itself. The verification codes and the API tokens stored alongside them are never returned.', 'abilities-catalog-yoast' ),
			'category'            => 'og-yoast',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => (object) array(),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'properties'           => array(
					'enable_xml_sitemap'                 => array(
						'type'        => 'boolean',
						'description' => __( 'Whether the Yoast XML sitemaps feature is enabled.', 'abilities-catalog-yoast' ),
					),
					'enable_index_now'                   => array(
						'type'        => 'boolean',
						'description' => __( 'Whether the IndexNow feature is enabled.', 'abilities-catalog-yoast' ),
					),
					'enable_llms_txt'                    => array(
						'type'        => 'boolean',
						'description' => __( 'Whether the llms.txt feature is enabled.', 'abilities-catalog-yoast' ),
					),
					'enable_cornerstone_content'         => array(
						'type'        => 'boolean',
						'description' => __( 'Whether the cornerstone content feature is enabled.', 'abilities-catalog-yoast' ),
					),
					'enable_schema'                      => array(
						'type'        => 'boolean',
						'description' => __( 'Whether schema (structured data) output is enabled.', 'abilities-catalog-yoast' ),
					),
					'enable_text_link_counter'           => array(
						'type'        => 'boolean',
						'description' => __( 'Whether the text-link counter feature is enabled.', 'abilities-catalog-yoast' ),
					),
					'enable_admin_bar_menu'              => array(
						'type'        => 'boolean',
						'description' => __( 'Whether the Yoast admin-bar menu is enabled.', 'abilities-catalog-yoast' ),
					),
					'content_analysis_active'            => array(
						'type'        => 'boolean',
						'description' => __( 'Whether the readability (content) analysis is active.', 'abilities-catalog-yoast' ),
					),
					'keyword_analysis_active'            => array(
						'type'        => 'boolean',
						'description' => __( 'Whether the SEO (keyword) analysis is active.', 'abilities-catalog-yoast' ),
					),
					'inclusive_language_analysis_active' => array(
						'type'        => 'boolean',
						'description' => __( 'Whether the inclusive-language analysis is active.', 'abilities-catalog-yoast' ),
					),
					'deny_search_crawling'               => array(
						'type'        => 'boolean',
						'description' => __( 'Whether the search-results crawl-cleanup deny flag is set.', 'abilities-catalog-yoast' ),
					),
					'deny_wp_json_crawling'              => array(
						'type'        => 'boolean',
						'description' => __( 'Whether the wp-json REST API crawl-cleanup deny flag is set.', 'abilities-catalog-yoast' ),
					),
					'deny_adsbot_crawling'               => array(
						'type'        => 'boolean',
						'description' => __( 'Whether the Google AdsBot crawl-cleanup deny flag is set.', 'abilities-catalog-yoast' ),
					),
					'deny_ccbot_crawling'                => array(
						'type'        => 'boolean',
						'description' => __( 'Whether the Common Crawl (CCBot) crawl-cleanup deny flag is set.', 'abilities-catalog-yoast' ),
					),
					'deny_google_extended_crawling'      => array(
						'type'        => 'boolean',
						'description' => __( 'Whether the Google-Extended crawl-cleanup deny flag is set.', 'abilities-catalog-yoast' ),
					),
					'deny_gptbot_crawling'               => array(
						'type'        => 'boolean',
						'description' => __( 'Whether the OpenAI GPTBot crawl-cleanup deny flag is set.', 'abilities-catalog-yoast' ),
					),
					'disableadvanced_meta'               => array(
						'type'        => 'boolean',
						'description' => __( 'Whether editing the advanced per-post SEO fields is restricted to advanced-metadata editors.', 'abilities-catalog-yoast' ),
					),
					'baiduverify_has_value'              => array(
						'type'        => 'boolean',
						'description' => __( 'Whether a Baidu site-verification code is set. The code itself is never returned.', 'abilities-catalog-yoast' ),
					),
					'googleverify_has_value'             => array(
						'type'        => 'boolean',
						'description' => __( 'Whether a Google site-verification code is set. The code itself is never returned.', 'abilities-catalog-yoast' ),
					),
					'msverify_has_value'                 => array(
						'type'        => 'boolean',
						'description' => __( 'Whether a Bing (Microsoft) site-verification code is set. The code itself is never returned.', 'abilities-catalog-yoast' ),
					),
					'yandexverify_has_value'             => array(
						'type'        => 'boolean',
						'description' => __( 'Whether a Yandex site-verification code is set. The code itself is never returned.', 'abilities-catalog-yoast' ),
					),
					'ahrefsverify_has_value'             => array(
						'type'        => 'boolean',
						'description' => __( 'Whether an Ahrefs site-verification code is set. The code itself is never returned.', 'abilities-catalog-yoast' ),
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
	 * Authorizes the read: Yoast active and the caller may manage Yoast settings.
	 *
	 * Settings are site-global, so there is no object-level check. The cap is Yoast's
	 * own settings-page gate `wpseo_manage_options` (research-findings §8,
	 * `settings-integration.php:406`), never the WordPress `manage_options` substitute.
	 *
	 * @param mixed $input The validated input (this ability takes none).
	 * @return bool True when Yoast is active and the caller may manage Yoast settings.
	 */
	public function hasPermission( $input ): bool {
		return YoastPlugin::isActive() && current_user_can( 'wpseo_manage_options' );
	}

	/**
	 * Reads and returns the curated general feature settings as a flat row.
	 *
	 * @param mixed $input The validated input (this ability takes none).
	 * @return array<string,bool>|\WP_Error The flat general-settings row, or a typed error.
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

		foreach ( self::BOOLEAN_KEYS as $key ) {
			$row[ $key ] = (bool) ( $option[ $key ] ?? false );
		}

		foreach ( self::VERIFY_KEYS as $key ) {
			$row[ $key . '_has_value' ] = '' !== (string) ( $option[ $key ] ?? '' );
		}

		return $row;
	}
}
