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
 * Updates Yoast SEO's breadcrumb settings.
 *
 * Writes the site-wide breadcrumb configuration: whether breadcrumbs are enabled, the
 * separator, the home / prefix / search-prefix / archive-prefix labels, the 404 crumb,
 * bold-last, and show-blog-page. Every supplied key is written through Yoast's own option
 * store via {@see YoastPlugin::setOption()} against the `wpseo_titles` group — the
 * breadcrumb keys are a subset of `wpseo_titles` (research-findings §6.2,
 * `class-wpseo-option-titles.php:65-73`).
 *
 * This is a deny-by-default allow-list write. The input schema is closed
 * (`additionalProperties:false`) and lists only this concern's nine allow-listed keys, and
 * a second runtime allow-list check rejects any key outside the list before any write —
 * Yoast's `WPSEO_Options::set()` silently writes only the in-memory cache and returns
 * `null` for an unknown key, so the key must be validated first (research-findings §6.1).
 *
 * It is a low-blast-radius (T2 safe) write: labels, the separator, and display toggles —
 * no de-indexing, sitemap, or crawl changes — so it is `destructive=false` and carries no
 * old→new transparency block (only flagged and dangerous writes do).
 *
 * `WPSEO_Options::set()` returns `null` only when Yoast did not recognize the key (the one
 * real write failure); a `false` is a normalized success (Yoast trimmed/sanitized the
 * value). A key Yoast did not recognize returns a typed write-failed error. The group is
 * then re-read with {@see YoastPlugin::getOptionGroup()} to build the result — the updated
 * curated breadcrumbs row in the same shape and key order as `og-yoast/get-breadcrumbs`. It
 * is a {@see ConditionalAbility} gated on Yoast SEO being active.
 *
 * @since 0.7.0
 */
final class UpdateBreadcrumbs implements ConditionalAbility {

	/**
	 * The Yoast option group the breadcrumb keys live in.
	 *
	 * @var string
	 */
	private const OPTION_GROUP = 'wpseo_titles';

	/**
	 * The `get-*` partner a caller inspects to discover the valid keys and current values.
	 *
	 * @var string
	 */
	private const READ_PARTNER = 'og-yoast/get-breadcrumbs';

	/**
	 * Breadcrumb allow-list keys Yoast stores as booleans.
	 *
	 * Mirrors {@see \GalatanOvidiu\AbilitiesCatalogYoast\Abilities\Yoast\Settings\GetBreadcrumbs}
	 * so the read and write agree on the bool-vs-string split.
	 *
	 * @var list<string>
	 */
	private const BOOLEAN_KEYS = array(
		'breadcrumbs-enable',
		'breadcrumbs-boldlast',
		'breadcrumbs-display-blog-page',
		'breadcrumbs-404crumb',
	);

	/**
	 * Breadcrumb allow-list keys Yoast stores as strings.
	 *
	 * @var list<string>
	 */
	private const STRING_KEYS = array(
		'breadcrumbs-home',
		'breadcrumbs-prefix',
		'breadcrumbs-searchprefix',
		'breadcrumbs-archiveprefix',
		'breadcrumbs-sep',
	);

