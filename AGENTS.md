# AGENTS.md

Guidance for AI coding agents working in this repository.

## What this is

A WordPress plugin that registers the **Yoast SEO** management surface on the **Abilities API** (ships in WP 6.9 core) so any consumer can read and write SEO metadata for posts, terms, and authors, manage the site SEO settings, and rebuild the SEO index. It is an **add-on for Abilities Catalog** but works standalone on the core Abilities API — the catalog is optional. Yoast SEO is a hard runtime dependency: while Yoast is inactive the `og-yoast/*` abilities do not register at all (absent, not registered-and-denying), and the `og-yoast` domain tool and knowledge concepts do not appear.

Namespace: `GalatanOvidiu\AbilitiesCatalogYoast\` → `includes/`. Runtime uses a **no-build PSR-4 autoloader** in `abilities-catalog-yoast.php` (no Composer step for runtime code; Composer is dev-only).

## Commands

Lint / static analysis / format (run on host, need `composer install`):
```bash
composer lint          # phpcs (VIP + slevomat standard, .phpcs.xml.dist)
composer format        # phpcbf — auto-fix
composer phpstan       # phpstan analyse --memory-limit=1G (phpstan.neon.dist)
```

Tests run **inside wp-env** (a Docker WordPress with Yoast SEO + abilities-catalog installed), not on the host — PHPUnit needs the WP test env and Yoast loaded:
```bash
npm run wp-env start            # bring up the container (first time / after stop)
npm run test:php:setup          # composer install inside the container (run once)
npm run test:php                # full PHPUnit suite, no coverage
npm run test:php:coverage       # with coverage
```
Run a single test through the same wrapper by appending PHPUnit args:
```bash
npm run test:php -- --filter UpdateGeneralSettingsTest
```
`composer test` runs phpunit directly but only works if `WP_TESTS_DIR` / a WP test env is already wired up (see `tests/phpunit/bootstrap.php`); prefer the `npm run test:php` path.

## Architecture

**Convention-driven discovery.** `Registry` (`includes/Registry.php`) recursively scans `includes/Abilities/<Group>/`. Every class implementing `Contracts\Ability` is registered as an ability; every `Contracts\CategoryProvider` contributes its group's categories. There is **no shared manifest and no shared category list** — a contributor adds files only under their own group folder. The abilities live under `includes/Abilities/Yoast/` in functional subfolders (`Post/`, `Term/`, `Author/`, `Settings/`, `Ops/`); `Yoast/CategoryCatalog.php` is the group's single `CategoryProvider`. To add an ability: drop one class (one file) implementing `Ability`, referencing a category slug the group's `CategoryProvider` defines.

**Three contracts** (`includes/Contracts/`):
- `Ability` — `name()` (`namespace/verb-resource`, kebab-case) + `args()` (the full `wp_register_ability()` arg array).
- `ConditionalAbility extends Ability` — adds `isAvailable()`. The Registry registers it **only** when its dependency is present. All `og-yoast/*` abilities are conditional, gated on Yoast. `isAvailable()` is checked at registration/filter time (after `plugins_loaded`), never at file load or in the constructor.
- `CategoryProvider` — one per group, owns category slugs (global to the Abilities API — don't reuse a slug for a different meaning).

**Annotation guard (hard gate in `registerAbilities()`).** Read-only abilities register. A write registers only if it explicitly sets a boolean `annotations.destructive` (`false` for ordinary writes, `true` for destructive); a write that *omits* `destructive` is treated as unsafe and skipped with `_doing_it_wrong()`. This add-on has **no destructive abilities** (no deletes) — every write sets `destructive => false`. Registration ≠ exposure: the catalog adapter gates browser exposure separately via the write/destructive settings. Capability (`permission_callback`) is always the hard authorization guard.

**Schema normalization.** `Registry::normalizeSchema()` repairs two PHP→JSON quirks for every ability so authors don't have to: empty `'properties' => array()` becomes `stdClass` (`{}` not `[]`), and empty `'required' => array()` is dropped (AJV rejects a zero-length `required`).

**Yoast access funnels through one facade.** `Support\YoastPlugin` is the *only* place that touches `WPSEO_*` classes (`WPSEO_Meta`, `WPSEO_Options`, `WPSEO_Rank`, `WPSEO_Taxonomy_Meta`) or the `YoastSEO()` container. It owns the `isActive()` availability check (symbol presence **and** `WPSEO_VERSION >= MIN_VERSION`, currently `24.0`), the `unavailable()` typed error (defensive path), and typed wrappers over Yoast's reads and writes (`getPostMetaValue()`, `setPostMetaValue()`, `getOption()`, `rankFromScore()`, taxonomy meta, …). No ability names a `WPSEO_*` symbol directly — they call the facade.

**Abilities call the facade directly — they do NOT wrap REST routes.** Yoast has no full REST surface for SEO meta, so reads and writes go through `YoastPlugin`'s wrappers around Yoast's own option/meta API (which runs Yoast's per-field sanitizers). `Support\YoastFieldShaper` holds pure value transforms (no Yoast symbols) — it maps Yoast's terse internal codes (the `'0'`/`'1'`/`'2'` robots tri-state, separator slugs) to the human-readable labels an ability surfaces, kept in one tested place. `Ops\RebuildSeoIndex` is special: it additionally requires the `YoastSEO()` container, runs the six indexation actions to completion, and is a no-op off a production environment (Yoast skips indexing there).

**MCP integration is optional and filter-based** (`includes/Mcp/Integration.php`). When the Abilities Catalog MCP server is active, this add-on contributes a curated `og-yoast` domain tool (one tool over all `og-yoast/*` abilities — only the ones Yoast actually registered, confirmed via `wp_has_ability()`, so the tool stays honest) and an OKF knowledge bundle (`includes/knowledge/`: `audit-site-seo`, `optimize-post-seo`, `seo-safety`) — both through the catalog's public filters (`abilities_catalog_mcp_domains`, `abilities_catalog_mcp_knowledge`). The knowledge filter carries scanned `KnowledgeBundle` objects (a catalog class), so `contributeKnowledge` scans this add-on's own `includes/knowledge/` dir with `KnowledgeBundle::fromDirectory()` and pushes the bundle; that class resolves because the catalog — which fires the filter — is loaded. The filters no-op when the catalog is absent, so the add-on stays inert standalone. No core files of Abilities Catalog are modified. Concept bodies are static procedural text — they never embed per-site IDs or values. This add-on and the catalog move in **lockstep** on the knowledge filter: its name and payload type both changed together.

The Registry also contributes two catalog-adapter maps via filters: `abilities_catalog_dangerous_tools` (abilities with `annotations.dangerous = true`, for the Settings opt-in) and `abilities_catalog_screen_links` (`meta.screen` deep-link templates for write entries).

## Conventions

- PHP 8.1+, `declare(strict_types=1)`, `final` classes, tabs. Every class file guards with `if ( ! defined( 'ABSPATH' ) ) { exit; }`.
- High-risk writes: three abilities set `annotations.dangerous => true` — `update-general-settings`, `update-indexing-settings`, `rebuild-seo-index`. They change site-wide indexing/output or rebuild the index, so the catalog requires an explicit per-ability opt-in before exposing them. Keep that flag; the `seo-safety` knowledge concept documents what each one does.
- Result transparency: surface Yoast's terse codes as readable labels via `YoastFieldShaper` rather than echoing the raw `'0'`/`'1'`/`'2'` — it's a deliberate clarity affordance, not noise.
- Tests are integration-only (`tests/phpunit/Integration/`, mirroring the ability subfolders), run against real Yoast state; every test self-skips with `markTestSkipped()` behind a `YoastPlugin::isActive()` guard, so the whole suite skips cleanly when Yoast is inactive.
