<?php
// Dev tool (C9): the admin area is closed to signed-in accounts without an admin capability, and the qpmeta and tag admin AJAX actions
// check the right to the object they read or change. CLI only; talks to the local site over HTTP.
//   php admin/dev/admin-gate-test.php [--base=https://host/path] [--resolve=127.0.0.1]
// Creates one throwaway account per role (users table and user meta written directly, so no "user registered" hook or email runs),
// signs each in with a session token, and deletes the accounts afterwards. Saves nothing: the allowed qpmeta case is a read.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('QLOPY_INIT', true);
chdir(dirname(__DIR__, 2));
$opt = getopt('', ['base::', 'resolve::']);
require 'db.php'; $pdo = db();
require 'auth.php';
$config = require 'config.php';

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
function admin_markup(string $body): bool { return stripos($body, 'Qlopy Admin') !== false || stripos($body, 'sidebar') !== false || stripos($body, 'do not have permission') !== false; }

$roles = ['admin', 'manager', 'editor', 'author', 'subscriber', ''];
$users = [];
$tag = 'c9gate' . bin2hex(random_bytes(3));
try {
    foreach ($roles as $role) {
        $name = $tag . '_' . ($role !== '' ? $role : 'none');
        $pdo->prepare('INSERT INTO ' . table_name('users') . ' (username, email, password_hash) VALUES (?, ?, ?)')
            ->execute([$name, $name . '@example.test', password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT)]);
        $id = (int)$pdo->lastInsertId();
        if ($role !== '') { update_user_meta($id, 'role', $role); assign_capabilities_to_user($id, get_role_capabilities($role)); }
        $users[$role !== '' ? $role : 'none'] = ['id' => $id, 'cookie' => cookie_for(create_session_token($id))];
    }
    $admin_like = ['admin', 'manager', 'editor', 'author'];

    echo "-- one rule for the login form and the gate\n";
    foreach ($users as $r => $u) {
        $expect = in_array($r, $admin_like, true);
        ok(qp_admin_access_allowed(get_user_by('id', $u['id'])) === $expect, "qp_admin_access_allowed: {$r} " . ($expect ? 'may' : 'may not') . ' enter the admin');
    }

    echo "-- admin pages\n";
    foreach ($users as $r => $u) {
        $res = http('admin/index.php', ['cookie' => $u['cookie']]);
        if (in_array($r, $admin_like, true)) {
            ok($res['code'] === 200, "admin/index.php: {$r} gets 200 (got {$res['code']})");
        } else {
            ok($res['code'] === 404 && !admin_markup($res['body']) && stripos($res['body'], 'Page not found') !== false, "admin/index.php: {$r} gets a plain 404 with no admin markup (got {$res['code']})");
            ok(stripos($res['head'], 'Set-Cookie: ' . QP_SESSION_COOKIE . '=') === false, "admin/index.php: {$r}'s session cookie is not touched");
            $p = http('admin/index.php?page=iit-client-panel', ['cookie' => $u['cookie']]);
            ok($p['code'] === 404 && !admin_markup($p['body']), "a plugin page: {$r} gets 404 (got {$p['code']})");
            $m = http('admin/media.php', ['cookie' => $u['cookie']]);
            ok($m['code'] === 404 && !admin_markup($m['body']), "admin/media.php: {$r} gets 404 (got {$m['code']})");
            $l = http('admin/login.php', ['cookie' => $u['cookie']]);
            ok($l['code'] === 404 && stripos($l['body'], 'password') === false, "admin/login.php: {$r} gets 404, not the form (got {$l['code']})");
            $a = http('admin/ajax.php', ['cookie' => $u['cookie'], 'post' => ['action' => 'qp_heartbeat']]);
            ok($a['code'] === 404 && json_decode($a['body'], true) === ['status' => 'error', 'message' => 'Not found'], "admin/ajax.php: {$r} gets a JSON 404 (got {$a['code']})");
            $s = http('admin/ajax.php', ['cookie' => $u['cookie'], 'post' => ['action' => 'qpmeta_save', 'object_type' => 'option', 'object_id' => 101]]);
            ok($s['code'] === 404, "qpmeta_save to an option: {$r} is stopped at the gate (got {$s['code']})");
            ok(validate_session_token(rawurldecode(substr($u['cookie'], strlen(QP_SESSION_COOKIE) + 1))) !== null, "{$r} is still signed in (the shop session survives)");
        }
    }
    $out = http('admin/index.php');
    ok($out['code'] === 302 && stripos($out['head'], 'login.php') !== false, 'signed out: admin/index.php still redirects to the login page');
    $form = http('admin/login.php');
    ok($form['code'] === 200 && stripos($form['body'], 'password') !== false, 'signed out: the login form is shown');

    echo "-- qpmeta and tag actions check the object\n";
    $other = (int)$pdo->query('SELECT id FROM ' . table_name('posts') . ' WHERE author_id <> ' . (int)$users['author']['id'] . ' ORDER BY id LIMIT 1')->fetchColumn();
    ok($other > 0, 'a post written by someone else exists');
    $ajax = fn (string $r, array $post) => http('admin/ajax.php', ['cookie' => $users[$r]['cookie'], 'post' => $post]);
    $r1 = $ajax('author', ['action' => 'qpmeta_save', 'object_type' => 'option', 'object_id' => 101]);
    ok($r1['code'] === 403, "qpmeta_save to an option: author (admin access, no option rights) gets 403 (got {$r1['code']})");
    $r2 = $ajax('author', ['action' => 'qpmeta_save', 'object_type' => 'post', 'object_id' => $other]);
    ok($r2['code'] === 403, "qpmeta_save to someone else's post: author gets 403 (got {$r2['code']})");
    $r3 = $ajax('author', ['action' => 'qpmeta_save_field_value', 'object_type' => 'option', 'object_id' => 101, 'field_name' => 'x', 'field_value' => 'y']);
    ok($r3['code'] === 403, "qpmeta_save_field_value to an option: author gets 403 (got {$r3['code']})");
    $r4 = $ajax('author', ['action' => 'qpmeta_tag_set', 'post_id' => $other, 'taxonomy' => 'post_tag', 'tag_ids' => []]);
    ok($r4['code'] === 403, "qpmeta_tag_set on someone else's post: author gets 403 (got {$r4['code']})");
    $r5 = $ajax('editor', ['action' => 'qpmeta_get_field_value', 'object_type' => 'option', 'object_id' => 101, 'field_name' => 'site_name']);
    ok($r5['code'] === 403, "qpmeta_get_field_value of an option: editor (no option rights) gets 403 (got {$r5['code']})");
    $r6 = $ajax('manager', ['action' => 'qpmeta_get_field_value', 'object_type' => 'option', 'object_id' => 101, 'field_name' => 'site_name']);
    ok($r6['code'] === 200 && (json_decode($r6['body'], true)['status'] ?? '') === 'success', "qpmeta_get_field_value of an option: manager (manage_admin_pages, opens option pages today) is allowed (got {$r6['code']})");
    $r7 = $ajax('author', ['action' => 'qpmeta_tag_search', 'taxonomy' => 'post_tag', 'q' => 'a']);
    ok($r7['code'] === 200, "qpmeta_tag_search: author (manage_categories) is allowed (got {$r7['code']})");
} finally {
    foreach ($users as $u) {
        $pdo->prepare('DELETE FROM ' . table_name('user_meta') . ' WHERE user_id = ?')->execute([$u['id']]);
        $pdo->prepare('DELETE FROM ' . table_name('users') . ' WHERE id = ?')->execute([$u['id']]);
    }
    echo 'Removed ' . count($users) . " throwaway accounts.\n";
}
echo "\n{$pass} passed, {$fail} failed\n";
exit($fail ? 1 : 0);
