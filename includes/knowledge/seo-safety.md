---
type: Guideline
title: SEO safety — what Yoast's high-risk writes do
description: The blast radius of the flagged and dangerous Yoast write abilities, applied before any noindex, canonical, indexing, or rebuild change.
---

These house rules apply on top of any recipe, whenever you call one of the flagged or dangerous Yoast write abilities. Read them before the call. The common danger is that these writes are silent and persistent: the page stays live, nothing breaks visibly, and the effect only shows up when someone re-inspects the SEO tags later.

NOINDEX SILENTLY DE-INDEXES
Setting a post's, term's, or author's noindex (og-yoast/update-post-robots, og-yoast/update-term-robots, og-yoast/update-author-noindex) removes that one URL from search engines. The change is silent and persistent: the page keeps working but drops out of Google with no visible signal until someone re-checks the robots tag. These are flagged writes — each returns the old value and the new value so you can see and revert the change. Writing the default value restores the previous behavior.

CANONICAL CHANGES BLEED LINK EQUITY
Pointing a post's or term's canonical at another URL (og-yoast/update-post-canonical, og-yoast/update-term-canonical) tells search engines the page is a duplicate of that target, transferring its ranking signals away. It has the same silent, persistent, invisible-until-rechecked blast radius as noindex. These are flagged writes too — each returns the old value and the new value.

WHOLE-TYPE DE-INDEX IS DANGEROUS
og-yoast/update-indexing-settings can de-index every URL of a post type or taxonomy in one call (the noindex-<type> keys), and og-yoast/update-general-settings can toggle the XML sitemap and crawler directives across the whole site. Both are marked dangerous: they stay disabled until the site owner enables them per-ability on the catalog's settings page, and each returns the old value and the new value for every changed key. Treat them as site-wide actions, not per-page edits.

REBUILD IS BOUND AND ADDITIVE
og-yoast/rebuild-seo-index re-derives Yoast's indexable cache from the site's posts and terms in one server request. It is additive (it never clears or truncates) and idempotent (re-running it drains to a no-op), but it runs to completion synchronously. So it is bound to normal sites — hundreds to low-thousands of objects. Very large sites should use Yoast's background indexer or the "wp yoast index" command instead. On a non-production environment the rebuild is a silent no-op, by the production-environment guard.

CAPABILITY IS ALWAYS THE HARD GUARD
Every write enforces its real Yoast capability on the server, regardless of how the ability is exposed or what it returns. The old-to-new result and the owner opt-in are transparency and exposure affordances — they are not the gate. The capability check is.
