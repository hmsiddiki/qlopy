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

// End of cli-helpers.php
