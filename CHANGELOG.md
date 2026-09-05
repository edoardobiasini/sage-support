# Changelog

All notable changes to `patterns-digital/sage-support` are documented here.
The format follows [Keep a Changelog](https://keepachangelog.com/), and this
project adheres to [Semantic Versioning](https://semver.org/).

## [0.2.0] - 2026-09-05

### Added

- `sage-support/preconnect_hosts` filter (in `src/seo.php`) — the consuming theme
  supplies an array of origins to `preconnect` to (analytics, video embeds,
  fonts) via a `wp_resource_hints` hook. Ships empty (no-op) by default.

## [0.1.0] - 2026-09-05

### Added

- Initial extraction from the `sage-starter` theme's `app/` directory into a
  reusable, project-agnostic Composer library.
- `src/update-policy.php` — disables wp-admin update/install paths on `local`.
- `src/tag-manager.php` — production-host-gated Google Tag Manager injection.
- `src/seo.php` — technical SEO (meta, canonical, OG/Twitter, JSON-LD, robots).

### Changed

- Filter prefix moved from `sage-starter/*` to the stable `sage-support/*` so
  the theme's per-project `bin/rename.sh` can't rewrite the module ⇄ config
  contract.
- Namespace `App\` → `Patterns\SageSupport\`.
- Each module guards on `defined('ABSPATH')` so it no-ops during Composer's
  autoload-dump (Acorn's `post-autoload-dump`) instead of fataling outside WP.
