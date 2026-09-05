# patterns-digital/sage-support

Reusable, **project-agnostic** WordPress theme support modules, shared across
Patterns Digital Sage starter projects. Everything here is best-practice code
with **zero project-specific values** — each value is read through a
`sage-support/*` filter, which the consuming theme wires up in its own
`app/config.php`.

Three modules, loaded via Composer `autoload.files`:

| File | What it does |
|---|---|
| `src/update-policy.php` | Disables every wp-admin update/install path **on `local`** (gated on `wp_get_environment_type()`), so pinned-plugin versions can't drift. No-op on staging/production. |
| `src/tag-manager.php` | Google Tag Manager head + noscript snippets, gated on a **production-host allowlist** (not `WP_ENVIRONMENT_TYPE`, so it survives Cloudways clone / push-to-live). Off until a container ID **and** a production host are set. |
| `src/seo.php` | Technical SEO — meta description, canonical, Open Graph/Twitter, JSON-LD (`Organization`/`WebSite`/`WebPage`/`Product`/`FAQPage`), favicon, robots. Forces `noindex` on any non-production host. Reuses `tag-manager.php`'s host allowlist so indexing and tracking can never drift apart. |

## Why these live in a package (and `config.php` doesn't)

The modules carry logic; the *values* are per-project. That seam is the whole
point: improvements to the SEO/GTM/update logic ship to every project via
`composer update patterns-digital/sage-support`, while each project's concrete
values stay in its own theme (`app/config.php`), untouched by updates.

The filter prefix is deliberately `sage-support/*` — a **stable** namespace the
theme's `bin/rename.sh` (which blanket-renames the `sage-starter` slug per
project) never rewrites. If these filters used the theme slug, a renamed
project's `config.php` would call `acme/gtm_id` while this (un-renamed) package
still read `sage-support/gtm_id`, and everything would silently break.

## Loading contract

Each file starts with `if (! defined('ABSPATH')) return;`. This matters: the
consuming theme runs Composer with Acorn's `post-autoload-dump` script, which
loads the root autoloader **outside WordPress**. Without the guard, the
top-level `add_filter()`/`wp_get_environment_type()` calls would fatal during
`composer install`. Inside WordPress (theme `functions.php` → `vendor/autoload.php`),
`ABSPATH` is defined and the modules register their hooks normally.

## Public filter API

All filters return empty by default (safe: `noindex`, no tracking). The theme
sets them in `app/config.php`.

- `sage-support/production_hosts` — `string[]` of production hostnames. Empty ⇒ `noindex` everywhere, GTM off. **This is the master switch.**
- `sage-support/canonical_url` — canonical origin (falls back to the site URL).
- `sage-support/gtm_id` — GTM container ID (`GTM-XXXXXXX`); `''` disables.
- `sage-support/meta_description`, `sage-support/og_image`, `sage-support/favicon`, `sage-support/theme_color`
- `sage-support/org_name` (+ `org_vat_id` / `org_phone` / `org_address` / `org_same_as` / `org_logo`)
- `sage-support/product_name` (+ `product_specs` / `product_brand` / `product_description`)
- `sage-support/faq_items` — `[['q' => …, 'a' => …], …]`, shared by a FAQ accordion and the `FAQPage` JSON-LD.
- `sage-support/preconnect_hosts` — `string[]` of full origins to `preconnect` to (e.g. `'https://www.googletagmanager.com'`), emitted via `wp_resource_hints`. Empty ⇒ no hints.

## Consuming it

This is a standalone public repo, consumed over a Composer **VCS** repository.
In the theme's root `composer.json`:

```json
"repositories": [
  { "type": "vcs", "url": "https://github.com/edoardobiasini/sage-support.git" }
],
"require": { "patterns-digital/sage-support": "^0.2" }
```

Then `composer update patterns-digital/sage-support`. Git tags drive versions
(this repo carries no `version` field). From then on, best-practice improvements
are a tag here + `composer update` in each project away — no per-project
cherry-pick or copy. The theme wires the `sage-support/*` filters in its own
`app/config.php`; updates to the module logic never touch that.

## Versioning

Semver. See `CHANGELOG.md`. Breaking changes to a filter name or its
contract are a major bump.
