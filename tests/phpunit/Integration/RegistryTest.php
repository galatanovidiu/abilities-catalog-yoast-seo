<?php
/**
 * Integration tests for ability registration.
 *
 * @package AbilitiesCatalogYoast\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogYoast\Tests\Integration;

use GalatanOvidiu\AbilitiesCatalogYoast\Abilities\Yoast\CategoryCatalog;
use GalatanOvidiu\AbilitiesCatalogYoast\Contracts\Ability;
use GalatanOvidiu\AbilitiesCatalogYoast\Support\YoastPlugin;
use GalatanOvidiu\AbilitiesCatalogYoast\Tests\TestCase;

/**
 * The standing registration guard for the Yoast add-on.
 *
 * It verifies that every `og-yoast/*` ability class on disk actually registers with
 * the Abilities API, that each registered ability points at the registered `og-yoast`
 * category, and that the consumer-facing filters expose a consistent subset of
 * abilities. It is written to stay green from batch 01 (zero abilities) onward: every
 * per-ability assertion iterates the discovered set, so with no abilities the
 * structural assertions still prove the discovery, category, and guard wiring without
 * pinning a hardcoded count or dangerous list.
 */
final class RegistryTest extends TestCase {

	/**
	 * Skips the whole class when Yoast SEO is inactive.
	 *
	 * The Yoast abilities are conditional, so they only register when Yoast is loaded;
	 * without it there is nothing to assert about registration.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		if ( ! YoastPlugin::isActive() ) {
			$this->markTestSkipped( 'Yoast SEO is not active; the og-yoast abilities do not register.' );
		}
	}

	/**
	 * Instantiates every ability class under includes/Abilities/<Group>/.
	 *
	 * Mirrors Registry::discover() (recursive, group-aware) so the test sees exactly
	 * the same set the Registry would register.
	 *
	 * @return array<int,Ability> Ability instances.
	 */
	private function discoverAbilities(): array {
		$base = ABILITIES_CATALOG_YOAST_DIR . 'includes/Abilities/';

		$files = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $base, \FilesystemIterator::SKIP_DOTS )
		);

		$abilities = array();
		foreach ( $files as $file ) {
			if ( ! $file->isFile() || 'php' !== $file->getExtension() ) {
				continue;
			}

			$relative = substr( $file->getPathname(), strlen( $base ), -strlen( '.php' ) );
			$class    = 'GalatanOvidiu\\AbilitiesCatalogYoast\\Abilities\\' . str_replace( '/', '\\', $relative );

			if ( ! class_exists( $class ) || ! is_subclass_of( $class, Ability::class ) ) {
				continue;
			}

			$abilities[] = new $class();
		}

		return $abilities;
	}

	public function test_every_ability_file_is_registered(): void {
		$missing = array();

		foreach ( $this->discoverAbilities() as $ability ) {
			if ( ! wp_has_ability( $ability->name() ) ) {
				$missing[] = $ability->name();
			}
		}

		$this->assertSame(
			array(),
			$missing,
			'These abilities exist on disk but failed to register (annotation guard or schema error): ' . implode( ', ', $missing )
		);
	}

	public function test_every_registered_ability_resolves(): void {
		$abilities = $this->discoverAbilities();
		$this->assertIsArray( $abilities, 'Ability discovery must return a list.' );

		foreach ( $abilities as $ability ) {
			$name = $ability->name();

			if ( ! wp_has_ability( $name ) ) {
				continue;
			}

			$this->assertNotNull(
				wp_get_ability( $name ),
				$name . ' reports registered but does not resolve via wp_get_ability().'
			);
		}
	}

	public function test_every_write_declares_an_explicit_boolean_destructive(): void {
		$abilities = $this->discoverAbilities();
		$this->assertIsArray( $abilities, 'Ability discovery must return a list.' );

		foreach ( $abilities as $ability ) {
			$annotations = $ability->args()['meta']['annotations'] ?? array();

			// Read-only abilities are exempt; only writes must annotate destructive.
			if ( true === ( $annotations['readonly'] ?? null ) ) {
				continue;
			}

			$this->assertArrayHasKey(
				'destructive',
				$annotations,
				$ability->name() . ' is a write but omits meta.annotations.destructive.'
			);
			$this->assertIsBool(
				$annotations['destructive'],
				$ability->name() . ' declares a non-boolean meta.annotations.destructive.'
			);
		}
	}

	public function test_every_ability_uses_the_registered_og_yoast_category(): void {
		$abilities = $this->discoverAbilities();
		$this->assertIsArray( $abilities, 'Ability discovery must return a list.' );

		foreach ( $abilities as $ability ) {
			$slug = $ability->args()['category'] ?? null;

			$this->assertSame(
				'og-yoast',
				$slug,
				$ability->name() . ' must use the og-yoast category.'
			);
			$this->assertTrue(
				wp_has_ability_category( (string) $slug ),
				$ability->name() . ' references the og-yoast category, which is not registered.'
			);
		}
	}

	public function test_og_yoast_category_is_owned_by_category_catalog(): void {
		$this->assertTrue(
			wp_has_ability_category( 'og-yoast' ),
			'The og-yoast category is not registered.'
		);

		$this->assertArrayHasKey(
			'og-yoast',
			( new CategoryCatalog() )->categories(),
			'CategoryCatalog does not declare the og-yoast category.'
		);
	}

	public function test_dangerous_tools_filter_is_consistent_with_annotations(): void {
		$tools = apply_filters( 'abilities_catalog_dangerous_tools', array() );

		$this->assertIsArray( $tools );

		foreach ( $this->discoverAbilities() as $ability ) {
			$annotations  = $ability->args()['meta']['annotations'] ?? array();
			$is_dangerous = true === ( $annotations['dangerous'] ?? null );

			if ( $is_dangerous ) {
				$this->assertArrayHasKey(
					$ability->name(),
					$tools,
					$ability->name() . ' is dangerous but missing from the dangerous-tools filter.'
				);
			} else {
				$this->assertArrayNotHasKey(
					$ability->name(),
					$tools,
					$ability->name() . ' is not dangerous but appears in the dangerous-tools filter.'
				);
			}
		}
	}

	public function test_screen_links_filter_excludes_readonly_abilities(): void {
		$links = apply_filters( 'abilities_catalog_screen_links', array() );

		$this->assertIsArray( $links );

		foreach ( $this->discoverAbilities() as $ability ) {
			$annotations = $ability->args()['meta']['annotations'] ?? array();

			if ( true === ( $annotations['readonly'] ?? null ) ) {
				$this->assertArrayNotHasKey(
					$ability->name(),
					$links,
					$ability->name() . ' is read-only but has a screen link.'
				);
			}
		}
	}
}
