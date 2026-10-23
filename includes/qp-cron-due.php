<?php
if (!defined('QLOPY_INIT')) { http_response_code(403); exit; }
// includes/qp-cron-due.php
// "Next due" value for the scheduler (core upgrade plan, step C2.2). Loaded from auth.php; tiny on purpose.
//
// One integer (unix time) says when the scheduler next has work. A page load compares time() with it: no database query.
//   qp_cron_next_due(): ?int            stored value, or null when unknown (treated as "due" so the next tick rebuilds it)
//   qp_cron_is_due(): bool              true when work may be due now (or the value is unknown)
//   qp_cron_note_work(int $when)        a writer added work due at $when: lower the value if needed (never raises it)
//   qp_cron_recompute_next_due(): int   rebuild from the queue, scheduled posts and the mail queue; called at the end of every tick
// Storage: APCu when present (per-site key), otherwise uploads/cron-next.txt. The value is never trusted for more than 5 minutes
// (the recompute caps it at now + 300), so a lost write cannot hide work for long.

if (defined('QP_CRON_DUE_LOADED')) return;
define('QP_CRON_DUE_LOADED', true);

/** Directory for the scheduler's small files. Works from the CLI runner too (auth.php, and so qp_uploads_base(), is not loaded there). */
function qp_cron_dir(): string {
    if (function_exists('qp_uploads_base')) return qp_uploads_base();
    global $config;
    $base = dirname(__DIR__) . DIRECTORY_SEPARATOR . ((is_array($config ?? null) ? ($config['uploads_dir'] ?? null) : null) ?: 'uploads');
    if (!is_dir($base)) @mkdir($base, 0775, true);
    return $base;
}

function qp_cron_due_file(): string {
    return qp_cron_dir() . DIRECTORY_SEPARATOR . 'cron-next.txt';
}

function qp_cron_due_apcu_key(): string {
    $cfg = function_exists('get_config') ? get_config() : [];
    return 'qp_cron_next:' . substr(md5((string)($cfg['security_salt'] ?? '') . '|' . __DIR__), 0, 8);
}

function qp_cron_due_use_apcu(): bool {
    return function_exists('apcu_fetch') && filter_var(ini_get('apc.enabled'), FILTER_VALIDATE_BOOLEAN)
        && (PHP_SAPI !== 'cli' || filter_var(ini_get('apc.enable_cli'), FILTER_VALIDATE_BOOLEAN));
}

function qp_cron_next_due(): ?int {
    if (qp_cron_due_use_apcu()) {
        $v = apcu_fetch(qp_cron_due_apcu_key(), $ok);
        if ($ok && is_int($v)) return $v;
    }
    $raw = @file_get_contents(qp_cron_due_file());
    if ($raw === false) return null;
    $raw = trim($raw);
    return ctype_digit($raw) ? (int)$raw : null;
}

function qp_cron_is_due(): bool {
    $n = qp_cron_next_due();
    return $n === null || $n <= time();
}

function qp_cron_due_write(int $ts): void {
    if (qp_cron_due_use_apcu()) @apcu_store(qp_cron_due_apcu_key(), $ts);
    @file_put_contents(qp_cron_due_file(), (string)$ts, LOCK_EX);
}

function qp_cron_note_work(int $when): void {
    try {
        $cur = qp_cron_next_due();
        // Unknown means "due" already, and the next tick rebuilds it; do not invent a value here.
        if ($cur === null) return;
        if ($when < $cur) qp_cron_due_write($when);
    } catch (Throwable $_) {}
}

