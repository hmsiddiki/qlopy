<?php
// CLI runner for QP Cron: php qp-cron.php [--key=...] [--limit=10] [--http-url=...] [--core-only]
//
// When something is due it loads the site the way a page does (includes/qp-cron-boot.php: core, the active theme, the active
// plugins), so every scheduled task has its handler, the same as in the built-in worker and the external ping. A minute with
// nothing due loads nothing: a few cheap queries, then it stops.
// --core-only skips the theme and plugins (the old behaviour): for when a broken plugin stops the runner from starting. Tasks
// whose handlers live in a plugin or the theme are then retried and finally marked failed, instead of being reported as done.
if (php_sapi_name() !== 'cli') {
    echo "This script must be run from CLI.\n"; exit(1);
}

// Mark CLI entrypoint as authorized for include-only files
if (!defined('QLOPY_INIT')) define('QLOPY_INIT', true);

$config = require __DIR__ . '/config.php';
// Load site-specific constants so CLI cron runs see the same updater constants
// as web requests. This file is safe for site owners to edit and is not
// intended to be overwritten by the updater.
if (is_file(__DIR__ . '/includes/site-constants.php')) {
    require_once __DIR__ . '/includes/site-constants.php';
}
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/qp-cron-due.php';

$opts = getopt('', ['key::', 'limit::', 'http-url::', 'core-only']);
$key = $opts['key'] ?? null;
$limit = isset($opts['limit']) ? (int)$opts['limit'] : 10;
$http = $opts['http-url'] ?? null;
$coreOnly = isset($opts['core-only']);

/** One stored option, read straight from the table: the site is not loaded yet (get_option_meta() comes with auth.php). */
function qp_cron_cli_option(string $name) {
    try {
        $st = db()->prepare('SELECT option_value FROM ' . table_name('site_options') . ' WHERE option_name = ? LIMIT 1');
        $st->execute([$name]);
        $v = $st->fetchColumn();
    } catch (Throwable $_) { return null; }
    if ($v === false || $v === null) return null;
    $d = json_decode((string)$v, true);
    return $d === null ? $v : $d;
}

/** Anything for this run: a due or stuck task (heavy ones too), a scheduled post to publish, or mail waiting to go. */
function qp_cron_cli_has_work(): bool {
    $checks = [
        'SELECT 1 FROM ' . table_name('cron_queue') . " WHERE (status = 'pending' AND next_run <= " . time() . ") OR status = 'running' LIMIT 1",
        'SELECT 1 FROM ' . table_name('posts') . " WHERE status = 'scheduled' AND published_at <= UTC_TIMESTAMP() LIMIT 1",
        'SELECT 1 FROM ' . table_name('mail_queue') . ' WHERE next_attempt_at <= UTC_TIMESTAMP() LIMIT 1',
    ];
    foreach ($checks as $sql) {
        try { if (db()->query($sql)->fetchColumn()) return true; } catch (Throwable $_) { return true; }   // a missing table: let the tick sort it out
    }
    return false;
}

$expected = qp_cron_cli_option('qp_cron_key');
$expected = is_string($expected) ? trim(preg_replace('/^["\'\s]+|["\'\s]+$/u', '', $expected)) : null;
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

if (!qp_cron_cli_has_work()) {
    // Still a healthy runner: the built-in worker stays quiet for 15 minutes.
    qp_cron_note_runner('cli');
    echo "QP Cron runner: nothing due.\n";
    exit(0);
}

if ($coreOnly) {
    require_once __DIR__ . '/includes/cli-helpers.php';
    if (is_file(__DIR__ . '/' . ($config['uploads_dir'] ?? 'uploads') . '/page-cache/state.json')) require_once __DIR__ . '/includes/page-cache.php';
} else {
    require __DIR__ . '/includes/qp-cron-boot.php';   // file level on purpose: see the notes in that file
}
require_once __DIR__ . '/includes/qp-cron.php';
require_once __DIR__ . '/includes/qp-cron-queue.php';
require_once __DIR__ . '/includes/updater.php';

// One runner at a time: the same lock the built-in worker and the ping take, so a long run is never overlapped by the next minute's.
$lock = @fopen(qp_cron_dir() . DIRECTORY_SEPARATOR . 'cron-lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    qp_cron_note_runner('cli');
    echo "QP Cron runner: another run is in progress.\n";
    exit(0);
}

echo "QP Cron runner starting" . ($coreOnly ? ' (core only)' : '') . "...\n";
try {
    // Same function the built-in worker and the external ping run (includes/qp-cron.php).
    $res = qp_cron_tick(['seconds' => 3600, 'tasks' => $limit, 'posts' => $limit, 'mail' => 50]);
} finally {
    flock($lock, LOCK_UN); fclose($lock);
}
echo "Processed: " . count($res['queue']['processed']) . " errors: " . count($res['queue']['errors']) . "\n";
if (!empty($res['queue']['processed'])) echo json_encode($res['queue']['processed'], JSON_PRETTY_PRINT) . "\n";
foreach ($res['queue']['errors'] as $id => $err) echo "Task $id: $err\n";
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
