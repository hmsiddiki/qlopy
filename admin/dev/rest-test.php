<?php
// Dev tool (C4): checks the REST API: registry rules, validation, every sign-in scheme, CORS, limits, conventions and webhooks. CLI only.
//   php admin/dev/rest-test.php [--base=https://host/path] [--resolve=127.0.0.1]
// Runs in a child process signed in as the first administrator (a temporary session). Switches the API schemes on for the run and
// restores the settings, the widgets, posts and everything else it created at the end.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('QLOPY_INIT', true);
chdir(dirname(__DIR__, 2));
$opt = getopt('', ['base::', 'resolve::', 'cookie::', 'uid::']);
if (empty($opt['cookie'])) {
    require 'db.php'; $pdo = db();
    require 'auth.php';
    $uid = 0;
    foreach ($pdo->query("SELECT user_id, meta_value FROM " . table_name('user_meta') . " WHERE meta_key = 'role'")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if (strpos((string)$r['meta_value'], 'admin') !== false) { $uid = (int)$r['user_id']; break; }
    }
    if (!$uid) { echo "No administrator found.\n"; exit(1); }
    $tok = create_session_token($uid);
    $cookie = $tok['token_id'] . '|' . $tok['user_id'] . '|' . $tok['expires'] . '|' . qp_hash_token_sig($tok['token_id'], $tok['user_id'], $tok['expires']);
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --cookie=' . escapeshellarg($cookie) . ' --uid=' . $uid;
    foreach (['base', 'resolve'] as $k) if (isset($opt[$k])) $cmd .= ' --' . $k . '=' . escapeshellarg($opt[$k]);
    passthru($cmd, $code);
    revoke_session_token($tok['token_id'], $uid);
    exit($code);
}
$_COOKIE['qp_token'] = $opt['cookie'];
$uid = (int)$opt['uid'];
require 'db.php'; $pdo = db();
require 'auth.php';
foreach (["ajax-hooks", "post-types", "rest", "rest-auth", "webhooks", "rest-routes", "rest-oauth"] as $inc) require_once "includes/$inc.php";
$base = rtrim($opt['base'] ?? $config['site_url'], '/');
$host = parse_url($base, PHP_URL_HOST);
$port = parse_url($base, PHP_URL_PORT) ?: (parse_url($base, PHP_URL_SCHEME) === 'https' ? 443 : 80);
$resolve = $opt['resolve'] ?? '127.0.0.1';
$cookie = $opt['cookie'];
$pass = 0; $fail = 0; $EPOCH = time();   // every run counts rate limits and lockouts in its own epoch
function ok($c, $m) { global $pass, $fail; if ($c) $pass++; else $fail++; echo ($c ? 'PASS ' : 'FAIL ') . $m . "\n"; }

function api($method, $path, $o = []) {
    global $base, $host, $port, $resolve;
    $url = $base . (!empty($o['raw']) ? '' : '/api') . $path;
    $c = curl_init($url);
    $h = $o['h'] ?? [];
    if (isset($o['json'])) { $h[] = 'Content-Type: application/json'; $o['body'] = json_encode($o['json']); }
    if (isset($o['form'])) { $h[] = 'Content-Type: application/x-www-form-urlencoded'; $o['body'] = http_build_query($o['form']); }
    $opts = [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RESOLVE => ["$host:$port:$resolve"], CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0, CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 60, CURLOPT_HTTPHEADER => $h, CURLOPT_FOLLOWLOCATION => false];
    if (isset($o['body'])) $opts[CURLOPT_POSTFIELDS] = $o['body'];
    if (!empty($o['cookie'])) $opts[CURLOPT_COOKIE] = 'qp_token=' . $o['cookie'];
    if (!empty($o['user'])) $opts[CURLOPT_USERPWD] = $o['user'];
    if ($method === 'HEAD') $opts[CURLOPT_NOBODY] = true;
    curl_setopt_array($c, $opts);
    $r = (string)curl_exec($c); $hs = curl_getinfo($c, CURLINFO_HEADER_SIZE); $code = curl_getinfo($c, CURLINFO_HTTP_CODE); curl_close($c);
    $head = substr($r, 0, $hs); $body = substr($r, $hs);
    $hdr = [];
    foreach (explode("\r\n", $head) as $line) if (strpos($line, ':') !== false) { [$k, $v] = explode(':', $line, 2); $hdr[strtolower(trim($k))] = trim($v); }
    return ['code' => $code, 'h' => $hdr, 'body' => $body, 'j' => json_decode($body, true)];
}
function errcode($r) { return $r['j']['error']['code'] ?? ''; }

$origSettings = get_option_meta('qp_rest_settings');
$origWebhooks = get_option_meta('qp_webhooks');
$origKeys = get_user_meta($uid, 'qp_api_keys'); $origPw = get_user_meta($uid, 'qp_app_passwords'); $origRefresh = get_user_meta($uid, 'qp_jwt_refresh');
$createdPosts = []; $createdTerms = []; $createdUsers = []; $receiver = null;
$cleanup = function () use ($pdo, $uid, $origSettings, $origWebhooks, $origKeys, $origPw, $origRefresh, &$createdPosts, &$createdTerms, &$receiver) {
    if ($receiver) { @proc_terminate($receiver['proc']); }
    $O = table_name('site_options');
    if ($origSettings) update_option_meta('qp_rest_settings', $origSettings); else $pdo->exec("DELETE FROM $O WHERE option_name = 'qp_rest_settings'");
    if ($origWebhooks) update_option_meta('qp_webhooks', $origWebhooks); else $pdo->exec("DELETE FROM $O WHERE option_name = 'qp_webhooks'");
    $pdo->exec("DELETE FROM $O WHERE option_name IN ('qp_webhook_log', 'qp_oauth_clients')");
    update_user_meta($uid, 'qp_api_keys', $origKeys ?: null); update_user_meta($uid, 'qp_app_passwords', $origPw ?: null); update_user_meta($uid, 'qp_jwt_refresh', $origRefresh ?: null);
    foreach ($createdPosts as $id) { $pdo->exec("DELETE FROM " . table_name('post_terms') . " WHERE post_id = " . (int)$id); $pdo->exec("DELETE FROM " . table_name('posts') . " WHERE id = " . (int)$id); }
    foreach ($createdTerms as $id) $pdo->exec("DELETE FROM " . table_name('taxonomy_terms') . " WHERE id = " . (int)$id);
    @unlink(qp_webhook_queue_file()); if (function_exists("qp_remove_tasks_by_hook")) qp_remove_tasks_by_hook("qp_webhook_flush");
};

