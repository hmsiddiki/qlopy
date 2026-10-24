<?php
// Dev tool: checks login sessions (lifetimes, sliding, pruning, cap, concurrency, logout confirmation, heartbeat). CLI only.
//   php admin/dev/auth-session-test.php [--base=https://host/path] [--resolve=127.0.0.1]
// Uses the account with id 3 (or --uid=N) and restores its session tokens and form tokens afterwards.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('QLOPY_INIT', true);
chdir(dirname(__DIR__, 2));
$opt = getopt('', ['base::', 'resolve::', 'uid::', 'worker::', 'cookie::', 'n::']);
if (isset($opt['cookie'])) $_COOKIE['qp_token'] = (string)$opt['cookie'];   // before auth.php: the signed-in user is worked out once per request
require 'db.php'; $pdo = db();
require 'auth.php';
$uid = (int)($opt['uid'] ?? 3);

// ---- worker mode: one of several parallel processes
if (isset($opt['worker'])) {
    $n = (string)($opt['n'] ?? '0');
    if ($opt['worker'] === 'token') { create_session_token($uid); }
    elseif ($opt['worker'] === 'mutate') { qp_user_meta_mutate($uid, 'qp_test_mutate', function ($a) use ($n) { usleep(2000); $a['k' . $n] = 1; return $a; }); }
    elseif ($opt['worker'] === 'nonce') {
        require_once 'admin/admin_head.php';
        qp_admin_create_nonce('race_test');
    }
    exit(0);
}

$base = rtrim($opt['base'] ?? $config['site_url'], '/');
$host = parse_url($base, PHP_URL_HOST);
$port = parse_url($base, PHP_URL_PORT) ?: (parse_url($base, PHP_URL_SCHEME) === 'https' ? 443 : 80);
$resolve = $opt['resolve'] ?? '127.0.0.1';
$pass = 0; $fail = 0;
function ok($c, $m) { global $pass, $fail; if ($c) $pass++; else $fail++; echo ($c ? 'PASS ' : 'FAIL ') . $m . "\n"; }
function http($path, $o = []) {
    global $base, $host, $port, $resolve;
    $c = curl_init($base . '/' . ltrim($path, '/'));
    $opts = [CURLOPT_RESOLVE => ["$host:$port:$resolve"], CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0, CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 30];
    if (!empty($o['cookie'])) $opts[CURLOPT_COOKIE] = $o['cookie'];
    if (isset($o['post'])) { $opts[CURLOPT_POST] = true; $opts[CURLOPT_POSTFIELDS] = http_build_query($o['post']); }
    curl_setopt_array($c, $opts);
    $r = (string)curl_exec($c); $hs = curl_getinfo($c, CURLINFO_HEADER_SIZE); $code = curl_getinfo($c, CURLINFO_HTTP_CODE); curl_close($c);
    return ['code' => $code, 'head' => substr($r, 0, $hs), 'body' => substr($r, $hs)];
}
function cookie_for($t) { return QP_SESSION_COOKIE . '=' . rawurlencode($t['token_id'] . '|' . $t['user_id'] . '|' . $t['expires'] . '|' . qp_hash_token_sig($t['token_id'], $t['user_id'], $t['expires'])); }
function cookie_raw($t) { return $t['token_id'] . '|' . $t['user_id'] . '|' . $t['expires'] . '|' . qp_hash_token_sig($t['token_id'], $t['user_id'], $t['expires']); }
function spawn_workers($mode, $count, $extra = []) {
    $procs = [];
    for ($i = 0; $i < $count; $i++) {
        $cmd = [PHP_BINARY, __FILE__, '--worker=' . $mode, '--n=' . $i, '--uid=' . $GLOBALS['uid']];
        foreach ($extra as $k => $v) $cmd[] = '--' . $k . '=' . $v;
        $procs[] = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    }
    foreach ($procs as $p) proc_close($p);
}

$TM = table_name('user_meta');
$saved = [];
foreach (['session_tokens', 'qp_nonces', 'qp_test_mutate'] as $k) {
    $st = $pdo->prepare("SELECT meta_value FROM $TM WHERE user_id = ? AND meta_key = ?"); $st->execute([$uid, $k]); $v = $st->fetchColumn(); $saved[$k] = $v === false ? null : $v;
}
$o_auth = get_option_meta('qp_auth_settings');
$setTokens = function (array $t) use ($uid) { qp_user_meta_mutate($uid, 'session_tokens', function () use ($t) { return $t; }); qp_cache_delete('qp_user_tokens_' . $uid); };
$setTokens([]);
update_option_meta('qp_auth_settings', ['session_ttl' => 172800, 'remember_ttl' => 1209600, 'max_tokens' => 100]);

