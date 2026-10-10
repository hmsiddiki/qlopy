<?php
if (!defined('QLOPY_INIT')) { http_response_code(403); exit; }
$config = require __DIR__ . '/config.php';
if(defined('QP_DEBUG') && QP_DEBUG) {
$uploadDir = (function_exists('get_config') ? get_config()['uploads_dir'] ?? 'uploads' : 'uploads');
    ini_set('display_errors', '1');
   
} else {
     error_reporting(0);
    ini_set('display_errors', '0');
}
// Minimal translation function fallback to avoid fatal errors when themes/plugins
// call `__()` before an i18n system is loaded.
if (!function_exists('__')) {
    function __($text, $domain = null) {
        return $text;
    }
}
function getBaseUrl() {
    // Determine the protocol
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
    // Get the host name
    $host = $_SERVER['HTTP_HOST'];
    // Get the base directory path of the script
    $path = rtrim(dirname($_SERVER['PHP_SELF']), '/\\');
    
    // Construct the base URL and add a trailing slash
    $baseUrl = "$protocol://$host$path/";

    return $baseUrl;
}

define('SITE_URL', rtrim($config['site_url'], '/'));
define('PLUGIN_URL', SITE_URL . "/{$config['content_dir']}/{$config['plugins_dir']}");
//define('SITE_URL', rtrim(getBaseUrl(), '/'));
// Load site-specific constants (updater manifest URL, version, etc.)
// This file is safe to edit per-site and is not intended to be overwritten
// by the updater itself.
$site_constants = __DIR__ . '/includes/site-constants.php';
if (is_file($site_constants)) {
    require_once $site_constants;
}
// Ensure core mail helpers are available for frontend, admin and AJAX handlers.
// Requiring the file here makes `qp_mail()` present to all entry points
// (`index.php`, `ajax.php`, admin pages) without duplicate requires.
$qp_mail_file = __DIR__ . '/includes/qp-mail.php';
if (is_file($qp_mail_file)) {
    require_once $qp_mail_file;
}
// Helper: return site home URL (similar to WP `home_url()` / `get_home_url()`)
if (!function_exists('get_home_url')) {
    function get_home_url($path = '', $scheme = null) {
        $base = defined('SITE_URL') && SITE_URL ? SITE_URL : '';
        if (!$base) {
            $scheme = $scheme ?? ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http');
            $host = $_SERVER['HTTP_HOST'] ?? '';
            $base = rtrim($scheme . '://' . $host, '/');
        }
        $base = rtrim($base, '/');
        // Allow plugins to override the computed home/permalink base (host + subpath).
        // Plugins can hook `permalink_base` to return an alternate base URL.
        if (function_exists('apply_filters')) {
            $base = apply_filters('permalink_base', $base, 'home');
        } elseif (function_exists('apply_filter')) {
            $base = apply_filter('permalink_base', $base, 'home');
        }
        if ($path) {
            return $base . '/' . ltrim($path, '/');
        }
        return $base;
    }
    function home_url($path = '', $scheme = null) { return get_home_url($path, $scheme); }
}

// -----------------------------------------------------------------------------
// Logout helpers (global)
// -----------------------------------------------------------------------------
if (!function_exists('qp_logout')) {
    /**
     * Logout current user by revoking the session token in cookie and clearing cookie.
     * If $redirect is provided, sends a Location header and exits.
     * Returns true on success.
     */
    function qp_logout(?string $redirect = null): bool {
        if (PHP_SAPI === 'cli') return false;
        // Use centralized helper when available
        if (function_exists('qp_clear_auth_cookie')) {
            qp_clear_auth_cookie(true);
        } else {
            // best-effort fallback
            $cookie = $_COOKIE[QP_SESSION_COOKIE] ?? '';
            if ($cookie) {
                $parts = explode('|', $cookie);
                $token_id = $parts[0] ?? '';
                $user_id = isset($parts[1]) ? (int)$parts[1] : null;
                if ($token_id !== '' && function_exists('revoke_session_token')) {
                    try { revoke_session_token($token_id, $user_id); } catch (Throwable $_) {}
                }
            }
            setcookie(QP_SESSION_COOKIE, '', time() - 42000, '/', '', false, true);
            unset($_COOKIE[QP_SESSION_COOKIE]);
        }
        if ($redirect) {
            // sanitize redirect: allow only local paths or absolute same-host
            $r = $redirect;
            // Basic safety: prevent CRLF and javascript: schemes
            if (preg_match('/^(https?:)?\/\//i', $r)) {
                // allow absolute only if host matches
                $host = $_SERVER['HTTP_HOST'] ?? '';
                $u = @parse_url($r);
                if (empty($u) || (isset($u['host']) && $u['host'] !== $host)) {
                    $r = '/';
                }
            } elseif (strpos($r, '\n') !== false || stripos($r, 'javascript:') !== false) {
                $r = '/';
            }
            header('Location: ' . $r);
            exit;
        }
        return true;
    }
}

if (!function_exists('qp_logout_url')) {
    /**
     * Build a logout URL that will trigger qp_logout when visited.
     * Pass optional $redirect to have the logout handler redirect afterwards.
     */
    function qp_logout_url(?string $redirect = null): string {
        $base = (isset($_SERVER['REQUEST_URI']) ? strtok($_SERVER['REQUEST_URI'], '?') : '/') ?: '/';
        $params = ['logout' => '1'];
        if ($redirect) $params['redirect'] = $redirect;
        return $base . (strpos($base, '?') === false ? '?' : '&') . http_build_query($params);
    }
}

if (!function_exists('qp_logout_link')) {
    /**
     * Echo or return a logout anchor. If $echo is true, prints directly.
     */
    function qp_logout_link(string $text = 'Logout', ?string $redirect = null, bool $echo = true): string {
        $url = qp_logout_url($redirect);
        $a = '<a href="' . htmlspecialchars($url) . '">' . htmlspecialchars($text) . '</a>';
        if ($echo) { echo $a; }
        return $a;
    }
}

// auth.php

// NOTE: Legacy PHP session handler (SQLite/file-based) removed. The application
// now uses token-based authentication stored in user_meta (`session_tokens`) and
// verified via signed cookies. The legacy `data/` session storage can be deleted
// after this migration is validated and backups are taken.

// Token-based session signatures use the `security_salt`. If the salt changes,
// existing tokens will naturally fail validation (HMAC mismatch) so no explicit
// PHP session invalidation is required.
// Ensure session cookie constant is available before including other modules
if (!defined('QP_SESSION_COOKIE')) define('QP_SESSION_COOKIE', 'qp_token');
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/ajax-hooks.php';
// Make menus API available globally (frontend + admin)
require_once __DIR__ . '/includes/menus.php';
// Permalink rewrite helpers
require_once __DIR__ . '/includes/rewrite.php';

// -----------------------------------------------------------------------------
// Shortcode API (shared between frontend and admin)
// -----------------------------------------------------------------------------
if (!function_exists('qp_add_shortcode')) {
    $GLOBALS['qp_shortcodes'] = $GLOBALS['qp_shortcodes'] ?? [];
    function qp_add_shortcode($tag, $callable) {
        $GLOBALS['qp_shortcodes'][$tag] = $callable;
    }
}

    // Auto-handle logout via ?logout=1 (optional redirect via ?redirect=/path)
    if (PHP_SAPI !== 'cli' && !empty($_GET['logout'])) {
        // If a redirect was explicitly provided, use it. Otherwise build
        // a redirect to the current request path but with logout/redirect params removed.
        $explicit = $_GET['redirect'] ?? $_GET['redirect_to'] ?? null;
        if ($explicit) {
            qp_logout($explicit);
        } else {
            $req = $_SERVER['REQUEST_URI'] ?? '/';
            $path = strtok($req, '?') ?: '/';
            $qs = parse_url($req, PHP_URL_QUERY) ?: '';
            parse_str($qs, $qarr);
            // Remove logout-related params
            unset($qarr['logout'], $qarr['redirect'], $qarr['redirect_to']);
            $newqs = http_build_query($qarr);
            $target = $path . ($newqs ? ('?' . $newqs) : '');
            if ($target === '') $target = '/';
            qp_logout($target);
        }
    }

if (!function_exists('qp_do_shortcodes')) {
    function qp_do_shortcodes($content) {
        if (!$content || stripos($content, '[') === false) return $content;
        $shortcodes = $GLOBALS['qp_shortcodes'] ?? [];
        if (empty($shortcodes)) return $content;
        // handle longest tag names first
        uksort($shortcodes, function($a,$b){ return strlen($b)-strlen($a); });
        foreach ($shortcodes as $tag => $cb) {
            // enclosing form [tag]...[/tag]
            $pattern = '/\[' . preg_quote($tag, '/') . '\](.*?)\[\/' . preg_quote($tag, '/') . '\]/is';
            $content = preg_replace_callback($pattern, function($m) use ($cb){
                $inner = $m[1] ?? '';
                try { return call_user_func($cb, trim($inner), []); } catch(Exception $e){ return $m[0]; }
            }, $content);
            // self-closing with attrs [tag key="val"]
            $pattern2 = '/\[' . preg_quote($tag, '/') . '\s+([^\]]+)\]/i';
            $content = preg_replace_callback($pattern2, function($m) use ($cb){
                $attrstr = $m[1] ?? '';
                $attrs = [];
                if (preg_match_all('/(\w+)\s*=\s*"([^"]*)"/', $attrstr, $am, PREG_SET_ORDER)) {
                    foreach ($am as $ai) $attrs[$ai[1]] = $ai[2];
                }
                try { return call_user_func($cb, '', $attrs); } catch(Exception $e){ return $m[0]; }
            }, $content);

            // bare self-closing tag [tag] (no attrs, no closing) — treat as empty inner
            $pattern3 = '/\[' . preg_quote($tag, '/') . '\]/i';
            $content = preg_replace_callback($pattern3, function($m) use ($cb){
                try { return call_user_func($cb, '', []); } catch(Exception $e){ return $m[0]; }
            }, $content);
        }
        return $content;
    }
}

if (!function_exists('qp_embed_shortcode_handler')) {
    function qp_embed_shortcode_handler($inner, $attrs) {
        $url = '';
        if (!empty($inner)) $url = trim($inner);
        if (isset($attrs['url'])) $url = $attrs['url'];
        if (!$url) return '';
        $allowed = ['http','https'];
        $parts = parse_url($url);
        if (!$parts || !in_array(strtolower($parts['scheme'] ?? ''), $allowed)) return htmlspecialchars($url);
        $ext = strtolower(pathinfo($parts['path'] ?? '', PATHINFO_EXTENSION) ?: '');
        if ($ext === 'pdf') {
            return '<div class="qlopy-embed">' . '<iframe src="' . htmlspecialchars($url) . '" style="width:100%;height:560px;border:0;" allowfullscreen></iframe>' . '</div>';
        }
        return '<a href="' . htmlspecialchars($url) . '" target="_blank" rel="noopener">' . htmlspecialchars($url) . '</a>';
    }
    qp_add_shortcode('embed', 'qp_embed_shortcode_handler');
}

// -----------------------------------------------------------------------------
// Shortcode: site_url
// Usage: [site_url] -> prints site base URL
//        [site_url path="about"] or [site_url]about -> prints site base + path
// -----------------------------------------------------------------------------
if (!function_exists('qp_site_url_shortcode')) {
    function qp_site_url_shortcode($inner = '', $attrs = []) {
        $path = '';
        if (!empty($attrs['path'])) $path = $attrs['path'];
        elseif (!empty($inner)) $path = trim($inner);

        // If path looks like an absolute URL or has a scheme, sanitize and return
        if (preg_match('/^[a-zA-Z][a-zA-Z0-9+.-]*:/', $path)) {
            $u = $path;
            $scheme = strtolower(parse_url($u, PHP_URL_SCHEME) ?: '');
            if (!in_array($scheme, ['http', 'https', 'mailto', 'tel'])) return '#';
            return filter_var($u, FILTER_SANITIZE_URL);
        }

        // Treat as path relative to site root
        $u = home_url($path ?: '/');
        return filter_var($u, FILTER_SANITIZE_URL);
    }
    qp_add_shortcode('site_url', 'qp_site_url_shortcode');
}

// -----------------------------------------------------------------------------
// Shortcode: archive_url
// Usage: [archive_url]post    or [archive_url post_type="flipbooks"]
// -----------------------------------------------------------------------------
if (!function_exists('qp_archive_url_shortcode')) {
    function qp_archive_url_shortcode($inner = '', $attrs = []) {
        $pt = 'post';
        if (!empty($attrs['post_type'])) $pt = $attrs['post_type'];
        elseif (!empty($attrs['type'])) $pt = $attrs['type'];
        elseif (!empty($inner)) $pt = trim($inner);
        try {
            return get_site_archive_url($pt ?: 'post');
        } catch (Throwable $_e) {
            return '';
        }
    }
    qp_add_shortcode('archive_url', 'qp_archive_url_shortcode');
}

// -----------------------------------------------------------------------------
// Session token (usermeta-backed) helpers with optional APCu/Redis/Memcached cache
// -----------------------------------------------------------------------------
if (!defined('QP_SESSION_COOKIE')) define('QP_SESSION_COOKIE', 'qp_token');

function qp_hash_token_sig($token_id, $user_id, $expires) {
    // Use cached config instead of re-loading file on each call
    if (function_exists('get_config')) {
        $cfg = get_config();
    } else {
        $cfg = require __DIR__ . '/config.php';
    }
    $salt = $cfg['security_salt'] ?? '';
    return hash_hmac('sha256', $token_id . '|' . $user_id . '|' . $expires, $salt);
}

/**
 * Browser CSRF nonces are bound to the current session, or to a per-browser
 * anonymous seed before login. Version 1 nonces remain verifiable until their
 * embedded expiry so pages rendered immediately before an upgrade still work.
 */
function qp_nonce_security_salt(): string {
    $cfg = function_exists('get_config')
        ? get_config()
        : (is_file(__DIR__ . '/config.php') ? require __DIR__ . '/config.php' : []);
    return (string)($cfg['security_salt'] ?? '');
}

function qp_nonce_base64url_encode(string $value): string {
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

function qp_nonce_base64url_decode(string $value) {
    if ($value === '' || preg_match('/^[A-Za-z0-9_-]+$/', $value) !== 1) return false;
    $padding = strlen($value) % 4;
    if ($padding) $value .= str_repeat('=', 4 - $padding);
    return base64_decode(strtr($value, '-_', '+/'), true);
}

function qp_nonce_anonymous_binding(int $expires): ?string {
    $cookie_name = 'qp_csrf_seed';
    $seed = $_COOKIE[$cookie_name] ?? '';
    if (!is_string($seed) || preg_match('/^[a-f0-9]{64}$/', $seed) !== 1) {
        if (headers_sent()) {
            error_log('Unable to create Qlopy CSRF nonce after response headers were sent.');
            return null;
        }
        try {
            $seed = bin2hex(random_bytes(32));
        } catch (Exception $e) {
            error_log('Unable to generate Qlopy anonymous CSRF seed: ' . $e->getMessage());
            return null;
        }

        $cfg = function_exists('get_config')
            ? get_config()
            : (is_file(__DIR__ . '/config.php') ? require __DIR__ . '/config.php' : []);
        $site_url = $cfg['site_url'] ?? null;
        $cookie_path = '/';
        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (string)($_SERVER['SERVER_PORT'] ?? '') === '443';
        if ($site_url) {
            $parsed = parse_url($site_url);
            if (!empty($parsed['path'])) $cookie_path = rtrim($parsed['path'], '/') ?: '/';
            if (!empty($parsed['scheme']) && strtolower($parsed['scheme']) === 'https') $secure = true;
        }
        setcookie($cookie_name, $seed, $expires, $cookie_path, '', $secure, true);
        $_COOKIE[$cookie_name] = $seed;
    }
    return hash_hmac('sha256', 'anonymous|' . $seed, qp_nonce_security_salt());
}

function qp_nonce_request_context(int $expires): ?array {
    $cookie = $_COOKIE[QP_SESSION_COOKIE] ?? '';
    $session = $cookie ? validate_session_token($cookie) : null;
    if ($session) {
        return [
            'kind' => 'session',
            'user_id' => (int)$session['user_id'],
            'binding' => hash_hmac('sha256', 'session|' . $session['token_id'], qp_nonce_security_salt()),
        ];
    }

    $binding = qp_nonce_anonymous_binding($expires);
    if ($binding === null) return null;
    return ['kind' => 'anonymous', 'user_id' => 0, 'binding' => $binding];
}

// Version 2 nonces provide CSRF protection while preserving the original public API.
if (!function_exists('qp_create_nonce')) {
    function qp_create_nonce(string $action = '-1', int $ttl = 7200): string {
        if ($action === '' || $ttl <= 0) return '';

        $expires = time() + $ttl;
        $context = qp_nonce_request_context($expires);
        if ($context === null) return '';

        $payload = json_encode([
            'v' => 2,
            'e' => $expires,
            'a' => $action,
            'k' => $context['kind'],
            'u' => $context['user_id'],
            'b' => $context['binding'],
        ]);
        if ($payload === false) {
            error_log('Unable to encode Qlopy CSRF nonce payload.');
            return '';
        }

        $encoded_payload = qp_nonce_base64url_encode($payload);
        $signature = hash_hmac('sha256', 'qp_nonce_v2|' . $encoded_payload, qp_nonce_security_salt(), true);
        return 'qp2.' . $encoded_payload . '.' . qp_nonce_base64url_encode($signature);
    }
}

if (!function_exists('qp_verify_nonce')) {
    function qp_verify_nonce(string $nonce, string $action = '-1'): bool {
        if ($nonce === '' || $action === '' || strlen($nonce) > 8192) return false;

        if (strncmp($nonce, 'qp2.', 4) === 0) {
            $parts = explode('.', $nonce);
            if (count($parts) !== 3 || $parts[0] !== 'qp2') return false;

            $payload_json = qp_nonce_base64url_decode($parts[1]);
            $signature = qp_nonce_base64url_decode($parts[2]);
            if ($payload_json === false || $signature === false || strlen($payload_json) > 4096) return false;

            $expected = hash_hmac('sha256', 'qp_nonce_v2|' . $parts[1], qp_nonce_security_salt(), true);
            if (!hash_equals($expected, $signature)) return false;

            $payload = json_decode($payload_json, true);
            if (!is_array($payload)
                || ($payload['v'] ?? null) !== 2
                || !isset($payload['e'], $payload['a'], $payload['k'], $payload['u'], $payload['b'])
                || !is_int($payload['e'])
                || !is_string($payload['a'])
                || !is_string($payload['k'])
                || !is_int($payload['u'])
                || !is_string($payload['b'])
                || $payload['e'] < time()
                || !hash_equals($action, $payload['a'])) {
                return false;
            }

            $context = qp_nonce_request_context($payload['e']);
            return $context !== null
                && hash_equals($context['kind'], $payload['k'])
                && $context['user_id'] === $payload['u']
                && hash_equals($context['binding'], $payload['b']);
        }

        // Legacy v1 support: accept only tokens generated before the upgrade
        // until their original expiry. New tokens are always generated as v2.
        $raw = qp_nonce_base64url_decode($nonce);
        if ($raw === false) return false;
        $parts = explode('|', $raw);
        if (count($parts) !== 2 || !ctype_digit($parts[0]) || !ctype_xdigit($parts[1]) || strlen($parts[1]) !== 64) return false;
        $expires = (int)$parts[0];
        if ($expires < time()) return false;
        $expected = hash_hmac('sha256', $action . '|' . $expires, qp_nonce_security_salt());
        return hash_equals($expected, $parts[1]);
    }
}

// Seed anonymous browser requests before templates emit output, so public forms
// can use the same CSRF API as authenticated forms.
if (empty($_COOKIE[QP_SESSION_COOKIE]) && !headers_sent()) {
    qp_nonce_anonymous_binding(time() + 7200);
}

function qp_cache_backend() {
    static $backend = null;
    if ($backend !== null) return $backend;
    // Use cached config instead of re-loading file in hot path
    if (function_exists('get_config')) {
        $cfg = get_config();
    } else {
        $cfg = require __DIR__ . '/config.php';
    }
    $opt = $cfg['session_cache'] ?? null;
    // Priority: explicit config, then Redis extension, then Memcached extension, then APCu
    if (is_array($opt) && !empty($opt['type'])) {
        $backend = $opt;
        return $backend;
    }
    if (extension_loaded('redis')) {
        $backend = ['type' => 'redis', 'host' => '127.0.0.1', 'port' => 6379];
        return $backend;
    }
    if (extension_loaded('memcached')) {
        $backend = ['type' => 'memcached', 'servers' => [['127.0.0.1', 11211]]];
        return $backend;
    }
    // fallback to apcu if available
    if (function_exists('apcu_fetch')) {
        $backend = ['type' => 'apcu'];
        return $backend;
    }
    $backend = ['type' => 'none'];
    return $backend;
}

// NOTE: revoke logging removed. Previously wrote JSON lines to updates/logs/session_revokes.log

function qp_cache_fetch($key) {
    $b = qp_cache_backend();
    if ($b['type'] === 'apcu') return apcu_fetch($key);
    if ($b['type'] === 'redis') {
        static $r = null;
        if ($r === null) {
            $r = new Redis();
            $host = $b['host'] ?? '127.0.0.1'; $port = $b['port'] ?? 6379;
            @$r->connect($host, $port);
        }
        return $r ? json_decode($r->get($key), true) : false;
    }
    if ($b['type'] === 'memcached') {
        static $mc = null;
        if ($mc === null) {
            $mc = new Memcached();
            foreach ($b['servers'] as $s) $mc->addServer($s[0], $s[1]);
        }
        return $mc->get($key);
    }
    return false;
}

/**
 * Extended wrapper for qp_handle_upload with convenient option normalization.
 *
 * Options supported (merged into qp_handle_upload options):
 * - skip_sizes (bool): skip generating image sizes
 * - subdir (string): sanitized subfolder under uploads
 * - use_date_folders (bool): whether to use YYYY/MM folders (default true)
 * - folder (string): alternative to subdir; sanitized folder name used if subdir empty
 *
 * Example:
 * qp_handle_upload_extensive($_FILES['file'], null, ['folder'=>'my-plugin','skip_sizes'=>true,'use_date_folders'=>false]);
 *
 * Returns same as qp_handle_upload: [bool $ok, array|string $result]
 */
/**
 * Convenience wrapper that normalizes and documents upload options.
 *
 * - `folder` (string) will be sanitized and used as `subdir` for placement under `uploads/`.
 * - `skip_sizes` (bool) skips image derivative generation when true.
 * - `use_date_folders` (bool) controls whether YYYY/MM are used beneath the folder.
 * - `compress_original` (bool) when true will compress the original uploaded image file.
 * - `original_quality` (int) JPEG/WebP quality to use when compressing original (default 85).
 *
 * This wrapper ensures callers can pass friendly names and receive the same return
 * structure as `qp_handle_upload()` while keeping the low-level handler focused.
 */
function qp_handle_upload_extensive(array $file, ?int $post_author_id = null, array $options = []): array {
    // Normalize options and provide folder->subdir convenience
    $opts = $options;
    if (isset($opts['folder']) && empty($opts['subdir'])) {
        // sanitize folder name to safe filesystem/URL token
        $opts['subdir'] = preg_replace('/[^a-zA-Z0-9_-]+/', '', (string)$opts['folder']);
    }
    // Defaults
    if (!array_key_exists('use_date_folders', $opts)) $opts['use_date_folders'] = true;
    $opts['skip_sizes'] = !empty($opts['skip_sizes']);
    $opts['use_date_folders'] = (bool)$opts['use_date_folders'];
    $opts['subdir'] = isset($opts['subdir']) ? preg_replace('/[^a-zA-Z0-9_-]+/', '', (string)$opts['subdir']) : '';

    // Delegate to low-level handler
    return qp_handle_upload($file, $post_author_id, $opts);
}

function qp_cache_store($key, $val, $ttl = 300) {
    $b = qp_cache_backend();
    if ($b['type'] === 'apcu') return apcu_store($key, $val, $ttl);
    if ($b['type'] === 'redis') {
        static $r = null;
        if ($r === null) {
            $r = new Redis();
            $host = $b['host'] ?? '127.0.0.1'; $port = $b['port'] ?? 6379;
            @$r->connect($host, $port);
        }
        return $r ? $r->setex($key, $ttl, json_encode($val)) : false;
    }
    if ($b['type'] === 'memcached') {
        static $mc = null;
        if ($mc === null) {
            $mc = new Memcached();
            foreach ($b['servers'] as $s) $mc->addServer($s[0], $s[1]);
        }
        return $mc->set($key, $val, $ttl);
    }
    return false;
}

function qp_cache_delete($key) {
    $b = qp_cache_backend();
    if ($b['type'] === 'apcu') return apcu_delete($key);
    if ($b['type'] === 'redis') {
        static $r = null;
        if ($r === null) {
            $r = new Redis();
            $host = $b['host'] ?? '127.0.0.1'; $port = $b['port'] ?? 6379;
            @$r->connect($host, $port);
        }
        return $r ? $r->del($key) : false;
    }
    if ($b['type'] === 'memcached') {
        static $mc = null;
        if ($mc === null) {
            $mc = new Memcached();
            foreach ($b['servers'] as $s) $mc->addServer($s[0], $s[1]);
        }
        return $mc->delete($key);
    }
    return false;
}

function get_user_session_tokens($user_id) {
    // Try cache first
    $cacheKey = 'qp_user_tokens_' . $user_id;
    $cached = qp_cache_fetch($cacheKey);
        if ($cached !== false && is_array($cached)) {
            return $cached;
        }
    // Read usermeta
    $tokens = get_user_meta($user_id, 'session_tokens');
    if (!is_array($tokens)) $tokens = [];
    // normalize numeric expires to int
    foreach ($tokens as $k => $v) {
        if (isset($v['expires_at'])) $tokens[$k]['expires_at'] = (int)$v['expires_at'];
    }
    // store in cache for TTL equal to nearest expiry or default 300s
    $ttl = 300;
    if (!empty($tokens)) {
        $minExpires = null;
        foreach ($tokens as $t) { if (!empty($t['expires_at'])) { if ($minExpires === null || $t['expires_at'] < $minExpires) $minExpires = $t['expires_at']; }}
        if ($minExpires !== null) $ttl = max(30, $minExpires - time());
    }
    qp_cache_store($cacheKey, $tokens, $ttl);
    return $tokens;
}

function store_user_session_tokens($user_id, $tokens) {
    // persist to DB
    update_user_meta($user_id, 'session_tokens', $tokens);
    // update cache
    $cacheKey = 'qp_user_tokens_' . $user_id;
    $ttl = 300;
    if (!empty($tokens)) {
        $minExpires = null;
        foreach ($tokens as $t) { if (!empty($t['expires_at'])) { if ($minExpires === null || $t['expires_at'] < $minExpires) $minExpires = $t['expires_at']; }}
        if ($minExpires !== null) $ttl = max(30, $minExpires - time());
    }
    qp_cache_store($cacheKey, $tokens, $ttl);
}

function create_session_token($user_id, $ttl = 21600, $meta = null) {
    $token_id = bin2hex(random_bytes(24));
            $now = time(); // Get the current time
        $expires = $now + (int)$ttl; // Set expiration time
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $tokens = get_user_session_tokens($user_id);
    $tokens[$token_id] = [
        'expires_at' => $expires,
        'last_activity' => $now,
        'ip' => $ip,
        'user_agent' => $ua,
        'meta' => $meta
    ];
    store_user_session_tokens($user_id, $tokens);
    return ['token_id'=>$token_id,'user_id'=>$user_id,'expires'=>$expires];
}

function set_session_token_cookie($token_row) {
    $token_id = $token_row['token_id'];
            $user_id = $token_row['user_id']; // Extract user ID from token row
    $expires = $token_row['expires'];
    $sig = qp_hash_token_sig($token_id, $user_id, $expires);
    $val = $token_id . '|' . $user_id . '|' . $expires . '|' . $sig;
    $cfg = require __DIR__ . '/config.php';
    $siteUrl = $cfg['site_url'] ?? null;
    $cookiePath = '/';
    $secureFlag = false;
    if ($siteUrl) {
        $parsed = parse_url($siteUrl);
        if (!empty($parsed['path'])) $cookiePath = rtrim($parsed['path'], '/') ?: '/';
        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['SERVER_PORT'] ?? '') == '443';
        $secureFlag = $isHttps || (!empty($parsed['scheme']) && strtolower($parsed['scheme']) === 'https');
    }
    setcookie(QP_SESSION_COOKIE, $val, (int)$expires, $cookiePath, '', $secureFlag, true);
}

function validate_session_token($cookieVal) {
    if (empty($cookieVal)) return null;
    $parts = explode('|', $cookieVal);
    if (count($parts) < 4) return null;
    list($token_id, $user_id, $expires, $sig) = $parts;
    if (!ctype_xdigit($token_id) || !is_numeric($user_id) || !is_numeric($expires)) return null;
    if ($expires < time()) return null;
    $expect = qp_hash_token_sig($token_id, $user_id, $expires);
    if (!hash_equals($expect, $sig)) return null;

    // Load user's tokens (from cache or usermeta)
    $tokens = get_user_session_tokens((int)$user_id);
    if (empty($tokens[$token_id])) return null;
    $row = $tokens[$token_id];
    if (empty($row['expires_at']) || $row['expires_at'] < time()) return null;
    // return combined row info
    return ['token_id'=>$token_id,'user_id'=>(int)$user_id,'expires_at'=>$row['expires_at'],'last_activity'=>$row['last_activity'] ?? null,'meta'=>$row['meta'] ?? null];
}

function revoke_session_token($token_id, $user_id = null) {
    if (empty($token_id)) return false;
    // If user_id provided, remove directly
    if ($user_id) {
        $tokens = get_user_session_tokens((int)$user_id);
        if (isset($tokens[$token_id])) unset($tokens[$token_id]);
        store_user_session_tokens((int)$user_id, $tokens);
        qp_cache_delete('qp_user_tokens_' . $user_id);
        return true;
    }
    // If not provided, attempt to read cookie to find user
    $cookie = $_COOKIE[QP_SESSION_COOKIE] ?? '';
    if ($cookie) {
        $parts = explode('|', $cookie);
        if (count($parts) >= 2) {
            $u = (int)$parts[1];
            $tokens = get_user_session_tokens($u);
            if (isset($tokens[$token_id])) {
                unset($tokens[$token_id]);
                store_user_session_tokens($u, $tokens);
                qp_cache_delete('qp_user_tokens_' . $u);
                return true;
            }
        }
    }
    return false;
}

function revoke_user_tokens($user_id) {
    // delete usermeta row
    global $pdo;
    $table = table_name('user_meta');
    $stmt = $pdo->prepare("DELETE FROM {$table} WHERE user_id = ? AND meta_key = 'session_tokens'");
    $res = $stmt->execute([$user_id]);
    qp_cache_delete('qp_user_tokens_' . $user_id);
    return $res;
}

/**
 * Clear the Qlopy session cookie and optionally revoke the associated token.
 * Mirrors WP's wp_clear_auth_cookie() behavior for Qlopy's token system.
 *
 * @param bool $revoke If true, attempts to revoke the token stored in the cookie.
 * @return bool
 */
function qp_clear_auth_cookie($revoke = true) {
    $cookie = $_COOKIE[QP_SESSION_COOKIE] ?? '';
    if ($cookie && $revoke && function_exists('validate_session_token')) {
        $parts = explode('|', $cookie);
        $token_id = $parts[0] ?? '';
        $user_id = isset($parts[1]) ? (int)$parts[1] : null;
        if ($token_id && $user_id && function_exists('revoke_session_token')) {
            try { revoke_session_token($token_id, $user_id); } catch (Throwable $_) {}
        }
    }

    // Determine cookie path similar to set_session_token_cookie()
    $cfg = function_exists('get_config') ? get_config() : (is_file(__DIR__ . '/config.php') ? require __DIR__ . '/config.php' : []);
    $siteUrl = $cfg['site_url'] ?? null;
    $cookiePath = '/';
    if ($siteUrl) {
        $parsed = @parse_url($siteUrl);
        if (!empty($parsed['path'])) $cookiePath = rtrim($parsed['path'], '/') ?: '/';
    }

    // Clear cookie and superglobals
    setcookie(QP_SESSION_COOKIE, '', time() - 42000, $cookiePath, '', false, true);
    unset($_COOKIE[QP_SESSION_COOKIE]);
    return true;
}


function auth_init_from_token() {
    // Validate token cookie and optionally refresh last_activity. Does not set PHP session.
    $cookie = $_COOKIE[QP_SESSION_COOKIE] ?? '';
    $row = validate_session_token($cookie);
    if ($row) {
        // throttle last_activity update: only update if >5 minutes since last_activity
        // also implement sliding sessions: extend expires_at on activity and update cookie
        $now = time();
        if (empty($row['last_activity']) || $row['last_activity'] < $now - 300) {
            $tokens = get_user_session_tokens((int)$row['user_id']);
            if (isset($tokens[$row['token_id']])) {
                $tokens[$row['token_id']]['last_activity'] = $now;
                // extend expiry by default TTL (use same default as create_session_token)
                $ttl = 21600; // 6 hours
                $newExpires = $now + (int)$ttl;
                $tokens[$row['token_id']]['expires_at'] = $newExpires;
                store_user_session_tokens((int)$row['user_id'], $tokens);
                // update client cookie so browser sees extended expiry
                if (function_exists('set_session_token_cookie')) {
                    set_session_token_cookie(['token_id' => $row['token_id'], 'user_id' => $row['user_id'], 'expires' => $newExpires]);
                }
            }
        }
        return true;
    }
    return false;
}

// Pretty URL parser: map configured patterns into query vars; includes fallback
if (function_exists('permalink_get_structure') && permalink_get_structure() === 'pretty') {
    // Only apply to frontend, not admin pages
    $scriptDir = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/');
    $admin_dir = $GLOBALS['admin_dir'] ?? ($GLOBALS['config']['admin_dir'] ?? 'admin');
    $isAdmin = (basename($scriptDir) === $admin_dir);
    if (!$isAdmin && empty($_GET['page'])) {
        if (function_exists('parse_pretty_request')) {
            parse_pretty_request($scriptDir);
        }
    }
}

// Normalize legacy query param `page` to router vars for compatibility
if (!empty($_GET['page'])) {
    if (empty($_GET['route'])) $_GET['route'] = 'singular';
    if (empty($_GET['post_type'])) $_GET['post_type'] = 'page';
    if (empty($_GET['slug'])) $_GET['slug'] = $_GET['page'];
}


// Roles and capabilities arrays stored as globals (branded)
$GLOBALS['qlopy_roles'] = [];
$GLOBALS['qlopy_capabilities'] = [];

