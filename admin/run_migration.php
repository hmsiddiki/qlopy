<?php
require_once __DIR__ . '/admin_head.php';
require_once __DIR__ . '/../db.php';

if (!check_permission('manage_options')) {
    echo "Access denied"; exit;
}

$migFile = __DIR__ . '/../sql/20251211_posts_publish_visibility_migration.sql';
if (!file_exists($migFile)) {
    echo "Migration file missing: $migFile"; exit;
}

$raw = file_get_contents($migFile);
if ($raw === false) { echo "Failed to read migration file"; exit; }

$prefix = $GLOBALS['db_table_prefix'] ?? ($config['db_table_prefix'] ?? 'qp_');
// Try to detect prefix from config
if (file_exists(__DIR__ . '/../config.php')) {
    $cfg = require __DIR__ . '/../config.php';
    if (!empty($cfg['db_table_prefix'])) $prefix = $cfg['db_table_prefix'];
}

$sql = str_replace('{{prefix}}', $prefix, $raw);
// Split statements on semicolon newline
$stmts = array_filter(array_map('trim', preg_split('/;\s*\n/', $sql)));
$pdo = db();
$errors = [];
foreach ($stmts as $s) {
    if (!$s) continue;
    try {
        $pdo->exec($s);
    } catch (Exception $e) {
        $errors[] = $e->getMessage();
    }
}

if (empty($errors)) {
    echo "Migration applied successfully.";
} else {
    echo "Migration finished with errors:\n";
    foreach ($errors as $err) echo "- " . htmlspecialchars($err) . "\n";
}
