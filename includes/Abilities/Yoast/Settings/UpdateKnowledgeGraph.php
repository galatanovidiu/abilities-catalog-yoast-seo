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
 * Updates Yoast's site-representation (knowledge graph) identity settings.
 *
 * Writes whether the site represents an organization or a person, the names, logos,
 * and organization details (description, contact email/phone, legal name, founding
 * date, employee count, and the registered-ID fields) that search engines use to build
 * the site's knowledge-graph entry. Every supplied key is written through Yoast's own
 * option store via {@see YoastPlugin::setOption()} against the `wpseo_titles` group —
 * these identity keys live in `wpseo_titles`, NOT a separate option (research-findings
 * §6.2, `class-wpseo-option-titles.php:75-116`).
 *
 * This is a deny-by-default allow-list write. The input schema is closed
 * (`additionalProperties:false`) and lists only this concern's allow-listed keys, and a
 * second runtime allow-list check rejects any key outside the list before any write —
 * Yoast's `WPSEO_Options::set()` silently writes only the in-memory cache and returns
 * `null` for an unknown key, so the key must be validated first (research-findings §6.1).
 *
 * It is a low-blast-radius (T2 safe) write: identity strings, logos, and the
 * company/person selector — no de-indexing, sitemap, or crawl toggles — so it is
 * `destructive=false` and carries no old→new transparency block (only flagged and
 * dangerous writes do).
 *
 * `WPSEO_Options::set()` returns `null` only when Yoast did not recognize the key (the
 * one real write failure); a `false` is a normalized success. A key Yoast did not
 * recognize returns a typed write-failed error. The group is then re-read with
 * {@see YoastPlugin::getOptionGroup()} to build the curated row. It is a
 * {@see ConditionalAbility} gated on Yoast SEO being active.
 *
 * @since 0.7.0
 */
final class UpdateKnowledgeGraph implements ConditionalAbility {

	/**
	 * The Yoast option group the knowledge-graph identity keys live in.
	 *
	 * @var string
	 */
	private const OPTION_GROUP = 'wpseo_titles';

	/**
	 * The `get-*` partner a caller inspects to discover the valid keys and current values.
	 *
	 * @var string
	 */
	private const READ_PARTNER = 'og-yoast/get-knowledge-graph';

	/**
	 * The allowed values for the company-or-person selector.
	 *
	 * @var list<string>
	 */
	private const COMPANY_OR_PERSON = array( 'company', 'person' );

	/**
	 * Integer identity keys (attachment IDs / the user ID). Default `0` when unset.
	 *
	 * @var list<string>
	 */
	private const INT_KEYS = array(
		'company_or_person_user_id',
		'person_logo_id',
		'company_logo_id',
	);

	/**
	 * Image-meta keys. Yoast stores attachment-metadata arrays here; surfaced as objects.
	 *
	 * @var list<string>
	 */
	private const META_KEYS = array(
		'person_logo_meta',
		'company_logo_meta',
	);