/**
 * Register a new role with given capabilities.
 */
function register_role(string $role, array $capabilities) {
    $GLOBALS['qlopy_roles'][$role] = $capabilities;
    foreach ($capabilities as $cap) {
        $GLOBALS['qlopy_capabilities'][$cap] = true;
    }
}

/**
 * Check if a capability is registered.
 */
function is_capability_registered(string $cap) {
    return isset($GLOBALS['qlopy_capabilities'][$cap]);
}

/**
 * Retrieve capabilities for a role.
 */
function get_role_capabilities(string $role): array {
    return $GLOBALS['qlopy_roles'][$role] ?? [];
}

/**
 * Assign capabilities to a user.
 */
function assign_capabilities_to_user(int $user_id, array $capabilities) {
    update_user_meta($user_id, 'capabilities', $capabilities);
}

/**
 * Add one or more capabilities to a user while preserving existing ones.
 * Accepts a string (single cap), a comma-separated string, or an array of caps.
 * Returns true on success.
 */
function assign_new_capabilities_to_user(int $user_id, $caps) {
    // normalize input to array of strings
    if (is_string($caps)) {
        // comma-separated or single
        $caps = array_map('trim', array_filter(array_map('trim', explode(',', $caps)), 'strlen'));
    } elseif (!is_array($caps)) {
        return false;
    }

    if (empty($caps)) return true;

    $existing = get_user_meta($user_id, 'capabilities') ?? [];
    if (!is_array($existing)) $existing = (array)$existing;

    // normalize existing caps: trim and remove empties
    $existing = array_values(array_unique(array_filter(array_map('trim', $existing), 'strlen')));

    // determine new caps that aren't already present
    $to_add = [];
    foreach ($caps as $c) {
        $c = trim($c);
        if ($c === '') continue;
        if (!in_array($c, $existing, true)) $to_add[] = $c;
    }

    if (empty($to_add)) {
        // nothing to add
        return true;
    }

    $merged = array_values(array_unique(array_merge($existing, $to_add)));
    return update_user_meta($user_id, 'capabilities', $merged);
}

/**
 * Get user meta from DB.
 */
function get_user_meta($user_id, $key, $single = true) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT meta_value FROM " . table_name('user_meta') . " WHERE user_id = ? AND meta_key = ? LIMIT 1");
    $stmt->execute([$user_id, $key]);
    $value = $stmt->fetchColumn();
    if (!$value) return null;
    if ($single) return maybe_unserialize($value);
    return maybe_unserialize($value);
}

/**
 * Update or insert user meta.
 */
function update_user_meta($user_id, $key, $value) {
    global $pdo;
    $value = maybe_serialize($value);
    // Use atomic upsert to avoid race conditions and duplicate-key errors.
    $table = table_name('user_meta');
    $sql = "INSERT INTO {$table} (user_id, meta_key, meta_value) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value)";
    $stmt = $pdo->prepare($sql);
    try {
        return $stmt->execute([$user_id, $key, $value]);
    } catch (PDOException $e) {
        // If failure was due to data-too-long, attempt to widen the column to LONGTEXT and retry once
        $msg = $e->getMessage();
        error_log('update_user_meta upsert failed: ' . $msg);
        $isDataTooLong = false;
        // SQLSTATE 22001 indicates string data right truncated; also check message for MySQL specific code
        if ($e->getCode() === '22001' || stripos($msg, 'Data too long') !== false) { $isDataTooLong = true; }
        if ($isDataTooLong) {
            try {
                $alterSql = "ALTER TABLE " . table_name('user_meta') . " MODIFY meta_value LONGTEXT";
                $pdo->exec($alterSql);
                // Retry the upsert after altering the column
                $stmtRetry = $pdo->prepare($sql);
                $ok = $stmtRetry->execute([$user_id, $key, $value]);
                if ($ok) return $ok;
            } catch (Throwable $_alterEx) {
                error_log('Failed to ALTER user_meta.meta_value to LONGTEXT: ' . $_alterEx->getMessage());
            }
        }
        // Fall back to update if insert failed for another reason or alter didn't help
        $stmt2 = $pdo->prepare("UPDATE " . table_name('user_meta') . " SET meta_value = ? WHERE user_id = ? AND meta_key = ?");
        return $stmt2->execute([$value, $user_id, $key]);
    }
}

/**
 * Delete a user meta key for a user.
 */
function delete_user_meta($user_id, $key) {
    global $pdo;
    try {
        // Optional $meta_value filter to match WP semantics
        $numArgs = func_num_args();
        $meta_value = $numArgs >= 3 ? func_get_arg(2) : null;
        if ($meta_value === null) {
            $stmt = $pdo->prepare('DELETE FROM ' . table_name('user_meta') . ' WHERE user_id = ? AND meta_key = ?');
            $stmt->execute([$user_id, $key]);
            return ($stmt->rowCount() > 0);
        }
        $json = json_encode($meta_value);
        $plain = is_scalar($meta_value) ? (string)$meta_value : $json;
        $stmt = $pdo->prepare('DELETE FROM ' . table_name('user_meta') . ' WHERE user_id = ? AND meta_key = ? AND (meta_value = ? OR meta_value = ?)');
        $stmt->execute([$user_id, $key, $json, $plain]);
        return ($stmt->rowCount() > 0);
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * WP-like update_user_meta replacement with WP-compatible return semantics.
 * - returns new meta_id (int) on insert
 * - returns true on update
 * - returns false on no-change or failure
 * Supports optional $prev_value to update only matching existing row.
 */
function update_user_metadata($user_id, $meta_key, $meta_value, $prev_value = '') {
    global $pdo;
    $user_id = (int)$user_id;
    $has_prev = func_num_args() >= 4;
    $ser_new = maybe_serialize($meta_value);
    $ser_prev = $has_prev ? maybe_serialize($prev_value) : null;

    $stmt = $pdo->prepare('SELECT id, meta_value FROM ' . table_name('user_meta') . ' WHERE user_id = ? AND meta_key = ? ORDER BY id ASC');
    $stmt->execute([$user_id, $meta_key]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!empty($rows)) {
        if ($has_prev) {
            foreach ($rows as $row) {
                if ($row['meta_value'] === $ser_prev) {
                    if ($row['meta_value'] === $ser_new) return false;
                    $u = $pdo->prepare('UPDATE ' . table_name('user_meta') . ' SET meta_value = ? WHERE id = ?');
                    $ok = $u->execute([$ser_new, $row['id']]);
                    return $ok ? true : false;
                }
            }
            // no matching prev row -> insert new
            $i = $pdo->prepare('INSERT INTO ' . table_name('user_meta') . ' (user_id, meta_key, meta_value) VALUES (?, ?, ?)');
            $res = $i->execute([$user_id, $meta_key, $ser_new]);
            if ($res) return (int)$pdo->lastInsertId();
            return false;
        } else {
            // operate on first existing row
            $first = $rows[0];
            if ($first['meta_value'] === $ser_new) return false;
            $u = $pdo->prepare('UPDATE ' . table_name('user_meta') . ' SET meta_value = ? WHERE id = ?');
            $ok = $u->execute([$ser_new, $first['id']]);
            return $ok ? true : false;
        }
    }

    // no existing rows: insert new
    $i = $pdo->prepare('INSERT INTO ' . table_name('user_meta') . ' (user_id, meta_key, meta_value) VALUES (?, ?, ?)');
    $res = $i->execute([$user_id, $meta_key, $ser_new]);
    if ($res) return (int)$pdo->lastInsertId();
    return false;
}

/**
 * Add a user meta value. Mirrors WP's `add_user_meta` semantics.
 * Returns the new meta_id (int) on success or false on failure.
 */
function add_user_meta($user_id, $meta_key, $meta_value, $unique = false) {
    global $pdo;
    $user_id = (int)$user_id;
    $val = maybe_serialize($meta_value);
    if ($unique) {
        $c = $pdo->prepare('SELECT COUNT(*) FROM ' . table_name('user_meta') . ' WHERE user_id = ? AND meta_key = ?');
        $c->execute([$user_id, $meta_key]);
        if ($c->fetchColumn() > 0) return false;
    }
    $i = $pdo->prepare('INSERT INTO ' . table_name('user_meta') . ' (user_id, meta_key, meta_value) VALUES (?, ?, ?)');
    $ok = $i->execute([$user_id, $meta_key, $val]);
    if ($ok) return (int)$pdo->lastInsertId();
    return false;
}

/**
 * Add a post meta value. Mirrors WP's `add_post_meta` semantics.
 * Uses JSON encoding for storage to match other post_meta helpers.
 */
function add_post_meta($post_id, $meta_key, $meta_value, $unique = false) {
    global $pdo;
    $post_id = (int)$post_id;
    $val = json_encode($meta_value);
    if ($unique) {
        $c = $pdo->prepare('SELECT COUNT(*) FROM ' . table_name('post_meta') . ' WHERE post_id = ? AND meta_key = ?');
        $c->execute([$post_id, $meta_key]);
        if ($c->fetchColumn() > 0) return false;
    }
    $i = $pdo->prepare('INSERT INTO ' . table_name('post_meta') . ' (post_id, meta_key, meta_value) VALUES (?, ?, ?)');
    $ok = $i->execute([$post_id, $meta_key, $val]);
    if ($ok) return (int)$pdo->lastInsertId();
    return false;
}

/**
 * Add a term meta value. Mirrors WP's `add_term_meta` semantics.
 */
function add_term_meta($term_id, $meta_key, $meta_value, $unique = false) {
    global $pdo;
    $term_id = (int)$term_id;
    $val = json_encode($meta_value);
    if ($unique) {
        $c = $pdo->prepare('SELECT COUNT(*) FROM ' . table_name('term_meta') . ' WHERE term_id = ? AND meta_key = ?');
        $c->execute([$term_id, $meta_key]);
        if ($c->fetchColumn() > 0) return false;
    }
    $i = $pdo->prepare('INSERT INTO ' . table_name('term_meta') . ' (term_id, meta_key, meta_value) VALUES (?, ?, ?)');
    $ok = $i->execute([$term_id, $meta_key, $val]);
    if ($ok) return (int)$pdo->lastInsertId();
    return false;
}

/**
 * WP-like get_user_meta replacement that returns all values or a single value.
 * Signature mirrors WP: get_user_metadata($user_id, $key = '', $single = false)
 */
function get_user_metadata($user_id, $key = '', $single = false) {
    global $pdo;
    $user_id = (int)$user_id;
    if ($user_id <= 0) return $single ? '' : [];

    // Detect frontend vs admin for ml_get_meta filter consistency
    $is_admin = false;
    if (!empty($_SERVER['SCRIPT_NAME']) && stripos($_SERVER['SCRIPT_NAME'], '/admin/') !== false) {
        $is_admin = true;
    }
    $lang = null;
    if (!$is_admin && function_exists('ml_get_current_lang')) {
        $lang = ml_get_current_lang();
    }

    if ($key === '' || $key === null) {
        $stmt = $pdo->prepare('SELECT meta_key, meta_value FROM ' . table_name('user_meta') . ' WHERE user_id = ? ORDER BY id ASC');
        $stmt->execute([$user_id]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $out = [];
        foreach ($rows as $r) {
            $k = $r['meta_key'];
            $raw = $r['meta_value'];
            $val = maybe_unserialize($raw);
            if (function_exists('apply_filters')) $val = apply_filters('ml_get_meta', $val, $user_id, $k, $lang);
            $out[$k][] = $val;
        }
        return $out;
    }

    $stmt = $pdo->prepare('SELECT meta_value FROM ' . table_name('user_meta') . ' WHERE user_id = ? AND meta_key = ? ORDER BY id ASC');
    $stmt->execute([$user_id, $key]);
    $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);
    if (empty($rows)) return $single ? '' : [];

    $values = array_map(function($v){ return maybe_unserialize($v); }, $rows);
    if (function_exists('apply_filters')) {
        foreach ($values as $i => $val) {
            $values[$i] = apply_filters('ml_get_meta', $val, $user_id, $key, $lang);
        }
    }
    if ($single) return $values[0] ?? '';
    return $values;
}

/**
 * Serialization helpers.
 */
function maybe_serialize($data) {
    if (is_array($data) || is_object($data)) {
        return serialize($data);
    }
    return $data;
}
function maybe_unserialize($data) {
    if (is_serialized($data)) {
        return unserialize($data);
    }
    // Try JSON decode for backward compatibility with installer that stored JSON
    if (is_string($data)) {
        $decoded = json_decode($data, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $decoded;
        }
    }
    return $data;
}
function is_serialized($data) {
    if (!is_string($data)) return false;
    $data = trim($data);
    if ($data == 'b:0;') return true;
    $length = strlen($data);
    if ($length < 4) return false;
    if ($data[1] !== ':') return false;
    $lastc = $data[$length - 1];
    if ($lastc !== ';' && $lastc !== '}') return false;
    $token = $data[0];
    switch ($token) {
        case 's': return (bool)preg_match('/^s:[0-9]+:".*";$/s', $data);
        case 'a': case 'O': case 'C': return (bool)preg_match("/^{$token}:[0-9]+:.*[;}]\$/s", $data);
        case 'b': case 'i': case 'd': return (bool)preg_match("/^{$token}:[0-9.E-]+;$/", $data);
    }
    return false;
}

/**
 * Register default roles and capabilities.
 */
function qlopy_register_default_roles() {
    register_role('admin', ['manage_posts','manage_users','manage_options','manage_themes','manage_plugins','edit_posts','publish_posts','delete_posts','edit_pages','publish_pages','delete_pages','moderate_comments','manage_comments','manage_categories','upload_files','edit_others_posts','edit_others_pages','delete_others_posts','delete_others_pages','manage_menus','manage_widgets','manage_admin_pages']);
    register_role('manager', ['manage_posts','edit_posts','publish_posts','delete_posts','edit_pages','publish_pages','delete_pages','moderate_comments','manage_comments','manage_categories','upload_files','edit_others_posts','edit_others_pages','delete_others_posts','delete_others_pages','manage_menus','manage_widgets','manage_admin_pages']);
    register_role('editor', ['manage_posts','edit_posts','publish_posts','delete_posts','edit_pages','publish_pages','delete_pages','moderate_comments','manage_categories','upload_files','edit_others_posts','edit_others_pages','delete_others_posts','delete_others_pages']);
    register_role('author', ['publish_posts','edit_own_posts','delete_own_posts','manage_categories','upload_files']);
    register_role('subscriber', []);
}
qlopy_register_default_roles();


function get_role_list(): array {
    $roles = array_reverse(array_keys($GLOBALS['qlopy_roles']));
    $pretty_roles = [];
    foreach ($roles as $role) {
        // Convert slug to title case for display names
        // Example: admin => Admin, editor => Editor, subscriber => Subscriber
        $pretty_name = ucwords(str_replace('_', ' ', $role));
        $pretty_roles[$role] = $pretty_name;
    }
    return $pretty_roles;
}

/**
 * Get user by username.
 *
 * Returns an associative array of the user row or null if not found.
 */
/**
 * Format a raw DB user row into a WP-like associative array.
 */
function format_user_row(array $user) {
    $uid = (int)($user['id'] ?? 0);
    $nicename = get_user_meta($uid, 'nicename') ?: '';
    $first_name = get_user_meta($uid, 'first_name') ?: '';
    $last_name = get_user_meta($uid, 'last_name') ?: '';
    $display_name = get_user_meta($uid, 'display_name') ?: '';
    if (!$display_name) {
        if ($nicename) $display_name = $nicename;
        elseif ($first_name || $last_name) $display_name = trim($first_name . ' ' . $last_name);
        else $display_name = $user['username'] ?? '';
    }
    $user_caps = get_user_meta($uid, 'capabilities') ?? [];
    if (!is_array($user_caps)) $user_caps = (array)$user_caps;
    $role = get_user_meta($uid, 'role') ?: null;
    $role_caps = $role ? get_role_capabilities($role) : [];
    $capabilities = array_values(array_unique(array_merge($role_caps, $user_caps)));
    return [
        'ID' => $uid,
        'user_login' => $user['username'] ?? '',
        'nicename' => $nicename,
        'display_name' => $display_name,
        'user_email' => $user['email'] ?? '',
        'first_name' => $first_name,
        'last_name' => $last_name,
        'capabilities' => $capabilities,
        'raw' => $user,
    ];
}

/**
 * Get a user by a field (id, email, login/username) and return WP-like fields.
 */
function get_user_by($field, $value) {
    global $pdo;
    if (empty($field) || $value === null) return null;
    $f = strtolower((string)$field);
    switch ($f) {
        case 'id': case 'userid': case 'user_id': case 'i':
            $stmt = $pdo->prepare("SELECT * FROM " . table_name('users') . " WHERE id = ? LIMIT 1");
            $stmt->execute([(int)$value]);
            break;
        case 'email':
            $stmt = $pdo->prepare("SELECT * FROM " . table_name('users') . " WHERE email = ? LIMIT 1");
            $stmt->execute([(string)$value]);
            break;
        case 'login': case 'username': case 'user_login':
            $stmt = $pdo->prepare("SELECT * FROM " . table_name('users') . " WHERE username = ? LIMIT 1");
            $stmt->execute([(string)$value]);
            break;
        default:
            return null;
    }
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user) return null;
    return format_user_row($user);
}

/** Convenience wrappers */
function get_user_by_id($id) { return get_user_by('id', $id); }
function get_user_by_email($email) { return get_user_by('email', $email); }

/**
 * Get user by username. Delegates to get_user_by to return WP-like structure.
 */
function get_user_by_username(string $username) {
    if (empty($username)) return null;
    return get_user_by('login', $username);
}
/**
 * Register user assigning capabilities based on current registered roles.
 */
function register_user($username, $password, $email, $role = 'subscriber') {
    global $pdo;
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $pdo->prepare("INSERT INTO " . table_name('users') . " (username, email, password_hash) VALUES (?, ?, ?)");
    $success = $stmt->execute([$username, $email, $hash]);
    if (!$success) return false;
    $user_id = $pdo->lastInsertId();
    update_user_meta($user_id, 'role', $role);
    $caps = get_role_capabilities($role);
    assign_capabilities_to_user($user_id, $caps);
    return true;
}

/**
 * Login user using token-based auth (no PHP session writes).
 */
function login_user($username, $password, $ttl = null) {
    $user = get_user_by_username($username);
    if (!$user) return false;
    // get raw DB row if format_user_row returned WP-like fields
    $raw = is_array($user['raw'] ?? null) ? $user['raw'] : $user;
    $stored_hash = $raw['password_hash'] ?? $raw['password'] ?? null;
    if ($stored_hash && password_verify($password, $stored_hash)) {
        // Ensure we have a numeric user id
        $uid = (int)($raw['id'] ?? $user['ID'] ?? 0);
        if ($uid <= 0) return false;
        // Create DB-backed token and set token cookie (for cross-server consistency)
        $tokenRow = null;
        if (function_exists('create_session_token')) {
            $tokenRow = $ttl === null ? create_session_token($uid) : create_session_token($uid, (int)$ttl);
            if ($tokenRow && function_exists('set_session_token_cookie')) {
                set_session_token_cookie($tokenRow);
            }
        }
        // Return token row when available for callers that want to manage TTL.
        return $tokenRow ?: true;
    }
    return false;
}
function login_user_versatile($username, $password, $ttl = null) {
    if ($username === '') return false;
    $user = null;
    // Try email lookup when input contains '@'
    if (strpos($username, '@') !== false) {
        if (function_exists('get_user_by_email')) {
            $user = get_user_by_email($username);
        } elseif (function_exists('get_user_by')) {
            $user = get_user_by('email', $username);
        }
    }
    // Fallback to username/login lookup
    if (empty($user)) {
        if (function_exists('get_user_by_username')) {
            $user = get_user_by_username($username);
        } elseif (function_exists('get_user_by')) {
            $user = get_user_by('login', $username);
        }
    }
    if (!$user) return false;
    $raw = is_array($user['raw'] ?? null) ? $user['raw'] : $user;
    $stored_hash = $raw['password_hash'] ?? $raw['password'] ?? null;
    if ($stored_hash && password_verify($password, $stored_hash)) {
        $uid = (int)($raw['id'] ?? $user['ID'] ?? 0);
        if ($uid <= 0) return false;
        $tokenRow = null;
        if (function_exists('create_session_token')) {
            $tokenRow = $ttl === null ? create_session_token($uid) : create_session_token($uid, (int)$ttl);
            if ($tokenRow && function_exists('set_session_token_cookie')) {
                set_session_token_cookie($tokenRow);
            }
        }
        return $tokenRow ?: true;
    }
    return false;
}

/**
 * Check if user logged in via token cookie.
 */
function qp_current_user_id() {
    static $cached = false;
    static $uid = null;
    if ($cached) return $uid;
    $cached = true;
    $cookie = $_COOKIE[QP_SESSION_COOKIE] ?? '';
    $row = validate_session_token($cookie);
    if ($row) $uid = (int)$row['user_id'];
    return $uid;
}

function is_logged_in() {
    return (bool)qp_current_user_id();
}

/**
 * Check whether current user has a capability.
 * Compatible with `current_user_can('cap')` calls elsewhere.
 */
function current_user_can(string $cap): bool {
    if (empty($cap)) return false;
    $user = get_logged_in_user();
    if (empty($user)) return false;
    $user_id = (int)$user['id'];
    // Capabilities assigned directly to the user
    $user_caps = get_user_meta($user_id, 'capabilities');
    if (empty($user_caps)) $user_caps = [];
    if (!is_array($user_caps)) $user_caps = (array)$user_caps;
    $role = get_user_meta($user_id, 'role');
    $role_caps = [];
    if (!empty($role)) $role_caps = get_role_capabilities($role);
    $all_caps = array_merge($role_caps, $user_caps);
    return in_array($cap, $all_caps, true);
}

/**
 * Get logged in user data (cached). Uses token cookie validation.
 */
function get_logged_in_user() {
    static $cached = null;
    if ($cached !== null) return $cached;
    $user_id = qp_current_user_id();
    if (empty($user_id)) { $cached = null; return null; }
    global $pdo;
    $stmt = $pdo->prepare("SELECT * FROM " . table_name('users') . " WHERE id = ?");
    $stmt->execute([$user_id]);
    $cached = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    return $cached;
}

/**
 * Check if current user has a capability.
 */
function check_permission($capability) {
    if (empty($capability)) return false;
    $user = get_logged_in_user();
    if (empty($user)) return false;
    $user_id = (int)$user['id'];
    // Prefer formatted user (includes role caps + user caps)
    if (function_exists('get_user_by')) {
        $u = get_user_by('id', $user_id);
        if (is_array($u) && !empty($u['capabilities'])) {
            return in_array($capability, (array)$u['capabilities'], true);
        }
    }
    // Fallback: check usermeta 'capabilities' only
    $caps = get_user_meta($user_id, 'capabilities');
    if (!is_array($caps)) $caps = (array)$caps;
    return in_array($capability, $caps, true);
}

/**
 * Ensure the `posts.status` ENUM contains the 'trash' value. Runs only for
 * privileged logged-in users and is safe to call repeatedly.
 */
function qp_ensure_post_status_has_trash(): void {
    try {
        if (!function_exists('is_logged_in') || !is_logged_in()) return;
        if (!function_exists('check_permission') || !check_permission('manage_posts')) return;
        $pdo = db();
        $table = table_name('posts');
        $stmt = $pdo->query("SHOW COLUMNS FROM `{$table}` LIKE 'status'");
        $col = $stmt->fetch(PDO::FETCH_ASSOC);
        if (empty($col) || empty($col['Type'])) return;
        $type = $col['Type']; // e.g. enum('draft','published',...)
        if (stripos($type, "'trash'") !== false) return; // already present

        // Extract existing enum values
        if (preg_match("/^enum\((.*)\)/", $type, $m)) {
            $inside = $m[1];
            // split on comma that separates quoted values
            $parts = preg_split("/,(?=(?:[^']*'[^']*')*[^']*$)/", $inside);
            $vals = array_map(function($v){ return trim($v); }, $parts);
            // Add 'trash' at end
            $vals[] = "'trash'";
            $newEnum = 'ENUM(' . implode(',', $vals) . ') DEFAULT \'draft\'';
            $sql = "ALTER TABLE `{$table}` MODIFY status {$newEnum}";
            $pdo->exec($sql);
        }
    } catch (Exception $e) {
        // Safe to ignore; migration will be retried later if necessary.
    }
}

// Ensure DB has 'trash' status for posts when admin is active
qp_ensure_post_status_has_trash();

/**
 * Ensure `posts.author_id` is NOT NULL and has default 0. Runs for admins only.
 */
function qp_ensure_posts_author_notnull(): void {
    try {
        if (!function_exists('is_logged_in') || !is_logged_in()) return;
        if (!function_exists('check_permission') || !check_permission('manage_posts')) return;
        $pdo = db();
        $table = table_name('posts');
        // Set any existing NULL author_id values to 0 first
        $pdo->exec("UPDATE `{$table}` SET author_id = 0 WHERE author_id IS NULL");
        // Alter column to NOT NULL DEFAULT 0
        $pdo->exec("ALTER TABLE `{$table}` MODIFY author_id INT NOT NULL DEFAULT 0");
    } catch (Exception $e) {
        // Non-fatal: ignore and retry later
    }
}

// Ensure posts.author_id constraint when admin is active
qp_ensure_posts_author_notnull();

function logout_user() {
    // Use centralized helper to revoke and clear
    if (function_exists('qp_clear_auth_cookie')) qp_clear_auth_cookie(true);
    else {
        $cookie = $_COOKIE[QP_SESSION_COOKIE] ?? '';
        if ($cookie) {
            $parts = explode('|', $cookie);
            if (count($parts) >= 2 && function_exists('revoke_session_token')) {
                revoke_session_token($parts[0], (int)$parts[1]);
            }
        }
        setcookie(QP_SESSION_COOKIE, '', time() - 42000, '/', '', true, true);
        unset($_COOKIE[QP_SESSION_COOKIE]);
    }
    // Clear cached current user
    if (isset($GLOBALS['qp_current_user'])) unset($GLOBALS['qp_current_user']);
    return true;
}


/**
 * ---------- AJAX HANDLERS REGISTRATION ----------
 */

require_once __DIR__ . '/includes/ajax-hooks.php';

// User Management Handlers

add_action('iitcm_ajax_add_user', function($req) {
    if (!check_permission('manage_users')) {
        echo json_encode(['status' => 'error', 'message' => 'No permission']); exit;
    }
    $username = trim($req['username'] ?? '');
    $email = trim($req['email'] ?? '');
    $password = $req['password'] ?? '';
    $role = $req['role'] ?? 'subscriber';

    if (empty($username) || empty($email) || empty($password)) {
        echo json_encode(['status' => 'error', 'message' => 'Missing fields']); exit;
    }
    if (register_user($username, $password, $email, $role)) {
        echo json_encode(['status' => 'success', 'message' => 'User added']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Failed to add user']);
    }
    exit;
}, 10, 1);

add_action('iitcm_ajax_delete_user', function($req) {
    if (!check_permission('manage_users')) {
        echo json_encode(['status' => 'error', 'message' => 'No permission']); exit;
    }
    global $pdo;
    $user_id = intval($req['user_id'] ?? 0);
    if ($user_id <= 0) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid user ID']); exit;
    }
    try {
        $stmt = $pdo->prepare("DELETE FROM " . table_name('users') . " WHERE id = ?");
        $stmt->execute([$user_id]);
        echo json_encode(['status' => 'success', 'message' => 'User deleted']);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => 'Delete failed']);
    }
    exit;
}, 10, 1);

add_action('iitcm_ajax_update_user', function($req) {
    if (!check_permission('manage_users')) {
        echo json_encode(['status' => 'error', 'message' => 'No permission']); exit;
    }
    global $pdo;
    $user_id = intval($req['user_id'] ?? 0);
    $email = trim($req['email'] ?? '');
    $role = $req['role'] ?? '';
    $password = $req['password'] ?? '';

    if ($user_id <= 0 || empty($email) || empty($role)) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid input']); exit;
    }
    try {
        if ($password) {
            $password_hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("UPDATE " . table_name('users') . " SET email = ?, role = ?, password_hash = ? WHERE id = ?");
            $stmt->execute([$email, $role, $password_hash, $user_id]);
        } else {
            $stmt = $pdo->prepare("UPDATE " . table_name('users') . " SET email = ?, role = ? WHERE id = ?");
            $stmt->execute([$email, $role, $user_id]);
        }
        update_user_meta($user_id, 'role', $role);
        $caps = get_role_capabilities($role);
        assign_capabilities_to_user($user_id, $caps);

        echo json_encode(['status' => 'success', 'message' => 'User updated']);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => 'Update failed']);
    }
    exit;
}, 10, 1);

// Rotate security salt on-demand (AJAX).
add_action('iitcm_ajax_rotate_salt', function($req) {
    if (!check_permission('manage_options')) {
        echo json_encode(['status' => 'error', 'message' => 'No permission']); exit;
    }
    $config_file = __DIR__ . '/config.php';
    if (!file_exists($config_file) || !is_writable($config_file)) {
        echo json_encode(['status' => 'error', 'message' => 'Config file missing or not writable']); exit;
    }
    // Load existing config, update salt
    $cfg = require $config_file;
    try {
        $new_salt = bin2hex(random_bytes(32));
    } catch (Exception $e) {
        // Fallback to less strong but usable salt
        $new_salt = bin2hex(openssl_random_pseudo_bytes(32));
    }
    $cfg['security_salt'] = $new_salt;
    $tmp = $config_file . '.tmp';
    $content = "<?php\nreturn " . var_export($cfg, true) . ";\n";
    if (file_put_contents($tmp, $content) === false) {
        echo json_encode(['status' => 'error', 'message' => 'Failed to write temp config']); exit;
    }
    if (!@rename($tmp, $config_file)) {
        @unlink($tmp);
        echo json_encode(['status' => 'error', 'message' => 'Failed to replace config']); exit;
    }
    // For security, revoke all existing session tokens so clients must re-authenticate.
    try {
        global $pdo;
        $table = table_name('user_meta');
        $stmt = $pdo->prepare("DELETE FROM {$table} WHERE meta_key = 'session_tokens'");
        $stmt->execute();
        $rows = method_exists($stmt,'rowCount') ? $stmt->rowCount() : null;
        // Rotation: all session tokens deleted (logging removed)
        // purge any caches if present
        // (best-effort: no central list of users here)
    } catch (Exception $e) {
        // Rotation failed (logging removed); non-fatal
    }
    echo json_encode(['status' => 'success', 'message' => 'Security salt rotated']);
    exit;
}, 10, 1);

// Frontend logout handler (supports both logged-in and nopriv requests)
add_action('iitcm_ajax_nopriv_ajax_logout', function($req) {
    header('Content-Type: application/json');
    try {
        $ok = logout_user();
        echo json_encode(['status' => $ok ? 'success' : 'error', 'message' => $ok ? 'Logged out' : 'Logout failed']);
    } catch (Throwable $e) {
        echo json_encode(['status' => 'error', 'message' => 'Server error']);
    }
    exit;
}, 10, 1);

add_action('iitcm_ajax_ajax_logout', function($req) {
    header('Content-Type: application/json');
    try {
        $ok = logout_user();
        echo json_encode(['status' => $ok ? 'success' : 'error', 'message' => $ok ? 'Logged out' : 'Logout failed']);
    } catch (Throwable $e) {
        echo json_encode(['status' => 'error', 'message' => 'Server error']);
    }
    exit;
}, 10, 1);


// Post Management Handlers

add_action('iitcm_ajax_add_post', function($req) {
    if (!check_permission('manage_posts')) { echo json_encode(['status' => 'error', 'message' => 'No permission']); exit; }
    global $pdo;

    $title = trim($req['title'] ?? '');
    $slug = trim($req['slug'] ?? '');
    $content = $req['content'] ?? '';
    $post_type = $req['post_type'] ?? 'post';
    $status = $req['status'] ?? 'draft';
    $terms = $req['terms'] ?? [];
    $lu = get_logged_in_user(); $author_id = $lu['id'] ?? null;

    if (!$title || !$slug || !$content) {
        echo json_encode(['status' => 'error', 'message' => 'Missing required fields']);
        exit;
    }

    try {
        $stmt = $pdo->prepare("INSERT INTO " . table_name('posts') . " (title, slug, content, post_type, status, author_id) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$title, $slug, $content, $post_type, $status, $author_id]);

        $post_id = $pdo->lastInsertId();

        foreach ($terms as $taxonomy => $term_ids) {
            if (is_array($term_ids)) {
                foreach ($term_ids as $term_id) {
                    $stmt = $pdo->prepare("INSERT IGNORE INTO " . table_name('post_terms') . " (post_id, term_id) VALUES (?,?)");
                    $stmt->execute([$post_id, $term_id]);
                }
            }
        }

        echo json_encode(['status' => 'success', 'message' => 'Post added']);
        if (function_exists('do_action')) do_action('save_post', (int)$post_id);
    } catch (PDOException $e) {
        echo json_encode(['status' => 'error', 'message' => 'Add post failed: ' . $e->getMessage()]);
    }
    exit;
}, 10, 1);

add_action('iitcm_ajax_update_post', function($req) {
    if (!check_permission('manage_posts')) { echo json_encode(['status' => 'error', 'message' => 'No permission']); exit; }
    global $pdo;

    $post_id = intval($req['post_id'] ?? 0);
    $title = trim($req['title'] ?? '');
    $slug = trim($req['slug'] ?? '');
    $content = $req['content'] ?? '';
    $post_type = $req['post_type'] ?? 'post';
    $status = $req['status'] ?? 'draft';
    $terms = $req['terms'] ?? [];

    if ($post_id <= 0 || !$title || !$slug || !$content) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid input']);
        exit;
    }

    try {
        $stmt = $pdo->prepare("UPDATE " . table_name('posts') . " SET title = ?, slug = ?, content = ?, post_type = ?, status = ? WHERE id = ?");
        $stmt->execute([$title, $slug, $content, $post_type, $status, $post_id]);

        $stmt = $pdo->prepare("DELETE FROM " . table_name('post_terms') . " WHERE post_id = ?");
        $stmt->execute([$post_id]);

        foreach ($terms as $taxonomy => $term_ids) {
            if (is_array($term_ids)) {
                foreach ($term_ids as $term_id) {
                    $stmt = $pdo->prepare("INSERT IGNORE INTO " . table_name('post_terms') . " (post_id, term_id) VALUES (?, ?)");
                    $stmt->execute([$post_id, $term_id]);
                }
            }
        }

        echo json_encode(['status' => 'success', 'message' => 'Post updated']);
        if (function_exists('do_action')) do_action('save_post', (int)$post_id);
    } catch (PDOException $e) {
        echo json_encode(['status' => 'error', 'message' => 'Update failed']);
    }
    exit;
}, 10, 1);

