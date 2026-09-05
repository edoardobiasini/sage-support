<?php

/**
 * Technical SEO — meta description, canonical, Open Graph/Twitter, JSON-LD,
 * favicon, and robots. Self-contained module (mirrors tag-manager.php):
 * everything is emitted from a single wp_head action + a few filters, with no
 * Blade/layout edit. Every value is filterable and ships EMPTY here, so a fresh
 * project is safe by default and you fill in per-project values.
 *
 * Host gating — shared with tag-manager.php via production_hosts() (the
 * `sage-support/production_hosts` filter): any request whose host is NOT a
 * production host is forced to noindex,nofollow and gets no canonical. Since the
 * production-host list ships empty, a brand-new project is noindex everywhere
 * until you add your production domain(s) — then indexing + canonical (and GTM)
 * switch on together. All emitted URLs are built from the canonical host
 * (`sage-support/canonical_url`, falling back to the site URL), not the request
 * host.
 *
 * To enable per project, set (via filters or by editing the defaults below):
 *   - sage-support/production_hosts  (in tag-manager.php) + sage-support/canonical_url
 *   - sage-support/meta_description, sage-support/og_image, sage-support/favicon
 *   - sage-support/org_name (+ org_vat_id / org_address / org_phone / org_same_as / org_logo)
 *   - sage-support/product_name (+ product_specs / product_brand / product_description)
 *   - sage-support/faq_items
 *   - sage-support/theme_color
 *
 * Left to WP core (not duplicated): <title> (title-tag), wp-sitemap.xml, the
 * virtual robots.txt.
 */

namespace Patterns\SageSupport;

if (! defined('ABSPATH')) {
    return;
}

/**
 * Is the current request served on a production host? Reuses the same allowlist
 * as GTM (production_hosts() in tag-manager.php) so indexing and tracking can
 * never drift apart. Both modules load together (same package, autoloaded via
 * composer files), so no function_exists guard is needed. Empty list ⇒ never
 * production ⇒ noindex until set.
 */
function is_production_request(): bool
{
    $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));

    return in_array($host, production_hosts(), true);
}

/** Canonical site origin, no trailing slash (falls back to the site URL). */
function canonical_origin(): string
{
    $url = (string) apply_filters('sage-support/canonical_url', '');

    return rtrim($url !== '' ? $url : home_url('/'), '/');
}

/** Absolute canonical URL for the current request, on the canonical host. */
function canonical_url(): string
{
    $path = strtok((string) ($_SERVER['REQUEST_URI'] ?? '/'), '?');

    return canonical_origin() . $path;
}

/** Meta description: a per-singular excerpt, else the site default. */
function meta_description(): string
{
    $default = (string) apply_filters('sage-support/meta_description', '');

    if (is_singular() && ! is_front_page()) {
        $excerpt = trim(wp_strip_all_tags(get_the_excerpt()));

        if ($excerpt !== '') {
            $default = $excerpt;
        }
    }

    return trim($default);
}

/** Social share image (~1200×630). Empty string = no image emitted. */
function og_image(): string
{
    return (string) apply_filters('sage-support/og_image', '');
}

/**
 * FAQ items ([['q' => …, 'a' => …], …]) — the single source shared by a FAQ
 * accordion (if the project has one) and the FAQPage JSON-LD. Empty by default.
 */
function faq_items(): array
{
    return (array) apply_filters('sage-support/faq_items', []);
}

/**
 * Organization schema. Returns [] when no name is configured (default), so a
 * fresh project emits none.
 */
function organization_schema(): array
{
    $name = (string) apply_filters('sage-support/org_name', '');
    if ($name === '') {
        return [];
    }

    $origin = canonical_origin();

    $org = [
        '@type' => 'Organization',
        '@id' => $origin . '/#organization',
        'name' => $name,
        'url' => $origin . '/',
    ];

    $logo = (string) apply_filters('sage-support/org_logo', '');
    if ($logo !== '') {
        $org['logo'] = $logo;
    }

    $vat = (string) apply_filters('sage-support/org_vat_id', '');
    if ($vat !== '') {
        $org['vatID'] = $vat;
    }

    $address = array_filter((array) apply_filters('sage-support/org_address', []));
    if ($address) {
        $org['address'] = $address;
    }

    $phone = (string) apply_filters('sage-support/org_phone', '');
    if ($phone !== '') {
        $org['telephone'] = $phone;
    }

    $sameAs = array_values(array_filter((array) apply_filters('sage-support/org_same_as', [])));
    if ($sameAs) {
        $org['sameAs'] = $sameAs;
    }

    return $org;
}

/** WebSite schema. */
function website_schema(): array
{
    $origin = canonical_origin();

    return [
        '@type' => 'WebSite',
        '@id' => $origin . '/#website',
        'name' => get_bloginfo('name'),
        'url' => $origin . '/',
        'inLanguage' => str_replace('_', '-', get_locale()),
        'publisher' => ['@id' => $origin . '/#organization'],
    ];
}

/** WebPage schema for the current request. */
function webpage_schema(): array
{
    $origin = canonical_origin();

    return [
        '@type' => 'WebPage',
        '@id' => canonical_url() . '#webpage',
        'url' => canonical_url(),
        'name' => wp_get_document_title(),
        'isPartOf' => ['@id' => $origin . '/#website'],
        'inLanguage' => str_replace('_', '-', get_locale()),
    ];
}

