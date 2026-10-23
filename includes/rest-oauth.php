<?php
if (!defined('QLOPY_INIT')) { http_response_code(403); exit; }
// includes/rest-oauth.php
// OAuth 2.0 authorization code flow with PKCE (core upgrade plan C4-2) for third-party apps that act for a signed-in user.
// Public clients only (a mobile app, a single-page app, a desktop tool): there is no client secret, PKCE with S256 is required,
// redirect addresses must match a registered one exactly, and the access and refresh tokens are the same short-lived JWT and one-use
// rotating refresh tokens as the password grant (includes/rest-auth.php), so scopes, revocation and password-change rules are shared.
//
//   Authorize (a page the user sees):   GET  <site>/api/qlopy/oauth/authorize?response_type=code&client_id=..&redirect_uri=..&scope=..&state=..&code_challenge=..&code_challenge_method=S256
//   Token:                              POST <site>/api/qlopy/v1/oauth/token   (application/x-www-form-urlencoded)
//                                         grant_type=authorization_code&code=..&redirect_uri=..&client_id=..&code_verifier=..
//                                         grant_type=refresh_token&refresh_token=..&client_id=..
//   Revoke (RFC 7009):                  POST <site>/api/qlopy/v1/oauth/revoke  token=<refresh token>&client_id=..
// Clients are registered on Settings > API > Apps. Needs "Tokens (JWT)" and "OAuth apps" switched on there.

const QP_OAUTH_CLIENTS_OPTION = 'qp_oauth_clients';
const QP_OAUTH_CODE_TTL = 600;

function qp_oauth_clients(): array { $v = get_option_meta(QP_OAUTH_CLIENTS_OPTION); return is_array($v) ? $v : []; }
function qp_oauth_save_clients(array $c): void { update_option_meta(QP_OAUTH_CLIENTS_OPTION, $c); }

function qp_oauth_enabled(): bool { $s = qp_rest_settings(); return !empty($s['oauth']) && !empty($s['jwt']); }

/** A redirect address a public client may register: https, http only for the loopback, or an app scheme like com.example.app:/cb. */
function qp_oauth_valid_redirect(string $u): bool {
    if (strlen($u) > 300 || preg_match('/[\s"<>\\\\]/', $u) || strpos($u, '#') !== false) return false;
    $p = parse_url($u);
    if (!$p || empty($p['scheme'])) return false;
    $sch = strtolower($p['scheme']);
    if (in_array($sch, ['javascript', 'data', 'file', 'vbscript', 'blob', 'about', 'ftp'], true)) return false;
    if ($sch === 'https') return !empty($p['host']);
    if ($sch === 'http') return in_array(strtolower($p['host'] ?? ''), ['localhost', '127.0.0.1', '[::1]', '::1'], true);
    return (bool)preg_match('/^[a-z][a-z0-9+.-]*$/', $sch) && strpos($sch, '.') !== false || preg_match('/^[a-z][a-z0-9+-]{2,}$/', $sch) === 1;   // an app scheme
}

function qp_oauth_client_create(string $name, array $redirects): array {
    $name = mb_substr(trim($name), 0, 80);
    $good = [];
    foreach ($redirects as $r) { $r = trim((string)$r); if ($r !== '' && qp_oauth_valid_redirect($r)) $good[] = $r; }
    $good = array_values(array_unique($good));
    if ($name === '' || !$good) return [null, 'Give the app a name and at least one valid redirect address (https, http://localhost, or an app scheme).'];
    $clients = qp_oauth_clients();
    $id = 'qpc_' . bin2hex(random_bytes(8));
    $clients[$id] = ['name' => $name, 'redirects' => $good, 'created' => time()];
    qp_oauth_save_clients($clients);
    return [$id, null];
}

function qp_oauth_client_delete(string $id): bool {
    $c = qp_oauth_clients();
    if (!isset($c[$id])) return false;
    unset($c[$id]);
    qp_oauth_save_clients($c);
    // sessions started through this app end with it
    foreach (db()->query('SELECT user_id, meta_value FROM ' . table_name('user_meta') . " WHERE meta_key = 'qp_jwt_refresh'")->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $fams = maybe_unserialize($row['meta_value']);
        if (!is_array($fams)) continue;
        $changed = false;
        foreach ($fams as $fid => $f) if (($f['client'] ?? '') === $id) { unset($fams[$fid]); $changed = true; }
        if ($changed) update_user_meta((int)$row['user_id'], 'qp_jwt_refresh', $fams ?: null);
    }
    return true;
}

