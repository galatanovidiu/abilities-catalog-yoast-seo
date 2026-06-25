# Abilities Catalog — Contact Form 7

Registers [Contact Form 7](https://wordpress.org/plugins/contact-form-7/) (CF7) forms on the WordPress **Abilities API** (ships in WP 7.0 core), so any Abilities API consumer can list, read, create, update, duplicate, and delete CF7 forms — each gated by CF7's own capabilities.

It is **consumer-agnostic** and works standalone on the core Abilities API. The [Abilities Catalog](https://github.com/galatanovidiu/abilities-catalog) is optional: when its MCP server is active, this add-on contributes a curated **cf7** domain tool over the `cf7/*` abilities and an OKF knowledge bundle (the **set-up-contact-form** concept), through the catalog's public filters. No core files of Abilities Catalog are modified.

Contact Form 7 is a hard runtime dependency: while CF7 is inactive the `cf7/*` abilities do not register at all (they are absent from the Abilities API, not registered-and-denying), and the cf7 domain tool and knowledge concept do not appear.

## Requirements

- WordPress 7.0+ (for the core Abilities API)
- PHP 8.1+
- Contact Form 7 (active)
- Optional: Abilities Catalog, for the MCP cf7 tool and setup knowledge concept

## Abilities

| Ability | Type | What it does |
|---|---|---|
| `cf7/list-forms` | read | list and search forms |
| `cf7/get-form` | read | read one form's full configuration and shortcode |
| `cf7/create-form` | write | create a form |
| `cf7/update-form` | write | update a form |
| `cf7/duplicate-form` | write | copy a form |
| `cf7/delete-form` | destructive write | permanently delete a form |

## Installation

1. Install and activate Contact Form 7.
2. Install and activate this plugin. The `cf7/*` abilities register automatically — no build step.
3. Optional: install Abilities Catalog and enable its MCP server to expose the cf7 domain tool and the set-up-contact-form knowledge concept.

## Development

Lint, static analysis, and format run on the host (need `composer install`):

```bash
composer lint      # phpcs (VIP + slevomat, .phpcs.xml.dist)
composer format    # phpcbf — auto-fix
composer phpstan   # phpstan analyse
```

Tests run inside wp-env (Docker WordPress with CF7 installed), not on the host:

```bash
npm run wp-env start       # bring up the container
npm run test:php:setup     # composer install inside the container (run once)
npm run test:php           # full PHPUnit suite
npm run test:php -- --filter CreateFormTest   # single test
```

See [AGENTS.md](AGENTS.md) for architecture, conventions, and how to add an ability.

## License

MIT — see [LICENSE](LICENSE).