try {
    echo "-- lifetimes\n";
    ok(qp_auth_ttl(false) === 172800 && qp_auth_ttl(true) === 1209600, 'defaults: 2 days, remember 14 days');
    $t1 = create_session_token($uid);
    ok(abs($t1['expires'] - (time() + 172800)) < 5, 'an ordinary login lasts 2 days');
    $row = get_user_session_tokens($uid)[$t1['token_id']];
    ok($row['ttl'] === 172800 && $row['remember'] === false, 'the login records its own length');
    $t2 = create_session_token($uid, qp_auth_ttl(true));
    ok(abs($t2['expires'] - (time() + 1209600)) < 5 && get_user_session_tokens($uid)[$t2['token_id']]['remember'] === true, '"remember me" lasts 14 days and is flagged');
    ok(validate_session_token(cookie_raw($t1)) !== null, 'a fresh login validates');

    echo "-- sliding keeps each login\'s own length\n";
    qp_user_meta_mutate($uid, 'session_tokens', function ($t) use ($t2) { $t[$t2['token_id']]['last_activity'] = time() - 400; $t[$t2['token_id']]['expires_at'] = time() + 3600; return $t; });
    qp_cache_delete('qp_user_tokens_' . $uid);
    $_COOKIE[QP_SESSION_COOKIE] = cookie_raw(['token_id' => $t2['token_id'], 'user_id' => $uid, 'expires' => time() + 3600]);
    auth_init_from_token();
    $after = get_user_session_tokens($uid)[$t2['token_id']];
    ok(abs($after['expires_at'] - (time() + 1209600)) < 5, 'a remembered login is extended by 14 days, not by the ordinary length');
    ok($after['last_activity'] >= time() - 5, 'last activity updated');
    // a login saved before lengths were stored gets the ordinary length
    qp_user_meta_mutate($uid, 'session_tokens', function ($t) use ($t1) { unset($t[$t1['token_id']]['ttl']); $t[$t1['token_id']]['last_activity'] = time() - 400; return $t; });
    qp_cache_delete('qp_user_tokens_' . $uid);
    $_COOKIE[QP_SESSION_COOKIE] = cookie_raw($t1);
    auth_init_from_token();
    $leg = get_user_session_tokens($uid)[$t1['token_id']];
    ok(abs($leg['expires_at'] - (time() + 172800)) < 5 && $leg['ttl'] === 172800, 'an older login without a stored length gets the ordinary length');
    unset($_COOKIE[QP_SESSION_COOKIE]);

    echo "-- pruning and the cap\n";
    $setTokens([]);
    qp_user_meta_mutate($uid, 'session_tokens', function () { return ['dead1' => ['expires_at' => time() - 10, 'last_activity' => time() - 99999], 'dead2' => ['expires_at' => time() - 20, 'last_activity' => time() - 99999]]; });
    $a = create_session_token($uid);
    $ids = array_keys(get_user_session_tokens($uid));
    ok($ids === [$a['token_id']], 'expired logins are removed when a login is created');
    update_option_meta('qp_auth_settings', ['session_ttl' => 172800, 'remember_ttl' => 1209600, 'max_tokens' => 3]);
    // settings are cached per process: check the cap in a fresh process
    $tmpf = sys_get_temp_dir() . '/qp-cap-' . getmypid() . '.php';
    file_put_contents($tmpf, '<?php define("QLOPY_INIT", 1); chdir(' . var_export(getcwd(), true) . '); require "db.php"; require "auth.php"; for ($i = 0; $i < 5; $i++) { usleep(1100000); create_session_token(' . $uid . '); } echo count(get_user_session_tokens(' . $uid . '));');
    $out = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($tmpf)); @unlink($tmpf);
    ok(trim((string)$out) === '3', 'only the 3 newest logins are kept when the cap is 3 (kept: ' . trim((string)$out) . ')');
    update_option_meta('qp_auth_settings', ['session_ttl' => 172800, 'remember_ttl' => 1209600, 'max_tokens' => 100]);

    echo "-- many requests at the same moment\n";
    $setTokens([]);
    spawn_workers('token', 12);
    qp_cache_delete('qp_user_tokens_' . $uid);
    ok(count(get_user_session_tokens($uid)) === 12, '12 parallel logins: none is lost (' . count(get_user_session_tokens($uid)) . ' stored)');
    $pdo->prepare("DELETE FROM $TM WHERE user_id = ? AND meta_key = 'qp_test_mutate'")->execute([$uid]);
    spawn_workers('mutate', 12);
    $st = $pdo->prepare("SELECT meta_value FROM $TM WHERE user_id = ? AND meta_key = 'qp_test_mutate'"); $st->execute([$uid]);
    $m = maybe_unserialize($st->fetchColumn());
    ok(is_array($m) && count($m) === 12, '12 parallel changes to one stored list: all kept (' . (is_array($m) ? count($m) : 0) . ')');
    // admin form tokens from 10 parallel page loads
    $setTokens([]);
    $tn = create_session_token($uid);
    qp_user_meta_mutate($uid, 'qp_nonces', function () { return []; });
    spawn_workers('nonce', 10, ['cookie' => cookie_raw($tn)]);
    $st = $pdo->prepare("SELECT meta_value FROM $TM WHERE user_id = ? AND meta_key = 'qp_nonces'"); $st->execute([$uid]);
    $nn = maybe_unserialize($st->fetchColumn());
    ok(is_array($nn) && count($nn['race_test'] ?? []) === 10, '10 parallel page loads: every admin form token is kept (' . count($nn['race_test'] ?? []) . ')');

    echo "-- revoking\n";
    $setTokens([]);
    $r1 = create_session_token($uid); $r2 = create_session_token($uid); $r3 = create_session_token($uid);
    revoke_session_token($r1['token_id'], $uid);
    ok(validate_session_token(cookie_raw($r1)) === null && validate_session_token(cookie_raw($r2)) !== null, 'revoking one login leaves the others');
    $n = qp_revoke_other_sessions($uid, $r2['token_id']);
    ok($n === 1 && validate_session_token(cookie_raw($r2)) !== null && validate_session_token(cookie_raw($r3)) === null, 'log out everywhere else keeps only the current login');

    echo "-- logout needs confirmation\n";
    $setTokens([]);
    $lt = create_session_token($uid);
    $_COOKIE[QP_SESSION_COOKIE] = cookie_raw($lt);
    $nonce = qp_create_nonce('qp_logout', 600);
    $g = http('?logout=1', ['cookie' => cookie_for($lt)]);
    ok($g['code'] === 200 && stripos($g['body'], 'Do you really want to log out') !== false, 'a bare ?logout=1 asks first');
    ok(validate_session_token(cookie_raw($lt)) !== null, 'the login is still valid after that request');
    $bad = http('?logout=1&_qpn=' . urlencode($nonce . 'x'), ['cookie' => cookie_for($lt)]);
    ok($bad['code'] === 200 && validate_session_token(cookie_raw($lt)) !== null, 'a wrong nonce does not log out');
    $go = http('?logout=1&_qpn=' . urlencode($nonce), ['cookie' => cookie_for($lt)]);
    ok(in_array($go['code'], [301, 302, 303], true), 'with the nonce the logout redirects (' . $go['code'] . ')');
    qp_cache_delete('qp_user_tokens_' . $uid);
    ok(validate_session_token(cookie_raw($lt)) === null, 'and the login is gone');
    ok(preg_match('/Set-Cookie: qp_token=deleted|Set-Cookie: qp_token=;|expires=Thu, 01 Jan 1970/i', $go['head']) === 1, 'the cookie is cleared');

    echo "-- keep-alive and sign-in box\n";
    $ht = create_session_token($uid);
    $hb = http('admin/ajax.php', ['cookie' => cookie_for($ht), 'post' => ['action' => 'qp_heartbeat']]);
    $j = json_decode(substr($hb['body'], strpos($hb['body'], '{')), true);
    ok($hb['code'] === 200 && ($j['status'] ?? '') === 'success' && (int)($j['user'] ?? 0) === $uid, 'heartbeat answers for a valid login');
    $hb2 = http('admin/ajax.php', ['post' => ['action' => 'qp_heartbeat']]);
    ok($hb2['code'] === 403, 'heartbeat answers 403 when not signed in (this is what opens the sign-in box)');
    $lg = http('admin/login.php?interim=1');
    ok($lg['code'] === 200 && stripos($lg['head'], 'X-Frame-Options: SAMEORIGIN') !== false && stripos($lg['head'], "frame-ancestors 'self'") !== false, 'the login page can only be framed by this site');
    ok(strpos($lg['body'], 'name="remember"') !== false, 'the login form has "Remember me"');
    $js = http('admin/assets/js/qp-session.js');
    ok($js['code'] === 200 && strpos($js['body'], 'qp_heartbeat') !== false, 'the keep-alive script is served');
    $page = http('admin/index.php', ['cookie' => cookie_for($ht)]);
    ok(strpos($page['body'], 'qp-session.js') !== false, 'admin pages load the keep-alive script');
} finally {
    foreach ($saved as $k => $v) {
        if ($v === null) $pdo->prepare("DELETE FROM $TM WHERE user_id = ? AND meta_key = ?")->execute([$uid, $k]);
        else $pdo->prepare("INSERT INTO $TM (user_id, meta_key, meta_value) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value)")->execute([$uid, $k, $v]);
    }
    if ($o_auth === null) $pdo->prepare('DELETE FROM ' . table_name('site_options') . " WHERE option_name = 'qp_auth_settings'")->execute();
    else update_option_meta('qp_auth_settings', $o_auth);
    qp_cache_delete('qp_user_tokens_' . $uid);
    echo "-- restored: user $uid tokens and form tokens, settings\n";
}
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
