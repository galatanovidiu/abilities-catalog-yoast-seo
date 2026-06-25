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
 * Updates Yoast SEO's site-indexing settings — the highest-blast-radius write in the add-on.
 *
 * Writes one or more keys of Yoast's `wpseo_titles` option group that control which
 * URLs Yoast removes from search engines (de-indexes) or disables outright: the
 * author/date/post-format/attachment archive toggles, the author and special-archive
 * noindex flags, and — by post type, post-type archive, and taxonomy — the per-object
 * noindex flags. A single `noindex-<type>` write de-indexes every URL of that post
 * type or taxonomy in one call, so this is a dangerous-tier write that returns the
 * old-to-new value of every changed key for transparency.
 *
 * The per-object flags are sent as three maps (`post_type_noindex`,
 * `post_type_archive_noindex`, `taxonomy_noindex`), each keyed by a registered public
 * post type or taxonomy. The keys are validated against the site's registered public
 * objects; an unknown post type or taxonomy is recorded in `rejected[]` rather than
 * written, so the agent sees exactly what was skipped instead of it silently failing.
 *
 * Each write is a deny-by-default allow-list: only the `site_indexing` keys
 * (research-findings §6.2) are accepted. `WPSEO_Options::set()` gives no reliable
 * success signal — only a `null` return (Yoast did not recognize the key) is a real
 * failure — so the ability treats anything other than `null` as confirmed and reads
 * the group first to build the old-to-new block.
 *
 * All Yoast access goes through
 * {@see \GalatanOvidiu\AbilitiesCatalogYoast\Support\YoastPlugin}, so the ability never
 * names a `WPSEO_*` symbol itself. It is a {@see ConditionalAbility} gated on Yoast SEO
 * being active, so it does not register when Yoast is off.
 *
 * @since 0.8.0
 */
final class UpdateIndexingSettings implements ConditionalAbility {

	/**
	 * The Yoast option group this write targets.
	 *
	 * @var string
	 */
	private const OPTION_GROUP = 'wpseo_titles';

	/**
	 * The `get-*` partner ability a caller inspects valid keys / current values with.
	 *
	 * @var string
	 */
	private const READ_ABILITY = 'og-yoast/get-indexing-settings';

