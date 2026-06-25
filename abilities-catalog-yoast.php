<?php
/**
 * Plugin Name:       Abilities Catalog — Yoast SEO
 * Plugin URI:        https://github.com/galatanovidiu/abilities-catalog-yoast
 * Description:       Registers the Yoast SEO management surface as Abilities API abilities (read and write SEO metadata for posts, terms, and authors, manage the site SEO settings, and rebuild the SEO index). An add-on for Abilities Catalog: it works standalone on the core Abilities API, and when the Abilities Catalog MCP server is active it contributes a Yoast SEO domain tool and knowledge concepts.
 * Version:           0.1.0
 * Requires at least: 7.0
 * Requires PHP:      8.1
 * Requires Plugins:  wordpress-seo
 * Author:            Ovidiu Galatan
 * Author URI:        https://github.com/galatanovidiu
 * License:           MIT
 * License URI:       https://opensource.org/license/mit
 * Text Domain:       abilities-catalog-yoast
 *
 * @package AbilitiesCatalogYoast
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogYoast;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ABILITIES_CATALOG_YOAST_VERSION', '0.1.0' );
define( 'ABILITIES_CATALOG_YOAST_FILE', __FILE__ );
define( 'ABILITIES_CATALOG_YOAST_DIR', plugin_dir_path( __FILE__ ) );

/**
 * No-build PSR-4 autoloader for the `GalatanOvidiu\AbilitiesCatalogYoast\` namespace.
 *
 * Maps the namespace root to the `includes/` directory, mirroring the Abilities
 * Catalog's no-build ethos (no Composer step for runtime code). Registered before
 * the bootstrap so the Registry and ability classes load on demand.
 */
spl_autoload_register(
	static function ( string $class_name ): void {
		$prefix = __NAMESPACE__ . '\\';
		if ( 0 !== strpos( $class_name, $prefix ) ) {
			return;
		}

		$relative = substr( $class_name, strlen( $prefix ) );
		$path     = ABILITIES_CATALOG_YOAST_DIR . 'includes/' . str_replace( '\\', '/', $relative ) . '.php';

		if ( ! is_readable( $path ) ) {
			return;
		}

		// phpcs:ignore WordPressVIPMinimum.Files.IncludingFile.UsingVariable -- PSR-4 path built from a plugin constant and an internal class name, not user input.
		require_once $path;
	}
);

add_action(
	'plugins_loaded',
	static function (): void {
		// The Yoast abilities register on the core Abilities API directly, so the
		// add-on works without Abilities Catalog present. A ConditionalAbility stays
		// absent while Yoast SEO is inactive.
		( new Registry() )->register();

		// The Abilities API ships with WordPress 7.0; without it there is nothing to
		// expose, so the optional MCP integration below has no hooks to attach to.
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		// Plug the Yoast SEO domain tool and the knowledge concepts into the
		// Abilities Catalog MCP server through its public filters. The filters no-op
		// when the catalog (or its server) is absent, so this stays safe standalone.
		Mcp\Integration::register();
	}
);