// ---------------------------------------------------------------- authorization codes (single use, 10 minutes, stored as hashed file names)

function qp_oauth_code_file(string $code): string { return qp_rest_counter_dir('api-oauth') . '/' . hash('sha256', $code) . '.json'; }

function qp_oauth_code_issue(array $data): string {
    $code = qp_rest_random(48);
    file_put_contents(qp_oauth_code_file($code), json_encode($data + ['exp' => time() + QP_OAUTH_CODE_TTL]), LOCK_EX);
    if (mt_rand(1, 50) === 1) qp_rest_counter_gc(qp_rest_counter_dir('api-oauth'), 3600);
    return $code;
}

/** Take a code: returns its data or null. The file is removed first, so a code works once even with two simultaneous requests. */
function qp_oauth_code_take(string $code): ?array {
    if (!preg_match('/^[A-Za-z0-9]{48}$/', $code)) return null;
    $f = qp_oauth_code_file($code);
    $tmp = $f . '.' . bin2hex(random_bytes(4)) . '.used';
    if (!@rename($f, $tmp)) return null;        // someone else took it, or it never existed
    $d = json_decode((string)@file_get_contents($tmp), true);
    @unlink($tmp);
    return (is_array($d) && ($d['exp'] ?? 0) >= time()) ? $d : null;
}

