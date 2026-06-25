# Abilities Catalog — Yoast SEO

Registers the [Yoast SEO](https://wordpress.org/plugins/wordpress-seo/) management surface on the WordPress **Abilities API** (ships in WP 7.0 core), so any Abilities API consumer can read and write SEO metadata for posts, terms, and authors, manage the site SEO settings, and rebuild the SEO index — each gated by the same capability Yoast requires.

It is **consumer-agnostic** and works standalone on the core Abilities API. The [Abilities Catalog](https://github.com/galatanovidiu/abilities-catalog) is optional: when its MCP server is active, this add-on contributes a curated **og-yoast** domain tool over the `og-yoast/*` abilities and an OKF knowledge bundle (the concepts under `includes/knowledge/`), through the catalog's public filters. No core files of Abilities Catalog are modified.

Yoast SEO is a hard runtime dependency: while Yoast is inactive the `og-yoast/*` abilities do not register at all (they are absent from the Abilities API, not registered-and-denying), and the og-yoast domain tool and knowledge concepts do not appear.

## Requirements

- WordPress 7.0+ (for the core Abilities API)
- PHP 8.1+
- Yoast SEO (active)
- Optional: Abilities Catalog, for the MCP og-yoast tool and SEO knowledge concepts

## Abilities

26 abilities, all named `og-yoast/*`. No delete — there are no destructive abilities; three writes are flagged **dangerous** (they need an explicit catalog opt-in to expose).

| Group | Read | Write |
|---|---|---|
| Post | `get-post-seo`, `get-post-score` | `update-post-seo`, `update-post-schema`, `update-post-robots`, `update-post-canonical` |
| Term | `get-term-seo` | `update-term-seo`, `update-term-robots`, `update-term-canonical` |
| Author | `get-author-seo` | `update-author-seo`, `update-author-noindex` |
| Search appearance | `get-search-appearance` | `update-search-appearance` |
| Breadcrumbs | `get-breadcrumbs` | `update-breadcrumbs` |
| Knowledge graph | `get-knowledge-graph` | `update-knowledge-graph` |
| Social | `get-social-settings` | `update-social-settings` |
| Indexing | `get-indexing-settings` | `update-indexing-settings` ⚠ |
| General | `get-general-settings` | `update-general-settings` ⚠ |
| Ops | — | `rebuild-seo-index` ⚠ |

⚠ = dangerous (changes site-wide indexing/output or rebuilds the index).

## Installation

1. Install and activate Yoast SEO.
2. Install and activate this plugin. The `og-yoast/*` abilities register automatically — no build step.
3. Optional: install Abilities Catalog and enable its MCP server to expose the og-yoast domain tool and the SEO knowledge concepts.

## Development

Lint, static analysis, and format run on the host (need `composer install`):

```bash
composer lint      # phpcs (VIP + slevomat, .phpcs.xml.dist)
composer format    # phpcbf — auto-fix
composer phpstan   # phpstan analyse
```

Tests run inside wp-env (Docker WordPress with Yoast SEO installed), not on the host:

```bash
npm run wp-env start       # bring up the container
npm run test:php:setup     # composer install inside the container (run once)
npm run test:php           # full PHPUnit suite
npm run test:php -- --filter UpdateGeneralSettingsTest   # single test
```

See [AGENTS.md](AGENTS.md) for architecture, conventions, and how to add an ability.

## License

MIT — see [LICENSE](LICENSE).