add_action('iitcm_ajax_delete_post', function($req) {
    if (!check_permission('manage_posts')) { echo json_encode(['status' => 'error', 'message' => 'No permission']); exit; }
    $post_id = intval($req['post_id'] ?? 0);
    if ($post_id <= 0) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid post ID']);
        exit;
    }
    global $pdo;
    try {
        $stmt = $pdo->prepare("DELETE FROM " . table_name('posts') . " WHERE id = ?");
        $stmt->execute([$post_id]);
        echo json_encode(['status' => 'success', 'message' => 'Post deleted']);
        if (function_exists('do_action')) do_action('delete_post', (int)$post_id);
    } catch (PDOException $e) {
        echo json_encode(['status' => 'error', 'message' => 'Delete failed']);
    }
    exit;
}, 10, 1);

// Move a post to Trash (soft-delete)
add_action('iitcm_ajax_trash_post', function($req) {
    if (!check_permission('manage_posts')) { echo json_encode(['status' => 'error', 'message' => 'No permission']); exit; }
    $post_id = intval($req['post_id'] ?? 0);
    if ($post_id <= 0) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid post ID']);
        exit;
    }
    $pdo = db();
    try {
        // Ensure post exists
        $check = $pdo->prepare('SELECT status FROM ' . table_name('posts') . ' WHERE id = ? LIMIT 1');
        $check->execute([$post_id]);
        $cur = $check->fetchColumn();
        if ($cur === false) {
            echo json_encode(['status' => 'error', 'message' => 'Post not found']); exit;
        }
        if ($cur === 'trash') {
            echo json_encode(['status' => 'error', 'message' => 'Post already in Trash']); exit;
        }

        $stmt = $pdo->prepare("UPDATE " . table_name('posts') . " SET status = ? WHERE id = ?");
        $stmt->execute(['trash', $post_id]);
        if ($stmt->rowCount() > 0) {
            echo json_encode(['status' => 'success', 'message' => 'Post moved to Trash']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'No changes made']);
        }
    } catch (PDOException $e) {
        echo json_encode(['status' => 'error', 'message' => 'Failed to move to Trash: ' . $e->getMessage()]);
    }
    exit;
}, 10, 1);

// Restore a trashed post (optionally to a given status)
add_action('iitcm_ajax_restore_post', function($req) {
    if (!check_permission('manage_posts')) { echo json_encode(['status' => 'error', 'message' => 'No permission']); exit; }
    $post_id = intval($req['post_id'] ?? 0);
    if ($post_id <= 0) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid post ID']);
        exit;
    }
    $restore_to = $req['restore_to'] ?? 'draft';
    if (!in_array($restore_to, ['draft','published'], true)) $restore_to = 'draft';
    $pdo = db();
    try {
        $check = $pdo->prepare('SELECT status FROM ' . table_name('posts') . ' WHERE id = ? LIMIT 1');
        $check->execute([$post_id]);
        $cur = $check->fetchColumn();
        if ($cur === false) { echo json_encode(['status' => 'error', 'message' => 'Post not found']); exit; }
        if ($cur !== 'trash') { echo json_encode(['status' => 'error', 'message' => 'Post is not in Trash']); exit; }

        $stmt = $pdo->prepare("UPDATE " . table_name('posts') . " SET status = ? WHERE id = ?");
        $stmt->execute([$restore_to, $post_id]);
        if ($stmt->rowCount() > 0) echo json_encode(['status' => 'success', 'message' => 'Post restored']);
        else echo json_encode(['status' => 'error', 'message' => 'No changes made']);
    } catch (PDOException $e) {
        echo json_encode(['status' => 'error', 'message' => 'Restore failed: ' . $e->getMessage()]);
    }
    exit;
}, 10, 1);

// Permanently delete a post and its attachments/meta
add_action('iitcm_ajax_delete_post_permanent', function($req) {
    if (!check_permission('manage_posts')) { echo json_encode(['status' => 'error', 'message' => 'No permission']); exit; }
    $post_id = intval($req['post_id'] ?? 0);
    if ($post_id <= 0) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid post ID']);
        exit;
    }
    $pdo = db();
    try {
        // Ensure post exists
        $check = $pdo->prepare('SELECT id FROM ' . table_name('posts') . ' WHERE id = ? LIMIT 1');
        $check->execute([$post_id]);
        if ($check->fetchColumn() === false) { echo json_encode(['status' => 'error', 'message' => 'Post not found']); exit; }

        $pdo->beginTransaction();

        // Find attachments that reference this post as parent
        $stmt = $pdo->prepare("SELECT a.id FROM " . table_name('posts') . " a
            JOIN " . table_name('post_meta') . " pm1 ON pm1.post_id = a.id AND pm1.meta_key = '_parent_id'
            JOIN " . table_name('post_meta') . " pm2 ON pm2.post_id = a.id AND pm2.meta_key = '_parent_type'
            WHERE pm1.meta_value = ? AND pm2.meta_value = 'post'");
        $stmt->execute([$post_id]);
        $atts = $stmt->fetchAll(PDO::FETCH_COLUMN);

        foreach ($atts as $aid) {
            $aid = (int)$aid;
            $meta = function_exists('qp_get_attachment_metadata') ? qp_get_attachment_metadata($aid) : null;
            if ($meta) {
                @unlink($meta['file']['path'] ?? '');
                foreach (($meta['sizes'] ?? []) as $s) {
                    if (!empty($s['path'])) @unlink($s['path']);
                }
            }
            $pdo->prepare('DELETE FROM ' . table_name('post_meta') . ' WHERE post_id = ?')->execute([$aid]);
            $pdo->prepare('DELETE FROM ' . table_name('posts') . ' WHERE id = ? AND post_type = ?')->execute([$aid, 'attachment']);
        }

        // Remove meta, term relations and the post itself
        $pdo->prepare('DELETE FROM ' . table_name('post_meta') . ' WHERE post_id = ?')->execute([$post_id]);
        $pdo->prepare('DELETE FROM ' . table_name('post_terms') . ' WHERE post_id = ?')->execute([$post_id]);
        $pdo->prepare('DELETE FROM ' . table_name('posts') . ' WHERE id = ?')->execute([$post_id]);

        $pdo->commit();
        echo json_encode(['status' => 'success', 'message' => 'Post permanently deleted']);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['status' => 'error', 'message' => 'Permanent delete failed']);
    }
    exit;
}, 10, 1);

// Bulk post actions: trash / restore / delete_permanent
add_action('iitcm_ajax_bulk_posts', function($req) {
    if (!check_permission('manage_posts')) { echo json_encode(['status' => 'error', 'message' => 'No permission']); exit; }
    $bulk_action = $req['bulk_action'] ?? '';
    $ids = $req['ids'] ?? [];
    if (is_string($ids)) {
        $ids = array_filter(array_map('trim', explode(',', $ids)));
    }
    $ids = array_map('intval', (array)$ids);
    $ids = array_filter($ids);
    if (empty($ids)) { echo json_encode(['status' => 'error', 'message' => 'No IDs provided']); exit; }

    $pdo = db();
    try {
        $pdo->beginTransaction();
        $processed = 0;

        foreach ($ids as $post_id) {
            $post_id = (int)$post_id;
            if ($post_id <= 0) continue;

            if ($bulk_action === 'trash') {
                $stmt = $pdo->prepare('UPDATE ' . table_name('posts') . ' SET status = ? WHERE id = ? AND status != ?');
                $stmt->execute(['trash', $post_id, 'trash']);
                $processed += $stmt->rowCount();
                continue;
            }

            if ($bulk_action === 'restore') {
                $restore_to = $req['restore_to'] ?? 'draft';
                if (!in_array($restore_to, ['draft','published'], true)) $restore_to = 'draft';
                $stmt = $pdo->prepare('UPDATE ' . table_name('posts') . ' SET status = ? WHERE id = ? AND status = ?');
                $stmt->execute([$restore_to, $post_id, 'trash']);
                $processed += $stmt->rowCount();
                continue;
            }

            if ($bulk_action === 'delete_permanent') {
                // Find attachments linked to this post
                $stmt = $pdo->prepare("SELECT a.id FROM " . table_name('posts') . " a
                    JOIN " . table_name('post_meta') . " pm1 ON pm1.post_id = a.id AND pm1.meta_key = '_parent_id'
                    JOIN " . table_name('post_meta') . " pm2 ON pm2.post_id = a.id AND pm2.meta_key = '_parent_type'
                    WHERE pm1.meta_value = ? AND pm2.meta_value = 'post'");
                $stmt->execute([$post_id]);
                $atts = $stmt->fetchAll(PDO::FETCH_COLUMN);

                foreach ($atts as $aid) {
                    $aid = (int)$aid;
                    $meta = function_exists('qp_get_attachment_metadata') ? qp_get_attachment_metadata($aid) : null;
                    if ($meta) {
                        @unlink($meta['file']['path'] ?? '');
                        foreach (($meta['sizes'] ?? []) as $s) {
                            if (!empty($s['path'])) @unlink($s['path']);
                        }
                    }
                    $pdo->prepare('DELETE FROM ' . table_name('post_meta') . ' WHERE post_id = ?')->execute([$aid]);
                    $pdo->prepare('DELETE FROM ' . table_name('posts') . ' WHERE id = ? AND post_type = ?')->execute([$aid, 'attachment']);
                }

                // Remove meta, term relations and the post itself
                $pdo->prepare('DELETE FROM ' . table_name('post_meta') . ' WHERE post_id = ?')->execute([$post_id]);
                $pdo->prepare('DELETE FROM ' . table_name('post_terms') . ' WHERE post_id = ?')->execute([$post_id]);
                $pdo->prepare('DELETE FROM ' . table_name('posts') . ' WHERE id = ?')->execute([$post_id]);
                $processed++;
                continue;
            }
        }

        $pdo->commit();
        echo json_encode(['status' => 'success', 'processed' => $processed]);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['status' => 'error', 'message' => 'Bulk action failed: ' . $e->getMessage()]);
    }
    exit;
}, 10, 1);

// Media upload (AJAX)
add_action('qp_media_upload', function($req) {
    if (!check_permission('manage_posts')) { echo json_encode(['status' => 'error', 'message' => 'No permission']); exit; }
    if (!isset($_FILES['file'])) { echo json_encode(['status' => 'error', 'message' => 'No file']); exit; }
    // Respect optional upload options from request: folder, use_date_folders, skip_sizes,
    // compress_original and original_quality
    $opts = [];
    if (!empty($req['folder'])) $opts['folder'] = preg_replace('/[^a-zA-Z0-9_-]+/', '', (string)$req['folder']);
    if (array_key_exists('use_date_folders', $req)) $opts['use_date_folders'] = filter_var($req['use_date_folders'], FILTER_VALIDATE_BOOLEAN);
    if (array_key_exists('skip_sizes', $req)) $opts['skip_sizes'] = filter_var($req['skip_sizes'], FILTER_VALIDATE_BOOLEAN);
    if (array_key_exists('compress_original', $req)) $opts['compress_original'] = filter_var($req['compress_original'], FILTER_VALIDATE_BOOLEAN);
    if (array_key_exists('original_quality', $req)) $opts['original_quality'] = intval($req['original_quality']);
    [$ok, $res] = qp_handle_upload_extensive($_FILES['file'], null, $opts);
    if ($ok) {
        // If parent_id and parent_type provided, attach immediately to avoid orphans
        $parent_id = isset($req['parent_id']) ? intval($req['parent_id']) : 0;
        $parent_type = $req['parent_type'] ?? '';
        $field_id = $req['field_id'] ?? '';
        $is_multiple = !empty($req['is_multiple']);
        
        if ($parent_id > 0 && $parent_type && $field_id) {
            $pdo = db();
            
            if (in_array($parent_type, ['post', 'term'], true)) {
                // Store parent relationship in post_meta for reference
                $pdo->prepare('INSERT INTO ' . table_name('post_meta') . ' (post_id, meta_key, meta_value) VALUES (?,?,?) ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value)')
                    ->execute([$res['post_id'], '_parent_id', $parent_id]);
                $pdo->prepare('INSERT INTO ' . table_name('post_meta') . ' (post_id, meta_key, meta_value) VALUES (?,?,?) ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value)')
                    ->execute([$res['post_id'], '_parent_type', $parent_type]);
                
                // Immediately save attachment ID to the parent's meta (post_meta or term_meta)
                $table = $parent_type === 'post' ? table_name('post_meta') : table_name('term_meta');
                $id_col = $parent_type === 'post' ? 'post_id' : 'term_id';
                
                if ($is_multiple) {
                    // Multiple field: get existing array and append
                    $stmt = $pdo->prepare("SELECT meta_value FROM {$table} WHERE {$id_col} = ? AND meta_key = ?");
                    $stmt->execute([$parent_id, $field_id]);
                    $existing = $stmt->fetchColumn();
                    
                    $attachmentIds = [];
                    if ($existing) {
                        $decoded = json_decode($existing, true);
                        $attachmentIds = is_array($decoded) ? $decoded : [];
                    }
                    $attachmentIds[] = $res['post_id'];
                    
                    $pdo->prepare("INSERT INTO {$table} ({$id_col}, meta_key, meta_value) VALUES (?,?,?) ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value)")
                        ->execute([$parent_id, $field_id, json_encode($attachmentIds)]);
                } else {
                    // Single field: replace value
                    $pdo->prepare("INSERT INTO {$table} ({$id_col}, meta_key, meta_value) VALUES (?,?,?) ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value)")
                        ->execute([$parent_id, $field_id, $res['post_id']]);
                }
            } elseif ($parent_type === 'option') {
                // For option type: save to site_options immediately
                // Mark attachment as belonging to this option
                $pdo->prepare('INSERT INTO ' . table_name('post_meta') . ' (post_id, meta_key, meta_value) VALUES (?,?,?) ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value)')
                    ->execute([$res['post_id'], '_parent_id', $parent_id]);
                $pdo->prepare('INSERT INTO ' . table_name('post_meta') . ' (post_id, meta_key, meta_value) VALUES (?,?,?) ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value)')
                    ->execute([$res['post_id'], '_parent_type', 'option']);
                
                // Get existing value and append new ID (for multiple uploads)
                if (function_exists('get_option_meta') && function_exists('update_option_meta')) {
                    $existing = get_option_meta($field_id);
                    
                    if ($is_multiple) {
                        // Multiple field: append to existing array
                        if (is_array($existing)) {
                            $existing[] = $res['post_id'];
                            update_option_meta($field_id, $existing);
                        } else {
                            // First upload for multiple field: create array
                            update_option_meta($field_id, [$res['post_id']]);
                        }
                    } else {
                        // Single field: replace existing value
                        update_option_meta($field_id, $res['post_id']);
                    }
                }
            }
        }
        // Allow plugins to react to newly uploaded media. Receives the upload result and the original request.
        do_action('qp_media_uploaded', $res, $req);
        echo json_encode(['status' => 'success', 'data' => $res]);
    } else {
        echo json_encode(['status' => 'error', 'message' => $res]);
    }
    exit;
}, 10, 1);

/*
Example plugin usage (place this in your plugin file to react to uploads):

add_action('qp_media_uploaded', function($res, $req) {
    // $res: upload result array (includes 'post_id', 'file', etc.)
    // $req: original request that initiated the upload (may include parent_id, parent_type, field_id)

    // Example: if upload was sent with parent_type='order' and parent_id set,
    // store a relation in a custom table `iit_order_media` to avoid orphaned attachments.
    $parent_id = isset($req['parent_id']) ? intval($req['parent_id']) : 0;
    $parent_type = $req['parent_type'] ?? '';

    if ($parent_id > 0 && $parent_type === 'order') {
        $pdo = db();
        $stmt = $pdo->prepare('INSERT INTO iit_order_media (order_id, attachment_id, created_at) VALUES (?, ?, NOW())');
        $stmt->execute([$parent_id, $res['post_id']]);
    }
}, 10, 2);

*/

// Media delete (AJAX)
add_action('qp_media_delete', function($req) {
    if (!check_permission('manage_posts')) { echo json_encode(['status' => 'error', 'message' => 'No permission']); exit; }
    $post_id = intval($req['post_id'] ?? 0);
    if ($post_id <= 0) { echo json_encode(['status' => 'error', 'message' => 'Invalid id']); exit; }
    $meta = qp_get_attachment_metadata($post_id);
    if (!$meta) { echo json_encode(['status' => 'error', 'message' => 'Not found']); exit; }
    // delete files (reconstruct full filesystem paths)
    $mainPath = qp_get_attachment_path($post_id);

    // Prepare uploads log early so file exists even if no derivatives are found
   /* $uploadsBase = qp_uploads_base();
    $logFile = rtrim($uploadsBase, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'qp_media_delete.log';
    $initialLine = date('c') . ' START ' . ($mainPath ?: '[no mainPath]') . ' sizes=' . count($meta['sizes'] ?? []) . PHP_EOL;
    @file_put_contents($logFile, $initialLine, FILE_APPEND | LOCK_EX);*/

    // Also remove generated derivatives for image attachments only.
    $is_image = false;
    $img_exts = ['jpg','jpeg','png','gif','webp','bmp','tiff','svg'];
    $fileExt = strtolower($meta['file']['ext'] ?? pathinfo($mainPath ?: '', PATHINFO_EXTENSION));
    $mime = $meta['file']['mime'] ?? '';
    if ($mime && stripos($mime, 'image/') === 0) $is_image = true;
    if (!$is_image && in_array($fileExt, $img_exts, true)) $is_image = true;
    if ($is_image && $mainPath && is_file($mainPath)) {
        $dir = dirname($mainPath);
        $baseName = pathinfo($mainPath, PATHINFO_FILENAME);
        // Delete any file starting with baseName- and having an image extension
        foreach ($img_exts as $extItem) {
            $pattern = $dir . DIRECTORY_SEPARATOR . $baseName . '-*.' . $extItem;
            foreach (glob($pattern) as $cand) {
                // Append log line before attempting deletion
            //    $line = date('c') . ' ' . $mainPath . ' ' . $baseName . ' ' . $cand . PHP_EOL;
            //    @file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
                if (is_file($cand)) @unlink($cand);
            }
        }
    }

    if ($mainPath) @unlink($mainPath);
    
   /* foreach (($meta['sizes'] ?? []) as $s) {
        $s_path = $s['path'] ?? '';
        if (!$s_path) continue;
        // If stored as absolute URL, map to local path
        if (preg_match('#^https?://#i', $s_path)) {
            $s_path_full = qp_map_uploads_url_to_path($s_path);
        } elseif (strpos($s_path, DIRECTORY_SEPARATOR) === 0 || preg_match('#^[A-Za-z]:\\#', $s_path)) {
            $s_path_full = $s_path;
        } else {
            $s_path_full = rtrim(qp_uploads_base(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . str_replace(['/', chr(92)], DIRECTORY_SEPARATOR, ltrim($s_path, '/' . chr(92)));
        }
        if ($s_path_full) @unlink($s_path_full);
    }*/
    
    // delete DB rows
    $pdo = db();
    $pdo->prepare('DELETE FROM ' . table_name('post_meta') . ' WHERE post_id = ? AND meta_key = ?')->execute([$post_id, '_file_metadata']);
    $pdo->prepare('DELETE FROM ' . table_name('posts') . ' WHERE id = ? AND post_type = ?')->execute([$post_id, 'attachment']);
    echo json_encode(['status' => 'success']);
    exit;
}, 10, 1);

// Media bulk delete
add_action('qp_media_bulk_delete', function($req) {
    if (!check_permission('manage_posts')) { echo json_encode(['status' => 'error', 'message' => 'No permission']); exit; }
    $ids = $req['ids'] ?? '';
    if (is_string($ids)) { $ids = array_filter(array_map('intval', explode(',', $ids))); }
    if (!is_array($ids) || empty($ids)) { echo json_encode(['status' => 'error', 'message' => 'No ids']); exit; }
    $pdo = db();
    foreach ($ids as $post_id) {
        $meta = qp_get_attachment_metadata((int)$post_id);
        if ($meta) {
            $mainPath = qp_get_attachment_path((int)$post_id);
            
            // remove generated derivatives for image attachments only
            $is_image = false;
            $img_exts = ['jpg','jpeg','png','gif','webp','bmp','tiff','svg'];
            $fileExt = strtolower($meta['file']['ext'] ?? pathinfo($mainPath ?: '', PATHINFO_EXTENSION));
            $mime = $meta['file']['mime'] ?? '';
            if ($mime && stripos($mime, 'image/') === 0) $is_image = true;
            if (!$is_image && in_array($fileExt, $img_exts, true)) $is_image = true;
            if ($is_image && $mainPath && is_file($mainPath)) {
                $dir = dirname($mainPath);
                $baseName = pathinfo($mainPath, PATHINFO_FILENAME);
                foreach ($img_exts as $extItem) {
                    $pattern = $dir . DIRECTORY_SEPARATOR . $baseName . '-*.' . $extItem;
                    foreach (glob($pattern) as $cand) {
                        if (is_file($cand)) @unlink($cand);
                    }
                }
            }


            if ($mainPath) @unlink($mainPath);
        /*    foreach (($meta['sizes'] ?? []) as $s) {
                $s_path = $s['path'] ?? '';
                if (!$s_path) continue;
                if (preg_match('#^https?://#i', $s_path)) {
                    $s_path_full = qp_map_uploads_url_to_path($s_path);
                } elseif (strpos($s_path, DIRECTORY_SEPARATOR) === 0 || preg_match('#^[A-Za-z]:\\#', $s_path)) {
                    $s_path_full = $s_path;
                } else {
                    $s_path_full = rtrim(qp_uploads_base(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . str_replace(['/', chr(92)], DIRECTORY_SEPARATOR, ltrim($s_path, '/' . chr(92)));
                }
                if ($s_path_full) @unlink($s_path_full);
            }*/
            
        }
        $pdo->prepare('DELETE FROM ' . table_name('post_meta') . ' WHERE post_id = ? AND meta_key = ?')->execute([$post_id, '_file_metadata']);
        $pdo->prepare('DELETE FROM ' . table_name('posts') . ' WHERE id = ? AND post_type = ?')->execute([$post_id, 'attachment']);
    }
    echo json_encode(['status' => 'success']);
    exit;
}, 10, 1);

// Media list (AJAX) for infinite scroll
add_action('qp_media_list', function($req) {
    if (!check_permission('manage_posts')) { echo json_encode(['status' => 'error', 'message' => 'No permission']); exit; }
    $page = max(1, (int)($req['p'] ?? 1));
    $per_page = max(1, min(60, (int)($req['per_page'] ?? 24)));
    $offset = ($page - 1) * $per_page;
    $file_mode = isset($req['file_mode']) ? strtolower(trim($req['file_mode'])) : '';
    $pdo = db();
    $uploaded_month = isset($req['uploaded_month']) ? trim($req['uploaded_month']) : '';
    $where = "WHERE post_type='attachment'";
    $params = [];
    if ($uploaded_month && preg_match('/^\d{4}-\d{2}$/', $uploaded_month)) {
        // filter by created_at month
        $start = $uploaded_month . '-01 00:00:00';
        $end = date('Y-m-d H:i:s', strtotime($start . ' +1 month -1 second'));
        $where .= ' AND created_at >= ? AND created_at <= ?';
        $params[] = $start;
        $params[] = $end;
    }
    // Apply file_mode filtering at the SQL level so pagination and counts reflect the filter.
    if ($file_mode && $file_mode !== 'all') {
        // Normalize
        $fm = strtolower($file_mode);
        // Use post_meta JSON search on _file_metadata to limit results. This is a pragmatic
        // approach that avoids loading all metadata into PHP before filtering.
        $metaTable = table_name('post_meta');
        if ($fm === 'images') {
            // Match common image types by looking for either explicit mime values or
            // extension/url occurrences in the stored JSON. Some metadata rows
            // may not have the mime key within the first bytes, so searching for
            // extensions is more reliable across legacy entries.
            $exts = ['jpg','jpeg','png','gif','webp','svg','bmp','tiff'];
            $orParts = [];
            foreach ($exts as $e) {
                $orParts[] = "meta_value LIKE ?"; // ext field
                $params[] = '%"ext":"' . $e . '"%';
                $orParts[] = "meta_value LIKE ?"; // filename/url contains extension
                $params[] = '%.' . $e . '%';
            }
            // Also try mime-based patterns (both plain and escaped slash)
            $orParts[] = "meta_value LIKE ?";
            $params[] = '%"mime":"image/%';
            $orParts[] = "meta_value LIKE ?";
            $params[] = '%"mime":"image\\/%';

            $where .= " AND id IN (SELECT post_id FROM {$metaTable} WHERE meta_key='_file_metadata' AND (" . implode(' OR ', $orParts) . "))";

            // Debug sampling removed in production.
        } elseif ($fm === 'pdf') {
            $where .= " AND id IN (SELECT post_id FROM {$metaTable} WHERE meta_key='_file_metadata' AND (meta_value LIKE ? OR meta_value LIKE ?) )";
            $params[] = '%"ext":"pdf"%';
            $params[] = '%"mime":"application/pdf"%';
        } else {
            // treat as extension filter
            $extFilter = preg_replace('/[^a-z0-9]+/i','', $fm);
            if ($extFilter !== '') {
                $where .= " AND id IN (SELECT post_id FROM {$metaTable} WHERE meta_key='_file_metadata' AND (meta_value LIKE ? OR meta_value LIKE ?) )";
                $params[] = '%"ext":"' . $extFilter . '"%';
                $params[] = '%"mime":"%' . $extFilter . '%';
            }
        }
    }
    $total_stmt = $pdo->prepare('SELECT COUNT(*) FROM ' . table_name('posts') . " " . $where);
    $total_stmt->execute($params);
    $total = (int)$total_stmt->fetchColumn();
    $sql = 'SELECT id, title FROM ' . table_name('posts') . ' ' . $where . ' ORDER BY id DESC LIMIT ? OFFSET ?';
    $stmt = $pdo->prepare($sql);
    // bind previous params first, then limit/offset
    $bindIndex = 1;
    foreach ($params as $p) {
        $stmt->bindValue($bindIndex, $p);
        $bindIndex++;
    }
    $stmt->bindValue($bindIndex++, $per_page, PDO::PARAM_INT);
    $stmt->bindValue($bindIndex++, $offset, PDO::PARAM_INT);
    $stmt->execute();
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $out = [];
    foreach ($items as $it) {
        $id = (int)$it['id'];
        $meta = qp_get_attachment_metadata($id);
        $thumb = qp_get_attachment_image_src($id, 'thumbnail');
        $url = $meta['file']['url'] ?? null;
        $mime = $meta['file']['mime'] ?? null;
        $thumbUrl = $thumb ? $thumb[0] : null;

        // Determine extension
        $ext = '';
        if ($url) {
            $path = parse_url($url, PHP_URL_PATH) ?: '';
            $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION) ?: '');
        }
        if (!$ext && $mime) {
            $mime_map = [
                'application/pdf' => 'pdf',
                'text/plain' => 'txt',
                'application/msword' => 'doc',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
                'application/vnd.ms-excel' => 'xls',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
            ];
            if (isset($mime_map[$mime])) $ext = $mime_map[$mime];
            else {
                $parts = explode('/', $mime);
                $ext = end($parts);
            }
        }

        // If no thumbnail and non-image, generate small SVG placeholder with ext label
        if (empty($thumbUrl)) {
            $previewable = false;
            if ($mime && stripos($mime, 'image/') === 0) $previewable = true;
            if (!$previewable) {
                $label = strtoupper($ext ?: 'FILE');
                $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="120" height="90">'
                     . '<rect width="100%" height="100%" fill="#f3f4f6"/>'
                     . '<text x="50%" y="50%" dominant-baseline="middle" text-anchor="middle"'
                     . ' font-family="Arial, Helvetica, sans-serif" font-size="16" fill="#374151">' . htmlspecialchars($label) . '</text>'
                     . '</svg>';
                $thumbUrl = 'data:image/svg+xml;utf8,' . rawurlencode($svg);
            }
        }

        // Ensure thumbnail and extension are always present (use empty string if unknown)
        if (empty($thumbUrl)) $thumbUrl = '';
        if ($ext === null) $ext = '';
        // As a last resort, try to derive extension from title
        if ($ext === '' && !empty($it['title'])) {
            $ext = strtolower(pathinfo($it['title'], PATHINFO_EXTENSION) ?: '');
        }

        $out[] = [
            'id' => $id,
            'title' => $it['title'],
            'url' => $url ?: '',
            'mime' => $mime ?: '',
            'thumb' => $thumbUrl,
            'extension' => $ext ?: '',
        ];
    }
    // Server-side file_mode filter: if requested, filter items accordingly
    if ($file_mode === 'images') {
        $out = array_values(array_filter($out, function($it){
            return !empty($it['mime']) && stripos($it['mime'], 'image/') === 0;
        }));
    } elseif ($file_mode === 'pdf') {
        $out = array_values(array_filter($out, function($it){
            return (!empty($it['mime']) && stripos($it['mime'], 'pdf') !== false) || (!empty($it['extension']) && strtolower($it['extension']) === 'pdf');
        }));
    } elseif ($file_mode && $file_mode !== 'all') {
        // treat file_mode as extension filter (e.g., 'docx', 'txt')
        $extFilter = strtolower(trim($file_mode));
        $out = array_values(array_filter($out, function($it) use ($extFilter){
            return (!empty($it['extension']) && strtolower($it['extension']) === $extFilter) || (!empty($it['mime']) && stripos($it['mime'], $extFilter) !== false);
        }));
    }

    echo json_encode(['status' => 'success', 'total' => $total, 'items' => $out]);
    // Debug logging removed.
    exit;
}, 10, 1);

// Provide available media filters (extensions and months)
add_action('qp_media_filters', function($req) {
    if (!check_permission('manage_posts')) { echo json_encode(['status'=>'error','message'=>'No permission']); exit; }
    $pdo = db();
    // Collect extensions and months from attachments
    $stmt = $pdo->query("SELECT id, created_at FROM " . table_name('posts') . " WHERE post_type='attachment' ORDER BY created_at DESC");
    $extensions = [];
    $months = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $id = (int)$row['id'];
        $meta = qp_get_attachment_metadata($id);
        $ext = '';
        if ($meta && !empty($meta['file']['ext'])) $ext = strtolower($meta['file']['ext']);
        else if ($meta && !empty($meta['file']['filename'])) $ext = strtolower(pathinfo($meta['file']['filename'], PATHINFO_EXTENSION) ?: '');
        if ($ext) $extensions[$ext] = true;
        $created = $row['created_at'] ?? null;
        if ($created) {
            $ym = date('Y-m', strtotime($created));
            if ($ym) $months[$ym] = true;
        }
    }
    // Sort extensions by frequency or alphabetic (alphabetic here)
    $extList = array_keys($extensions);
    sort($extList);
    // Build month list with readable labels
    $monthKeys = array_keys($months);
    rsort($monthKeys); // newest first
    $monthList = array_map(function($ym){
        $t = strtotime($ym . '-01');
        return ['value'=>$ym, 'label'=>date('F Y', $t)];
    }, $monthKeys);
    echo json_encode(['status'=>'success','extensions'=>$extList,'months'=>$monthList]);
    exit;
}, 10, 1);

// Map admin-prefixed AJAX hooks to media handlers for admin/ajax.php
add_action('iitcm_admin_ajax_qp_media_upload', function($req){ do_action('qp_media_upload', $req); }, 10, 1);
add_action('iitcm_admin_ajax_qp_media_delete', function($req){ do_action('qp_media_delete', $req); }, 10, 1);
add_action('iitcm_admin_ajax_qp_media_get', function($req){ do_action('qp_media_get', $req); }, 10, 1);

// Media get details
add_action('qp_media_get', function($req) {
    if (!check_permission('manage_posts')) { echo json_encode(['status' => 'error', 'message' => 'No permission']); exit; }
    $post_id = (int)($req['post_id'] ?? 0);
    if ($post_id <= 0) { echo json_encode(['status' => 'error', 'message' => 'Invalid id']); exit; }
    $pdo = db();
    $stmt = $pdo->prepare('SELECT p.id, p.title, p.created_at, p.author_id, u.username AS author_name FROM ' . table_name('posts') . ' p LEFT JOIN ' . table_name('users') . ' u ON p.author_id = u.id WHERE p.id = ? AND p.post_type = ? LIMIT 1');
    $stmt->execute([$post_id, 'attachment']);
    $post = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$post) { echo json_encode(['status' => 'error', 'message' => 'Not found']); exit; }
    $meta = qp_get_attachment_metadata($post_id);
    $alt = get_post_meta($post_id, '_alt') ?? '';
    $post['author_name'] = $post['author_name'] ?? null;
    // Ensure admin UI gets absolute URLs for preview/download
    if (is_array($meta) && !empty($meta['file'])) {
        $abs = qp_get_attachment_url($post_id);
        if ($abs) $meta['file']['url'] = $abs;
        // Normalize sizes to absolute URLs when present
        if (!empty($meta['sizes']) && is_array($meta['sizes'])) {
            foreach ($meta['sizes'] as $k => $s) {
                if (is_array($s) && !empty($s['url']) && !preg_match('#^https?://#i', $s['url'])) {
                    $meta['sizes'][$k]['url'] = rtrim(qp_uploads_url_base(), '/') . '/' . ltrim($s['url'], '/');
                }
            }
        }
    }
    // Provide site-formatted created_at for admin UI convenience
    $created_raw = $post['created_at'] ?? null;
    $post['created_at_formatted'] = (function_exists('format_site_datetime') ? format_site_datetime($created_raw) : $created_raw);
    echo json_encode([
        'status' => 'success',
        'post' => $post,
        'meta' => $meta,
        'alt' => $alt,
    ]);
    exit;
}, 10, 1);

// Media update (title, alt)
add_action('qp_media_update', function($req) {
    if (!check_permission('manage_posts')) { echo json_encode(['status' => 'error', 'message' => 'No permission']); exit; }
    $post_id = (int)($req['post_id'] ?? 0);
    $title = trim($req['title'] ?? '');
    $alt = trim($req['alt'] ?? '');
    if ($post_id <= 0) { echo json_encode(['status' => 'error', 'message' => 'Invalid id']); exit; }
    $pdo = db();
    // Update title
    if ($title !== '') {
        $stmt = $pdo->prepare('UPDATE ' . table_name('posts') . ' SET title = ? WHERE id = ? AND post_type = ?');
        $stmt->execute([$title, $post_id, 'attachment']);
    }
    // Update alt in post_meta
    update_post_meta($post_id, '_alt', $alt);
    echo json_encode(['status' => 'success']);
    exit;
}, 10, 1);


// Term Management Handlers

