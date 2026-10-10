<?php
// Mark as authorized entrypoint for included files
if (!defined('QLOPY_INIT')) define('QLOPY_INIT', true);
// ajax.php - central AJAX handler that triggers hooked callbacks

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/includes/ajax-hooks.php'; 
require_once __DIR__ . '/includes/qpmeta.php';
require_once __DIR__ . '/includes/query.php';
require_once __DIR__ . '/includes/post-types.php';

if (function_exists('qp_register_nonce_refresh_ajax_handler')) {
    qp_register_nonce_refresh_ajax_handler();
}
 

try {
    $cfg = require_once __DIR__ . '/config.php';
    $content_dir = $cfg['content_dir'] ?? 'content';
    $plugins_dir = $cfg['plugins_dir'] ?? 'plugins';
    $themes_dir = $cfg['themes_dir'] ?? 'themes';
    $plugin_dir = __DIR__ . "/{$content_dir}/{$plugins_dir}/";
    

    $stmt = $pdo->prepare("SELECT option_value FROM " . table_name('site_options') . " WHERE option_name = 'active_plugins' LIMIT 1");
    $stmt->execute();
    $active_plugins_json = $stmt->fetchColumn();
    $active_plugins = $active_plugins_json ? json_decode($active_plugins_json, true) : [];
    if (is_array($active_plugins) && is_dir($plugin_dir)) {
        foreach ($active_plugins as $plugin => $_) {
            $slug = is_string($plugin) && !is_numeric($plugin) ? $plugin : (is_string($_) ? $_ : null);
            if (!$slug) continue;
            $plugin_file = $plugin_dir . $slug . '/plugin.php';
            if (is_file($plugin_file)) include_once $plugin_file;
        }
    }
} catch (Throwable $_) {
    // best-effort: don't break AJAX on plugin-load failures
}

// Load active plugins so they can register global AJAX handlers (matches index.php behavior)
try {
    $cfg = require_once __DIR__ . '/config.php';
    $content_dir = $cfg['content_dir'] ?? 'content';
    $plugins_dir = $cfg['plugins_dir'] ?? 'plugins';
    $themes_dir = $cfg['themes_dir'] ?? 'themes';
    $plugin_dir = __DIR__ . "/{$content_dir}/{$plugins_dir}/";
    
    // Load theme and plugin system
$theme_dir_root = __DIR__ . "/$content_dir/$themes_dir/";
$stmt = $pdo->prepare("SELECT option_value FROM " . table_name('site_options') . " WHERE option_name = 'active_theme' LIMIT 1");
$stmt->execute();
$active_theme = $stmt->fetchColumn() ?: 'default';

$theme_dir = $theme_dir_root . $active_theme . '/';
if (!is_dir($theme_dir)) {
    $fallback_theme = 'default';
    if ($active_theme !== $fallback_theme) {
        $fallback_dir = $theme_dir_root . $fallback_theme . '/';
        if (is_dir($fallback_dir)) {
            $active_theme = $fallback_theme;
            $theme_dir = $fallback_dir;
        }
    }
}

// Include theme functions for the resolved active theme
// Ensure core query helpers are available for theme code


// Include theme functions for the resolved active theme
if (file_exists($theme_dir . 'functions.php')) {
    include_once $theme_dir . 'functions.php';
}



} catch (Throwable $_) {
    // best-effort: don't break AJAX on plugin-load failures
}
 qp_register_default_image_sizes(); ///added to ensure default image sizes are registered in the admin context and can be modified from themes and plugins by add_filter

header('Content-Type: application/json');

$action = $_REQUEST['action'] ?? null;

if (!$action) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'No AJAX action specified']);
    exit;
}

// Compose hook names for logged-in and guest (nopriv)
$hook_logged_in = "iitcm_ajax_{$action}";
$hook_nopriv = "iitcm_ajax_nopriv_{$action}";

// Dispatch with request data
$args = [$_REQUEST];

if (is_logged_in()) {
    if (!do_action($hook_logged_in, ...$args)) {
        http_response_code(404);
        echo json_encode(['status' => 'error', 'message' => 'No handler for: ' . $hook_logged_in]);
    }
} else {
    if (!do_action($hook_nopriv, ...$args)) {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'Unauthorized or no handler for: ' . $hook_nopriv]);
    }
}
exit;