function qp_oauth_pkce_ok(string $verifier, string $challenge): bool {
    if (!preg_match('/^[A-Za-z0-9._~-]{43,128}$/', $verifier)) return false;
    return hash_equals($challenge, rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='));
}

// ---------------------------------------------------------------- the consent page

function qp_oauth_scope_label(string $s): string {
    static $names = ['*' => 'Everything this account can do', 'read' => 'Read your content and profile', 'write' => 'Create and change content'];
    if (isset($names[$s])) return $names[$s];
    [$res, $act] = array_pad(explode(':', $s, 2), 2, '');
    return ($act === 'read' ? 'Read ' : ($act === 'write' ? 'Change ' : 'Use ')) . $res;
}

function qp_oauth_page(string $title, string $body, int $status = 200): void {
    while (ob_get_level() > 0) ob_end_clean();
    http_response_code($status);
    header('Content-Type: text/html; charset=utf-8');
    header('X-Frame-Options: DENY');
    header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self' https: http: *; frame-ancestors 'none'; base-uri 'none'");
    header('Cache-Control: no-store');
    header('Referrer-Policy: no-referrer');
    header_remove('Set-Cookie');
    $site = htmlspecialchars((string)(function_exists('get_option_meta') ? (get_option_meta('site_name') ?: '') : ''));
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>' . htmlspecialchars($title) . '</title><style>'
        . ':root{--bg:#f4f6f8;--card:#fff;--text:#1f2a37;--muted:#667085;--line:#e4e8ef;--accent:#2f7d79}@media(prefers-color-scheme:dark){:root{--bg:#10171c;--card:#18222a;--text:#e6edf2;--muted:#9fb0bc;--line:#2a3944;--accent:#6fcfc3}}'
        . 'body{margin:0;min-height:100vh;display:grid;place-items:center;background:var(--bg);color:var(--text);font:16px/1.5 system-ui,-apple-system,Segoe UI,sans-serif}'
        . '.card{width:min(440px,92vw);background:var(--card);border:1px solid var(--line);border-radius:14px;padding:28px;box-shadow:0 10px 30px -14px rgba(0,0,0,.25)}'
        . 'h1{font-size:1.25rem;margin:0 0 6px}p{margin:.5rem 0;color:var(--muted)}ul{padding-left:1.2rem;margin:.75rem 0}li{margin:.25rem 0;color:var(--text)}'
        . 'button,a.btn{font:inherit;border-radius:10px;padding:.6rem 1.1rem;border:1px solid var(--line);background:transparent;color:var(--text);cursor:pointer;text-decoration:none;display:inline-block}'
        . 'button.primary{background:var(--accent);border-color:var(--accent);color:#fff}.row{display:flex;gap:10px;margin-top:18px}code{background:var(--bg);padding:1px 6px;border-radius:6px}.who{font-size:.85rem}'
        . '</style></head><body><main class="card"><p class="who">' . $site . '</p>' . $body . '</main></body></html>';
}

function qp_oauth_authorize_page(array $config): void {
    $h = function ($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); };
    if (!qp_oauth_enabled()) { qp_oauth_page('Not available', '<h1>Not available</h1><p>Sign-in with apps is not turned on for this site.</p>', 403); return; }
    $q = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET;
    $clients = qp_oauth_clients();
    $cid = (string)($q['client_id'] ?? ''); $redirect = (string)($q['redirect_uri'] ?? '');
    $client = $clients[$cid] ?? null;
    // Until the client and the redirect address are known to match, never redirect: show the problem here.
    if (!$client || !in_array($redirect, $client['redirects'], true)) { qp_oauth_page('Cannot sign in', '<h1>Cannot sign in</h1><p>This app is not set up correctly for this site (unknown app or redirect address).</p>', 400); return; }
    $state = isset($q['state']) ? (string)$q['state'] : null;
    $back = function (array $params) use ($redirect, $state) {
        if ($state !== null) $params['state'] = $state;
        header('Location: ' . $redirect . (strpos($redirect, '?') === false ? '?' : '&') . http_build_query($params), true, 302);
        exit;
    };
    if (($q['response_type'] ?? '') !== 'code') $back(['error' => 'unsupported_response_type']);
    $challenge = (string)($q['code_challenge'] ?? '');
    if (($q['code_challenge_method'] ?? '') !== 'S256' || !preg_match('/^[A-Za-z0-9_-]{43}$/', $challenge)) $back(['error' => 'invalid_request', 'error_description' => 'PKCE with code_challenge_method=S256 is required.']);
    $scopes = array_values(array_filter(preg_split('/\s+/', trim((string)($q['scope'] ?? 'read'))) ?: [], 'qp_rest_valid_scope'));
    if (!$scopes) $scopes = ['read'];

    $uid = function_exists('qp_current_user_id') ? qp_current_user_id() : null;
    if (!$uid) {
        // Not signed in: the admin login page brings the user back here afterwards.
        $login = rtrim((string)$config['site_url'], '/') . '/' . ($config['admin_dir'] ?? 'admin') . '/login.php?redirect_to=' . rawurlencode(qp_rest_site_origin() . $_SERVER['REQUEST_URI']);
        qp_oauth_page('Sign in', '<h1>Sign in to continue</h1><p><strong>' . $h($client['name']) . '</strong> wants to connect to your account.</p><div class="row"><a class="btn primary" href="' . $h($login) . '">Sign in</a></div>');
        return;
    }
    $user = qp_rest_user_row((int)$uid);
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!qp_verify_nonce((string)($_POST['_n'] ?? ''), 'qp_oauth')) { qp_oauth_page('Expired', '<h1>This page expired</h1><p>Go back to the app and start again.</p>', 403); return; }
        if (isset($_POST['deny'])) $back(['error' => 'access_denied']);
        $code = qp_oauth_code_issue(['client' => $cid, 'uid' => (int)$uid, 'redirect' => $redirect, 'scopes' => $scopes, 'challenge' => $challenge]);
        $back(['code' => $code]);
    }
    $items = '';
    foreach ($scopes as $s) $items .= '<li>' . $h(qp_oauth_scope_label($s)) . '</li>';
    $hidden = '';
    foreach (['client_id' => $cid, 'redirect_uri' => $redirect, 'response_type' => 'code', 'scope' => implode(' ', $scopes), 'code_challenge' => $challenge, 'code_challenge_method' => 'S256'] + ($state !== null ? ['state' => $state] : []) as $k => $v) $hidden .= '<input type="hidden" name="' . $h($k) . '" value="' . $h($v) . '">';
    qp_oauth_page('Allow access', '<h1>Allow ' . $h($client['name']) . '?</h1><p>You are signed in as <strong>' . $h($user['username'] ?? '') . '</strong>. This app will be able to:</p><ul>' . $items . '</ul>'
        . '<p>It can never do more than your account may. You can end its access later on Settings &gt; API.</p>'
        . '<form method="post" action="">' . $hidden . '<input type="hidden" name="_n" value="' . $h(qp_create_nonce('qp_oauth', 900)) . '"><div class="row"><button class="primary" type="submit" name="allow" value="1">Allow</button><button type="submit" name="deny" value="1">Deny</button></div></form>');
}

// ---------------------------------------------------------------- token and revoke endpoints (RFC 6749 / 7009 error bodies)

function qp_oauth_error(string $error, string $desc, int $status = 400): QP_REST_Response {
    return new QP_REST_Response(['error' => $error, 'error_description' => $desc], $status, ['Cache-Control' => 'no-store', 'Pragma' => 'no-cache']);
}

