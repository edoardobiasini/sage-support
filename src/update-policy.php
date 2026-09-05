<?php

/**
 * Local update policy.
 *
 * composer.json (in the WP root, not this repo) is the source of truth for
 * pinned plugins; Cloudways SafeUpdates handles core and everything else on
 * staging/production. On local, every WP-admin path to an update is removed
 * so nothing can drift silently. See docs/DEPLOYMENT.md ("Update strategy").
 *
 * Requires `WP_ENVIRONMENT_TYPE` (or `WP_ENV`) set to "local" in the local
 * wp-config.php — this intentionally does nothing on staging/production.
 */

namespace Patterns\SageSupport;

if (! defined('ABSPATH')) {
    return;
}

if (wp_get_environment_type() !== 'local') {
    return;
}

// Kill background/silent auto-updates outright.
add_filter('auto_update_plugin', '__return_false');
add_filter('auto_update_theme', '__return_false');
add_filter('automatic_updater_disabled', '__return_true');

/**
 * Strip the update/install capabilities so wp-admin has nothing to render
 * ("Update now" links disappear) and a direct update-core.php/update.php
 * hit is refused server-side, not just hidden from the UI.
 */
add_filter('map_meta_cap', function ($caps, $cap) {
    $blocked = ['update_plugins', 'update_themes', 'update_core', 'install_plugins', 'install_themes'];

    return in_array($cap, $blocked, true) ? ['do_not_allow'] : $caps;
}, 10, 2);

// Explain why, right where someone would otherwise look for an Update button.
add_action('admin_notices', function () {
    $screen = get_current_screen();

    if (! $screen || ! in_array($screen->id, ['plugins', 'themes', 'update-core', 'dashboard'], true)) {
        return;
    }

    printf(
        '<div class="notice notice-warning"><p>%s</p></div>',
        esc_html__(
            'Updates are disabled on local by design. Composer-managed plugins update via `composer update <package>` + deploy; core and everything else update on staging/production via Cloudways SafeUpdates. See docs/DEPLOYMENT.md.',
            'sage-support'
        )
    );
});
