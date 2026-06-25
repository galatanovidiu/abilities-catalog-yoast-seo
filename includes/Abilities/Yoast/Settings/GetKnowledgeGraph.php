<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogYoast\Abilities\Yoast\Settings;

use GalatanOvidiu\AbilitiesCatalogYoast\Contracts\ConditionalAbility;
use GalatanOvidiu\AbilitiesCatalogYoast\Support\YoastPlugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads Yoast's site-representation (knowledge graph) identity settings.
 *
 * Returns a flat, closed row describing whether the site represents an organization
 * or a person, the organization's name / logo / contact details, and the person's
 * name / logo. Search engines use this identity to build the site's knowledge-graph
 * entry, so a consumer reads it to understand who the site claims to be before any
 * write changes that identity.
 *
 * These keys live in Yoast's `wpseo_titles` option group, NOT a separate
 * `wpseo_knowledge_graph` option (research-findings §6.2,
 * `class-wpseo-option-titles.php:75-116`). The read curates the full group array down
 * to this concern's allow-list, so a future Yoast key outside the list is never
 * surfaced.
 *
 * Yoast stores the two image-meta keys (`person_logo_meta`, `company_logo_meta`) as
 * internal attachment-metadata arrays. They are surfaced as nested objects so the
 * closed schema can carry them without enumerating Yoast's internal shape; a consumer
 * reads the scalar identity fields for the meaningful values.
 *
 * The single Yoast access is {@see YoastPlugin::getOptionGroup()}, so the ability
 * never names a `WPSEO_*` symbol itself. It is a
 * {@see ConditionalAbility} gated on Yoast SEO being active, so it does not register
 * when Yoast is off.
 *
 * @since 0.6.0
 */
final class GetKnowledgeGraph implements ConditionalAbility {

	/**
	 * The Yoast option group the knowledge-graph identity keys live in.
	 *
	 * @var string
	 */
	private const OPTION_GROUP = 'wpseo_titles';

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
	 * Integer identity keys curated from `wpseo_titles`. Default `0` when unset.
	 *
	 * @var list<string>
	 */
	private const INT_KEYS = array(
		'company_or_person_user_id',
		'person_logo_id',
		'company_logo_id',
	);

	/**
	 * Image-meta keys curated from `wpseo_titles`. Yoast stores attachment-metadata
	 * arrays here; surfaced as nested objects. Default empty object when unset.
	 *
	 * @var list<string>
	 */
	private const META_KEYS = array(
		'person_logo_meta',
		'company_logo_meta',
	);

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-yoast/get-knowledge-graph';
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
			'label'               => __( 'Get site identity (knowledge graph)', 'abilities-catalog-yoast' ),
			'description'         => __( 'Reads the site-representation settings Yoast SEO publishes for search engines: whether the site represents an organization or a person, the organization name, logo, and contact details, and the person name and logo. Read-only inspection of the site identity before any change.', 'abilities-catalog-yoast' ),
			'category'            => 'og-yoast',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => (object) array(),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'properties'           => array(
					'company_or_person'         => array(
						'type'        => 'string',
						'enum'        => array( 'company', 'person' ),
						'description' => __( 'Whether the site represents a company (organization) or a person.', 'abilities-catalog-yoast' ),
					),
					'company_or_person_user_id' => array(
						'type'        => 'integer',
						'description' => __( 'When the site represents a person, the WordPress user ID of that person. 0 when unset.', 'abilities-catalog-yoast' ),
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
						'description' => __( 'The person logo / avatar image URL. Empty when unset.', 'abilities-catalog-yoast' ),
					),
					'person_logo_id'            => array(
						'type'        => 'integer',
						'description' => __( 'The attachment ID of the person logo image. 0 when unset.', 'abilities-catalog-yoast' ),
					),
					'person_logo_meta'          => array(
						'type'                 => 'object',
						'description'          => __( 'Yoast-internal image metadata for the person logo. Empty object when unset.', 'abilities-catalog-yoast' ),
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
						'description' => __( 'The organization logo image URL. Empty when unset.', 'abilities-catalog-yoast' ),
					),
					'company_logo_id'           => array(
						'type'        => 'integer',
						'description' => __( 'The attachment ID of the organization logo image. 0 when unset.', 'abilities-catalog-yoast' ),
					),
					'company_logo_meta'         => array(
						'type'                 => 'object',
						'description'          => __( 'Yoast-internal image metadata for the organization logo. Empty object when unset.', 'abilities-catalog-yoast' ),
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
	 * Authorizes the read: Yoast active and the caller may manage Yoast options.
	 *
	 * Knowledge-graph identity is a site-global Yoast setting, so the guard is the
	 * same cap Yoast's own settings page enforces — `wpseo_manage_options`
	 * (research-findings §8) — with no object-level check. `manage_options` is never
	 * substituted: a user who may change WordPress core options is not necessarily
	 * trusted with Yoast's settings.
	 *
	 * @param array<string,mixed> $input The validated input (none for this read).
	 * @return bool True when Yoast is active and the caller may manage Yoast options.
	 */
	public function hasPermission( $input ): bool {
		if ( ! YoastPlugin::isActive() ) {
			return false;
		}

		return current_user_can( 'wpseo_manage_options' );
	}

	/**
	 * Reads and returns the curated knowledge-graph identity row.
	 *
	 * @param array<string,mixed> $input The validated input (none for this read).
	 * @return array<string,mixed>|\WP_Error The flat identity row, or a typed read error.
	 */
	public function execute( $input ) {
		if ( ! YoastPlugin::isActive() ) {
			return YoastPlugin::unavailable();
		}

		$option = YoastPlugin::getOptionGroup( self::OPTION_GROUP );
		if ( is_wp_error( $option ) ) {
			return $option;
		}

		$selector = (string) ( $option['company_or_person'] ?? 'company' );
		$row      = array(
			'company_or_person' => in_array( $selector, array( 'company', 'person' ), true ) ? $selector : 'company',
		);

		foreach ( self::INT_KEYS as $key ) {
			$row[ $key ] = (int) ( $option[ $key ] ?? 0 );
		}

		foreach ( self::STRING_KEYS as $key ) {
			$row[ $key ] = (string) ( $option[ $key ] ?? '' );
		}

		foreach ( self::META_KEYS as $key ) {
			$value       = $option[ $key ] ?? array();
			$row[ $key ] = (object) ( is_array( $value ) ? $value : array() );
		}

		return $this->ordered( $row );
	}

	/**
	 * Reorders the curated values to the documented output-schema key order.
	 *
	 * `execute()` collects the values by type group (selector, ints, strings, meta)
	 * for clarity; this restores the schema's declared property order so the result
	 * key order is stable for consumers and the happy-path test.
	 *
	 * @param array<string,mixed> $row The curated values, in collection order.
	 * @return array<string,mixed> The same values, in output-schema order.
	 */
	private function ordered( array $row ): array {
		$order = array(
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

		$ordered = array();
		foreach ( $order as $key ) {
			$ordered[ $key ] = $row[ $key ];
		}

		return $ordered;
	}
}
