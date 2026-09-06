<?php

/**
 * Serve missing media from production — local only.
 *
 * `wp-content/uploads/` is in no sync lane: it isn't in git, isn't in the deploy
 * rsync, and `bin/db-sync.sh pull` brings the attachment *rows* but not the
 * *files*. So a local site restored from a production DB shows broken images for
 * every attachment whose file was never downloaded. This rewrites the URL of any
 * upload that is missing on disk to the production origin, so media "just works"
 * locally with zero download. Files that DO exist locally are served locally,
 * untouched.
 *
 * Gated on `wp_get_environment_type() === 'local'` AND a configured production
 * origin (`sage-support/uploads_fallback_url`, e.g. https://example.com). Empty
 * origin ⇒ no-op (safe default). Assumes the classic wp-content layout, so the
 * `/wp-content/uploads/...` path is identical on both sides — only the origin
 * swaps. For the physical copy instead of the redirect, use
 * `bin/db-sync.sh pull --with-uploads`.
 */

namespace Patterns\SageSupport;

if (! defined('ABSPATH')) {
    return;
}

/**
 * Production origin to borrow missing media from (scheme + host, no trailing
 * slash). Empty ⇒ feature off.
 */
function uploads_fallback_origin(): string
{
    return rtrim((string) apply_filters('sage-support/uploads_fallback_url', ''), '/');
}

if (wp_get_environment_type() === 'local' && uploads_fallback_origin() !== '') {
    /**
     * Rewrite one uploads URL to production when its file is missing locally.
     * Returns the URL unchanged if it isn't a local upload or the file exists.
     */
    function rewrite_missing_upload(string $url): string
    {
        static $baseurl = null, $basedir = null;

        if ($baseurl === null) {
            $dir = wp_get_upload_dir();
            $baseurl = $dir['baseurl'];
            $basedir = $dir['basedir'];
        }

        if ($url === '' || strpos($url, $baseurl) !== 0) {
            return $url; // not a local upload
        }

        $path = wp_parse_url($url, PHP_URL_PATH);
        if (! is_string($path) || $path === '') {
            return $url;
        }

        $relative = substr($url, strlen($baseurl));
        $relative = strtok($relative, '?'); // drop any query before touching disk
        if (is_string($relative) && $relative !== '' && file_exists($basedir . $relative)) {
            return $url; // present locally — serve local
        }

        $new = uploads_fallback_origin().$path;
        $query = wp_parse_url($url, PHP_URL_QUERY);

        return $query ? $new.'?'.$query : $new;
    }

    add_filter('wp_get_attachment_url', function ($url) {
        return is_string($url) ? rewrite_missing_upload($url) : $url;
    }, 20);

    add_filter('wp_get_attachment_image_src', function ($image) {
        if (is_array($image) && isset($image[0]) && is_string($image[0])) {
            $image[0] = rewrite_missing_upload($image[0]);
        }

        return $image;
    }, 20);

    add_filter('wp_calculate_image_srcset', function ($sources) {
        if (is_array($sources)) {
            foreach ($sources as $width => $source) {
                if (isset($source['url']) && is_string($source['url'])) {
                    $sources[$width]['url'] = rewrite_missing_upload($source['url']);
                }
            }
        }

        return $sources;
    }, 20);
}
