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
$res = qp_process_queue($limit);
echo "Processed: " . count($res['processed']) . " errors: " . count($res['errors']) . "\n";
if (!empty($res['processed'])) echo json_encode($res['processed'], JSON_PRETTY_PRINT) . "\n";

// Also process scheduled posts (publish them when their published_at has arrived)
$sch = qp_process_scheduled_posts($limit);
echo "Scheduled published: " . count($sch['published']) . " errors: " . count($sch['errors']) . "\n";
if (!empty($sch['published'])) echo json_encode($sch['published'], JSON_PRETTY_PRINT) . "\n";

// If the mail subsystem is present, attempt to process the mail queue as part of cron runs.
if (file_exists(__DIR__ . '/includes/qp-mail.php')) {
    require_once __DIR__ . '/includes/qp-mail.php';
    if (function_exists('qp_mail_process_queue')) {
        echo "Processing mail queue...\n";
        $mailSettings = function_exists('get_option_meta') ? get_option_meta('qp_mail_settings') : [];
        $maxAttempts = intval($mailSettings['queue_max_attempts'] ?? 5);
        $mailRes = qp_mail_process_queue(50, $maxAttempts, null);
        echo "Mail processed: " . ($mailRes['processed'] ?? 0) . " Sent: " . ($mailRes['sent'] ?? 0) . " Failed: " . ($mailRes['failed'] ?? 0) . "\n";
        if (!empty($mailRes['errors'])) {
            foreach ($mailRes['errors'] as $err) echo "Mail error: $err\n";
        }
    }
}

exit(0);