	/**
	 * The full output / curated-row key order (mirrors the get-breadcrumbs partner).
	 *
	 * @var list<string>
	 */
	private const ROW_ORDER = array(
		'breadcrumbs-enable',
		'breadcrumbs-boldlast',
		'breadcrumbs-display-blog-page',
		'breadcrumbs-404crumb',
		'breadcrumbs-home',
		'breadcrumbs-prefix',
		'breadcrumbs-searchprefix',
		'breadcrumbs-archiveprefix',
		'breadcrumbs-sep',
	);

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-yoast/update-breadcrumbs';
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
			'label'               => __( 'Update breadcrumbs settings', 'abilities-catalog-yoast' ),
			'description'         => __( 'Updates Yoast SEO breadcrumbs settings: whether breadcrumbs are enabled, the separator, the home, prefix, search-prefix and archive-prefix labels, the 404 crumb, bold-last, and show-blog-page. Send only the keys to change. Low blast radius — display labels and toggles only, no indexing or sitemap changes.', 'abilities-catalog-yoast' ),
			'category'            => 'og-yoast',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'breadcrumbs-enable'            => array(
						'type'        => 'boolean',
						'description' => __( 'Whether breadcrumbs output is enabled.', 'abilities-catalog-yoast' ),
					),
					'breadcrumbs-boldlast'          => array(
						'type'        => 'boolean',
						'description' => __( 'Whether the last breadcrumb (the current page) is bold.', 'abilities-catalog-yoast' ),
					),
					'breadcrumbs-display-blog-page' => array(
						'type'        => 'boolean',
						'description' => __( 'Whether the assigned blog page shows in the breadcrumb trail.', 'abilities-catalog-yoast' ),
					),
					'breadcrumbs-404crumb'          => array(
						'type'        => 'boolean',
						'description' => __( 'Whether a breadcrumb crumb is shown on 404 pages.', 'abilities-catalog-yoast' ),
					),
					'breadcrumbs-home'              => array(
						'type'        => 'string',
						'description' => __( 'The anchor text for the home breadcrumb.', 'abilities-catalog-yoast' ),
					),
					'breadcrumbs-prefix'            => array(
						'type'        => 'string',
						'description' => __( 'The text prefixed to the breadcrumb trail.', 'abilities-catalog-yoast' ),
					),
					'breadcrumbs-searchprefix'      => array(
						'type'        => 'string',
						'description' => __( 'The prefix shown before the search term on search result pages.', 'abilities-catalog-yoast' ),
					),
					'breadcrumbs-archiveprefix'     => array(
						'type'        => 'string',
						'description' => __( 'The prefix shown before an archive title on archive pages.', 'abilities-catalog-yoast' ),
					),
					'breadcrumbs-sep'               => array(
						'type'        => 'string',
						'description' => __( 'The separator placed between breadcrumb items.', 'abilities-catalog-yoast' ),
					),
				),
				'additionalProperties' => false,
				'default'              => (object) array(),
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'properties'           => array(
					'breadcrumbs-enable'            => array( 'type' => 'boolean' ),
					'breadcrumbs-boldlast'          => array( 'type' => 'boolean' ),
					'breadcrumbs-display-blog-page' => array( 'type' => 'boolean' ),
					'breadcrumbs-404crumb'          => array( 'type' => 'boolean' ),
					'breadcrumbs-home'              => array( 'type' => 'string' ),
					'breadcrumbs-prefix'            => array( 'type' => 'string' ),
					'breadcrumbs-searchprefix'      => array( 'type' => 'string' ),
					'breadcrumbs-archiveprefix'     => array( 'type' => 'string' ),
					'breadcrumbs-sep'               => array( 'type' => 'string' ),
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
	 * Authorizes the write: Yoast active and the caller may manage Yoast options.
	 *
	 * Breadcrumb settings are site-global, so the guard is the same cap Yoast's own
	 * settings page enforces — `wpseo_manage_options` (research-findings §8) — with no
	 * object-level check. `manage_options` is never substituted: a user who may change
	 * WordPress core options is not necessarily trusted with Yoast's settings.
	 *
	 * @param array<string,mixed> $input The validated input — only the keys to change.
	 * @return bool True when Yoast is active and the caller may manage Yoast options.
	 */
	public function hasPermission( $input ): bool {
		if ( ! YoastPlugin::isActive() ) {
			return false;
		}

		return current_user_can( 'wpseo_manage_options' );
	}

	/**
	 * Writes the supplied breadcrumb keys and returns the updated curated row.
	 *
	 * @param array<string,mixed> $input The validated input — only the keys to change.
	 * @return array<string,mixed>|\WP_Error The updated breadcrumbs row, or a typed error.
	 */
	public function execute( $input ) {
		if ( ! YoastPlugin::isActive() ) {
			return YoastPlugin::unavailable();
		}

		$input = is_array( $input ) ? $input : array();

		$allowed = array_merge( self::BOOLEAN_KEYS, self::STRING_KEYS );

		// Deny-by-default: reject any key outside this concern's allow-list before any
		// write. Yoast's set() would otherwise write only the in-memory cache and return
		// null for an unknown key (research-findings §6.1).
		foreach ( array_keys( $input ) as $key ) {
			if ( ! in_array( $key, $allowed, true ) ) {
				return new WP_Error(
					'og_yoast_unknown_setting_key',
					sprintf(
						/* translators: 1: rejected setting key, 2: the get-* partner ability name. */
						__( 'The key "%1$s" is not a breadcrumbs setting and was not written. Inspect the valid keys and current values with %2$s.', 'abilities-catalog-yoast' ),
						(string) $key,
						self::READ_PARTNER
					),
					array( 'status' => 400 )
				);
			}
		}

		foreach ( $input as $key => $value ) {
			$result = YoastPlugin::setOption( (string) $key, $this->coerce( (string) $key, $value ), self::OPTION_GROUP );

			// set() returns null only when Yoast did not recognize the key (it wrote
			// just the in-memory cache) — the one real write failure. true means the
			// stored value byte-matches what was sent; false means Yoast wrote it but
			// its per-group validate() normalized the value (e.g. trimmed the
			// separator), which is success, not failure. The allow-list check above is
			// the real guard against unknown keys; null here is the residual signal.
			if ( null === $result ) {
				return $this->writeFailed( (string) $key );
			}
		}

		$option = YoastPlugin::getOptionGroup( self::OPTION_GROUP );
		if ( is_wp_error( $option ) ) {
			return $option;
		}

		return $this->curatedRow( $option );
	}

	/**
	 * The typed error returned when a written key did not round-trip.
	 *
	 * @param string $key The setting key that did not stick.
	 * @return \WP_Error A `og_yoast_setting_write_failed` error with HTTP status 500.
	 */
	private function writeFailed( string $key ): WP_Error {
		return new WP_Error(
			'og_yoast_setting_write_failed',
			sprintf(
				/* translators: 1: setting key, 2: option group, 3: the get-* partner ability name. */
				__( 'The breadcrumbs key "%1$s" did not save to the %2$s settings. Re-read the current values with %3$s and retry.', 'abilities-catalog-yoast' ),
				$key,
				self::OPTION_GROUP,
				self::READ_PARTNER
			),
			array( 'status' => 500 )
		);
	}

	/**
	 * Coerces one input value to the PHP type its key stores.
	 *
	 * Yoast's per-group `validate()` runs the authoritative sanitize under `set()`; this
	 * only casts the JSON-decoded value to the key's broad PHP type (boolean for the toggle
	 * keys, string for the label keys) so the round-trip comparison and the stored value
	 * line up.
	 *
	 * @param string $key   The setting key.
	 * @param mixed  $value The decoded input value.
	 * @return bool|string The value cast to the key's PHP type.
	 */
	private function coerce( string $key, $value ) {
		if ( in_array( $key, self::BOOLEAN_KEYS, true ) ) {
			return (bool) $value;
		}

		return (string) $value;
	}

	/**
	 * Curates the full `wpseo_titles` array down to the breadcrumbs row, in schema order.
	 *
	 * Mirrors the `get-breadcrumbs` partner's shape exactly so a caller sees the same object
	 * whether it reads or writes: the toggle keys cast to booleans and the label keys to
	 * strings, in the documented output key order.
	 *
	 * @param array<string,mixed> $option The full `wpseo_titles` option array.
	 * @return array<string,mixed> The curated breadcrumbs row, in output-schema key order.
	 */
	private function curatedRow( array $option ): array {
		$values = array();

		foreach ( self::BOOLEAN_KEYS as $key ) {
			$values[ $key ] = ! empty( $option[ $key ] );
		}

		foreach ( self::STRING_KEYS as $key ) {
			$values[ $key ] = isset( $option[ $key ] ) ? (string) $option[ $key ] : '';
		}

		$ordered = array();
		foreach ( self::ROW_ORDER as $key ) {
			$ordered[ $key ] = $values[ $key ];
		}

		return $ordered;
	}
}
