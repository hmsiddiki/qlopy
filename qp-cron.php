<?php
// Simple CLI runner for QP Cron
if (php_sapi_name() !== 'cli') {
    echo "This script must be run from CLI.\n"; exit(1);
}

// Mark CLI entrypoint as authorized for include-only files
if (!defined('QLOPY_INIT')) define('QLOPY_INIT', true);

require __DIR__ . '/config.php';
// Load site-specific constants so CLI cron runs see the same updater constants
// as web requests. This file is safe for site owners to edit and is not
// intended to be overwritten by the updater.
if (is_file(__DIR__ . '/includes/site-constants.php')) {
    require_once __DIR__ . '/includes/site-constants.php';
}
require __DIR__ . '/db.php';
// Provide minimal option helpers for CLI (so cron can read/update site options)
require_once __DIR__ . '/includes/cli-helpers.php';
// Purge the page cache when scheduled work publishes something (only while the cache is on)
if (is_file(__DIR__ . '/' . ($config['uploads_dir'] ?? 'uploads') . '/page-cache/state.json')) require_once __DIR__ . '/includes/page-cache.php';
require __DIR__ . '/includes/qp-cron.php';
require __DIR__ . '/includes/qp-cron-queue.php';
require __DIR__ . '/includes/updater.php';


$opts = getopt('', ['key::','limit::','http-url::']);
$key = $opts['key'] ?? null;
$limit = isset($opts['limit']) ? (int)$opts['limit'] : 10;
$http = $opts['http-url'] ?? null;

$expected = qp_cron_get_key();
if ($expected && $key !== $expected) {
    echo "Invalid key\n"; exit(2);
}

if ($http) {
    // Simple HTTP trigger: POST key to provided URL
    $data = http_build_query(['key' => $key]);
    $opts_http = [
        'http' => [
            'method' => 'POST',
            'header' => "Content-type: application/x-www-form-urlencoded\r\n",
            'content' => $data,
            'timeout' => 10
        ]
    ];
    $ctx = stream_context_create($opts_http);
    $res = @file_get_contents($http, false, $ctx);
    echo "HTTP trigger response:\n" . ($res ?? '(no response)') . "\n";
    exit(0);
}

echo "QP Cron runner starting...\n";
// Same function the built-in worker and the external ping run (includes/qp-cron.php).
$res = qp_cron_tick(['seconds' => 3600, 'tasks' => $limit, 'posts' => $limit, 'mail' => 50]);
echo "Processed: " . count($res['queue']['processed']) . " errors: " . count($res['queue']['errors']) . "\n";
if (!empty($res['queue']['processed'])) echo json_encode($res['queue']['processed'], JSON_PRETTY_PRINT) . "\n";
echo "Scheduled published: " . count($res['posts']['published']) . " errors: " . count($res['posts']['errors']) . "\n";
if (!empty($res['posts']['published'])) echo json_encode($res['posts']['published'], JSON_PRETTY_PRINT) . "\n";
if ($res['mail'] !== null) {
    echo "Mail processed: " . ($res['mail']['processed'] ?? 0) . " Sent: " . ($res['mail']['sent'] ?? 0) . " Failed: " . ($res['mail']['failed'] ?? 0) . "\n";
    foreach (($res['mail']['errors'] ?? []) as $err) echo "Mail error: $err\n";
}
foreach ($res['errors'] as $err) echo "Cron error: $err\n";
// Tell the built-in worker a real runner is healthy (it then stays quiet for 15 minutes).
qp_cron_note_runner('cli');

exit(0);
