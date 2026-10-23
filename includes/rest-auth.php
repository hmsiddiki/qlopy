<?php
if (!defined('QLOPY_INIT')) { http_response_code(403); exit; }
// includes/rest-auth.php
// Credentials for the REST API (core upgrade plan C4): API keys, application passwords, JWT access tokens with rotating refresh tokens.
// Nothing is stored in a new table: everything lives in user meta (`qp_api_keys`, `qp_app_passwords`, `qp_jwt_refresh`).
// Secrets are random and long, so they are stored as an HMAC (keyed from security_salt) and compared with hash_equals.
// Every scheme except the cookie is off until Settings > API turns it on, and none is accepted over plain HTTP (except from the loopback).

const QP_REST_BASE62 = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';

function qp_rest_random(int $len, string $alphabet = QP_REST_BASE62): string {
    $out = ''; $n = strlen($alphabet);
    while (strlen($out) < $len) { $b = random_bytes(1); $v = ord($b); if ($v < 248) $out .= $alphabet[$v % $n]; }   // 248 = 62 * 4: no modulo bias
    return $out;
}

function qp_rest_hash_secret(string $kind, string $secret): string {
    return hash_hmac('sha256', $secret, qp_rest_secret('hash|' . $kind));
}

/** Same-IP and same-account failure counters: 10 failures in 15 minutes lock that IP or that account out of credential checks. */
function qp_rest_auth_locked(string $who): ?QP_REST_Error {
    $n = qp_rest_counter_peek('authfail:' . $who, 900);
    if ($n >= 10) return new QP_REST_Error('rate_limited', 'Too many failed attempts. Try again later.', 429, [], ['Retry-After' => '900']);
    return null;
}
function qp_rest_auth_failed(string $who): void { qp_rest_hit('authfail:' . $who, 900); }

/** User id for a login name (one query, no profile lookups); 0 when there is none. */
function qp_rest_user_id_by_login(string $username): int {
    if ($username === '' || strlen($username) > 100) return 0;
    $st = db()->prepare('SELECT id FROM ' . table_name('users') . ' WHERE username = ? LIMIT 1');
    $st->execute([$username]);
    return (int)$st->fetchColumn();
}

function qp_rest_user_meta_list(int $uid, string $key): array {
    $v = get_user_meta($uid, $key);
    return is_array($v) ? $v : [];
}

// ---------------------------------------------------------------- API keys

/** Create a key for a user. Returns the full key (shown once) and its id. Scopes like ['posts:read', 'read', '*']. */
function qp_api_key_create(int $uid, string $name, array $scopes, ?int $rate = null, ?int $expires = null): array {
    $scopes = array_values(array_unique(array_filter($scopes, 'qp_rest_valid_scope')));
    if (!$scopes) $scopes = ['read'];
    $kid = qp_rest_random(8, '0123456789abcdef');
    $secret = qp_rest_random(40);
    $keys = qp_rest_user_meta_list($uid, 'qp_api_keys');
    $keys[$kid] = ['name' => mb_substr(trim($name) ?: 'Key', 0, 60), 'hash' => qp_rest_hash_secret('key', $secret), 'scopes' => $scopes, 'created' => time(), 'last_used' => 0, 'last_ip' => '', 'rate' => $rate, 'expires' => $expires, 'revoked' => false];
    update_user_meta($uid, 'qp_api_keys', $keys);
    return ['id' => $kid, 'key' => 'qpk_' . $uid . '.' . $kid . '.' . $secret];
}

function qp_api_key_revoke(int $uid, string $kid): bool {
    $keys = qp_rest_user_meta_list($uid, 'qp_api_keys');
    if (!isset($keys[$kid])) return false;
    unset($keys[$kid]);
    update_user_meta($uid, 'qp_api_keys', $keys ?: null);
    return true;
}

function qp_api_keys_for(int $uid): array {
    $out = [];
    foreach (qp_rest_user_meta_list($uid, 'qp_api_keys') as $kid => $k) { unset($k['hash']); $out[$kid] = $k; }
    return $out;
}

