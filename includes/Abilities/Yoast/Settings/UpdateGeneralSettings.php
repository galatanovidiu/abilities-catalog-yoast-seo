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
 * Updates Yoast SEO's general feature toggles.
 *
 * Writes one or more keys of Yoast's `wpseo` option group over the
 * `general_features` allow-list: the feature toggles (XML sitemap, IndexNow,
 * llms.txt, cornerstone content, schema output, text-link counter, admin-bar
 * menu), the content / keyword / inclusive-language analysis toggles, the
 * crawl-cleanup deny flags, the advanced-meta restriction flag, and the five
 * site-verification codes. Send only the keys you want to change; every property
 * is optional.
 *
 * This is the highest-blast-radius general write in the add-on: disabling the XML
 * sitemap removes it, and the `deny_*_crawling` toggles change site-wide bot
 * behavior. So it is a dangerous-tier write that returns an old-to-new
 * transparency block ({@see execute()} returns `changed[<key>] = { from, to }` for
 * each key it actually changed) rather than a curated row, so a human can audit
 * exactly what moved.
 *
 * Each write is a deny-by-default allow-list: only the `general_features` keys are
 * accepted; an out-of-list key is rejected with a typed error, not written. The
 * secret/token keys that also live in `wpseo` (`myyoast-oauth`, `semrush_tokens`,
 * `wincher_tokens`, `index_now_key`) are intentionally absent from the allow-list,
 * so any attempt to write one is rejected (research-findings §6.3,
 * `class-wpseo-option-wpseo.php:38,66-72,74,93`).
 *
 * `WPSEO_Options::set()` gives no reliable success signal: only a `null` (Yoast did
 * not recognize the key) is a write failure — a `false` is a normalized success.
 *
 * All Yoast access goes through
 * {@see \GalatanOvidiu\AbilitiesCatalogYoast\Support\YoastPlugin}, so the ability
 * never names a `WPSEO_*` symbol itself. It is a {@see ConditionalAbility} gated on
 * Yoast SEO being active, so it does not register when Yoast is off.
 *
 * @since 0.8.0
 */
final class UpdateGeneralSettings implements ConditionalAbility {

	/**
	 * The Yoast option group this write targets.
	 *
	 * @var string
	 */
	private const OPTION_GROUP = 'wpseo';

	/**
	 * The `get-*` partner ability a caller inspects valid keys / current state with.
	 *
	 * @var string
	 */
	private const READ_ABILITY = 'og-yoast/get-general-settings';