// Add a new taxonomy term
add_action('iitcm_ajax_add_term', function($req) {
    if (!check_permission('manage_posts')) {
        echo json_encode(['status' => 'error', 'message' => 'No permission']);
        exit;
    }
    global $pdo;
    $taxonomy = $req['taxonomy'] ?? '';
    $term = trim($req['term'] ?? '');
    $slug = trim($req['slug'] ?? '');
    $description = $req['description'] ?? '';
    $parent_id = isset($req['parent_id']) && $req['parent_id'] !== '' ? (int)$req['parent_id'] : null;
    $term_order = isset($req['term_order']) ? (int)$req['term_order'] : 0;

    if (!$taxonomy || !$term) {
        echo json_encode(['status' => 'error', 'message' => 'Missing taxonomy or term']);
        exit;
    }

    // Auto-generate slug if empty: lowercase, dash separated
    if ($slug === '') {
        $slug = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $term), '-'));
    }

    // Check term uniqueness within taxonomy (including slug)
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM " . table_name('taxonomy_terms') . " WHERE taxonomy = ? AND (term = ? OR slug = ?)");
    $stmt->execute([$taxonomy, $term, $slug]);
    if ($stmt->fetchColumn() > 0) {
        echo json_encode(['status' => 'error', 'message' => 'Term or slug already exists']);
        exit;
    }

    // Insert new term with parent and order
    $stmt = $pdo->prepare("INSERT INTO " . table_name('taxonomy_terms') . " (taxonomy, term, slug, parent_id, term_order) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([$taxonomy, $term, $slug, $parent_id, $term_order]);

    // Save description into term meta if provided
    if ($description !== '' && function_exists('update_term_meta')) {
        $new_id = (int)$pdo->lastInsertId();
        update_term_meta($new_id, 'description', $description);
    }

    echo json_encode(['status' => 'success', 'message' => 'Term added']);
    exit;
});




// Update taxonomy term (including slug, parent_id, term_order)
add_action('iitcm_ajax_update_term', function($req) {
    if (!check_permission('manage_posts')) {
        echo json_encode(['status' => 'error', 'message' => 'No permission']);
        exit;
    }
    global $pdo;
    $term_id = intval($req['term_id'] ?? 0);
    $new_term = trim($req['term'] ?? '');
    $slug = trim($req['slug'] ?? '');
    $parent_id = isset($req['parent_id']) && $req['parent_id'] !== '' ? (int)$req['parent_id'] : null;
    $term_order = isset($req['term_order']) ? (int)$req['term_order'] : 0;
    $taxonomy = $req['taxonomy'] ?? '';
    $description = $req['description'] ?? null;

    if ($term_id <= 0 || !$new_term || !$taxonomy) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid input']);
        exit;
    }

    if ($slug === '') {
        $slug = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $new_term), '-'));
    }

    // Check uniqueness of term and slug excluding current term
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM " . table_name('taxonomy_terms') . " WHERE taxonomy = ? AND (term = ? OR slug = ?) AND id != ?");
    $stmt->execute([$taxonomy, $new_term, $slug, $term_id]);
    if ($stmt->fetchColumn() > 0) {
        echo json_encode(['status' => 'error', 'message' => 'Term or slug already exists']);
        exit;
    }

    // Prevent setting parent to self
    if ($parent_id === $term_id) {
        echo json_encode(['status' => 'error', 'message' => 'Parent term cannot be self']);
        exit;
    }

    $stmt = $pdo->prepare("UPDATE " . table_name('taxonomy_terms') . " SET term = ?, slug = ?, parent_id = ?, term_order = ? WHERE id = ? AND taxonomy = ?");
    $ok = $stmt->execute([$new_term, $slug, $parent_id, $term_order, $term_id, $taxonomy]);

    if ($ok) {
        // Save description into term meta when present
        if ($description !== null && function_exists('update_term_meta')) {
            update_term_meta($term_id, 'description', $description);
        }
        echo json_encode(['status' => 'success', 'message' => 'Term updated']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Update failed']);
    }
    exit;
});





// Delete taxonomy term (consider child terms if necessary)
add_action('iitcm_ajax_delete_term', function($req) {
    if (!check_permission('manage_posts')) {
        echo json_encode(['status' => 'error', 'message' => 'No permission']);
        exit;
    }
    global $pdo;
    $term_id = intval($req['term_id'] ?? 0);
    $taxonomy = $req['taxonomy'] ?? '';

    if ($term_id <= 0 || !$taxonomy) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid input']);
        exit;
    }

    // Optional: handle cascade delete or reassign children here (not implemented)

    $stmt = $pdo->prepare("DELETE FROM " . table_name('taxonomy_terms') . " WHERE id = ? AND taxonomy = ?");
    $ok = $stmt->execute([$term_id, $taxonomy]);

    if ($ok) {
        echo json_encode(['status' => 'success', 'message' => 'Term deleted']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Delete failed']);
    }
    exit;
});



// Update term_order for multiple terms (after drag & drop or form submit)
add_action('iitcm_ajax_update_term_order', function($req) {
    if (!check_permission('manage_posts')) {
        echo json_encode(['status' => 'error', 'message' => 'No permission']);
        exit;
    }
    global $pdo;
    $taxonomy = $req['taxonomy'] ?? '';
    $orders = $req['order'] ?? [];

    if (!$taxonomy || !is_array($orders)) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid input']);
        exit;
    }

    $stmt = $pdo->prepare("UPDATE " . table_name('taxonomy_terms') . " SET term_order = ? WHERE id = ? AND taxonomy = ?");

    foreach ($orders as $term_id => $order) {
        $term_id = (int)$term_id;
        $order = (int)$order;
        $stmt->execute([$order, $term_id, $taxonomy]);
    }

    echo json_encode(['status' => 'success', 'message' => 'Order updated']);
    exit;
});




/**
 * Get term term meta from DB as per your schema.
 */
function get_term_meta($term_id, $key) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT meta_value FROM " . table_name('term_meta') . " WHERE term_id = ? AND meta_key = ?");
    $stmt->execute([$term_id, $key]);
    $value = $stmt->fetchColumn();
    if ($value !== false) {
        $decoded = json_decode($value, true);
        $ret = is_null($decoded) ? $value : $decoded;
        if (function_exists('apply_filters')) {
            $is_admin = false;
            if (!empty($_SERVER['SCRIPT_NAME']) && stripos($_SERVER['SCRIPT_NAME'], '/admin/') !== false) {
                $is_admin = true;
            }
            $lang = null;
            if (!$is_admin && function_exists('ml_get_current_lang')) {
                $lang = ml_get_current_lang();
            }
            $ret = apply_filters('ml_get_meta', $ret, $term_id, $key, $lang);
        }
        return $ret;
    }
    return null;
}

/**
 * Update term term meta in DB.
 */
function update_term_meta($term_id, $key, $value) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM " . table_name('term_meta') . " WHERE term_id = ? AND meta_key = ?");
    $stmt->execute([$term_id, $key]);
    if ($stmt->fetchColumn()) {
        $json = json_encode($value);
        $stmt = $pdo->prepare("UPDATE " . table_name('term_meta') . " SET meta_value = ? WHERE term_id = ? AND meta_key = ?");
        $stmt->execute([$json, $term_id, $key]);
    } else {
        $json = json_encode($value);
        $stmt = $pdo->prepare("INSERT INTO " . table_name('term_meta') . " (term_id, meta_key, meta_value) VALUES (?, ?, ?)");
        $stmt->execute([$term_id, $key, $json]);
    }
}

/**
 * WP-like term meta updater with return semantics similar to update_post_meta.
 * Returns new meta_id (int) on insert, true on update, false on no-change/failure.
 * Supports optional $prev_value to update only matching existing row.
 */
function update_term_metadata($term_id, $meta_key, $meta_value, $prev_value = '') {
    global $pdo;
    $term_id = (int)$term_id;
    $json_new = json_encode($meta_value);
    $has_prev = func_num_args() >= 4;
    $json_prev = $has_prev ? json_encode($prev_value) : null;

    // Fetch existing rows for this term+key
    $stmt = $pdo->prepare("SELECT id, meta_value FROM " . table_name('term_meta') . " WHERE term_id = ? AND meta_key = ? ORDER BY id ASC");
    $stmt->execute([$term_id, $meta_key]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!empty($rows)) {
        if ($has_prev) {
            foreach ($rows as $row) {
                if ($row['meta_value'] === $json_prev) {
                    if ($row['meta_value'] === $json_new) return false;
                    $u = $pdo->prepare("UPDATE " . table_name('term_meta') . " SET meta_value = ? WHERE id = ?");
                    $ok = $u->execute([$json_new, $row['id']]);
                    return $ok ? true : false;
                }
            }
            // No matching prev row -> insert new
            $i = $pdo->prepare("INSERT INTO " . table_name('term_meta') . " (term_id, meta_key, meta_value) VALUES (?, ?, ?)");
            $res = $i->execute([$term_id, $meta_key, $json_new]);
            if ($res) return (int)$pdo->lastInsertId();
            return false;
        } else {
            // No prev specified: operate on first existing row
            $first = $rows[0];
            if ($first['meta_value'] === $json_new) return false;
            $u = $pdo->prepare("UPDATE " . table_name('term_meta') . " SET meta_value = ? WHERE id = ?");
            $ok = $u->execute([$json_new, $first['id']]);
            return $ok ? true : false;
        }
    }

    // No existing rows: insert new
    $i = $pdo->prepare("INSERT INTO " . table_name('term_meta') . " (term_id, meta_key, meta_value) VALUES (?, ?, ?)");
    $res = $i->execute([$term_id, $meta_key, $json_new]);
    if ($res) return (int)$pdo->lastInsertId();
    return false;
}

/**
 * WP-like get_term_meta replacement that returns all values or a single value.
 * Signature mirrors WP: get_term_metadata($term_id, $key = '', $single = false)
 * - When `$key === ''` returns all meta as array: [ meta_key => [ values... ], ... ]
 * - When `$single === false` returns an array of values for the key
 * - When `$single === true` returns the first value or an empty string when none
 *
 * Uses `maybe_unserialize()` to restore PHP-serialized or JSON-encoded values
 * and applies `apply_filters('ml_get_meta', ...)` to each returned value.
 */
function get_term_metadata($term_id, $key = '', $single = false) {
    global $pdo;
    $term_id = (int)$term_id;
    if ($term_id <= 0) return $single ? '' : [];

    // Detect frontend vs admin for ml_get_meta filter consistency
    $is_admin = false;
    if (!empty($_SERVER['SCRIPT_NAME']) && stripos($_SERVER['SCRIPT_NAME'], '/admin/') !== false) {
        $is_admin = true;
    }
    $lang = null;
    if (!$is_admin && function_exists('ml_get_current_lang')) {
        $lang = ml_get_current_lang();
    }

    if ($key === '' || $key === null) {
        $stmt = $pdo->prepare("SELECT meta_key, meta_value FROM " . table_name('term_meta') . " WHERE term_id = ? ORDER BY id ASC");
        $stmt->execute([$term_id]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $out = [];
        foreach ($rows as $r) {
            $k = $r['meta_key'];
            $raw = $r['meta_value'];
            $val = maybe_unserialize($raw);
            if (function_exists('apply_filters')) $val = apply_filters('ml_get_meta', $val, $term_id, $k, $lang);
            $out[$k][] = $val;
        }
        return $out;
    }

    $stmt = $pdo->prepare("SELECT meta_value FROM " . table_name('term_meta') . " WHERE term_id = ? AND meta_key = ? ORDER BY id ASC");
    $stmt->execute([$term_id, $key]);
    $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);
    if (empty($rows)) {
        return $single ? '' : [];
    }

    $values = array_map(function($v){ return maybe_unserialize($v); }, $rows);
    if (function_exists('apply_filters')) {
        foreach ($values as $i => $val) {
            $values[$i] = apply_filters('ml_get_meta', $val, $term_id, $key, $lang);
        }
    }

    if ($single) return $values[0] ?? '';
    return $values;
}


// Example: Get post meta value by post ID and meta key
function get_post_meta($post_id, $meta_key) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT meta_value FROM " . table_name('post_meta') . " WHERE post_id = ? AND meta_key = ?");
    $stmt->execute([$post_id, $meta_key]);
    $value = $stmt->fetchColumn();
    if ($value !== false) {
        $decoded = json_decode($value, true);
        $ret = is_null($decoded) ? $value : $decoded; // decode JSON stored arrays/objects
        // Allow multilingual plugins to override meta values per language
        // but avoid applying language filtering in admin pages so editors
        // can access the raw stored arrays (e.g. i18n_content) for per-lang fields.
        if (function_exists('apply_filters')) {
            $lang = null;
            // Detect frontend vs admin by script path (simple heuristic)
            $is_admin = false;
            if (!empty($_SERVER['SCRIPT_NAME']) && stripos($_SERVER['SCRIPT_NAME'], '/admin/') !== false) {
                $is_admin = true;
            }
            if (!$is_admin && function_exists('ml_get_current_lang')) {
                $lang = ml_get_current_lang();
            }
            $ret = apply_filters('ml_get_meta', $ret, $post_id, $meta_key, $lang);
        }
        return $ret;
    }
    return null;
}

/**
 * WP-like get_post_meta replacement that returns all values or a single value.
 * Signature mirrors WP: get_post_metadata($post_id, $key = '', $single = false)
 * - When `$key === ''` returns all meta as array: [ meta_key => [ values... ], ... ]
 * - When `$single === false` returns an array of values for the key
 * - When `$single === true` returns the first value or an empty string when none
 *
 * Uses `maybe_unserialize()` to restore PHP-serialized or JSON-encoded values
 * and applies `apply_filters('ml_get_meta', ...)` to each returned value (same
 * multilingual hook used by `get_post_meta`).
 */