/** X-API-Key: qpk_<user>.<id>.<secret>   (also  Authorization: ApiKey <key>). */
function qp_rest_auth_apikey(QP_REST_Request $r) {
    $raw = $r->get_header('x-api-key');
    $auth = (string)($r->get_header('authorization') ?? '');
    if ($raw === null && stripos($auth, 'ApiKey ') === 0) $raw = trim(substr($auth, 7));
    if ($raw === null || $raw === '') return null;
    if ($e = qp_rest_https_required_error()) return $e;
    if (!qp_rest_settings()['keys']) return new QP_REST_Error('api_disabled', 'API keys are not turned on for this site.', 403);
    $ip = qp_rest_client_ip();
    if ($e = qp_rest_auth_locked('ip:' . $ip)) return $e;
    if (!preg_match('/^qpk_(\d{1,10})\.([0-9a-f]{8})\.([0-9A-Za-z]{40})$/', trim($raw), $m)) { qp_rest_auth_failed('ip:' . $ip); return qp_rest_bad_auth(); }
    $uid = (int)$m[1];
    $keys = qp_rest_user_meta_list($uid, 'qp_api_keys');
    $k = $keys[$m[2]] ?? null;
    $okHash = is_array($k) && hash_equals((string)$k['hash'], qp_rest_hash_secret('key', $m[3]));
    if (!$okHash || !empty($k['revoked']) || (!empty($k['expires']) && $k['expires'] < time()) || !qp_rest_user_row($uid)) { qp_rest_auth_failed('ip:' . $ip); return qp_rest_bad_auth(); }
    if (time() - (int)$k['last_used'] > 300) {   // a write at most every five minutes per key
        $keys[$m[2]]['last_used'] = time(); $keys[$m[2]]['last_ip'] = $ip;
        update_user_meta($uid, 'qp_api_keys', $keys);
    }
    $rate = isset($k['rate']) && $k['rate'] ? (int)$k['rate'] : (int)qp_rest_settings()['rate_key'];
    return ['user_id' => $uid, 'scopes' => (array)$k['scopes'], 'scheme' => 'apikey', 'key_id' => $m[2], 'rate_limit' => $rate];
}

// ---------------------------------------------------------------- application passwords (HTTP Basic)

/** Returns the password to show once (in groups of four) and its id. */
function qp_app_password_create(int $uid, string $name): array {
    $pw = qp_rest_random(24);
    $id = qp_rest_random(8, '0123456789abcdef');
    $list = qp_rest_user_meta_list($uid, 'qp_app_passwords');
    $list[$id] = ['name' => mb_substr(trim($name) ?: 'App', 0, 60), 'hash' => qp_rest_hash_secret('apppw', $pw), 'created' => time(), 'last_used' => 0, 'last_ip' => ''];
    update_user_meta($uid, 'qp_app_passwords', $list);
    return ['id' => $id, 'password' => implode(' ', str_split($pw, 4)), 'raw' => $pw];
}

function qp_app_password_revoke(int $uid, string $id): bool {
    $list = qp_rest_user_meta_list($uid, 'qp_app_passwords');
    if (!isset($list[$id])) return false;
    unset($list[$id]);
    update_user_meta($uid, 'qp_app_passwords', $list ?: null);
    return true;
}

function qp_app_passwords_for(int $uid): array {
    $out = [];
    foreach (qp_rest_user_meta_list($uid, 'qp_app_passwords') as $id => $p) { unset($p['hash']); $out[$id] = $p; }
    return $out;
}

/** Does this password match one of the user's application passwords? Returns its id or null. Compares them all (no early exit). */
function qp_app_password_match(int $uid, string $password): ?string {
    $pw = preg_replace('/[\s-]+/', '', $password);
    $h = qp_rest_hash_secret('apppw', (string)$pw);
    $hit = null;
    foreach (qp_rest_user_meta_list($uid, 'qp_app_passwords') as $id => $p) if (hash_equals((string)$p['hash'], $h)) $hit = (string)$id;
    return $hit;
}

