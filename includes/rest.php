<?php
if (!defined('QLOPY_INIT')) { http_response_code(403); exit; }
// includes/rest.php
// REST API core (core upgrade plan C4): route registry, request and response objects, argument validation, the authentication chain,
// CORS, rate limits, ETag, idempotency keys, one error shape, the OpenAPI export. Loaded only for requests under <site>/api/
// (see includes/rest-entry.php); a request for any other URL never reads this file.
//
// For plugins:
//   qp_register_rest_route('myplugin/v1', '/things/(?P<id>\d+)', [
//       'methods' => 'GET',
//       'callback' => function (QP_REST_Request $r) { return ['id' => (int)$r['id']]; },
//       'permission_callback' => 'qp_rest_permit_public',        // REQUIRED. Or fn($r) => $r->can('manage_options')
//       'args' => ['id' => ['type' => 'integer', 'required' => true, 'minimum' => 1]],
//       'scope' => 'things:read',                                 // optional; default is <first path segment>:read|write
//   ]);
//   Register routes on the `qp_rest_init` action (or while the plugin loads). Namespaces look like `vendor/v1`; `qlopy/` is core's.
//   A route without a callable permission_callback is refused (logged, not registered), so an open route can only be open on purpose.
//   Callbacks return an array (200), a QP_REST_Response, or a QP_REST_Error.

const QP_REST_VERSION = '1';
const QP_REST_DEFAULTS_KEY = 'qp_rest_settings';

// ---------------------------------------------------------------- objects

class QP_REST_Error {
    public $code; public $message; public $status; public $fields; public $headers;
    public function __construct(string $code, string $message, int $status = 400, array $fields = [], array $headers = []) {
        $this->code = $code; $this->message = $message; $this->status = $status; $this->fields = $fields; $this->headers = $headers;
    }
}

class QP_REST_Response {
    public $data; public $status; public $headers;
    public function __construct($data = null, int $status = 200, array $headers = []) { $this->data = $data; $this->status = $status; $this->headers = $headers; }
    public function header(string $k, string $v): self { $this->headers[$k] = $v; return $this; }
}

class QP_REST_Request implements ArrayAccess {
    public $method = 'GET'; public $route = ''; public $namespace = ''; public $query = []; public $body = []; public $url_params = [];
    public $headers = []; public $raw_body = ''; public $handler = []; public $params = []; public $files = [];
    public $user_id = 0; public $scopes = []; public $scheme = 'none'; public $key_id = null; public $family = null;
    public function get_param(string $k, $default = null) { return array_key_exists($k, $this->params) ? $this->params[$k] : $default; }
    public function get_params(): array { return $this->params; }
    public function get_header(string $name): ?string { return $this->headers[strtolower($name)] ?? null; }
    public function is_authenticated(): bool { return $this->user_id > 0; }
    /** Does the authenticated user hold this capability? (False for anonymous requests.) Scopes are checked separately by the dispatcher. */
    public function can(string $cap): bool { return $this->user_id > 0 && qp_rest_user_can($this->user_id, $cap); }
    public function offsetExists($o): bool { return isset($this->params[$o]); }
    #[\ReturnTypeWillChange] public function offsetGet($o) { return $this->params[$o] ?? null; }
    public function offsetSet($o, $v): void { $this->params[$o] = $v; }
    public function offsetUnset($o): void { unset($this->params[$o]); }
}

// ---------------------------------------------------------------- settings

function qp_rest_defaults(): array {
    return [
        'keys' => false, 'app_passwords' => false, 'jwt' => false,     // public-API schemes: off until the owner turns them on
        'cors_origins' => [], 'rate_ip' => 600, 'rate_key' => 600, 'max_body' => 1048576,
        'jwt_access_ttl' => 900, 'jwt_refresh_ttl' => 2592000, 'jwt_kid' => 'k1', 'jwt_old_kids' => [],
        'webhooks' => false, 'webhook_allow_private' => false, 'max_upload' => 10485760, 'counter_epoch' => 0, 'oauth' => false,
    ];
}

function qp_rest_settings(bool $reload = false): array {
    static $s = null;
    if ($s === null || $reload) {
        $raw = function_exists('get_option_meta') ? get_option_meta(QP_REST_DEFAULTS_KEY) : null;
        $s = array_merge(qp_rest_defaults(), is_array($raw) ? $raw : []);
    }
    return $s;
}

// ---------------------------------------------------------------- registry

function &qp_rest_routes_ref(): array { static $r = []; return $r; }
function &qp_rest_rejected_ref(): array { static $r = []; return $r; }

/** The permission callback of a route that is open on purpose. */
function qp_rest_permit_public(): bool { return true; }

