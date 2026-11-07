<?php
if (!defined('QLOPY_INIT')) { http_response_code(403); exit; }
// includes/page-cache-early.php
// Page cache for anonymous visitors, early half (core upgrade plan, step C1e).
//
// index.php loads this file only when uploads/page-cache/state.json exists (i.e. the cache has been switched on), before auth.php,
// the theme and the plugins. It answers a request from a stored file or returns, and the normal request carries on.
// It reads one small JSON state file, builds a key, and reads at most one cache file: no database, no plugin code.
//
// What is never served from cache (reason shown in the X-QP-Cache header of the normal response):
//   a non-GET request; any request carrying a logged-in, preview, cart, notice or install cookie (state.bypass_cookies, extended
//   by plugins through qp_page_cache_bypass_cookie()); search, preview and cron query keys; the admin, ajax, install and cron
//   entry points; paths plugins registered through qp_page_cache_bypass_path(); AJAX and Authorization requests.
// The key is: generation, host, path, sorted query without tracking parameters, and the values of the vary cookies (currency,
// language), plus the device class when state.device_split is on. Host is part of the key because a plugin may rewrite URLs
// per host.
//
// File format: <uploads>/page-cache/pages/g<gen>/<aa>/<sha1 of path+query>/<sha1 of variant>.cache
//   line 1: JSON meta {t, status, ct, etag}; the rest: the page body. Never executed, never included.

const QP_PC_IGNORED_QUERY = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'fbclid', 'gclid', 'msclkid', 'mc_cid', 'mc_eid'];

function qp_pc_dir(array $config): string {
    return dirname(__DIR__) . DIRECTORY_SEPARATOR . ($config['uploads_dir'] ?? 'uploads') . DIRECTORY_SEPARATOR . 'page-cache';
}

function qp_pc_read_state(string $dir): array {
    $raw = @file_get_contents($dir . DIRECTORY_SEPARATOR . 'state.json');
    $a = $raw ? json_decode($raw, true) : null;
    return is_array($a) ? $a : [];
}

/** Read-modify-write the state file under an exclusive lock. $fn gets the array, returns the new one or null to leave it alone. */
function qp_pc_state_update(string $dir, callable $fn): array {
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    $fh = @fopen($dir . DIRECTORY_SEPARATOR . 'state.json', 'c+');
    if (!$fh) return [];
    $out = [];
    try {
        if (flock($fh, LOCK_EX)) {
            $raw = stream_get_contents($fh);
            $cur = $raw ? json_decode($raw, true) : null;
            $cur = is_array($cur) ? $cur : [];
            $new = $fn($cur);
            if (is_array($new)) { ftruncate($fh, 0); rewind($fh); fwrite($fh, json_encode($new)); fflush($fh); $out = $new; } else { $out = $cur; }
            flock($fh, LOCK_UN);
        }
    } finally { fclose($fh); }
    return $out;
}

/** Path of the request relative to the site's own folder, without query string. */
function qp_pc_request_path(array $config): string {
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    $path = is_string($path) ? rawurldecode($path) : '/';
    $base = rtrim((string)(parse_url((string)($config['site_url'] ?? ''), PHP_URL_PATH) ?? ''), '/');
    if ($base !== '' && stripos($path, $base) === 0) $path = substr($path, strlen($base));
    return '/' . trim($path, '/');
}

/** Normalised query string: tracking parameters removed, keys sorted. */
function qp_pc_request_query(): string {
    $q = [];
    parse_str((string)($_SERVER['QUERY_STRING'] ?? ''), $q);
    foreach (QP_PC_IGNORED_QUERY as $k) unset($q[$k]);
    ksort($q);
    return http_build_query($q);
}

function qp_pc_device_class(): string {
    $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
    return preg_match('/Mobi|Android|iPhone|iPad|iPod|Windows Phone/i', $ua) ? 'mobile' : 'desktop';
}

