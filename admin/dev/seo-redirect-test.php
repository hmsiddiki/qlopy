<?php
// Dev tool (C3 step 4): checks the redirect manager and the 404 log over real HTTP. CLI only.
//   php admin/dev/seo-redirect-test.php [--base=https://host/path] [--resolve=127.0.0.1]
// Switches the SEO module on, adds temporary rules and a temporary post, then removes everything it created.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('QLOPY_INIT', true);
chdir(dirname(__DIR__, 2));
require 'db.php'; $pdo = db();
require 'auth.php';
require_once 'includes/seo.php'; require_once 'includes/seo-schema.php'; require_once 'includes/seo-redirects.php';
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
    $opts = [CURLOPT_RESOLVE => ["$host:$port:$resolve"], CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0, CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 30, CURLOPT_HTTPHEADER => $o['h'] ?? []];
    if (!empty($o['post'])) { $opts[CURLOPT_POST] = true; $opts[CURLOPT_POSTFIELDS] = 'a=b'; }
    curl_setopt_array($c, $opts);
    $r = (string)curl_exec($c); $hs = curl_getinfo($c, CURLINFO_HEADER_SIZE); $code = curl_getinfo($c, CURLINFO_HTTP_CODE); curl_close($c);
    $head = substr($r, 0, $hs); preg_match('/^Location:\s*(.*)$/mi', $head, $m);
    return ['code' => $code, 'head' => $head, 'body' => substr($r, $hs), 'loc' => trim($m[1] ?? '')];
}
$site = rtrim($config['site_url'], '/');
$o_enabled = get_option_meta('qp_seo_enabled'); $o_settings = get_option_meta('qp_seo_settings');
$T = table_name('posts'); $pid = 0;
$R = qp_seo_redirect_table(); $L = qp_seo_404_table();
$cleanup = function () use ($pdo, $R, $L) { $pdo->exec("DELETE FROM `$R` WHERE note LIKE 'T4TEST%' OR source LIKE '/t4-%' OR source LIKE '/post/t4-%' OR source LIKE '^/t4-%'"); $pdo->exec("DELETE FROM `$L` WHERE path LIKE '/t4-%' OR path LIKE '/post/t4-%'"); qp_seo_redirects_compile(); };
try {
    update_option_meta('qp_seo_enabled', true);
    update_option_meta('qp_seo_settings', array_merge(qp_seo_defaults(), []));
    qp_seo_sitemap_dir(); qp_seo_redirects_install(); $cleanup();

    echo "-- matching\n";
    $a = qp_seo_redirect_add('/t4-old-a', '/t4-new-a', 301, 'exact', ['note' => 'T4TEST']);
    ok($a['ok'], 'exact rule added');
    $r = req('t4-old-a'); ok($r['code'] === 301 && $r['loc'] === $site . '/t4-new-a' && stripos($r['head'], 'X-Redirect-By') !== false, '301 to the new address: ' . $r['loc']);
    $r = req('T4-Old-A/'); ok($r['code'] === 301 && $r['loc'] === $site . '/t4-new-a', 'case and trailing slash do not matter');
    $r = req('t4-old-a?utm=1&x=2'); ok($r['loc'] === $site . '/t4-new-a?utm=1&x=2', 'query string kept: ' . $r['loc']);
    qp_seo_redirect_add('/t4-old-nq', '/t4-new-nq', 302, 'exact', ['note' => 'T4TEST', 'keep_query' => false]);
    $r = req('t4-old-nq?x=1'); ok($r['code'] === 302 && $r['loc'] === $site . '/t4-new-nq', '302, query dropped when the rule says so');
    qp_seo_redirect_add('/t4-section', '/t4-newsection', 301, 'prefix', ['note' => 'T4TEST']);
    ok(req('t4-section')['code'] === 301 && req('t4-section/deep/page')['code'] === 301 && req('t4-sectional')['code'] === 404, 'prefix: the path and everything under it, not a longer name');
    $ok1 = qp_seo_redirect_add('^/t4-blog/(\d+)/(.*)$', '/post/$2', 301, 'regex', ['note' => 'T4TEST']);
    $r = req('t4-blog/2024/my-post'); ok($ok1['ok'] && $r['loc'] === $site . '/post/my-post', 'regex with $2 in the target: ' . $r['loc']);
    qp_seo_redirect_add('/t4-gone', '', 410, 'exact', ['note' => 'T4TEST']);
    $r = req('t4-gone'); ok($r['code'] === 410 && trim($r['body']) === 'Gone', '410 gone');
    qp_seo_redirect_add('/t4-ext', 'https://example.org/landing', 307, 'exact', ['note' => 'T4TEST']);
    $r = req('t4-ext'); ok($r['code'] === 307 && $r['loc'] === 'https://example.org/landing', 'a 307 to another site');
    ok(req('t4-old-a', ['post' => 1])['code'] !== 301, 'a POST is never redirected');
    $before = (int)$pdo->query("SELECT hits FROM `$R` WHERE source = '/t4-old-a'")->fetchColumn(); req('t4-old-a'); usleep(300000);
    ok((int)$pdo->query("SELECT hits FROM `$R` WHERE source = '/t4-old-a'")->fetchColumn() === $before + 1, 'hits are counted (after the response)');

    echo "-- refusals\n";
    $e = function ($res) { return !$res['ok']; };
    ok($e(qp_seo_redirect_add('/t4-self', '/t4-self', 301, 'exact', ['note' => 'T4TEST'])), 'a rule to itself is refused: ' . qp_seo_redirect_add('/t4-self', '/t4-self')['error']);
    $ch = qp_seo_redirect_add('/t4-chain', '/t4-old-a', 301, 'exact', ['note' => 'T4TEST']); ok(!$ch['ok'] && strpos($ch['error'], 'final address') !== false, 'a rule whose target is itself redirected is refused (no chains): ' . $ch['error']);
    $lp = qp_seo_redirect_add('/t4-new-a', '/t4-old-a', 301, 'exact', ['note' => 'T4TEST']); ok(!$lp['ok'] && strpos($lp['error'], 'loop') !== false, 'a loop is refused: ' . $lp['error']);
    ok($e(qp_seo_redirect_add('/t4-x', 'javascript:alert(1)', 301, 'exact')), 'javascript: target refused');
    ok($e(qp_seo_redirect_add('/t4-x', 'relative/path', 301, 'exact')), 'a target that is not an address is refused');
    ok($e(qp_seo_redirect_add('/t4-x', '/y', 399, 'exact')), 'unknown status code refused');
    ok($e(qp_seo_redirect_add('^/t4-(', '/y', 301, 'regex')), 'a broken regular expression is refused');
    ok($e(qp_seo_redirect_add('', '/y', 301, 'exact')), 'an empty old address is refused');

    echo "-- switching and removing\n";
    $id = $a['id']; qp_seo_redirect_set_enabled($id, false); ok(req('t4-old-a')['code'] === 404, 'a turned-off rule does nothing');
    qp_seo_redirect_set_enabled($id, true); ok(req('t4-old-a')['code'] === 301, 'turned on again');
    qp_seo_redirect_delete($id); ok(req('t4-old-a')['code'] === 404, 'a deleted rule is gone at once (compiled file rebuilt)');

    echo "-- no database query to match\n";
    $tmp = sys_get_temp_dir() . '/qp_seo_rd_test.php';
    file_put_contents($tmp, "<?php\ndefine('QLOPY_INIT', true); chdir(" . var_export(getcwd(), true) . ");\nrequire 'db.php'; require 'auth.php'; require_once 'includes/seo.php';\n\$pdo = db(); \$pdo->exec('RENAME TABLE `" . $R . "` TO `" . $R . "_hidden`');\n\$m = qp_seo_redirect_match('/t4-old-nq'); \$pdo->exec('RENAME TABLE `" . $R . "_hidden` TO `" . $R . "`');\necho json_encode(\$m);\n");
    $out = json_decode((string)shell_exec('php ' . escapeshellarg($tmp)), true); @unlink($tmp);
    ok(is_array($out) && $out['code'] === 302, 'a rule is found with the rules table out of the way: only the compiled file is read');

    echo "-- slug changes\n";
    $pdo->prepare("INSERT INTO $T (title,slug,content,post_type,status,visibility,published_at) VALUES ('T4 slug test','t4-slug-a','x','post','published','public',?)")->execute([gmdate('Y-m-d H:i:s')]);
    $pid = (int)$pdo->lastInsertId();
    $rename = function ($old, $new) use ($pdo, $T, $pid) { $pdo->prepare("UPDATE $T SET slug = ? WHERE id = ?")->execute([$new, $pid]); do_action('qp_post_slug_changed', $pid, $old, $new, 'post'); };
    $rename('t4-slug-a', 't4-slug-b');
    $r = req('post/t4-slug-a'); ok($r['code'] === 301 && $r['loc'] === $site . '/post/t4-slug-b', 'renaming a post redirects the old address: ' . $r['loc']);
    $rename('t4-slug-b', 't4-slug-c');
    ok(req('post/t4-slug-a')['loc'] === $site . '/post/t4-slug-c' && req('post/t4-slug-b')['loc'] === $site . '/post/t4-slug-c', 'a second rename moves earlier rules to the final address (no chains)');
    $rename('t4-slug-c', 't4-slug-a');
    $r = req('post/t4-slug-a'); ok($r['code'] === 200, 'renaming back removes the rule that would loop (the post answers 200 again)');
    ok(req('post/t4-slug-b')['loc'] === $site . '/post/t4-slug-a' && req('post/t4-slug-c')['loc'] === $site . '/post/t4-slug-a', 'and the other old addresses now lead to it');

    echo "-- 404 log\n";
    req('t4-missing-page', ['h' => ['Referer: https://example.org/linking-page']]); req('t4-missing-page');
    $row = $pdo->query("SELECT * FROM `$L` WHERE path = '/t4-missing-page'")->fetch(PDO::FETCH_ASSOC);
    ok($row && (int)$row['hits'] === 2 && $row['referrer'] === 'https://example.org/linking-page', 'a missing address is counted per path with its referrer');
    req('t4-missing-page', ['post' => 1]); $row2 = $pdo->query("SELECT hits FROM `$L` WHERE path = '/t4-missing-page'")->fetchColumn(); ok((int)$row2 === 2, 'POST requests are not logged');
    req('t4-old-nq'); ok((int)$pdo->query("SELECT COUNT(*) FROM `$L` WHERE path = '/t4-old-nq'")->fetchColumn() === 0, 'a redirected address is not a 404');
    ok(req('')['code'] === 200 && (int)$pdo->query("SELECT COUNT(*) FROM `$L` WHERE path IN ('/', '')")->fetchColumn() === 0, 'a found page is not logged');
    // prune: old rows go, and the table is capped
    $ins = $pdo->prepare("INSERT IGNORE INTO `$L` (path_hash, path, hits, first_seen, last_seen) VALUES (?,?,?,?,?)");
    $pdo->beginTransaction(); for ($i = 0; $i < 1100; $i++) $ins->execute([sha1("t4-bulk-$i"), "/t4-bulk-$i", 1 + ($i % 7), time() - 100, time() - 100]); $pdo->commit();
    $ins->execute([sha1('t4-ancient'), '/t4-ancient', 99, time() - 40 * 86400, time() - 40 * 86400]);
    qp_seo_404_prune();
    $cnt = (int)$pdo->query("SELECT COUNT(*) FROM `$L`")->fetchColumn(); $anc = (int)$pdo->query("SELECT COUNT(*) FROM `$L` WHERE path = '/t4-ancient'")->fetchColumn();
    ok($cnt <= QP_SEO_404_CAP && $anc === 0, "pruned: entries older than 30 days removed, table capped at " . QP_SEO_404_CAP . " (now $cnt)");
    $pdo->exec("DELETE FROM `$L` WHERE path LIKE '/t4-bulk-%'");
} finally {
    if ($pid) $pdo->exec("DELETE FROM $T WHERE id = $pid");
    $cleanup();
    update_option_meta('qp_seo_settings', $o_settings ?: qp_seo_defaults());
    update_option_meta('qp_seo_enabled', $o_enabled ?: false);
    echo "-- restored: module " . var_export((bool)get_option_meta('qp_seo_enabled'), true) . ", rules left: " . (int)$pdo->query("SELECT COUNT(*) FROM `$R`")->fetchColumn() . "\n";
}
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