function get_post_metadata($post_id, $key = '', $single = false) {
    global $pdo;
    $post_id = (int)$post_id;
    if ($post_id <= 0) return $single ? '' : [];

    // Detect frontend vs admin for ml_get_meta filter consistency
    $is_admin = false;
    if (!empty($_SERVER['SCRIPT_NAME']) && stripos($_SERVER['SCRIPT_NAME'], '/admin/') !== false) {
        $is_admin = true;
    }
    $lang = null;
    if (!$is_admin && function_exists('ml_get_current_lang')) {
        $lang = ml_get_current_lang();
    }

    if ($key === '' || $key === null) {
        $stmt = $pdo->prepare("SELECT meta_key, meta_value FROM " . table_name('post_meta') . " WHERE post_id = ? ORDER BY id ASC");
        $stmt->execute([$post_id]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $out = [];
        foreach ($rows as $r) {
            $k = $r['meta_key'];
            $raw = $r['meta_value'];
            $val = maybe_unserialize($raw);
            if (function_exists('apply_filters')) $val = apply_filters('ml_get_meta', $val, $post_id, $k, $lang);
            $out[$k][] = $val;
        }
        return $out;
    }

    $stmt = $pdo->prepare("SELECT meta_value FROM " . table_name('post_meta') . " WHERE post_id = ? AND meta_key = ? ORDER BY id ASC");
    $stmt->execute([$post_id, $key]);
    $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (empty($rows)) {
        return $single ? '' : [];
    }

    $values = array_map(function($v){ return maybe_unserialize($v); }, $rows);
    if (function_exists('apply_filters')) {
        foreach ($values as $i => $val) {
            $values[$i] = apply_filters('ml_get_meta', $val, $post_id, $key, $lang);
        }
    }

    if ($single) return $values[0] ?? '';
    return $values;
}

// Example: Update or insert post meta key-value
function update_post_meta($post_id, $meta_key, $meta_value, $prev_value = '') {
    global $pdo;
    $json_new = json_encode($meta_value);
    $has_prev = func_num_args() >= 4;
    $json_prev = $has_prev ? json_encode($prev_value) : null;

    // Fetch existing rows for this post+key
    $stmt = $pdo->prepare("SELECT id, meta_value FROM " . table_name('post_meta') . " WHERE post_id = ? AND meta_key = ? ORDER BY id ASC");
    $stmt->execute([$post_id, $meta_key]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // If there are existing rows
    if (!empty($rows)) {
        if ($has_prev) {
            // Try to find a row matching $prev_value
            foreach ($rows as $row) {
                if ($row['meta_value'] === $json_prev) {
                    // If identical to new value, no change
                    if ($row['meta_value'] === $json_new) return false;
                    $u = $pdo->prepare("UPDATE " . table_name('post_meta') . " SET meta_value = ? WHERE id = ?");
                    $ok = $u->execute([$json_new, $row['id']]);
                    return $ok ? true : false;
                }
            }
            // No matching prev row found -> insert new meta row
            $i = $pdo->prepare("INSERT INTO " . table_name('post_meta') . " (post_id, meta_key, meta_value) VALUES (?, ?, ?)");
            $res = $i->execute([$post_id, $meta_key, $json_new]);
            if ($res) return (int)$pdo->lastInsertId();
            return false;
        } else {
            // No prev specified: operate on first existing row
            $first = $rows[0];
            if ($first['meta_value'] === $json_new) return false;
            $u = $pdo->prepare("UPDATE " . table_name('post_meta') . " SET meta_value = ? WHERE id = ?");
            $ok = $u->execute([$json_new, $first['id']]);
            return $ok ? true : false;
        }
    }

    // No existing rows: insert new
    $i = $pdo->prepare("INSERT INTO " . table_name('post_meta') . " (post_id, meta_key, meta_value) VALUES (?, ?, ?)");
    $res = $i->execute([$post_id, $meta_key, $json_new]);
    if ($res) return (int)$pdo->lastInsertId();
    return false;
}

// Delete a post meta key for a given post id
function delete_post_meta($post_id, $meta_key, $meta_value = null) {
    global $pdo;
    try {
        if (func_num_args() < 3 || $meta_value === null) {
            $stmt = $pdo->prepare("DELETE FROM " . table_name('post_meta') . " WHERE post_id = ? AND meta_key = ?");
            $stmt->execute([$post_id, $meta_key]);
            return ($stmt->rowCount() > 0);
        }
        $json = json_encode($meta_value);
        $plain = is_scalar($meta_value) ? (string)$meta_value : $json;
        $stmt = $pdo->prepare("DELETE FROM " . table_name('post_meta') . " WHERE post_id = ? AND meta_key = ? AND (meta_value = ? OR meta_value = ?)");
        $stmt->execute([$post_id, $meta_key, $json, $plain]);
        return ($stmt->rowCount() > 0);
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Delete a term meta key for a given term id (WP-like signature).
 *
 * @param int $term_id
 * @param string $meta_key
 * @param mixed|null $meta_value Optional. Only delete rows matching this value.
 * @return bool True if a row was deleted.
 */
function delete_term_meta($term_id, $meta_key, $meta_value = null) {
    global $pdo;
    try {
        if (func_num_args() < 3 || $meta_value === null) {
            $stmt = $pdo->prepare("DELETE FROM " . table_name('term_meta') . " WHERE term_id = ? AND meta_key = ?");
            $stmt->execute([$term_id, $meta_key]);
            return ($stmt->rowCount() > 0);
        }
        $json = json_encode($meta_value);
        $plain = is_scalar($meta_value) ? (string)$meta_value : $json;
        $stmt = $pdo->prepare("DELETE FROM " . table_name('term_meta') . " WHERE term_id = ? AND meta_key = ? AND (meta_value = ? OR meta_value = ?)");
        $stmt->execute([$term_id, $meta_key, $json, $plain]);
        return ($stmt->rowCount() > 0);
    } catch (Throwable $e) {
        return false;
    }
}

// Example: Get option meta (single value per key)
function get_option_meta($option_name) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT option_value FROM " . table_name('site_options') . " WHERE option_name = ?");
    $stmt->execute([$option_name]);
    $value = $stmt->fetchColumn();
    if ($value !== false) {
        $decoded = json_decode($value, true);
        return is_null($decoded) ? $value : $decoded;
    }
    return null;
}

// Example: Update or insert option meta
function update_option_meta($option_name, $option_value) {
    global $pdo;
    $json_value = json_encode($option_value);
    $stmt_check = $pdo->prepare("SELECT COUNT(*) FROM " . table_name('site_options') . " WHERE option_name = ?");
    $stmt_check->execute([$option_name]);
    if ($stmt_check->fetchColumn() > 0) {
        $stmt = $pdo->prepare("UPDATE " . table_name('site_options') . " SET option_value = ? WHERE option_name = ?");
        return $stmt->execute([$json_value, $option_name]);
    } else {
        $stmt = $pdo->prepare("INSERT INTO " . table_name('site_options') . " (option_name, option_value) VALUES (?, ?)");
        return $stmt->execute([$option_name, $json_value]);
    }
}

// ---------------------- Site settings helpers ----------------------
// These are global helpers to retrieve site timezone and display formats
// and to format/parse datetimes in the configured site timezone.
function get_site_timezone(): string {
    $tz = get_option_meta('timezone_string');
    if (empty($tz)) return 'UTC';
    return (string)$tz;
}

function get_site_date_format(): string {
    $fmt = get_option_meta('date_format');
    if (empty($fmt)) return 'F j, Y';
    return (string)$fmt;
}

function get_site_time_format(): string {
    $fmt = get_option_meta('time_format');
    if (empty($fmt)) return 'g:i a';
    return (string)$fmt;
}

function get_site_week_start(): int {
    $v = get_option_meta('week_starts_on');
    $i = is_numeric($v) ? intval($v) : null;
    if ($i === null) return 1; // default Monday
    if ($i < 0 || $i > 6) return 1;
    return $i;
}

/**
 * Format a UTC timestamp/string into site timezone and formats.
 * Accepts integer timestamp, DateTime, or date string (assumed UTC if no timezone provided).
 */
function format_site_datetime($dt, $format = null) {
    $tzId = get_site_timezone();
    $format = $format ?: (get_site_date_format() . ' ' . get_site_time_format());
    try {
        if ($dt instanceof DateTimeInterface) {
            $d = new DateTime($dt->format('Y-m-d H:i:s'), new DateTimeZone('UTC'));
        } elseif (is_numeric($dt)) {
            $d = new DateTime('@' . intval($dt));
        } else {
            // Try to parse as UTC first
            $d = new DateTime($dt, new DateTimeZone('UTC'));
        }
        $d->setTimezone(new DateTimeZone($tzId));
        return $d->format($format);
    } catch (Exception $e) {
        return is_string($dt) ? $dt : '';
    }
}

/**
 * Parse a user-supplied date/time string in the site timezone and return UTC timestamp.
 * If $format is provided it will be used with DateTime::createFromFormat; otherwise try flexible parsing.
 * Returns integer timestamp (seconds) or null on failure.
 */
function parse_site_datetime_to_utc($datetime_str, $format = null) {
    if (empty($datetime_str)) return null;
    $tzId = get_site_timezone();
    try {
        if ($format) {
            $d = DateTime::createFromFormat($format, $datetime_str, new DateTimeZone($tzId));
            if ($d === false) return null;
        } else {
            // Try to parse with fallback: assume the input is in site timezone
            $d = new DateTime($datetime_str, new DateTimeZone($tzId));
        }
        // Convert to UTC timestamp
        $d->setTimezone(new DateTimeZone('UTC'));
        return (int)$d->getTimestamp();
    } catch (Exception $e) {
        return null;
    }
}



// Template functions for clean theme files (WordPress-style with qp_ prefix)
function get_header() {
    // Helper to enforce post visibility rules for single posts/pages
    // - public: visible to all when published_at <= NOW()
    // - password: requires a password entry stored in post.post_password
    // - private: visible only to author or users with manage_posts capability
    if (!function_exists('qp_enforce_post_visibility')) {
        function qp_enforce_post_visibility(array & $post) {
            if (empty($post)) return;
            $vis = $post['visibility'] ?? 'public';
            if ($vis === 'private') {
                if (!function_exists('is_logged_in') || !is_logged_in()) {
                    http_response_code(403);
                    echo '<h1>Private</h1><p>This post is private. Please log in to view.</p>'; exit;
                }
                $lu = function_exists('get_logged_in_user') ? get_logged_in_user() : null;
                $uid = $lu['id'] ?? null;
                if ($uid !== (int)$post['author_id'] && (!function_exists('check_permission') || !check_permission('manage_posts'))) {
                    http_response_code(403);
                    echo '<h1>Private</h1><p>Access denied.</p>'; exit;
                }
                return;
            }

            if ($vis === 'password') {
                $post_id = (int)$post['id'];
                $pw = $post['post_password'] ?? '';
                if ($pw === '') { http_response_code(403); echo '<h1>Protected</h1><p>No password set for this post.</p>'; exit; }
                $cookieName = 'qp_post_access_' . $post_id;
                $hasAccess = false;
                if (!empty($_COOKIE[$cookieName]) && $_COOKIE[$cookieName] === sha1($pw)) $hasAccess = true;
                if (!$hasAccess && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['qp_post_password'])) {
                    if (hash_equals(sha1($_POST['qp_post_password']), sha1($pw))) {
                        setcookie($cookieName, sha1($pw), time() + 3600, '/', '', false, true);
                        // After successful login, redirect so template can render normally
                        header('Location: ' . $_SERVER['REQUEST_URI']); exit;
                    }
                }
                if (!$hasAccess) {
                    // Mark the post so templates can show a password form instead of content
                    $post['_needs_password'] = true;
                    return;
                }
                // Access granted via cookie
                return;
                return;
            }
        }
    }
    global $pdo, $post, $posts, $page_title, $front_page_option, $front_post_id, $sitename, $slug, $theme_dir, $post_type, $taxonomy, $term_slug, $term, $search_query;
    
    // Guard: when a 404 template is already being rendered, `get_header()` may
    // be called from the 404 template — prevent recursion by short-circuiting
    // and directly including the theme header.
    if (!empty($GLOBALS['qlopy_rendering_404'])) {
        if (function_exists('do_action')) do_action('get_header');
        if (isset($theme_dir) && file_exists($theme_dir . 'header.php')) {
            include $theme_dir . 'header.php';
        }
        if (function_exists('do_action')) do_action('qp_head');
        return;
    }
    // Get route and parameters from URL
    $route = $_GET['route'] ?? null;
    $post_type = $_GET['post_type'] ?? 'post';
    $slug = $_GET['slug'] ?? '';
    $taxonomy = $_GET['taxonomy'] ?? 'category';
    $term_slug = $_GET['term'] ?? '';
    $search_query = $_GET['q'] ?? '';
    
    // Page template logic
    if ($route === 'singular' && $post_type === 'page') {
        $stmt = $pdo->prepare("SELECT option_value FROM " . table_name('site_options') . " WHERE option_name = 'front_page_option' LIMIT 1");
        $stmt->execute();
        $front_page_option = $stmt->fetchColumn() ?: 'theme';
        
            if ($front_page_option === 'static') {
            $stmt = $pdo->prepare("SELECT option_value FROM " . table_name('site_options') . " WHERE option_name = 'front_page_id' LIMIT 1");
            $stmt->execute();
            $front_post_id = intval($stmt->fetchColumn() ?: 0);
            if ($front_post_id > 0) {
                $stmt = $pdo->prepare("SELECT * FROM " . table_name('posts') . " WHERE id = ? AND post_type = 'page'");
                $stmt->execute([$front_post_id]);
                $post = $stmt->fetch(PDO::FETCH_ASSOC);
            } else {
                $post = null;
            }
        } else {
            $front_post_id = 0;
            if (!$slug) {
                http_response_code(404);
                echo "<h1>Post not found</h1>";
                exit;
            }
            $stmt = $pdo->prepare("SELECT * FROM " . table_name('posts') . " WHERE slug = ? AND post_type = 'page' AND status='published' AND (published_at IS NULL OR published_at <= UTC_TIMESTAMP()) LIMIT 1");
            $stmt->execute([$slug]);
            $post = $stmt->fetch(PDO::FETCH_ASSOC);
        }
        
        if (!$post) {
            http_response_code(404);
            echo "<h1>Post not found</h1>";
            exit;
        }
        // Enforce visibility rules for this page
        

        if ($front_page_option === 'static' && $front_post_id === intval($post['id'])) {
            $stmt = $pdo->prepare("SELECT option_value FROM " . table_name('site_options') . " WHERE option_name = 'site_name' LIMIT 1");
            $stmt->execute();
            $page_title = $stmt->fetchColumn();
        } else {
            $page_title = $post['title'];
        }
    }
    
    // Single post template logic
    if ($route === 'singular' && $post_type === 'post' && !isset($post)) {
        
        if (!$slug) {
            http_response_code(404);
            $tmpl404 = ($GLOBALS['theme_dir'] ?? '') . '404.php';
            if ($tmpl404 && file_exists($tmpl404)) {
                $page_title = 'Not Found';
                $GLOBALS['qlopy_rendering_404'] = true;
                include $tmpl404;
                unset($GLOBALS['qlopy_rendering_404']);
            } else {
                echo "<h1>Post not found</h1>";
            }
            exit;
        }
        $stmt = $pdo->prepare("SELECT * FROM " . table_name('posts') . " WHERE slug = ? AND status='published' AND (published_at IS NULL OR published_at <= UTC_TIMESTAMP()) LIMIT 1");
        $stmt->execute([$slug]);
        $post = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$post) {
            http_response_code(404);
            $tmpl404 = ($GLOBALS['theme_dir'] ?? '') . '404.php';
            if ($tmpl404 && file_exists($tmpl404)) {
                $page_title = 'Not Found';
                $GLOBALS['qlopy_rendering_404'] = true;
                include $tmpl404;
                unset($GLOBALS['qlopy_rendering_404']);
            } else {
                echo "<h1>Post not found</h1>";
            }
            exit;
        }
        // Enforce visibility rules for this post
        
        $page_title = $post['title'];
    }
    
    // Index/Home template logic
    if ($route === 'frontpage' && !isset($posts)) {
        $page_title = $page_title ?? 'Home';
        $stmt = $pdo->prepare("SELECT id, title, slug FROM " . table_name('posts') . " WHERE post_type='post' AND status='published' AND (published_at IS NULL OR published_at <= UTC_TIMESTAMP()) ORDER BY created_at DESC LIMIT 10");
        $stmt->execute();
        $posts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // Category template logic
    if ($route === 'archive' && $taxonomy && $term_slug && !isset($term)) {
        if (!$term_slug) {
            http_response_code(404);
            $tmpl404 = ($GLOBALS['theme_dir'] ?? '') . '404.php';
            if ($tmpl404 && file_exists($tmpl404)) {
                $page_title = 'Not Found';
                $GLOBALS['qlopy_rendering_404'] = true;
                include $tmpl404;
                unset($GLOBALS['qlopy_rendering_404']);
            } else {
                echo "<h1>Category not found</h1>";
            }
            exit;
        }
        $stmt = $pdo->prepare("SELECT * FROM " . table_name('taxonomy_terms') . " WHERE taxonomy = ? AND slug = ? LIMIT 1");
        $stmt->execute([$taxonomy, $term_slug]);
        $term = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$term) {
            http_response_code(404);
            $tmpl404 = ($GLOBALS['theme_dir'] ?? '') . '404.php';
            if ($tmpl404 && file_exists($tmpl404)) {
                $page_title = 'Not Found';
                $GLOBALS['qlopy_rendering_404'] = true;
                include $tmpl404;
                unset($GLOBALS['qlopy_rendering_404']);
            } else {
                echo "<h1>Category not found</h1>";
            }
            exit;
        }
        $page_title = "Category: " . htmlspecialchars($term['term']);
        
        $stmt_posts = $pdo->prepare(
            "SELECT p.id, p.title, p.slug FROM " . table_name('posts') . " p
            JOIN " . table_name('post_terms') . " pt ON p.id = pt.post_id
            WHERE pt.term_id = ? AND p.status='published' AND (p.published_at IS NULL OR p.published_at <= UTC_TIMESTAMP())
            ORDER BY p.created_at DESC"
        );
        $stmt_posts->execute([$term['id']]);
        $posts = $stmt_posts->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // Search template logic
    if (!empty($search_query) && !isset($posts)) {
        $page_title = "Search results for " . htmlspecialchars($search_query);
        $like_query = '%' . $search_query . '%';
        $stmt = $pdo->prepare("SELECT id, title, slug FROM " . table_name('posts') . " WHERE status='published' AND (published_at IS NULL OR published_at <= UTC_TIMESTAMP()) AND (title LIKE ? OR content LIKE ?) ORDER BY created_at DESC");
        $stmt->execute([$like_query, $like_query]);
        $posts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // Hook before header
    do_action('get_header');
    
    // Load header template
    if (isset($theme_dir) && file_exists($theme_dir . 'header.php')) {
        include $theme_dir . 'header.php';
    }
   // Hook before header
    do_action('qp_head'); 
}

/**
 * Output post content with visibility handling.
 * If a post requires a password and access has not been granted, this will
 * render a password form instead of the content. Otherwise it echoes the
 * processed content.
 */
function qp_the_content(array $post) {
    // Simple, template-level visibility enforcement: if the post is password
    // protected and the visitor hasn't provided the correct password, show a
    // password form in-place. This keeps the full page rendering intact.
    $vis = $post['visibility'] ?? 'public';
    if ($vis === 'password') {
        $post_id = (int)($post['id'] ?? 0);
        $pw = $post['post_password'] ?? '';
        if ($pw === '') {
            echo '<h1>Protected</h1><p>No password set for this post.</p>';
            return;
        }
        $cookieName = 'qp_post_access_' . $post_id;
        $hasAccess = (!empty($_COOKIE[$cookieName]) && $_COOKIE[$cookieName] === sha1($pw));
        if (!$hasAccess && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['qp_post_password'])) {
            if (hash_equals(sha1($_POST['qp_post_password']), sha1($pw))) {
                setcookie($cookieName, sha1($pw), time() + 3600, '/', '', false, true);
                header('Location: ' . $_SERVER['REQUEST_URI']); exit;
            } else {
                echo '<div class="alert alert-danger">Invalid password</div>';
            }
        }
        if (!$hasAccess) {
            echo '<h3>Password required</h3>';
            echo '<form method="post"><input name="qp_post_password" type="password" class="form-control" style="max-width:300px;display:inline-block;margin-right:8px"><button type="submit" class="btn btn-primary">Submit</button></form>';
            return;
        }
    }
    // Default: show full content (run shortcodes if helper exists)
    // Allow multilanguage filter to override content on frontend
    $content = $post['content'] ?? '';
    if (function_exists('apply_filters')) {
        $lang = null;
        if (function_exists('ml_get_current_lang')) $lang = ml_get_current_lang();
        $content = apply_filters('ml_the_content', $content, isset($post['id']) ? (int)$post['id'] : null, $lang);
    }
    if (function_exists('qp_do_shortcodes')) {
        echo qp_do_shortcodes($content);
    } else {
        echo $content;
    }
}

// --- Admin enqueue wrappers (use global assets system) ---

// --- WP-like title/content helpers ---
if (!function_exists('get_the_title')) {
    function get_the_title($post = null) {
        global $pdo;
        // Resolve post param (null => global post)
        if ($post === null) $post = $GLOBALS['post'] ?? null;
        $title = '';
        $post_id = null;
        if (is_int($post) || (is_string($post) && ctype_digit($post))) {
            $post_id = (int)$post;
            try {
                $stmt = $pdo->prepare('SELECT title FROM ' . table_name('posts') . ' WHERE id = ? LIMIT 1');
                $stmt->execute([$post_id]);
                $title = $stmt->fetchColumn() ?: '';
            } catch (Throwable $_e) { $title = ''; }
        } elseif (is_array($post)) {
            $post_id = isset($post['id']) ? (int)$post['id'] : null;
            $title = $post['title'] ?? '';
        } elseif (is_object($post)) {
            $post_id = isset($post->id) ? (int)$post->id : null;
            $title = $post->title ?? '';
        }

        if (function_exists('apply_filters')) {
            $lang = function_exists('ml_get_current_lang') ? ml_get_current_lang() : null;
            $title = apply_filters('ml_the_title', $title, $post_id, $lang);
        }
        return (string)$title;
    }
}

if (!function_exists('the_title')) {
    function the_title($post = null) {
        echo get_the_title($post);
    }
}

if (!function_exists('get_the_content')) {
    function get_the_content($post = null) {
        global $pdo;
        if ($post === null) $post = $GLOBALS['post'] ?? null;
        $content = '';
        $post_id = null;
        if (is_int($post) || (is_string($post) && ctype_digit($post))) {
            $post_id = (int)$post;
            try {
                $stmt = $pdo->prepare('SELECT content FROM ' . table_name('posts') . ' WHERE id = ? LIMIT 1');
                $stmt->execute([$post_id]);
                $content = $stmt->fetchColumn() ?: '';
            } catch (Throwable $_e) { $content = ''; }
        } elseif (is_array($post)) {
            $post_id = isset($post['id']) ? (int)$post['id'] : null;
            $content = $post['content'] ?? '';
        } elseif (is_object($post)) {
            $post_id = isset($post->id) ? (int)$post->id : null;
            $content = $post->content ?? '';
        }

        if (function_exists('apply_filters')) {
            $lang = function_exists('ml_get_current_lang') ? ml_get_current_lang() : null;
            $content = apply_filters('ml_the_content', $content, $post_id, $lang);
        }
        if (function_exists('qp_do_shortcodes')) {
            return qp_do_shortcodes($content);
        }
        return $content;
    }

    if (!function_exists('get_the_date')) {
        /**
         * Get formatted post date.
         * @param string $format date format (optional). If empty, uses site date format.
         * @param mixed $post post id, array or object (optional)
         * @return string
         */
        function get_the_date($format = '', $post = null) {
            global $pdo;
            if ($post === null) $post = $GLOBALS['post'] ?? null;
            $date_raw = null;
            $post_id = null;
            if (is_int($post) || (is_string($post) && ctype_digit($post))) {
                $post_id = (int)$post;
                try {
                    $stmt = $pdo->prepare('SELECT published_at, created_at FROM ' . table_name('posts') . ' WHERE id = ? LIMIT 1');
                    $stmt->execute([$post_id]);
                    $row = $stmt->fetch(PDO::FETCH_ASSOC);
                    if ($row) {
                        $date_raw = $row['published_at'] ?: $row['created_at'] ?? null;
                    }
                } catch (Throwable $_e) { $date_raw = null; }
            } elseif (is_array($post)) {
                $date_raw = $post['published_at'] ?? $post['created_at'] ?? null;
                $post_id = isset($post['id']) ? (int)$post['id'] : null;
            } elseif (is_object($post)) {
                $date_raw = $post->published_at ?? $post->created_at ?? null;
                $post_id = isset($post->id) ? (int)$post->id : null;
            }
            if (empty($date_raw)) return '';
            $fmt = $format ?: get_site_date_format();
            if (function_exists('format_site_datetime')) {
                return format_site_datetime($date_raw, $fmt);
            }
            // fallback: try strtotime and date
            $ts = is_numeric($date_raw) ? (int)$date_raw : strtotime($date_raw);
            if ($ts === false || $ts === null) return (string)$date_raw;
            return date($fmt, $ts);
        }
    }

    if (!function_exists('the_date')) {
        function the_date($format = '', $post = null) {
            echo get_the_date($format, $post);
        }
    }
}

if (!function_exists('the_content')) {
    function the_content($post = null) {
        echo get_the_content($post);
    }
}

if (!isset($GLOBALS['qlopy_admin_assets'])) {
    $GLOBALS['qlopy_admin_assets'] = [
        'styles' => [],
        'scripts' => [],
    ];
};

/**
 * Register a CSS style to enqueue
 * @param string $handle Unique id for the style
 * @param string $src URL or path to CSS file
 * @param array $deps Array of dependency handles
 * @param string $ver Version string
 * @param string $media Media attribute value (e.g. 'all', 'screen')
 */
function enqueue_admin_style($handle, $src, $deps = [], $ver = '', $media = 'all') {
    global $qlopy_admin_assets;
    $qlopy_admin_assets['styles'][$handle] = compact('handle', 'src', 'deps', 'ver', 'media');
}

/**
 * Register a JS script to enqueue
 * @param string $handle Unique id for the script
 * @param string $src URL or path to JS file
 * @param array $deps Array of dependency handles
 * @param string $ver Version string
 * @param bool $in_footer Load script before </body> if true, else in <head>
 */
function enqueue_admin_script($handle, $src, $deps = [], $ver = '', $in_footer = true) {
    global $qlopy_admin_assets;
    $qlopy_admin_assets['scripts'][$handle] = compact('handle', 'src', 'deps', 'ver', 'in_footer');
}

/**
 * Print the HTML tags for enqueued styles in the header
 */
function print_admin_styles() {
    global $qlopy_admin_assets;
    // Simple dependency resolution omitted for brevity (can be added later)
    foreach ($qlopy_admin_assets['styles'] as $style) {
        $ver_suffix = $style['ver'] ? '?ver=' . $style['ver'] : '';
        echo '<link rel="stylesheet" href="' . htmlspecialchars($style['src']) . $ver_suffix . '" media="' . htmlspecialchars($style['media']) . '">' . "\n";
    }
}

/**
 * Print JS scripts that should load in header (in_footer = false)
 */
function print_admin_header_scripts() {
    global $qlopy_admin_assets;
    foreach ($qlopy_admin_assets['scripts'] as $script) {
        if (!$script['in_footer']) {
            $ver_suffix = $script['ver'] ? '?ver=' . $script['ver'] : '';
            echo '<script src="' . htmlspecialchars($script['src']) . $ver_suffix . '"></script>' . "\n";
        }
    }
}


/**
 * Print JS scripts that should load in footer (in_footer = true)
 */
function print_admin_footer_scripts() {
    global $qlopy_admin_assets;
    foreach ($qlopy_admin_assets['scripts'] as $script) {
        if ($script['in_footer']) {
            $ver_suffix = $script['ver'] ? '?ver=' . $script['ver'] : '';
            echo '<script src="' . htmlspecialchars($script['src']) . $ver_suffix . '"></script>' . "\n";
        }
    }
}


function get_footer() {
    global $theme_dir;
    
    // Hook before footer template
    do_action('get_footer');
    
    // Load footer template
    if (isset($theme_dir) && file_exists($theme_dir . 'footer.php')) {
        include $theme_dir . 'footer.php';
    }
}

function qp_header(){
  
if (function_exists('do_action')) do_action('qp_header'); 
if (function_exists('do_action')) do_action('qp_before_header'); 
print_styles();
print_header_scripts();
if (function_exists('do_action')) do_action('qp_after_header'); 
}
function qp_footer(){ 
     if (function_exists('do_action')) do_action('qp_footer');
    if (function_exists('do_action')) do_action('qp_before_footer');  
    print_footer_scripts();
    if (function_exists('do_action')) do_action('qp_after_footer');
}

// ---------- Media helpers (PHP 8.2+) ----------
if (!function_exists('qp_add_image_size')) {
    $GLOBALS['qp_image_sizes'] = $GLOBALS['qp_image_sizes'] ?? [];
    function qp_add_image_size($name, $width, $height = 0, $crop = false) {
        $GLOBALS['qp_image_sizes'][$name] = [
            'width' => (int)$width,
            'height' => (int)$height,
            'crop' => (bool)$crop,
        ];
    }
}

function qp_get_image_sizes() {
    return $GLOBALS['qp_image_sizes'] ?? [];
}

function qp_uploads_base(): string {
    $cfg = function_exists('get_config') ? get_config() : [];
    $upload_dir = $cfg['uploads_dir'] ?? 'uploads';
    $base = __DIR__ . DIRECTORY_SEPARATOR . $upload_dir;
    if (!is_dir($base)) {
        @mkdir($base, 0775, true);
    }
    return $base;
}

/**
 * Map an uploads URL (absolute) to a local filesystem path under uploads base.
 * Returns null if mapping fails.
 */
function qp_map_uploads_url_to_path(string $url): ?string {
    if (!preg_match('#^https?://#i', $url)) return null;
    $uploadsBase = qp_uploads_base();
    $uploadsUrlBase = qp_uploads_url_base();
    $urlPath = parse_url($url, PHP_URL_PATH) ?: '';
    $basePath = parse_url($uploadsUrlBase, PHP_URL_PATH) ?: '';
    // Normalize slashes
    $urlPathNorm = '/' . ltrim(str_replace('\\','/',$urlPath), '/');
    $basePathNorm = '/' . ltrim(str_replace('\\','/',$basePath), '/');
    if (strpos($urlPathNorm, $basePathNorm) === 0) {
        $rel = ltrim(substr($urlPathNorm, strlen($basePathNorm)), '/');
        $local = rtrim($uploadsBase, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $rel);
        return $local;
    }
    // If not under uploads base, try to locate by taking last segments (fallback)
    $segments = explode('/', trim($urlPathNorm, '/'));
    $last = implode(DIRECTORY_SEPARATOR, array_slice($segments, -3)); // try last 3 segments
    $candidate = rtrim($uploadsBase, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $last;
    if (file_exists($candidate)) return $candidate;
    return null;
}

function qp_uploads_url_base(): string {
    $cfg = function_exists('get_config') ? get_config() : [];
    $upload_dir = $cfg['uploads_dir'] ?? 'uploads';
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $scriptDir = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/');
    // Remove trailing admin dir if current request is from admin area
    $segments = array_filter(explode('/', $scriptDir), 'strlen');
    $admin_dir = $GLOBALS['admin_dir'] ?? ($GLOBALS['config']['admin_dir'] ?? 'admin');
    if (!empty($segments) && end($segments) === $admin_dir) {
        array_pop($segments);
    }
    $basePath = '/' . implode('/', $segments);
    if ($basePath === '/') { $basePath = ''; }
    $base = $scheme . '://' . $host . $basePath . '/' . trim($upload_dir, '/');

    // Allow replacing the host (or full base) via filter `cdn_assets_domain`.
    if (function_exists('apply_filters')) {
        $cdn = apply_filters('cdn_assets_domain', null);
        if (is_string($cdn) && trim($cdn) !== '') {
            $cdn = trim($cdn);
            $orig = parse_url($base);
            $orig_scheme = $orig['scheme'] ?? 'http';
            $orig_path = $orig['path'] ?? '';
            if (preg_match('#^https?://#i', $cdn)) {
                $parsed = parse_url($cdn);
                $cdn_scheme = $parsed['scheme'] ?? $orig_scheme;
                $cdn_host = $parsed['host'] ?? '';
                $cdn_path = isset($parsed['path']) ? rtrim($parsed['path'], '/') : '';
                $base = $cdn_scheme . '://' . $cdn_host . $cdn_path . $orig_path;
            } else {
                // Treat as host (optionally with port)
                $base = $orig_scheme . '://' . $cdn . $orig_path;
            }
        }
    }

    return $base;
}

function plugins_dir_url(string $subpath = ''): string {
    $cfg = function_exists('get_config') ? get_config() : [];
    $content_dir = $cfg['content_dir'] ?? 'content';
    $plugins_dir = $cfg['plugins_dir'] ?? 'plugins';
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $scriptDir = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/');
    // Remove trailing admin dir if current request is from admin area
    $segments = array_filter(explode('/', $scriptDir), 'strlen');
    $admin_dir = $GLOBALS['admin_dir'] ?? ($GLOBALS['config']['admin_dir'] ?? 'admin');
    if (!empty($segments) && end($segments) === $admin_dir) {
        array_pop($segments);
    }
    $basePath = '/' . implode('/', $segments);
    if ($basePath === '/') { $basePath = ''; }
    $base = $scheme . '://' . $host . $basePath . '/' . trim($content_dir, '/') . '/' . trim($plugins_dir, '/');

    // Allow replacing the host (or full base) via filter `cdn_assets_domain`.
    if (function_exists('apply_filters')) {
        $cdn = apply_filters('cdn_assets_domain', null);
        if (is_string($cdn) && trim($cdn) !== '') {
            $cdn = trim($cdn);
            $orig = parse_url($base);
            $orig_scheme = $orig['scheme'] ?? 'http';
            $orig_path = $orig['path'] ?? '';
            if (preg_match('#^https?://#i', $cdn)) {
                $parsed = parse_url($cdn);
                $cdn_scheme = $parsed['scheme'] ?? $orig_scheme;
                $cdn_host = $parsed['host'] ?? '';
                $cdn_path = isset($parsed['path']) ? rtrim($parsed['path'], '/') : '';
                $base = $cdn_scheme . '://' . $cdn_host . $cdn_path . $orig_path;
            } else {
                // Treat as host (optionally with port)
                $base = $orig_scheme . '://' . $cdn . $orig_path;
            }
        }
    }

    // Append optional subpath if provided
    $subpath = trim((string)$subpath, "\/");
    if ($subpath !== '') {
        $base = rtrim($base, '/') . '/' . $subpath;
    }

    return $base;
}

/**
 * Return the URL to the active theme directory for UI usage.
 * Tries THEME_URL constant first, then falls back to computing from SITE_URL or
 * server variables and active theme stored in site options.
 */
function get_template_directory_ui($delimeter=''): string {
    if (defined('THEME_URL') && THEME_URL) return rtrim(THEME_URL, '/');

    // Load config if not already available
    $cfg = $GLOBALS['config'] ?? null;
    if (!is_array($cfg)) {
        $cfg_file = __DIR__ . '/config.php';
        if (is_file($cfg_file)) {
            $cfg = require $cfg_file;
        } else {
            $cfg = [];
        }
    }
    $site_url = rtrim($cfg['site_url'], '/'); 
    $content_dir = $cfg['content_dir'] ?? 'content';
    $themes_dir = $cfg['themes_dir'] ?? 'themes';
 $pdo = db();
    // Load theme and plugin system (unchanged from your current system)
$theme_dir_root = __DIR__ . "/$content_dir/$themes_dir/";
$available_themes = [];
if (is_dir($theme_dir_root)) {
    foreach (scandir($theme_dir_root) as $theme) {
        if ($theme[0] !== '.' && is_dir($theme_dir_root . $theme)) {
            $available_themes[] = $theme;
        }
    }
}
// Active theme: pick first if only one, else from DB or fallback
if (count($available_themes) === 1) {
    $active_theme = $available_themes[0];
    $stmt = $pdo->prepare("SELECT option_value FROM " . table_name('site_options') . " WHERE option_name = 'active_theme' LIMIT 1");
    $stmt->execute();
    $db_theme = $stmt->fetchColumn();
    if ($db_theme !== $active_theme) {
        $stmt_upd = $pdo->prepare("INSERT INTO " . table_name('site_options') . "(option_name, option_value) VALUES ('active_theme', ?) ON DUPLICATE KEY UPDATE option_value=VALUES(option_value)");
        $stmt_upd->execute([$active_theme]);
    }
} else {
    $stmt = $pdo->prepare("SELECT option_value FROM " . table_name('site_options') . " WHERE option_name = 'active_theme' LIMIT 1");
    $stmt->execute();
    $active_theme = $stmt->fetchColumn() ?: ($available_themes[0] ?? 'default');
}
if (!$active_theme) $active_theme = 'default';
 return rtrim($site_url . '/' . $content_dir . '/' . $themes_dir . '/' . $active_theme. $delimeter);

}

function qp_allowed_mimes(): array {
    $default = [
        // JPEG family (include common variants some systems report)
        'image/jpeg' => ['jpg','jpeg'],
        'image/jpg' => ['jpg','jpeg'],
        'image/pjpeg' => ['jpg','jpeg'],
        'image/png' => ['png'],
        'image/gif' => ['gif'],
        'image/webp' => ['webp'],
        'application/pdf' => ['pdf'],
        'application/msword' => ['doc'],
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => ['docx'],
            // Audio/Video extensions
            'audio/mpeg' => ['mp3'],
            'video/mp4' => ['mp4'],
            'audio/ogg' => ['ogg'],
            'video/webm' => ['webm'],
    ];
    return apply_filters('qp_allowed_mimes', $default);
}

/**
 * Get the configured maximum upload size in bytes.
 *
 * By default this returns null (no application-level limit). Themes/plugins
 * can override per-role or per-user by adding a filter for
 * `qp_max_upload_bytes` which receives the current default (null) and two
 * optional args: the user's role (string|null) and the full user record
 * (array|null).
 *
 * Example usage in a plugin (global limit):
 * add_filter('qp_max_upload_bytes', function($default, $role, $user){
 *   // Enforce 50 MB for all users
 *   return 50 * 1024 * 1024;
 * }, 10, 3);
 *
 * Example usage in a plugin (per-role limit):
 * add_filter('qp_max_upload_bytes', function($default, $role, $user){
 *   if ($role === 'author') return 10 * 1024 * 1024; // 10 MB for authors
 *   if ($role === 'contributor') return 5 * 1024 * 1024; // 5 MB for contributors
 *   return $default; // no limit otherwise
 * }, 10, 3);
 */
function qp_get_max_upload_bytes(): ?int {
    $user = function_exists('get_logged_in_user') ? get_logged_in_user() : null;
    $role = is_array($user) && isset($user['role']) ? $user['role'] : null;
    $default = null; // keep default behavior: no app-level limit
    if (function_exists('apply_filters')) {
        $val = apply_filters('qp_max_upload_bytes', $default, $role, $user);
        if (is_int($val) && $val >= 0) return $val;
        if (is_string($val) && ctype_digit($val)) return (int)$val;
    }
    return $default;
}

/**
 * Handle an uploaded file and register it as an attachment (media).
 *
 * Low-level upload handler used by admin endpoints and other callers.
 *
 * Signature:
 *   qp_handle_upload(array $file, ?int $post_author_id = null, array $options = []): array
 *
 * Parameters:
 * - $file: An uploaded file array with keys `name`, `type`, `tmp_name`, `size`, `error` (same as PHP's $_FILES item).
 * - $post_author_id: optional integer user id to associate as attachment author.
 * - $options: associative array controlling upload behavior (all optional):
 *     - 'subdir' (string): sanitized subfolder name under `uploads/` to place the file in
 *         (e.g. when passing from plugins/themes use the wrapper `qp_handle_upload_extensive` which accepts `folder`).
 *     - 'skip_sizes' (bool): if true, skip generating image derivatives (thumbnail/medium/large).
 *     - 'use_date_folders' (bool): whether to create YYYY/MM subfolders under the chosen folder
 *         (default: true). When false the file is placed directly into `uploads/<subdir>/`.
 *     - 'compress_original' (bool): when true, compress the original uploaded image file
 *         using `qp_img_compressor()` with the provided quality.
 *     - 'original_quality' (int): quality (1-100) to use when compressing the original image.
 *         Default: 85.
 *
 * Returns: array of form [bool $ok, array|string $result]. On success returns [true, ['post_id'=>int, 'url'=>string, ...]].
 * On error returns [false, 'error message'].
 *
 * Notes and security:
 * - The 'subdir' option is sanitized to allow only letters, numbers, dash and underscore.
 * - Avoid passing untrusted values for folder names; prefer using known plugin/theme identifiers.
 * - Skipping size generation reduces disk usage but callers that expect thumbnails will need to
 *   generate them on demand via `qp_get_attachment_image_src()` or similar.
 *
 * Example (direct):
 *   [$ok, $res] = qp_handle_upload($_FILES['file'], $user_id, ['subdir'=>'myplugin','skip_sizes'=>true]);
 */
function qp_handle_upload(array $file, ?int $post_author_id = null, array $options = []): array {
    // Allow callers to bypass PHP's is_uploaded_file() check when importing
    // local files by passing ['allow_local' => true] in $options. Default
    // behavior remains unchanged for regular browser uploads.
    if (empty($file) || !isset($file['tmp_name']) || (empty($options['allow_local']) && !is_uploaded_file($file['tmp_name']))) {
        return [false, 'Invalid upload.'];
    }
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    $allowed = qp_allowed_mimes();
    if (!isset($allowed[$mime])) {
        return [false, 'Disallowed MIME type.'];
    }
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed[$mime], true)) {
        $ext = $allowed[$mime][0];
    }

    // Application-level file size limit (filterable). Default is null => no limit.
    $maxBytes = function_exists('qp_get_max_upload_bytes') ? qp_get_max_upload_bytes() : null;
    if (is_int($maxBytes) && $maxBytes > 0) {
        // If browser provided size, check it first; fallback to temp file size if missing
        $reportedSize = isset($file['size']) ? (int)$file['size'] : null;
        if ($reportedSize !== null && $reportedSize > $maxBytes) {
            return [false, 'File exceeds maximum allowed size.'];
        }
        // As a final check, after move we also measure actual filesize (handled below)
    }

    $now = new DateTime('now');
    $y = $now->format('Y');
    $m = $now->format('m');

    // Options: allow skipping size generation, placing uploads into a sub-folder,
    // and control whether to use date-based subfolders (YYYY/MM)
    $skip_sizes = !empty($options['skip_sizes']);
    $subdir_raw = isset($options['subdir']) ? (string)$options['subdir'] : '';
    $use_date_folders = array_key_exists('use_date_folders', $options) ? (bool)$options['use_date_folders'] : true;
    // sanitize subdir: allow letters, numbers, dash and underscore only
    $subdir = preg_replace('/[^a-zA-Z0-9_-]+/', '', $subdir_raw);
    $baseUploads = qp_uploads_base() . ($subdir !== '' ? DIRECTORY_SEPARATOR . $subdir : '');
    if ($use_date_folders) {
        $targetDir = $baseUploads . DIRECTORY_SEPARATOR . $y . DIRECTORY_SEPARATOR . $m;
    } else {
        $targetDir = $baseUploads;
    }
    if (!is_dir($targetDir)) {
        @mkdir($targetDir, 0775, true);
    }
    
    // Safe base filename (no dimensions, no random). Add -1/-2 only if duplicate.
    $safeBase = preg_replace('/[^a-zA-Z0-9_-]+/', '-', pathinfo($file['name'], PATHINFO_FILENAME));
    $filename = $safeBase . '.' . $ext;
    $target = $targetDir . DIRECTORY_SEPARATOR . $filename;
    if (file_exists($target)) {
        $i = 1;
        do {
            $filename = $safeBase . '-' . $i . '.' . $ext;
            $target = $targetDir . DIRECTORY_SEPARATOR . $filename;
            $i++;
        } while (file_exists($target));
    }
    // Move uploaded file into target. If this is a local-import scenario
    // (options['allow_local'] === true) the source won't be an HTTP upload
    // and move_uploaded_file() will fail — fall back to rename/copy.
    $moved = false;
    if (!empty($options['allow_local'])) {
        $moved = @rename($file['tmp_name'], $target) || @copy($file['tmp_name'], $target);
    } else {
        $moved = @move_uploaded_file($file['tmp_name'], $target);
    }
    if (!$moved) {
        return [false, 'Failed to move uploaded file.'];
    }
    // Optionally compress original image to optimize size while preserving transparency
    $compress_original = !empty($options['compress_original']);
    $original_quality = isset($options['original_quality']) ? (int)$options['original_quality'] : 85;
    if ($compress_original && str_starts_with($mime, 'image/')) {
        @qp_img_compressor($target, $target, $original_quality);
    }

    $url = qp_uploads_url_base() . '/' . ($subdir !== '' ? $subdir . '/' : '');
    if ($use_date_folders) {
        $url .= $y . '/' . $m . '/';
    }
    $url .= $filename;

    // For images, record original width/height so callers can access without
    // needing to call getimagesize() on the file path every time.
    $orig_width = null; $orig_height = null;
    if (str_starts_with($mime, 'image/') && is_file($target)) {
        $info = @getimagesize($target);
        if ($info) { $orig_width = (int)$info[0]; $orig_height = (int)$info[1]; }
    }

    // Store relative path/url (after uploads base) to ease CDN/migration.
    $relative = ($subdir !== '' ? $subdir . '/' : '') . ($use_date_folders ? ($y . '/' . $m . '/') : '') . $filename;
    $meta = [
        'file' => [
            'path' => $relative,               // relative path within uploads
            'path_full' => $target,            // absolute filesystem path (backup)
            'url' => $relative,                // relative url (after uploads base)
            'url_full' => $url,                // absolute URL (backup)
            'filename' => $filename,
            'mime' => $mime,
            'ext' => $ext,
            'size' => filesize($target),
            'width' => $orig_width,
            'height' => $orig_height,
        ],
        'sizes' => [],
    ];

    $pdo = db();
    $stmt = $pdo->prepare('INSERT INTO ' . table_name('posts') . ' (post_type, title, slug, content, status, author_id, created_at) VALUES (?,?,?,?,?,?,UTC_TIMESTAMP())');
    $title = $file['name'];
    $slug = 'attachment-' . time() . '-' . bin2hex(random_bytes(4));
    $status = 'published';
    $lu = get_logged_in_user();
    $author = $post_author_id ?? (!empty($lu) ? (int)$lu['id'] : null);
    $stmt->execute(['attachment', $title, $slug, '', $status, $author]);
    $post_id = (int)$pdo->lastInsertId();

    $metaStmt = $pdo->prepare('INSERT INTO ' . table_name('post_meta') . ' (post_id, meta_key, meta_value) VALUES (?,?,?)');
    $metaStmt->execute([$post_id, '_file_metadata', json_encode($meta)]);

    // Generate default image sizes only for images, name derivatives with actual dimensions
    if (!$skip_sizes && str_starts_with($mime, 'image/') && qp_image_editor_available()) {
        foreach (qp_get_image_sizes() as $sizeName => $sizeData) {
            $dir = dirname($target);
            $baseName = pathinfo($target, PATHINFO_FILENAME);

            // Compute expected final dimensions similarly to qp_generate_image_size
            $origW = $meta['file']['width'] ?? null; $origH = $meta['file']['height'] ?? null;
            if ($origW === null || $origH === null) {
                $srcInfo = @getimagesize($target);
                if ($srcInfo) { $origW = (int)$srcInfo[0]; $origH = (int)$srcInfo[1]; }
            }
            if ($origW === null || $origH === null) {
                // cannot determine original dimensions, skip this size
                continue;
            }
            $tW = (int)($sizeData['width'] ?? 0); $tH = (int)($sizeData['height'] ?? 0); $crop = (bool)($sizeData['crop'] ?? false);
            if ($tW <= 0 && $tH <= 0) { $finalW = $origW; $finalH = $origH; }
            elseif ($tW > 0 && $tH <= 0) { $finalH = (int)round($origH * ($tW / max(1, $origW))); $finalW = $tW; }
            elseif ($tH > 0 && $tW <= 0) { $finalW = (int)round($origW * ($tH / max(1, $origH))); $finalH = $tH; }
            else { if ($crop) { $finalW = $tW; $finalH = $tH; } else { $scale = min($tW / max(1,$origW), $tH / max(1,$origH)); $finalW = (int)round($origW * $scale); $finalH = (int)round($origH * $scale); } }

            $finalPath = $dir . DIRECTORY_SEPARATOR . $baseName . '-' . $finalW . 'x' . $finalH . '.' . $ext;
            if (file_exists($finalPath)) {
                // reuse existing final file
                $urlDeriv = dirname($url) . '/' . basename($finalPath);
                $meta['sizes'][$sizeName] = ['path' => $finalPath, 'url' => $urlDeriv, 'width' => $finalW, 'height' => $finalH];
                continue;
            }

            // Acquire per-size lock to avoid concurrent generation race
            $lockPath = $dir . DIRECTORY_SEPARATOR . $baseName . '-' . $sizeName . '.lock';
            $lockFp = @fopen($lockPath, 'c');
            $tempPath = $dir . DIRECTORY_SEPARATOR . $baseName . '-' . $sizeName . '.' . $ext;
            if ($lockFp === false) {
                // could not lock; generate anyway
                $ok = qp_generate_image_size($target, $tempPath, $tW, $tH, $crop);
                if ($ok) {
                    $info2 = @getimagesize($tempPath);
                    if ($info2) {
                        $w = (int)$info2[0]; $h = (int)$info2[1];
                        $finalP = $dir . DIRECTORY_SEPARATOR . $baseName . '-' . $w . 'x' . $h . '.' . $ext;
                        if (file_exists($finalP)) {
                            $i = 1; do { $finalP = $dir . DIRECTORY_SEPARATOR . $baseName . '-' . $w . 'x' . $h . '-' . $i . '.' . $ext; $i++; } while (file_exists($finalP));
                        }
                        @rename($tempPath, $finalP);
                        $urlDeriv = dirname($url) . '/' . basename($finalP);
                        $meta['sizes'][$sizeName] = ['path' => $finalP, 'url' => $urlDeriv, 'width' => $w, 'height' => $h];
                    } else {
                        $urlDeriv = dirname($url) . '/' . basename($tempPath);
                        $meta['sizes'][$sizeName] = ['path' => $tempPath, 'url' => $urlDeriv];
                    }
                }
            } else {
                flock($lockFp, LOCK_EX);
                // re-check after acquiring lock
                if (file_exists($finalPath)) {
                    flock($lockFp, LOCK_UN); fclose($lockFp); @unlink($lockPath);
                    $urlDeriv = dirname($url) . '/' . basename($finalPath);
                    $meta['sizes'][$sizeName] = ['path' => $finalPath, 'url' => $urlDeriv, 'width' => $finalW, 'height' => $finalH];
                    continue;
                }
                // generate
                $ok = qp_generate_image_size($target, $tempPath, $tW, $tH, $crop);
                if ($ok) {
                    $info2 = @getimagesize($tempPath);
                    if ($info2) {
                        $w = (int)$info2[0]; $h = (int)$info2[1];
                        $finalP = $dir . DIRECTORY_SEPARATOR . $baseName . '-' . $w . 'x' . $h . '.' . $ext;
                        if (file_exists($finalP)) {
                            $i = 1; do { $finalP = $dir . DIRECTORY_SEPARATOR . $baseName . '-' . $w . 'x' . $h . '-' . $i . '.' . $ext; $i++; } while (file_exists($finalP));
                        }
                        @rename($tempPath, $finalP);
                        $urlDeriv = dirname($url) . '/' . basename($finalP);
                        $meta['sizes'][$sizeName] = ['path' => $finalP, 'url' => $urlDeriv, 'width' => $w, 'height' => $h];
                    } else {
                        $urlDeriv = dirname($url) . '/' . basename($tempPath);
                        $meta['sizes'][$sizeName] = ['path' => $tempPath, 'url' => $urlDeriv];
                    }
                }
                // release lock
                flock($lockFp, LOCK_UN); fclose($lockFp); @unlink($lockPath);
            }
        }
        // Update metadata with generated sizes
        qp_update_attachment_metadata($post_id, $meta);
    }

    return [true, ['post_id' => $post_id, 'url' => $url, 'path' => $target, 'mime' => $mime]];
}

function qp_get_attachment_metadata(int $post_id): ?array {
    $pdo = db();
    $stmt = $pdo->prepare('SELECT meta_value FROM ' . table_name('post_meta') . ' WHERE post_id = ? AND meta_key = ? LIMIT 1');
    $stmt->execute([$post_id, '_file_metadata']);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) return null;
    $data = json_decode($row['meta_value'], true);
    return is_array($data) ? $data : null;
}

function qp_update_attachment_metadata(int $post_id, array $meta): void {
    $pdo = db();
    $stmt = $pdo->prepare('UPDATE ' . table_name('post_meta') . ' SET meta_value = ? WHERE post_id = ? AND meta_key = ?');
    $stmt->execute([json_encode($meta), $post_id, '_file_metadata']);
}

// Clear in-memory attachment caches for a post or all
function qp_clear_attachment_cache(?int $post_id = null): void {
    if (!isset($GLOBALS['qp_attachment_cache'])) return;
    if ($post_id === null) {
        unset($GLOBALS['qp_attachment_cache']);
        return;
    }
    if (!empty($GLOBALS['qp_attachment_cache']['url'][$post_id])) unset($GLOBALS['qp_attachment_cache']['url'][$post_id]);
    if (!empty($GLOBALS['qp_attachment_cache']['image_src'])) {
        foreach (array_keys($GLOBALS['qp_attachment_cache']['image_src']) as $k) {
            if (strpos($k, $post_id . '|') === 0) unset($GLOBALS['qp_attachment_cache']['image_src'][$k]);
        }
    }
}

function qp_get_attachment_url(int $post_id): ?string {
    // per-request cache
    if (!isset($GLOBALS['qp_attachment_cache'])) $GLOBALS['qp_attachment_cache'] = ['url'=>[], 'image_src'=>[]];
    if (isset($GLOBALS['qp_attachment_cache']['url'][$post_id])) return $GLOBALS['qp_attachment_cache']['url'][$post_id];

    $meta = qp_get_attachment_metadata($post_id);
    if (!$meta || empty($meta['file'])) return null;
    $url = $meta['file']['url'] ?? null;
    // If stored value is already absolute, return it
    if ($url && preg_match('#^https?://#i', $url)) return function_exists('qp_apply_cdn_to_url') ? qp_apply_cdn_to_url($url) : $url;
    // If we have a full backup, prefer that
    if (empty($url) && !empty($meta['file']['url_full'])) return $meta['file']['url_full'];
    if (!$url) return null;
    $base = qp_uploads_url_base();
    $final = rtrim($base, '/') . '/' . ltrim($url, '/');
    $GLOBALS['qp_attachment_cache']['url'][$post_id] = $final;
    return $final;
}

