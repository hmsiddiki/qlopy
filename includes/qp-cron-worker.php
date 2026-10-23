<?php
if (!defined('QLOPY_INIT')) { http_response_code(403); exit; }
// includes/qp-cron-worker.php
// Built-in background worker (core upgrade plan, step C2.3). Loaded only when work is due or when a worker request arrives.
//
// A visitor's page never runs scheduled work. When qp_cron_is_due() is true:
//   1. at shutdown, after the page has been sent (fastcgi_finish_request where it exists), the page makes a fire-and-forget
//      loopback POST to /?qp_cron_worker=1 with a one-time key, at most once per 30 seconds;
//   2. that request is a separate PHP request: it checks the key, answers 202 at once, takes a non-blocking lock and runs
//      one short slice of qp_cron_tick() (light work only), then stores the time it finished.
// Mode `after_response` (hosts that block loopback) runs a 2-second slice in the page's own process after the page is sent.
// Nothing runs while a real runner (crontab / external ping) has reported in during the last 15 minutes.

const QP_CRON_SPAWN_GAP = 30;      // seconds between worker starts
const QP_CRON_RUNNER_FRESH = 900;  // a real runner seen within this many seconds keeps the built-in worker quiet

function qp_cron_load_high(): bool {
    if (!function_exists('sys_getloadavg')) return false;
    $l = @sys_getloadavg();
    $limit = function_exists('apply_filters') ? (float)apply_filters('qp_cron_max_load', 8.0) : 8.0;
    return is_array($l) && isset($l[0]) && $l[0] > $limit;
}

function qp_cron_runner_is_fresh(array $state): bool {
    return !empty($state['runner_at']) && (time() - (int)$state['runner_at']) < QP_CRON_RUNNER_FRESH;
}

/** Decide, once per request, whether to start a worker after the page is sent. */
function qp_cron_worker_schedule(): void {
    static $done = false;
    if ($done) return;
    $mode = qp_cron_mode();
    if ($mode === 'off' || $mode === 'ping') return;
    $state = qp_cron_state();
    if (qp_cron_runner_is_fresh($state)) return;
    $last = max((int)($state['last_spawn'] ?? 0), (int)($state['last_end'] ?? 0));
    if ((time() - $last) < QP_CRON_SPAWN_GAP) return;
    if (qp_cron_load_high()) return;
    $done = true;
    // Without fastcgi_finish_request we release the visitor by sending the page with an exact Content-Length, which needs the page
    // held in a buffer until shutdown. Skipped for logged-in users: index.php's theme-preview bar owns the buffer for them.
    if (!function_exists('fastcgi_finish_request') && ob_get_level() === 0 && !(function_exists('is_logged_in') && is_logged_in())) ob_start();
    register_shutdown_function('qp_cron_worker_after_page', $mode);
}

/**
 * Send the page to the visitor and release the connection while this process keeps running.
 * With fastcgi_finish_request (PHP-FPM) that is one call. Without it (php-cgi, mod_php) the page is held in the output buffer
 * at this point, so we give it an exact Content-Length and close the connection: the browser has the whole page and stops
 * waiting. Returns true when the visitor has been released, false when it could not be done safely.
 */
function qp_cron_release_visitor(): bool {
    if (function_exists('fastcgi_finish_request')) {
        while (ob_get_level() > 0) { @ob_end_flush(); }
        @fastcgi_finish_request();
        return true;
    }
    if (headers_sent() || ob_get_level() < 1) return false;
    ignore_user_abort(true);
    while (ob_get_level() > 1) { @ob_end_flush(); }
    $len = ob_get_length();
    if ($len === false) return false;
    header('Content-Length: ' . $len);
    header('Connection: close');
    header('X-Accel-Buffering: no'); // nginx would otherwise hold the page until this process ends
    @ob_end_flush();
    @flush();
    return true;
}

/** Shutdown function: let the visitor go first, then do the work (worker request, or a short slice in this process). */
function qp_cron_worker_after_page(string $mode): void {
    try {
        $has_finish = function_exists('fastcgi_finish_request');
        // If the visitor could not be released (output already sent), the slice still runs, but shorter.
        $released = qp_cron_release_visitor();
        // Claim the slot under the state lock so concurrent page loads start only one worker.
        $key = bin2hex(random_bytes(16));
        $claimed = false;
        qp_cron_state_update(function ($s) use ($key, &$claimed) {
            $last = max((int)($s['last_spawn'] ?? 0), (int)($s['last_end'] ?? 0));
            if ((time() - $last) < QP_CRON_SPAWN_GAP) return null;
            $s['last_spawn'] = time();
            $s['worker_key'] = hash('sha256', $key);
            $s['worker_key_at'] = time();
            $claimed = true;
            return $s;
        });
        if (!$claimed) return;
        ignore_user_abort(true);
        // A separate worker request only helps when this process can be released and the server has another one free
        // (PHP-FPM). On single-process or non-FPM hosts the worker would just queue behind this page, so run the
        // short slice here instead; the visitor already has the page.
        if ($mode === 'after_response' || !$has_finish) {
            // Smaller counts than a worker request: this process is a page's own, and a stage cannot be interrupted once started.
            qp_cron_run_slice($released ? 2 : 1, ['tasks' => 3, 'posts' => 20, 'mail' => 5]);
            return;
        }
        if (!qp_cron_spawn_request($key)) {
            // Loopback failed: remember it (shown on the Cron screen) and fall back to a short slice in this process.
            qp_cron_state_update(function ($s) { $s['loopback_ok'] = false; $s['loopback_at'] = time(); return $s; });
            qp_cron_run_slice(2, ['tasks' => 3, 'posts' => 20, 'mail' => 5]);
        }
    } catch (Throwable $e) {
        error_log('qp cron worker schedule failed: ' . $e->getMessage());
    }
}

