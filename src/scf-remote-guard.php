<?php

/**
 * SCF / ACF field-group schema guard for non-local environments.
 *
 * Field-group *definitions* (the schema) are version-controlled as Local JSON in
 * the theme's mu-plugin (`acf-json/`), which is symlinked into wp-content on local
 * and rsynced on deploy. Editing a field group in wp-admin on staging/production
 * therefore does NOT persist the way people expect: the next deploy overwrites the
 * JSON from git, while the `acf-field-group` post edited in that environment's DB
 * lingers — you get "sync available" divergence, not a clean save. Schema edits
 * belong on local, where the symlink writes straight back into git.
 *
 * Default: a non-blocking admin notice on the field-group editor whenever the
 * environment is not `local`. Opt-in per project (`sage-support/scf_guard_block`)
 * escalates to actually keeping people out of the editor there.
 *
 * Gated on `wp_get_environment_type()` (the inverse of update-policy): it does
 * nothing on `local`, and nothing unless the SCF/ACF field-group screens exist
 * (so it is naturally a no-op when SCF/ACF isn't active). Off entirely via
 * `sage-support/scf_guard_enabled` => false.
 */

namespace Patterns\SageSupport;

if (! defined('ABSPATH')) {
    return;
}

/**
 * The guard only makes sense away from local (where the symlink writes to git).
 */
function scf_guard_active(): bool
{
    if (wp_get_environment_type() === 'local') {
        return false;
    }

    return (bool) apply_filters('sage-support/scf_guard_enabled', true);
}

/**
 * Opt-in: actually block the editor instead of only warning. Off by default.
 */
function scf_guard_blocks(): bool
{
    return (bool) apply_filters('sage-support/scf_guard_block', false);
}

/**
 * True on the SCF/ACF field-group screens (editor, new, and the list).
 * `acf-field-group` is the post type SCF inherits from ACF.
 */
function is_scf_field_group_screen(?\WP_Screen $screen): bool
{
    return $screen instanceof \WP_Screen && $screen->post_type === 'acf-field-group';
}

/**
 * The explanatory notice, shown on every field-group screen off-local.
 */
add_action('admin_notices', function () {
    if (! scf_guard_active()) {
        return;
    }

    if (! is_scf_field_group_screen(get_current_screen())) {
        return;
    }

    $env = esc_html(wp_get_environment_type());
    $blocking = scf_guard_blocks();

    printf(
        '<div class="notice notice-%s"><p><strong>%s</strong> %s</p></div>',
        $blocking ? 'error' : 'warning',
        esc_html__('Field-group schema is edited on local, not here.', 'sage-support'),
        esc_html(sprintf(
            /* translators: %s: current environment type (e.g. "production"). */
            __('This is the "%s" environment. Changes to field-group definitions here do not persist — the next deploy overwrites the JSON from git. Edit the schema on local (where the mu-plugin is symlinked) and commit it. Field VALUES, on the other hand, are edited here as normal.', 'sage-support'),
            $env
        ))
    );
});

/**
 * Opt-in block: keep people out of the field-group editor/new screens off-local.
 * Runs at `current_screen` (before output, so the redirect is safe). The list
 * screen stays reachable read-only; only add/edit are bounced, carrying a flag so
 * the destination can explain why.
 */
add_action('current_screen', function (\WP_Screen $screen) {
    if (! scf_guard_active() || ! scf_guard_blocks()) {
        return;
    }

    if (! is_scf_field_group_screen($screen) || ! in_array($screen->base, ['post', 'post-new'], true)) {
        return;
    }

    wp_safe_redirect(admin_url('edit.php?post_type=acf-field-group&sage_support_scf_guard=1'));
    exit;
});

/**
 * When a block redirect lands on the list screen, say why.
 */
add_action('admin_notices', function () {
    if (! scf_guard_active() || ! scf_guard_blocks()) {
        return;
    }

    if (! isset($_GET['sage_support_scf_guard'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        return;
    }

    printf(
        '<div class="notice notice-error"><p>%s</p></div>',
        esc_html__('The field-group editor is disabled on this environment. Edit schema on local and deploy.', 'sage-support')
    );
});