	/**
	 * The boolean keys of the `general_features` allow-list.
	 *
	 * Deny-by-default — a key outside the combined allow-list is rejected, not
	 * written (research-findings §6.2, `class-wpseo-option-wpseo.php:43-160`).
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
	 * The site-verification code keys of the `general_features` allow-list.
	 *
	 * Stored as raw strings; `WPSEO_Options::set()` runs Yoast's own validator on
	 * write. These are NOT the secret API tokens — those (`myyoast-oauth`,
	 * `semrush_tokens`, `wincher_tokens`, `index_now_key`) are off the allow-list.
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
		return 'og-yoast/update-general-settings';
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
			'label'               => __( 'Update general SEO settings', 'abilities-catalog-yoast' ),
			'description'         => __( 'Updates Yoast SEO general feature settings: the feature toggles (XML sitemap, IndexNow, llms.txt, cornerstone content, schema output, text-link counter, admin-bar menu), the content / keyword / inclusive-language analysis toggles, the crawl-cleanup deny flags, the advanced-meta restriction flag, and the five site-verification codes (Baidu, Google, Bing, Yandex, Ahrefs). DANGEROUS, site-wide blast radius: disabling the XML sitemap removes it, and the deny_*_crawling toggles change site-wide bot behavior for the whole site. Send only the keys you want to change; all are optional. The API tokens stored alongside these settings cannot be written here. Returns the old-to-new value for each key that changed. Inspect the current values and valid keys with og-yoast/get-general-settings.', 'abilities-catalog-yoast' ),
			'category'            => 'og-yoast',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'enable_xml_sitemap'                 => array(
						'type'        => 'boolean',
						'description' => __( 'Whether the Yoast XML sitemaps feature is enabled. Disabling it removes the sitemap site-wide.', 'abilities-catalog-yoast' ),
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
						'description' => __( 'Whether to deny crawlers access to the search results. Changes site-wide bot behavior.', 'abilities-catalog-yoast' ),
					),
					'deny_wp_json_crawling'              => array(
						'type'        => 'boolean',
						'description' => __( 'Whether to deny crawlers access to the wp-json REST API. Changes site-wide bot behavior.', 'abilities-catalog-yoast' ),
					),
					'deny_adsbot_crawling'               => array(
						'type'        => 'boolean',
						'description' => __( 'Whether to deny the Google AdsBot crawler. Changes site-wide bot behavior.', 'abilities-catalog-yoast' ),
					),
					'deny_ccbot_crawling'                => array(
						'type'        => 'boolean',
						'description' => __( 'Whether to deny the Common Crawl (CCBot) crawler. Changes site-wide bot behavior.', 'abilities-catalog-yoast' ),
					),
					'deny_google_extended_crawling'      => array(
						'type'        => 'boolean',
						'description' => __( 'Whether to deny the Google-Extended crawler. Changes site-wide bot behavior.', 'abilities-catalog-yoast' ),
					),
					'deny_gptbot_crawling'               => array(
						'type'        => 'boolean',
						'description' => __( 'Whether to deny the OpenAI GPTBot crawler. Changes site-wide bot behavior.', 'abilities-catalog-yoast' ),
					),
					'disableadvanced_meta'               => array(
						'type'        => 'boolean',
						'description' => __( 'Whether editing the advanced per-post SEO fields is restricted to advanced-metadata editors.', 'abilities-catalog-yoast' ),
					),
					'baiduverify'                        => array(
						'type'        => 'string',
						'description' => __( 'The Baidu site-verification code.', 'abilities-catalog-yoast' ),
					),
					'googleverify'                       => array(
						'type'        => 'string',
						'description' => __( 'The Google site-verification code.', 'abilities-catalog-yoast' ),
					),
					'msverify'                           => array(
						'type'        => 'string',
						'description' => __( 'The Bing (Microsoft) site-verification code.', 'abilities-catalog-yoast' ),
					),
					'yandexverify'                       => array(
						'type'        => 'string',
						'description' => __( 'The Yandex site-verification code.', 'abilities-catalog-yoast' ),
					),
					'ahrefsverify'                       => array(
						'type'        => 'string',
						'description' => __( 'The Ahrefs site-verification code.', 'abilities-catalog-yoast' ),
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'properties'           => array(
					'changed'   => array(
						'type'        => 'object',
						'description' => __( 'Map of each changed key to its old-to-new value: { "<key>": { "from": <old>, "to": <new> } }. Empty when nothing changed.', 'abilities-catalog-yoast' ),
					),
					'unchanged' => array(
						'type'        => 'array',
						'items'       => array( 'type' => 'string' ),
						'description' => __( 'Keys whose submitted value already matched the stored value (no-op).', 'abilities-catalog-yoast' ),
					),
					'rejected'  => array(
						'type'        => 'array',
						'items'       => array( 'type' => 'string' ),
						'description' => __( 'Submitted keys that are not writable general_features keys, so they were not written.', 'abilities-catalog-yoast' ),
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
					'dangerous'   => true,
				),
				'show_in_rest' => true,
			),
		);
	}

	/**
	 * Authorizes the write: Yoast active and the caller may manage Yoast's settings.
	 *
	 * General settings are site-global, so there is no object-level check. The cap is
	 * Yoast's own `wpseo_manage_options` — the same capability that gates the live
	 * settings page (research-findings §8, `settings-integration.php:406`) — never the
	 * core `manage_options`.
	 *
	 * @param mixed $input The validated input.
	 * @return bool True when Yoast is active and the caller may manage Yoast settings.
	 */
	public function hasPermission( $input ): bool {
		return YoastPlugin::isActive() && current_user_can( 'wpseo_manage_options' );
	}

	/**
	 * Writes the supplied general feature keys and returns the old-to-new changes.
	 *
	 * Reads the whole `wpseo` group once before writing so each change can report its
	 * `from` value. Each key is allow-listed (deny-by-default); an out-of-list key —
	 * including a secret/token key — surfaces a typed unknown-key error and nothing is
	 * written. A key whose submitted value already matches goes to `unchanged`. The
	 * write is confirmed by Yoast's `set()` return: only a `null` (Yoast did not
	 * recognize the key) is a failure; a `false` is a normalized success.
	 *
	 * @param mixed $input The validated input (only the keys to change).
	 * @return array<string,mixed>|\WP_Error The {changed, unchanged, rejected} block, or a typed error.
	 */
	public function execute( $input ) {
		if ( ! YoastPlugin::isActive() ) {
			return YoastPlugin::unavailable();
		}

		$input = is_array( $input ) ? $input : array();

		// Deny-by-default: reject any key outside the general_features allow-list
		// (this is the load-bearing guard that keeps the wpseo secrets/tokens out).
		foreach ( array_keys( $input ) as $key ) {
			if ( ! $this->isAllowed( (string) $key ) ) {
				return $this->unknownKey( (string) $key );
			}
		}

		if ( array() === $input ) {
			return $this->noWritableKeys();
		}

		// Read the whole group once before writing so each change reports its old value.
		$before = YoastPlugin::getOptionGroup( self::OPTION_GROUP );
		if ( $before instanceof WP_Error ) {
			return $before;
		}

		$changed   = array();
		$unchanged = array();

		foreach ( $input as $key => $raw_value ) {
			$key   = (string) $key;
			$value = $this->normalizeForWrite( $key, $raw_value );
			$old   = $this->normalizeForWrite( $key, $before[ $key ] ?? null );

			if ( $old === $value ) {
				$unchanged[] = $key;
				continue;
			}

			$result = YoastPlugin::setOption( $key, $value, self::OPTION_GROUP );

			// set() returns null only when Yoast did not recognize the key (it wrote
			// just the in-memory cache) — the one real write failure. true means the
			// stored value byte-matches; false means Yoast wrote it but normalized the
			// value, which is success. The allow-list above is the real unknown-key
			// guard; null here is the residual defense-in-depth signal.
			if ( null === $result ) {
				return $this->writeFailed( $key );
			}

			$changed[ $key ] = array(
				'from' => $old,
				'to'   => $value,
			);
		}

		return array(
			// Cast so an empty changes map serializes as a JSON object, not an array.
			'changed'   => (object) $changed,
			'unchanged' => $unchanged,
			'rejected'  => array(),
		);
	}