function qp_apply_cdn_to_url(string $url): string {
    if (!function_exists('apply_filters')) return $url;
    $cdn = apply_filters('cdn_assets_domain', null);
    if (!is_string($cdn) || trim($cdn) === '') return $url;
    $cdn = trim($cdn);
    if (!preg_match('#^https?://#i', $url)) return $url;
    $orig = parse_url($url);
    $orig_scheme = $orig['scheme'] ?? 'http';
    $orig_path = $orig['path'] ?? '';
    if (preg_match('#^https?://#i', $cdn)) {
        $parsed = parse_url($cdn);
        $cdn_scheme = $parsed['scheme'] ?? $orig_scheme;
        $cdn_host = $parsed['host'] ?? '';
        $cdn_path = isset($parsed['path']) ? rtrim($parsed['path'], '/') : '';
        return $cdn_scheme . '://' . $cdn_host . $cdn_path . $orig_path . (isset($orig['query']) ? ('?' . $orig['query']) : '');
    } else {
        // Treat as host (optionally with port)
        return $orig_scheme . '://' . $cdn . $orig_path . (isset($orig['query']) ? ('?' . $orig['query']) : '');
    }
}

function qp_get_attachment_path(int $post_id): ?string {
    $meta = qp_get_attachment_metadata($post_id);
    if (!$meta || empty($meta['file'])) return null;
    $path = $meta['file']['path'] ?? null;
    $url = $meta['file']['url'] ?? null;
    // If stored value looks like an absolute filesystem path, return it
    if ($path && (strpos($path, DIRECTORY_SEPARATOR) === 0 || preg_match('/^[A-Za-z]:\\\\/', $path))) return $path;
    // If stored path is an absolute URL, map to local path
    if ($path && preg_match('#^https?://#i', $path)) {
        $mapped = qp_map_uploads_url_to_path($path);
        if ($mapped) return $mapped;
    }
    // If we have a full backup, prefer that
    if (empty($path) && !empty($meta['file']['path_full'])) return $meta['file']['path_full'];
    // If we have an absolute URL in file.url, attempt to map that
    if ((empty($path) || $path === null) && !empty($url) && preg_match('#^https?://#i', $url)) {
        $mapped = qp_map_uploads_url_to_path($url);
        if ($mapped) return $mapped;
    }
    if (!$path) return null;
    $base = qp_uploads_base();
    return rtrim($base, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, ltrim($path, '/\\'));
}

function qp_get_attachment_by_filename(string $filename): ?int {
    $pdo = db();
    $stmt = $pdo->prepare('SELECT post_id, meta_value FROM ' . table_name('post_meta') . ' WHERE meta_key = ?');
    $stmt->execute(['_file_metadata']);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $meta = json_decode($row['meta_value'], true);
        if (is_array($meta) && isset($meta['file']['filename']) && $meta['file']['filename'] === $filename) {
            return (int)$row['post_id'];
        }
    }
    return null;
}

function qp_image_editor_available(): bool {
    return function_exists('imagecreatetruecolor');
}

// Compress or re-encode an image while preserving transparency where applicable
function qp_img_compressor(string $source, string $destination, int $quality = 85): bool {
    $info = @getimagesize($source);
    if ($info === false) return false;
    $mime = $info['mime'] ?? '';
    switch ($mime) {
        case 'image/jpeg':
            $img = imagecreatefromjpeg($source); if (!$img) return false;
            $ok = imagejpeg($img, $destination, max(0, min(100, $quality))); imagedestroy($img); return $ok;
        case 'image/png':
            $img = imagecreatefrompng($source); if (!$img) return false;
            imagesavealpha($img, true);
            $pngQ = max(0, min(9, (int)round((100 - $quality) / 10))); // map 0-100 -> 0-9
            $ok = imagepng($img, $destination, $pngQ); imagedestroy($img); return $ok;
        case 'image/gif':
            $img = imagecreatefromgif($source); if (!$img) return false;
            $ok = imagegif($img, $destination); imagedestroy($img); return $ok;
        case 'image/webp':
            if (!function_exists('imagecreatefromwebp') || !function_exists('imagewebp')) return false;
            $img = imagecreatefromwebp($source); if (!$img) return false;
            $ok = imagewebp($img, $destination, max(0, min(100, $quality))); imagedestroy($img); return $ok;
        default:
            return false;
    }
}

function qp_generate_image_size(string $sourcePath, string $destPath, int $targetW, int $targetH, bool $crop = false): bool {
    $info = getimagesize($sourcePath);
    if ($info === false) return false;
    $width = (int)$info[0];
    $height = (int)$info[1];
    $mime = $info['mime'] ?? '';
    switch ($mime) {
        case 'image/jpeg': $src = imagecreatefromjpeg($sourcePath); break;
        case 'image/png': $src = imagecreatefrompng($sourcePath); break;
        case 'image/gif': $src = imagecreatefromgif($sourcePath); break;
        case 'image/webp': $src = function_exists('imagecreatefromwebp') ? imagecreatefromwebp($sourcePath) : null; break;
        default: return false;
    }
    if (!$src) return false;

    if ($targetW <= 0 && $targetH <= 0) { $targetW = $width; $targetH = $height; }
    elseif ($targetW > 0 && $targetH <= 0) { $targetH = (int)round($height * ($targetW / $width)); }
    elseif ($targetH > 0 && $targetW <= 0) { $targetW = (int)round($width * ($targetH / $height)); }

    if ($crop) {
        $srcRatio = $width / $height; $dstRatio = $targetW / $targetH;
        if ($srcRatio > $dstRatio) { $newHeight = $height; $newWidth = (int)round($height * $dstRatio); $srcX = (int)(($width - $newWidth) / 2); $srcY = 0; }
        else { $newWidth = $width; $newHeight = (int)round($width / $dstRatio); $srcX = 0; $srcY = (int)(($height - $newHeight) / 2); }
        $dst = imagecreatetruecolor($targetW, $targetH);
        // Preserve transparency backgrounds for PNG/WebP/GIF
        if (in_array($mime, ['image/png','image/webp'], true)) { imagealphablending($dst, false); imagesavealpha($dst, true); $transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127); imagefilledrectangle($dst, 0, 0, $targetW, $targetH, $transparent); }
        elseif ($mime === 'image/gif') { $ti = imagecolortransparent($src); if ($ti >= 0) { $tc = imagecolorsforindex($src, $ti); $tid = imagecolorallocate($dst, $tc['red'], $tc['green'], $tc['blue']); imagefill($dst, 0, 0, $tid); imagecolortransparent($dst, $tid); } }
        imagecopyresampled($dst, $src, 0, 0, $srcX, $srcY, $targetW, $targetH, $newWidth, $newHeight);
    } else {
        $scale = min($targetW / $width, $targetH / $height);
        $newW = (int)round($width * $scale); $newH = (int)round($height * $scale);
        $dst = imagecreatetruecolor($newW, $newH);
        if (in_array($mime, ['image/png','image/webp'], true)) { imagealphablending($dst, false); imagesavealpha($dst, true); $transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127); imagefilledrectangle($dst, 0, 0, $newW, $newH, $transparent); }
        elseif ($mime === 'image/gif') { $ti = imagecolortransparent($src); if ($ti >= 0) { $tc = imagecolorsforindex($src, $ti); $tid = imagecolorallocate($dst, $tc['red'], $tc['green'], $tc['blue']); imagefill($dst, 0, 0, $tid); imagecolortransparent($dst, $tid); } }
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $newW, $newH, $width, $height);
    }

    $ok = false;
    switch ($mime) {
        case 'image/jpeg': $ok = imagejpeg($dst, $destPath, 85); break;
        case 'image/png': imagesavealpha($dst, true); $ok = imagepng($dst, $destPath, 6); break;
        case 'image/gif': $ok = imagegif($dst, $destPath); break;
        case 'image/webp': $ok = function_exists('imagewebp') ? imagewebp($dst, $destPath, 85) : false; break;
    }
    imagedestroy($src); imagedestroy($dst);
    return $ok;
}

function qp_get_attachment_image_src(int $post_id, string $size = 'full'): ?array {
    if (!isset($GLOBALS['qp_attachment_cache'])) $GLOBALS['qp_attachment_cache'] = ['url'=>[], 'image_src'=>[]];
    $cache_key = $post_id . '|' . $size;
    if (isset($GLOBALS['qp_attachment_cache']['image_src'][$cache_key])) return $GLOBALS['qp_attachment_cache']['image_src'][$cache_key];

    $meta = qp_get_attachment_metadata($post_id);
    if (!$meta || !isset($meta['file'])) return null;
    // Full size: return reconstructed full URL/path
    if ($size === 'full') {
        $full_url = qp_get_attachment_url($post_id);
        $full_path = qp_get_attachment_path($post_id);
        return [$full_url, $full_path];
    }

    // If a stored size exists and file is present on disk, return it (reconstruct URL if necessary)
    if (!empty($meta['sizes'][$size]['path'])) {
        $storedPath = $meta['sizes'][$size]['path'];
        // ensure we have an absolute filesystem path
        if (!(strpos($storedPath, DIRECTORY_SEPARATOR) === 0 || preg_match('#^[A-Za-z]:\\#', $storedPath))) {
            $storedPathFull = rtrim(qp_uploads_base(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, ltrim($storedPath, '/\\'));
        } else {
            $storedPathFull = $storedPath;
        }
        if ($storedPathFull && file_exists($storedPathFull)) {
            $storedUrl = $meta['sizes'][$size]['url'] ?? null;
            if ($storedUrl && !preg_match('#^https?://#i', $storedUrl)) {
                $storedUrlFull = rtrim(qp_uploads_url_base(), '/') . '/' . ltrim($storedUrl, '/');
            } elseif ($storedUrl) {
                $storedUrlFull = function_exists('qp_apply_cdn_to_url') ? qp_apply_cdn_to_url($storedUrl) : $storedUrl;
            } else {
                // derive url from path
                $uploadsBase = rtrim(qp_uploads_base(), DIRECTORY_SEPARATOR);
                $rel = ltrim(str_replace('\\', '/', substr($storedPathFull, strlen($uploadsBase) + 1)), '/');
                $storedUrlFull = rtrim(qp_uploads_url_base(), '/') . '/' . $rel;
            }
            $val = [$storedUrlFull, $storedPathFull];
            $GLOBALS['qp_attachment_cache']['image_src'][$cache_key] = $val;
            return $val;
        }
    }

    // Need to generate the derivative
    $sizes = qp_get_image_sizes();
    if (!isset($sizes[$size])) return null;
    $def = $sizes[$size];
    $srcPath = qp_get_attachment_path($post_id);
    if (!$srcPath || !qp_image_editor_available()) return null;
    $ext = $meta['file']['ext'] ?? pathinfo($srcPath, PATHINFO_EXTENSION);
    $dir = dirname($srcPath);
    $baseName = pathinfo($srcPath, PATHINFO_FILENAME);

    // Compute expected final dimensions using same logic as qp_generate_image_size
    $srcInfo = @getimagesize($srcPath);
    if ($srcInfo === false) return null;
    $origW = (int)$srcInfo[0]; $origH = (int)$srcInfo[1];
    $targetW = (int)($def['width'] ?? 0); $targetH = (int)($def['height'] ?? 0); $crop = (bool)($def['crop'] ?? false);
    if ($targetW <= 0 && $targetH <= 0) { $finalW = $origW; $finalH = $origH; }
    elseif ($targetW > 0 && $targetH <= 0) { $finalH = (int)round($origH * ($targetW / max(1, $origW))); $finalW = $targetW; }
    elseif ($targetH > 0 && $targetW <= 0) { $finalW = (int)round($origW * ($targetH / max(1, $origH))); $finalH = $targetH; }
    else {
        if ($crop) { $finalW = $targetW; $finalH = $targetH; }
        else { $scale = min($targetW / max(1,$origW), $targetH / max(1,$origH)); $finalW = (int)round($origW * $scale); $finalH = (int)round($origH * $scale); }
    }

    $final = $dir . DIRECTORY_SEPARATOR . $baseName . '-' . $finalW . 'x' . $finalH . '.' . $ext;
    // If final already exists, reuse it and update metadata
    if (file_exists($final)) {
        $rel = ltrim(str_replace('\\', '/', substr($final, strlen(rtrim(qp_uploads_base(), DIRECTORY_SEPARATOR)) + 1)), '/');
        $meta['sizes'][$size] = ['path' => $final, 'url' => $rel, 'width' => $finalW, 'height' => $finalH];
        qp_update_attachment_metadata($post_id, $meta);
        $val = [rtrim(qp_uploads_url_base(), '/') . '/' . $rel, $final];
        $GLOBALS['qp_attachment_cache']['image_src'][$cache_key] = $val;
        return $val;
    }

    // Acquire a simple per-size lock to prevent concurrent generation of the same derivative
    $lockPath = $dir . DIRECTORY_SEPARATOR . $baseName . '-' . $size . '.lock';
    $lockFp = @fopen($lockPath, 'c');
    if ($lockFp === false) {
        // Unable to create lock file; fall back to generate without lock
        $temp = $dir . DIRECTORY_SEPARATOR . $baseName . '-' . $size . '.' . $ext;
        $ok = qp_generate_image_size($srcPath, $temp, $targetW, $targetH, $crop);
        if (!$ok) return null;
        $info = @getimagesize($temp);
    } else {
        // Block until we can acquire exclusive lock; this serializes generation
        flock($lockFp, LOCK_EX);
        // After acquiring lock, re-check whether another process created the final file
        if (file_exists($final)) {
            // release lock and return existing
            flock($lockFp, LOCK_UN); fclose($lockFp); @unlink($lockPath);
            $rel = ltrim(str_replace('\\', '/', substr($final, strlen(rtrim(qp_uploads_base(), DIRECTORY_SEPARATOR)) + 1)), '/');
            $meta['sizes'][$size] = ['path' => $final, 'url' => $rel, 'width' => $finalW, 'height' => $finalH];
            qp_update_attachment_metadata($post_id, $meta);
            $val = [rtrim(qp_uploads_url_base(), '/') . '/' . $rel, $final];
            $GLOBALS['qp_attachment_cache']['image_src'][$cache_key] = $val;
            return $val;
        }
        // safe to generate
        $temp = $dir . DIRECTORY_SEPARATOR . $baseName . '-' . $size . '.' . $ext;
        $ok = qp_generate_image_size($srcPath, $temp, $targetW, $targetH, $crop);
        if (!$ok) {
            flock($lockFp, LOCK_UN); fclose($lockFp); @unlink($lockPath);
            return null;
        }
        $info = @getimagesize($temp);
    }
    $metaChanged = false;
    if (!$info) {
        // fallback: store temp as size
        $rel = ltrim(str_replace('\\', '/', substr($temp, strlen(rtrim(qp_uploads_base(), DIRECTORY_SEPARATOR)) + 1)), '/');
        $meta['sizes'][$size] = ['path' => $temp, 'url' => $rel];
        $metaChanged = true;
        if ($metaChanged) qp_update_attachment_metadata($post_id, $meta);
        $val = [rtrim(qp_uploads_url_base(), '/') . '/' . $rel, $temp];
        // ensure lock cleanup if we acquired one
        if (isset($lockFp) && is_resource($lockFp)) {
            flock($lockFp, LOCK_UN); fclose($lockFp); @unlink($lockPath);
        }
        $GLOBALS['qp_attachment_cache']['image_src'][$cache_key] = $val;
        return $val;
    }
    $w = (int)$info[0]; $h = (int)$info[1];
    // prefer canonical final name; if exists, use next available suffix
    $final = $dir . DIRECTORY_SEPARATOR . $baseName . '-' . $w . 'x' . $h . '.' . $ext;
    if (file_exists($final)) {
        $i = 1;
        do {
            $candidate = $dir . DIRECTORY_SEPARATOR . $baseName . '-' . $w . 'x' . $h . '-' . $i . '.' . $ext;
            $i++;
        } while (file_exists($candidate));
        $final = $candidate;
    }
    @rename($temp, $final);
    $uploadsBase = rtrim(qp_uploads_base(), DIRECTORY_SEPARATOR);
    $rel = ltrim(str_replace('\\', '/', substr($final, strlen($uploadsBase) + 1)), '/');
    $urlDeriv = $rel;
    $meta['sizes'][$size] = ['path' => $final, 'url' => $urlDeriv, 'width' => $w, 'height' => $h];
    $metaChanged = true;
    if ($metaChanged) qp_update_attachment_metadata($post_id, $meta);
    $val = [rtrim(qp_uploads_url_base(), '/') . '/' . $urlDeriv, $final];
    // ensure lock cleanup if we acquired one
    if (isset($lockFp) && is_resource($lockFp)) {
        flock($lockFp, LOCK_UN); fclose($lockFp); @unlink($lockPath);
    }
    $GLOBALS['qp_attachment_cache']['image_src'][$cache_key] = $val;
    return $val;
}

function qp_get_attachment_image(int $post_id, string $size = 'full', array $attr = []): string {
    $src = qp_get_attachment_image_src($post_id, $size);
    if (!$src) return '';
    [$url, $path] = $src;
    $info = @getimagesize($path);
    $w = $info ? (int)$info[0] : null; $h = $info ? (int)$info[1] : null;
    $attrs = '';
    foreach ($attr as $k => $v) {
        $attrs .= ' ' . htmlspecialchars($k, ENT_QUOTES) . '="' . htmlspecialchars($v, ENT_QUOTES) . '"';
    }
    if ($w && $h) { $attrs .= ' width="' . $w . '" height="' . $h . '"'; }
    return '<img src="' . htmlspecialchars($url, ENT_QUOTES) . '"' . $attrs . ' />';
}

// Default sizes (filterable by themes/plugins)
function qp_register_default_image_sizes(): void {
    $qp_default_image_sizes = [
        'thumbnail' => [150, 150, true],
        'medium'    => [300, 0, false],
        'large'     => [1024, 0, false],
    ];
    if (function_exists('apply_filters')) {
        $qp_default_image_sizes = apply_filters('qp_default_image_sizes', $qp_default_image_sizes);
    }
    // Accept either associative map `name => [w,h,crop]`, or numeric list of arrays
    foreach ($qp_default_image_sizes as $k => $v) {
        if (is_int($k) && is_array($v)) {
            // numeric list: [name, width, height, crop]
            $name = $v[0] ?? null;
            $w = isset($v[1]) ? (int)$v[1] : 0;
            $h = isset($v[2]) ? (int)$v[2] : 0;
            $c = !empty($v[3]);
            if ($name) qp_add_image_size($name, $w, $h, $c);
            continue;
        }
        // associative: name => [width,height,crop] or name => ['width'=>..,'height'=>..,'crop'=>..]
        $name = $k;
        if (!is_array($v)) continue;
        if (array_key_exists('width', $v) || array_key_exists('height', $v) || array_key_exists('crop', $v)) {
            $w = isset($v['width']) ? (int)$v['width'] : (int)($v[0] ?? 0);
            $h = isset($v['height']) ? (int)$v['height'] : (int)($v[1] ?? 0);
            $c = isset($v['crop']) ? (bool)$v['crop'] : (bool)($v[2] ?? false);
        } else {
            $w = (int)($v[0] ?? 0);
            $h = (int)($v[1] ?? 0);
            $c = (bool)($v[2] ?? false);
        }
        qp_add_image_size($name, $w, $h, $c);
    }
}

// Register defaults now and again on 'init' so themes/plugins can add filters
qp_register_default_image_sizes();
if (function_exists('add_action')) add_action('init', function(){ qp_register_default_image_sizes(); }, 20);
//if (function_exists('add_admin_action')) add_admin_action('init', function(){ qp_register_default_image_sizes(); }, 20);
/**
 * Get orphaned attachment IDs (attachments without _parent_id meta)
 * These are files uploaded but not attached to any post/term
 * 
 * @param int|null $older_than_days Optional: Only get attachments older than X days (default: null = all orphans)
 * @return array Array of orphaned attachment post IDs
 */
function qp_get_orphaned_attachments(?int $older_than_days = null): array {
    $pdo = db();
    
    $sql = "SELECT p.id, p.created_at 
            FROM " . table_name('posts') . " p 
            WHERE p.post_type = 'attachment' 
            AND NOT EXISTS (
                SELECT 1 FROM " . table_name('post_meta') . " pm 
                WHERE pm.post_id = p.id AND pm.meta_key = '_parent_id'
            )";
    
    if ($older_than_days !== null && $older_than_days > 0) {
        $sql .= " AND p.created_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? DAY)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$older_than_days]);
    } else {
        $stmt = $pdo->query($sql);
    }
    
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

/**
 * Delete orphaned attachments (for use in cron jobs)
 * 
 * @param int $older_than_days Only delete orphans older than X days (default: 7 days for safety)
 * @return int Number of attachments deleted
 */
function qp_cleanup_orphaned_attachments(int $older_than_days = 7): int {
    $orphan_ids = qp_get_orphaned_attachments($older_than_days);
    $deleted = 0;
    
    foreach ($orphan_ids as $post_id) {
        $meta = qp_get_attachment_metadata($post_id);
        if ($meta) {
            // Delete physical files
            @unlink($meta['file']['path'] ?? '');
            foreach (($meta['sizes'] ?? []) as $s) {
                if (!empty($s['path'])) @unlink($s['path']);
            }
            
            // Delete database records
            $pdo = db();
            $pdo->prepare('DELETE FROM ' . table_name('post_meta') . ' WHERE post_id = ?')->execute([$post_id]);
            $pdo->prepare('DELETE FROM ' . table_name('posts') . ' WHERE id = ? AND post_type = ?')->execute([$post_id, 'attachment']);
            $deleted++;
        }
    }
    
    return $deleted;
}


// Helper: get post ID by slug (optional post_type, optional published-only)
if (!function_exists('qp_get_post_id_by_slug')) {
    function qp_get_post_id_by_slug(string $slug, string $post_type = null, bool $only_published = true): ?int {
        $slug = trim($slug);
        if ($slug === '') return null;
        if (!function_exists('db') || !function_exists('table_name')) return null;

        $pdo = db();
        $params = [$slug];
        $sql = 'SELECT id FROM ' . table_name('posts') . ' WHERE slug = ?';
        if ($post_type !== null) {
            $sql .= ' AND post_type = ?';
            $params[] = $post_type;
        }
        if ($only_published) {
            $sql .= " AND status='published' AND (published_at IS NULL OR published_at <= UTC_TIMESTAMP())";
        }
        $sql .= ' LIMIT 1';

        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ? (int)$row['id'] : null;
        } catch (Exception $ex) {
            return null;
        }
    }
}

///helper get slug by post ID
if (!function_exists('qp_get_slug_by_post_id')) {
    function qp_get_slug_by_post_id(int $post_id): ?string {
        $post_id = intval($post_id);
        if ($post_id <= 0) return null;
        if (!function_exists('db') || !function_exists('table_name')) return null;

        $pdo = db();
        $sql = 'SELECT slug FROM ' . table_name('posts') . ' WHERE id = ? LIMIT 1';
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$post_id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ? $row['slug'] : null;
        } catch (Exception $ex) {
            return null;
        }
    }

}

// Wrapper: resolve the 'queried' post ID using globals, query string, or slug
if (!function_exists('qp_get_queried_post_id')) {
    function qp_get_queried_post_id(string $post_type = null, bool $only_published = true): ?int {
        // 1) Prefer global post if available
        $global_id = $GLOBALS['post']['id'] ?? null;
        if (!empty($global_id) && is_numeric($global_id)) return (int)$global_id;

        // 2) Check common query-string parameters
        $candidates = [
            $_GET['p'] ?? null,
            $_GET['post_id'] ?? null,
            $_GET['id'] ?? null,
        ];
        foreach ($candidates as $val) {
            if ($val !== null && $val !== '') {
                if (is_numeric($val)) return (int)$val;
            }
        }

        // 3) Slug fallback
        $slug = $_GET['slug'] ?? ($_GET['post_name'] ?? null);
        if ($slug && function_exists('qp_get_post_id_by_slug')) {
            $found = qp_get_post_id_by_slug($slug, $post_type, $only_published);
            if ($found) return (int)$found;
        }

        return null;
    }
}

function is_frontpage($strict = false) {
    // If a Qlopy route/global exists, prefer it (adjust name if your site uses a different variable)
    if (isset($GLOBALS['route'])) {
        $r = $GLOBALS['route'];
        // Accept common route names including the canonical 'frontpage'
        if ($r === '' || $r === 'home' || $r === 'front' || $r === 'frontpage') {
            return $strict ? empty($_GET) : true;
        }
        return false;
    }

    // Compare path portion of SITE_URL (if defined) with REQUEST_URI
    $reqPath = '/';
    if (!empty($_SERVER['REQUEST_URI'])) {
        $reqPath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';
    }

    if (defined('SITE_URL')) {
        $sitePath = parse_url(SITE_URL, PHP_URL_PATH);
        if ($sitePath === null || $sitePath === false) {
            $sitePath = '/';
        }
        // normalize
        $sitePath = rtrim($sitePath, '/');
        $reqNorm = rtrim($reqPath, '/');
        if ($sitePath === '') { $sitePath = '/'; }
        if ($reqNorm === '') { $reqNorm = '/'; }

        if ($reqNorm === '/' || $reqNorm === $sitePath) {
            return $strict ? empty($_GET) : true;
        }
        return false;
    }

    // Final fallback: current script is the root index.php
    $script = basename($_SERVER['SCRIPT_NAME'] ?? '');
    if ($script === 'index.php' || $script === 'index') {
        return $strict ? empty($_GET) : true;
    }

    return false;
}




if (!function_exists('is_singular')) {
    function is_singular($post_type = null) {
        $route = $GLOBALS['route'] ?? ($_GET['route'] ?? null);
        if ($route !== 'singular') return false;

        $current_pt = $_GET['post_type'] ?? ($GLOBALS['post']['post_type'] ?? null);

        if ($post_type === null) return true;
        if (is_array($post_type)) return in_array($current_pt, $post_type, true);
        return (string)$current_pt === (string)$post_type;
    }
}

if (!function_exists('is_archive')) {
    function is_archive($post_type = null) {
        $route = $GLOBALS['route'] ?? ($_GET['route'] ?? null);
        if ($route !== 'archive') return false;

        $current_pt = $_GET['post_type'] ?? ($GLOBALS['post']['post_type'] ?? null);

        if ($post_type === null) return true;
        if (is_array($post_type)) return in_array($current_pt, $post_type, true);
        return (string)$current_pt === (string)$post_type;
    }
}

if (!function_exists('is_page')) {
    function is_page($page = null) {
        if (!is_singular('page')) return false;

        if ($page === null) return true;

        $current_id = $GLOBALS['post']['id'] ?? ($_GET['p'] ?? null);
        $current_slug = $GLOBALS['post']['slug'] ?? ($_GET['slug'] ?? null);

        if (is_numeric($page)) return (int)$current_id === (int)$page;
        return (string)$current_slug === (string)$page;
    }
}

