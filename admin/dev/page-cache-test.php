<?php
// Dev tool (C1e step 5): correctness matrix for the page cache, over real HTTP against this site. CLI only.
//   php admin/dev/page-cache-test.php [--base=https://host/path] [--resolve=127.0.0.1]
// It switches the cache on, runs every scenario, then restores: cache off, the active plugin list, the active theme, the test
// product's price and the test session. Needs the site's own PHP to be reachable over HTTP from this machine.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('QLOPY_INIT', true);
chdir(dirname(__DIR__, 2));
require 'db.php'; $pdo = db();
require 'auth.php';
require_once 'includes/page-cache.php';
$GLOBALS['config'] = $config;
$opt = getopt('', ['base::', 'resolve::']);
$base = rtrim($opt['base'] ?? $config['site_url'], '/');
$host = parse_url($base, PHP_URL_HOST);
$port = parse_url($base, PHP_URL_PORT) ?: (parse_url($base, PHP_URL_SCHEME) === 'https' ? 443 : 80);
$resolve = $opt['resolve'] ?? '127.0.0.1';
$pass = 0; $fail = 0;
function ok($c, $m) { global $pass, $fail; if ($c) $pass++; else $fail++; echo ($c ? 'PASS ' : 'FAIL ') . $m . "\n"; }
function req($path, $o = []) {
    global $base, $host, $port, $resolve;
    $c = curl_init($base . '/' . ltrim($path, '/'));
    $opts = [CURLOPT_RESOLVE => ["$host:$port:$resolve"], CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0, CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 30, CURLOPT_HTTPHEADER => $o['h'] ?? []];
    if (!empty($o['cookie'])) $opts[CURLOPT_COOKIE] = $o['cookie'];
    if (!empty($o['head'])) $opts[CURLOPT_NOBODY] = true;
    curl_setopt_array($c, $opts);
    $r = curl_exec($c); $hs = curl_getinfo($c, CURLINFO_HEADER_SIZE); $code = curl_getinfo($c, CURLINFO_HTTP_CODE); curl_close($c);
    $head = substr((string)$r, 0, $hs); preg_match('/^X-QP-Cache:\s*(.*)$/mi', $head, $m);
    return ['code' => $code, 'cache' => trim($m[1] ?? ''), 'head' => $head, 'body' => (string)substr((string)$r, $hs)];
}
$opt_get = function ($n) use ($pdo) { $st = $pdo->prepare('SELECT option_value FROM ' . table_name('site_options') . ' WHERE option_name = ?'); $st->execute([$n]); return $st->fetchColumn(); };
$opt_set = function ($n, $v) use ($pdo) { $pdo->prepare('UPDATE ' . table_name('site_options') . ' SET option_value = ? WHERE option_name = ?')->execute([$v, $n]); };
$gen = function () { return (int)(qp_page_cache_state()['gen'] ?? 0); };
$orig_plugins = $opt_get('active_plugins');
$orig_theme = $opt_get('active_theme');
$pid = (int)$pdo->query("SELECT id FROM " . table_name('posts') . " WHERE slug = 'sample-widget'")->fetchColumn();
$orig_price = $pid ? get_post_meta($pid, '_qc_regular_price') : null;
$token_id = null;
try {
    // Plugins: everything except the one that rewrites the page; the others declare themselves compatible.
    $keep = array_diff(array_keys(json_decode($orig_plugins, true) ?: []), qp_page_cache_incompatible_plugins());
    $opt_set('active_plugins', json_encode(array_fill_keys($keep, true)));
    qp_page_cache_enable(true, ['trusted_plugins' => []]);
    qp_page_cache_purge_all();
    req('sample-page'); // plugins load and declare themselves

    echo "-- basics\n";
    $a = req('sample-page'); $b = req('sample-page');
    ok(strpos($a['cache'], 'HIT') === 0 || strpos($a['cache'], 'MISS') === 0, 'ordinary page is stored: ' . $a['cache']);
    ok(strpos($b['cache'], 'HIT') === 0 && $b['body'] === $a['body'], 'second request is a HIT with the same body');
    ok(stripos($b['head'], 'set-cookie') === false, 'a HIT sets no cookie');
    $h = req('sample-page', ['head' => true]);
    ok(strpos($h['cache'], 'HIT') !== 0, 'HEAD request is not served from cache: ' . ($h['cache'] ?: '(none)'));
    $par = []; $mh = curl_multi_init(); qp_page_cache_purge_all();
    for ($i = 0; $i < 20; $i++) {
        $c = curl_init($base . '/sample-page');
        curl_setopt_array($c, [CURLOPT_RESOLVE => ["$host:$port:$resolve"], CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0, CURLOPT_RETURNTRANSFER => true]);
        curl_multi_add_handle($mh, $c); $par[] = $c;
    }
    do { curl_multi_exec($mh, $run); curl_multi_select($mh, 1); } while ($run > 0);
    $codes = []; $bodies = [];
    foreach ($par as $c) { $codes[] = curl_getinfo($c, CURLINFO_HTTP_CODE); $bodies[] = md5((string)curl_multi_getcontent($c)); curl_multi_remove_handle($mh, $c); }
    ok(count(array_unique($codes)) === 1 && $codes[0] === 200 && count(array_unique($bodies)) === 1, '20 simultaneous requests on a cold cache: all 200, one body');

    echo "-- visitors that must never share a page\n";
    ok(strpos(req('sample-page', ['cookie' => 'qcommerce_cart=x'])['cache'], 'BYPASS; cookie:qcommerce_cart') === 0, 'cart cookie');
    ok(strpos(req('sample-page', ['cookie' => 'qp_preview_theme=default'])['cache'], 'BYPASS; cookie:qp_preview_theme') === 0, 'theme preview cookie');
    ok(strpos(req('sample-page?preview_theme=default')['cache'], 'BYPASS; query:preview_theme') === 0, 'theme preview parameter');
    ok(strpos(req('sample-page', ['cookie' => 'qp_post_access_5=x'])['cache'], 'BYPASS; cookie:qp_post_access_5') === 0, 'password-protected post cookie');
    ok(strpos(req('sample-page?s=x')['cache'], 'BYPASS; query:s') === 0, 'search');
    // login and logout
    $row = create_session_token(1, 600); $token_id = $row['token_id'];
    $cookie = QP_SESSION_COOKIE . '=' . $row['token_id'] . '|' . $row['user_id'] . '|' . $row['expires'] . '|' . qp_hash_token_sig($row['token_id'], $row['user_id'], $row['expires']);
    req('sample-page'); req('sample-page');
    $in = req('sample-page', ['cookie' => $cookie]);
    ok(strpos($in['cache'], 'BYPASS; cookie:' . QP_SESSION_COOKIE) === 0, 'logged-in visitor is never served or stored: ' . $in['cache']);
    ok(strpos(req('sample-page')['cache'], 'HIT') === 0, 'after the logged-in request (and after logout) the anonymous copy is untouched');
    // languages / any per-visitor variation a plugin registers
    qp_page_cache_vary_cookie('qp_lang');
    req('sample-page', ['cookie' => 'qp_lang=fr']); $fr = req('sample-page', ['cookie' => 'qp_lang=fr']); $de = req('sample-page', ['cookie' => 'qp_lang=de']);
    ok(strpos($fr['cache'], 'HIT') === 0 && strpos($de['cache'], 'MISS') === 0, 'a registered vary cookie (language) gives separate copies: fr ' . $fr['cache'] . ' | de ' . $de['cache']);

    echo "-- what is stored holds no personal data\n";
    $base_dir = qp_page_cache_dir() . '/pages/g' . $gen(); $files = [];
    if (is_dir($base_dir)) foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base_dir, FilesystemIterator::SKIP_DOTS)) as $f) { if ($f->isFile()) $files[] = $f->getPathname(); }
    $bad = 0;
    foreach ($files as $f) { $t = (string)file_get_contents($f); if (preg_match('/qp2\.[A-Za-z0-9_-]{20,}\./', $t) || stripos($t, 'set-cookie') !== false || preg_match('/qp_token|qp_csrf_seed/', $t)) $bad++; }
    ok($files && $bad === 0, count($files) . ' stored files, none with a nonce, cookie or session token');

    echo "-- content changes\n";
    req('post/'); req('post/');
    $pdo->exec("INSERT INTO " . table_name('posts') . " (title,slug,content,post_type,status,visibility,published_at) VALUES ('Pagecache scheduled marker','pagecache-sched-marker','x','post','scheduled','public',UTC_TIMESTAMP() - INTERVAL 1 MINUTE)");
    $key = qp_cron_get_key() ?? ''; $before = $gen();
    $out = shell_exec('php ' . escapeshellarg(realpath('qp-cron.php')) . ' --key=' . escapeshellarg($key) . ' 2>&1');
    ok(strpos((string)$out, 'Scheduled published: 1') !== false && $gen() > $before, 'scheduled publish by the crontab runner purges (gen ' . $before . ' -> ' . $gen() . ')');
    $after = req('post/');
    ok(strpos($after['body'], 'Pagecache scheduled marker') !== false, 'the archive shows the newly published post straight away');
    $pdo->exec("DELETE FROM " . table_name('posts') . " WHERE slug = 'pagecache-sched-marker'");
    qp_page_cache_purge_all();
    if ($pid) {
        req('shop/'); $shop2 = req('shop/');
        ok(strpos($shop2['cache'], 'HIT') === 0 && strpos($shop2['body'], '19.99') !== false, 'shop page cached and shows the price 19.99: ' . $shop2['cache']);
        update_post_meta($pid, '_qc_regular_price', '24.50');
        $stale = req('shop/');
        ok(strpos($stale['body'], '19.99') !== false, 'KNOWN GAP: a raw meta write with no hook and no admin save leaves the old price in the cache (' . $stale['cache'] . ')');
        do_action('save_post', $pid); // what a real product save fires
        $fresh = req('shop/');
        ok(strpos($fresh['cache'], 'MISS') === 0 && strpos($fresh['body'], '24.50') !== false && strpos($fresh['body'], '19.99') === false, 'after the save hook the shop shows 24.50: ' . $fresh['cache']);
        update_post_meta($pid, '_qc_regular_price', $orig_price);
        qp_page_cache_purge_all();
    }
    // theme switch
    $page1 = req('sample-page'); req('sample-page'); $g0 = $gen();
    update_option_meta('active_theme', 'default');
    $sw = req('sample-page');
    ok($gen() > $g0 && strpos($sw['cache'], 'MISS') === 0 && $sw['body'] !== $page1['body'], 'theme switch purges and the new theme is served: ' . $sw['cache']);
    update_option_meta('active_theme', $orig_theme === false ? 'shofy' : trim((string)$orig_theme, '"'));
    $back = req('sample-page');
    ok(strpos($back['cache'], 'MISS') === 0, 'switching back purges again');

    echo "-- validators and errors\n";
    req('sample-page'); $x = req('sample-page'); preg_match('/^ETag:\s*(.*)$/mi', $x['head'], $m);
    $nm = req('sample-page', ['h' => ['If-None-Match: ' . trim($m[1] ?? '')]]);
    ok($nm['code'] === 304 && $nm['body'] === '', 'If-None-Match gives 304');
    $nf = req('qp-no-such-page'); $nf2 = req('qp-no-such-page');
    ok($nf['code'] === 404 && strpos($nf2['cache'], 'HIT') !== 0, '404 pages are never served from cache: ' . $nf2['cache']);
} finally {
    if ($token_id) { $t = get_user_session_tokens(1); unset($t[$token_id]); store_user_session_tokens(1, $t); }
    if ($pid && $orig_price !== null) update_post_meta($pid, '_qc_regular_price', $orig_price);
    $opt_set('active_plugins', $orig_plugins);
    if ($orig_theme !== false) $opt_set('active_theme', $orig_theme);
    qp_page_cache_enable(false);
    echo "-- restored: cache off, plugins " . $opt_get('active_plugins') . ", theme " . $opt_get('active_theme') . ", price " . ($pid ? json_encode(get_post_meta($pid, '_qc_regular_price')) : '-') . "\n";
}
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
