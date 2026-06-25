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
 * Reads Yoast SEO's breadcrumbs settings.
 *
 * Returns a flat, closed row of the site-wide breadcrumbs configuration: whether
 * breadcrumbs are enabled, the home / prefix / search-prefix / archive-prefix /
 * 404 labels, the separator, bold-last, and show-blog-page. It lets a consumer
 * inspect the breadcrumbs setup before any settings write.
 *
 * All values come from the `wpseo_titles` option group through
 * {@see \GalatanOvidiu\AbilitiesCatalogYoast\Support\YoastPlugin::getOptionGroup()},
 * curated down to the breadcrumbs allow-list (research-findings §6.2,
 * `class-wpseo-option-titles.php:65-73`). The allow-list is closed: a future Yoast
 * key not on it is never surfaced. The ability never names a `WPSEO_*` symbol
 * itself — all Yoast access goes through the facade.
 *
 * This is a {@see ConditionalAbility} gated on Yoast SEO being active, so it does
 * not register when Yoast is off.
 *
 * @since 0.6.0
 */
final class GetBreadcrumbs implements ConditionalAbility {

	/**
	 * The Yoast option group these settings live in.
	 *
	 * @var string
	 */
	private const OPTION_GROUP = 'wpseo_titles';

	/**
	 * Breadcrumbs allow-list keys Yoast stores as booleans.
	 *
	 * @var string[]
	 */
	private const BOOLEAN_KEYS = array(
		'breadcrumbs-enable',
		'breadcrumbs-boldlast',
		'breadcrumbs-display-blog-page',
		'breadcrumbs-404crumb',
	);

	/**
	 * Breadcrumbs allow-list keys Yoast stores as strings.
	 *
	 * @var string[]
	 */
	private const STRING_KEYS = array(
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
		return 'og-yoast/get-breadcrumbs';
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
			'label'               => __( 'Get breadcrumbs settings', 'abilities-catalog-yoast' ),
			'description'         => __( 'Reads Yoast SEO breadcrumbs settings: whether breadcrumbs are enabled, the home, prefix, search-prefix, archive-prefix and 404 labels, the separator, bold-last, and show-blog-page.', 'abilities-catalog-yoast' ),
			'category'            => 'og-yoast',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => (object) array(),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
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
	 * Breadcrumbs settings are site-global, so there is no object-level check. The
	 * cap is Yoast's own settings cap `wpseo_manage_options` (granted to
	 * administrators), never a substituted `manage_options` (research-findings §8).
	 *
	 * @param array<string,mixed> $input The validated input (no input expected).
	 * @return bool True when Yoast is active and the caller may manage Yoast options.
	 */
	public function hasPermission( $input ): bool {
		if ( ! YoastPlugin::isActive() ) {
			return false;
		}

		return current_user_can( 'wpseo_manage_options' );
	}

	/**
	 * Reads and returns the breadcrumbs settings as a flat, closed row.
	 *
	 * @param array<string,mixed> $input The validated input (no input expected).
	 * @return array<string,mixed>|\WP_Error The flat breadcrumbs row, or a typed read error.
	 */
	public function execute( $input ) {
		if ( ! YoastPlugin::isActive() ) {
			return YoastPlugin::unavailable();
		}

		$options = YoastPlugin::getOptionGroup( self::OPTION_GROUP );

		if ( $options instanceof WP_Error ) {
			return $options;
		}

		$row = array();

		foreach ( self::BOOLEAN_KEYS as $key ) {
			$row[ $key ] = ! empty( $options[ $key ] );
		}

		foreach ( self::STRING_KEYS as $key ) {
			$row[ $key ] = isset( $options[ $key ] ) ? (string) $options[ $key ] : '';
		}

		return $row;
	}
}
