# AGENTS.md

Guidance for AI coding agents working in this repository.

## What this is

A WordPress plugin that registers Contact Form 7 (CF7) forms on the **Abilities API** (ships in WP 7.0 core) so any consumer can list/get/create/update/duplicate/delete CF7 forms. It is an **add-on for Abilities Catalog** but works standalone on the core Abilities API — the catalog is optional. CF7 is a hard runtime dependency: while CF7 is inactive the `cf7/*` abilities do not register at all (absent, not registered-and-denying).

Namespace: `GalatanOvidiu\AbilitiesCatalogYoast\` → `includes/`. Runtime uses a **no-build PSR-4 autoloader** in `abilities-catalog-yoast.php` (no Composer step for runtime code; Composer is dev-only).

## Commands

Lint / static analysis / format (run on host, need `composer install`):
```bash
composer lint          # phpcs (VIP + slevomat standard, .phpcs.xml.dist)
composer format        # phpcbf — auto-fix
composer phpstan       # phpstan analyse --memory-limit=1G (phpstan.neon.dist)
```

Tests run **inside wp-env** (a Docker WordPress with CF7 + abilities-catalog installed), not on the host — PHPUnit needs the WP test env and CF7 loaded:
```bash
npm run wp-env start            # bring up the container (first time / after stop)
npm run test:php:setup          # composer install inside the container (run once)
npm run test:php                # full PHPUnit suite, no coverage
npm run test:php:coverage       # with coverage
```
Run a single test through the same wrapper by appending PHPUnit args:
```bash
npm run test:php -- --filter CreateFormTest
```
`composer test` runs phpunit directly but only works if `WP_TESTS_DIR` / a WP test env is already wired up (see `tests/phpunit/bootstrap.php`); prefer the `npm run test:php` path.

## Architecture

**Convention-driven discovery.** `Registry` (`includes/Registry.php`) recursively scans `includes/Abilities/<Group>/`. Every class implementing `Contracts\Ability` is registered as an ability; every `Contracts\CategoryProvider` contributes its group's categories. There is **no shared manifest and no shared category list** — a contributor adds files only under their own group folder. To add an ability: drop one class (one file) implementing `Ability` under `includes/Abilities/<Group>/`, referencing a category slug its group's `CategoryProvider` defines.

**Three contracts** (`includes/Contracts/`):
- `Ability` — `name()` (`namespace/verb-resource`, kebab-case) + `args()` (the full `wp_register_ability()` arg array).
- `ConditionalAbility extends Ability` — adds `isAvailable()`. The Registry registers it **only** when its dependency is present. All `cf7/*` abilities are conditional, gated on CF7. `isAvailable()` is checked at registration/filter time (after `plugins_loaded`), never at file load or in the constructor.
- `CategoryProvider` — one per group, owns category slugs (global to the Abilities API — don't reuse a slug for a different meaning).

**Annotation guard (hard gate in `registerAbilities()`).** Read-only abilities register. A write registers only if it explicitly sets a boolean `annotations.destructive` (`false` for ordinary writes, `true` for destructive like delete); a write that *omits* `destructive` is treated as unsafe and skipped with `_doing_it_wrong()`. Registration ≠ exposure: the catalog adapter gates browser exposure separately via the write/destructive settings. Capability (`permission_callback`) is always the hard authorization guard.

**Schema normalization.** `Registry::normalizeSchema()` repairs two PHP→JSON quirks for every ability so authors don't have to: empty `'properties' => array()` becomes `stdClass` (`{}` not `[]`), and empty `'required' => array()` is dropped (AJV rejects a zero-length `required`).

**CF7 access funnels through one facade.** `Support\YoastPlugin` is the *only* place that touches `wpcf7_*` / `WPCF7_*` symbols. It owns the `isActive()` availability check, the `unavailable()` typed error (HTTP 409, defensive path), and the reads the REST routes don't expose (`shortcode()`, `hash()`, `duplicate()` = `copy()`+`save()`). No other file references a CF7 symbol directly.

**Abilities wrap CF7's own REST routes** via `rest_do_request()` against `contact-form-7/v1/contact-forms` (so CF7's validation/capability checks run underneath) — except where CF7 has no route: duplicate uses `YoastPlugin::duplicate()`, and shortcode/hash come off the live object. Create/update share `Support\Cf7FormWriteRequest` (field forwarding + result shaping + shared output schema); the create/update difference is blank handling — create skips empty strings (omitted group → CF7 default template), update forwards present-but-empty so `""` can blank a field. Create dispatch must set `context=save` or CF7 builds the form in memory and never persists it.

**MCP integration is optional and filter-based** (`includes/Mcp/Integration.php`). When the Abilities Catalog MCP server is active, this add-on contributes a curated `cf7` domain tool (one tool over all six `cf7/*` abilities) and an OKF knowledge bundle (`includes/knowledge/`, the `set-up-contact-form` concept) — both through the catalog's public filters (`abilities_catalog_mcp_domains`, `abilities_catalog_mcp_knowledge`). The knowledge filter carries scanned `KnowledgeBundle` objects (a catalog class), so `contributeKnowledge` scans this add-on's own `includes/knowledge/` dir with `KnowledgeBundle::fromDirectory()` and pushes the bundle; that class resolves because the catalog — which fires the filter — is loaded. The filters no-op when the catalog is absent, so the add-on stays inert standalone. No core files of Abilities Catalog are modified. The concept body is static procedural text — it never embeds per-site form IDs or shortcodes. This add-on and the catalog move in **lockstep** on the knowledge filter: its name and payload type both changed together.

The Registry also contributes two catalog-adapter maps via filters: `abilities_catalog_dangerous_tools` (abilities with `annotations.dangerous = true`, for the Settings opt-in) and `abilities_catalog_screen_links` (`meta.screen` deep-link templates for write entries).

## Conventions

- PHP 8.1+, `declare(strict_types=1)`, `final` classes, tabs. Every class file guards with `if ( ! defined( 'ABSPATH' ) ) { exit; }`.
- Mail transparency: create/update echo the resulting `mail_recipient` and `mail_additional_headers` because CF7 does not validate them (a `Bcc:` header silently copies submissions). Keep that surfaced in results — it's a deliberate safety affordance, not noise.
- Tests are integration-only (`tests/phpunit/Integration/`), seeded through CF7's real `wpcf7_save_contact_form()` save path (see `Cf7FormsTestCase`); the whole CF7 suite self-skips when CF7 is inactive.