try {
    // ================= in-process =================
    echo "-- registry\n";
    $GLOBALS['qp_rest_core_loading'] = false;
    ok(qp_register_rest_route('t/v1', '/open', ['methods' => 'GET', 'callback' => function () { return []; }]) === false && in_array('t/v1/open', qp_rest_audit(), true), 'a route without a permission callback is refused and listed by the audit');
    ok(qp_register_rest_route('qlopy/v1', '/mine', ['methods' => 'GET', 'callback' => function () { return []; }, 'permission_callback' => 'qp_rest_permit_public']) === false, 'a plugin cannot register under the core namespace');
    ok(qp_register_rest_route('Bad NS', '/x', ['methods' => 'GET', 'callback' => function () { return []; }, 'permission_callback' => 'qp_rest_permit_public']) === false, 'a bad namespace is refused');
    ok(qp_register_rest_route('t/v1', '/ok', ['methods' => 'GET', 'callback' => function () { return []; }, 'permission_callback' => 'qp_rest_permit_public']) === true, 'a complete route registers');
    ok(qp_rest_scope_allows(['posts:read'], 'posts:read') && qp_rest_scope_allows(['read'], 'posts:read') && qp_rest_scope_allows(['*'], 'x:write') && qp_rest_scope_allows(['posts:*'], 'posts:write') && !qp_rest_scope_allows(['read'], 'posts:write') && !qp_rest_scope_allows(['pages:read'], 'posts:read'), 'scope matching');
    ok(qp_rest_valid_scope('posts:read') && qp_rest_valid_scope('*') && !qp_rest_valid_scope('posts:delete') && !qp_rest_valid_scope('a b'), 'scope syntax');
    $req = new QP_REST_Request(); $req->params = ['n' => '5', 'b' => 'yes', 'e' => 'zz', 'list' => '1,2,3', 's' => str_repeat('x', 30)];
    $errs = qp_rest_validate_args($req, ['n' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 4], 'b' => ['type' => 'boolean'], 'e' => ['type' => 'string', 'enum' => ['a']], 'list' => ['type' => 'array', 'items' => ['type' => 'integer']], 's' => ['type' => 'string', 'maxLength' => 10], 'req' => ['type' => 'string', 'required' => true], 'd' => ['default' => 7]]);
    ok(isset($errs['n'], $errs['e'], $errs['s'], $errs['req']) && !isset($errs['b'], $errs['list']) && $req->params['b'] === true && $req->params['list'] === [1, 2, 3] && $req->params['d'] === 7, 'validation: bounds, enum, length, required, coercion, default');

    echo "-- JWT rules\n";
    $s = qp_rest_settings(true);
    $now = time();
    $claims = ['iss' => qp_jwt_issuer(), 'aud' => qp_jwt_audience(), 'sub' => (string)$uid, 'iat' => $now, 'nbf' => $now, 'exp' => $now + 600, 'jti' => 'x', 'scp' => ['read'], 'fam' => 'f', 'pv' => 'p'];
    $good = qp_jwt_encode($claims);
    ok(qp_jwt_decode($good) !== null, 'a token we signed verifies');
    [$h64, $p64, $s64] = explode('.', $good);
    $none = qp_jwt_b64(json_encode(['alg' => 'none', 'typ' => 'JWT', 'kid' => $s['jwt_kid']])) . '.' . $p64 . '.';
    ok(qp_jwt_decode($none) === null, 'alg "none" is refused');
    $hs512 = qp_jwt_b64(json_encode(['alg' => 'HS512', 'typ' => 'JWT', 'kid' => $s['jwt_kid']])) . '.' . $p64 . '.' . $s64;
    ok(qp_jwt_decode($hs512) === null, 'a different algorithm in the header is refused (the server fixes HS256)');
    ok(qp_jwt_decode($h64 . '.' . qp_jwt_b64(json_encode(array_merge($claims, ['sub' => '999']))) . '.' . $s64) === null, 'a tampered payload is refused');
    ok(qp_jwt_decode(qp_jwt_encode(array_merge($claims, ['exp' => $now - 600]))) === null, 'an expired token is refused');
    ok(qp_jwt_decode(qp_jwt_encode(array_merge($claims, ['nbf' => $now + 600]))) === null, 'a not-yet-valid token is refused');
    ok(qp_jwt_decode(qp_jwt_encode(array_merge($claims, ['aud' => 'https://other.test/api']))) === null && qp_jwt_decode(qp_jwt_encode(array_merge($claims, ['iss' => 'https://other.test']))) === null, 'wrong issuer or audience is refused');
    $kidOther = qp_jwt_b64(json_encode(['alg' => 'HS256', 'typ' => 'JWT', 'kid' => 'zzz'])) . '.' . $p64 . '.' . qp_jwt_b64(hash_hmac('sha256', qp_jwt_b64(json_encode(['alg' => 'HS256', 'typ' => 'JWT', 'kid' => 'zzz'])) . '.' . $p64, qp_jwt_key('zzz'), true));
    ok(qp_jwt_decode($kidOther) === null, 'a validly signed token with an unknown key id is refused');
    ok(qp_jwt_decode(str_repeat('a', 5000)) === null && qp_jwt_decode('a.b') === null, 'junk is refused');

    echo "-- HTTPS rule and SSRF checks\n";
    $save = [$_SERVER['HTTPS'] ?? null, $_SERVER['REMOTE_ADDR'] ?? null, $_SERVER['SERVER_PORT'] ?? null];
    unset($_SERVER['HTTPS']); $_SERVER['SERVER_PORT'] = 80; $_SERVER['REMOTE_ADDR'] = '203.0.113.9';
    ok(qp_rest_https_required_error() instanceof QP_REST_Error, 'credentials over plain http from a remote address are refused');
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    ok(qp_rest_https_required_error() === null, 'plain http from the loopback is allowed');
    $_SERVER['HTTPS'] = 'on'; $_SERVER['REMOTE_ADDR'] = '203.0.113.9';
    ok(qp_rest_https_required_error() === null, 'https is accepted');
    foreach (['HTTPS', 'REMOTE_ADDR', 'SERVER_PORT'] as $i => $k) { if ($save[$i] === null) unset($_SERVER[$k]); else $_SERVER[$k] = $save[$i]; }
    update_option_meta('qp_rest_settings', array_merge(qp_rest_defaults(), ['counter_epoch' => $EPOCH], ['webhook_allow_private' => false])); qp_rest_settings(true);
    ok(!qp_webhook_url_check('http://example.com/h')[0], 'webhook: plain http refused');
    ok(!qp_webhook_url_check('https://127.0.0.1/h')[0] && !qp_webhook_url_check('https://10.0.0.5/h')[0] && !qp_webhook_url_check('https://192.168.1.1/h')[0] && !qp_webhook_url_check('https://169.254.169.254/latest')[0] && !qp_webhook_url_check('https://[::1]/h')[0], 'webhook: loopback, private, link-local (cloud metadata) and IPv6 loopback refused');
    ok(!qp_webhook_url_check('https://user:pw@example.com/h')[0] && !qp_webhook_url_check('ftp://example.com/h')[0] && !qp_webhook_url_check('not a url')[0], 'webhook: credentials in the address, other schemes and junk refused');

    // ================= HTTP =================
    echo "-- discovery and public reads\n";
    update_option_meta('qp_rest_settings', array_merge(qp_rest_defaults(), ['counter_epoch' => $EPOCH], ['keys' => true, 'app_passwords' => true, 'jwt' => true, 'cors_origins' => ['https://app.example.test'], 'rate_key' => 600])); qp_rest_settings(true);
    $r = api('GET', '/qlopy/v1/');
    ok($r['code'] === 200 && ($r['j']['api_version'] ?? '') === '1' && in_array('/qlopy/v1/posts', $r['j']['routes'] ?? [], true) && ($r['j']['authentication']['jwt'] ?? false), 'discovery document lists routes and enabled schemes');
    ok(strpos($r['h']['content-type'] ?? '', 'application/json') === 0 && ($r['h']['x-content-type-options'] ?? '') === 'nosniff' && !isset($r['h']['set-cookie']), 'JSON, nosniff, and no cookie is set');
    $r = api('GET', '/qlopy/v1/openapi.json');
    ok($r['code'] === 200 && ($r['j']['openapi'] ?? '') === '3.0.3' && isset($r['j']['paths']['/qlopy/v1/posts']['get'], $r['j']['paths']['/qlopy/v1/posts/{id}']['patch']) && isset($r['j']['components']['securitySchemes']['bearerAuth']), 'OpenAPI export has paths, methods and security schemes');
    $paths = $r['j']['paths'] ?? [];
    $missing = [];
    foreach ($paths as $p => $ops) foreach ($ops as $m => $op) if ($p !== '/qlopy/v1/' && strpos($p, 'openapi') === false && $p !== '/qlopy/v1/auth/token' && strpos($p, '/oauth/') === false && empty($op['security']) && !in_array($p . ' ' . $m, ['/qlopy/v1/posts get', '/qlopy/v1/posts/{id} get', '/qlopy/v1/pages get', '/qlopy/v1/pages/{id} get', '/qlopy/v1/terms get', '/qlopy/v1/terms/{id} get'], true)) $missing[] = "$p $m";
    ok(!$missing, 'every non-public route in the export declares security' . ($missing ? ': ' . implode(', ', $missing) : ''));
    $r = api('GET', '/qlopy/v1/posts?per_page=1&fields=id,title');
    ok($r['code'] === 200 && isset($r['h']['x-total'], $r['h']['x-total-pages'], $r['h']['link']) && is_array($r['j']) && array_keys($r['j'][0] ?? []) === ['id', 'title'], 'public list: pagination headers, field selection');
    $etag = $r['h']['etag'] ?? '';
    $r2 = api('GET', '/qlopy/v1/posts?per_page=1&fields=id,title', ['h' => ['If-None-Match: ' . $etag]]);
    ok($etag !== '' && $r2['code'] === 304 && $r2['body'] === '', 'ETag and If-None-Match give 304');
    $r = api('GET', '/qlopy/v1/posts?status=draft');
    ok($r['code'] === 401 && errcode($r) === 'unauthorized', 'drafts need credentials (401, one error shape): ' . $r['body']);
    $r = api('GET', '/qlopy/v1/posts?per_page=500&orderby=bogus');
    ok($r['code'] === 400 && errcode($r) === 'invalid_params' && isset($r['j']['error']['fields']['per_page'], $r['j']['error']['fields']['orderby']), 'validation errors list the fields');
    ok(api('GET', '/qlopy/v1/nope')['code'] === 404 && errcode(api('GET', '/qlopy/v1/nope')) === 'route_not_found', 'unknown route -> 404 in the error shape');
    $r = api('DELETE', '/qlopy/v1/options');
    ok($r['code'] === 405 && isset($r['h']['allow']), '405 carries Allow');
    $r = api('GET', '/qlopy/v1/users');
    ok($r['code'] === 401, 'users list needs sign-in');
    $r = api('GET', '/qlopy/v1/posts/999999');
    ok($r['code'] === 404, 'missing post -> 404');
    $r = api('HEAD', '/qlopy/v1/posts');
    ok($r['code'] === 200 && $r['body'] === '', 'HEAD works and has no body');

    echo "-- CORS\n";
    $r = api('OPTIONS', '/qlopy/v1/posts', ['h' => ['Origin: https://app.example.test', 'Access-Control-Request-Method: POST']]);
    ok($r['code'] === 204 && ($r['h']['access-control-allow-origin'] ?? '') === 'https://app.example.test' && ($r['h']['access-control-allow-credentials'] ?? '') === 'true' && stripos($r['h']['access-control-allow-headers'] ?? '', 'Authorization') !== false, 'preflight from a listed origin is answered with credentials allowed');
    $r = api('OPTIONS', '/qlopy/v1/posts', ['h' => ['Origin: https://evil.example.test', 'Access-Control-Request-Method: POST']]);
    ok($r['code'] === 204 && !isset($r['h']['access-control-allow-origin']), 'preflight from an unlisted origin gets no CORS headers');
    $r = api('GET', '/qlopy/v1/posts?per_page=1', ['h' => ['Origin: https://app.example.test']]);
    ok(($r['h']['access-control-allow-origin'] ?? '') === 'https://app.example.test' && stripos($r['h']['vary'] ?? '', 'Origin') !== false, 'a normal request from a listed origin gets the header and Vary: Origin');
    $r = api('GET', '/qlopy/v1/posts?per_page=1', ['h' => ['Origin: https://evil.example.test']]);
    ok(!isset($r['h']['access-control-allow-origin']), 'an unlisted origin gets no header');

    echo "-- cookie session + nonce\n";
    $nonceRes = api('GET', '/qlopy/v1/auth/nonce', ['cookie' => $cookie]);
    $nonce = $nonceRes['j']['nonce'] ?? '';
    ok($nonceRes['code'] === 200 && $nonce !== '' && ($nonceRes['h']['cache-control'] ?? '') === 'no-store', 'the nonce endpoint serves a no-store nonce to a cookie session');
    $r = api('GET', '/qlopy/v1/users/me', ['cookie' => $cookie]);
    ok($r['code'] === 200 && ($r['j']['auth_scheme'] ?? '') === 'cookie' && !isset($r['j']['password_hash']) && !isset($r['j']['raw']), 'users/me works with the cookie, no hashes in the reply');
    $r = api('POST', '/qlopy/v1/posts', ['cookie' => $cookie, 'json' => ['title' => 'REST test post']]);
    ok($r['code'] === 403 && errcode($r) === 'bad_nonce', 'a cookie write without a nonce is refused');
    $r = api('POST', '/qlopy/v1/posts', ['cookie' => $cookie, 'h' => ['X-QP-Nonce: ' . $nonce, 'Origin: https://evil.example.test'], 'json' => ['title' => 'REST test post']]);
    ok($r['code'] === 403 && errcode($r) === 'cross_origin_cookie', 'a cookie session from another origin is refused');
    $r = api('POST', '/qlopy/v1/posts', ['cookie' => $cookie, 'h' => ['X-QP-Nonce: ' . $nonce], 'json' => ['title' => 'REST test post', 'content' => '<p>Hello <script>alert(1)</script></p>', 'status' => 'draft']]);
    $pid = (int)($r['j']['id'] ?? 0); if ($pid) $createdPosts[] = $pid;
    ok($r['code'] === 201 && $pid > 0 && !empty($r['h']['location']) && ($r['j']['status'] ?? '') === 'draft', 'create a draft with cookie + nonce: 201 and Location');
    $r = api('GET', "/qlopy/v1/posts/$pid");
    ok($r['code'] === 404, 'the draft is invisible to anonymous readers (404, not 403)');
    $r = api('GET', "/qlopy/v1/posts/$pid", ['cookie' => $cookie]);
    ok($r['code'] === 200 && ($r['j']['title'] ?? '') === 'REST test post', 'the author sees the draft');
    $r = api('PATCH', "/qlopy/v1/posts/$pid", ['cookie' => $cookie, 'h' => ['X-QP-Nonce: ' . $nonce], 'json' => ['title' => 'REST test post 2', 'status' => 'published']]);
    ok($r['code'] === 200 && $r['j']['title'] === 'REST test post 2' && $r['j']['status'] === 'published' && !empty($r['j']['published_at']), 'PATCH changes title and publishes');
    $r = api('GET', "/qlopy/v1/posts/$pid");
    ok($r['code'] === 200, 'once published it is public');
    $r = api('POST', '/qlopy/v1/posts', ['cookie' => $cookie, 'h' => ['X-QP-Nonce: ' . $nonce], 'body' => '{"title": "x"', 'h2' => []]);
    ok(in_array($r['code'], [400, 415], true), 'a body that is not JSON is refused');
    $r = api('POST', '/qlopy/v1/posts', ['cookie' => $cookie, 'h' => ['X-QP-Nonce: ' . $nonce, 'Content-Type: application/json'], 'body' => '{"title": "x"']);
    ok($r['code'] === 400 && errcode($r) === 'invalid_json', 'broken JSON -> invalid_json');
    $r = api('POST', '/qlopy/v1/posts', ['cookie' => $cookie, 'h' => ['X-QP-Nonce: ' . $nonce, 'Content-Type: text/plain'], 'body' => 'title=x']);
    ok($r['code'] === 415, 'non-JSON content type -> 415');
    $r = api('POST', '/qlopy/v1/posts', ['cookie' => $cookie, 'h' => ['X-QP-Nonce: ' . $nonce, 'Content-Type: application/json'], 'body' => str_repeat(' ', 1048577) . '{}']);
    ok($r['code'] === 413, 'a body over the limit -> 413');
    $deep = str_repeat('[', 40) . str_repeat(']', 40);
    $r = api('POST', '/qlopy/v1/posts', ['cookie' => $cookie, 'h' => ['X-QP-Nonce: ' . $nonce, 'Content-Type: application/json'], 'body' => '{"title":"x","terms":' . $deep . '}']);
    ok($r['code'] === 400, 'JSON nested deeper than 32 levels is refused');

    echo "-- idempotency\n";
    $key = 'idem-' . bin2hex(random_bytes(6));
    $body = ['title' => 'Idempotent post', 'status' => 'draft'];
    $a = api('POST', '/qlopy/v1/posts', ['cookie' => $cookie, 'h' => ['X-QP-Nonce: ' . $nonce, 'Idempotency-Key: ' . $key], 'json' => $body]);
    $b = api('POST', '/qlopy/v1/posts', ['cookie' => $cookie, 'h' => ['X-QP-Nonce: ' . $nonce, 'Idempotency-Key: ' . $key], 'json' => $body]);
    if (!empty($a['j']['id'])) $createdPosts[] = (int)$a['j']['id'];
    ok($a['code'] === 201 && $b['code'] === 201 && ($a['j']['id'] ?? 1) === ($b['j']['id'] ?? 2) && ($b['h']['idempotency-replayed'] ?? '') === 'true', 'the same key replays the same reply and creates one post');
    $c = api('POST', '/qlopy/v1/posts', ['cookie' => $cookie, 'h' => ['X-QP-Nonce: ' . $nonce, 'Idempotency-Key: ' . $key], 'json' => ['title' => 'Different body']]);
    ok($c['code'] === 422 && errcode($c) === 'idempotency_mismatch', 'the same key with another body is refused');
    $n = (int)$pdo->query("SELECT COUNT(*) FROM " . table_name('posts') . " WHERE title = 'Idempotent post'")->fetchColumn();
    ok($n === 1, 'exactly one row exists');

    echo "-- terms\n";
    $r = api('POST', '/qlopy/v1/terms', ['cookie' => $cookie, 'h' => ['X-QP-Nonce: ' . $nonce], 'json' => ['taxonomy' => 'category', 'name' => 'REST Test Cat ' . random_int(1000, 9999)]]);
    $tid = (int)($r['j']['id'] ?? 0); if ($tid) $createdTerms[] = $tid;
    ok($r['code'] === 201 && $tid > 0, 'create a term');
    $r = api('PATCH', "/qlopy/v1/posts/$pid", ['cookie' => $cookie, 'h' => ['X-QP-Nonce: ' . $nonce], 'json' => ['terms' => ['category' => [$tid, 999999]]]]);
    ok($r['code'] === 200 && ($r['j']['terms']['category'] ?? []) === [$tid], 'terms attach to a post; an unknown term id is ignored');
    $r = api('GET', '/qlopy/v1/terms?taxonomy=category&per_page=100');
    ok($r['code'] === 200 && in_array($tid, array_column($r['j'], 'id'), true), 'the term is listed publicly');
    $r = api('GET', '/qlopy/v1/terms?taxonomy=nope');
    ok($r['code'] === 400, 'an unknown taxonomy is a validation error');
    $r = api('DELETE', "/qlopy/v1/terms/$tid", ['cookie' => $cookie, 'h' => ['X-QP-Nonce: ' . $nonce]]);
    ok($r['code'] === 200 && ($r['j']['deleted'] ?? false), 'delete a term');
    $createdTerms = [];

    echo "-- API keys\n";
    $k1 = qp_api_key_create($uid, 'test read', ['posts:read', 'users:read']);
    $k2 = qp_api_key_create($uid, 'test write', ['posts:write', 'posts:read']);
    $k3 = qp_api_key_create($uid, 'limited', ['read'], 3);
    $r = api('GET', '/qlopy/v1/users/me', ['h' => ['X-API-Key: ' . $k1['key']]]);
    ok($r['code'] === 200 && ($r['j']['auth_scheme'] ?? '') === 'apikey', 'a key works in X-API-Key');
    $r = api('GET', '/qlopy/v1/users/me', ['h' => ['Authorization: ApiKey ' . $k1['key']]]);
    ok($r['code'] === 200, 'and as Authorization: ApiKey');
    $r = api('POST', '/qlopy/v1/posts', ['h' => ['X-API-Key: ' . $k1['key']], 'json' => ['title' => 'nope']]);
    ok($r['code'] === 403 && errcode($r) === 'insufficient_scope', 'a read-only key cannot write');
    $r = api('GET', '/qlopy/v1/terms?taxonomy=category', ['h' => ['X-API-Key: ' . $k1['key']]]);
    ok($r['code'] === 403 && errcode($r) === 'insufficient_scope', 'a key without terms:read is refused even on a route that is public without credentials');
    $r = api('POST', '/qlopy/v1/posts', ['h' => ['X-API-Key: ' . $k2['key']], 'json' => ['title' => 'Key post', 'status' => 'draft']]);
    if (!empty($r['j']['id'])) $createdPosts[] = (int)$r['j']['id'];
    ok($r['code'] === 201, 'a write key creates a post (no nonce needed for keys)');
    $r = api('GET', '/qlopy/v1/users/me', ['h' => ['X-API-Key: ' . substr($k1['key'], 0, -1) . 'x']]);
    ok($r['code'] === 401 && errcode($r) === 'invalid_credentials', 'a wrong secret -> 401 invalid_credentials');
    $r2 = api('GET', '/qlopy/v1/users/me', ['h' => ['X-API-Key: qpk_' . $uid . '.' . str_repeat('0', 8) . '.' . str_repeat('A', 40)]]);
    ok($r2['code'] === 401 && $r2['j'] === $r['j'], 'an unknown key id gives the identical error (does not say which part was wrong)');
    $codes = []; for ($i = 0; $i < 5; $i++) { $x = api('GET', '/qlopy/v1/posts?per_page=1', ['h' => ['X-API-Key: ' . $k3['key']]]); $codes[] = $x['code']; }
    ok($codes === [200, 200, 200, 429, 429] && !empty($x['h']['retry-after']), 'per-key rate limit (3/min): ' . implode(',', $codes));
    qp_api_key_revoke($uid, $k1['id']);
    ok(api('GET', '/qlopy/v1/users/me', ['h' => ['X-API-Key: ' . $k1['key']]])['code'] === 401, 'a revoked key stops working at once');
    $stored = get_user_meta($uid, 'qp_api_keys');
    ok(!str_contains(json_encode($stored), substr($k2['key'], -40)), 'the secret is not stored, only its hash');
    $keysOff = array_merge(qp_rest_defaults(), ['counter_epoch' => $EPOCH], ['keys' => false, 'app_passwords' => true, 'jwt' => true]);
    update_option_meta('qp_rest_settings', $keysOff);
    $r = api('GET', '/qlopy/v1/users/me', ['h' => ['X-API-Key: ' . $k2['key']]]);
    ok($r['code'] === 403 && errcode($r) === 'api_disabled', 'with the scheme switched off a key is refused with api_disabled');
    update_option_meta('qp_rest_settings', array_merge(qp_rest_defaults(), ['counter_epoch' => $EPOCH], ['keys' => true, 'app_passwords' => true, 'jwt' => true, 'cors_origins' => ['https://app.example.test']]));

    echo "-- application passwords (Basic)\n";
    $uname = $pdo->query("SELECT username FROM " . table_name('users') . " WHERE id = " . $uid)->fetchColumn();
    $ap = qp_app_password_create($uid, 'test app');
    $r = api('GET', '/qlopy/v1/users/me', ['user' => $uname . ':' . $ap['password']]);
    ok($r['code'] === 200 && ($r['j']['auth_scheme'] ?? '') === 'basic', 'username + application password (with its spaces) works');
    $r = api('GET', '/qlopy/v1/users/me', ['user' => $uname . ':wrong-password']);
    ok($r['code'] === 401, 'a wrong password is refused');
    $r = api('GET', '/qlopy/v1/users/me', ['user' => 'no-such-user-xyz:' . $ap['password']]);
    ok($r['code'] === 401, 'an unknown user is refused');
    qp_app_password_revoke($uid, $ap['id']);
    ok(api('GET', '/qlopy/v1/users/me', ['user' => $uname . ':' . $ap['password']])['code'] === 401, 'a revoked application password stops working');

    echo "-- JWT sign-in, refresh rotation, logout\n";
    $ap2 = qp_app_password_create($uid, 'jwt test');
    $r = api('POST', '/qlopy/v1/auth/token', ['json' => ['grant_type' => 'password', 'username' => $uname, 'password' => 'definitely-wrong']]);
    ok($r['code'] === 400 && errcode($r) === 'invalid_grant', 'a wrong password: invalid_grant');
    $r = api('POST', '/qlopy/v1/auth/token', ['json' => ['grant_type' => 'password', 'username' => $uname, 'password' => $ap2['password'], 'scope' => 'read posts:write']]);
    $pair = $r['j'] ?? [];
    ok($r['code'] === 200 && !empty($pair['access_token']) && !empty($pair['refresh_token']) && ($pair['token_type'] ?? '') === 'Bearer' && ($r['h']['cache-control'] ?? '') === 'no-store', 'an application password gives a token pair (no-store)');
    $r = api('GET', '/qlopy/v1/users/me', ['h' => ['Authorization: Bearer ' . $pair['access_token']]]);
    ok($r['code'] === 200 && ($r['j']['auth_scheme'] ?? '') === 'bearer' && ($r['j']['scopes'] ?? []) === ['read', 'posts:write'], 'the access token works and carries its scopes');
    $r = api('POST', '/qlopy/v1/pages', ['h' => ['Authorization: Bearer ' . $pair['access_token']], 'json' => ['title' => 'x']]);
    ok($r['code'] === 403 && errcode($r) === 'insufficient_scope', 'a token cannot step outside its scopes');
    $r = api('POST', '/qlopy/v1/posts', ['h' => ['Authorization: Bearer ' . $pair['access_token']], 'json' => ['title' => 'Token post', 'status' => 'draft']]);
    if (!empty($r['j']['id'])) $createdPosts[] = (int)$r['j']['id'];
    ok($r['code'] === 201, 'and can do what its scope allows');
    $r1 = api('POST', '/qlopy/v1/auth/token', ['json' => ['grant_type' => 'refresh_token', 'refresh_token' => $pair['refresh_token']]]);
    $pair2 = $r1['j'] ?? [];
    ok($r1['code'] === 200 && !empty($pair2['refresh_token']) && $pair2['refresh_token'] !== $pair['refresh_token'], 'a refresh token gives a new pair and a new refresh token');
    $r = api('GET', '/qlopy/v1/users/me', ['h' => ['Authorization: Bearer ' . $pair2['access_token']]]);
    ok($r['code'] === 200, 'the new access token works');
    $r = api('POST', '/qlopy/v1/auth/token', ['json' => ['grant_type' => 'refresh_token', 'refresh_token' => $pair['refresh_token']]]);
    ok($r['code'] === 400, 'reusing the old refresh token fails');
    $r = api('GET', '/qlopy/v1/users/me', ['h' => ['Authorization: Bearer ' . $pair2['access_token']]]);
    ok($r['code'] === 401, 'and the reuse ended the whole family: the newest access token no longer works');
    $r = api('POST', '/qlopy/v1/auth/token', ['json' => ['grant_type' => 'refresh_token', 'refresh_token' => $pair2['refresh_token']]]);
    ok($r['code'] === 400, 'the newest refresh token is dead too');
    $r = api('POST', '/qlopy/v1/auth/token', ['json' => ['grant_type' => 'password', 'username' => $uname, 'password' => $ap2['password']]]);
    $pair3 = $r['j'] ?? [];
    $r = api('POST', '/qlopy/v1/auth/logout', ['h' => ['Authorization: Bearer ' . ($pair3['access_token'] ?? 'x')], 'body' => '', 'json' => new stdClass()]);
    ok($r['code'] === 200, 'logout ends the session');
    ok(api('GET', '/qlopy/v1/users/me', ['h' => ['Authorization: Bearer ' . $pair3['access_token']]])['code'] === 401, 'the access token of a logged-out session is refused at once');
    $r = api('POST', '/qlopy/v1/auth/token', ['json' => ['grant_type' => 'password', 'username' => $uname, 'password' => $ap2['password']]]);
    $pair4 = $r['j'] ?? [];
    $uRow = qp_rest_user_row($uid, true);
    $pdo->prepare("UPDATE " . table_name('users') . " SET password_hash = ? WHERE id = ?")->execute([$uRow['password_hash'] . 'x', $uid]);
    $r = api('GET', '/qlopy/v1/users/me', ['h' => ['Authorization: Bearer ' . $pair4['access_token']]]);
    $pdo->prepare("UPDATE " . table_name('users') . " SET password_hash = ? WHERE id = ?")->execute([$uRow['password_hash'], $uid]);
    ok($r['code'] === 401, 'a password change ends every token for that user');
    qp_app_password_revoke($uid, $ap2['id']);
    update_option_meta('qp_rest_settings', array_merge(qp_rest_defaults(), ['counter_epoch' => $EPOCH], ['keys' => true, 'jwt' => false]));
    ok(api('POST', '/qlopy/v1/auth/token', ['json' => ['grant_type' => 'password', 'username' => $uname, 'password' => 'x']])['code'] === 403, 'the token endpoint is off while JWT is off');

    echo "-- lockout\n";
    update_option_meta('qp_rest_settings', array_merge(qp_rest_defaults(), ['counter_epoch' => $EPOCH], ['keys' => true, 'app_passwords' => true, 'jwt' => true]));
    $pdo->exec("DELETE FROM " . table_name('site_options') . " WHERE 0");
    $codes = [];
    for ($i = 0; $i < 12; $i++) { $codes[] = api('GET', '/qlopy/v1/users/me', ['user' => 'lockout-test-user:bad' . $i])['code']; }
    ok(in_array(429, $codes, true) && $codes[0] === 401, 'ten failed sign-ins lock the address out (' . implode(',', array_slice($codes, 0, 12)) . ')');
    // the lock lives for 15 minutes: start every counter from zero (the same thing Settings > API > Reset limits does)
    $EPOCH++; $cur = qp_rest_settings(true); $cur['counter_epoch'] = $EPOCH; update_option_meta('qp_rest_settings', $cur); qp_rest_settings(true);

    echo "-- webhooks\n";
    update_option_meta('qp_rest_settings', array_merge(qp_rest_defaults(), ['counter_epoch' => $EPOCH], ['webhook_allow_private' => true])); qp_rest_settings(true);
    $recvDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'qp-recv-' . bin2hex(random_bytes(4)); mkdir($recvDir);
    file_put_contents($recvDir . '/r.php', '<?php $b = file_get_contents("php://input"); file_put_contents(__DIR__ . "/log.jsonl", json_encode(["h" => ["sig" => $_SERVER["HTTP_X_QP_SIGNATURE"] ?? "", "ev" => $_SERVER["HTTP_X_QP_EVENT"] ?? "", "id" => $_SERVER["HTTP_X_QP_DELIVERY"] ?? ""], "b" => $b]) . "\n", FILE_APPEND); $mode = @file_get_contents(__DIR__ . "/mode"); if ($mode === "fail") { http_response_code(500); echo "no"; } else { echo "ok"; }');
    $rport = random_int(20000, 40000);
    $proc = proc_open([PHP_BINARY, '-S', "127.0.0.1:$rport", '-t', $recvDir, $recvDir . '/r.php'], [1 => ['file', $recvDir . '/out', 'w'], 2 => ['file', $recvDir . '/err', 'w']], $pipes);
    $receiver = ['proc' => $proc];
    usleep(700000);
    [$wid, $werr] = qp_webhook_subscribe("http://127.0.0.1:$rport/r.php", ['post.saved', 'term.created', 'bogus.event']);
    ok($wid && !$werr, 'subscribe to a local receiver (private addresses allowed for this run)');
    $subs = qp_webhook_subscriptions(); $secret = $subs[$wid]['secret'];
    ok(($subs[$wid]['events'] ?? []) === ['post.saved', 'term.created'] && strpos($secret, 'whsec_') === 0, 'unknown events are dropped; a secret was generated');
    $n = qp_webhook_emit('post.saved', ['id' => 1, 'title' => 'Hi']);
    ok($n === 1 && qp_webhook_emit('post.deleted', ['id' => 1]) === 0, 'only subscribed events are queued');
    $res = qp_webhook_flush();
    $lines = file_exists($recvDir . '/log.jsonl') ? array_filter(explode("\n", (string)file_get_contents($recvDir . '/log.jsonl'))) : [];
    $got = $lines ? json_decode($lines[0], true) : null;
    ok($got && $got['h']['ev'] === 'post.saved', 'the receiver got the event');
    if ($got) {
        preg_match('/t=(\d+),v1=([0-9a-f]{64})/', $got['h']['sig'], $m);
        ok(!empty($m) && hash_equals(hash_hmac('sha256', $m[1] . '.' . $got['b'], $secret), $m[2]) && abs(time() - (int)$m[1]) < 60, 'the signature verifies with the secret and the timestamp is fresh');
        $payload = json_decode($got['b'], true);
        ok(($payload['event'] ?? '') === 'post.saved' && ($payload['data']['id'] ?? 0) === 1 && ($payload['id'] ?? '') === $got['h']['id'], 'the body has id, event and data, and the delivery id matches the header');
    }
    $log = get_option_meta('qp_webhook_log');
    ok(is_array($log) && ($log[0]['status'] ?? '') === 'ok' && ($log[0]['code'] ?? 0) === 200, 'the delivery log records the success');
    file_put_contents($recvDir . '/mode', 'fail');
    qp_webhook_emit('term.created', ['id' => 5, 'taxonomy' => 'category']);
    qp_webhook_flush();
    $log = get_option_meta('qp_webhook_log');
    $q = json_decode((string)file_get_contents(qp_webhook_queue_file()), true);
    ok(($log[0]['status'] ?? '') === 'retry' && ($log[0]['code'] ?? 0) === 500 && count($q) === 1 && $q[0]['attempt'] === 1 && $q[0]['next'] > time() + 30, 'a 500 answer is logged as retry and queued for a later attempt');
    $subs = qp_webhook_subscriptions();
    ok(($subs[$wid]['fails'] ?? 0) === 1, 'the failure is counted on the subscription');
    // push it through all retries
    for ($i = 0; $i < 6; $i++) { qp_webhook_queue_update(function ($q) { foreach ($q as $k => $it) $q[$k]['next'] = time() - 1; return $q; }); qp_webhook_flush(); }
    $q = json_decode((string)file_get_contents(qp_webhook_queue_file()), true);
    $log = get_option_meta('qp_webhook_log');
    ok(!$q && ($log[0]['status'] ?? '') === 'failed', 'after the last retry the delivery is dropped and logged as failed');
    file_put_contents($recvDir . '/mode', 'ok');
    $subs = qp_webhook_subscriptions(); $subs[$wid]['active'] = true; $subs[$wid]['fails'] = 0; qp_webhook_save_subscriptions($subs);
    [$code, $err] = qp_webhook_test($wid);
    ok($code === 200, 'the test button sends a signed ping');
    // hooks: a real save_post
    qp_webhooks_boot();
    do_action('save_post', $pid);
    $q = json_decode((string)file_get_contents(qp_webhook_queue_file()), true);
    ok(count($q) >= 1 && $q[count($q) - 1]['event'] === 'post.saved' && ($q[count($q) - 1]['data']['id'] ?? 0) === $pid, 'the save_post hook queues post.saved with the post id');
    qp_webhook_unsubscribe($wid);
    ok(qp_webhook_emit('post.saved', ['id' => 1]) === 0, 'no subscription, nothing queued');

    echo "-- media upload and delete\n";
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
    $h = ['X-QP-Nonce: ' . $nonce];
    $r = api('POST', '/qlopy/v1/media', ['cookie' => $cookie, 'h' => $h, 'json' => ['filename' => 'rest-test.png', 'content_base64' => base64_encode($png), 'title' => 'REST upload test']]);
    $mid = (int)($r['j']['id'] ?? 0); if ($mid) $createdPosts[] = $mid;
    ok($r['code'] === 201 && $mid > 0 && ($r['j']['title'] ?? '') === 'REST upload test' && !empty($r['j']['url']), 'JSON base64 upload creates a media item with its url');
    $r = api('POST', '/qlopy/v1/media', ['cookie' => $cookie, 'h' => $h, 'json' => ['filename' => 'bad.png', 'content_base64' => '***not base64***']]);
    ok($r['code'] === 400, 'invalid base64 is refused');
    $r = api('POST', '/qlopy/v1/media', ['cookie' => $cookie, 'h' => $h, 'json' => ['filename' => 'evil.php', 'content_base64' => base64_encode('<?php echo 1;')]]);
    ok($r['code'] === 422, 'a PHP file is rejected by the MIME allow-list (' . ($r['j']['error']['message'] ?? '') . ')');
    $tmpPng = tempnam(sys_get_temp_dir(), 'qpt'); file_put_contents($tmpPng, $png);
    $r = api('POST', '/qlopy/v1/media', ['cookie' => $cookie, 'h' => $h, 'body' => ['file' => new CURLFile($tmpPng, 'image/png', 'multipart-test.png'), 'title' => 'Multipart test']]);
    @unlink($tmpPng);
    $mid2 = (int)($r['j']['id'] ?? 0); if ($mid2) $createdPosts[] = $mid2;
    ok($r['code'] === 201 && $mid2 > 0, 'multipart upload works: ' . ($r['j']['error']['message'] ?? $r['code']));
    $r = api('POST', '/qlopy/v1/media', ['cookie' => $cookie, 'h' => $h, 'json' => new stdClass()]);
    ok($r['code'] === 400, 'an upload with no file is refused');
    $r = api('GET', "/qlopy/v1/media/$mid", ['cookie' => $cookie]);
    ok($r['code'] === 200, 'the upload can be read back');
    $r = api('GET', "/qlopy/v1/media/$mid");
    ok($r['code'] === 401, 'but not without credentials');
    $r = api('DELETE', "/qlopy/v1/media/$mid", ['cookie' => $cookie, 'h' => $h]);
    ok($r['code'] === 200 && ($r['j']['deleted'] ?? false), 'delete a media item');
    $r = api('DELETE', "/qlopy/v1/media/$mid2", ['cookie' => $cookie, 'h' => $h]);
    ok($r['code'] === 200, 'delete the multipart one too');
    $createdPosts = array_values(array_diff($createdPosts, [$mid, $mid2]));

    echo "-- user writes\n";
    $uname2 = 'resttest' . random_int(1000, 9999); $createdUsers = [];
    $r = api('POST', '/qlopy/v1/users', ['cookie' => $cookie, 'h' => $h, 'json' => ['username' => $uname2, 'email' => $uname2 . '@example.test', 'password' => 'short']]);
    ok($r['code'] === 400 && isset($r['j']['error']['fields']['password']), 'a short password is refused');
    $r = api('POST', '/qlopy/v1/users', ['cookie' => $cookie, 'h' => $h, 'json' => ['username' => $uname2, 'email' => 'not-an-email', 'password' => 'a-long-enough-password']]);
    ok($r['code'] === 400 && isset($r['j']['error']['fields']['email']), 'a bad email is refused');
    $r = api('POST', '/qlopy/v1/users', ['cookie' => $cookie, 'h' => $h, 'json' => ['username' => $uname2, 'email' => $uname2 . '@example.test', 'password' => 'a-long-enough-password', 'role' => 'subscriber', 'display_name' => 'Rest Tester']]);
    $nid = (int)($r['j']['id'] ?? 0); if ($nid) $createdUsers[] = $nid;
    ok($r['code'] === 201 && $nid > 0 && ($r['j']['role'] ?? '') === 'subscriber' && ($r['j']['display_name'] ?? '') === 'Rest Tester' && !isset($r['j']['password_hash']), 'create a subscriber; no hash in the reply');
    $r = api('POST', '/qlopy/v1/users', ['cookie' => $cookie, 'h' => $h, 'json' => ['username' => $uname2, 'email' => 'other@example.test', 'password' => 'a-long-enough-password']]);
    ok($r['code'] === 409, 'a duplicate username -> 409');
    $r = api('PATCH', "/qlopy/v1/users/$nid", ['cookie' => $cookie, 'h' => $h, 'json' => ['display_name' => 'Renamed', 'role' => 'editor']]);
    ok($r['code'] === 200 && $r['j']['display_name'] === 'Renamed' && $r['j']['role'] === 'editor', 'an administrator changes name and role');
    $r = api('PATCH', "/qlopy/v1/users/$uid", ['cookie' => $cookie, 'h' => $h, 'json' => ['role' => 'subscriber']]);
    ok($r['code'] === 403, 'nobody can change their own role');
    $r = api('PATCH', "/qlopy/v1/users/$uid", ['cookie' => $cookie, 'h' => $h, 'json' => ['password' => 'a-brand-new-password', 'current_password' => 'not-my-password']]);
    ok($r['code'] === 400 && isset($r['j']['error']['fields']['current_password']), 'changing your own password needs the current one');
    $r = api('DELETE', "/qlopy/v1/users/$uid", ['cookie' => $cookie, 'h' => $h]);
    ok($r['code'] === 403, 'you cannot delete yourself');
    // an editor key cannot make an administrator
    $ek = qp_api_key_create($nid, 'editor key', ['*']);
    $pdo->prepare("INSERT IGNORE INTO " . table_name('user_meta') . " (user_id, meta_key, meta_value) VALUES (?, 'x', '')")->execute([$nid]);
    $r = api('POST', '/qlopy/v1/users', ['h' => ['X-API-Key: ' . $ek['key']], 'json' => ['username' => 'x' . $uname2, 'email' => 'x' . $uname2 . '@example.test', 'password' => 'a-long-enough-password', 'role' => 'administrator']]);
    ok(in_array($r['code'], [401, 403], true), 'a user without manage_users cannot create users (HTTP ' . $r['code'] . ')');
    $r = api('DELETE', "/qlopy/v1/users/$nid", ['cookie' => $cookie, 'h' => $h]);
    ok($r['code'] === 200 && ($r['j']['deleted'] ?? false) && !qp_rest_user_row($nid) , 'delete the user');
    $createdUsers = [];
    echo "-- OAuth 2.0 authorization code + PKCE\n";
    ok(!qp_oauth_valid_redirect('javascript:alert(1)') && !qp_oauth_valid_redirect('data:text/html,x') && !qp_oauth_valid_redirect('http://evil.example.com/cb') && !qp_oauth_valid_redirect('https://a.test/cb#frag') && !qp_oauth_valid_redirect('file:///etc/passwd') && !qp_oauth_valid_redirect("https://a.test/cb\nLocation: x"), 'redirect validation refuses javascript:, data:, remote http, fragments, file:, header injection');
    ok(qp_oauth_valid_redirect('https://app.example.com/cb') && qp_oauth_valid_redirect('http://localhost:3000/cb') && qp_oauth_valid_redirect('http://127.0.0.1:8080/cb') && qp_oauth_valid_redirect('com.example.app:/oauth'), 'redirect validation accepts https, loopback http and app schemes');
    update_option_meta('qp_rest_settings', array_merge(qp_rest_defaults(), ['counter_epoch' => $EPOCH], ['keys' => true, 'jwt' => true, 'oauth' => false])); qp_rest_settings(true);
    $r = api('GET', '/qlopy/oauth/authorize', ['cookie' => $cookie]);
    ok($r['code'] === 403, 'the consent page is off until OAuth is switched on');
    update_option_meta('qp_rest_settings', array_merge(qp_rest_defaults(), ['counter_epoch' => $EPOCH], ['keys' => true, 'jwt' => true, 'oauth' => true])); qp_rest_settings(true);
    [$cid, $cerr] = qp_oauth_client_create('Test App', ['https://app.example.test/cb', 'http://localhost:5173/cb']);
    ok($cid && !$cerr, 'register a client');
    ok(qp_oauth_client_create('', ['https://a.test/cb'])[0] === null && qp_oauth_client_create('X', ['javascript:1'])[0] === null, 'a client needs a name and a valid redirect');
    $verifier = rtrim(strtr(base64_encode(random_bytes(40)), '+/', '-_'), '=');
    $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    $auth = ['response_type' => 'code', 'client_id' => $cid, 'redirect_uri' => 'https://app.example.test/cb', 'scope' => 'read posts:write', 'state' => 'xyz123', 'code_challenge' => $challenge, 'code_challenge_method' => 'S256'];
    $q = fn(array $o) => '/qlopy/oauth/authorize?' . http_build_query($o);
    $r = api('GET', $q(array_merge($auth, ['client_id' => 'qpc_nope'])), ['cookie' => $cookie]);
    ok($r['code'] === 400 && !isset($r['h']['location']), 'an unknown client: an error page, no redirect');
    $r = api('GET', $q(array_merge($auth, ['redirect_uri' => 'https://evil.example.test/cb'])), ['cookie' => $cookie]);
    ok($r['code'] === 400 && !isset($r['h']['location']), 'an unregistered redirect address: an error page, never a redirect to it');
    $r = api('GET', $q(array_diff_key($auth, ['code_challenge' => 1])), ['cookie' => $cookie]);
    ok($r['code'] === 302 && strpos($r['h']['location'] ?? '', 'error=invalid_request') !== false && strpos($r['h']['location'], 'state=xyz123') !== false, 'a missing PKCE challenge is sent back as invalid_request with the state');
    $r = api('GET', $q(array_merge($auth, ['code_challenge_method' => 'plain'])), ['cookie' => $cookie]);
    ok($r['code'] === 302 && strpos($r['h']['location'] ?? '', 'error=invalid_request') !== false, 'the plain method is refused');
    $r = api('GET', $q($auth));
    ok($r['code'] === 200 && strpos($r['body'], 'login.php?redirect_to=') !== false && strpos($r['body'], 'name="allow"') === false, 'not signed in: a sign-in link that returns here, no Allow button');
    ok(($r['h']['x-frame-options'] ?? '') === 'DENY' && stripos($r['h']['content-security-policy'] ?? '', 'frame-ancestors') !== false && !isset($r['h']['set-cookie']), 'the page cannot be framed and sets no cookie');
    $r = api('GET', $q($auth), ['cookie' => $cookie]);
    preg_match('/name="_n" value="([^"]+)"/', $r['body'], $nm);
    ok($r['code'] === 200 && strpos($r['body'], 'Test App') !== false && strpos($r['body'], 'name="allow"') !== false && !empty($nm[1]) && strpos($r['body'], 'Create and change') === false && strpos($r['body'], 'Change posts') !== false, 'signed in: the consent page names the app and lists the scopes');
    $post = array_merge($auth, ['_n' => $nm[1] ?? '', 'allow' => '1']);
    $r = api('POST', '/qlopy/oauth/authorize', ['cookie' => $cookie, 'form' => array_merge($post, ['_n' => 'bad'])]);
    ok($r['code'] === 403, 'Allow with a bad nonce is refused');
    $r = api('POST', '/qlopy/oauth/authorize', ['cookie' => $cookie, 'form' => array_merge($auth, ['_n' => $nm[1], 'deny' => '1'])]);
    ok($r['code'] === 302 && strpos($r['h']['location'] ?? '', 'error=access_denied') !== false, 'Deny goes back with access_denied');
    $r = api('POST', '/qlopy/oauth/authorize', ['cookie' => $cookie, 'form' => $post]);
    parse_str((string)parse_url($r['h']['location'] ?? '', PHP_URL_QUERY), $cb);
    ok($r['code'] === 302 && strpos($r['h']['location'], 'https://app.example.test/cb?') === 0 && !empty($cb['code']) && ($cb['state'] ?? '') === 'xyz123', 'Allow redirects to the registered address with a code and the state');
    $tok = fn(array $f) => api('POST', '/qlopy/v1/oauth/token', ['form' => $f]);
    $good = ['grant_type' => 'authorization_code', 'code' => $cb['code'] ?? '', 'redirect_uri' => 'https://app.example.test/cb', 'client_id' => $cid, 'code_verifier' => $verifier];
    $r = $tok(array_merge($good, ['code_verifier' => str_repeat('a', 50)]));
    ok($r['code'] === 400 && ($r['j']['error'] ?? '') === 'invalid_grant', 'a wrong code_verifier fails (RFC error body)');
    $cb2 = $cb;   // that failed attempt consumed the code: it can never be tried again
    $r = $tok($good);
    ok(($r['j']['error'] ?? '') === 'invalid_grant', 'a code that was tried once is gone');
    $r = api('POST', '/qlopy/oauth/authorize', ['cookie' => $cookie, 'form' => $post]);
    parse_str((string)parse_url($r['h']['location'] ?? '', PHP_URL_QUERY), $cb);
    $good['code'] = $cb['code'];
    $r = $tok(array_merge($good, ['redirect_uri' => 'http://localhost:5173/cb']));
    ok(($r['j']['error'] ?? '') === 'invalid_grant', 'a different redirect_uri fails');
    $r = api('POST', '/qlopy/oauth/authorize', ['cookie' => $cookie, 'form' => $post]);
    parse_str((string)parse_url($r['h']['location'] ?? '', PHP_URL_QUERY), $cb);
    $good['code'] = $cb['code'];
    $r = $tok(array_merge($good, ['client_id' => 'qpc_other']));
    ok($r['code'] === 401 && ($r['j']['error'] ?? '') === 'invalid_client', 'an unknown client at the token endpoint -> invalid_client');
    $r = $tok($good);
    $pairO = $r['j'] ?? [];
    ok($r['code'] === 200 && !empty($pairO['access_token']) && !empty($pairO['refresh_token']) && ($pairO['scope'] ?? '') === 'read posts:write' && ($r['h']['cache-control'] ?? '') === 'no-store', 'the right code and verifier give a token pair with the granted scopes');
    $r = $tok($good);
    ok(($r['j']['error'] ?? '') === 'invalid_grant', 'the same code cannot be used twice');
    $r = api('GET', '/qlopy/v1/users/me', ['h' => ['Authorization: Bearer ' . $pairO['access_token']]]);
    ok($r['code'] === 200 && ($r['j']['scopes'] ?? []) === ['read', 'posts:write'], 'the OAuth access token works, limited to what the user allowed');
    $r = api('POST', '/qlopy/v1/pages', ['h' => ['Authorization: Bearer ' . $pairO['access_token']], 'json' => ['title' => 'x']]);
    ok($r['code'] === 403, 'and nothing beyond that');
    $r = api('POST', '/qlopy/v1/auth/token', ['json' => ['grant_type' => 'refresh_token', 'refresh_token' => $pairO['refresh_token']]]);
    ok($r['code'] === 400, 'an OAuth refresh token does not work at the password-grant endpoint');
    $r = $tok(['grant_type' => 'refresh_token', 'refresh_token' => $pairO['refresh_token'], 'client_id' => 'qpc_other']);
    ok($r['code'] === 401, 'nor for another client');
    $r = $tok(['grant_type' => 'refresh_token', 'refresh_token' => $pairO['refresh_token'], 'client_id' => $cid]);
    $pairO2 = $r['j'] ?? [];
    ok($r['code'] === 200 && !empty($pairO2['refresh_token']) && $pairO2['refresh_token'] !== $pairO['refresh_token'], 'refresh through the OAuth endpoint rotates the refresh token');
    $r = api('POST', '/qlopy/v1/oauth/revoke', ['form' => ['token' => $pairO2['refresh_token'], 'client_id' => $cid]]);
    ok($r['code'] === 200, 'revocation answers 200');
    $r = $tok(['grant_type' => 'refresh_token', 'refresh_token' => $pairO2['refresh_token'], 'client_id' => $cid]);
    ok(($r['j']['error'] ?? '') === 'invalid_grant', 'a revoked refresh token is dead');
    ok(api('GET', '/qlopy/v1/users/me', ['h' => ['Authorization: Bearer ' . $pairO2['access_token']]])['code'] === 401, 'and so is the access token of that session');
    $r = api('POST', '/qlopy/v1/oauth/token', ['json' => $good]);
    ok($r['code'] === 415 || $r['code'] === 400, 'the token endpoint wants form encoding');
    $r = api('GET', '/qlopy/v1/');
    ok(!empty($r['j']['authentication']['oauth']['authorization_endpoint']) && ($r['j']['authentication']['oauth']['code_challenge_methods'] ?? []) === ['S256'], 'discovery advertises the OAuth endpoints');
    // removing a client ends its sessions
    $r = api('POST', '/qlopy/oauth/authorize', ['cookie' => $cookie, 'form' => $post]);
    parse_str((string)parse_url($r['h']['location'] ?? '', PHP_URL_QUERY), $cb);
    $pairO3 = $tok(array_merge($good, ['code' => $cb['code']]))['j'] ?? [];
    ok(api('GET', '/qlopy/v1/users/me', ['h' => ['Authorization: Bearer ' . ($pairO3['access_token'] ?? 'x')]])['code'] === 200, 'a fresh session works');
    qp_oauth_client_delete($cid);
    ok(api('GET', '/qlopy/v1/users/me', ['h' => ['Authorization: Bearer ' . ($pairO3['access_token'] ?? 'x')]])['code'] === 401, 'removing the client ends the sessions it started');
    update_option_meta('qp_rest_settings', array_merge(qp_rest_defaults(), ['counter_epoch' => $EPOCH], ['keys' => true, 'jwt' => true, 'oauth' => false])); qp_rest_settings(true);
    $r = api('POST', '/qlopy/v1/oauth/token', ['form' => ['grant_type' => 'refresh_token', 'client_id' => 'x', 'refresh_token' => 'y']]);
    ok($r['code'] === 403, 'with OAuth off the token endpoint is off');
    update_option_meta('qp_oauth_clients', []);
    echo "-- cleanup checks\n";
    $r = api('DELETE', "/qlopy/v1/posts/$pid", ['cookie' => $cookie, 'h' => ['X-QP-Nonce: ' . $nonce]]);
    ok($r['code'] === 200 && ($r['j']['status'] ?? '') === 'trash' && empty($r['j']['deleted']), 'DELETE moves a post to the trash');
    $r = api('DELETE', "/qlopy/v1/posts/$pid?force=true", ['cookie' => $cookie, 'h' => ['X-QP-Nonce: ' . $nonce]]);
    ok($r['code'] === 200 && ($r['j']['deleted'] ?? false) === true && !$pdo->query("SELECT COUNT(*) FROM " . table_name('posts') . " WHERE id = $pid")->fetchColumn(), 'force=true deletes it for good');
    $audit = qp_rest_audit();
    ok($audit === ['t/v1/open'], 'the route audit only lists the deliberately broken test route (core registered none without a permission callback)');
} catch (Throwable $e) {
    $fail++;
    echo 'FAIL exception: ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine() . "\n";
}
$cleanup();
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
