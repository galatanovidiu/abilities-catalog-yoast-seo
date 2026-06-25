---
type: Skill
title: Optimize a post's SEO with Yoast
description: When a user wants to improve one post or page's search-engine snippet — focus keyphrase, SEO title, meta description, or cornerstone status.
---

Recipe: optimize a single post or page's on-page SEO with Yoast.

Goal: read the post's current SEO state and its stored scores, then set the focus keyphrase, SEO title, meta description, and cornerstone flag. Read before you write — the values differ per post, and the scores were saved earlier by the editor, not computed here.

STEP 1 - READ THE CURRENT STATE (all reads, through the "og-yoast" tool)
- og-yoast execute og-yoast/get-post-seo: returns the post's current focus keyphrase, SEO title, meta description, cornerstone flag, canonical URL, robots directives, and schema types. Read this before changing anything so you know the starting point and can describe the change.
- og-yoast execute og-yoast/get-post-score: returns the stored SEO and readability scores — each as a value plus a rank (na, bad, ok, or good). These are the values the Yoast editor saved earlier. This tool reads them; it does NOT re-run any analysis. Do not promise a fresh or recalculated score. If a score is missing, report "no stored score" rather than implying you analyzed the post.

STEP 2 - WRITE THE BASIC SEO FIELDS (a safe write, through the "og-yoast" tool)
- og-yoast execute og-yoast/update-post-seo: sets any of focus keyphrase, SEO title, meta description, cornerstone flag, and the social snippet overrides on one post. Writing a field's default value resets it — Yoast removes the stored row, so the change is reversible. Low blast radius: it touches snippet and SEO copy only. It never de-indexes the post.

BOUNDARY - WHAT THIS RECIPE DOES NOT TOUCH
Changing the robots directives (noindex / nofollow), the canonical URL, or the schema types is out of scope here. Those live in separate, advanced-gated abilities — og-yoast/update-post-robots, og-yoast/update-post-canonical, and og-yoast/update-post-schema — and they carry real SEO blast radius. og-yoast/update-post-seo deliberately excludes those high-risk fields. Read the seo-safety guideline before using any of them.
