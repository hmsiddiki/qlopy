<?php
if (!defined('QLOPY_INIT')) { http_response_code(403); exit; }
// includes/cli-helpers.php
// Minimal helpers intended for CLI usage (cron runner). Exposes only
// option-get/set helpers without loading the full `auth.php` stack.

if (!function_exists('get_option_meta')) {
    function get_option_meta(string $option_name) {
        if (!function_exists('db') || !function_exists('table_name')) return null;
        try {
            $pdo = db();
            $stmt = $pdo->prepare('SELECT option_value FROM ' . table_name('site_options') . ' WHERE option_name = ? LIMIT 1');
            $stmt->execute([$option_name]);
            $v = $stmt->fetchColumn();
            if ($v === false || $v === null) return null;
            $decoded = json_decode($v, true);
            return is_null($decoded) ? $v : $decoded;
        } catch (Throwable $_e) {
            return null;
        }
    }
}

if (!function_exists('update_option_meta')) {
    function update_option_meta(string $option_name, $option_value) {
        if (!function_exists('db') || !function_exists('table_name')) return false;
        try {
            $pdo = db();
            $json = json_encode($option_value);
            $sql = 'INSERT INTO ' . table_name('site_options') . ' (option_name, option_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE option_value=VALUES(option_value)';
            $stmt = $pdo->prepare($sql);
            return (bool)$stmt->execute([$option_name, $json]);
        } catch (Throwable $_e) {
            return false;
        }
    }
}

// Folder-name helpers (the same ones auth.php defines for web requests). The cron runner never loads
// auth.php, so updater.php's qp_content_dir()/qp_themes_dir()/... were undefined here and every
// qlopy_update_checker run failed with "Call to undefined function qp_content_dir()".
if (!function_exists('qp_dir_name')) {
    function qp_dir_name(string $key): string {
        static $defaults = ['admin_dir' => 'admin', 'content_dir' => 'content', 'themes_dir' => 'themes', 'plugins_dir' => 'plugins', 'uploads_dir' => 'uploads'];
        $cfg = $GLOBALS['config'] ?? (function_exists('get_config') ? get_config() : []);
        $v = is_array($cfg) ? ($cfg[$key] ?? null) : null;
        return (is_string($v) && $v !== '.' && $v !== '..' && preg_match('/^[A-Za-z0-9_.-]+$/', $v)) ? $v : ($defaults[$key] ?? '');
    }
    function qp_admin_dir(): string { return qp_dir_name('admin_dir'); }
    function qp_content_dir(): string { return qp_dir_name('content_dir'); }
    function qp_themes_dir(): string { return qp_dir_name('themes_dir'); }
    function qp_plugins_dir(): string { return qp_dir_name('plugins_dir'); }
    function qp_uploads_dirname(): string { return qp_dir_name('uploads_dir'); }
}

// End of cli-helpers.php
