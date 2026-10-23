<?php
if (!defined('QLOPY_INIT')) { http_response_code(403); exit; }
// includes/seo-purge.php
// Deletes the cached sitemaps (uploads/seo-cache/sitemap*.xml) when something they list changes. Tiny and always safe to load: auth.php
// loads it in every entry point (admin, AJAX, cron, front end) once the cache folder exists, because the events that change content happen
// in requests where the SEO module itself is not loaded. The sitemap code is in seo-sitemap.php.

if (!function_exists('qp_seo_sitemap_purge')) {
    function qp_seo_sitemap_purge(): void {
        $d = function_exists('qp_uploads_base') ? qp_uploads_base() : dirname(__DIR__) . '/uploads';
        foreach (@glob($d . DIRECTORY_SEPARATOR . 'seo-cache' . DIRECTORY_SEPARATOR . 'sitemap*.xml') ?: [] as $f) @unlink($f);
    }
    /** Purge now and again when the request ends (a request that changed several things). */
    function qp_seo_sitemap_on_change(): void {
        static $armed = false;
        qp_seo_sitemap_purge();
        if (!$armed) { $armed = true; register_shutdown_function('qp_seo_sitemap_purge'); }
    }
    function qp_seo_option_matters(string $name): bool {
        foreach (['qp_cron', 'qp_schema', 'qp_search_mode', 'qp_page_cache', 'qp_seo_theme_scan', 'cron_', 'qlopy_update', 'qlopy_last', 'qlopy_allow_risky', 'qp_mail_queue', 'qp_mail_settings', 'rewrite_', '_transient'] as $p) {
            if (strpos($name, $p) === 0) return false;
        }
        return true;
    }
    if (!function_exists('add_action') && is_file(__DIR__ . '/ajax-hooks.php')) require_once __DIR__ . '/ajax-hooks.php';
    if (function_exists('add_action')) {
        $qp_seo_any = function (...$a) { qp_seo_sitemap_on_change(); };
        foreach (['save_post', 'delete_post', 'qp_post_published', 'set_post_thumbnail', 'delete_post_thumbnail', 'create_term', 'edit_term', 'delete_term'] as $qp_seo_hook) add_action($qp_seo_hook, $qp_seo_any, 10, 0);
        add_action('qp_option_updated', function ($name = '', $value = null) { if (is_string($name) && qp_seo_option_matters($name)) qp_seo_sitemap_on_change(); }, 10, 2);
        // Slug change -> automatic 301, and the daily 404 log cleanup: both need the whole module, loaded only when they happen.
        add_action('qp_post_slug_changed', function ($id = 0, $old = '', $new = '', $type = '') { require_once __DIR__ . '/seo.php'; if (!function_exists('qp_seo_on_slug_changed')) return; qp_seo_on_slug_changed($id, $old, $new, $type); }, 10, 4);
        add_action('qp_seo_404_prune', function () { require_once __DIR__ . '/seo.php'; if (function_exists('qp_seo_404_prune')) qp_seo_404_prune(); }, 10, 0);
        unset($qp_seo_any, $qp_seo_hook);
    }
}
