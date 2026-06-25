---
type: Skill
title: Audit a site's Yoast SEO posture
description: When a user wants a read-only review of their Yoast SEO settings and per-object SEO to find indexing or canonical risks before changing anything.
---

Recipe: audit a site's Yoast SEO posture.

Goal: survey the site-wide settings and a sample of per-object SEO, then surface the indexing and canonical risks. This is a read-and-recommend task: it does not change anything. Report the risk and the exact ability that would fix it, then let the user decide.

STEP 1 - READ THE SITE-WIDE SETTINGS (all reads, through the "og-yoast" tool)
- og-yoast execute og-yoast/get-indexing-settings: which post types, taxonomies, and archives are de-indexed (the noindex-<type> keys, disable-author, and similar). This is the highest-blast-radius surface — a single de-indexed type hides every URL of that type from search engines. Check it first.
- og-yoast execute og-yoast/get-search-appearance: the title and meta-description templates per content type.
- og-yoast execute og-yoast/get-breadcrumbs: the breadcrumb settings.
- og-yoast execute og-yoast/get-knowledge-graph: the organization or person identity used in structured data.
- og-yoast execute og-yoast/get-social-settings: the social profile URLs and social snippet defaults.
- og-yoast execute og-yoast/get-general-settings: the feature toggles and crawler directives. This read masks secrets — site-verification codes return a has_value boolean, never the raw token.

STEP 2 - SPOT-CHECK PER-OBJECT SEO (all reads, through the "og-yoast" tool)
- og-yoast execute og-yoast/get-post-seo, og-yoast/get-term-seo, and og-yoast/get-author-seo on a few representative objects. Look for a robots noindex set on an object (its URL is silently removed from search engines) or a canonical pointing at another URL (its ranking signals bleed to that target).
- og-yoast execute og-yoast/get-post-score: surfaces the stored SEO and readability rank only. Yoast computes scores in the editor, on the client; this audit reads what was saved. It cannot refresh a stale score. Flag "no stored score" rather than implying you analyzed the post.

STEP 3 - RECOMMEND, DO NOT SILENTLY FIX
Report the risks you found — de-indexed types, noindex objects, off-site canonicals — and name the exact ability that would change each one. Then let the user decide. Any fix goes through the flagged or dangerous writes; read the seo-safety guideline before recommending one.