/** Why this request must not be cached or served from cache, or null when it may. */
function qp_pc_bypass_reason(array $state, array $config): ?string {
    if (empty($state['enabled'])) return 'off';
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') return 'method';
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) return 'ajax';
    if (!empty($_SERVER['HTTP_AUTHORIZATION']) || !empty($_SERVER['PHP_AUTH_USER'])) return 'auth';
    $names = $state['bypass_cookies'] ?? [];
    $prefixes = $state['bypass_cookie_prefixes'] ?? [];
    foreach ($_COOKIE as $name => $_v) {
        $name = (string)$name;
        if (in_array($name, $names, true)) return 'cookie:' . $name;
        foreach ($prefixes as $p) { if ($p !== '' && strpos($name, $p) === 0) return 'cookie:' . $name; }
    }
    $q = [];
    parse_str((string)($_SERVER['QUERY_STRING'] ?? ''), $q);
    foreach (($state['bypass_query'] ?? []) as $k) { if (array_key_exists($k, $q)) return 'query:' . $k; }
    $path = qp_pc_request_path($config);
    $admin = '/' . trim((string)($config['admin_dir'] ?? 'admin'), '/');
    if ($path === $admin || strpos($path, $admin . '/') === 0) return 'path:admin';
    if (preg_match('#^/(ajax|install|qp-cron)\.php$#i', $path) || preg_match('#\.php$#i', $path)) return 'path:script';
    foreach (($state['bypass_paths'] ?? []) as $prefix) {
        $prefix = '/' . trim((string)$prefix, '/');
        if ($path === $prefix || strpos($path, $prefix . '/') === 0) return 'path:' . $prefix;
    }
    return null;
}

/** The first language of the browser's Accept-Language header that is in $codes (primary tag, lower case), or '' (the order a cookie-less provider uses). */
function qp_pc_negotiated_language(array $codes): string {
    $h = (string)($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '');
    if ($h === '' || !$codes) return '';
    foreach (explode(',', $h) as $part) {
        $primary = strtolower(explode('-', trim(explode(';', trim($part))[0]))[0]);
        if (in_array($primary, $codes, true)) return $primary;
    }
    return '';
}

/** [directory key, file key] for the current request, or null when it has no valid key. */
function qp_pc_key(array $state, array $config): array {
    $dk = sha1(qp_pc_request_path($config) . '?' . qp_pc_request_query());
    $variant = [strtolower((string)($_SERVER['HTTP_HOST'] ?? ''))];
    foreach (($state['vary_cookies'] ?? []) as $name) {
        $v = $_COOKIE[$name] ?? '';
        $variant[] = $name . '=' . (is_string($v) ? substr($v, 0, 40) : '');
    }
    if (!empty($state['vary_al'])) $variant[] = 'al=' . qp_pc_negotiated_language((array)$state['vary_al']);
    if (!empty($state['device_split'])) $variant[] = 'dev=' . qp_pc_device_class();
    return [$dk, sha1(implode('|', $variant))];
}

function qp_pc_file(string $dir, array $state, string $dk, string $fk): string {
    return $dir . DIRECTORY_SEPARATOR . 'pages' . DIRECTORY_SEPARATOR . 'g' . (int)($state['gen'] ?? 1) . DIRECTORY_SEPARATOR
        . substr($dk, 0, 2) . DIRECTORY_SEPARATOR . $dk . DIRECTORY_SEPARATOR . $fk . '.cache';
}

/** Serve the request from the cache and exit, or return (and leave the reason in $GLOBALS['qp_pc_reason']). */
/** Next unix time of a daily purge at HH:MM:SS (server time zone). */
function qp_pc_next_daily(string $hms): int {
    $t = strtotime('today ' . $hms);
    if ($t === false) return 0;
    return $t <= time() ? (int)strtotime('tomorrow ' . $hms) : (int)$t;
}

// ---- form tokens in cached pages (core C14) ----
// A visitor's form token (nonce) is bound to that visitor's qp_csrf_seed cookie, so a stored page cannot carry one: it would
// be the first visitor's, and fail (or worse, be shared) for everyone else. While a page is stored, qp_create_nonce() records
// every visitor token it made; the stored copy holds a placeholder in its place (action and lifetime, nothing secret), and each
// visitor served from the cache gets fresh tokens of their own, made here from the site's salt and their own seed cookie.
// So any form of any theme or plugin keeps its nonce and the page is still cached; no code has to change for it.
// The tokens made here are exactly qp_create_nonce()'s anonymous v2 tokens (auth.php): admin/dev/page-cache-test.php checks
// that qp_verify_nonce() accepts them.

/** The placeholder a stored page holds instead of a visitor's token. Only [a-z0-9-]: safe in HTML, attributes, JS and URLs. */
function qp_pc_nonce_placeholder(string $action, int $ttl): string {
    return 'qp2-nonce-' . bin2hex($action) . '-' . $ttl . '-end';
}

