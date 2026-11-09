<?php
// Dev tool: sign-in protection (includes/login-guard.php). CLI only.
//   php admin/dev/login-guard-test.php [--base=https://host/path] [--resolve=127.0.0.1] [--no-http]
// In-process: the real-IP helper, the counters and locks of login_user() / login_user_versatile(), the log, equal answers.
// Over HTTP (skipped with --no-http): the admin sign-in form gives one answer for every kind of failure.
// Creates two throwaway accounts (users table and user meta written directly) and removes them, their log rows and the rows of the
// test IPs afterwards. The HTTP part makes two failed sign-ins from this machine's own IP; their log rows are removed too.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('QLOPY_INIT', true);
chdir(dirname(__DIR__, 2));
$opt = getopt('', ['base::', 'resolve::', 'no-http']);
require 'db.php'; $pdo = db();
require 'auth.php';
require_once 'includes/login-guard.php';
$config = require 'config.php';

$pass = 0; $fail = 0;
function ok($c, $m) { global $pass, $fail; if ($c) $pass++; else $fail++; echo ($c ? 'PASS ' : 'FAIL ') . $m . "\n"; }
$t = qp_login_log_table();
$ipA = '198.51.100.17';   // documentation ranges (RFC 5737): never a real visitor
$ipB = '203.0.113.40';
$ipC = '192.0.2.99';
$as = function (string $ip) { $_SERVER['REMOTE_ADDR'] = $ip; unset($_SERVER['HTTP_CF_CONNECTING_IP'], $_SERVER['HTTP_X_FORWARDED_FOR']); };
$rows = function (array $where, array $args) use ($pdo, $t) { $st = $pdo->prepare("SELECT * FROM {$t} WHERE " . implode(' AND ', $where) . ' ORDER BY id'); $st->execute($args); return $st->fetchAll(PDO::FETCH_ASSOC); };
$age = function (string $ip, int $seconds) use ($pdo, $t) { $pdo->prepare("UPDATE {$t} SET created_at = created_at - ? WHERE ip = ?")->execute([$seconds, $ip]); };
$fake = function (string $ip, ?int $uid, int $n, string $login = 'lgtest') use ($pdo, $t) {
    $st = $pdo->prepare("INSERT INTO {$t} (ip, user_id, login, reason, cleared, created_at) VALUES (?, ?, ?, 'password', 0, ?)");
    for ($i = 0; $i < $n; $i++) $st->execute([$ip, $uid, $login, time() - 60]);
};
$quiet = function (callable $f) { ob_start(); try { return @$f(); } finally { ob_end_clean(); } };   // setcookie() in the CLI

