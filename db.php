<?php
// Prevent direct web access to DB credentials; include-only guard
if (!defined('QLOPY_INIT')) { http_response_code(403); exit; }

// db.php

// Load configuration once; reuse the same array via get_config()
global $config;
if (!isset($config) || !is_array($config)) {
    $config = require __DIR__ . '/config.php';
}

try {
    $pdo = new PDO(
        "mysql:host={$config['db_host']};dbname={$config['db_name']};charset=utf8mb4",
        $config['db_user'],
        $config['db_pass']
    );
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

// Database table prefix helper
if (!defined('DB_TABLE_PREFIX')) {
    define('DB_TABLE_PREFIX', $config['db_table_prefix'] ?? 'qp_');
}

function table_name(string $name): string {
    return DB_TABLE_PREFIX . $name;
}

// Simple accessor for loaded config array to avoid re-requiring config.php in hot helpers
if (!function_exists('get_config')) {
    function get_config(): array {
        global $config;
        return is_array($config) ? $config : [];
    }
}

// Simple accessor for PDO
function db(): PDO {
    global $pdo;
    return $pdo;
}