	/**
	 * Whether a submitted key is a writable `general_features` key.
	 *
	 * @param string $key The submitted key.
	 * @return bool True when the key is on the boolean or verification allow-list.
	 */
	private function isAllowed( string $key ): bool {
		return in_array( $key, self::BOOLEAN_KEYS, true )
			|| in_array( $key, self::VERIFY_KEYS, true );
	}

	/**
	 * Casts an input or stored value to the type Yoast stores for the key.
	 *
	 * Booleans compare as booleans and verification codes as strings, so the old/new
	 * comparison and the returned `from`/`to` reflect Yoast's storage type rather than
	 * the raw PHP input type.
	 *
	 * @param string $key   The allow-listed setting key.
	 * @param mixed  $value The raw input or stored value.
	 * @return bool|string The type-cast value.
	 */
	private function normalizeForWrite( string $key, $value ) {
		if ( in_array( $key, self::BOOLEAN_KEYS, true ) ) {
			return (bool) $value;
		}

		return (string) ( $value ?? '' );
	}

	/**
	 * The typed error for a key outside the `general_features` allow-list.
	 *
	 * A closed `input_schema` (`additionalProperties:false`) blocks an off-list key at
	 * the Abilities API boundary, but a direct `execute()` call bypasses that — so this
	 * runtime allow-list is the second, load-bearing guard. It also keeps the `wpseo`
	 * secret/token keys un-writable.
	 *
	 * @param string $key The rejected key.
	 * @return \WP_Error A `og_yoast_unknown_setting_key` error with HTTP status 400.
	 */
	private function unknownKey( string $key ): WP_Error {
		return new WP_Error(
			'og_yoast_unknown_setting_key',
			sprintf(
				/* translators: 1: rejected setting key, 2: the get-* partner ability name. */
				__( 'The setting key "%1$s" is not a writable general feature setting. Inspect the valid keys and current values with %2$s.', 'abilities-catalog-yoast' ),
				$key,
				self::READ_ABILITY
			),
			array( 'status' => 400 )
		);
	}

	/**
	 * The typed error returned when the input resolves to zero writable keys.
	 *
	 * @return \WP_Error A `og_yoast_no_writable_keys` error with HTTP status 400.
	 */
	private function noWritableKeys(): WP_Error {
		return new WP_Error(
			'og_yoast_no_writable_keys',
			sprintf(
				/* translators: %s: the get-* partner ability name. */
				__( 'No writable general_features key was given. Inspect the valid keys and current values with %s, then retry.', 'abilities-catalog-yoast' ),
				self::READ_ABILITY
			),
			array( 'status' => 400 )
		);
	}

	/**
	 * The typed error returned when Yoast did not recognize the written key.
	 *
	 * `WPSEO_Options::set()` returns `null` only when the key is not in Yoast's
	 * lookup/pattern table (it wrote just the in-memory cache) — the one real write
	 * failure. A `false` return is a normalized success, so it is not routed here.
	 *
	 * @param string $key The setting key whose write Yoast did not recognize.
	 * @return \WP_Error A `og_yoast_general_write_failed` error with HTTP status 500.
	 */
	private function writeFailed( string $key ): WP_Error {
		return new WP_Error(
			'og_yoast_general_write_failed',
			sprintf(
				/* translators: 1: setting key, 2: option group name, 3: the get-* partner ability name. */
				__( 'The general setting "%1$s" did not save to the "%2$s" option group. Re-read the current values with %3$s and retry.', 'abilities-catalog-yoast' ),
				$key,
				self::OPTION_GROUP,
				self::READ_ABILITY
			),
			array( 'status' => 500 )
		);
	}
}