	/**
	 * The static `site_indexing` allow-list keys (all stored as booleans).
	 *
	 * The exact key list from research-findings §6.2
	 * (`class-wpseo-option-titles.php:56-63`). A key outside this list (and the
	 * prefix-matched per-object keys validated in {@see execute()}) is never written.
	 *
	 * @var list<string>
	 */
	private const STATIC_KEYS = array(
		'noindex-author-wpseo',
		'noindex-author-noposts-wpseo',
		'noindex-archive-wpseo',
		'disable-author',
		'disable-date',
		'disable-post_format',
		'disable-attachment',
	);

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-yoast/update-indexing-settings';
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
			'label'               => __( 'Update indexing settings', 'abilities-catalog-yoast' ),
			'description'         => __( 'Updates the site-indexing settings: which post types, post-type archives, and taxonomies are hidden from search engines (de-indexed), plus the author, date, post-format, and attachment archive toggles. HIGH BLAST RADIUS: a single noindex flag de-indexes every URL of the targeted post type or taxonomy at once and the change is silent until search engines re-crawl. Send only the keys you want to change; all are optional. Unknown post types or taxonomies are reported back as rejected, not written. Returns the old-to-new value of each changed key. Inspect the current values with og-yoast/get-indexing-settings.', 'abilities-catalog-yoast' ),
			'category'            => 'og-yoast',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'noindex-author-wpseo'         => array(
						'type'        => 'boolean',
						'description' => __( 'When true, hide author archives from search engines.', 'abilities-catalog-yoast' ),
					),
					'noindex-author-noposts-wpseo' => array(
						'type'        => 'boolean',
						'description' => __( 'When true, hide archives for authors with no posts from search engines.', 'abilities-catalog-yoast' ),
					),
					'noindex-archive-wpseo'        => array(
						'type'        => 'boolean',
						'description' => __( 'When true, hide date-based archives from search engines.', 'abilities-catalog-yoast' ),
					),
					'disable-author'               => array(
						'type'        => 'boolean',
						'description' => __( 'When true, disable author archive pages entirely.', 'abilities-catalog-yoast' ),
					),
					'disable-date'                 => array(
						'type'        => 'boolean',
						'description' => __( 'When true, disable date-based archive pages entirely.', 'abilities-catalog-yoast' ),
					),
					'disable-post_format'          => array(
						'type'        => 'boolean',
						'description' => __( 'When true, disable post-format archive pages entirely.', 'abilities-catalog-yoast' ),
					),
					'disable-attachment'           => array(
						'type'        => 'boolean',
						'description' => __( 'When true, redirect attachment (media) pages to the file, disabling them as pages.', 'abilities-catalog-yoast' ),
					),
					'post_type_noindex'            => array(
						'type'                 => 'object',
						'description'          => __( 'A map of post type name to a boolean: true hides every URL of that post type from search engines. Each key must be a registered public post type; an unknown one is rejected. Written as noindex-<post_type>.', 'abilities-catalog-yoast' ),
						'additionalProperties' => array( 'type' => 'boolean' ),
					),
					'post_type_archive_noindex'    => array(
						'type'                 => 'object',
						'description'          => __( 'A map of post type name to a boolean: true hides that post type\'s archive page from search engines. Each key must be a registered public post type; an unknown one is rejected. Written as noindex-ptarchive-<post_type>.', 'abilities-catalog-yoast' ),
						'additionalProperties' => array( 'type' => 'boolean' ),
					),
					'taxonomy_noindex'             => array(
						'type'                 => 'object',
						'description'          => __( 'A map of taxonomy name to a boolean: true hides every term archive of that taxonomy from search engines. Each key must be a registered public taxonomy; an unknown one is rejected. Written as noindex-tax-<taxonomy>.', 'abilities-catalog-yoast' ),
						'additionalProperties' => array( 'type' => 'boolean' ),
					),
				),
				'additionalProperties' => false,
				'default'              => (object) array(),
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'properties'           => array(
					'changed'   => array(
						'type'                 => 'object',
						'description'          => __( 'Map of each written Yoast option key (e.g. "noindex-post", "noindex-ptarchive-post", "noindex-tax-category") to its old-to-new value, for every key that actually changed.', 'abilities-catalog-yoast' ),
						'additionalProperties' => array(
							'type'       => 'object',
							'properties' => array(
								'from' => array(
									'type'        => 'boolean',
									'description' => __( 'The value before the write.', 'abilities-catalog-yoast' ),
								),
								'to'   => array(
									'type'        => 'boolean',
									'description' => __( 'The value after the write.', 'abilities-catalog-yoast' ),
								),
							),
						),
					),
					'unchanged' => array(
						'type'        => 'array',
						'items'       => array( 'type' => 'string' ),
						'description' => __( 'The written Yoast option keys whose submitted value already matched the stored value (a no-op).', 'abilities-catalog-yoast' ),
					),
					'rejected'  => array(
						'type'        => 'array',
						'items'       => array( 'type' => 'string' ),
						'description' => __( 'Submitted post types or taxonomies that are not registered public objects, so were skipped (not written), reported as "post_type_noindex.<key>", "post_type_archive_noindex.<key>", or "taxonomy_noindex.<key>".', 'abilities-catalog-yoast' ),
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
	 * Site-indexing settings are site-global, so there is no object-level check. The cap
	 * is Yoast's own `wpseo_manage_options` — the capability that gates the live settings
	 * page (research-findings §8, `settings-integration.php:406`) — never the core
	 * `manage_options`.
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
	 * Writes the supplied indexing keys and returns the old-to-new transparency block.
	 *
	 * The flow is: resolve the static booleans and the three per-object maps into a flat
	 * `<written-key> => bool` set (rejecting unknown post types / taxonomies into
	 * `rejected[]`); read the current `wpseo_titles` group ONCE to capture old values;
	 * write each key whose value actually changes; and return `changed`/`unchanged`/`rejected`.
	 * The read serves only to build the old-to-new block, not as a byte-equality confirm of
	 * the sent value.
	 *
	 * @param array<string,mixed> $input The validated input (only the keys to change).
	 * @return array<string,mixed>|\WP_Error The transparency block, or a typed error.
	 */
	public function execute( $input ) {
		if ( ! YoastPlugin::isActive() ) {
			return YoastPlugin::unavailable();
		}

		$input = is_array( $input ) ? $input : array();

		$rejected = array();
		$writes   = $this->resolveWrites( $input, $rejected );

		// Reject any unrecognized top-level key (defense-in-depth behind the closed schema).
		foreach ( array_keys( $input ) as $key ) {
			if ( ! $this->isKnownInputKey( (string) $key ) ) {
				return new WP_Error(
					'og_yoast_unknown_setting_key',
					sprintf(
						/* translators: 1: rejected setting key, 2: the get-* partner ability name. */
						__( 'The setting key "%1$s" is not a writable indexing setting. Inspect the valid keys and current values with %2$s.', 'abilities-catalog-yoast' ),
						(string) $key,
						self::READ_ABILITY
					),
					array( 'status' => 400 )
				);
			}
		}

		if ( array() === $writes ) {
			return new WP_Error(
				'og_yoast_no_writable_keys',
				sprintf(
					/* translators: %s: the get-* partner ability name. */
					__( 'No valid site-indexing key was given. Submit a static toggle, or a post type / taxonomy that is a registered public object. Inspect the current state with %s.', 'abilities-catalog-yoast' ),
					self::READ_ABILITY
				),
				array( 'status' => 400 )
			);
		}

		$option = YoastPlugin::getOptionGroup( self::OPTION_GROUP );
		if ( $option instanceof WP_Error ) {
			return $option;
		}

		$changed   = array();
		$unchanged = array();

		foreach ( $writes as $written_key => $new_value ) {
			$old_value = (bool) ( $option[ $written_key ] ?? false );

			if ( $old_value === $new_value ) {
				$unchanged[] = $written_key;
				continue;
			}

			$result = YoastPlugin::setOption( $written_key, $new_value, self::OPTION_GROUP );

			// set() returns null only when Yoast did not recognize the key (it wrote just
			// the in-memory cache) — the one real write failure. true means the stored
			// value byte-matches what was sent; false means Yoast wrote it but its
			// per-group validate() normalized the value, which is success. The allow-list
			// resolution above is the real unknown-key guard; null here is the residual signal.
			if ( null === $result ) {
				return $this->writeFailed( $written_key );
			}

			$changed[ $written_key ] = array(
				'from' => $old_value,
				'to'   => $new_value,
			);
		}

		return array(
			'changed'   => (object) $changed,
			'unchanged' => $unchanged,
			'rejected'  => $rejected,
		);
	}

	/**
	 * Resolves the input into a flat `<written-key> => bool` set, collecting rejects.
	 *
	 * Static booleans map to their own key. The three per-object maps are validated:
	 * each key must be a registered public post type (for the two post-type maps) or a
	 * registered public taxonomy (for the taxonomy map), else it is pushed to
	 * `$rejected` (namespaced by the input field) and skipped.
	 *
	 * @param array<string,mixed> $input    The validated input.
	 * @param list<string>        $rejected The reject list to populate, by reference.
	 * @return array<string,bool> The flat written-key to boolean-value set.
	 */
	private function resolveWrites( array $input, array &$rejected ): array {
		$writes = array();

		foreach ( self::STATIC_KEYS as $key ) {
			if ( ! array_key_exists( $key, $input ) ) {
				continue;
			}

			$writes[ $key ] = (bool) $input[ $key ];
		}

		$public_post_types = get_post_types( array( 'public' => true ), 'names' );
		$public_taxonomies = get_taxonomies( array( 'public' => true ), 'names' );

		$this->resolveMap(
			$input['post_type_noindex'] ?? null,
			'post_type_noindex',
			$public_post_types,
			'noindex-',
			$writes,
			$rejected
		);

		$this->resolveMap(
			$input['post_type_archive_noindex'] ?? null,
			'post_type_archive_noindex',
			$public_post_types,
			'noindex-ptarchive-',
			$writes,
			$rejected
		);

		$this->resolveMap(
			$input['taxonomy_noindex'] ?? null,
			'taxonomy_noindex',
			$public_taxonomies,
			'noindex-tax-',
			$writes,
			$rejected
		);

		return $writes;
	}

	/**
	 * Resolves one per-object input map into flat writes, rejecting unknown objects.
	 *
	 * @param mixed               $map        The raw input map (object name => bool), or null.
	 * @param string              $field      The input field name (for reject namespacing).
	 * @param array<string>       $valid      The registered public object names the keys must be in.
	 * @param string              $key_prefix The Yoast option-key prefix the name is written under.
	 * @param array<string,bool>  $writes     The flat write set to populate, by reference.
	 * @param list<string>        $rejected   The reject list to populate, by reference.
	 * @return void
	 */
	private function resolveMap( $map, string $field, array $valid, string $key_prefix, array &$writes, array &$rejected ): void {
		if ( ! is_array( $map ) ) {
			return;
		}

		foreach ( $map as $object_name => $value ) {
			$object_name = (string) $object_name;

			if ( ! in_array( $object_name, $valid, true ) ) {
				$rejected[] = $field . '.' . $object_name;
				continue;
			}

			$writes[ $key_prefix . $object_name ] = (bool) $value;
		}
	}

	/**
	 * Whether a top-level input key is a recognized indexing-write input field.
	 *
	 * @param string $key The submitted top-level key.
	 * @return bool True when the key is a static toggle or one of the three object maps.
	 */
	private function isKnownInputKey( string $key ): bool {
		if ( in_array( $key, self::STATIC_KEYS, true ) ) {
			return true;
		}

		return in_array(
			$key,
			array( 'post_type_noindex', 'post_type_archive_noindex', 'taxonomy_noindex' ),
			true
		);
	}

	/**
	 * The typed error returned when Yoast did not recognize an allow-listed written key.
	 *
	 * `WPSEO_Options::set()` returns `null` only when the key is not in Yoast's
	 * lookup/pattern table (it wrote just the in-memory cache) — the one real write
	 * failure. A `false` return is a normalized success and is not routed here.
	 *
	 * @param string $key The written Yoast option key whose write Yoast did not recognize.
	 * @return \WP_Error A `og_yoast_indexing_write_failed` error with HTTP status 500.
	 */
	private function writeFailed( string $key ): WP_Error {
		return new WP_Error(
			'og_yoast_indexing_write_failed',
			sprintf(
				/* translators: 1: setting key, 2: option group name, 3: the get-* partner ability name. */
				__( 'The indexing setting "%1$s" did not save to the "%2$s" option group. Re-read the current values with %3$s and retry.', 'abilities-catalog-yoast' ),
				$key,
				self::OPTION_GROUP,
				self::READ_ABILITY
			),
			array( 'status' => 500 )
		);
	}
}