function qp_app_password_touch(int $uid, string $id): void {
    $list = qp_rest_user_meta_list($uid, 'qp_app_passwords');
    if (!isset($list[$id]) || time() - (int)$list[$id]['last_used'] < 300) return;
    $list[$id]['last_used'] = time(); $list[$id]['last_ip'] = qp_rest_client_ip();
    update_user_meta($uid, 'qp_app_passwords', $list);
}

function qp_rest_auth_basic(QP_REST_Request $r) {
    $auth = (string)($r->get_header('authorization') ?? '');
    if (stripos($auth, 'Basic ') !== 0) return null;
    if ($e = qp_rest_https_required_error()) return $e;
    if (!qp_rest_settings()['app_passwords']) return new QP_REST_Error('api_disabled', 'Application passwords are not turned on for this site.', 403);
    $dec = base64_decode(trim(substr($auth, 6)), true);
    $ip = qp_rest_client_ip();
    if ($e = qp_rest_auth_locked('ip:' . $ip)) return $e;
    if ($dec === false || strpos($dec, ':') === false) { qp_rest_auth_failed('ip:' . $ip); return qp_rest_bad_auth(); }
    [$user, $pw] = explode(':', $dec, 2);
    if ($e = qp_rest_auth_locked('user:' . strtolower($user))) return $e;
    $uid = qp_rest_user_id_by_login($user);
    $id = null;
    if ($uid) $id = qp_app_password_match($uid, $pw);
    else qp_rest_hash_secret('apppw', $pw);   // same work for a name that does not exist
    if ($id === null || !$uid) { qp_rest_auth_failed('ip:' . $ip); qp_rest_auth_failed('user:' . strtolower($user)); return qp_rest_bad_auth(); }
    qp_app_password_touch($uid, $id);
    return ['user_id' => $uid, 'scopes' => ['*'], 'scheme' => 'basic', 'key_id' => $id, 'rate_limit' => (int)qp_rest_settings()['rate_key']];
}

// ---------------------------------------------------------------- JWT (HS256 only; the server fixes the algorithm)

function qp_jwt_b64(string $s): string { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }
function qp_jwt_unb64(string $s): ?string { $d = base64_decode(strtr($s, '-_', '+/'), true); return $d === false ? null : $d; }

function qp_jwt_key(string $kid): string {
    $cfg = function_exists('get_config') ? get_config() : [];
    if (!empty($cfg['jwt_secret']) && strlen((string)$cfg['jwt_secret']) >= 32) return hash('sha256', (string)$cfg['jwt_secret'] . '|' . $kid, true);
    return hash_hmac('sha256', 'jwt|' . $kid, qp_rest_secret('jwt'), true);
}

function qp_jwt_issuer(): string { return defined('SITE_URL') ? SITE_URL : ''; }
function qp_jwt_audience(): string { return qp_jwt_issuer() . '/api'; }

function qp_jwt_encode(array $claims): string {
    $kid = (string)qp_rest_settings()['jwt_kid'];
    $h = qp_jwt_b64(json_encode(['alg' => 'HS256', 'typ' => 'JWT', 'kid' => $kid]));
    $p = qp_jwt_b64(json_encode($claims));
    return $h . '.' . $p . '.' . qp_jwt_b64(hash_hmac('sha256', $h . '.' . $p, qp_jwt_key($kid), true));
}