function qp_cron_recompute_next_due(): int {
    $now = time();
    $next = $now + 300;
    try {
        $pdo = db();
        // Queued tasks: earliest pending run; a task stuck in 'running' counts as due now so the tick can recover it.
        if (function_exists('qp_cron_queue_table')) {
            $q = qp_cron_queue_table();
            // Heavy hooks never run from the built-in worker, so they must not keep the value in the past (they would
            // make every page load spawn a worker that has nothing to do). A real crontab runs them regardless.
            $skip = '';
            if (!qp_cron_worker_runs_heavy()) {
                $heavy = qp_cron_heavy_hooks();
                $skip = ' AND heavy = 0' . ($heavy ? ' AND hook NOT IN (' . implode(',', array_map([$pdo, 'quote'], $heavy)) . ')' : '');
            }
            $st = $pdo->query("SELECT MIN(next_run) FROM {$q} WHERE status = 'pending'{$skip}");
            $v = $st ? $st->fetchColumn() : false;
            if ($v !== false && $v !== null) $next = min($next, max((int)$v, 0));
        }
        // Scheduled posts: seconds until the earliest published_at, measured by the database clock (the publisher compares with UTC_TIMESTAMP()).
        $st = $pdo->query("SELECT TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), MIN(published_at)) FROM " . table_name('posts') . " WHERE status = 'scheduled'");
        $v = $st ? $st->fetchColumn() : false;
        if ($v !== false && $v !== null) $next = min($next, $now + (int)$v);
        // Mail queue: same way. A missing table just means no mail queue.
        try {
            $st = $pdo->query("SELECT TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), MIN(next_attempt_at)) FROM " . (function_exists('qp_mail_table') ? qp_mail_table('queue') : table_name('mail_queue')));
            $v = $st ? $st->fetchColumn() : false;
            if ($v !== false && $v !== null) $next = min($next, $now + (int)$v);
        } catch (Throwable $_) {}
    } catch (Throwable $_) {
        $next = $now; // could not tell: stay "due" so the next tick tries again
    }
    qp_cron_due_write($next);
    return $next;
}

/**
 * Hooks that must not run from a visitor-triggered worker: they download, unpack or replace files (the updater).
 * A real crontab, the Cron screen's Run button and the external ping run them. Plugins add theirs with the filter
 * `qp_cron_heavy_hooks`. (A per-task `heavy` argument on qp_queue_task is planned in C2 step 4.)
 */
function qp_cron_heavy_hooks(): array {
    $h = ['qlopy_do_update', 'qlopy_update_checker', 'qlopy_update_checker_login', 'qlopy_update_initiator'];
    if (function_exists('apply_filters')) {
        $f = apply_filters('qp_cron_heavy_hooks', $h);
        if (is_array($f)) $h = array_values(array_unique(array_filter(array_map('strval', $f), 'strlen')));
    }
    return $h;
}

// ---- small shared state (uploads/cron-state.json): last spawn, last finish, last real-runner run, one-time worker key ----

function qp_cron_state_file(): string { return qp_cron_dir() . DIRECTORY_SEPARATOR . 'cron-state.json'; }

function qp_cron_state(): array {
    $raw = @file_get_contents(qp_cron_state_file());
    $a = $raw ? json_decode($raw, true) : null;
    return is_array($a) ? $a : [];
}

/** Read-modify-write under an exclusive lock. $fn receives the state array and returns the new one, or null to leave it unchanged. */
function qp_cron_state_update(callable $fn): array {
    $fh = @fopen(qp_cron_state_file(), 'c+');
    if (!$fh) return [];
    $out = [];
    try {
        if (flock($fh, LOCK_EX)) {
            $raw = stream_get_contents($fh);
            $cur = $raw ? json_decode($raw, true) : null;
            $cur = is_array($cur) ? $cur : [];
            $new = $fn($cur);
            if (is_array($new)) {
                ftruncate($fh, 0); rewind($fh); fwrite($fh, json_encode($new)); fflush($fh);
                $out = $new;
            } else {
                $out = $cur;
            }
            flock($fh, LOCK_UN);
        }
    } finally { fclose($fh); }
    return $out;
}

/** Record that a real runner (CLI crontab, external ping) just ran: the built-in worker then stays quiet for 15 minutes. */
function qp_cron_note_runner(string $who): void {
    qp_cron_state_update(function ($s) use ($who) { $s['runner'] = $who; $s['runner_at'] = time(); return $s; });
}

/** Settings > General > "also run heavy tasks from the built-in worker" (default off). */
function qp_cron_worker_runs_heavy(): bool {
    return function_exists('get_option_meta') && filter_var(get_option_meta('qp_cron_heavy_in_worker'), FILTER_VALIDATE_BOOLEAN);
}

/** Scheduler mode from Settings: worker (default) | after_response | ping | off. */
function qp_cron_mode(): string {
    $m = function_exists('get_option_meta') ? get_option_meta('qp_cron_mode') : null;
    return in_array($m, ['worker', 'after_response', 'ping', 'off'], true) ? $m : 'worker';
}

/**
 * Called once per front-end page load (index.php). When nothing is due it costs one small file read.
 * When work is due it schedules the actual work for after the page has been sent (see includes/qp-cron-worker.php).
 */
function qp_cron_page_trigger(): void {
    if (PHP_SAPI === 'cli') return;
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($method !== 'GET' && $method !== 'HEAD') return;
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) return;
    if (!qp_cron_is_due()) return;
    require_once __DIR__ . '/qp-cron-worker.php';
    qp_cron_worker_schedule();
}