function qp_register_rest_route(string $namespace, string $route, array $args): bool {
    $namespace = trim($namespace, '/');
    if (!preg_match('~^[a-z0-9_-]+/v[0-9]+$~', $namespace)) { error_log("qp_register_rest_route: bad namespace '$namespace'"); return false; }
    if (strncmp($namespace, 'qlopy/', 6) === 0 && empty($GLOBALS['qp_rest_core_loading'])) { error_log("qp_register_rest_route: namespace '$namespace' belongs to the core"); return false; }
    if ($route === '' || $route[0] !== '/') $route = '/' . $route;
    $handlers = isset($args[0]) && is_array($args[0]) ? $args : [$args];
    $routes = &qp_rest_routes_ref();
    $ok = true;
    foreach ($handlers as $h) {
        $methods = array_map('strtoupper', (array)($h['methods'] ?? 'GET'));
        $bad = array_diff($methods, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE']);
        if ($bad || !isset($h['callback']) || !is_callable($h['callback'])) { error_log("qp_register_rest_route: $namespace$route needs methods and a callable callback"); $ok = false; continue; }
        if (!isset($h['permission_callback']) || !is_callable($h['permission_callback'])) {
            // Refused: a route without a permission callback cannot exist.
            error_log("qp_register_rest_route: $namespace$route has no permission_callback and was not registered");
            qp_rest_rejected_ref()[] = $namespace . $route;
            $ok = false; continue;
        }
        $h['methods'] = $methods;
        $h['args'] = (array)($h['args'] ?? []);
        $h['public'] = $h['permission_callback'] === 'qp_rest_permit_public';
        $routes[$namespace][$route][] = $h;
    }
    return $ok;
}

/** Every registered route as [namespace, route, handler] (for the OpenAPI export, the admin docs tab and the audit). */
function qp_rest_all_routes(): array {
    $out = [];
    foreach (qp_rest_routes_ref() as $ns => $routes) foreach ($routes as $route => $handlers) foreach ($handlers as $h) $out[] = [$ns, $route, $h];
    return $out;
}

/** A route that was refused at registration for lacking a permission callback: for the build check. */
function qp_rest_audit(): array { return qp_rest_rejected_ref(); }

// ---------------------------------------------------------------- helpers

function qp_rest_client_ip(): string {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $cfg = function_exists('get_config') ? get_config() : [];
    if (!empty($cfg['trust_proxy']) && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $first = trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
        if (filter_var($first, FILTER_VALIDATE_IP)) $ip = $first;
    }
    return (string)$ip;
}

function qp_rest_is_loopback(): bool {
    $a = $_SERVER['REMOTE_ADDR'] ?? '';
    return $a === '127.0.0.1' || $a === '::1' || strpos($a, '127.') === 0;
}

function qp_rest_is_https(): bool {
    if (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') return true;
    if ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443) return true;
    $cfg = function_exists('get_config') ? get_config() : [];
    return !empty($cfg['trust_proxy']) && strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
}

function qp_rest_site_origin(): string {
    $u = parse_url(defined('SITE_URL') ? SITE_URL : '');
    if (empty($u['host'])) return '';
    return ($u['scheme'] ?? 'https') . '://' . $u['host'] . (isset($u['port']) ? ':' . $u['port'] : '');
}

function qp_rest_secret(string $label): string {
    $cfg = function_exists('get_config') ? get_config() : [];
    return hash_hmac('sha256', 'qp_rest|' . $label, (string)($cfg['security_salt'] ?? ''), true);
}

/** Does this user hold the capability? Same rules as current_user_can(), for any user id (API users are not the cookie user). */
function qp_rest_user_can(int $uid, string $cap): bool {
    static $memo = [];
    if (!isset($memo[$uid])) {
        $caps = get_user_meta($uid, 'capabilities');
        $caps = is_array($caps) ? $caps : (array)$caps;
        $role = get_user_meta($uid, 'role');
        $rc = $role ? get_role_capabilities((string)$role) : [];
        $memo[$uid] = array_flip(array_merge($rc, $caps));
    }
    return isset($memo[$uid][$cap]);
}

/** A user row without the password hash; null when the id does not exist. */
function qp_rest_user_row(int $uid, bool $with_hash = false): ?array {
    static $memo = [];
    if (!isset($memo[$uid])) {
        $st = db()->prepare('SELECT id, username, email, password_hash, created_at FROM ' . table_name('users') . ' WHERE id = ? LIMIT 1');
        $st->execute([$uid]);
        $memo[$uid] = $st->fetch(PDO::FETCH_ASSOC) ?: false;
    }
    if (!$memo[$uid]) return null;
    $r = $memo[$uid];
    if (!$with_hash) unset($r['password_hash']);
    return $r;
}

// ---------------------------------------------------------------- rate limits and counters (no schema; APCu when present, else small files)

function qp_rest_counter_dir(string $name): string {
    $cfg = function_exists('get_config') ? get_config() : [];
    $d = dirname(__DIR__) . '/' . ($cfg['uploads_dir'] ?? 'uploads') . '/' . $name;
    if (!is_dir($d)) @mkdir($d, 0775, true);
    return $d;
}

/** Count one hit in a fixed window. Returns [count, seconds until the window ends]. */
function qp_rest_hit(string $bucket, int $window): array {
    $bucket = (int)qp_rest_settings()['counter_epoch'] . ':' . $bucket;   // bumping the epoch (Settings > API > Reset limits) starts every counter from zero
    $slot = intdiv(time(), $window);
    $reset = ($slot + 1) * $window - time();
    $key = 'qprest:' . sha1($bucket) . ':' . $slot;
    if (function_exists('apcu_inc') && ini_get('apc.enabled') && (PHP_SAPI !== 'cli' || ini_get('apc.enable_cli'))) {
        apcu_add($key, 0, $window + 5);
        $n = apcu_inc($key);
        if ($n !== false) return [(int)$n, $reset];
    }
    $dir = qp_rest_counter_dir('api-rate');
    $f = $dir . '/' . sha1($bucket) . '.json';
    $fh = @fopen($f, 'c+');
    if (!$fh) return [1, $reset];
    $n = 1;
    if (flock($fh, LOCK_EX)) {
        $cur = json_decode((string)stream_get_contents($fh), true);
        $n = (is_array($cur) && ($cur['s'] ?? -1) === $slot) ? (int)$cur['n'] + 1 : 1;
        ftruncate($fh, 0); rewind($fh); fwrite($fh, json_encode(['s' => $slot, 'n' => $n]));
        flock($fh, LOCK_UN);
    }
    fclose($fh);
    if (mt_rand(1, 200) === 1) qp_rest_counter_gc($dir, 3600);
    return [$n, $reset];
}

function qp_rest_counter_peek(string $bucket, int $window): int {
    $bucket = (int)qp_rest_settings()['counter_epoch'] . ':' . $bucket;
    $slot = intdiv(time(), $window);
    $key = 'qprest:' . sha1($bucket) . ':' . $slot;
    if (function_exists('apcu_fetch') && ini_get('apc.enabled') && (PHP_SAPI !== 'cli' || ini_get('apc.enable_cli'))) {
        $v = apcu_fetch($key, $ok);
        if ($ok) return (int)$v;
    }
    $f = qp_rest_counter_dir('api-rate') . '/' . sha1($bucket) . '.json';
    $cur = is_file($f) ? json_decode((string)@file_get_contents($f), true) : null;
    return (is_array($cur) && ($cur['s'] ?? -1) === $slot) ? (int)$cur['n'] : 0;
}

function qp_rest_counter_gc(string $dir, int $maxAge): void {
    foreach (@scandir($dir) ?: [] as $n) {
        if ($n[0] === '.') continue;
        $p = $dir . '/' . $n;
        if (is_file($p) && filemtime($p) < time() - $maxAge) @unlink($p);
    }
}

/** Apply a limit; returns a 429 error or null. Adds X-RateLimit headers to $headers. */
function qp_rest_rate_limit(string $bucket, int $limit, array &$headers, int $window = 60): ?QP_REST_Error {
    if ($limit <= 0) return null;
    [$n, $reset] = qp_rest_hit($bucket, $window);
    $headers['X-RateLimit-Limit'] = (string)$limit;
    $headers['X-RateLimit-Remaining'] = (string)max(0, $limit - $n);
    $headers['X-RateLimit-Reset'] = (string)$reset;
    if ($n > $limit) return new QP_REST_Error('rate_limited', 'Too many requests. Try again later.', 429, [], ['Retry-After' => (string)max(1, $reset)]);
    return null;
}

// ---------------------------------------------------------------- argument schema

function qp_rest_coerce($v, string $type) {
    switch ($type) {
        case 'integer': return (is_int($v) || (is_string($v) && preg_match('/^-?\d{1,18}$/', $v))) ? (int)$v : null;
        case 'number': return is_numeric($v) && !is_string($v) || (is_string($v) && is_numeric($v)) ? $v + 0 : null;
        case 'boolean':
            if (is_bool($v)) return $v;
            if (in_array($v, [1, '1', 'true', 'on', 'yes'], true)) return true;
            if (in_array($v, [0, '0', 'false', 'off', 'no', ''], true)) return false;
            return null;
        case 'array': return is_array($v) ? $v : (is_string($v) ? ($v === '' ? [] : array_map('trim', explode(',', $v))) : null);
        case 'object': return is_array($v) ? $v : null;
        default: return is_scalar($v) ? (string)$v : null;   // string
    }
}

/** Validate and coerce the request parameters against the route's args schema. Returns field errors (empty when fine). */
function qp_rest_validate_args(QP_REST_Request $r, array $schema): array {
    $errors = [];
    foreach ($schema as $name => $def) {
        $def = (array)$def;
        $present = array_key_exists($name, $r->params);
        if (!$present) {
            if (!empty($def['required'])) { $errors[$name] = 'This field is required.'; continue; }
            if (array_key_exists('default', $def)) $r->params[$name] = $def['default'];
            continue;
        }
        $type = $def['type'] ?? 'string';
        $v = qp_rest_coerce($r->params[$name], $type);
        if ($v === null) { $errors[$name] = 'Must be of type ' . $type . '.'; continue; }
        if (isset($def['enum']) && !in_array($v, $def['enum'], true)) { $errors[$name] = 'Must be one of: ' . implode(', ', $def['enum']) . '.'; continue; }
        if (is_numeric($v) && !is_string($v)) {
            if (isset($def['minimum']) && $v < $def['minimum']) { $errors[$name] = 'Must be at least ' . $def['minimum'] . '.'; continue; }
            if (isset($def['maximum']) && $v > $def['maximum']) { $errors[$name] = 'Must be at most ' . $def['maximum'] . '.'; continue; }
        }
        if (is_string($v)) {
            if (isset($def['maxLength']) && mb_strlen($v) > $def['maxLength']) { $errors[$name] = 'At most ' . $def['maxLength'] . ' characters.'; continue; }
            if (isset($def['minLength']) && mb_strlen($v) < $def['minLength']) { $errors[$name] = 'At least ' . $def['minLength'] . ' characters.'; continue; }
            if (isset($def['pattern']) && !preg_match($def['pattern'], $v)) { $errors[$name] = 'Not in the expected format.'; continue; }
            $v = preg_replace('/[\x00-\x08\x0b\x0c\x0e-\x1f]/', '', $v);
        }
        if (is_array($v) && isset($def['items']['type'])) {
            foreach ($v as $i => $item) {
                $c = qp_rest_coerce($item, $def['items']['type']);
                if ($c === null) { $errors[$name] = 'Items must be of type ' . $def['items']['type'] . '.'; break; }
                $v[$i] = $c;
            }
            if (isset($errors[$name])) continue;
            if (isset($def['maxItems']) && count($v) > $def['maxItems']) { $errors[$name] = 'At most ' . $def['maxItems'] . ' items.'; continue; }
        }
        if (!empty($def['validate_callback']) && is_callable($def['validate_callback'])) {
            $res = call_user_func($def['validate_callback'], $v, $r, $name);
            if ($res !== true) { $errors[$name] = is_string($res) ? $res : 'Invalid value.'; continue; }
        }
        if (!empty($def['sanitize_callback']) && is_callable($def['sanitize_callback'])) $v = call_user_func($def['sanitize_callback'], $v, $r, $name);
        $r->params[$name] = $v;
    }
    return $errors;
}

// ---------------------------------------------------------------- CORS

/** Headers for a request from $origin, or [] when the origin is not allowed. Same-origin needs none. */
function qp_rest_cors_headers(string $origin): array {
    if ($origin === '') return [];
    $allowed = (array)qp_rest_settings()['cors_origins'];
    $o = rtrim(strtolower($origin), '/');
    $match = false;
    foreach ($allowed as $a) if (rtrim(strtolower((string)$a), '/') === $o) { $match = true; break; }
    if (!$match) return [];
    return ['Access-Control-Allow-Origin' => $origin, 'Access-Control-Allow-Credentials' => 'true', 'Access-Control-Expose-Headers' => 'X-Total, X-Total-Pages, Link, ETag, X-RateLimit-Limit, X-RateLimit-Remaining, X-RateLimit-Reset, Retry-After, Idempotency-Replayed'];
}

// ---------------------------------------------------------------- authentication chain

/** The schemes, in order. Each callable($request) returns null (no credentials of this kind), a QP_REST_Error, or ['user_id','scopes','scheme', optional 'key_id'/'family']. */
function qp_rest_auth_schemes(): array {
    $schemes = ['cookie' => 'qp_rest_auth_cookie', 'bearer' => 'qp_rest_auth_bearer', 'apikey' => 'qp_rest_auth_apikey', 'basic' => 'qp_rest_auth_basic'];
    if (function_exists('apply_filters')) {
        $f = apply_filters('qp_rest_auth_schemes', $schemes);
        if (is_array($f)) $schemes = $f;
    }
    return $schemes;
}

function qp_rest_bad_auth(): QP_REST_Error {
    // One message for every credential failure: it never says which part was wrong.
    return new QP_REST_Error('invalid_credentials', 'Authentication failed.', 401, [], ['WWW-Authenticate' => 'Bearer realm="qlopy"', 'Cache-Control' => 'no-store']);
}

/** Cookie session + nonce: same-origin only, for the admin and storefront JavaScript. */
function qp_rest_auth_cookie(QP_REST_Request $r) {
    if ($r->get_header('authorization') !== null || $r->get_header('x-api-key') !== null) return null;   // explicit credentials win
    $uid = function_exists('qp_current_user_id') ? qp_current_user_id() : null;
    if (!$uid) return null;
    $origin = $r->get_header('origin');
    if ($origin !== null && $origin !== '' && rtrim(strtolower($origin), '/') !== strtolower(qp_rest_site_origin())) return new QP_REST_Error('cross_origin_cookie', 'Cookie sessions work from the site\'s own pages only. Use an API key or token from other origins.', 403);
    if (!in_array($r->method, ['GET', 'HEAD'], true)) {
        $n = (string)($r->get_header('x-qp-nonce') ?? '');
        if ($n === '' || !qp_verify_nonce($n, 'qp_rest')) return new QP_REST_Error('bad_nonce', 'Missing or expired nonce. Reload the page and try again.', 403);
    }
    return ['user_id' => (int)$uid, 'scopes' => ['*'], 'scheme' => 'cookie'];
}

function qp_rest_https_required_error(): ?QP_REST_Error {
    if (qp_rest_is_https() || qp_rest_is_loopback()) return null;
    return new QP_REST_Error('https_required', 'This way of signing in needs HTTPS. Credentials are not accepted over plain HTTP.', 400);
}

/** Does a granted scope list allow a needed scope like "posts:read"? */
function qp_rest_scope_allows(array $granted, string $need): bool {
    if ($need === '' || $need === 'none') return true;
    if (in_array('*', $granted, true) || in_array($need, $granted, true)) return true;
    [$res, $act] = array_pad(explode(':', $need, 2), 2, '');
    return in_array($act, $granted, true) || in_array($res . ':*', $granted, true);
}

function qp_rest_valid_scope(string $s): bool {
    return $s === '*' || (bool)preg_match('~^([a-z0-9_-]+:)?(read|write|\*)$~', $s);
}

// ---------------------------------------------------------------- dispatcher

function qp_rest_path_params(array $m): array { return array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY); }