/** Verify a token; returns its claims or null. The algorithm in the header is never trusted: it must be exactly HS256. */
function qp_jwt_decode(string $token): ?array {
    $parts = explode('.', $token);
    if (count($parts) !== 3 || strlen($token) > 4096) return null;
    $hdr = json_decode((string)qp_jwt_unb64($parts[0]), true);
    if (!is_array($hdr) || ($hdr['alg'] ?? '') !== 'HS256' || ($hdr['typ'] ?? 'JWT') !== 'JWT' || !is_string($hdr['kid'] ?? null)) return null;
    $s = qp_rest_settings();
    if ($hdr['kid'] !== $s['jwt_kid'] && !in_array($hdr['kid'], (array)$s['jwt_old_kids'], true)) return null;
    $sig = qp_jwt_unb64($parts[2]);
    if ($sig === null || !hash_equals(hash_hmac('sha256', $parts[0] . '.' . $parts[1], qp_jwt_key($hdr['kid']), true), $sig)) return null;
    $c = json_decode((string)qp_jwt_unb64($parts[1]), true);
    if (!is_array($c)) return null;
    $now = time();
    if (($c['iss'] ?? '') !== qp_jwt_issuer() || ($c['aud'] ?? '') !== qp_jwt_audience()) return null;
    if (!isset($c['exp'], $c['nbf'], $c['sub']) || $c['exp'] < $now - 30 || $c['nbf'] > $now + 30) return null;
    return $c;
}

/** A short value that changes when the user's password changes, so a password change ends every token at once. */
function qp_jwt_password_version(array $userRowWithHash): string { return substr(hash_hmac('sha256', (string)$userRowWithHash['password_hash'], qp_rest_secret('pv')), 0, 12); }

function qp_rest_auth_bearer(QP_REST_Request $r) {
    $auth = (string)($r->get_header('authorization') ?? '');
    if (stripos($auth, 'Bearer ') !== 0) return null;
    if ($e = qp_rest_https_required_error()) return $e;
    if (!qp_rest_settings()['jwt']) return new QP_REST_Error('api_disabled', 'Token sign-in is not turned on for this site.', 403);
    $c = qp_jwt_decode(trim(substr($auth, 7)));
    if (!$c) return qp_rest_bad_auth();
    $uid = (int)$c['sub'];
    $row = qp_rest_user_row($uid, true);
    if (!$row || !hash_equals(qp_jwt_password_version($row), (string)($c['pv'] ?? ''))) return qp_rest_bad_auth();
    // Revocation: the refresh family the token came from must still exist (logout or a reused refresh token removes it).
    $fam = (string)($c['fam'] ?? '');
    $fams = qp_rest_user_meta_list($uid, 'qp_jwt_refresh');
    if ($fam === '' || !isset($fams[$fam]) || ($fams[$fam]['exp'] ?? 0) < time()) return qp_rest_bad_auth();
    return ['user_id' => $uid, 'scopes' => array_values((array)($c['scp'] ?? [])), 'scheme' => 'bearer', 'key_id' => $fam, 'family' => $fam, 'rate_limit' => (int)qp_rest_settings()['rate_key']];
}

function qp_jwt_issue_access(int $uid, array $row, string $family, array $scopes): array {
    $s = qp_rest_settings(); $now = time(); $ttl = max(60, (int)$s['jwt_access_ttl']);
    $claims = ['iss' => qp_jwt_issuer(), 'aud' => qp_jwt_audience(), 'sub' => (string)$uid, 'iat' => $now, 'nbf' => $now, 'exp' => $now + $ttl, 'jti' => qp_rest_random(16, '0123456789abcdef'), 'scp' => $scopes, 'fam' => $family, 'pv' => qp_jwt_password_version($row)];
    return ['access_token' => qp_jwt_encode($claims), 'token_type' => 'Bearer', 'expires_in' => $ttl];
}