function get_the_permalink($post_type, $slug) {

if(!is_string($slug) && $slug !== '' && is_numeric($slug)) {
    $slug = qp_get_slug_by_post_id($slug);
}
            
 // Build a permalink template for this post type where "%slug%" will be substituted client-side.
            // Use permalink_for_page for the `page` post type so root-page patterns ('/%slug%') render without a '/page/' prefix.
            if ($post_type === 'page' && function_exists('permalink_for_page')) {
              $permalink_template = permalink_for_page($slug);
            } elseif (function_exists('permalink_for_post')) {
              $permalink_template = permalink_for_post($slug, $post_type);
              // Prefer the registered post type `slug` for the admin preview
              if (function_exists('get_post_types')) {
                $rpts = get_post_types();
                $reg_slug = $rpts[$post_type]['slug'] ?? '';
                $reg_slug = is_string($reg_slug) ? trim($reg_slug, '/') : '';
                if ($reg_slug !== '' && $reg_slug !== $post_type) {
                  $base_site = defined('SITE_URL') ? SITE_URL : ((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? '');
                  // Replace first occurrence of /{post_type}/ with /{reg_slug}/ in the path portion only
                  if ($base_site && str_starts_with($permalink_template, $base_site)) {
                    $path = substr($permalink_template, strlen($base_site));
                    $newPath = preg_replace('#/'.preg_quote($post_type, '#').'/#', '/'.$reg_slug.'/', $path, 1);
                    $permalink_template = $base_site . $newPath;
                  } else {
                    $permalink_template = preg_replace('#/'.preg_quote($post_type, '#').'/#', '/'.$reg_slug.'/', $permalink_template, 1);
                  }
                }
              }
            } else {
                $base_site = defined('SITE_URL') ? SITE_URL : ((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? '');
                $permalink_template = $base_site . '/index.php?route=singular&post_type=' . rawurlencode($post_type) . '&slug=' . rawurlencode($slug);
            }
return $permalink_template;
}

function get_template_directory_uri($delimeter='') {
    static $base = null;
    static $cache = [];
    $key = (string)$delimeter;
    if (isset($cache[$key])) return $cache[$key];

    // Compute base URL once per request
    if ($base === null) {
        if (defined('THEME_URL') && THEME_URL) {
            $base = rtrim(THEME_URL, '/');
        } else {
            $base = rtrim(get_template_directory_ui(''), '/');
        }

        // Allow replacing the host (or full base) via filter `cdn_assets_domain`.
        if (function_exists('apply_filters')) {
            $cdn = apply_filters('cdn_assets_domain', null);
            if (is_string($cdn) && trim($cdn) !== '') {
                $cdn = trim($cdn);
                $orig = parse_url($base);
                $orig_scheme = $orig['scheme'] ?? 'http';
                $orig_path = $orig['path'] ?? '';
                $orig_query = isset($orig['query']) ? ('?' . $orig['query']) : '';
                $orig_frag = isset($orig['fragment']) ? ('#' . $orig['fragment']) : '';

                if (preg_match('#^https?://#i', $cdn)) {
                    $parsed = parse_url($cdn);
                    $cdn_scheme = $parsed['scheme'] ?? $orig_scheme;
                    $cdn_host = $parsed['host'] ?? '';
                    $cdn_path = isset($parsed['path']) ? rtrim($parsed['path'], '/') : '';
                    $base = $cdn_scheme . '://' . $cdn_host . $cdn_path . $orig_path;
                } else {
                    // Treat as host (optionally with port)
                    $base = $orig_scheme . '://' . $cdn . $orig_path;
                }
            }
        }
    }

    $result = $base . $delimeter;
    $cache[$key] = $result;
    return $result;
}

/**
 * Get the attachment ID used as the post's featured image (thumbnail).
 * Mirrors WP's `get_post_thumbnail_id()` behaviour for compatibility.
 *
 * @param int|object|null $post Optional. Post ID or post object. Defaults to global $post.
 * @return int|null Attachment post ID or null when not set.
 */
function get_post_thumbnail_id($post = null) {
    if (is_null($post)) {
        // Use global post if available
        $post = $GLOBALS['post'] ?? null;
    }
    // Accept either ID or post object/array
    $post_id = null;
    if (is_array($post) && isset($post['id'])) $post_id = (int)$post['id'];
    elseif (is_object($post) && isset($post->ID)) $post_id = (int)$post->ID;
    elseif (is_int($post) || ctype_digit((string)$post)) $post_id = (int)$post;
    if (!$post_id) return null;

    $val = get_post_meta($post_id, '_thumbnail_id');
    if ($val === null) return null;
    // Stored meta may be JSON-encoded (arrays/objects) or plain values.
    // If it's an array/associative structure, try common keys first,
    // then fall back to the first numeric element.
    if (is_array($val)) {
        foreach (['ID','id','post_ID','post_id','attachment_id'] as $k) {
            if (isset($val[$k]) && (is_int($val[$k]) || (is_string($val[$k]) && ctype_digit($val[$k])))) {
                return (int)$val[$k];
            }
        }
        foreach ($val as $candidate) {
            if (is_int($candidate) && $candidate > 0) return $candidate;
            if (is_string($candidate) && ctype_digit($candidate)) return (int)$candidate;
            if (is_numeric($candidate)) return (int)$candidate;
        }
        return null;
    }
    if (is_object($val)) {
        foreach (['ID','id','post_ID','post_id','attachment_id'] as $k) {
            if (isset($val->$k) && (is_int($val->$k) || (is_string($val->$k) && ctype_digit($val->$k)))) {
                return (int)$val->$k;
            }
        }
        return null;
    }
    // Plain scalar values
    if (is_string($val) && ctype_digit($val)) return (int)$val;
    if (is_numeric($val)) return (int)$val;
    return null;
}

/**
 * Determine whether the current post (or passed post) has a featured image.
 * Mirrors WP's `has_post_thumbnail()` behaviour.
 *
 * @param int|object|null $post Optional. Post ID or post object. Defaults to global $post.
 * @return bool True if a featured image is set.
 */
function has_post_thumbnail($post = null) {
    $id = get_post_thumbnail_id($post);
    return !empty($id) && is_int($id) && $id > 0;
}

/**
 * Set the post's featured image (thumbnail).
 * Writes the canonical `_thumbnail_id` meta and keeps the legacy
 * `featured_image` meta for backward compatibility.
 *
 * @param int $post_id
 * @param int|string|null $attachment_id
 * @return bool
 */
function set_post_thumbnail($post_id, $attachment_id) {
    $post_id = (int)$post_id;
    if ($post_id <= 0) return false;

    // Treat empty-ish values as a request to delete the thumbnail
    if ($attachment_id === null || $attachment_id === '' || (is_string($attachment_id) && trim($attachment_id) === '') ) {
        return delete_post_thumbnail($post_id);
    }

    $attachment_id = (int)$attachment_id;
    if ($attachment_id <= 0) return delete_post_thumbnail($post_id);

    // Persist canonical thumbnail meta only. Remove legacy `featured_image` to
    // avoid duplicate storage and encourage the canonical key.
    $res = update_post_meta($post_id, '_thumbnail_id', $attachment_id);
    try { delete_post_meta($post_id, 'featured_image'); } catch (Throwable $_) {}
    if (function_exists('do_action')) do_action('set_post_thumbnail', $post_id, $attachment_id);
    return $res;
}

/**
 * Remove the post's featured image (thumbnail).
 * Deletes both `_thumbnail_id` and legacy `featured_image` meta keys.
 *
 * @param int $post_id
 * @return bool
 */
function delete_post_thumbnail($post_id) {
    $post_id = (int)$post_id;
    if ($post_id <= 0) return false;
    $res1 = delete_post_meta($post_id, '_thumbnail_id');
    $res2 = delete_post_meta($post_id, 'featured_image');
    if (function_exists('do_action')) do_action('delete_post_thumbnail', $post_id);
    return ($res1 || $res2);
}
function get_pattern_base(string $pattern): string {
    $pattern = trim($pattern, '/');
    $pos = strpos($pattern, '%slug%');
    if ($pos === false) {
        // no slug placeholder — return first non-placeholder segment or empty
        foreach (explode('/', $pattern) as $s) {
            if ($s !== '' && strpos($s, '%') === false) return $s;
        }
        return '';
    }
    $before = trim(substr($pattern, 0, $pos), '/');
    if ($before === '') return ''; // root pattern like '/%slug%'
    $parts = explode('/', $before);
    return end($parts);
}
function get_site_archive_url(string $post_type = 'post'): string {
    $patterns = function_exists('permalink_get_patterns') ? permalink_get_patterns() : [];
    $base = '';
    if ($post_type === 'post') {
        $tpl = $patterns['post'] ?? '/post/%slug%';
        $base = function_exists('get_pattern_base') ? get_pattern_base($tpl) : trim(str_replace('%slug%', '', trim($tpl, '/')), '/');
    } else {
        if (!empty($patterns['post_types'][$post_type])) {
            $tpl = $patterns['post_types'][$post_type];
            $base = function_exists('get_pattern_base') ? get_pattern_base($tpl) : trim(str_replace('%slug%', '', trim($tpl, '/')), '/');
        } elseif (!empty($GLOBALS['qlopy_post_types'][$post_type])) {
            // fallback to registered slug in global registration
            $reg = $GLOBALS['qlopy_post_types'][$post_type];
            $base = $reg['slug'] ?? $post_type;
        } else {
            $base = $post_type;
        }
    }
    $base = trim((string)$base, '/');
    $site = rtrim(constant('SITE_URL') ?? (defined('SITE_URL') ? SITE_URL : ''), '/');
    return $site . '/' . ($base !== '' ? $base . '/' : '');
}

// Backwards-compatible helper for themes that expect `get_blog_archive_url()`
if (!function_exists('get_blog_archive_url')) {
    function get_blog_archive_url(): string {
        return get_site_archive_url('post');
    }

    /**
     * Trim text to a given number of words (Qlopy equivalent of WP's wp_trim_words).
     *
     * @param string $text Text to trim.
     * @param int $num_words Number of words to return.
     * @param string $more String to append if text is truncated (can contain HTML).
     * @return string
     */
    function qp_trim_words($text, $num_words = 55, $more = '&#8230;') {
        if (!is_string($text)) {
            if (is_null($text)) return '';
            $text = (string)$text;
        }
        $text = trim($text);
        if ($text === '') return '';

        // Strip tags and normalize whitespace
        $stripped = strip_tags($text);
        $stripped = preg_replace('/\s+/u', ' ', $stripped);
        $stripped = trim($stripped);

        if ($num_words <= 0) return '';

        $words = preg_split('/\s+/u', $stripped, -1, PREG_SPLIT_NO_EMPTY);
        if (!is_array($words) || count($words) === 0) return '';
        if (count($words) <= $num_words) return $stripped;

        $truncated = implode(' ', array_slice($words, 0, $num_words));
        return $truncated . $more;
    }
}

// Basic escaping helpers similar to WordPress (lightweight)
if (!function_exists('esc_url')) {
    function esc_url($url) {
        if (!is_string($url)) return '';
        $url = trim($url);
        // remove potential control characters
        $url = preg_replace('/[\x00-\x1F\x7F]/u', '', $url);
        // allow only http/https/mailto/tel and relative paths
        if (preg_match('/^[a-zA-Z][a-zA-Z0-9+.-]*:/', $url)) {
            $scheme = strtolower(parse_url($url, PHP_URL_SCHEME) ?: '');
            if (!in_array($scheme, ['http','https','mailto','tel'])) return '';
        }
        return filter_var($url, FILTER_SANITIZE_URL);
    }
}

if (!function_exists('esc_html')) {
    function esc_html($text) {
        if (!is_scalar($text)) return '';
        return htmlspecialchars((string)$text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('esc_attr')) {
    /**
     * Escape text for HTML attributes (Qlopy equivalent of WP's esc_attr)
     *
     * @param mixed $text
     * @return string
     */
    function esc_attr($text) {
        if (!is_scalar($text)) return '';
        // Normalize to string and escape quotes & special chars for attribute context
        $s = (string)$text;
        // Remove control characters that are invalid in attributes
        $s = preg_replace('/[\x00-\x1F\x7F]/u', '', $s);
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('sanitize_text_field')) {
    /**
     * Minimal implementation of WP's sanitize_text_field for Qlopy.
     * Strips tags, removes control/null bytes and collapses whitespace.
     */
    function sanitize_text_field($str) {
        if (is_array($str)) return '';
        if (!is_scalar($str)) return '';
        $s = (string)$str;
        // Normalize encoding to UTF-8 when possible
        if (function_exists('mb_convert_encoding')) {
            $s = mb_convert_encoding($s, 'UTF-8', 'UTF-8');
        }
        // Strip tags
        $s = strip_tags($s);
        // Remove null bytes
        $s = str_replace("\0", '', $s);
        // Remove control characters (except common whitespace), replace with single space
        $s = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $s);
        // Collapse multiple whitespace to single space
        $s = preg_replace('/\s+/u', ' ', $s);
        return trim($s);
    }
}

if (!function_exists('qp_unslash')) {
    function qp_unslash($value) {
        if (is_array($value)) return array_map('qp_unslash', $value);
        if (!is_string($value)) return $value;
        return stripslashes($value);
    }
}

// Very small, conservative kses_post-like filter: allow a limited set of tags and attrs
if (!function_exists('qp_kses_post')) {
    function qp_kses_post($html) {
        if (!is_string($html)) return '';
        $allowed_tags = [
            'a' => ['href'=>1,'title'=>1,'rel'=>1,'target'=>1],
            'em' => [], 'strong' => [], 'b'=>[], 'i'=>[], 'u'=>[],
            'p'=>[], 'br'=>[], 'ul'=>[], 'ol'=>[], 'li'=>[],
            'blockquote'=>[], 'code'=>[], 'pre'=>[], 'span'=>['class'=>1],
            'img'=>['src'=>1,'alt'=>1,'title'=>1,'width'=>1,'height'=>1],
        ];
        // Use DOMDocument to sanitize conservatively
        libxml_use_internal_errors(true);
        $doc = new DOMDocument();
        $wrapped = '<div>' . $html . '</div>';
        $doc->loadHTML('<?xml encoding="utf-8" ?>' . $wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        $body = $doc->getElementsByTagName('div')->item(0);
        $out = '';
        foreach ($body->childNodes as $node) {
            $out .= _qp_kses_node($node, $allowed_tags);
        }
        return $out;
    }
}

if (!function_exists('_qp_kses_node')) {
    function _qp_kses_node($node, $allowed_tags) {
        if ($node->nodeType === XML_TEXT_NODE) return htmlspecialchars($node->nodeValue, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        if ($node->nodeType !== XML_ELEMENT_NODE) return '';
        $tag = strtolower($node->nodeName);
        if (!isset($allowed_tags[$tag])) {
            // render children only
            $s = '';
            foreach ($node->childNodes as $c) $s .= _qp_kses_node($c, $allowed_tags);
            return $s;
        }
        $attrs = '';
        $allowed_attrs = $allowed_tags[$tag] ?? [];
        if ($node->hasAttributes()) {
            foreach ($node->attributes as $a) {
                $name = strtolower($a->name);
                if (!isset($allowed_attrs[$name])) continue;
                $val = $a->value;
                if ($name === 'href' || $name === 'src') {
                    $val = esc_url($val);
                    if ($val === '') continue;
                } else {
                    $val = htmlspecialchars($val, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                }
                $attrs .= ' ' . $name . '="' . $val . '"';
            }
        }
        $inner = '';
        foreach ($node->childNodes as $c) $inner .= _qp_kses_node($c, $allowed_tags);
        if (in_array($tag, ['br','img'])) {
            return '<' . $tag . $attrs . ' />';
        }
        return '<' . $tag . $attrs . '>' . $inner . '</' . $tag . '>';
    }
}
if (!function_exists('__return_false')) {
    function __return_false() { return false; }
}
function qp_compress_inline_js($js) {
    // 1. Remove multi-line comments
    $js = preg_replace('!/\*.*?\*/!s', '', $js);
    
    // 2. Remove single-line comments (careful not to clear them inside URLs)
    $js = preg_replace('/(?<!:|\\\|\')\/\/.*$/m', '', $js);
    
    // 3. Remove spaces around operators and brackets
    $js = preg_replace('/\s*([\{\}\(\)\[\]\+\-\*\/=\?:\.,;\|&!<>])\s*/', '$1', $js);
    
    // 4. Reduce multiple spaces, tabs, and newlines into a single space/newline
    $js = preg_replace('/\s+/', ' ', $js);
    
    return trim($js);
}

// ---------------------- Taxonomy / Term helpers ----------------------
/**
 * Return an array of terms for a given taxonomy.
 * Each item is an associative array matching the `taxonomy_terms` table.
 * @param string $taxonomy
 * @return array
 */
/**
 * Retrieve terms for a taxonomy with lightweight WP-like args.
 * Supported args (array or string shorthand):
 * - taxonomy (string) required
 * - fields: 'all'|'ids'|'slugs'|'count' (default 'all')
 * - slug, term: string|array filter by slug or term name
 * - include, exclude: array of term ids
 * - parent: int|null filter direct parent_id
 * - only_parent: bool return only top-level terms (parent_id IS NULL)
 * - child_of: int include descendants of this term id
 * - meta_query: array of clauses: [ ['key'=>k,'value'=>v,'compare'=>'='|'LIKE'|'IN'], ... ] (ANDed)
 * - orderby: 'term'|'slug'|'term_order'|'id' (default 'term')
 * - order: 'ASC'|'DESC' (default 'ASC')
 * - limit, offset: ints
 * - cache: seconds (per-request short cache), default 60
 *
 * Returns array of rows, list of ids/slugs, or integer for count depending on `fields`.
 */
function get_terms($args = []) {
    if (is_string($args)) $args = ['taxonomy' => $args];
    if (!is_array($args)) return [];
    $defaults = [
        'taxonomy' => '',
        'fields' => 'all',
        'slug' => null,
        'term' => null,
        'include' => null,
        'exclude' => null,
        'parent' => null,
        'only_parent' => false,
        'child_of' => null,
        'meta_query' => null,
        'orderby' => 'term',
        'order' => 'ASC',
        'limit' => null,
        'offset' => null,
        'cache' => 60,
    ];
    $opts = array_merge($defaults, $args);
    $taxonomy = trim((string)$opts['taxonomy']);
    if ($taxonomy === '' || !function_exists('db') || !function_exists('table_name')) return [];

    // simple per-request cache
    $cacheTtl = (int)($opts['cache'] ?? 0);
    if (!isset($GLOBALS['qp_terms_cache'])) $GLOBALS['qp_terms_cache'] = [];
    $cacheKey = md5(json_encode($opts));
    if ($cacheTtl > 0 && !empty($GLOBALS['qp_terms_cache'][$cacheKey])) {
        $entry = $GLOBALS['qp_terms_cache'][$cacheKey];
        if (time() - $entry['ts'] <= $entry['ttl']) return $entry['data'];
    }

    $pdo = db();
    $params = [];
    $where = ['taxonomy = ?']; $params[] = $taxonomy;

    // slug / term filters
    if (!empty($opts['slug'])) {
        $sl = (array)$opts['slug'];
        $place = implode(',', array_fill(0, count($sl), '?'));
        $where[] = 'slug IN (' . $place . ')';
        foreach ($sl as $v) $params[] = trim((string)$v);
    }
    if (!empty($opts['term'])) {
        $ts = (array)$opts['term'];
        $place = implode(',', array_fill(0, count($ts), '?'));
        $where[] = 'term IN (' . $place . ')';
        foreach ($ts as $v) $params[] = trim((string)$v);
    }

    // include / exclude by id
    if (!empty($opts['include'])) {
        $inc = array_values(array_map('intval', (array)$opts['include']));
        if (!empty($inc)) {
            $p = implode(',', array_fill(0, count($inc), '?'));
            $where[] = 'id IN (' . $p . ')';
            foreach ($inc as $v) $params[] = $v;
        }
    }
    if (!empty($opts['exclude'])) {
        $exc = array_values(array_map('intval', (array)$opts['exclude']));
        if (!empty($exc)) {
            $p = implode(',', array_fill(0, count($exc), '?'));
            $where[] = 'id NOT IN (' . $p . ')';
            foreach ($exc as $v) $params[] = $v;
        }
    }

    // parent / only_parent
    if (!is_null($opts['parent']) && $opts['parent'] !== '') {
        $where[] = 'parent_id = ?'; $params[] = (int)$opts['parent'];
    }
    if (!empty($opts['only_parent'])) {
        $where[] = 'parent_id IS NULL';
    }

    // child_of -> include descendants
    if (!empty($opts['child_of'])) {
        $desc = get_term_descendants((int)$opts['child_of'], $taxonomy);
        $desc[] = (int)$opts['child_of'];
        $desc = array_values(array_unique($desc));
        if (!empty($desc)) {
            $p = implode(',', array_fill(0, count($desc), '?'));
            $where[] = 'id IN (' . $p . ')';
            foreach ($desc as $d) $params[] = (int)$d;
        }
    }

    // meta_query support: AND of simple clauses (key, value, compare)
    $metaClauses = [];
    if (!empty($opts['meta_query']) && is_array($opts['meta_query'])) {
        foreach ($opts['meta_query'] as $mi => $mc) {
            if (empty($mc['key'])) continue;
            $mkey = $mc['key'];
            $mval = $mc['value'] ?? null;
            $compare = strtoupper(trim($mc['compare'] ?? '='));
            if ($compare === 'IN' && is_array($mval) && !empty($mval)) {
                $place = implode(',', array_fill(0, count($mval), '?'));
                $metaClauses[] = "EXISTS (SELECT 1 FROM " . table_name('term_meta') . " tm WHERE tm.term_id = tt.id AND tm.meta_key = ? AND tm.meta_value IN ($place))";
                $params[] = $mkey;
                foreach ($mval as $mv) $params[] = $mv;
            } elseif ($compare === 'LIKE') {
                $metaClauses[] = "EXISTS (SELECT 1 FROM " . table_name('term_meta') . " tm WHERE tm.term_id = tt.id AND tm.meta_key = ? AND tm.meta_value LIKE ? )";
                $params[] = $mkey; $params[] = (string)$mval;
            } else {
                // default equals
                $metaClauses[] = "EXISTS (SELECT 1 FROM " . table_name('term_meta') . " tm WHERE tm.term_id = tt.id AND tm.meta_key = ? AND tm.meta_value = ? )";
                $params[] = $mkey; $params[] = (string)$mval;
            }
        }
    }

    // Build SELECT based on fields
    $fields = $opts['fields'] ?? 'all';
    $select = 'tt.id, tt.taxonomy, tt.term, tt.slug, tt.parent_id, tt.term_order';
    if ($fields === 'ids') $select = 'tt.id';
    if ($fields === 'slugs') $select = 'tt.slug';
    if ($fields === 'count') $select = 'COUNT(*) AS cnt';

    // Order by
    $allowedOrder = ['term'=>'tt.term','slug'=>'tt.slug','term_order'=>'tt.term_order','id'=>'tt.id'];
    $orderby = $allowedOrder[$opts['orderby']] ?? $allowedOrder['term'];
    $order = strtoupper($opts['order'] ?? 'ASC'); if ($order !== 'ASC') $order = 'DESC';

    $sql = 'SELECT ' . $select . ' FROM ' . table_name('taxonomy_terms') . ' tt WHERE ' . implode(' AND ', $where);
    if (!empty($metaClauses)) {
        $sql .= ' AND (' . implode(' AND ', $metaClauses) . ')';
    }
    if ($fields !== 'count') {
        $sql .= ' ORDER BY ' . $orderby . ' ' . $order;
    }
    if (!empty($opts['limit']) && $fields !== 'count') {
        $sql .= ' LIMIT ' . intval($opts['limit']);
        if (!empty($opts['offset'])) $sql .= ' OFFSET ' . intval($opts['offset']);
    }

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        if ($fields === 'count') {
            $ret = (int)$stmt->fetchColumn();
        } elseif ($fields === 'ids') {
            $ret = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
        } elseif ($fields === 'slugs') {
            $ret = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
        } else {
            $ret = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }
        if ($cacheTtl > 0) {
            $GLOBALS['qp_terms_cache'][$cacheKey] = ['ts'=>time(),'ttl'=>$cacheTtl,'data'=>$ret];
        }
        return $ret;
    } catch (Throwable $_e) {
        return [];
    }
}

/**
 * Return a human-friendly label for a registered taxonomy key.
 * Falls back to ucfirst of the key when no label is configured.
 * @param string $taxonomy
 * @return string|null
 */
function get_taxonomy_label(string $taxonomy): ?string {
    $taxonomy = trim($taxonomy);
    if ($taxonomy === '') return null;
    if (function_exists('get_taxonomies')) {
        $taxes = get_taxonomies();
    } else {
        $taxes = $GLOBALS['qlopy_taxonomies'] ?? [];
    }
    if (!empty($taxes[$taxonomy]) && is_array($taxes[$taxonomy])) {
        $t = $taxes[$taxonomy];
        if (!empty($t['label'])) return (string)$t['label'];
        if (!empty($t['name'])) return (string)$t['name'];
    }
    return ucfirst($taxonomy);
}

/**
 * Resolve a term display name by taxonomy and slug or id.
 * @param string $taxonomy
 * @param int|string $slug_or_id
 * @return string|null
 */
function get_term_name(string $taxonomy, $slug_or_id): ?string {
    $taxonomy = trim($taxonomy);
    if ($taxonomy === '' || !function_exists('db') || !function_exists('table_name')) return null;
    $pdo = db();
    try {
        if (is_numeric($slug_or_id)) {
            $stmt = $pdo->prepare('SELECT term FROM ' . table_name('taxonomy_terms') . ' WHERE id = ? LIMIT 1');
            $stmt->execute([(int)$slug_or_id]);
            $row = $stmt->fetchColumn();
            return $row ?: null;
        }
        $slug = trim((string)$slug_or_id);
        if ($slug === '') return null;
        $stmt = $pdo->prepare('SELECT term FROM ' . table_name('taxonomy_terms') . ' WHERE taxonomy = ? AND slug = ? LIMIT 1');
        $stmt->execute([$taxonomy, $slug]);
        $row = $stmt->fetchColumn();
        return $row ?: null;
    } catch (Throwable $_e) {
        return null;
    }
}

/**
 * Simplified import: download remote URL into the configured uploads folder
 * and call `qp_handle_upload_extensive()` to register the media.
 *
 * This function downloads the remote file into `uploads/imports/`, calls
 * the existing uploader and then removes the temporary import copy.
 * It intentionally keeps behavior small and relies on the upload handler
 * to perform metadata/attachment creation.
 *
 * @param string $url Remote file URL
 * @param int|null $parent_id Optional parent post id to attach meta for
 * @param string $parent_type Optional parent type string
 * @param array $options Options forwarded to qp_handle_upload_extensive
 * @return array [bool $ok, array|string $result]
 */
function qp_media_import_from_url(string $url, ?int $parent_id = null, string $parent_type = '', array $options = []): array {
    if (!filter_var($url, FILTER_VALIDATE_URL)) return [false, 'Invalid URL'];

    $uploadsBase = rtrim(qp_uploads_base(), DIRECTORY_SEPARATOR);
    $importsDir = $uploadsBase . DIRECTORY_SEPARATOR . 'imports';
    if (!is_dir($importsDir)) @mkdir($importsDir, 0775, true);

    $path = parse_url($url, PHP_URL_PATH) ?: '';
    $origName = basename($path) ?: 'remote_file';
    $ext = pathinfo($origName, PATHINFO_EXTENSION);
    $destName = 'import_' . uniqid() . ($ext ? '.' . $ext : '');
    $destPath = $importsDir . DIRECTORY_SEPARATOR . $destName;

    // Download using helper if available, fallback to file_get_contents
    $downloaded = false;
    if (function_exists('qlopy_download_file')) {
        $downloaded = qlopy_download_file($url, $destPath);
    } else {
        $data = @file_get_contents($url);
        if ($data !== false) $downloaded = (bool)@file_put_contents($destPath, $data);
    }
    if (!$downloaded) { @unlink($destPath); return [false, 'Failed to download remote file']; }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = $finfo ? finfo_file($finfo, $destPath) : '';
    if ($finfo) finfo_close($finfo);


    // Copy into PHP's temp upload dir so handlers that expect an upload-like
    // path can operate. Note: is_uploaded_file() will still fail unless we
    // bypass it; we pass 'allow_local' below.
    $phpTmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('qpup_', true) . ($ext ? '.' . $ext : '');
    if (!@copy($destPath, $phpTmp)) { @unlink($destPath); return [false, 'Failed to prepare upload temp']; }

    $fileForUpload = [
        'name' => $origName,
        'tmp_name' => $phpTmp,
        'size' => @filesize($phpTmp) ?: 0,
        'type' => $mime ?: '',
        'error' => 0,
    ];

    // Ensure uploader knows this is a local-import scenario so it skips
    // is_uploaded_file() check we can't satisfy here.
    $opts = $options; $opts['allow_local'] = true;
    if(isset($opts['user_id']) && !empty($opts['user_id'])){
        $userId = $opts['user_id'];
    } else {
        $userId = 1; // Use a default author id (1) for imported media to satisfy NOT NULL DB constraint
    }
    [$ok, $res] = qp_handle_upload_extensive($fileForUpload, $userId, $opts);

    // Remove temps: both the import copy and the php tmp copy
    @unlink($destPath);
    @unlink($phpTmp);

    if ($ok && !empty($res['post_id']) && $parent_id && $parent_type) {
        if (function_exists('update_post_meta')) {
            update_post_meta((int)$res['post_id'], '_parent_id', $parent_id);
            update_post_meta((int)$res['post_id'], '_parent_type', $parent_type);
        } else {
            try {
                $pdo = db();
                $stmt = $pdo->prepare('INSERT INTO ' . table_name('post_meta') . " (post_id, meta_key, meta_value) VALUES (?,?,?) ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value)");
                $stmt->execute([(int)$res['post_id'], '_parent_id', $parent_id]);
                $stmt->execute([(int)$res['post_id'], '_parent_type', $parent_type]);
            } catch (Throwable $_) { /* ignore */ }
        }
    }

    return [$ok, $res];
}

// Admin AJAX bridge: handle `action=qp_media_import_url` from admin and import a remote URL.
if (function_exists('add_admin_action')) {
    add_admin_action('iitcm_admin_ajax_qp_media_import_url', function($req) {
        $r = is_array($req) && isset($req[0]) ? $req[0] : $req;
        $url = trim((string)($r['url'] ?? ''));
        $parent_id = isset($r['parent_id']) ? intval($r['parent_id']) : null;
        $parent_type = isset($r['parent_type']) ? trim((string)$r['parent_type']) : '';
        $opts = [];
        if (!empty($r['options']) && is_string($r['options'])) {
            $decoded = json_decode($r['options'], true);
            if (is_array($decoded)) $opts = $decoded;
        }

        header('Content-Type: application/json');
        if ($url === '') { echo json_encode(['status'=>'error','message'=>'url required']); exit; }

        [$ok, $res] = qp_media_import_from_url($url, $parent_id, $parent_type, $opts);
        if ($ok) {
            echo json_encode(['status'=>'success','result'=>$res]);
        } else {
            echo json_encode(['status'=>'error','message'=>$res]);
        }
        exit;
    }, 10, 1);
}
// Count posts associated with a term. Defaults to counting 'travely_tour' post_type.
function get_term_post_count($term, $taxonomy = 'category', $post_type = 'post', $include_children = true) {
    // Resolve term id from numeric, object/array, or slug
    $term_id = null;
    if (is_object($term)) $term_id = (int)($term->term_id ?? $term->id ?? 0);
    elseif (is_array($term)) $term_id = (int)($term['id'] ?? $term['term_id'] ?? 0);
    elseif (ctype_digit((string)$term)) $term_id = (int)$term;
    else {
        if (function_exists('get_term_by_slug')) {
            $t = get_term_by_slug($taxonomy, (string)$term);
            $term_id = (int)($t['id'] ?? 0);
        }
    }

    if ($term_id <= 0) return 0;

    // build cache key (term, taxonomy, post_type, include_children)
    $tax_safe = $taxonomy ? preg_replace('/[^a-zA-Z0-9_]+/', '_', $taxonomy) : '';
    $cache_key = 'term_post_count_' . $term_id . '_' . $tax_safe . '_' . $post_type . '_' . ($include_children ? '1' : '0');
    if (function_exists('qp_cache_fetch')) {
        $cached = qp_cache_fetch($cache_key);
        if ($cached !== false && $cached !== null && is_numeric($cached)) {
            return (int)$cached;
        }
    }

    try {
        $pdo = db();
        $term_ids = [$term_id];
        if ($include_children && $taxonomy && function_exists('get_term_descendants')) {
            $desc = get_term_descendants($term_id, $taxonomy);
            if (!empty($desc) && is_array($desc)) $term_ids = array_merge($term_ids, $desc);
        }

        // Build query with placeholders for term ids
        $placeholders = implode(',', array_fill(0, count($term_ids), '?'));
        $sql = 'SELECT COUNT(DISTINCT p.id) FROM ' . table_name('posts') . ' p INNER JOIN ' . table_name('post_terms') . ' pt ON p.id = pt.post_id WHERE pt.term_id IN (' . $placeholders . ')';
        $params = $term_ids;
        if ($post_type) {
            $sql .= ' AND p.post_type = ?';
            $params[] = $post_type;
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $count = (int)$stmt->fetchColumn();

        if (function_exists('qp_cache_store')) qp_cache_store($cache_key, $count, 300);

        return $count;
    } catch (Throwable $e) {
        return 0;
    }
}

// Invalidate cached term post counts for a term (and optionally descendants)
function invalidate_term_post_count_cache_by_term($term_id, $taxonomy = null, $post_type = 'travely_tour') {
    if (!function_exists('qp_cache_delete')) return;
    $term_id = (int)$term_id;
    if ($term_id <= 0) return;
    $tax_safe = $taxonomy ? preg_replace('/[^a-zA-Z0-9_]+/', '_', $taxonomy) : '';
    $keys = [];
    foreach (['0','1'] as $ic) {
        $keys[] = 'term_post_count_' . $term_id . '_' . $tax_safe . '_' . $ic . '_' . $post_type;
    }
    if ($taxonomy && function_exists('get_term_descendants')) {
        $desc = get_term_descendants($term_id, $taxonomy);
        if (!empty($desc)) {
            foreach ($desc as $d) {
                foreach (['0','1'] as $ic) {
                    $keys[] = 'term_post_count_' . (int)$d . '_' . $tax_safe . '_' . $ic . '_' . $post_type;
                }
            }
        }
    }
    foreach ($keys as $k) qp_cache_delete($k);
}

// Invalidate cached term counts for all terms attached to a post
function invalidate_term_post_count_cache_by_post($post_id) {
    if (!function_exists('qp_cache_delete')) return;
    $post = get_post($post_id);
    if (!$post) return;
    $post_type = $post['post_type'] ?? null;
    $pdo = db();
    $stmt = $pdo->prepare('SELECT pt.term_id, tt.taxonomy FROM ' . table_name('post_terms') . ' pt LEFT JOIN ' . table_name('taxonomy_terms') . ' tt ON pt.term_id = tt.id WHERE pt.post_id = ?');
    $stmt->execute([$post_id]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        $term_id = (int)($r['term_id'] ?? 0);
        $taxonomy = $r['taxonomy'] ?? null;
        if ($term_id > 0) invalidate_term_post_count_cache_by_term($term_id, $taxonomy, $post_type ?? 'travely_tour');
    }
}
function get_tax_term_meta($term_id_or_slug, string $taxonomy, string $meta_key, $default = null) {
    $term_id = null;
    if (is_string($term_id_or_slug) && $term_id_or_slug !== '') {
        if (function_exists('get_term_by_slug')) {
            $t = get_term_by_slug($taxonomy, $term_id_or_slug);
            if ($t && !empty($t['id'])) {
                $term_id_or_slug = (int)$t['id'];
            }
        }
    }
    if (is_object($term_id_or_slug)) $term_id = (int)($term_id_or_slug->term_id ?? 0);
    else $term_id = (int)$term_id_or_slug;
    if ($term_id <= 0) return $default;

    $val = qpmeta_get_object_meta('term', $term_id, $meta_key);
    return $val !== null ? $val : $default;
}
// Return all terms attached to a post grouped by taxonomy.
if (!function_exists('qp_get_post_terms')) {
    function qp_get_post_terms(int $post_id): array {
        $pdo = db();
        $sql = 'SELECT t.taxonomy, t.id, t.term, t.slug FROM ' . table_name('taxonomy_terms') . ' t INNER JOIN ' . table_name('post_terms') . ' pt ON pt.term_id = t.id WHERE pt.post_id = ? ORDER BY t.taxonomy, t.term';
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$post_id]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $by_tax = [];
        foreach ($rows as $r) {
            $tax = $r['taxonomy'] ?? '';
            if ($tax === '') continue;
            $by_tax[$tax][] = [
                'id' => isset($r['id']) ? (int)$r['id'] : 0,
                'term' => $r['term'] ?? '',
                'slug' => $r['slug'] ?? '',
            ];
        }
        return $by_tax;
    }
}

/**
 * Programmatically log in a user by ID.
 * Returns the token row (array) on success, or false on failure.
 */
function qp_login_by_id(int $user_id, ?int $ttl = null) {
    if ($user_id <= 0) return false;
    if (!function_exists('create_session_token') || !function_exists('set_session_token_cookie') || !function_exists('qp_hash_token_sig')) {
        return false;
    }

    // Create DB-backed token
    $tokenRow = $ttl === null ? create_session_token($user_id) : create_session_token($user_id, (int)$ttl);
    if (!$tokenRow) return false;

    // Send cookie to browser (uses same cookie path/flags as core helper)
    if (function_exists('set_session_token_cookie')) {
        set_session_token_cookie($tokenRow);
    }

    // Ensure current request recognizes the login immediately
    $sig = qp_hash_token_sig($tokenRow['token_id'], $tokenRow['user_id'], $tokenRow['expires']);
    $_COOKIE[QP_SESSION_COOKIE] = $tokenRow['token_id'] . '|' . $tokenRow['user_id'] . '|' . $tokenRow['expires'] . '|' . $sig;

    // Optionally prime a simple global cache used in some code paths
    if (function_exists('get_user_by_id')) {
        $u = get_user_by_id($user_id);
        if ($u) $GLOBALS['qp_current_user'] = $u;
    }

    return $tokenRow;
}

/**
 * Retrieve users matching simple criteria.
 * Args supported:
 * - 'meta_key' (string) and 'meta_value' (string) to filter by a single user_meta pair
 * - 'number' (int) limit
 * - 'offset' (int) offset
 * - 'orderby' (id|username|email) default 'id'
 * - 'order' (ASC|DESC) default 'DESC'
 * Returns array of WP-like formatted users (see format_user_row).
 */
function get_users(array $args = []): array {
    global $pdo;
    $meta_key = isset($args['meta_key']) ? (string)$args['meta_key'] : null;
    $meta_value = array_key_exists('meta_value', $args) ? (string)$args['meta_value'] : null;
    $number = isset($args['number']) ? (int)$args['number'] : 0;
    $offset = isset($args['offset']) ? (int)$args['offset'] : 0;
    $orderby = strtolower((string)($args['orderby'] ?? 'id'));
    $order = strtoupper((string)($args['order'] ?? 'DESC'));
    if ($order !== 'ASC') $order = 'DESC';

    $orderMap = ['id' => 'u.id', 'username' => 'u.username', 'email' => 'u.email'];
    $orderBySql = $orderMap[$orderby] ?? $orderMap['id'];

    if ($meta_key !== null && $meta_value !== null) {
        $sql = "SELECT DISTINCT u.* FROM " . table_name('users') . " u JOIN " . table_name('user_meta') . " m ON m.user_id = u.id AND m.meta_key = ? AND m.meta_value = ? ORDER BY {$orderBySql} {$order}";
        if ($number > 0) $sql .= " LIMIT " . (int)$number . " OFFSET " . (int)$offset;
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$meta_key, $meta_value]);
    } else {
        $sql = "SELECT u.* FROM " . table_name('users') . " u ORDER BY {$orderBySql} {$order}";
        if ($number > 0) $sql .= " LIMIT " . (int)$number . " OFFSET " . (int)$offset;
        $stmt = $pdo->prepare($sql);
        $stmt->execute();
    }
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $out = [];
    foreach ($rows as $r) {
        $out[] = format_user_row($r);
    }
    return $out;
}

/**
 * Minimal error container similar to WP_Error for Qlopy.
 */
class QP_Error {
    protected $errors = [];
    protected $error_data = [];

    public function __construct($code = '', $message = '', $data = null) {
        if ($code !== '') $this->add($code, $message, $data);
    }

    public function add($code, $message = '', $data = null) {
        if (!isset($this->errors[$code])) $this->errors[$code] = [];
        $this->errors[$code][] = $message;
        if ($data !== null) $this->error_data[$code] = $data;
    }

    public function get_error_codes() {
        return array_keys($this->errors);
    }

    public function get_error_code() {
        $keys = $this->get_error_codes();
        return $keys[0] ?? '';
    }

    public function get_error_messages($code = '') {
        if ($code === '') {
            $out = [];
            foreach ($this->errors as $c => $msgs) $out = array_merge($out, $msgs);
            return $out;
        }
        return $this->errors[$code] ?? [];
    }

    public function get_error_message($code = '') {
        $msgs = $this->get_error_messages($code);
        return $msgs[0] ?? '';
    }

    public function get_error_data($code = '') {
        if ($code === '') $code = $this->get_error_code();
        return $this->error_data[$code] ?? null;
    }

    public function has_errors() {
        return !empty($this->errors);
    }
}

/**
 * Check whether a variable is a QP_Error (or WP_Error if present).
 */
function is_qp_error($thing) {
    return ($thing instanceof QP_Error);
}

/**
 * Qlopy-compatible wp_insert_user replacement.
 * Accepts a WP-style $userdata array and returns inserted user ID (int)
 * or a QP_Error instance on failure.
 */
function qp_insert_user($userdata) {
    global $pdo;
    if (!is_array($userdata)) {
        $err = new QP_Error('invalid_userdata', 'User data must be an array');
        return $err;
    }

    $user_login = trim($userdata['user_login'] ?? '');
    $user_email = trim($userdata['user_email'] ?? '');
    $user_pass = $userdata['user_pass'] ?? null;
    $role = $userdata['role'] ?? 'subscriber';

    if ($user_login === '' && $user_email === '') {
        $e = new QP_Error('empty_user_login', 'A username or email address is required');
        return $e;
    }

    // Derive username from email if not provided
    if ($user_login === '' && $user_email !== '') {
        $parts = explode('@', $user_email);
        $user_login = $parts[0];
    }

    // Check existing username or email
    if (function_exists('get_user_by') && get_user_by('login', $user_login)) {
        $e = new QP_Error('existing_user_login', 'Username already exists');
        return $e;
    }
    if ($user_email !== '' && function_exists('get_user_by') && get_user_by('email', $user_email)) {
        $e = new QP_Error('existing_user_email', 'Email already exists');
        return $e;
    }

    // Ensure we have a password
    if ($user_pass === null) {
        // generate a random password when none provided
        $user_pass = bin2hex(random_bytes(8));
    }

    $hash = password_hash((string)$user_pass, PASSWORD_DEFAULT);

    try {
        $stmt = $pdo->prepare("INSERT INTO " . table_name('users') . " (username, email, password_hash) VALUES (?, ?, ?)");
        $ok = $stmt->execute([$user_login, $user_email, $hash]);
        if (!$ok) {
            $e = new QP_Error('db_insert_failed', 'Failed to insert user');
            return $e;
        }
        $user_id = (int)$pdo->lastInsertId();

        // store role and capabilities
        if (function_exists('update_user_meta')) update_user_meta($user_id, 'role', $role);
        if (function_exists('get_role_capabilities') && function_exists('assign_capabilities_to_user')) {
            $caps = get_role_capabilities($role);
            assign_capabilities_to_user($user_id, $caps);
        }

        return $user_id;
    } catch (Throwable $e) {
        $err = new QP_Error('exception', $e->getMessage());
        return $err;
    }
}

/**
 * Qlopy-compatible wp_update_user replacement.
 * Accepts a WP-style $userdata array with an 'ID' key and updates provided fields.
 * Returns the user ID on success or a QP_Error on failure.
 */
function qp_update_user($userdata) {
    global $pdo;
    if (!is_array($userdata) && !is_object($userdata)) {
        return new QP_Error('invalid_userdata', 'User data must be an array or object');
    }
    $data = (array)$userdata;
    $id = isset($data['ID']) ? (int)$data['ID'] : (isset($data['id']) ? (int)$data['id'] : 0);
    if ($id <= 0) {
        return new QP_Error('invalid_user_id', 'Valid user ID is required');
    }

    // Fetch existing user
    $existing = get_user_by('id', $id);
    if (!$existing) return new QP_Error('no_user', 'User not found');
    $raw = is_array($existing['raw'] ?? null) ? $existing['raw'] : $existing;

    $fieldsToUpdate = [];
    $params = [];

    // Allowed top-level user fields in DB: username, email, password_hash
    if (isset($data['user_login'])) {
        $newLogin = trim((string)$data['user_login']);
        if ($newLogin !== '' && $newLogin !== ($raw['username'] ?? '')) {
            // ensure uniqueness
            $other = get_user_by('login', $newLogin);
            if ($other && ((int)($other['raw']['id'] ?? 0) !== $id)) {
                return new QP_Error('existing_user_login', 'Username already exists');
            }
            $fieldsToUpdate[] = 'username = ?';
            $params[] = $newLogin;
        }
    }
    if (isset($data['user_email'])) {
        $newEmail = trim((string)$data['user_email']);
        if ($newEmail !== '' && $newEmail !== ($raw['email'] ?? '')) {
            $other = get_user_by('email', $newEmail);
            if ($other && ((int)($other['raw']['id'] ?? 0) !== $id)) {
                return new QP_Error('existing_user_email', 'Email already exists');
            }
            $fieldsToUpdate[] = 'email = ?';
            $params[] = $newEmail;
        }
    }
    if (array_key_exists('user_pass', $data)) {
        // Allow null to mean leave unchanged; explicit null will be ignored
        if ($data['user_pass'] !== null) {
            $hash = password_hash((string)$data['user_pass'], PASSWORD_DEFAULT);
            $fieldsToUpdate[] = 'password_hash = ?';
            $params[] = $hash;
        }
    }

    try {
        if (!empty($fieldsToUpdate)) {
            $sql = "UPDATE " . table_name('users') . " SET " . implode(', ', $fieldsToUpdate) . " WHERE id = ?";
            $params[] = $id;
            $stmt = $pdo->prepare($sql);
            $ok = $stmt->execute($params);
            if (!$ok) return new QP_Error('db_update_failed', 'Failed to update user');
        }

        // Update meta-like fields via update_user_meta
        $metaFields = ['first_name','last_name','display_name','nicename','role','capabilities','billing_phone'];
        foreach ($metaFields as $m) {
            if (array_key_exists($m, $data)) {
                update_user_meta($id, $m, $data[$m]);
            }
        }

        // If role changed, reassign capabilities
        if (isset($data['role'])) {
            $role = (string)$data['role'];
            $caps = get_role_capabilities($role);
            assign_capabilities_to_user($id, $caps);
        }

        return $id;
    } catch (Throwable $e) {
        return new QP_Error('exception', $e->getMessage());
    }
}
function qp_current_user_role(): ?string {
    $uid = function_exists('qp_current_user_id') ? (int) qp_current_user_id() : 0;
    if ($uid <= 0) return null;

    // Try common helpers / shapes returned by core
    if (function_exists('get_user_by')) {
        $user = get_user_by('id', $uid);
        if (is_array($user)) {
            if (!empty($user['role'])) return (string)$user['role'];
            if (!empty($user['roles']) && is_array($user['roles'])) return implode(',', $user['roles']);
            $raw = $user['raw'] ?? $user;
            if (is_array($raw)) {
                if (!empty($raw['role'])) return (string)$raw['role'];
                if (!empty($raw['roles']) && is_array($raw['roles'])) return implode(',', $raw['roles']);
            }
        }
    }

    // Fallback to meta
    if (function_exists('get_user_meta')) {
        $meta = get_user_meta($uid, 'role');
        if (!empty($meta)) return is_array($meta) ? (string)$meta[0] : (string)$meta;
        $meta2 = get_user_meta($uid, 'roles');
        if (!empty($meta2)) return is_array($meta2) ? implode(',', $meta2) : (string)$meta2;
    }

    return null;
}

/**
 * Compatibility wrappers around core capability helpers.
 * Prefer core functions when available; fall back to direct user_meta operations.
 */
if (!function_exists('qp_get_user_caps')) {
    function qp_get_user_caps(int $user_id): array {
        if ($user_id <= 0) return [];
        // Prefer formatted user (includes merged role caps)
        if (function_exists('get_user_by')) {
            $u = get_user_by('id', $user_id);
            if (is_array($u) && !empty($u['capabilities'])) {
                return is_array($u['capabilities']) ? $u['capabilities'] : (array)$u['capabilities'];
            }
        }
        if (function_exists('get_user_meta')) {
            $raw = get_user_meta($user_id, 'capabilities');
            if ($raw === null || $raw === '') return [];
            if (is_array($raw)) return $raw;
            // try json encoded
            $decoded = json_decode((string)$raw, true);
            if (is_array($decoded)) return $decoded;
            // fallback comma-separated
            return array_values(array_filter(array_map('trim', explode(',', (string)$raw))));
        }
        return [];
    }
}

if (!function_exists('qp_set_user_caps')) {
    function qp_set_user_caps(int $user_id, array $caps): bool {
        if ($user_id <= 0) return false;
        $caps = array_values(array_unique(array_filter($caps, 'strlen')));
        // Prefer core assign helper
        if (function_exists('assign_capabilities_to_user')) {
            try { assign_capabilities_to_user($user_id, $caps); return true; } catch (Throwable $_) {}
        }
        if (function_exists('update_user_meta')) {
            return (bool) update_user_meta($user_id, 'capabilities', $caps);
        }
        // Fallback direct DB upsert
        try {
            $pdo = db(); $table = table_name('user_meta');
            $json = json_encode($caps);
            $del = $pdo->prepare("DELETE FROM {$table} WHERE user_id = ? AND meta_key = 'capabilities'");
            $del->execute([$user_id]);
            $ins = $pdo->prepare("INSERT INTO {$table} (user_id, meta_key, meta_value) VALUES (?, 'capabilities', ?)");
            return (bool)$ins->execute([$user_id, $json]);
        } catch (Throwable $_e) { return false; }
    }
}

if (!function_exists('qp_add_capability')) {
    function qp_add_capability(int $user_id, string $cap): bool {
        if ($user_id <= 0 || !strlen(trim($cap))) return false;
        // Prefer core adder
        if (function_exists('assign_new_capabilities_to_user')) {
            return (bool) assign_new_capabilities_to_user($user_id, $cap);
        }
        $caps = qp_get_user_caps($user_id);
        if (in_array($cap, $caps, true)) return true;
        $caps[] = $cap;
        return qp_set_user_caps($user_id, $caps);
    }
}

if (!function_exists('qp_remove_capability')) {
    function qp_remove_capability(int $user_id, string $cap): bool {
        if ($user_id <= 0 || !strlen(trim($cap))) return false;
        $caps = qp_get_user_caps($user_id);
        $new = array_values(array_diff($caps, [$cap]));
        return qp_set_user_caps($user_id, $new);
    }
}

if (!function_exists('qp_user_has_cap')) {
 function qp_user_has_cap(string $cap, ?int $user_id = null): bool {
    if (!$cap) return false;
    if ($user_id === null) {
        if (function_exists('check_permission')) return check_permission($cap);
        $user_id = function_exists('qp_current_user_id') ? (int) qp_current_user_id() : 0;
    }
    if ($user_id <= 0) return false;
    if (function_exists('get_user_by')) {
        $u = get_user_by('id', $user_id);
        if (is_array($u) && !empty($u['capabilities'])) {
            return in_array($cap, (array)$u['capabilities'], true);
        }
    }
    $caps = get_user_meta($user_id, 'capabilities') ?: [];
    if (!is_array($caps)) $caps = (array)$caps;
    $role = get_user_meta($user_id, 'role') ?: '';
    if ($role) {
        $role_caps = get_role_capabilities($role);
        $caps = array_values(array_unique(array_merge($role_caps, $caps)));
    }
    return in_array($cap, $caps, true);
}
}

function qlopy_register_role_from(string $sourceRole, string $newRole, array $additionalCaps = []): array {
    if (!function_exists('get_role_capabilities') || !function_exists('register_role')) {
        return ['success' => false, 'message' => 'core role helpers missing'];
    }
    $srcCaps = get_role_capabilities($sourceRole) ?: [];
    $merged = array_values(array_unique(array_filter(array_merge($srcCaps, $additionalCaps), 'strlen')));
    register_role($newRole, $merged);
    return ['success' => true, 'registered_caps' => $merged];
}

/**
 * Delete a single attachment by ID.
 * Removes physical files referenced in attachment metadata, clears related post meta
 * and deletes the attachment post row. Clears per-request attachment cache.
 *
 * @param int $post_id Attachment post ID
 * @return bool True on success (or when nothing to delete), false on error
 */
function qp_delete_attachment(int $post_id): bool {
    $post_id = (int)$post_id;
    if ($post_id <= 0) return false;

    // Load metadata (may be null if already partially removed)
    $meta = function_exists('qp_get_attachment_metadata') ? qp_get_attachment_metadata($post_id) : null;

    // Delete physical files if metadata present
    // Gather paths from metadata (support 'path', 'path_full', and legacy 'url' entries)
    $paths = [];
    if (is_array($meta) && !empty($meta['file'])) {
        if (!empty($meta['file']['path'])) $paths[] = $meta['file']['path'];
        if (!empty($meta['file']['path_full'])) $paths[] = $meta['file']['path_full'];
        if (!empty($meta['file']['url'])) $paths[] = $meta['file']['url'];

        if (!empty($meta['sizes']) && is_array($meta['sizes'])) {
            foreach ($meta['sizes'] as $sz) {
                if (!empty($sz['path'])) $paths[] = $sz['path'];
                if (!empty($sz['path_full'])) $paths[] = $sz['path_full'];
                if (!empty($sz['url'])) $paths[] = $sz['url'];
            }
        }
    }

    // Always attempt to derive main path via helper (covers cases where metadata lacks path)
    $mainPath = null;
    if (function_exists('qp_get_attachment_path')) $mainPath = qp_get_attachment_path($post_id);
    if (!$mainPath && is_array($meta) && !empty($meta['file']['path'])) $mainPath = $meta['file']['path'];
    if (!$mainPath && is_array($meta) && !empty($meta['file']['url'])) {
        $u = $meta['file']['url'];
        if (preg_match('#^https?://#i', $u) && function_exists('qp_map_uploads_url_to_path')) {
            $mapped = qp_map_uploads_url_to_path($u);
            if ($mapped) $mainPath = $mapped;
        } else {
            // relative url -> map to uploads base
            if (function_exists('qp_uploads_base')) {
                $base = rtrim(qp_uploads_base(), DIRECTORY_SEPARATOR);
                $cand = $base . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, ltrim($u, '/\\'));
                if (file_exists($cand)) $mainPath = $cand;
            }
        }
    }

    // Normalize and unlink explicit paths/urls discovered in metadata
    foreach ($paths as $p) {
        if (!is_string($p) || $p === '') continue;
        // If looks like URL, map to local path when possible
        if (preg_match('#^https?://#i', $p) && function_exists('qp_map_uploads_url_to_path')) {
            $mapped = qp_map_uploads_url_to_path($p);
            if ($mapped) $p = $mapped;
        } elseif (!preg_match('#^([a-zA-Z]:)?[\\/]#', $p) && strpos($p, '://') === false) {
            // not absolute path and not full URL -> treat as relative uploads url
            if (function_exists('qp_uploads_base')) {
                $cand = rtrim(qp_uploads_base(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, ltrim($p, '/\\'));
                $p = $cand;
            }
        }
        // Normalize slashes
        $p = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $p);
        if (file_exists($p) && is_file($p)) {
            @unlink($p);
        }
    }

    // If mainPath is known, attempt to remove generated derivative images using glob
    if ($mainPath && is_file($mainPath)) {
        $img_exts = ['jpg','jpeg','png','gif','webp','bmp','tiff','svg'];
        $dir = dirname($mainPath);
        $baseName = pathinfo($mainPath, PATHINFO_FILENAME);
        foreach ($img_exts as $extItem) {
            $pattern = $dir . DIRECTORY_SEPARATOR . $baseName . '-*.' . $extItem;
            foreach (glob($pattern) as $cand) {
                if (is_file($cand)) @unlink($cand);
            }
        }
    }

    // Remove DB records related to this attachment
    try {
        $pdo = db();
        if ($pdo->inTransaction() === false) $pdo->beginTransaction();

        // post_meta cleanup
        $stmt = $pdo->prepare('DELETE FROM ' . table_name('post_meta') . ' WHERE post_id = ?');
        $stmt->execute([$post_id]);

        // post_terms cleanup (if any)
        $stmt = $pdo->prepare('DELETE FROM ' . table_name('post_terms') . ' WHERE post_id = ?');
        $stmt->execute([$post_id]);

        // delete posts row for attachment
        $stmt = $pdo->prepare('DELETE FROM ' . table_name('posts') . ' WHERE id = ? AND post_type = ?');
        $stmt->execute([$post_id, 'attachment']);

        $pdo->commit();
    } catch (Throwable $e) {
        try { if ($pdo && $pdo->inTransaction()) $pdo->rollBack(); } catch (Throwable $_) {}
        return false;
    }

    // Clear in-memory caches
    if (function_exists('qp_clear_attachment_cache')) qp_clear_attachment_cache($post_id);

    // Allow plugins/themes to react
    if (function_exists('do_action')) do_action('qp_delete_attachment', $post_id);

    return true;
}


/**
 * Return users having the given role.
 * Returns an array of associative rows with keys: id, display_name, user_login, nicename, user_email
 */
/**
 * Get users matching one or more roles.
 * $roles may be a string (single role or comma-separated) or an array of roles.
 * Matches if user's 'role' meta equals any of the provided roles or if their 'capabilities' meta contains any.
 */
function qp_get_users_by_role($roles): array {
    // normalize to array of trimmed strings
    if (is_string($roles)) {
        $r = trim($roles);
        if ($r === '') return [];
        if (strpos($r, ',') !== false) {
            $rolesArr = array_map('trim', array_filter(array_map('trim', explode(',', $r)), fn($v) => $v !== ''));
        } else {
            $rolesArr = [$r];
        }
    } elseif (is_array($roles)) {
        $rolesArr = array_values(array_filter(array_map('trim', $roles), fn($v) => $v !== ''));
        if (empty($rolesArr)) return [];
    } else {
        return [];
    }

    try {
        $pdo = db();
        // First, try a direct join on user_meta where meta_key = 'role'
        // build placeholders for IN()
        $placeholders = implode(',', array_fill(0, count($rolesArr), '?'));
        $sql = 'SELECT u.id, u.username AS user_login, u.email AS user_email'
            . ' FROM ' . table_name('users') . ' u'
            . ' JOIN ' . table_name('user_meta') . " m ON m.user_id = u.id AND m.meta_key = 'role'"
            . ' WHERE m.meta_value IN (' . $placeholders . ') ORDER BY u.username ASC';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($rolesArr);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $out = [];
        foreach ($rows as $r) {
            $uid = (int)($r['id'] ?? 0);
            $u = null;
            if (function_exists('get_user_by')) {
                $u = get_user_by('id', $uid);
            }
            $out[] = [
                'id' => $uid,
                'display_name' => (string)($u['display_name'] ?? ($r['user_login'] ?? '')),
                'user_login' => (string)($r['user_login'] ?? ''),
                'nicename' => (string)($u['nicename'] ?? ($r['user_login'] ?? '')),
                'user_email' => (string)($r['user_email'] ?? ''),
            ];
        }

        // If none found via 'role' meta, fall back to scanning capabilities meta for role-like entries
        if (empty($out)) {
            $stmt2 = $pdo->query('SELECT id FROM ' . table_name('users'));
            $uids = $stmt2->fetchAll(PDO::FETCH_COLUMN);
            foreach ($uids as $uidRaw) {
                $uid = (int)$uidRaw;
                if ($uid <= 0) continue;
                $meta = get_user_meta($uid, 'capabilities');
                if (empty($meta)) continue;
                $caps = $meta;
                if (is_string($caps)) {
                    $dec = @json_decode($caps, true);
                    if (is_array($dec)) $caps = $dec;
                    elseif (strpos($caps, ',') !== false) $caps = array_map('trim', explode(',', $caps));
                    else $caps = [$caps];
                }
                if (!is_array($caps)) $caps = (array)$caps;
                foreach ($caps as $c) {
                    foreach ($rolesArr as $roleCandidate) {
                        if ((string)$c === (string)$roleCandidate) {
                            $u = get_user_by('id', $uid);
                            if ($u) {
                                $out[] = [
                                    'id' => $uid,
                                    'display_name' => (string)($u['display_name'] ?? ($u['user_login'] ?? '')),
                                    'user_login' => (string)($u['user_login'] ?? ''),
                                    'nicename' => (string)($u['nicename'] ?? ''),
                                    'user_email' => (string)($u['user_email'] ?? ''),
                                ];
                            }
                            break 2;
                        }
                    }
                }
            }
        }

        return $out;
    } catch (Throwable $e) {
        return [];
    }
}


function qp_current_user_has_any_role($roles): bool {
    $cur = function_exists('qp_current_user_id') ? (int) qp_current_user_id() : 0;
    if ($cur <= 0) return false;
    $users = qp_get_users_by_role($roles);
    if (empty($users)) return false;
    $ids = array_map('intval', array_column($users, 'id'));
    return in_array($cur, $ids, true);
}

function qp_get_current_user_role(): string {
    $uid = function_exists('qp_current_user_id') ? (int) qp_current_user_id() : 0;
    if ($uid <= 0) return '';

    $role = get_user_meta($uid, 'role');
    if (is_array($role)) $role = $role[0] ?? '';
    $role = trim((string)$role);
    if ($role !== '') return $role;

    $caps = get_user_meta($uid, 'capabilities');
    if (is_string($caps)) {
        $dec = @json_decode($caps, true);
        if (is_array($dec)) $caps = $dec;
        elseif (strpos($caps, ',') !== false) $caps = array_map('trim', explode(',', $caps));
        else $caps = [$caps];
    }
    if (is_array($caps)) {
        foreach ($caps as $k => $v) {
            if (is_string($k) && $v) return (string)$k;
        }
        if (isset($caps[0]) && is_string($caps[0])) return (string)$caps[0];
    }

    return '';
}


// Qlopy replacement for wp_remote_get with curl -> streams fallback.
// Returns WP-like response array on success or array with 'error' key on failure.
function qp_remote_get(string $url, array $args = []): array {
    $defaults = [
        'timeout'   => 5,
        'headers'   => [],
        'sslverify' => true,
        'user-agent'=> 'qlopy-remote/1.0',
    ];
    $opts = array_merge($defaults, $args);

    // Normalize headers
    $headers = [];
    foreach ($opts['headers'] as $k => $v) {
        if (is_int($k)) {
            $parts = explode(':', $v, 2);
            if (count($parts) === 2) $headers[trim($parts[0])] = trim($parts[1]);
        } else {
            $headers[$k] = $v;
        }
    }
    if (!isset($headers['User-Agent'])) $headers['User-Agent'] = $opts['user-agent'];

    $build_response = function(string $body, array $raw_headers, int $code, string $message = ''): array {
        $assoc = [];
        foreach ($raw_headers as $line) {
            if (strpos($line, ':') !== false) {
                [$k, $v] = explode(':', $line, 2);
                $assoc[trim($k)] = trim($v);
            }
        }
        return [
            'headers'       => $assoc,
            'body'          => $body,
            'response'      => ['code' => $code, 'message' => $message],
            'cookies'       => [],
            'http_response' => null,
        ];
    };

    // Try cURL first
    if (function_exists('curl_init')) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, (int)$opts['timeout']);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, (bool)$opts['sslverify']);
        curl_setopt($ch, CURLOPT_USERAGENT, $headers['User-Agent']);
        $curl_headers = [];
        foreach ($headers as $k => $v) {
            if ($k === 'User-Agent') continue;
            $curl_headers[] = $k . ': ' . $v;
        }
        if (!empty($curl_headers)) curl_setopt($ch, CURLOPT_HTTPHEADER, $curl_headers);

        // Capture headers and body together
        curl_setopt($ch, CURLOPT_HEADER, true);
        $full = curl_exec($ch);
        if ($full === false) {
            $errno = curl_errno($ch);
            $errmsg = curl_error($ch);
            curl_close($ch);
            return ['error' => ['code' => $errno, 'message' => $errmsg ?: 'cURL error']];
        }
        $info = curl_getinfo($ch);
        $header_size = isset($info['header_size']) ? (int)$info['header_size'] : 0;
        $http_code = (int)($info['http_code'] ?? 0);
        $header_text = $header_size ? substr($full, 0, $header_size) : '';
        $body = $header_size ? substr($full, $header_size) : $full;
        $header_lines = $header_text !== '' ? preg_split("/\r\n|\n|\r/", trim($header_text)) : [];

        curl_close($ch);
        return $build_response((string)$body, $header_lines ?: [], $http_code, '');
    }

    // Fallback: file_get_contents with stream context
    $http_opts = [
        'method'  => 'GET',
        'header'  => '',
        'timeout' => (int)$opts['timeout'],
        'user_agent' => $headers['User-Agent'],
    ];
    $hdrs = [];
    foreach ($headers as $k => $v) {
        if ($k === 'User-Agent') continue;
        $hdrs[] = $k . ': ' . $v;
    }
    if (!empty($hdrs)) $http_opts['header'] = implode("\r\n", $hdrs);

    $ctx_opts = ['http' => $http_opts];
    if (stripos($url, 'https://') === 0) {
        $ctx_opts['ssl'] = ['verify_peer' => (bool)$opts['sslverify'], 'verify_peer_name' => (bool)$opts['sslverify']];
    }
    $ctx = stream_context_create($ctx_opts);

    set_error_handler(function() {}, E_WARNING);
    $body = @file_get_contents($url, false, $ctx);
    restore_error_handler();

    if ($body === false) {
        $err = error_get_last();
        $msg = $err['message'] ?? 'HTTP request failed';
        return ['error' => ['code' => 0, 'message' => $msg]];
    }

    $raw_headers = $http_response_header ?? [];
    $code = 0; $message = '';
    if (!empty($raw_headers)) {
        foreach ($raw_headers as $line) {
            if (preg_match('#HTTP/\d+\.\d+\s+(\d+)\s*(.*)#i', $line, $m)) {
                $code = (int)$m[1];
                $message = trim($m[2]);
                break;
            }
        }
    }

    return $build_response((string)$body, $raw_headers, $code, $message);
}