/** Fire-and-forget POST to the site's own worker URL over loopback. Returns false when the connection could not be made. */
function qp_cron_spawn_request(string $key): bool {
    $url = parse_url(SITE_URL);
    if (!$url || empty($url['host'])) return false;
    $https = (($url['scheme'] ?? 'http') === 'https');
    $port = (int)($url['port'] ?? ($https ? 443 : 80));
    $path = rtrim($url['path'] ?? '', '/') . '/?qp_cron_worker=1';
    $host = $url['host'] . (isset($url['port']) ? ':' . $url['port'] : '');
    $ctx = stream_context_create(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false, 'SNI_enabled' => true, 'peer_name' => $url['host']]]);
    $errno = 0; $err = '';
    // Loopback only: the key never leaves this machine.
    $fp = @stream_socket_client(($https ? 'ssl' : 'tcp') . '://127.0.0.1:' . $port, $errno, $err, 0.5, STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) return false;
    $body = 'key=' . $key;
    $req = "POST {$path} HTTP/1.1\r\nHost: {$host}\r\nUser-Agent: qp-cron-worker\r\nContent-Type: application/x-www-form-urlencoded\r\n"
         . 'Content-Length: ' . strlen($body) . "\r\nConnection: close\r\n\r\n" . $body;
    $w = @fwrite($fp, $req);
    if ($w === false || $w <= 0) { @fclose($fp); return false; }
    // Wait for the status line (the worker answers 202 right after the key check, before the slice), so the server has
    // really taken the request before we hang up. The page is already sent when fastcgi_finish_request exists.
    stream_set_timeout($fp, function_exists('fastcgi_finish_request') ? 1 : 0, function_exists('fastcgi_finish_request') ? 0 : 250000);
    $line = @fgets($fp, 64);
    @fclose($fp);
    return is_string($line) && strpos($line, ' 202') !== false;
}

/** One locked slice of light work. Returns the tick result, or null when another worker holds the lock or nothing may run. */
function qp_cron_run_slice(int $seconds = 5, array $counts = []): ?array {
    require_once __DIR__ . '/qp-cron.php';
    require_once __DIR__ . '/qp-cron-queue.php';
    $lock = @fopen(qp_cron_dir() . DIRECTORY_SEPARATOR . 'cron-lock', 'c');
    if (!$lock) return null;
    try {
        if (!flock($lock, LOCK_EX | LOCK_NB)) return null;
        if (qp_cron_runner_is_fresh(qp_cron_state())) { flock($lock, LOCK_UN); return null; }
        @set_time_limit(max(30, $seconds * 4));
        $res = qp_cron_tick($counts + ['seconds' => $seconds, 'tasks' => 10, 'posts' => 50, 'mail' => 20, 'light_only' => !qp_cron_worker_runs_heavy()]);
        qp_cron_state_update(function ($s) use ($res) {
            $s['last_end'] = time();
            $s['last_slice'] = [
                'at' => time(),
                'seconds' => $res['seconds'] ?? 0,
                'tasks' => count($res['queue']['processed'] ?? []),
                'posts' => count($res['posts']['published'] ?? []),
                'mail' => (int)($res['mail']['processed'] ?? 0),
                'errors' => count($res['errors'] ?? []) + count($res['queue']['errors'] ?? []),
            ];
            return $s;
        });
        flock($lock, LOCK_UN);
        return $res;
    } finally { fclose($lock); }
}

/** The worker request itself (index.php, after plugins and `qp_init`). Always ends the request. */
function qp_cron_worker_handle(): void {
    ignore_user_abort(true);
    $sent = (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['key']) && is_string($_POST['key'])) ? $_POST['key'] : '';
    $ok = false;
    if ($sent !== '') {
        // One-time key: valid for 60 seconds and removed on first use, so a copied request cannot be replayed.
        qp_cron_state_update(function ($s) use ($sent, &$ok) {
            if (!empty($s['worker_key']) && (time() - (int)($s['worker_key_at'] ?? 0)) <= 60
                && hash_equals((string)$s['worker_key'], hash('sha256', $sent))) {
                $ok = true;
                unset($s['worker_key'], $s['worker_key_at']);
                $s['loopback_ok'] = true; $s['loopback_at'] = time();
                return $s;
            }
            return null;
        });
    }
    if (!$ok) { http_response_code(403); header('Cache-Control: no-store'); exit; }
    http_response_code(202);
    header('Cache-Control: no-store');
    header('Connection: close');
    header('Content-Length: 0');
    while (ob_get_level() > 0) { @ob_end_flush(); }
    if (function_exists('fastcgi_finish_request')) @fastcgi_finish_request(); else @flush();
    if (qp_cron_load_high()) exit;
    try { qp_cron_run_slice(5); } catch (Throwable $e) { error_log('qp cron worker failed: ' . $e->getMessage()); }
    exit;
}

