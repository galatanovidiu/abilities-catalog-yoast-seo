=== Abilities Catalog — Contact Form 7 ===
Contributors: ovidiu-galatan
Tags: abilities-api, contact-form-7, ai, mcp
Requires at least: 7.0
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 0.1.1
License: MIT
License URI: https://opensource.org/license/mit

Registers Contact Form 7 forms as Abilities API abilities. An add-on for Abilities Catalog.

== Description ==

This plugin registers Contact Form 7 (CF7) forms on the WordPress Abilities API so
any Abilities API consumer can list, read, create, update, duplicate, and delete CF7
forms, each gated by CF7's own capabilities.

It is **consumer-agnostic** and works standalone on the core Abilities API — it does
not require Abilities Catalog. When the optional Abilities Catalog MCP server is
active, this add-on contributes a curated **forms** domain tool over the `cf7/*`
abilities and a **set-up-contact-form** knowledge concept (a recipe that chains
finding or creating a form into placing its shortcode on a page), through the
catalog's public filters. No core files of Abilities Catalog are modified.

Contact Form 7 is a hard runtime dependency: while CF7 is inactive the `cf7/*`
abilities do not register at all (they are absent from the Abilities API rather than
registered-and-denying), and the forms domain tool and knowledge concept do not appear.

= Abilities =

* `cf7/list-forms` — list and search forms (read).
* `cf7/get-form` — read one form's full configuration and shortcode (read).
* `cf7/create-form` — create a form (write).
* `cf7/update-form` — update a form (write).
* `cf7/duplicate-form` — copy a form (write).
* `cf7/delete-form` — permanently delete a form (destructive write).

== Installation ==

1. Install and activate Contact Form 7.
2. Install and activate this plugin. The `cf7/*` abilities register automatically.
3. Optional: install Abilities Catalog and enable its MCP server to expose the
   forms domain tool and the set-up-contact-form knowledge concept.

== Changelog ==

= 0.1.1 =
* Changed: the MCP integration now contributes an OKF knowledge bundle (the
  `set-up-contact-form` markdown concept under `includes/knowledge/`) through the
  catalog's `abilities_catalog_mcp_knowledge` filter, replacing the former PHP
  recipe class. Lockstep with Abilities Catalog 0.4.0; off-by-default and only
  active when that catalog and its MCP server are on.

= 0.1.0 =
* Initial release: six CF7 form abilities, plus optional Abilities Catalog MCP
  integration (forms domain tool and set-up-contact-form knowledge concept).