// Compatibility helpers
function qp_is_error($val): bool {
    return is_array($val) && isset($val['error']);
}
function qp_remote_retrieve_body($response): string {
    if (is_array($response) && isset($response['body'])) return (string)$response['body'];
    return '';
}
function qp_remote_retrieve_response_message($response): string {
    if (is_array($response) && isset($response['response']['message'])) return (string)$response['response']['message'];
    return '';
}
function qp_remote_retrieve_response_code($response): int {
    if (is_array($response) && isset($response['response']['code'])) return (int)$response['response']['code'];
    return 0;
}

/**
 * Perform an HTTP request with arbitrary method. Returns WP-like response array.
 * Preferred helper for POST/PUT/etc. Signatures mirror qp_remote_get style.
 */
function qp_remote_request(string $method, string $url, array $args = []): array {
    $method = strtoupper($method);
    $defaults = [
        'timeout'   => 5,
        'headers'   => [],
        'sslverify' => true,
        'user-agent'=> 'qlopy-remote/1.0',
        'body'      => null,
    ];
    $opts = array_merge($defaults, $args);

    // Normalize headers
    $headers = [];
    foreach ($opts['headers'] as $k => $v) {
        if (is_int($k)) {
            $parts = explode(':', $v, 2);
            if (count($parts) === 2) $headers[trim($parts[0])] = trim($parts[1]);
        } else {
            $headers[$k] = $v;
        }
    }
    if (!isset($headers['User-Agent'])) $headers['User-Agent'] = $opts['user-agent'];

    $build_response = function(string $body, array $raw_headers, int $code, string $message = '') use ($headers) {
        $assoc = [];
        foreach ($raw_headers as $line) {
            if (strpos($line, ':') !== false) {
                [$k, $v] = explode(':', $line, 2);
                $assoc[trim($k)] = trim($v);
            }
        }
        return [
            'headers'       => $assoc,
            'body'          => $body,
            'response'      => ['code' => $code, 'message' => $message],
            'cookies'       => [],
            'http_response' => null,
        ];
    };

    // Prepare body
    $body = $opts['body'];
    $isAssoc = is_array($body);
    if ($isAssoc && !isset($headers['Content-Type'])) {
        // default to form-encoded for arrays
        $headers['Content-Type'] = 'application/x-www-form-urlencoded';
    }
    if ($isAssoc && isset($headers['Content-Type']) && stripos($headers['Content-Type'], 'application/x-www-form-urlencoded') !== false) {
        $bodyStr = http_build_query($body);
    } elseif (is_string($body)) {
        $bodyStr = $body;
    } else {
        $bodyStr = $body !== null ? json_encode($body) : '';
        if ($body !== null && !isset($headers['Content-Type'])) $headers['Content-Type'] = 'application/json';
    }

    // Try cURL
    if (function_exists('curl_init')) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, (int)$opts['timeout']);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, (bool)$opts['sslverify']);
        curl_setopt($ch, CURLOPT_USERAGENT, $headers['User-Agent']);
        $curl_headers = [];
        foreach ($headers as $k => $v) {
            if ($k === 'User-Agent') continue;
            $curl_headers[] = $k . ': ' . $v;
        }
        if (!empty($curl_headers)) curl_setopt($ch, CURLOPT_HTTPHEADER, $curl_headers);
        if ($method !== 'GET') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $bodyStr);
        }
        curl_setopt($ch, CURLOPT_HEADER, true);
        $full = curl_exec($ch);
        if ($full === false) {
            $errno = curl_errno($ch);
            $errmsg = curl_error($ch);
            curl_close($ch);
            return ['error' => ['code' => $errno, 'message' => $errmsg ?: 'cURL error']];
        }
        $info = curl_getinfo($ch);
        $header_size = isset($info['header_size']) ? (int)$info['header_size'] : 0;
        $http_code = (int)($info['http_code'] ?? 0);
        $header_text = $header_size ? substr($full, 0, $header_size) : '';
        $body = $header_size ? substr($full, $header_size) : $full;
        $header_lines = $header_text !== '' ? preg_split("/\r\n|\n|\r/", trim($header_text)) : [];
        curl_close($ch);
        return $build_response((string)$body, $header_lines ?: [], $http_code, '');
    }

    // Fallback to stream context
    $http_opts = [
        'method'  => $method,
        'header'  => '',
        'timeout' => (int)$opts['timeout'],
        'user_agent' => $headers['User-Agent'],
        'content' => $bodyStr,
    ];
    $hdrs = [];
    foreach ($headers as $k => $v) {
        if ($k === 'User-Agent') continue;
        $hdrs[] = $k . ': ' . $v;
    }
    if (!empty($hdrs)) $http_opts['header'] = implode("\r\n", $hdrs);
    $ctx_opts = ['http' => $http_opts];
    if (stripos($url, 'https://') === 0) {
        $ctx_opts['ssl'] = ['verify_peer' => (bool)$opts['sslverify'], 'verify_peer_name' => (bool)$opts['sslverify']];
    }
    $ctx = stream_context_create($ctx_opts);
    set_error_handler(function() {}, E_WARNING);
    $body = @file_get_contents($url, false, $ctx);
    restore_error_handler();
    if ($body === false) {
        $err = error_get_last();
        $msg = $err['message'] ?? 'HTTP request failed';
        return ['error' => ['code' => 0, 'message' => $msg]];
    }
    $raw_headers = $http_response_header ?? [];
    $code = 0; $message = '';
    if (!empty($raw_headers)) {
        foreach ($raw_headers as $line) {
            if (preg_match('#HTTP/\d+\.\d+\s+(\d+)\s*(.*)#i', $line, $m)) {
                $code = (int)$m[1];
                $message = trim($m[2]);
                break;
            }
        }
    }
    return $build_response((string)$body, $raw_headers, $code, $message);
}

function qp_remote_post(string $url, array $args = []): array {
    return qp_remote_request('POST', $url, $args);
}

/**
 * Frontend CSRF nonce refresh API
 *
 * This opt-in feature lets long-running authenticated frontend forms replace a
 * nearing-expiry session-bound nonce without reloading the page. It does not
 * grant permission to perform the underlying action: each AJAX/form handler
 * must still enforce its own capability and resource-ownership checks.
 *
 * Register only actions that are safe to mint for the active user session:
 *
 *     qp_register_nonce_refresh_action('add_post', 7200);
 *     qp_register_nonce_refresh_action('profile_update', 3600);
 *     qp_register_nonce_refresh_action('change_password', 900);
 *
 * The browser then sends an authenticated same-origin POST request to:
 *
 *     /ajax.php?action=qp_refresh_nonce
 *
 * with these form values/headers:
 *
 *     nonce_action=add_post
 *     X-QP-Nonce-Refresh: 1
 *
 * A matching form can use the optional qp-nonce-refresh.js helper:
 *
 *     <form data-qp-nonce-action="add_post" data-qp-nonce-ttl="7200">
 *       <input type="hidden" name="nonce" data-qp-nonce
 *              value="<?= htmlspecialchars(qp_create_nonce('add_post'), ENT_QUOTES) ?>">
 *     </form>
 *
 * The endpoint accepts only registered actions and uses the server-registered
 * TTL; clients cannot choose either value. It also requires a valid Qlopy
 * login session, POST, the explicit refresh header, and a same-origin Origin.
 */
function qp_register_nonce_refresh_action(string $action, int $ttl = 7200): bool {
    if (preg_match('/^[A-Za-z0-9:_-]{1,128}$/', $action) !== 1 || $ttl <= 0) {
        return false;
    }
    if (!isset($GLOBALS['qp_nonce_refresh_actions']) || !is_array($GLOBALS['qp_nonce_refresh_actions'])) {
        $GLOBALS['qp_nonce_refresh_actions'] = [];
    }
    $GLOBALS['qp_nonce_refresh_actions'][$action] = $ttl;
    return true;
}

function qp_nonce_refresh_is_same_origin_request(): bool {
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if (!is_string($origin) || $origin === '') return false;

    $cfg = function_exists('get_config')
        ? get_config()
        : (is_file(__DIR__ . '/config.php') ? require __DIR__ . '/config.php' : []);
    $site_url = $cfg['site_url'] ?? '';
    $expected = is_string($site_url) ? parse_url($site_url) : false;
    $actual = parse_url($origin);
    if ($expected === false || $actual === false
        || empty($expected['scheme']) || empty($expected['host'])
        || empty($actual['scheme']) || empty($actual['host'])) {
        return false;
    }

    $expected_scheme = strtolower($expected['scheme']);
    $actual_scheme = strtolower($actual['scheme']);
    $expected_host = strtolower($expected['host']);
    $actual_host = strtolower($actual['host']);
    $expected_port = (int)($expected['port'] ?? ($expected_scheme === 'https' ? 443 : 80));
    $actual_port = (int)($actual['port'] ?? ($actual_scheme === 'https' ? 443 : 80));
    if ($expected_scheme !== $actual_scheme || $expected_host !== $actual_host || $expected_port !== $actual_port) {
        return false;
    }

    $fetch_site = $_SERVER['HTTP_SEC_FETCH_SITE'] ?? '';
    return $fetch_site === '' || $fetch_site === 'same-origin';
}

function qp_handle_nonce_refresh_request(array $request): void {
    header('Cache-Control: no-store, private');
    header('Pragma: no-cache');

    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST'
        || ($_SERVER['HTTP_X_QP_NONCE_REFRESH'] ?? '') !== '1'
        || !qp_nonce_refresh_is_same_origin_request()) {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'Invalid nonce refresh request.']);
        return;
    }
    if (!is_logged_in()) {
        http_response_code(401);
        echo json_encode(['status' => 'error', 'message' => 'Authentication required.']);
        return;
    }

    $action = $request['nonce_action'] ?? '';
    $allowed_actions = $GLOBALS['qp_nonce_refresh_actions'] ?? [];
    if (!is_string($action) || !is_array($allowed_actions) || !isset($allowed_actions[$action])) {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'Nonce action is not refreshable.']);
        return;
    }

    $ttl = (int)$allowed_actions[$action];
    $nonce = qp_create_nonce($action, $ttl);
    if ($nonce === '') {
        http_response_code(500);
        error_log('Unable to issue a Qlopy CSRF nonce refresh token.');
        echo json_encode(['status' => 'error', 'message' => 'Unable to refresh nonce.']);
        return;
    }

    echo json_encode([
        'status' => 'success',
        'nonce' => $nonce,
        'expires_at' => time() + $ttl,
    ]);
}

function qp_register_nonce_refresh_ajax_handler(): void {
    if (function_exists('add_action')) {
        add_action('iitcm_ajax_qp_refresh_nonce', 'qp_handle_nonce_refresh_request');
    }
}