function qp_oauth_register_routes(): void {
    $ns = 'qlopy/v1';
    $args = ['grant_type' => ['type' => 'string', 'required' => true, 'enum' => ['authorization_code', 'refresh_token']], 'client_id' => ['type' => 'string', 'required' => true, 'maxLength' => 60],
             'code' => ['type' => 'string', 'maxLength' => 100], 'redirect_uri' => ['type' => 'string', 'maxLength' => 300], 'code_verifier' => ['type' => 'string', 'maxLength' => 200], 'refresh_token' => ['type' => 'string', 'maxLength' => 200]];
    qp_register_rest_route($ns, '/oauth/token', ['methods' => 'POST', 'permission_callback' => 'qp_rest_permit_public', 'scope' => 'none', 'no_store' => true, 'form' => true, 'args' => $args,
        'summary' => 'OAuth 2.0 token endpoint (authorization code with PKCE, and refresh). Send application/x-www-form-urlencoded.',
        'callback' => function (QP_REST_Request $r) {
            if ($e = qp_rest_https_required_error()) return qp_oauth_error('invalid_request', 'HTTPS is required.');
            if (!qp_oauth_enabled()) return qp_oauth_error('unsupported_grant_type', 'Sign-in with apps is not turned on.', 403);
            $ip = qp_rest_client_ip(); $hh = [];
            if (!qp_rest_is_loopback() && qp_rest_rate_limit('oauth:' . $ip, 30, $hh)) return qp_oauth_error('invalid_request', 'Too many requests.', 429);
            if (qp_rest_auth_locked('ip:' . $ip)) return qp_oauth_error('invalid_request', 'Too many failed attempts.', 429);
            $client = qp_oauth_clients()[(string)$r['client_id']] ?? null;
            if (!$client) { qp_rest_auth_failed('ip:' . $ip); return qp_oauth_error('invalid_client', 'Unknown client.', 401); }
            if ($r['grant_type'] === 'authorization_code') {
                $d = qp_oauth_code_take((string)$r['code']);
                if (!$d || $d['client'] !== (string)$r['client_id'] || $d['redirect'] !== (string)$r['redirect_uri'] || !qp_oauth_pkce_ok((string)$r['code_verifier'], (string)$d['challenge'])) {
                    qp_rest_auth_failed('ip:' . $ip);
                    return qp_oauth_error('invalid_grant', 'The code is not valid, was already used, expired, or the verifier does not match.');
                }
                if (!qp_rest_user_row((int)$d['uid'])) return qp_oauth_error('invalid_grant', 'The account no longer exists.');
                $pair = qp_jwt_start((int)$d['uid'], (array)$d['scopes'], (string)$r['client_id']);
                return new QP_REST_Response($pair, 200, ['Cache-Control' => 'no-store', 'Pragma' => 'no-cache']);
            }
            $pair = qp_jwt_refresh((string)$r['refresh_token'], (string)$r['client_id']);
            if (!$pair) { qp_rest_auth_failed('ip:' . $ip); return qp_oauth_error('invalid_grant', 'The refresh token is not valid.'); }
            return new QP_REST_Response($pair, 200, ['Cache-Control' => 'no-store', 'Pragma' => 'no-cache']);
        }]);
    qp_register_rest_route($ns, '/oauth/revoke', ['methods' => 'POST', 'permission_callback' => 'qp_rest_permit_public', 'scope' => 'none', 'no_store' => true, 'form' => true,
        'args' => ['token' => ['type' => 'string', 'required' => true, 'maxLength' => 200], 'client_id' => ['type' => 'string', 'required' => true, 'maxLength' => 60]],
        'summary' => 'Revoke a refresh token (RFC 7009). Always answers 200.',
        'callback' => function (QP_REST_Request $r) {
            if (preg_match('/^qpr_(\d{1,10})\.([0-9a-f]{12})\.([0-9A-Za-z]{48})$/', (string)$r['token'], $m)) {
                $fams = qp_rest_user_meta_list((int)$m[1], 'qp_jwt_refresh');
                if (isset($fams[$m[2]]) && ($fams[$m[2]]['client'] ?? '') === (string)$r['client_id'] && hash_equals((string)$fams[$m[2]]['hash'], qp_rest_hash_secret('refresh', $m[3]))) qp_jwt_revoke_family((int)$m[1], $m[2]);
            }
            return new QP_REST_Response((object)[], 200, ['Cache-Control' => 'no-store']);
        }]);
}