/** Find the handler list for a path: [namespace, route, handlers, url_params] or null. */
function qp_rest_match(string $path): ?array {
    foreach (qp_rest_routes_ref() as $ns => $routes) {
        $prefix = '/' . $ns;
        if ($path !== $prefix && strncmp($path, $prefix . '/', strlen($prefix) + 1) !== 0) continue;
        $rest = substr($path, strlen($prefix));
        if ($rest === '' || $rest === false) $rest = '/';
        foreach ($routes as $route => $handlers) {
            if (preg_match('#^' . $route . '/?$#', $rest, $m)) return [$ns, $route, $handlers, qp_rest_path_params($m)];
        }
    }
    return null;
}

function qp_rest_send($status, array $headers, ?string $body): void {
    while (ob_get_level() > 0) ob_end_clean();
    if (function_exists('header_remove')) header_remove('Set-Cookie');   // the API never sets cookies (auth.php seeds one for anonymous pages)
    http_response_code($status);
    foreach ($headers as $k => $v) header($k . ': ' . $v);
    if (function_exists('qp_profile_finish')) qp_profile_finish();   // the benchmark profiler's headers (only when it is on and asked)
    if ($body !== null && $status !== 204 && $status !== 304) echo $body;
}

function qp_rest_error_body(QP_REST_Error $e): array {
    $err = ['code' => $e->code, 'message' => $e->message, 'status' => $e->status];
    if ($e->fields) $err['fields'] = $e->fields;
    return ['error' => $err];
}