	/**
	 * String identity keys curated from `wpseo_titles`. Default `''` when unset.
	 *
	 * @var list<string>
	 */
	private const STRING_KEYS = array(
		'website_name',
		'alternate_website_name',
		'person_name',
		'person_logo',
		'company_name',
		'company_alternate_name',
		'company_logo',
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
	 * The full output / curated-row key order (mirrors the get-knowledge-graph partner).
	 *
	 * @var list<string>
	 */
	private const ROW_ORDER = array(
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
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-yoast/update-knowledge-graph';
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
			'label'               => __( 'Update site identity (knowledge graph)', 'abilities-catalog-yoast' ),
			'description'         => __( 'Updates the site-representation settings Yoast SEO publishes for search engines: whether the site represents an organization or a person, the organization name, logo, and contact details, and the person name and logo. Send only the keys to change. Low blast radius — identity strings and logos only, no indexing or sitemap changes.', 'abilities-catalog-yoast' ),
			'category'            => 'og-yoast',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'company_or_person'         => array(
						'type'        => 'string',
						'enum'        => self::COMPANY_OR_PERSON,
						'description' => __( 'Whether the site represents a company (organization) or a person.', 'abilities-catalog-yoast' ),
					),
					'company_or_person_user_id' => array(
						'type'        => 'integer',
						'description' => __( 'When the site represents a person, the WordPress user ID of that person.', 'abilities-catalog-yoast' ),
					),
					'website_name'              => array(
						'type'        => 'string',
						'description' => __( 'The website name used in the knowledge-graph entry.', 'abilities-catalog-yoast' ),
					),
					'alternate_website_name'    => array(
						'type'        => 'string',
						'description' => __( 'An alternate website name (e.g. an acronym).', 'abilities-catalog-yoast' ),
					),
					'person_name'               => array(
						'type'        => 'string',
						'description' => __( 'The person name, when the site represents a person.', 'abilities-catalog-yoast' ),
					),
					'person_logo'               => array(
						'type'        => 'string',
						'description' => __( 'The person logo / avatar image URL.', 'abilities-catalog-yoast' ),
					),
					'person_logo_id'            => array(
						'type'        => 'integer',
						'description' => __( 'The attachment ID of the person logo image.', 'abilities-catalog-yoast' ),
					),
					'person_logo_meta'          => array(
						'type'                 => 'object',
						'description'          => __( 'Yoast-internal image metadata for the person logo.', 'abilities-catalog-yoast' ),
						'additionalProperties' => true,
					),
					'company_name'              => array(
						'type'        => 'string',
						'description' => __( 'The organization name, when the site represents a company.', 'abilities-catalog-yoast' ),
					),
					'company_alternate_name'    => array(
						'type'        => 'string',
						'description' => __( 'An alternate organization name (e.g. a trading name).', 'abilities-catalog-yoast' ),
					),
					'company_logo'              => array(
						'type'        => 'string',
						'description' => __( 'The organization logo image URL.', 'abilities-catalog-yoast' ),
					),
					'company_logo_id'           => array(
						'type'        => 'integer',
						'description' => __( 'The attachment ID of the organization logo image.', 'abilities-catalog-yoast' ),
					),
					'company_logo_meta'         => array(
						'type'                 => 'object',
						'description'          => __( 'Yoast-internal image metadata for the organization logo.', 'abilities-catalog-yoast' ),
						'additionalProperties' => true,
					),
					'org-description'           => array(
						'type'        => 'string',
						'description' => __( 'The organization description.', 'abilities-catalog-yoast' ),
					),
					'org-email'                 => array(
						'type'        => 'string',
						'description' => __( 'The organization contact email.', 'abilities-catalog-yoast' ),
					),
					'org-phone'                 => array(
						'type'        => 'string',
						'description' => __( 'The organization contact phone number.', 'abilities-catalog-yoast' ),
					),
					'org-legal-name'            => array(
						'type'        => 'string',
						'description' => __( 'The registered legal name of the organization.', 'abilities-catalog-yoast' ),
					),
					'org-founding-date'         => array(
						'type'        => 'string',
						'description' => __( 'The organization founding date.', 'abilities-catalog-yoast' ),
					),
					'org-number-employees'      => array(
						'type'        => 'string',
						'description' => __( 'The organization number of employees.', 'abilities-catalog-yoast' ),
					),
					'org-vat-id'                => array(
						'type'        => 'string',
						'description' => __( 'The organization VAT identification number.', 'abilities-catalog-yoast' ),
					),
					'org-tax-id'                => array(
						'type'        => 'string',
						'description' => __( 'The organization tax identification number.', 'abilities-catalog-yoast' ),
					),
					'org-iso'                   => array(
						'type'        => 'string',
						'description' => __( 'The organization ISO 6523 identifier.', 'abilities-catalog-yoast' ),
					),
					'org-duns'                  => array(
						'type'        => 'string',
						'description' => __( 'The organization DUNS number.', 'abilities-catalog-yoast' ),
					),
					'org-leicode'               => array(
						'type'        => 'string',
						'description' => __( 'The organization Legal Entity Identifier (LEI) code.', 'abilities-catalog-yoast' ),
					),
					'org-naics'                 => array(
						'type'        => 'string',
						'description' => __( 'The organization NAICS industry code.', 'abilities-catalog-yoast' ),
					),
				),
				'additionalProperties' => false,
				'default'              => (object) array(),
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'properties'           => array(
					'company_or_person'         => array(
						'type' => 'string',
						'enum' => self::COMPANY_OR_PERSON,
					),
					'company_or_person_user_id' => array( 'type' => 'integer' ),
					'website_name'              => array( 'type' => 'string' ),
					'alternate_website_name'    => array( 'type' => 'string' ),
					'person_name'               => array( 'type' => 'string' ),
					'person_logo'               => array( 'type' => 'string' ),
					'person_logo_id'            => array( 'type' => 'integer' ),
					'person_logo_meta'          => array(
						'type'                 => 'object',
						'additionalProperties' => true,
					),
					'company_name'              => array( 'type' => 'string' ),
					'company_alternate_name'    => array( 'type' => 'string' ),
					'company_logo'              => array( 'type' => 'string' ),
					'company_logo_id'           => array( 'type' => 'integer' ),
					'company_logo_meta'         => array(
						'type'                 => 'object',
						'additionalProperties' => true,
					),
					'org-description'           => array( 'type' => 'string' ),
					'org-email'                 => array( 'type' => 'string' ),
					'org-phone'                 => array( 'type' => 'string' ),
					'org-legal-name'            => array( 'type' => 'string' ),
					'org-founding-date'         => array( 'type' => 'string' ),
					'org-number-employees'      => array( 'type' => 'string' ),
					'org-vat-id'                => array( 'type' => 'string' ),
					'org-tax-id'                => array( 'type' => 'string' ),
					'org-iso'                   => array( 'type' => 'string' ),
					'org-duns'                  => array( 'type' => 'string' ),
					'org-leicode'               => array( 'type' => 'string' ),
					'org-naics'                 => array( 'type' => 'string' ),
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
	 * Knowledge-graph identity is a site-global Yoast setting, so the guard is the same
	 * cap Yoast's own settings page enforces — `wpseo_manage_options` (research-findings
	 * §8) — with no object-level check. `manage_options` is never substituted: a user
	 * who may change WordPress core options is not necessarily trusted with Yoast's
	 * settings.
	 *
	 * @param array<string,mixed> $input The validated input.
	 * @return bool True when Yoast is active and the caller may manage Yoast options.
	 */
	public function hasPermission( $input ): bool {
		if ( ! YoastPlugin::isActive() ) {
			return false;
		}

		return current_user_can( 'wpseo_manage_options' );
	}

	/**
	 * Writes the supplied identity keys and returns the updated curated row.
	 *
	 * @param array<string,mixed> $input The validated input — only the keys to change.
	 * @return array<string,mixed>|\WP_Error The updated identity row, or a typed write error.
	 */
	public function execute( $input ) {
		if ( ! YoastPlugin::isActive() ) {
			return YoastPlugin::unavailable();
		}

		$input = is_array( $input ) ? $input : array();

		$allowed = $this->allowedKeys();

		// Deny-by-default: reject any key outside this concern's allow-list before any
		// write. Yoast's set() would otherwise write only the in-memory cache and return
		// null for an unknown key (research-findings §6.1).
		foreach ( array_keys( $input ) as $key ) {
			if ( ! in_array( $key, $allowed, true ) ) {
				return new WP_Error(
					'og_yoast_unknown_setting_key',
					sprintf(
						/* translators: 1: rejected setting key, 2: the get-* partner ability name. */
						__( 'The key "%1$s" is not a knowledge-graph setting and was not written. Inspect the valid keys and current values with %2$s.', 'abilities-catalog-yoast' ),
						(string) $key,
						self::READ_PARTNER
					),
					array( 'status' => 400 )
				);
			}
		}

		// The company-or-person selector is a closed enum. The input schema rejects an
		// out-of-enum value at validation time; this runtime guard repeats it so a direct
		// execute() call (bypassing schema validation) cannot persist an off-enum value.
		if ( array_key_exists( 'company_or_person', $input )
			&& ! in_array( (string) $input['company_or_person'], self::COMPANY_OR_PERSON, true ) ) {
			return new WP_Error(
				'og_yoast_unknown_setting_key',
				sprintf(
					/* translators: 1: rejected value, 2: allowed values, 3: the get-* partner ability name. */
					__( 'The value "%1$s" is not allowed for company_or_person (allowed: %2$s) and was not written. Inspect the current values with %3$s.', 'abilities-catalog-yoast' ),
					(string) $input['company_or_person'],
					implode( ', ', self::COMPANY_OR_PERSON ),
					self::READ_PARTNER
				),
				array( 'status' => 400 )
			);
		}

		foreach ( $input as $key => $value ) {
			$result = YoastPlugin::setOption( $key, $this->coerce( (string) $key, $value ), self::OPTION_GROUP );

			// set() returns null only when Yoast did not recognize the key (it wrote
			// just the in-memory cache) — the one real write failure. true means the
			// stored value byte-matches what was sent; false means Yoast wrote it but
			// its per-group validate() normalized the value (e.g. sanitized a URL or
			// stripped a leading '@'), which is success, not failure. The allow-list
			// check above is the real guard against unknown keys; null here is the
			// residual signal.
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
				__( 'The knowledge-graph key "%1$s" did not save to the %2$s settings. Re-read the current values with %3$s and retry.', 'abilities-catalog-yoast' ),
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
	 * only casts the JSON-decoded value to the key's broad PHP type (int / array / string)
	 * so the round-trip comparison and the stored value line up.
	 *
	 * @param string $key   The setting key.
	 * @param mixed  $value The decoded input value.
	 * @return mixed The value cast to the key's PHP type.
	 */
	private function coerce( string $key, $value ) {
		if ( in_array( $key, self::INT_KEYS, true ) ) {
			return (int) $value;
		}

		if ( in_array( $key, self::META_KEYS, true ) ) {
			return is_array( $value ) ? $value : (array) $value;
		}

		return (string) $value;
	}

	/**
	 * The full set of keys this concern accepts (the deny-by-default allow-list).
	 *
	 * @return list<string>
	 */
	private function allowedKeys(): array {
		return array_merge(
			array( 'company_or_person' ),
			self::INT_KEYS,
			self::META_KEYS,
			self::STRING_KEYS
		);
	}

	/**
	 * Curates the full `wpseo_titles` array down to the knowledge-graph row, in schema order.
	 *
	 * Mirrors the `get-knowledge-graph` partner's shape exactly so a caller sees the same
	 * object whether it reads or writes: the selector normalized to the enum, integer keys
	 * cast to int, string keys to string, and the two image-meta keys as objects.
	 *
	 * @param array<string,mixed> $option The full `wpseo_titles` option array.
	 * @return array<string,mixed> The curated identity row, in output-schema key order.
	 */
	private function curatedRow( array $option ): array {
		$selector = (string) ( $option['company_or_person'] ?? 'company' );

		$values = array(
			'company_or_person' => in_array( $selector, self::COMPANY_OR_PERSON, true ) ? $selector : 'company',
		);

		foreach ( self::INT_KEYS as $key ) {
			$values[ $key ] = (int) ( $option[ $key ] ?? 0 );
		}

		foreach ( self::STRING_KEYS as $key ) {
			$values[ $key ] = (string) ( $option[ $key ] ?? '' );
		}

		foreach ( self::META_KEYS as $key ) {
			$value          = $option[ $key ] ?? array();
			$values[ $key ] = (object) ( is_array( $value ) ? $value : array() );
		}

		return $this->ordered( $values );
	}

	/**
	 * Reorders the curated values to the documented output-schema key order.
	 *
	 * {@see curatedRow()} collects the values by type group (selector, ints, strings,
	 * meta) for clarity; this restores the schema's declared property order so the
	 * result key order is stable for consumers and the happy-path test. Mirrors the
	 * `get-knowledge-graph` partner's reorder step.
	 *
	 * @param array<string,mixed> $row The curated values, in collection order.
	 * @return array<string,mixed> The same values, in output-schema order.
	 */
	private function ordered( array $row ): array {
		$ordered = array();
		foreach ( self::ROW_ORDER as $key ) {
			$ordered[ $key ] = $row[ $key ];
		}

		return $ordered;
	}
}