// ---- External ping (C2 step 5): a crontab-less way to run the same tick from a web-cron service ----

/** Settings: Cron screen > "External ping". Off until the owner enables it. */
function qp_cron_ping_enabled(): bool {
    return function_exists('get_option_meta') && filter_var(get_option_meta('qp_cron_ping_enabled'), FILTER_VALIDATE_BOOLEAN);
}

function qp_cron_ping_reply(int $code, array $body = []): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    if ($code === 429) header('Retry-After: 30');
    echo $body ? json_encode($body) : '';
    exit;
}

/**
 * `POST /?qp_cron=1` with the runner key in the body (`key=`) or the `X-QP-Cron-Key` header, or `GET /?qp_cron=<key>` for services
 * that can only GET (the key then appears in server logs: prefer POST). Runs one full tick (heavy tasks included, like a crontab).
 * Disabled -> 404. Wrong key -> 403 with no detail. Rate limits: 10 wrong keys per minute lock it for a minute; a run at most every 5 s.
 * Always ends the request.
 */
function qp_cron_ping_handle(): void {
    if (!qp_cron_ping_enabled()) qp_cron_ping_reply(404);
    require_once __DIR__ . '/qp-cron.php'; // qp_cron_get_key() lives here and is not loaded on a normal front-end request
    $expected = qp_cron_get_key();
    $sent = '';
    if (isset($_POST['key']) && is_string($_POST['key'])) $sent = $_POST['key'];
    elseif (!empty($_SERVER['HTTP_X_QP_CRON_KEY'])) $sent = (string)$_SERVER['HTTP_X_QP_CRON_KEY'];
    elseif (isset($_GET['qp_cron']) && is_string($_GET['qp_cron']) && $_GET['qp_cron'] !== '1') $sent = $_GET['qp_cron'];

    $verdict = 'ok';
    qp_cron_state_update(function ($s) use ($expected, $sent, &$verdict) {
        $now = time();
        $fails = array_values(array_filter($s['ping_fails'] ?? [], function ($t) use ($now) { return $t > $now - 60; }));
        if (count($fails) >= 10) { $verdict = 'locked'; $s['ping_fails'] = $fails; return $s; }
        if (!$expected || $sent === '' || !hash_equals((string)$expected, $sent)) {
            $fails[] = $now; $s['ping_fails'] = $fails; $verdict = 'bad_key'; return $s;
        }
        if (($now - (int)($s['ping_last'] ?? 0)) < 5) { $verdict = 'too_soon'; return null; }
        $s['ping_last'] = $now; $s['ping_fails'] = $fails;
        return $s;
    });
    if ($verdict === 'locked' || $verdict === 'too_soon') qp_cron_ping_reply(429);
    if ($verdict === 'bad_key') qp_cron_ping_reply(403);

    ignore_user_abort(true);
    @set_time_limit(120);
    require_once __DIR__ . '/qp-cron.php';
    require_once __DIR__ . '/qp-cron-queue.php';
    // Updater hooks are registered by updater.php (the CLI runner loads it too), so heavy update tasks have a handler.
    if (is_file(__DIR__ . '/updater.php')) { try { require_once __DIR__ . '/updater.php'; } catch (Throwable $_) {} }

    $lock = @fopen(qp_cron_dir() . DIRECTORY_SEPARATOR . 'cron-lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) qp_cron_ping_reply(200, ['status' => 'busy']);
    try {
        $res = qp_cron_tick(['seconds' => 50, 'tasks' => 50, 'posts' => 100, 'mail' => 50]);
        qp_cron_note_runner('ping');
        qp_cron_state_update(function ($s) use ($res) {
            $s['last_end'] = time();
            $s['last_ping_run'] = ['at' => time(), 'seconds' => $res['seconds'] ?? 0, 'tasks' => count($res['queue']['processed'] ?? []), 'posts' => count($res['posts']['published'] ?? []), 'mail' => (int)($res['mail']['processed'] ?? 0), 'errors' => count($res['errors'] ?? []) + count($res['queue']['errors'] ?? [])];
            return $s;
        });
    } finally { flock($lock, LOCK_UN); fclose($lock); }
    qp_cron_ping_reply(200, ['status' => 'ok', 'tasks' => count($res['queue']['processed'] ?? []), 'posts' => count($res['posts']['published'] ?? []), 'mail' => (int)($res['mail']['processed'] ?? 0), 'errors' => count($res['errors'] ?? []) + count($res['queue']['errors'] ?? []), 'seconds' => $res['seconds'] ?? 0]);
}