$tag = 'lgtest' . bin2hex(random_bytes(3));
$users = [];
$password = 'Pw-' . bin2hex(random_bytes(6));
try {
    foreach (['admin', 'subscriber'] as $role) {
        $name = $tag . '_' . $role;
        $pdo->prepare('INSERT INTO ' . table_name('users') . ' (username, email, password_hash) VALUES (?, ?, ?)')
            ->execute([$name, $name . '@example.test', password_hash($password, PASSWORD_DEFAULT)]);
        $id = (int)$pdo->lastInsertId();
        update_user_meta($id, 'role', $role);
        assign_capabilities_to_user($id, get_role_capabilities($role));
        $users[$role] = ['id' => $id, 'name' => $name];
    }
    $adm = $users['admin'];

    echo "-- addresses\n";
    ok(qp_ip_in_cidr('172.68.225.68', '172.64.0.0/13') && !qp_ip_in_cidr('172.72.0.1', '172.64.0.0/13'), 'IPv4 ranges');
    ok(qp_ip_in_cidr('2606:4700:10::6816:1', '2606:4700::/32') && !qp_ip_in_cidr('2606:4701::1', '2606:4700::/32'), 'IPv6 ranges');
    ok(qp_ip_in_cidr('2a06:98c7::1', '2a06:98c0::/29') && !qp_ip_in_cidr('2a06:98c8::1', '2a06:98c0::/29'), 'IPv6 range on a part byte (/29)');
    ok(!qp_ip_in_cidr('172.68.225.68', '2606:4700::/32') && !qp_ip_in_cidr('nonsense', '10.0.0.0/8'), 'mixed families and junk never match');
    $_SERVER['REMOTE_ADDR'] = '172.68.225.68'; $_SERVER['HTTP_CF_CONNECTING_IP'] = '198.51.100.5';
    ok(qp_client_ip() === '198.51.100.5', 'from a Cloudflare edge, CF-Connecting-IP is the visitor');
    $_SERVER['REMOTE_ADDR'] = '203.0.113.9';
    ok(qp_client_ip() === '203.0.113.9', 'from anywhere else, a CF-Connecting-IP header is ignored (cannot be faked)');
    $_SERVER['REMOTE_ADDR'] = '172.68.225.68'; $_SERVER['HTTP_CF_CONNECTING_IP'] = 'junk';
    ok(qp_client_ip() === '172.68.225.68', 'a malformed CF-Connecting-IP is ignored');
    ok(function_exists('qp_rest_client_ip') ? true : (require_once 'includes/rest.php') !== null, 'REST helper available');
    $_SERVER['HTTP_CF_CONNECTING_IP'] = '198.51.100.5';
    ok(qp_rest_client_ip() === '198.51.100.5', 'the REST API counts the same visitor IP');

    echo "-- a good sign-in\n";
    $as($ipA);
    $tok = $quiet(fn () => login_user($adm['name'], $password));
    ok(is_array($tok) && (int)$tok['user_id'] === $adm['id'], 'login_user() with the right password gives a session token');
    $r = $rows(['ip = ?'], [$ipA]);
    ok(count($r) === 1 && $r[0]['reason'] === 'success' && (int)$r[0]['user_id'] === $adm['id'] && $r[0]['login'] === $adm['name'], 'the success is logged (IP, account, the name typed)');
    ok(strpos(json_encode($r), $password) === false, 'the password is never logged');
    $tok = $quiet(fn () => login_user_versatile($adm['name'] . '@example.test', $password));
    ok(is_array($tok), 'login_user_versatile() still takes the email address');

    echo "-- failures, the slow-down and the IP lock\n";
    $as($ipB);
    ok($quiet(fn () => login_user($adm['name'], 'wrong')) === false && qp_login_guard_message() === '', 'a wrong password: false, and no lock message yet');
    ok($quiet(fn () => login_user($tag . '_nobody', 'wrong')) === false, 'an unknown name: false');
    $r = $rows(['ip = ?'], [$ipB]);
    ok(count($r) === 2 && $r[0]['reason'] === 'password' && $r[1]['reason'] === 'unknown' && $r[1]['user_id'] === null, 'both are logged, with the reason');
    for ($i = 0; $i < 3; $i++) $quiet(fn () => login_user($adm['name'], 'wrong'));
    $res = $quiet(fn () => login_user($adm['name'], $password));
    $w = qp_login_guard_retry_after();
    ok($res === false && $w > 0 && $w <= 30, "after 5 failures the next try waits (even with the right password): {$w} s");
    ok(stripos(qp_login_guard_message(), 'seconds') !== false, 'message: ' . qp_login_guard_message());
    ok(count($rows(['ip = ?'], [$ipB])) === 5, 'a refused try is not counted or checked');
    $age($ipB, 31);
    $res = $quiet(fn () => login_user($adm['name'], 'wrong'));
    ok($res === false && qp_login_guard_retry_after() === 0, '30 s after the last failure one more try is allowed (and fails)');
    $fake($ipB, null, 4);
    $res = $quiet(fn () => login_user($adm['name'], $password));
    $w = qp_login_guard_retry_after();
    ok($res === false && $w > 800 && $w <= 900, "10 failures lock the IP for 15 minutes: {$w} s");
    ok(stripos(qp_login_guard_message(), 'minutes') !== false, 'message: ' . qp_login_guard_message());
    $as($ipC);
    ok(is_array($quiet(fn () => login_user($adm['name'], $password))), 'another IP is not affected by that lock');
    $as($ipB);
    $age($ipB, 900);
    ok(is_array($quiet(fn () => login_user($adm['name'], $password))), 'after the window the IP may sign in again');
    $left = $rows(['ip = ?', 'cleared = 0'], [$ipB]);
    ok($left === [], 'a successful sign-in clears that IP\'s counter');

    echo "-- the account lock\n";
    $pdo->prepare("DELETE FROM {$t} WHERE user_id = ? OR ip IN (?, ?, ?)")->execute([$adm['id'], $ipA, $ipB, $ipC]);
    $as($ipA);
    $quiet(fn () => login_user($adm['name'], $password));   // ipA: the owner's usual connection
    $fake('203.0.113.' . random_int(100, 200), $adm['id'], 20, $adm['name']);
    $as($ipC);
    $res = $quiet(fn () => login_user($adm['name'], $password));
    $w = qp_login_guard_retry_after();
    ok($res === false && $w > 0, "20 failures on one account lock it from a new IP: {$w} s");
    $as($ipA);
    ok(is_array($quiet(fn () => login_user($adm['name'], $password))), 'but not from an IP that signed in to it before (the owner is not locked out)');

    echo "-- one answer for every failure\n";
    $pdo->prepare("DELETE FROM {$t} WHERE ip IN (?, ?, ?)")->execute([$ipA, $ipB, $ipC]);
    $as($ipC);
    $gate = static function ($u) { return qp_admin_access_allowed($u); };
    $sub = $users['subscriber'];
    ok($quiet(fn () => login_user($sub['name'], $password, null, $gate)) === false && qp_login_guard_message() === '', 'right password, account not allowed on this form: plain false');
    $r = $rows(['ip = ?'], [$ipC]);
    ok(($r[0]['reason'] ?? '') === 'not_allowed', 'logged as not_allowed');
    ok(is_array($quiet(fn () => login_user($adm['name'], $password, null, $gate))), 'an allowed account passes the same check');
    $time = function (callable $f) { $s = microtime(true); $f(); return microtime(true) - $s; };
    $pdo->prepare("DELETE FROM {$t} WHERE ip = ?")->execute([$ipC]);
    $tu = $time(fn () => $quiet(fn () => login_user($tag . '_nobody', 'wrong')));
    $tw = $time(fn () => $quiet(fn () => login_user($adm['name'], 'wrong')));
    ok($tu > $tw * 0.6, sprintf('an unknown name takes as long as a wrong password (%.0f ms vs %.0f ms)', $tu * 1000, $tw * 1000));

    echo "-- a broken log never locks anyone out\n";
    $pdo->prepare("DELETE FROM {$t} WHERE ip = ?")->execute([$ipC]);
    $GLOBALS['lg_break'] = true;
    add_filter('qp_login_guard_limits', function ($l) { return $GLOBALS['lg_break'] ? ['window' => 'x'] + $l : $l; });
    ok(is_array($quiet(fn () => login_user($adm['name'], $password))), 'odd limits from a filter still let a good sign-in through');
    $GLOBALS['lg_break'] = false;

    if (!isset($opt['no-http'])) {
        echo "-- the admin sign-in form (HTTP)\n";
        $base = rtrim($opt['base'] ?? $config['site_url'], '/');
        $host = parse_url($base, PHP_URL_HOST);
        $port = parse_url($base, PHP_URL_PORT) ?: (parse_url($base, PHP_URL_SCHEME) === 'https' ? 443 : 80);
        $resolve = $opt['resolve'] ?? '127.0.0.1';
        $post = function (array $fields) use ($base, $host, $port, $resolve, $config) {
            $c = curl_init($base . '/' . ($config['admin_dir'] ?? 'admin') . '/login.php');
            curl_setopt_array($c, [CURLOPT_RESOLVE => ["$host:$port:$resolve"], CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0,
                CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($fields), CURLOPT_TIMEOUT => 30,
                CURLOPT_HTTPHEADER => ['CF-Connecting-IP: 198.51.100.200']]);   // must be ignored: the request does not come from Cloudflare
            $body = (string)curl_exec($c); $code = curl_getinfo($c, CURLINFO_HTTP_CODE); curl_close($c);
            return [$code, $body];
        };
        [$c1, $b1] = $post(['username' => $tag . '_nobody', 'password' => 'wrong']);
        [$c2, $b2] = $post(['username' => $sub['name'], 'password' => $password]);
        ok($c1 === 200 && stripos($b1, 'Invalid username or password') !== false, "unknown name: 'Invalid username or password' (got {$c1})");
        ok($c2 === 200 && stripos($b2, 'Invalid username or password') !== false && stripos($b2, 'permission') === false, 'a customer account with the right password gets the same answer (no "permission" hint)');
        ok($rows(['ip = ?'], ['198.51.100.200']) === [], 'the faked CF-Connecting-IP header was not used');
        ok(count($rows(["login IN (?, ?)"], [$tag . '_nobody', $sub['name']])) >= 2, 'both tries are in the log');
    }
} finally {
    $ids = array_column($users, 'id');
    foreach ($ids as $id) {
        $pdo->prepare('DELETE FROM ' . table_name('user_meta') . ' WHERE user_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM ' . table_name('users') . ' WHERE id = ?')->execute([$id]);
        $pdo->prepare("DELETE FROM {$t} WHERE user_id = ?")->execute([$id]);
    }
    $pdo->prepare("DELETE FROM {$t} WHERE login LIKE ? OR ip IN (?, ?, ?)")->execute([$tag . '%', $ipA, $ipB, $ipC]);
    $pdo->prepare("DELETE FROM {$t} WHERE ip LIKE '203.0.113.%'")->execute();
    echo 'Removed ' . count($ids) . " throwaway accounts and their log rows.\n";
}
echo "\n{$pass} passed, {$fail} failed\n";
exit($fail ? 1 : 0);
