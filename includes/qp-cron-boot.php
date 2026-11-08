<?php
if (!defined('QLOPY_INIT')) { http_response_code(403); exit; }
// includes/qp-cron-boot.php
// Loads the site for the CLI cron runner (qp-cron.php) the way index.php loads it for a page, without the page: core, the active
// theme's functions.php and the active plugins, then `init`. Every scheduled task then has the same handlers it has in the built-in
// worker and the external ping, which run inside index.php. Left out: routing, the page cache, theme preview, SEO serving, sign-in.
//
// Include it at file level, never from inside a function: auth.php, the theme and plugins keep their state in global variables.
// qp-cron.php only includes it when something is due (qp_cron_cli_has_work()), so an idle minute loads none of this.
// Whatever the theme or a plugin prints while loading is discarded, so it cannot clutter the cron output.

if (!defined('QP_CRON_CLI')) define('QP_CRON_CLI', true);

// Request values that core and plugins read, taken from site_url: a CLI process has no host, address or request path.
$qp_cb_url = parse_url((string)($config['site_url'] ?? ''));
$qp_cb_path = rtrim((string)($qp_cb_url['path'] ?? ''), '/');
if (empty($_SERVER['HTTP_HOST']) && !empty($qp_cb_url['host'])) $_SERVER['HTTP_HOST'] = $qp_cb_url['host'] . (isset($qp_cb_url['port']) ? ':' . $qp_cb_url['port'] : '');
if (!isset($_SERVER['HTTPS']) && ($qp_cb_url['scheme'] ?? '') === 'https') $_SERVER['HTTPS'] = 'on';
if (!isset($_SERVER['REQUEST_URI'])) $_SERVER['REQUEST_URI'] = $qp_cb_path . '/';
if (!isset($_SERVER['REQUEST_METHOD'])) $_SERVER['REQUEST_METHOD'] = 'GET';
if (!isset($_SERVER['REMOTE_ADDR'])) $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['SCRIPT_NAME'] = $_SERVER['PHP_SELF'] = $qp_cb_path . '/qp-cron.php';
unset($qp_cb_url, $qp_cb_path);

ob_start();
try {
    $qp_cb_root = dirname(__DIR__);
    require_once $qp_cb_root . '/auth.php';
    // The core files a page loads before the theme and plugins, because they call them while they load (same list as the REST entry).
    require_once $qp_cb_root . '/includes/ajax-hooks.php';
    require_once $qp_cb_root . '/includes/post-types.php';
    require_once $qp_cb_root . '/' . qp_admin_dir() . '/admin-ajax-hook.php';
    foreach (['qpmeta', 'admin-menus', 'assets', 'rewrite', 'query-vars', 'query', 'comments'] as $qp_cb_inc) require_once $qp_cb_root . '/includes/' . $qp_cb_inc . '.php';
    unset($qp_cb_inc);

    // Theme first, then plugins: the order index.php uses.
    $qp_cb_theme = qp_option_raw('active_theme') ?: 'default';
    if (!is_dir(qp_themes_path($qp_cb_theme))) $qp_cb_theme = 'default';
    if (!defined('THEME_URL')) define('THEME_URL', SITE_URL . '/' . qp_content_dir() . '/' . qp_themes_dir() . '/' . $qp_cb_theme);
    $qp_cb_file = qp_themes_path($qp_cb_theme) . 'functions.php';
    if (is_file($qp_cb_file)) {
        try { include_once $qp_cb_file; } catch (Throwable $qp_cb_e) { error_log('qp-cron: theme ' . $qp_cb_theme . ' failed to load: ' . $qp_cb_e->getMessage()); }
    }

    // One plugin at a time: a plugin that throws is skipped and its hooks taken back; the rest still load (C10).
    $qp_cb_plugins = json_decode((string)(qp_option_raw('active_plugins') ?: '[]'), true);
    if (is_array($qp_cb_plugins)) {
        foreach ($qp_cb_plugins as $qp_cb_k => $qp_cb_v) {
            $qp_cb_slug = is_string($qp_cb_k) && !is_numeric($qp_cb_k) ? $qp_cb_k : (is_string($qp_cb_v) ? $qp_cb_v : null);
            if (!$qp_cb_slug || !is_file(qp_plugins_path($qp_cb_slug) . 'plugin.php')) continue;
            $qp_hooks_before = qp_hooks_snapshot($qp_cb_slug);
            try { include_once qp_plugins_path($qp_cb_slug) . 'plugin.php'; } catch (Throwable $qp_e) { qp_plugin_load_failed($qp_cb_slug, $qp_e, $qp_hooks_before); } finally { qp_hooks_log_stop(); }
        }
    }

    do_action('init');   // plugins that attach their scheduled-task handlers on init
} finally {
    ob_end_clean();
    unset($qp_cb_root, $qp_cb_theme, $qp_cb_file, $qp_cb_plugins, $qp_cb_k, $qp_cb_v, $qp_cb_slug, $qp_cb_e);
}