/** Product schema (homepage). Returns [] when no product name is configured. */
function product_schema(): array
{
    $name = (string) apply_filters('sage-support/product_name', '');
    if ($name === '') {
        return [];
    }

    $specs = (array) apply_filters('sage-support/product_specs', []);

    $props = array_map(fn ($s) => [
        '@type' => 'PropertyValue',
        'name' => $s['name'],
        'value' => $s['value'],
    ], $specs);

    $brand = (string) apply_filters('sage-support/product_brand', '');
    $image = og_image();

    return array_filter([
        '@type' => 'Product',
        'name' => $name,
        'description' => (string) apply_filters('sage-support/product_description', meta_description()),
        'image' => $image !== '' ? $image : null,
        'brand' => $brand !== '' ? ['@type' => 'Brand', 'name' => $brand] : null,
        'additionalProperty' => $props ?: null,
    ]);
}

/** FAQPage schema, mirroring the visible FAQ. Returns [] when there are no items. */
function faq_schema(): array
{
    $items = faq_items();
    if (! $items) {
        return [];
    }

    return [
        '@type' => 'FAQPage',
        'mainEntity' => array_map(fn ($f) => [
            '@type' => 'Question',
            'name' => $f['q'],
            'acceptedAnswer' => [
                '@type' => 'Answer',
                'text' => wp_strip_all_tags($f['a']),
            ],
        ], $items),
    ];
}

/** Assemble the JSON-LD @graph for the current request. */
function json_ld_graph(): array
{
    $graph = [
        organization_schema(),
        website_schema(),
        webpage_schema(),
    ];

    if (is_front_page()) {
        $graph[] = product_schema();
        $graph[] = faq_schema();
    }

    return array_values(array_filter($graph));
}

/*
 * Robots — force non-production hosts to noindex,nofollow. With an empty
 * production-host list, that's everywhere until you configure one.
 */
add_filter('wp_robots', function (array $robots): array {
    if (! is_production_request()) {
        $robots['noindex'] = true;
        $robots['nofollow'] = true;
        unset($robots['max-image-preview'], $robots['max-snippet'], $robots['max-video-preview']);
    }

    return $robots;
});

/*
 * Own the canonical (core's rel_canonical only fires on singular and uses the
 * request host); drop legacy discovery/generator tags from <head>.
 */
remove_action('wp_head', 'rel_canonical');
remove_action('wp_head', 'rsd_link');
remove_action('wp_head', 'wlwmanifest_link');
remove_action('wp_head', 'wp_generator');
remove_action('wp_head', 'wp_shortlink_wp_head');

/* Meta description + canonical + Open Graph/Twitter + favicon + JSON-LD. */
add_action('wp_head', function () {
    $desc = meta_description();
    $title = wp_get_document_title();
    $image = og_image();

    echo "\n";

    if ($desc !== '') {
        printf('<meta name="description" content="%s">' . "\n", esc_attr($desc));
    }

    if (is_production_request()) {
        printf('<link rel="canonical" href="%s">' . "\n", esc_url(canonical_url()));
    }

    // Open Graph
    printf('<meta property="og:type" content="%s">' . "\n", esc_attr(is_singular() && ! is_front_page() ? 'article' : 'website'));
    printf('<meta property="og:site_name" content="%s">' . "\n", esc_attr(get_bloginfo('name')));
    printf('<meta property="og:title" content="%s">' . "\n", esc_attr($title));
    if ($desc !== '') {
        printf('<meta property="og:description" content="%s">' . "\n", esc_attr($desc));
    }
    printf('<meta property="og:url" content="%s">' . "\n", esc_url(canonical_url()));
    printf('<meta property="og:locale" content="%s">' . "\n", esc_attr(get_locale()));
    if ($image !== '') {
        printf('<meta property="og:image" content="%s">' . "\n", esc_url($image));
    }

    // Twitter
    printf('<meta name="twitter:card" content="%s">' . "\n", esc_attr($image !== '' ? 'summary_large_image' : 'summary'));
    printf('<meta name="twitter:title" content="%s">' . "\n", esc_attr($title));
    if ($desc !== '') {
        printf('<meta name="twitter:description" content="%s">' . "\n", esc_attr($desc));
    }
    if ($image !== '') {
        printf('<meta name="twitter:image" content="%s">' . "\n", esc_url($image));
    }

    // Favicon (only when configured and no core Site Icon is set) + theme color.
    $favicon = (string) apply_filters('sage-support/favicon', '');
    if ($favicon !== '' && ! has_site_icon()) {
        printf('<link rel="icon" href="%s" type="image/svg+xml">' . "\n", esc_url($favicon));
    }
    $themeColor = (string) apply_filters('sage-support/theme_color', '');
    if ($themeColor !== '') {
        printf('<meta name="theme-color" content="%s">' . "\n", esc_attr($themeColor));
    }

    // JSON-LD structured data
    $graph = json_ld_graph();
    if ($graph) {
        echo '<script type="application/ld+json">'
            . wp_json_encode(['@context' => 'https://schema.org', '@graph' => $graph], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            . '</script>' . "\n";
    }
}, 5);

/*
 * Resource hints — preconnect to third-party origins (analytics, video embeds,
 * fonts) so the browser opens those connections early. The host list is
 * project-specific, so it ships empty; the consuming theme supplies origins via
 * the `sage-support/preconnect_hosts` filter (an array of full origins, e.g.
 * 'https://www.googletagmanager.com'). No-op until configured.
 */
add_filter('wp_resource_hints', function (array $hints, string $relation): array {
    if ($relation !== 'preconnect') {
        return $hints;
    }

    $hosts = array_values(array_filter((array) apply_filters('sage-support/preconnect_hosts', [])));

    return array_merge($hints, $hosts);
}, 10, 2);