/** Fresh tokens for this visitor in place of the placeholders. Sets the visitor's seed cookie when they have none. */
function qp_pc_fill_nonces(string $body, array $config): string {
    if (strpos($body, 'qp2-nonce-') === false) return $body;
    if (!preg_match_all('/qp2-nonce-((?:[0-9a-f]{2}){1,128})-(\d{1,9})-end/', $body, $m)) return $body;
    $salt = (string)($config['security_salt'] ?? '');
    $seed = $_COOKIE['qp_csrf_seed'] ?? '';
    if (!is_string($seed) || preg_match('/^[a-f0-9]{64}$/', $seed) !== 1) {
        $seed = bin2hex(random_bytes(32));
        $path = '/'; $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (string)($_SERVER['SERVER_PORT'] ?? '') === '443';
        $site = parse_url((string)($config['site_url'] ?? ''));
        if (is_array($site)) {
            if (!empty($site['path'])) $path = rtrim($site['path'], '/') ?: '/';
            if (strtolower((string)($site['scheme'] ?? '')) === 'https') $secure = true;
        }
        setcookie('qp_csrf_seed', $seed, time() + max(array_map('intval', $m[2])), $path, '', $secure, true);
        $_COOKIE['qp_csrf_seed'] = $seed;
    }
    $binding = hash_hmac('sha256', 'anonymous|' . $seed, $salt);
    $b64 = function (string $v): string { return rtrim(strtr(base64_encode($v), '+/', '-_'), '='); };
    $made = [];
    return (string)preg_replace_callback('/qp2-nonce-((?:[0-9a-f]{2}){1,128})-(\d{1,9})-end/', function ($x) use (&$made, $binding, $salt, $b64) {
        if (isset($made[$x[0]])) return $made[$x[0]];
        $payload = json_encode(['v' => 2, 'e' => time() + (int)$x[2], 'a' => (string)hex2bin($x[1]), 'k' => 'anonymous', 'u' => 0, 'b' => $binding]);
        $enc = $b64((string)$payload);
        return $made[$x[0]] = 'qp2.' . $enc . '.' . $b64(hash_hmac('sha256', 'qp_nonce_v2|' . $enc, $salt, true));
    }, $body);
}

function qp_pc_early_serve(array $config, string $dir): void {
    $state = qp_pc_read_state($dir);
    // A plugin asked for a purge at a fixed time of day (qp_page_cache_purge_daily): prices that change by date, for example.
    if (!empty($state['next_purge']) && time() >= (int)$state['next_purge']) {
        $state = qp_pc_state_update($dir, function ($s) {
            if (empty($s['next_purge']) || time() < (int)$s['next_purge']) return null;
            $s['gen'] = (int)($s['gen'] ?? 1) + 1; $s['purged_at'] = time();
            $s['next_purge'] = qp_pc_next_daily((string)($s['daily_purge'] ?? '00:00:05'));
            return $s;
        });
    }
    $GLOBALS['qp_pc_state'] = $state;
    $reason = qp_pc_bypass_reason($state, $config);
    if ($reason !== null) { $GLOBALS['qp_pc_reason'] = $reason; return; }
    [$dk, $fk] = qp_pc_key($state, $config);
    $file = qp_pc_file($dir, $state, $dk, $fk);
    $fh = @fopen($file, 'rb');
    if (!$fh) { $GLOBALS['qp_pc_reason'] = null; return; }
    $meta = json_decode((string)fgets($fh), true);
    $ttl = (int)($state['ttl'] ?? 21600);
    if (!is_array($meta) || empty($meta['t']) || (time() - (int)$meta['t']) > $ttl) { fclose($fh); $GLOBALS['qp_pc_reason'] = null; return; }
    $age = max(0, time() - (int)$meta['t']);
    $etag = (string)($meta['etag'] ?? '');
    // A page with form tokens is the same stored copy with this visitor's own tokens filled in (C14): never shared by a proxy,
    // never answered with 304 (the browser's copy holds older tokens), so no ETag.
    $dynamic = !empty($meta['nonces']);
    if ($dynamic) $etag = '';
    header('X-QP-Cache: HIT; age=' . $age . ($dynamic ? '; tokens' : ''));
    header('Cache-Control: ' . ($dynamic ? 'private, no-cache' : (string)($state['cache_control'] ?? 'public, max-age=0, must-revalidate')));
    if ($etag !== '') header('ETag: ' . $etag);
    if ($etag !== '' && isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim((string)$_SERVER['HTTP_IF_NONE_MATCH']) === $etag) {
        fclose($fh);
        http_response_code(304);
        exit;
    }
    header('Content-Type: ' . (string)($meta['ct'] ?? 'text/html; charset=UTF-8'));
    $body = stream_get_contents($fh);
    fclose($fh);
    if ($dynamic) $body = qp_pc_fill_nonces((string)$body, $config);
    header('Content-Length: ' . strlen($body));
    echo $body;
    exit;
}
