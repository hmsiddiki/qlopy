<?php
// Dev tool (C6): checks the front-end widget system and the admin widget system. CLI only.
//   php admin/dev/widgets-test.php [--base=https://host/path] [--resolve=127.0.0.1]
// Part 1 and 2 run in a child process that is signed in as the first administrator (a temporary session, removed at the end).
// Part 3 calls the real Dashboard, Widgets screen and AJAX handlers over HTTP with that session.
// Everything it changes (the widgets option, layouts, the session, a draft post) is restored or removed at the end.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('QLOPY_INIT', true);
chdir(dirname(__DIR__, 2));
$opt = getopt('', ['base::', 'resolve::', 'cookie::', 'uid::']);
if (empty($opt['cookie'])) {
    // Parent: make a session for an administrator, then run this file again as that user (the cookie must exist before auth.php loads).
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
require_once 'includes/admin-widgets.php';
$cookie = $opt['cookie'];
$base = rtrim($opt['base'] ?? $config['site_url'], '/');
$host = parse_url($base, PHP_URL_HOST);
$port = parse_url($base, PHP_URL_PORT) ?: (parse_url($base, PHP_URL_SCHEME) === 'https' ? 443 : 80);
$resolve = $opt['resolve'] ?? '127.0.0.1';
$pass = 0; $fail = 0;
function ok($c, $m) { global $pass, $fail; if ($c) $pass++; else $fail++; echo ($c ? 'PASS ' : 'FAIL ') . $m . "\n"; }

$origWidgets = get_option_meta('qp_widgets');
$origDefault = get_option_meta('qp_admin_layout_default_dashboard');
$origLayout = get_user_meta($uid, 'qp_admin_layout_dashboard');
$draftIds = [];
$cleanup = function () use ($uid, $origWidgets, $origDefault, $origLayout, &$draftIds, $pdo) {
    update_option_meta('qp_widgets', $origWidgets ?: []);
    if ($origWidgets === null || $origWidgets === false) $pdo->exec("DELETE FROM " . table_name('site_options') . " WHERE option_name = 'qp_widgets'");
    if ($origDefault) update_option_meta('qp_admin_layout_default_dashboard', $origDefault);
    else $pdo->exec("DELETE FROM " . table_name('site_options') . " WHERE option_name = 'qp_admin_layout_default_dashboard'");
    update_user_meta($uid, 'qp_admin_layout_dashboard', $origLayout ?: null);
    foreach ($draftIds as $id) $pdo->exec("DELETE FROM " . table_name('posts') . " WHERE id = " . (int)$id);
};
try {
    // ================= Part 1: front-end widgets =================
    echo "-- registry\n";
    update_option_meta('qp_widgets', []); qp_widgets_store(true);
    ok(register_widget(['id' => 'wt-hello', 'name' => 'Hello', 'fields' => [['id' => 'title', 'type' => 'text', 'label' => 'Title', 'default' => 'Hi'], ['id' => 'n', 'type' => 'number', 'min' => 1, 'max' => 5, 'default' => 3], ['id' => 'flag', 'type' => 'checkbox'], ['id' => 'kind', 'type' => 'select', 'options' => ['a' => 'A', 'b' => 'B'], 'default' => 'a'], ['id' => 'raw', 'type' => 'html']],
        'render' => function ($a, $s) { echo $a['before_widget'] . htmlspecialchars($s['title']) . '|' . $s['n'] . '|' . ($s['flag'] ? 'on' : 'off') . '|' . $s['kind'] . $a['after_widget']; }]) === 'wt-hello', 'array widget registered');
    ok(register_widget(['id' => 'wt-boom', 'name' => 'Boom', 'render' => function () { echo 'partial'; throw new RuntimeException('x'); }]) === 'wt-boom', 'throwing widget registered');
    ok(register_widget(['id' => 'wt-novary', 'name' => 'Per visitor', 'cache' => false, 'render' => function ($a) { echo $a['before_widget'] . 'cart' . $a['after_widget']; }]) === 'wt-novary', 'per-visitor widget registered');
    ok(register_widget(['id' => 'Bad Id!', 'render' => function () {}]) === false, 'a bad id is refused');
    ok(register_widget(['id' => 'x']) === false, 'a widget without a render callback is refused');
    ok(qp_widget_type('text') instanceof QP_Widget && qp_widget_type('search') && qp_widget_type('recent-posts') && qp_widget_type('terms') && qp_widget_type('nav-menu') && qp_widget_type('archives') && qp_widget_type('recent-comments'), 'all seven core widget types load');
    ok(qp_widget_type('nope') === null, 'an unknown type is null');

    echo "-- sanitising\n";
    $t = qp_widget_type('wt-hello');
    $s = $t->update(['title' => '<b>Bold</b> x', 'n' => '99', 'flag' => '1', 'kind' => 'zzz', 'raw' => '<script>1</script>'], []);
    ok($s['title'] === 'Bold x' && $s['n'] === 5 && $s['flag'] === true && $s['kind'] === 'a', 'tags stripped, number clamped, checkbox true, bad select falls back');
    ok($s['raw'] === '<script>1</script>', 'an administrator may save raw HTML in an html field');
    $s2 = $t->update(['n' => 'abc'], []);
    ok($s2['n'] === 3 && $s2['flag'] === false && $s2['title'] === 'Hi', 'garbage number -> default, missing checkbox -> false, missing text -> default');
    ok(qp_widget_fields_sanitize([['id' => 'u', 'type' => 'url']], ['u' => 'javascript:alert(1)'])['u'] === '' && qp_widget_fields_sanitize([['id' => 'u', 'type' => 'url']], ['u' => 'https://a.test/x'])['u'] === 'https://a.test/x', 'url field accepts http(s), refuses javascript:');

    echo "-- areas and placement\n";
    ok(register_widget_area('wt-side', ['name' => 'Test side', 'before_widget' => '<div id="%1$s" class="%2$s">', 'after_widget' => '</div>']) === 'wt-side', 'area registered');
    ok(register_widget_area('inactive') === false && register_widget_area('B A D') === false, 'reserved or bad area ids refused');
    ob_start(); $printed = dynamic_widget_area('wt-nobody-registered'); $o = ob_get_clean();
    ok($printed === false && $o === '', 'an unregistered area prints nothing');
    ok(is_widget_area_active('wt-side') === false, 'an empty area is not active');
    $a = add_widget_to_area('wt-side', 'wt-hello', ['title' => 'First', 'n' => 2]);
    $b = add_widget_to_area('wt-side', 'wt-hello', ['title' => 'Second']);
    ok($a && $b && $a !== $b, 'two instances with distinct ids: ' . $a . ', ' . $b);
    ok(is_widget_area_active('wt-side'), 'area with widgets is active');
    ob_start(); $printed = dynamic_widget_area('wt-side'); $o = ob_get_clean();
    ok($printed && strpos($o, 'First|2|off|a') < strpos($o, 'Second|3|off|a') && strpos($o, 'id="' . $a . '"') !== false && strpos($o, 'class="widget_wt-hello"') !== false, 'printed in order, wrapper has the instance id and class');
    ok(add_widget_to_area('wt-side', 'does-not-exist') === false && add_widget_to_area('no-area', 'wt-hello') === false, 'unknown type or area refused');
    ok(move_widget($b, 'wt-side', 0) && qp_widgets_store()['areas']['wt-side'][0] === $b, 'move_widget reorders');
    ok($a === qp_widgets_store()['areas']['wt-side'][1] && qp_widgets_store()['instances'][$a]['settings']['title'] === 'First', 'ids and settings stay stable when a widget moves');
    ok(move_widget($b, 'inactive') && !in_array($b, qp_widgets_store()['areas']['wt-side'], true) && in_array($b, qp_widgets_store()['inactive'], true), 'move to Inactive');
    ok(remove_widget($b) && !isset(qp_widgets_store()['instances'][$b]), 'remove_widget deletes the instance');

    echo "-- default widgets\n";
    register_widget_area('wt-defaults', ['name' => 'With defaults', 'default_widgets' => [['type' => 'wt-hello', 'settings' => ['title' => 'Default one']], ['type' => 'search'], ['type' => 'gone-type']]]);
    ob_start(); dynamic_widget_area('wt-defaults'); $o = ob_get_clean();
    ok(strpos($o, 'Default one') !== false && strpos($o, 'widget-search') !== false, 'defaults appear on first print');
    $ids = qp_widgets_store()['areas']['wt-defaults'] ?? [];
    ok(count($ids) === 2 && !empty(qp_widgets_store()['defaults_done']['wt-defaults']), 'two stored (unknown type skipped), marked as applied');
    foreach ($ids as $i) remove_widget($i);
    ob_start(); dynamic_widget_area('wt-defaults'); $o = ob_get_clean();
    ok($o === '', 'defaults are never re-applied over an owner who emptied the area');
    register_widget_area('wt-owner', ['name' => 'Owner chose', 'default_widgets' => [['type' => 'search']]]);
    add_widget_to_area('wt-owner', 'wt-hello', ['title' => 'Mine']);
    $store = qp_widgets_store(true); $store['defaults_done'] = []; qp_widgets_save($store);
    ob_start(); dynamic_widget_area('wt-owner'); $o = ob_get_clean();
    ok(strpos($o, 'Mine') !== false && strpos($o, 'widget-search') === false, 'defaults do not touch an area the owner already filled');

    echo "-- theme switch\n";
    $store = qp_widgets_store(true);
    $keepIds = $store['areas']['wt-owner'];
    $areasRef = &qp_widget_areas_ref(); $saved = $areasRef['wt-owner']; unset($areasRef['wt-owner']);   // the new theme lacks the area
    $state = qp_widgets_admin_state();
    $parked = array_filter($state['inactive'], function ($w) use ($keepIds) { return in_array($w['id'], $keepIds, true) && ($w['from'] ?? '') === 'wt-owner'; });
    ok(count($parked) === count($keepIds), 'widgets of a missing area wait in Inactive, flagged with their old area');
    ok(qp_widgets_apply_layout(['areas' => ['wt-side' => []], 'inactive' => []]) && qp_widgets_store(true)['areas']['wt-owner'] === $keepIds, 'saving another layout does not lose them');
    $areasRef['wt-owner'] = $saved;
    ob_start(); dynamic_widget_area('wt-owner'); $o = ob_get_clean();
    ok(strpos($o, 'Mine') !== false, 'they return when the area is registered again');

    echo "-- conditions, error boundary, caching flags\n";
    $c1 = add_widget_to_area('wt-side', 'wt-hello', ['title' => 'OnlyOut'], null, ['auth' => 'out']);
    $c2 = add_widget_to_area('wt-side', 'wt-hello', ['title' => 'OnlyFront'], null, ['pages' => ['front']]);
    $c3 = add_widget_to_area('wt-side', 'wt-hello', ['title' => 'OnlySingleNews'], null, ['pages' => ['single'], 'post_types' => ['news']]);
    $_GET['route'] = 'frontpage'; unset($GLOBALS['route']);
    ob_start(); dynamic_widget_area('wt-side'); $o = ob_get_clean();
    ok(strpos($o, 'OnlyFront') !== false && strpos($o, 'OnlySingleNews') === false && strpos($o, 'OnlyOut') === false, 'conditions: front page, signed in (this test is signed in)');
    $_GET['route'] = 'singular'; $_GET['post_type'] = 'news';
    ob_start(); dynamic_widget_area('wt-side'); $o = ob_get_clean();
    ok(strpos($o, 'OnlySingleNews') !== false && strpos($o, 'OnlyFront') === false, 'conditions: single "news" post');
    $_GET['post_type'] = 'post';
    ob_start(); dynamic_widget_area('wt-side'); $o = ob_get_clean();
    ok(strpos($o, 'OnlySingleNews') === false, 'conditions: another post type hides it');
    unset($_GET['route'], $_GET['post_type']);
    add_widget_to_area('wt-side', 'wt-hello', ['title' => 'Plain']);
    $boom = add_widget_to_area('wt-side', 'wt-boom');
    $lvl = ob_get_level();
    ob_start(); $printed = dynamic_widget_area('wt-side'); $o = ob_get_clean();
    ok(strpos($o, 'partial') === false && strpos($o, 'Plain') !== false && ob_get_level() === $lvl && $printed, 'a throwing widget prints nothing, buffers are balanced, the rest of the area still prints');
    $np = add_widget_to_area('wt-side', 'wt-novary');
    $GLOBALS['qp_pc_block'] = [];
    if (function_exists('qp_page_cache_mark_uncacheable')) {
        ob_start(); dynamic_widget_area('wt-side'); ob_end_clean();
        ok(!empty($GLOBALS['qp_pc_block']['widget']), 'a cache=false widget marks the page uncacheable');
    } else ok(true, 'page cache module not loaded in CLI (flag check skipped)');

    echo "-- stored data safety\n";
    $store = qp_widgets_store(true);
    $store['instances']['evil-1'] = ['type' => 'system', 'settings' => []];
    $store['areas']['wt-side'][] = 'evil-1'; $store['areas']['wt-side'][] = 'missing-9';
    qp_widgets_save($store);
    ob_start(); dynamic_widget_area('wt-side'); ob_end_clean();
    ok(true, 'an unknown type id and a dangling instance id in stored data are skipped without error');
    ok(!in_array('evil-1', qp_widget_area_instance_ids('wt-side'), true), 'unknown type is not listed as active');

    echo "-- core widgets render\n";
    foreach (['recent-posts', 'terms', 'archives', 'recent-comments', 'search', 'text', 'nav-menu'] as $ty) {
        $iid = add_widget_to_area('wt-side', $ty, $ty === 'text' ? ['title' => 'T', 'content' => '<em>hello</em>'] : ($ty === 'terms' ? ['taxonomy' => 'category', 'show_count' => true] : []));
        ob_start();
        try { qp_widget_type($ty)->widget(['before_widget' => '<i>', 'after_widget' => '</i>', 'before_title' => '<h>', 'after_title' => '</h>'], array_merge(qp_widget_type($ty)->defaults(), qp_widgets_store()['instances'][$iid]['settings'])); $good = true; } catch (Throwable $e) { $good = false; echo $e->getMessage() . "\n"; }
        $o = ob_get_clean();
        ok($good, "core widget '$ty' runs" . ($ty === 'text' ? ' and prints its HTML: ' . (strpos($o, '<em>hello</em>') !== false ? 'yes' : 'no') : ''));
    }

    // ================= Part 2: admin widgets =================
    echo "-- admin registry and layouts (as user $uid)\n";
    
    ok(current_user_can('manage_options'), 'test user is an administrator');
    register_admin_widget('wt-cap', ['title' => 'Cap only', 'screen' => 'dashboard', 'capability' => 'cap_nobody_has', 'callback' => function () { echo 'secret'; }]);
    register_admin_widget('wt-boom', ['title' => 'Boom', 'screen' => 'dashboard', 'column' => 2, 'callback' => function () { echo 'half'; throw new Exception('boom'); }]);
    register_admin_widget('wt-fixed', ['title' => 'Fixed', 'screen' => 'dashboard', 'column' => 1, 'removable' => false, 'callback' => function () { echo 'fixed'; }]);
    register_admin_widget('wt-cfg', ['title' => 'Configurable', 'screen' => 'dashboard', 'configurable' => true, 'fields' => [['id' => 'n', 'type' => 'number', 'min' => 1, 'max' => 9, 'default' => 4]], 'callback' => function ($s) { echo 'n=' . $s['n']; }]);
    register_admin_widget('wt-other', ['title' => 'Other screen', 'screen' => 'another', 'callback' => function () { echo 'x'; }]);
    $w = qp_admin_widgets_for('dashboard');
    ok(isset($w['at_a_glance'], $w['site_health'], $w['activity'], $w['welcome']), 'the four core dashboard widgets are registered');
    ok(!isset($w['wt-cap']), 'a widget the user lacks the capability for is not listed');
    ok(!isset($w['wt-other']) && isset(qp_admin_widgets_for('another')['wt-other']), 'widgets belong to their own screen');
    ok(!$w['at_a_glance']['removable'] && !$w['site_health']['removable'] && $w['welcome']['removable'], 'At a glance and Site health are unremovable, Welcome is removable');
    update_user_meta($uid, 'qp_admin_layout_dashboard', null); $pdo->exec("DELETE FROM " . table_name('site_options') . " WHERE option_name = 'qp_admin_layout_default_dashboard'"); qp_option_map_flush();
    $lay = qp_admin_layout_resolve('dashboard');
    $flat = []; foreach ($lay['columns'] as $ci => $col) foreach ($col as $x) $flat[$x['id']] = $ci;
    ok($lay['source'] === 'code' && $lay['cols'] === 3 && $flat['at_a_glance'] === 0 && $flat['site_health'] === 1 && $flat['wt-boom'] === 1, 'code defaults: columns follow the declared column');
    ok(array_search('at_a_glance', array_column($lay['columns'][0], 'id')) < array_search('wt-fixed', array_column($lay['columns'][0], 'id')), 'priority high comes before core inside a column');
    // filter can drop a widget
    add_filter('qp_admin_widgets', function ($ws, $screen) { if ($screen === 'dashboard' && !empty($GLOBALS['wt_drop'])) unset($ws['welcome']); return $ws; }, 10, 2);
    $GLOBALS['wt_drop'] = true; ok(!isset(qp_admin_widgets_for('dashboard')['welcome']), 'the qp_admin_widgets filter can drop a widget'); $GLOBALS['wt_drop'] = false;
    $GLOBALS['hooks_reset'] = true;
    // sanitise: hiding unremovable is refused
    [$l, $err] = qp_admin_layout_sanitize('dashboard', ['cols' => 2, 'order' => [['at_a_glance'], ['activity']], 'hidden' => ['at_a_glance', 'wt-cfg'], 'collapsed' => []]);
    ok($l === null && strpos((string)$err, 'At a glance') !== false, 'the server refuses to hide an unremovable box: ' . $err);
    [$l, $err] = qp_admin_layout_sanitize('dashboard', ['cols' => 9, 'order' => []]);
    ok($l === null, 'column count outside 1..4 refused');
    [$l, $err] = qp_admin_layout_sanitize('dashboard', ['cols' => 3, 'order' => [['wt-cfg', 'ghost', 'wt-cap'], ['activity', 'activity']], 'hidden' => ['wt-cfg', 'ghost'], 'collapsed' => ['activity', 'ghost']]);
    ok($l && $l['order'][0] === ['wt-cfg'] && $l['order'][1] === ['activity'] && $l['hidden'] === ['wt-cfg'] && $l['collapsed'] === ['activity'], 'unknown, not-permitted and duplicate ids are dropped');
    update_user_meta($uid, qp_admin_layout_meta_key('dashboard'), $l);
    $lay = qp_admin_layout_resolve('dashboard');
    $ids = []; foreach ($lay['columns'] as $col) foreach ($col as $x) $ids[] = $x['id'];
    ok($lay['source'] === 'user' && $lay['cols'] === 3 && in_array('at_a_glance', $ids, true) && in_array('site_health', $ids, true), 'a saved layout still shows widgets it did not mention (and all unremovable ones)');
    ok($lay['hidden'] === ['wt-cfg'] && $lay['collapsed'] === ['activity'], 'hidden and collapsed come back');
    ok(count($lay['columns']) === 3 && $lay['columns'][0][0]['id'] === 'wt-cfg', 'per-user column count and order');
    // a stored layout that tries to hide an unremovable box is overridden
    update_user_meta($uid, qp_admin_layout_meta_key('dashboard'), ['cols' => 2, 'order' => [], 'hidden' => ['site_health'], 'collapsed' => []]);
    ok(!in_array('site_health', qp_admin_layout_resolve('dashboard')['hidden'], true), 'a tampered stored layout cannot hide an unremovable box');
    update_user_meta($uid, qp_admin_layout_meta_key('dashboard'), null);
    // site default
    update_option_meta('qp_admin_layout_default_dashboard', ['cols' => 1, 'order' => [['activity', 'at_a_glance']], 'hidden' => [], 'collapsed' => []]); qp_option_map_flush();
    $lay = qp_admin_layout_resolve('dashboard');
    ok($lay['source'] === 'site' && $lay['cols'] === 1 && count($lay['columns']) === 1 && $lay['columns'][0][0]['id'] === 'activity', 'site default applies when the user saved nothing, and wins over code');
    $pdo->exec("DELETE FROM " . table_name('site_options') . " WHERE option_name = 'qp_admin_layout_default_dashboard'"); qp_option_map_flush();
    // error boundary
    $lvl = ob_get_level();
    $out = qp_admin_widget_output($w['wt-boom'], []);
    ok(strpos($out, 'could not load') !== false && strpos($out, 'half') === false && ob_get_level() === $lvl, 'a throwing admin widget shows "could not load" and keeps the buffers balanced');
    // render the whole thing
    ob_start(); qp_admin_widgets_render('dashboard'); $html = ob_get_clean();
    ok(strpos($html, 'data-id="at_a_glance"') !== false && strpos($html, 'could not load') !== false && strpos($html, 'secret') === false && strpos($html, 'data-id="wt-cap"') === false, 'dashboard renders; the failing box does not stop the others; no capability leak');
    ok(strpos($html, 'n=4') !== false, 'a configurable widget gets its default settings');

    // ================= Part 3: over HTTP =================
    echo "-- HTTP (Dashboard, Widgets screen, AJAX)\n";
    $adminBase = $base . '/' . ($config['admin_dir'] ?? 'admin') . '/';
    $cookieName = QP_SESSION_COOKIE;
    $http = function ($path, $post = null) use ($adminBase, $host, $port, $resolve, $cookie, $cookieName) {
        $c = curl_init($adminBase . $path);
        $o = [CURLOPT_RESOLVE => ["$host:$port:$resolve"], CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_COOKIE => $cookieName . '=' . $cookie, CURLOPT_FOLLOWLOCATION => false];
        if ($post !== null) { $o[CURLOPT_POST] = true; $o[CURLOPT_POSTFIELDS] = http_build_query($post); }
        curl_setopt_array($c, $o);
        $body = (string)curl_exec($c); $code = curl_getinfo($c, CURLINFO_HTTP_CODE); curl_close($c);
        return ['code' => $code, 'body' => $body, 'json' => json_decode(preg_replace("~^\xEF\xBB\xBF~", "", $body), true)];
    };
    $r = $http('index.php?page=dashboard');
    ok($r['code'] === 200 && strpos($r['body'], 'id="qpw-grid"') !== false && strpos($r['body'], 'data-id="at_a_glance"') !== false, 'Dashboard page renders the grid (HTTP ' . $r['code'] . ')');
    ok(preg_match('/data-config="([^"]+)"/', $r['body'], $m) === 1, 'dashboard carries its config');
    $cfg = json_decode(html_entity_decode($m[1] ?? '{}', ENT_QUOTES), true);
    $n = $cfg['nonce'] ?? '';
    $ajax = 'ajax.php';
    $r = $http($ajax, ['action' => 'qpw_layout_save', '_n' => 'bad', 'screen' => 'dashboard', 'layout' => '{}']);
    ok($r['code'] === 403, 'layout save with a bad nonce is refused');
    $r = $http($ajax, ['action' => 'qpw_layout_save', '_n' => $n, 'screen' => 'dashboard', 'layout' => json_encode(['cols' => 2, 'order' => [['at_a_glance'], ['site_health']], 'hidden' => ['site_health'], 'collapsed' => []])]);
    ok($r['code'] === 422 && ($r['json']['status'] ?? '') === 'error', 'over HTTP: hiding Site health is refused (' . ($r['json']['message'] ?? $r['body']) . ')');
    $r = $http($ajax, ['action' => 'qpw_layout_save', '_n' => $n, 'screen' => 'dashboard', 'layout' => json_encode(['cols' => 3, 'order' => [['at_a_glance'], ['site_health'], ['welcome']], 'hidden' => ['welcome'], 'collapsed' => ['activity']])]);
    ok(($r['json']['status'] ?? '') === 'success', 'a valid layout saves');
    $r = $http('index.php?page=dashboard');
    ok(strpos($r['body'], 'qpw-cols-3') !== false && preg_match('/data-id="welcome"[^>]*hidden/', $r['body']) === 1, 'the saved layout (3 columns, Welcome hidden) is what the next page load draws');
    $r = $http($ajax, ['action' => 'qpw_widget_load', '_n' => $n, 'screen' => 'dashboard', 'id' => 'site_health']);
    ok(($r['json']['status'] ?? '') === 'success' && strpos($r['json']['html'] ?? '', 'PHP') !== false, 'Site health loads lazily');
    $r = $http($ajax, ['action' => 'qpw_widget_load', '_n' => $n, 'screen' => 'dashboard', 'id' => 'wt-cap']);
    ok($r['code'] === 404, 'a lazy load for a widget the user may not see is refused');
    $r = $http($ajax, ['action' => 'qpw_widget_load', '_n' => $n, 'screen' => 'dashboard', 'id' => 'activity']);
    ok(($r['json']['status'] ?? '') === 'success' && strpos($r['json']['html'] ?? '', 'Latest content') !== false, 'Recent activity loads');
    $r = $http($ajax, ['action' => 'qpw_widget_settings', '_n' => $n, 'screen' => 'dashboard', 'id' => 'activity', 'settings' => ['x' => 1]]);
    ok($r['code'] === 404, 'settings for a non-configurable widget are refused');
    $r = $http($ajax, ['action' => 'qpw_layout_reset', '_n' => $n, 'screen' => 'dashboard']);
    $r2 = $http('index.php?page=dashboard');
    ok(($r['json']['status'] ?? '') === 'success' && strpos($r2['body'], 'qpw-cols-3') !== false, 'Reset returns to the default layout');
    $r = $http($ajax, ['action' => 'qpw_layout_default', '_n' => $n, 'screen' => 'dashboard', 'layout' => json_encode(['cols' => 1, 'order' => [['at_a_glance', 'site_health']], 'hidden' => [], 'collapsed' => []])]);
    ok(($r['json']['status'] ?? '') === 'success', 'an administrator can set the site default layout');
    // Widgets screen
    $r = $http('widgets.php');
    ok($r['code'] === 200 && strpos($r['body'], 'id="qpwf"') !== false, 'Appearance > Widgets renders (HTTP ' . $r['code'] . ')');
    preg_match('/id="qpwf" class="qpwf-layout" data-config="([^"]+)"/', $r['body'], $m);
    $fc = json_decode(html_entity_decode($m[1] ?? '{}', ENT_QUOTES), true);
    $fn = $fc['nonce'] ?? '';
    ok(count($fc['state']['types'] ?? []) >= 7, 'the screen lists the core widget types plus registered ones');
    $r = $http($ajax, ['action' => 'qpw_front_add', '_n' => 'bad', 'type' => 'search', 'area' => 'inactive']);
    ok($r['code'] === 403, 'front-widget add with a bad nonce is refused');
    $r = $http($ajax, ['action' => 'qpw_front_add', '_n' => $fn, 'type' => 'text', 'area' => 'inactive']);
    $newId = $r['json']['id'] ?? '';
    ok($newId !== '' && ($r['json']['status'] ?? '') === 'success', 'add a Text widget to Inactive over HTTP: ' . $newId);
    $r = $http($ajax, ['action' => 'qpw_front_save', '_n' => $fn, 'id' => $newId, 'settings' => ['title' => 'Hello <i>x</i>', 'content' => '<b>bold</b>', 'autop' => '1'], 'cond' => json_encode(['auth' => 'out', 'pages' => ['front', 'bogus'], 'post_types' => ['post', 'x y']])]);
    qp_widgets_store(true); qp_option_map_flush();
    $inst = (get_option_meta('qp_widgets')['instances'][$newId] ?? []);
    ok(($inst['settings']['title'] ?? '') === 'Hello x' && ($inst['settings']['content'] ?? '') === '<b>bold</b>' && ($inst['cond']['pages'] ?? []) === ['front'] && ($inst['cond']['post_types'] ?? []) === ['post'], 'settings and conditions saved and sanitised (administrator keeps HTML)');
    $r = $http($ajax, ['action' => 'qpw_front_form', '_n' => $fn, 'id' => $newId]);
    ok(($r['json']['status'] ?? '') === 'success' && strpos($r['json']['html'] ?? '', 'name="settings[content]"') !== false, 'the settings form is served');
    $r = $http($ajax, ['action' => 'qpw_front_layout', '_n' => $fn, 'layout' => json_encode(['areas' => [], 'inactive' => [$newId, 'ghost-1']])]);
    ok(($r['json']['status'] ?? '') === 'success', 'layout save ignores unknown ids');
    $r = $http($ajax, ['action' => 'qpw_front_delete', '_n' => $fn, 'id' => $newId]);
    ok(($r['json']['status'] ?? '') === 'success', 'delete a widget over HTTP');
    $r = $http($ajax, ['action' => 'qpw_front_delete', '_n' => $fn, 'id' => 'nope-1']);
    ok($r['code'] === 404, 'delete of an unknown widget -> 404');
} catch (Throwable $e) {
    $fail++;
    echo 'FAIL exception: ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine() . "\n";
}
$cleanup();
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
