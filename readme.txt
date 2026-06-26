=== Abilities Catalog — Yoast SEO ===
Contributors: ovidiu-galatan
Tags: abilities-api, yoast, seo, ai, mcp
Requires at least: 6.9
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 0.1.0
License: MIT
License URI: https://opensource.org/license/mit

Registers the Yoast SEO management surface as Abilities API abilities. An add-on for Abilities Catalog.

== Description ==

This plugin registers Yoast SEO management on the WordPress Abilities API so any
Abilities API consumer can read and write SEO metadata for posts, terms, and
authors, manage the site SEO settings, and rebuild the SEO index — each gated by
the same capability Yoast requires.

It is **consumer-agnostic** and works standalone on the core Abilities API — it does
not require Abilities Catalog. When the optional Abilities Catalog MCP server is
active, this add-on contributes a curated **og-yoast** domain tool over the
`og-yoast/*` abilities and an OKF knowledge bundle (the SEO concepts under
`includes/knowledge/`), through the catalog's public filters. No core files of
Abilities Catalog are modified.

Yoast SEO is a hard runtime dependency: while Yoast is inactive the `og-yoast/*`
abilities do not register at all (they are absent from the Abilities API rather than
registered-and-denying), and the og-yoast domain tool and knowledge concepts do not appear.

These abilities are not meant to replace Yoast SEO's own. This add-on is a working
bridge while Yoast SEO grows official Abilities API support. As soon as Yoast SEO
ships its own abilities, the duplicated ones in this plugin will be removed to make
room for the plugin-owned definitions.

= Abilities =

26 abilities, all named `og-yoast/*`. There are no destructive (delete) abilities;
three writes are flagged dangerous and need an explicit catalog opt-in to expose.

* Post: `get-post-seo`, `get-post-score` (read); `update-post-seo`, `update-post-schema`, `update-post-robots`, `update-post-canonical` (write).
* Term: `get-term-seo` (read); `update-term-seo`, `update-term-robots`, `update-term-canonical` (write).
* Author: `get-author-seo` (read); `update-author-seo`, `update-author-noindex` (write).
* Settings: `get`/`update` pairs for `search-appearance`, `breadcrumbs`, `knowledge-graph`, `social-settings`, `indexing-settings`, `general-settings`.
* Ops: `rebuild-seo-index` (write).

`update-general-settings`, `update-indexing-settings`, and `rebuild-seo-index` are
flagged dangerous.

== Installation ==

1. Install and activate Yoast SEO.
2. Install and activate this plugin. The `og-yoast/*` abilities register automatically.
3. Optional: install Abilities Catalog and enable its MCP server to expose the
   og-yoast domain tool and the SEO knowledge concepts.

== Changelog ==

= 0.1.0 =
* Initial release: 26 Yoast SEO abilities (post, term, author, settings, and index
  ops), plus optional Abilities Catalog MCP integration (og-yoast domain tool and an
  OKF knowledge bundle: audit-site-seo, optimize-post-seo, and seo-safety concepts).
