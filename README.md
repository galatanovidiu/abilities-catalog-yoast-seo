# Abilities Catalog — Yoast SEO

**This add-on registers 26 Yoast SEO operations on the WordPress Abilities API,
so any Abilities API consumer can run them. When the
[Abilities Catalog](https://github.com/galatanovidiu/abilities-catalog) MCP
server is active, agents reach these abilities through the same search-based
surface as core abilities — no separate server per plugin.**

It works standalone on the core Abilities API. Abilities Catalog is optional.
When the catalog is present and its MCP server is on, the Yoast abilities become
discoverable through the catalog's search server, and the add-on also
contributes a curated `og-yoast` domain tool and an OKF knowledge bundle. It
edits no catalog files: it plugs in through the catalog's public filters.

Yoast SEO is a hard runtime dependency. While Yoast is inactive the abilities do
not register at all — they are absent from the Abilities API, not
registered-and-denying — and the `og-yoast` tool and knowledge concepts do not
appear.

> [!NOTE]
> These abilities are not meant to replace Yoast SEO's own. This add-on is a
> working bridge while Yoast SEO grows official Abilities API support. As soon as
> Yoast SEO ships its own abilities, the duplicated ones in this plugin will be
> removed to make room for the plugin-owned definitions.

## Requirements

- WordPress 6.9 or later, with the Abilities API in core.
- PHP 8.1 or later.
- Yoast SEO, active.
- Optional: Abilities Catalog, for the MCP surface and SEO knowledge concepts.

## Installation

1. Install and activate Yoast SEO.
2. Install and activate this plugin. The Yoast abilities register
   automatically — no build step.
3. Optional: install Abilities Catalog and enable its MCP server. The Yoast
   abilities then appear through the catalog's search server, as a curated
   domain tool, and the SEO knowledge concepts become available.

## What it registers

26 abilities, all under the single `og-yoast` domain:

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

Ability names use a `domain/verb-noun` shape, for example:

- `og-yoast/get-post-seo`
- `og-yoast/update-post-canonical`
- `og-yoast/rebuild-seo-index`

Each ability declares an input and output schema, points at a category, enforces
a server-side `permission_callback`, and carries risk annotations. Of the 26
abilities, 10 are read-only and 16 are non-destructive writes. There are no
destructive abilities (no deletes); three writes are flagged **dangerous** (⚠) —
they change site-wide indexing or output, or rebuild the index, so they need an
explicit catalog opt-in to expose.

## How agents reach these abilities

The add-on registers on the same Abilities API as the core catalog, so it rides
the catalog's MCP surfaces. There is no separate server per plugin.

### Search server (primary)

When the catalog's MCP server is enabled, the Yoast abilities are indexed
alongside core abilities. An agent searches by task, describes one ability, and
executes it through the one search endpoint:

```text
/wp-json/abilities-catalog/v1/mcp-search
```

Discovery cost tracks the result set, not the total catalog size. This is the
recommended surface for new clients.

### Curated domain tool and knowledge

On the catalog's curated domain server, the add-on contributes one `og-yoast`
domain tool through the `abilities_catalog_mcp_domains` filter — a single tool
over all `og-yoast/*` abilities, supporting `list`, `describe`, and `execute`.
It also contributes an OKF knowledge bundle through the
`abilities_catalog_mcp_knowledge` filter (the concepts under
`includes/knowledge/`: auditing site SEO, optimizing a post, and SEO safety).
Both are inert when the catalog is absent.

## Safety

Two layers gate every ability, the same as core:

- **Capability is the hard guard.** Every ability's `permission_callback` calls
  `current_user_can()` with the matching Yoast capability — for example
  `wpseo_manage_options`, `wpseo_edit_advanced_metadata`, or the standard
  `edit_post` / `edit_term` / `edit_user` for object-level writes. This runs on
  every execution, independent of any MCP client.
- **MCP exposure gate.** When the catalog's MCP server is on, write and
  dangerous abilities stay gated for MCP execution until an administrator
  enables them. The three dangerous abilities (⚠) need an explicit opt-in at the
  catalog's settings before they can be exposed.

> [!WARNING]
> An MCP client acts as the authenticated WordPress user. Enabling write or
> dangerous Yoast abilities lets the client change real SEO data — post and term
> metadata, site-wide indexing and output settings. The dangerous abilities can
> de-index the whole site or rebuild the SEO index. Back up the site before
> enabling high-risk abilities, and enable only what the agent needs.

## Standalone and decoupled

This is a separate plugin, not part of the core catalog. It works on the bare
Abilities API with no catalog present: the `og-yoast/*` abilities still register
and run for any consumer. The MCP integration is filter-based and inert when the
catalog is absent — no catalog class is referenced and no catalog file is edited.

See
[Building an Abilities Catalog add-on](https://github.com/galatanovidiu/abilities-catalog/blob/main/docs/building-add-ons.md)
for the extension pattern.

## Development

Static checks run on the host (need `composer install`):

```bash
composer lint      # phpcs (VIP + Slevomat, .phpcs.xml.dist)
composer format    # phpcbf — auto-fix
composer phpstan   # phpstan analyse
```

Tests run inside wp-env (Docker WordPress with Yoast SEO installed), not on the
host:

```bash
npm run wp-env start       # bring up the container
npm run test:php:setup     # composer install inside the container (run once)
npm run test:php           # full PHPUnit suite
```

See [AGENTS.md](AGENTS.md) for architecture, conventions, and how to add an
ability.

## License

MIT — see [LICENSE](LICENSE).
