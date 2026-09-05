<?php

/**
 * Third-party tag + consent injection (Google Tag Manager now; Iubenda later).
 *
 * Standard in every project, OFF by default: it only emits when the request host
 * is a production host (production_hosts() allowlist) AND a container ID is set.
 * A fresh project has neither, so it outputs nothing until you configure both.
 *
 * The gate is host-based, NOT WP_ENVIRONMENT_TYPE, so it survives Cloudways
 * clone / Push-to-Live / Pull-from-Staging: staging serves a different host (GTM
 * never leaks there) and a push-to-live keeps firing because live's host is
 * listed, whatever env value a sync carries.
 *
 * To enable on a project: (1) set the GTM container ID — edit gtm_id() below or
 * add `add_filter('sage-support/gtm_id', fn () => 'GTM-XXXXXXX')`; and (2) add
 * the project's production domain(s) to production_hosts() below (or via the
 * 'sage-support/production_hosts' filter).
 *
 * GTM serves GA4 and Search Console via tags configured INSIDE the container,
 * so only the GTM snippet lives here — no separate GA/GSC code. GTM container
 * IDs are public (visible in page source), so keeping the ID in code is fine.
 *
 * Iubenda / Google Consent Mode v2: the GTM head snippet is added at wp_head
 * priority 2 on purpose, leaving priority 1 free for a future consent-defaults
 * (denied) block + the Iubenda loader, which must run BEFORE gtm.js. When the
 * Iubenda embed is available, add a wp_head(…, 1) block behind the same
 * tags_enabled() gate — no restructuring needed.
 */

namespace Patterns\SageSupport;

if (! defined('ABSPATH')) {
    return;
}

/**
 * The GTM container ID. Filterable; returning '' disables all injection.
 * Empty by default — set it per project (see file header).
 */
function gtm_id(): string
{
    return (string) apply_filters('sage-support/gtm_id', '');
}

/**
 * Hosts that count as production — set this per project (also filterable via
 * 'sage-support/production_hosts'). Empty by default so a fresh project stays
 * off. This is the whole environment gate: staging serves a different host so
 * GTM never leaks there, and a push-to-live keeps firing because live's host is
 * listed — independent of WP_ENVIRONMENT_TYPE. Exact, case-insensitive match
 * against HTTP_HOST.
 */
function production_hosts(): array
{
    return array_map('strtolower', (array) apply_filters('sage-support/production_hosts', [
        // 'example.com',
        // 'www.example.com',
    ]));
}

/**
 * Load third-party tags only on a production host, and only when configured.
 */
function tags_enabled(): bool
{
    $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));

    return gtm_id() !== '' && in_array($host, production_hosts(), true);
}

/**
 * Google Tag Manager — <head> snippet, as high in <head> as possible.
 * Priority 2 reserves priority 1 for a future Consent Mode default block.
 */
add_action('wp_head', function () {
    if (! tags_enabled()) {
        return;
    }

    $id = gtm_id();
    ?>
<!-- Google Tag Manager -->
<script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':
new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],
j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src=
'https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);
})(window,document,'script','dataLayer','<?php echo esc_js($id); ?>');</script>
<!-- End Google Tag Manager -->
    <?php
}, 2);

/**
 * Google Tag Manager — <body> noscript fallback, immediately after <body>.
 */
add_action('wp_body_open', function () {
    if (! tags_enabled()) {
        return;
    }

    $id = gtm_id();
    ?>
<!-- Google Tag Manager (noscript) -->
<noscript><iframe src="https://www.googletagmanager.com/ns.html?id=<?php echo esc_attr($id); ?>"
height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>
<!-- End Google Tag Manager (noscript) -->
    <?php
});