/** Serve one request; sends the reply and returns. $path is like "/qlopy/v1/posts". */
function qp_rest_serve(string $path): void {
    $headers = [
        'Content-Type' => 'application/json; charset=utf-8', 'X-Content-Type-Options' => 'nosniff', 'X-Robots-Tag' => 'noindex',
        'Vary' => 'Origin, Authorization, Cookie', 'Referrer-Policy' => 'no-referrer',
    ];
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    $out = function ($status, $data, array $extra = []) use (&$headers, $method) {
        $h = array_merge($headers, $extra);
        $body = $data === null ? null : json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        qp_rest_send($status, $h, $method === 'HEAD' ? null : $body);
    };
    $fail = function (QP_REST_Error $e) use ($out, &$headers) { $out($e->status, qp_rest_error_body($e), $e->headers); };

    $r = new QP_REST_Request();
    $r->method = $method;
    foreach ($_SERVER as $k => $v) if (strncmp($k, 'HTTP_', 5) === 0) $r->headers[strtolower(str_replace('_', '-', substr($k, 5)))] = (string)$v;
    if (isset($_SERVER['CONTENT_TYPE'])) $r->headers['content-type'] = (string)$_SERVER['CONTENT_TYPE'];
    if (!isset($r->headers['authorization']) && !empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) $r->headers['authorization'] = (string)$_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
    if (!isset($r->headers['authorization']) && function_exists('getallheaders')) { foreach (getallheaders() as $k => $v) if (strtolower($k) === 'authorization') $r->headers['authorization'] = (string)$v; }

    // CORS: same-origin needs nothing; a listed origin gets headers; anything else gets none (the browser blocks it).
    $origin = (string)($r->get_header('origin') ?? '');
    $cors = ($origin !== '' && strtolower(rtrim($origin, '/')) !== strtolower(qp_rest_site_origin())) ? qp_rest_cors_headers($origin) : [];
    $headers = array_merge($headers, $cors);
    if ($method === 'OPTIONS') {
        $pre = $cors ? ['Access-Control-Allow-Methods' => 'GET, POST, PUT, PATCH, DELETE, OPTIONS', 'Access-Control-Allow-Headers' => 'Authorization, Content-Type, X-QP-Nonce, X-API-Key, Idempotency-Key, If-None-Match', 'Access-Control-Max-Age' => '600'] : [];
        qp_rest_send(204, array_merge($headers, $pre, ['Content-Length' => '0']), null);
        return;
    }
    if (!in_array($method, ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE'], true)) { $fail(new QP_REST_Error('method_not_allowed', 'Method not allowed.', 405, [], ['Allow' => 'GET, POST, PUT, PATCH, DELETE'])); return; }

    try {
        // Per-IP limit first: it is the cheapest check and protects everything below it.
        $rate = [];
        if (!qp_rest_is_loopback()) {
            $limited = qp_rest_rate_limit('ip:' . qp_rest_client_ip(), (int)qp_rest_settings()['rate_ip'], $rate);
            $headers = array_merge($headers, $rate);
            if ($limited) { $fail($limited); return; }
        }

        $m = qp_rest_match($path);
        if (!$m) { $fail(new QP_REST_Error('route_not_found', 'No such route.', 404)); return; }
        [$ns, $route, $handlers, $urlParams] = $m;
        $handler = null; $allow = [];
        foreach ($handlers as $h) { foreach ($h['methods'] as $mm) $allow[$mm] = true; if (in_array($method === 'HEAD' ? 'GET' : $method, $h['methods'], true)) { $handler = $h; break; } }
        if (!$handler) { $fail(new QP_REST_Error('method_not_allowed', 'Method not allowed for this route.', 405, [], ['Allow' => implode(', ', array_keys($allow))])); return; }
        $r->namespace = $ns; $r->route = $route; $r->handler = $handler; $r->url_params = $urlParams;

        // Body: JSON only, size and depth limits.
        parse_str((string)($_SERVER['QUERY_STRING'] ?? ''), $q);
        unset($q['qp_rest']);
        $r->query = $q;
        $form = !empty($handler['form']) && $method === 'POST' && stripos((string)($r->get_header('content-type') ?? ''), 'application/x-www-form-urlencoded') === 0;
        $multipart = !empty($handler['multipart']) && $method === 'POST' && stripos((string)($r->get_header('content-type') ?? ''), 'multipart/form-data') === 0;
        if ($form) {
            // OAuth endpoints take form bodies, as the standard says.
            if ((int)($r->get_header('content-length') ?? 0) > 16384) { $fail(new QP_REST_Error('payload_too_large', 'The request body is too large.', 413)); return; }
            $r->body = is_array($_POST) ? $_POST : [];
        } elseif ($multipart) {
            // A route that takes file uploads (a handler with 'multipart' => true): PHP has already parsed the form; the size limit is the upload limit.
            if ((int)($r->get_header('content-length') ?? 0) > max(1048576, (int)qp_rest_settings()['max_upload']) + 65536) { $fail(new QP_REST_Error('payload_too_large', 'The upload is too large.', 413)); return; }
            $r->body = is_array($_POST) ? $_POST : [];
            $r->files = is_array($_FILES) ? $_FILES : [];
        } elseif (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            $len = (int)($r->get_header('content-length') ?? 0);
            $max = max(1024, (int)qp_rest_settings()['max_body']);
            if ($len > $max) { $fail(new QP_REST_Error('payload_too_large', 'The request body is too large.', 413)); return; }
            $raw = (string)file_get_contents('php://input', false, null, 0, $max + 1);
            if (strlen($raw) > $max) { $fail(new QP_REST_Error('payload_too_large', 'The request body is too large.', 413)); return; }
            $r->raw_body = $raw;
            if ($raw !== '') {
                if (stripos((string)($r->get_header('content-type') ?? ''), 'application/json') !== 0) { $fail(new QP_REST_Error('unsupported_media_type', 'Send the body as JSON with Content-Type: application/json.', 415)); return; }
                $j = json_decode($raw, true, 32);
                if (!is_array($j)) { $fail(new QP_REST_Error('invalid_json', 'The request body is not valid JSON (objects up to 32 levels deep).', 400)); return; }
                $r->body = $j;
            }
        }
        $r->params = array_merge($r->query, $r->body, $r->url_params);

        // Authentication: the first scheme that finds credentials decides.
        foreach (qp_rest_auth_schemes() as $name => $fn) {
            if (!is_callable($fn)) continue;
            $res = call_user_func($fn, $r);
            if ($res instanceof QP_REST_Error) { $fail($res); return; }
            if (is_array($res) && !empty($res['user_id'])) {
                $r->user_id = (int)$res['user_id']; $r->scopes = (array)($res['scopes'] ?? []); $r->scheme = (string)($res['scheme'] ?? $name);
                $r->key_id = $res['key_id'] ?? null; $r->family = $res['family'] ?? null;
                if (!empty($res['rate_limit'])) {
                    $limited = qp_rest_rate_limit('key:' . $r->scheme . ':' . $r->user_id . ':' . $r->key_id, (int)$res['rate_limit'], $rate);
                    $headers = array_merge($headers, $rate);
                    if ($limited) { $fail($limited); return; }
                }
                break;
            }
        }
        if ($r->user_id) $headers['Cache-Control'] = 'private, no-cache';

        // Scope, then arguments, then the permission callback (it sees validated, coerced parameters).
        $need = $handler['scope'] ?? null;
        if ($need === null) {
            $res = trim($route, '/'); $res = preg_replace('~[^a-z0-9_-].*$~i', '', $res) ?: 'root';
            $need = $res . ':' . (in_array($method, ['GET', 'HEAD'], true) ? 'read' : 'write');
        }
        if ($r->user_id && !qp_rest_scope_allows($r->scopes, (string)$need)) { $fail(new QP_REST_Error('insufficient_scope', 'This credential is not allowed to do that.', 403)); return; }
        $errs = qp_rest_validate_args($r, $handler['args']);
        if ($errs) { $fail(new QP_REST_Error('invalid_params', 'Some values are not valid.', 400, $errs)); return; }
        $perm = call_user_func($handler['permission_callback'], $r);
        if ($perm instanceof QP_REST_Error) { $fail($perm); return; }
        if ($perm !== true) { $fail($r->user_id ? new QP_REST_Error('forbidden', 'You are not allowed to do that.', 403) : new QP_REST_Error('unauthorized', 'Sign in to do that.', 401, [], ['WWW-Authenticate' => 'Bearer realm="qlopy"'])); return; }

        // Idempotency keys for writes.
        $idemKey = null;
        if ($r->user_id && $method !== 'GET' && $method !== 'HEAD') {
            $k = (string)($r->get_header('idempotency-key') ?? '');
            if ($k !== '') {
                if (!preg_match('/^[A-Za-z0-9_.:-]{8,64}$/', $k)) { $fail(new QP_REST_Error('invalid_idempotency_key', 'Idempotency-Key must be 8 to 64 characters: letters, digits and _ . : -', 400)); return; }
                $fp = sha1($method . ' ' . $path . ' ' . $r->raw_body);
                $file = qp_rest_counter_dir('api-idem') . '/' . sha1($r->user_id . '|' . $k) . '.json';
                $saved = is_file($file) ? json_decode((string)@file_get_contents($file), true) : null;
                if (is_array($saved) && ($saved['t'] ?? 0) > time() - 86400) {
                    if (($saved['fp'] ?? '') !== $fp) { $fail(new QP_REST_Error('idempotency_mismatch', 'This Idempotency-Key was already used with a different request.', 422)); return; }
                    qp_rest_send((int)$saved['status'], array_merge($headers, ['Idempotency-Replayed' => 'true']), json_encode($saved['body'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
                    return;
                }
                $idemKey = [$file, $fp];
                if (mt_rand(1, 100) === 1) qp_rest_counter_gc(dirname($file), 86400);
            }
        }

        $res = call_user_func($handler['callback'], $r);
        if ($res instanceof QP_REST_Error) { $fail($res); return; }
        if (!($res instanceof QP_REST_Response)) $res = new QP_REST_Response($res);
        $extra = $res->headers;
        $status = $res->status;
        if (!$r->user_id && $method === 'GET' && $status === 200 && !isset($extra['Cache-Control'])) $extra['Cache-Control'] = 'no-cache';
        if (!empty($handler['no_store'])) $extra['Cache-Control'] = 'no-store';

        $json = $res->data === null ? null : json_encode($res->data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        if ($method === 'GET' || $method === 'HEAD') {
            if ($status === 200 && $json !== null && empty($handler['no_store'])) {
                $etag = '"' . sha1($json) . '"';
                $extra['ETag'] = $etag;
                $inm = (string)($r->get_header('if-none-match') ?? '');
                if ($inm !== '' && in_array($etag, array_map('trim', explode(',', str_replace('W/', '', $inm))), true)) { qp_rest_send(304, array_merge($headers, $extra), null); return; }
            }
        }
        if ($idemKey && $status < 500) {
            @file_put_contents($idemKey[0], json_encode(['t' => time(), 'fp' => $idemKey[1], 'status' => $status, 'body' => $res->data]), LOCK_EX);
        }
        qp_rest_send($status, array_merge($headers, $extra), $method === 'HEAD' ? null : $json);
    } catch (Throwable $e) {
        error_log('REST error on ' . $method . ' ' . $path . ': ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());
        $fail(new QP_REST_Error('server_error', 'Something went wrong on the server.', 500));   // no detail leaves the server
    }
}

// ---------------------------------------------------------------- pagination and fields

/** Add X-Total, X-Total-Pages and Link headers to a collection response. */
function qp_rest_paginated(array $items, int $total, int $page, int $per_page): QP_REST_Response {
    $pages = max(1, (int)ceil($total / max(1, $per_page)));
    $res = new QP_REST_Response($items);
    $res->headers['X-Total'] = (string)$total;
    $res->headers['X-Total-Pages'] = (string)$pages;
    $base = qp_rest_site_origin() . strtok((string)($_SERVER['REQUEST_URI'] ?? ''), '?');
    parse_str((string)($_SERVER['QUERY_STRING'] ?? ''), $q);
    $link = function ($p, $rel) use ($base, $q) { $q['page'] = $p; return '<' . $base . '?' . http_build_query($q) . '>; rel="' . $rel . '"'; };
    $links = [$link(1, 'first'), $link($pages, 'last')];
    if ($page > 1) $links[] = $link($page - 1, 'prev');
    if ($page < $pages) $links[] = $link($page + 1, 'next');
    $res->headers['Link'] = implode(', ', $links);
    return $res;
}

/** Keep only the requested fields (?fields=id,title). */
function qp_rest_select_fields($item, $fields) {
    if (!is_array($item) || !$fields) return $item;
    $want = is_array($fields) ? $fields : array_map('trim', explode(',', (string)$fields));
    return array_intersect_key($item, array_flip($want));
}

// ---------------------------------------------------------------- OpenAPI export

function qp_rest_openapi(): array {
    $paths = [];
    foreach (qp_rest_all_routes() as [$ns, $route, $h]) {
        $path = '/' . $ns . preg_replace_callback('~\(\?P<([a-z_]+)>[^)]*\)~i', function ($m) { return '{' . $m[1] . '}'; }, $route);
        foreach ($h['methods'] as $mth) {
            $params = [];
            foreach (preg_match_all('~\{([a-z_]+)\}~i', $path, $mm) ? $mm[1] : [] as $pn) $params[] = ['name' => $pn, 'in' => 'path', 'required' => true, 'schema' => ['type' => ($h['args'][$pn]['type'] ?? 'string')]];
            $bodyProps = []; $bodyReq = [];
            foreach ($h['args'] as $an => $def) {
                if (in_array($an, array_column($params, 'name'), true)) continue;
                $schema = array_intersect_key((array)$def, array_flip(['type', 'enum', 'minimum', 'maximum', 'minLength', 'maxLength', 'default', 'pattern', 'items']));
                $schema += ['type' => 'string'];
                if ($mth === 'GET' || $mth === 'DELETE') $params[] = ['name' => $an, 'in' => 'query', 'required' => !empty($def['required']), 'schema' => $schema];
                else { $bodyProps[$an] = $schema; if (!empty($def['required'])) $bodyReq[] = $an; }
            }
            $op = ['summary' => (string)($h['summary'] ?? ''), 'parameters' => $params, 'responses' => ['200' => ['description' => 'Success'], '400' => ['description' => 'Invalid parameters'], '401' => ['description' => 'Authentication needed'], '403' => ['description' => 'Not allowed']]];
            if (!empty($h['description'])) $op['description'] = (string)$h['description'];
            if ($bodyProps) $op['requestBody'] = ['content' => ['application/json' => ['schema' => ['type' => 'object', 'properties' => $bodyProps] + ($bodyReq ? ['required' => $bodyReq] : [])]]];
            if (!$h['public']) $op['security'] = [['bearerAuth' => []], ['apiKey' => []], ['basicAuth' => []], ['cookieAuth' => []]];
            $paths[$path][strtolower($mth)] = $op;
        }
    }
    ksort($paths);
    return [
        'openapi' => '3.0.3',
        'info' => ['title' => 'Qlopy API', 'version' => QP_REST_VERSION, 'description' => 'Generated from the registered routes.'],
        'servers' => [['url' => (defined('SITE_URL') ? SITE_URL : '') . '/api']],
        'paths' => $paths,
        'components' => ['securitySchemes' => [
            'bearerAuth' => ['type' => 'http', 'scheme' => 'bearer', 'bearerFormat' => 'JWT'],
            'apiKey' => ['type' => 'apiKey', 'in' => 'header', 'name' => 'X-API-Key'],
            'basicAuth' => ['type' => 'http', 'scheme' => 'basic', 'description' => 'Username and an application password'],
            'cookieAuth' => ['type' => 'apiKey', 'in' => 'cookie', 'name' => 'qp_token', 'description' => 'Same-origin only; send X-QP-Nonce on writes'],
        ]],
    ];
}
