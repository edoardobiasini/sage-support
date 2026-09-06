<?php

/**
 * `wp sage-support plugins-audit` — surface plugin drift against a committed manifest.
 *
 * Plugins are the filesystem lane: their files live in `wp-content/plugins/` (not
 * git, not the deploy rsync) and their active state lives in the DB. So a plugin a
 * colleague installs via wp-admin on production is invisible to git and to every
 * other environment. This command makes that drift visible by comparing three
 * sources:
 *
 *   1. the committed manifest (`sage-support/plugins_manifest_path`) — what SHOULD be here;
 *   2. plugins present on disk (`get_plugins()`);
 *   3. plugins active in the DB (`active_plugins` option).
 *
 * It reports:
 *   - ACTIVE-BUT-MISSING: active in the DB but not on disk. After a
 *     `bin/db-sync.sh pull production` the DB carries production's active set, so
 *     this is exactly "production has a plugin you don't have locally" — the
 *     signal that someone changed plugins upstream. Fix: `wp plugin install <slug> --activate`.
 *   - UNDOCUMENTED: installed but not in the manifest (installed via UI?).
 *   - MISSING: in the manifest but not installed here.
 *   - DRIFT: manifest pins an exact version that differs from the installed one.
 *
 * Warn-only by default (exit 0) — it never blocks a deploy; the deploy step calls
 * it for visibility, and the only hard gate in the pipeline stays the health check.
 * `--strict` exits non-zero if anything is found (for anyone who wants a CI gate).
 * `--update-manifest` appends the UNDOCUMENTED plugins to the manifest so
 * "update the manifest" is one command + a reviewed `git diff`, not hand-editing.
 *
 * Manifest CSV: `slug,version,track,notes` with a header row. `#`-lines and blank
 * lines are ignored. `slug` is the plugin directory (e.g. `secure-custom-fields`);
 * `track` is `pinned` (Composer WP-root) or `platform` (Cloudways SafeUpdates).
 */

namespace Patterns\SageSupport;

if (! defined('ABSPATH')) {
    return;
}

if (! defined('WP_CLI') || ! \WP_CLI) {
    return;
}

/**
 * Absolute path to the project's committed plugin manifest CSV. Empty ⇒ the
 * command can't run (it tells you to set the filter). Set per project in config.
 */
function plugins_manifest_path(): string
{
    return (string) apply_filters('sage-support/plugins_manifest_path', '');
}

/**
 * Plugin file (`dir/main.php` or `single.php`) → slug (its directory, or the
 * basename for a single-file plugin).
 */
function plugin_slug_from_file(string $file): string
{
    return str_contains($file, '/') ? dirname($file) : basename($file, '.php');
}

/**
 * Parse the manifest CSV into slug => ['version' => …, 'track' => …].
 */
function read_plugins_manifest(string $path): array
{
    $rows = [];
    $handle = fopen($path, 'r');
    if (! $handle) {
        return $rows;
    }

    $first = true;
    while (($cols = fgetcsv($handle)) !== false) {
        if ($cols === [null] || $cols === false) {
            continue; // blank line
        }
        $slug = trim((string) ($cols[0] ?? ''));
        if ($slug === '' || str_starts_with($slug, '#')) {
            continue; // comment / empty
        }
        if ($first && strtolower($slug) === 'slug') {
            $first = false;
            continue; // header
        }
        $first = false;
        $rows[$slug] = [
            'version' => trim((string) ($cols[1] ?? '')),
            'track' => trim((string) ($cols[2] ?? '')),
        ];
    }
    fclose($handle);

    return $rows;
}

/**
 * True when a manifest version looks like an exact version we can drift-check
 * (e.g. `6.9.5`), as opposed to a range/constraint (`^6.0`, `*`, empty).
 */
function is_exact_version(string $v): bool
{
    return $v !== '' && (bool) preg_match('/^\d+(\.\d+)*$/', $v);
}

\WP_CLI::add_command('sage-support plugins-audit', function (array $args, array $assoc): void {
    $strict = isset($assoc['strict']);
    $update = isset($assoc['update-manifest']);

    $path = plugins_manifest_path();
    if ($path === '') {
        \WP_CLI::warning("No manifest configured. Set add_filter('sage-support/plugins_manifest_path', fn () => '/abs/path/plugins.manifest.csv'). Nothing to audit.");

        return;
    }
    if (! file_exists($path)) {
        \WP_CLI::warning("Manifest not found: {$path}");

        return;
    }

    require_once ABSPATH.'wp-admin/includes/plugin.php';

    $manifest = read_plugins_manifest($path);

    // Installed on disk: slug => version.
    $installed = [];
    foreach (get_plugins() as $file => $data) {
        $installed[plugin_slug_from_file((string) $file)] = (string) ($data['Version'] ?? '');
    }

    // Active in the DB (carries production's set right after a `db-sync pull`).
    $active = array_map(fn ($f) => plugin_slug_from_file((string) $f), (array) get_option('active_plugins', []));
    $active = array_values(array_unique($active));

    $active_missing = array_values(array_diff($active, array_keys($installed)));
    $undocumented = array_values(array_diff(array_keys($installed), array_keys($manifest)));
    $missing = array_values(array_diff(array_keys($manifest), array_keys($installed)));

    $drift = [];
    foreach ($manifest as $slug => $row) {
        if (isset($installed[$slug]) && is_exact_version($row['version']) && $installed[$slug] !== $row['version']) {
            $drift[$slug] = ['manifest' => $row['version'], 'installed' => $installed[$slug]];
        }
    }

    $findings = 0;

    if ($active_missing) {
        $findings += count($active_missing);
        \WP_CLI::warning('Active in the DB but not installed here (an environment has plugins you don\'t): '.implode(', ', $active_missing));
        \WP_CLI::log('   fix: '.implode('  ', array_map(fn ($s) => "wp plugin install {$s} --activate", $active_missing)));
    }
    if ($undocumented) {
        $findings += count($undocumented);
        \WP_CLI::warning('Installed but not in the manifest (installed via UI?): '.implode(', ', $undocumented));
    }
    if ($missing) {
        $findings += count($missing);
        \WP_CLI::warning('In the manifest but not installed here: '.implode(', ', $missing));
    }
    foreach ($drift as $slug => $v) {
        $findings++;
        \WP_CLI::warning("Version drift {$slug}: manifest {$v['manifest']} vs installed {$v['installed']}");
    }

    if ($update && $undocumented) {
        $handle = fopen($path, 'a');
        if ($handle) {
            foreach ($undocumented as $slug) {
                fputcsv($handle, [$slug, $installed[$slug] ?? '', 'platform', 'auto-added by plugins-audit --update-manifest; review track']);
            }
            fclose($handle);
            \WP_CLI::log('Appended '.count($undocumented).' plugin(s) to the manifest — review the diff, set the right track, and commit.');
        } else {
            \WP_CLI::warning("Could not write to manifest: {$path}");
        }
    }

    if ($findings === 0) {
        \WP_CLI::success('Plugins match the manifest.');

        return;
    }

    $summary = "{$findings} plugin discrepanc".($findings === 1 ? 'y' : 'ies').' found.';
    if ($strict) {
        \WP_CLI::error($summary); // non-zero exit for CI gating
    }
    \WP_CLI::log($summary.' (warn-only)');
});