/** Start a refresh family and return the token pair. */
function qp_jwt_start(int $uid, array $scopes, string $client = ''): array {
    $row = qp_rest_user_row($uid, true);
    $s = qp_rest_settings(); $now = time();
    $fams = array_filter(qp_rest_user_meta_list($uid, 'qp_jwt_refresh'), function ($f) use ($now) { return ($f['exp'] ?? 0) > $now; });
    if (count($fams) >= 20) { uasort($fams, function ($a, $b) { return $a['created'] <=> $b['created']; }); array_shift($fams); }
    $fam = qp_rest_random(12, '0123456789abcdef');
    $secret = qp_rest_random(48);
    $fams[$fam] = ['hash' => qp_rest_hash_secret('refresh', $secret), 'exp' => $now + max(3600, (int)$s['jwt_refresh_ttl']), 'created' => $now, 'scopes' => $scopes, 'client' => $client, 'ip' => qp_rest_client_ip(), 'ua' => mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 120)];
    update_user_meta($uid, 'qp_jwt_refresh', $fams);
    return qp_jwt_issue_access($uid, $row, $fam, $scopes) + ['refresh_token' => 'qpr_' . $uid . '.' . $fam . '.' . $secret, 'refresh_expires_in' => $fams[$fam]['exp'] - $now, 'scope' => implode(' ', $scopes)];
}

/** One-use refresh: returns the new pair, or null. A token that was already used (the stored hash moved on) revokes the whole family. */
function qp_jwt_refresh(string $token, string $client = ''): ?array {
    if (!preg_match('/^qpr_(\d{1,10})\.([0-9a-f]{12})\.([0-9A-Za-z]{48})$/', trim($token), $m)) return null;
    $uid = (int)$m[1]; $fam = $m[2];
    $fams = qp_rest_user_meta_list($uid, 'qp_jwt_refresh');
    $f = $fams[$fam] ?? null;
    $row = qp_rest_user_row($uid, true);
    if (!is_array($f) || !$row || ($f['exp'] ?? 0) < time()) return null;
    if ((string)($f['client'] ?? '') !== $client) return null;   // a session belongs to the app that started it (or to none, for the password grant)
    if (!hash_equals((string)$f['hash'], qp_rest_hash_secret('refresh', $m[3]))) {
        unset($fams[$fam]);                      // reuse of a rotated token: someone has a copy; end the family
        update_user_meta($uid, 'qp_jwt_refresh', $fams ?: null);
        return null;
    }
    $secret = qp_rest_random(48);
    $fams[$fam]['hash'] = qp_rest_hash_secret('refresh', $secret);
    $fams[$fam]['rotated'] = time();
    update_user_meta($uid, 'qp_jwt_refresh', $fams);
    $scopes = (array)$f['scopes'];
    return qp_jwt_issue_access($uid, $row, $fam, $scopes) + ['refresh_token' => 'qpr_' . $uid . '.' . $fam . '.' . $secret, 'refresh_expires_in' => $f['exp'] - time(), 'scope' => implode(' ', $scopes)];
}

function qp_jwt_revoke_family(int $uid, string $fam): bool {
    $fams = qp_rest_user_meta_list($uid, 'qp_jwt_refresh');
    if (!isset($fams[$fam])) return false;
    unset($fams[$fam]);
    update_user_meta($uid, 'qp_jwt_refresh', $fams ?: null);
    return true;
}

function qp_jwt_revoke_all(int $uid): void { update_user_meta($uid, 'qp_jwt_refresh', null); }

/** Sign-in with an account password or an application password. Returns the user id or null. Counts failures per IP and per account. */
function qp_rest_password_login(string $username, string $password): ?int {
    $ip = qp_rest_client_ip();
    $uid = qp_rest_user_id_by_login($username);
    $full = $uid ? qp_rest_user_row($uid, true) : null;
    $ok = false;
    if ($full) {
        $ok = password_verify($password, (string)$full['password_hash']) || qp_app_password_match($uid, $password) !== null;
    } else {
        password_verify($password, '$2y$10$abcdefghijklmnopqrstuuABCDEFGHIJKLMNOPQRSTUVWXYZ01234');   // same work whether or not the name exists
    }
    if (!$ok) { qp_rest_auth_failed('ip:' . $ip); qp_rest_auth_failed('user:' . strtolower($username)); return null; }
    return $uid;
}
