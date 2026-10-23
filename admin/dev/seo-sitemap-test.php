<?php
// Dev tool (C3 step 3): checks sitemaps and robots.txt over real HTTP, through the provider API, and on a large synthetic site. CLI only.
//   php admin/dev/seo-sitemap-test.php [--base=https://host/path] [--resolve=127.0.0.1] [--big=50000]
// Switches the module on, creates temporary posts, then restores settings and removes everything it created (--big=0 skips the large site).
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('QLOPY_INIT', true);
chdir(dirname(__DIR__, 2));
require 'db.php'; $pdo = db();
require 'auth.php';
require_once 'includes/seo.php';
$opt = getopt('', ['base::', 'resolve::', 'big::']);
$base = rtrim($opt['base'] ?? $config['site_url'], '/');
$host = parse_url($base, PHP_URL_HOST);
$port = parse_url($base, PHP_URL_PORT) ?: (parse_url($base, PHP_URL_SCHEME) === 'https' ? 443 : 80);
$resolve = $opt['resolve'] ?? '127.0.0.1';
$big = (int)($opt['big'] ?? 50000);
$pass = 0; $fail = 0;
function ok($c, $m) { global $pass, $fail; if ($c) $pass++; else $fail++; echo ($c ? 'PASS ' : 'FAIL ') . $m . "\n"; }
function get($path, $h = []) {
    global $base, $host, $port, $resolve;
    $c = curl_init($base . '/' . ltrim($path, '/'));
    curl_setopt_array($c, [CURLOPT_RESOLVE => ["$host:$port:$resolve"], CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0, CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 120, CURLOPT_HTTPHEADER => $h]);
    $r = (string)curl_exec($c); $hs = curl_getinfo($c, CURLINFO_HEADER_SIZE); $code = curl_getinfo($c, CURLINFO_HTTP_CODE); curl_close($c);
    return ['code' => $code, 'head' => substr($r, 0, $hs), 'body' => substr($r, $hs)];
}
function locs($xml) { preg_match_all('#<loc>([^<]*)</loc>#', $xml, $m); return array_map('html_entity_decode', $m[1]); }
function wellformed($xml) { $d = new DOMDocument(); return @$d->loadXML($xml); }
function purge_files() { qp_seo_sitemap_purge(); }
$o_enabled = get_option_meta('qp_seo_enabled'); $o_settings = get_option_meta('qp_seo_settings');
$site = rtrim($config['site_url'], '/');
$T = table_name('posts'); $made = [];
$mk = function ($slug, $status, $vis, $content = 'body', $type = 'post', $pub = null) use ($pdo, $T, &$made) {
    $pdo->prepare("INSERT INTO $T (title,slug,content,post_type,status,visibility,published_at) VALUES (?,?,?,?,?,?,?)")->execute(['SM ' . $slug, $slug, $content, $type, $status, $vis, $pub ?? gmdate('Y-m-d H:i:s')]);
    $id = (int)$pdo->lastInsertId(); $made[] = $id; return $id;
};
try {
    update_option_meta('qp_seo_enabled', true);
    update_option_meta('qp_seo_settings', array_merge(qp_seo_defaults(), []));
    purge_files();

    echo "-- files\n";
    $ix = get('sitemap.xml');
    ok($ix['code'] === 200 && wellformed($ix['body']) && stripos($ix['head'], 'application/xml') !== false && stripos($ix['head'], 'X-Robots-Tag: noindex') !== false, 'index: 200, well-formed XML, noindex header');
    $files = locs($ix['body']);
    ok(count($files) >= 2 && !array_filter($files, function ($u) use ($site) { return strpos($u, $site . '/sitemap-') !== 0; }), 'index lists ' . count($files) . ' files, all on the site address');
    foreach ($files as $f) { $r = get(substr($f, strlen($site) + 1)); ok($r['code'] === 200 && wellformed($r['body']), 'well-formed: ' . basename($f)); }
    $b = get('sitemap-nope-1.xml'); ok($b['code'] === 404, 'unknown provider is 404'); ok(get('sitemap-post-999.xml')['code'] === 404, 'a page past the end is 404');
    $et = []; preg_match('/^ETag:\s*(.*)$/mi', $ix['head'], $et); $nm = get('sitemap.xml', ['If-None-Match: ' . trim($et[1] ?? '')]);
    ok($nm['code'] === 304, 'If-None-Match gives 304');

    echo "-- what is listed\n";
    $pub = $mk('sm-published', 'published', 'public', '<p>Hello <img src="/uploads/sm-test.png"> there</p>');
    $mk('sm-draft', 'draft', 'public'); $mk('sm-private', 'published', 'private'); $mk('sm-password', 'published', 'password');
    $mk('sm-scheduled', 'scheduled', 'public', 'x', 'post', gmdate('Y-m-d H:i:s', time() + 86400));
    $noidx = $mk('sm-noindex', 'published', 'public'); update_post_meta($noidx, '_seo_robots', 'noindex');
    $canon = $mk('sm-canonical-elsewhere', 'published', 'public'); update_post_meta($canon, '_seo_canonical', 'https://example.org/original');
    $before = get('sitemap-post-1.xml')['body'];
    ok(strpos($before, 'sm-published') === false, 'the sitemap is served from its cache (a new post is not there until content changes)');
    do_action('save_post', $pub);   // what a real save fires
    $after = get('sitemap-post-1.xml')['body'];
    ok(strpos($after, '/post/sm-published') !== false, 'after a save the sitemap is rebuilt and lists the new post');
    foreach (['sm-draft', 'sm-private', 'sm-password', 'sm-scheduled', 'sm-noindex', 'sm-canonical-elsewhere'] as $s) ok(strpos($after, '/' . $s) === false, "not listed: $s");
    ok(strpos($after, '<image:loc>' . $site . '/uploads/sm-test.png</image:loc>') !== false, 'image entry with an absolute address');
    ok(preg_match('#/post/sm-published</loc><lastmod>\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\+00:00</lastmod>#', $after) === 1, 'lastmod is ISO 8601');
    $pg = get('sitemap-page-1.xml')['body']; ok(locs($pg)[0] === $site . '/', 'the home page leads the page sitemap');
    $g0 = (function () { $f = glob(qp_seo_sitemap_dir() . '/sitemap*.xml'); return count($f); })();
    update_option_meta('site_tagline', 'sitemap test ' . time()); ok(count(glob(qp_seo_sitemap_dir() . '/sitemap*.xml')) === 0 && $g0 > 0, 'a settings change deletes the cached files (' . $g0 . ' -> 0)');
    update_option_meta('site_tagline', '');

    echo "-- robots.txt\n";
    $rb = get('robots.txt')['body'];
    ok(strpos($rb, "User-agent: *\n") === 0 && strpos($rb, 'Disallow: ' . parse_url($site, PHP_URL_PATH) . '/admin/') !== false && strpos($rb, 'Sitemap: ' . $site . '/sitemap.xml') !== false, 'default robots.txt: admin disallowed, sitemap announced');
    update_option_meta('qp_seo_settings', array_merge(qp_seo_defaults(), ['robots_extra' => "Disallow: /private-area/\nCrawl-delay: 5"]));
    $rb = get('robots.txt')['body']; ok(strpos($rb, "Disallow: /private-area/\nCrawl-delay: 5") !== false, 'extra lines are added');
    update_option_meta('qp_seo_settings', array_merge(qp_seo_defaults(), ['robots_block_all' => true]));
    $rb = get('robots.txt')['body']; ok(strpos($rb, "Disallow: /\n") !== false && strpos($rb, '/admin/') === false, 'block-all gives Disallow: /');
    file_put_contents('robots.txt', "User-agent: *\nDisallow: /physical-file-wins/\n");
    $rb = get('robots.txt')['body']; ok(strpos($rb, 'physical-file-wins') !== false, 'a real robots.txt file is served instead (never replaced)');
    @unlink('robots.txt');
    update_option_meta('qp_seo_settings', array_merge(qp_seo_defaults(), ['sitemap' => false]));
    ok(get('sitemap.xml')['code'] !== 200 && strpos(get('robots.txt')['body'], 'Sitemap:') === false, 'sitemap switched off: no sitemap, no Sitemap line');
    update_option_meta('qp_seo_settings', array_merge(qp_seo_defaults(), []));

    echo "-- provider API and host (separate process)\n";
    $tmp = sys_get_temp_dir() . '/qp_seo_sm_test.php';
    file_put_contents($tmp, "<?php\ndefine('QLOPY_INIT', true); chdir(" . var_export(getcwd(), true) . ");\nrequire 'db.php'; require 'auth.php'; require_once 'includes/seo.php';\n"
        . "add_filter('permalink_base', function (\$b) { return 'https://evil.example/' . trim((string)parse_url(\$b, PHP_URL_PATH), '/'); });\n"
        . "add_filter('qp_seo_sitemap_providers', function (\$p) { \$p['myplugin'] = ['label' => 'Mine', 'count' => function () { return 2; }, 'entries' => function (\$o, \$l) { return [['loc' => 'https://aloelo.dev/orcasy/mine/1', 'lastmod' => '2026-01-02T03:04:05+00:00', 'images' => ['https://aloelo.dev/orcasy/i.png'], 'alternates' => [['lang' => 'fr', 'href' => 'https://aloelo.dev/orcasy/fr/mine/1']]], ['loc' => 'https://aloelo.dev/orcasy/mine/2']]; }]; return \$p; });\n"
        . "add_filter('qp_seo_sitemap_entry', function (\$e, \$p, \$t) { return strpos(\$e['loc'], 'sm-published') !== false ? null : \$e; }, 10, 3);\n"
        . "echo qp_seo_sitemap_index_xml(), '#####', qp_seo_sitemap_file_xml('myplugin', 1), '#####', qp_seo_sitemap_file_xml('post', 1);\n");
    $out = (string)shell_exec('php ' . escapeshellarg($tmp) . ' 2>&1'); @unlink($tmp); purge_files();
    [$o1, $o2, $o3] = array_pad(explode('#####', $out), 3, '');
    ok(strpos($o1, '/sitemap-myplugin-1.xml') !== false, 'a plugin provider appears in the index');
    ok(strpos($o2, '<xhtml:link rel="alternate" hreflang="fr" href="https://aloelo.dev/orcasy/fr/mine/1"/>') !== false && strpos($o2, '<image:loc>') !== false && wellformed($o2), 'provider entries with image and hreflang alternate render as valid XML');
    ok(strpos($o3, 'sm-published') === false && strpos($o3, '<url>') !== false, 'the entry filter can drop an entry');
    ok(strpos($o1 . $o3, 'evil.example') === false, 'a permalink_base filter changing the host never reaches the sitemap');

    if ($big > 0) {
        echo "-- large site ($big published products)\n";
        $existing = (int)$pdo->query("SELECT COUNT(*) FROM $T WHERE post_type = 'product' AND status = 'published' AND visibility = 'public'")->fetchColumn();
        passthru('php ' . escapeshellarg(__DIR__ . '/search-seed.php') . ' seed ' . (int)$big . ' zipf > ' . (stripos(PHP_OS, 'WIN') === 0 ? 'NUL' : '/dev/null'));
        require_once 'includes/schema.php'; $ix = qp_schema_add_posts_type_status_index();
        update_option_meta('qp_seo_settings', array_merge(qp_seo_defaults(), ['sitemap_post_types' => ['post', 'page', 'product'], 'sitemap_images' => true]));
        purge_files();
        $t = microtime(true); $r = get('sitemap.xml'); $t_index = (microtime(true) - $t) * 1000;
        $n = count(array_filter(locs($r['body']), function ($u) { return strpos($u, '/sitemap-product-') !== false; }));
        $total = $big + $existing; $lastCount = $total - ($n - 1) * 1000;
        ok($n === (int)ceil($total / 1000), "index lists $n product files for $total products (index built cold in " . round($t_index) . ' ms, index status ' . $ix['status'] . ')');
        $t = microtime(true); $r1 = get('sitemap-product-1.xml'); $t1 = (microtime(true) - $t) * 1000;
        $t = microtime(true); $rl = get('sitemap-product-' . $n . '.xml'); $tl = (microtime(true) - $t) * 1000;
        $t = microtime(true); get('sitemap-product-1.xml'); $tc = (microtime(true) - $t) * 1000;
        ok($r1['code'] === 200 && substr_count($r1['body'], '<url>') === 1000 && wellformed($r1['body']), 'first file: 1000 entries, well-formed, built cold in ' . round($t1) . ' ms');
        ok($rl['code'] === 200 && substr_count($rl['body'], '<url>') === $lastCount, 'last file (offset ' . (($n - 1) * 1000) . '): ' . $lastCount . ' entries, built cold in ' . round($tl) . ' ms');
        ok($t1 < 2500 && $tl < 4000 && $t_index < 1500, 'cold builds stay under budget (file < 2.5 s, last file < 4 s, index < 1.5 s)');
        echo "   cached file served in " . round($tc) . " ms\n";
    }
} finally {
    @unlink('robots.txt');
    if ($made) { $in = implode(',', array_map('intval', $made)); $pdo->exec("DELETE FROM " . table_name('post_meta') . " WHERE post_id IN ($in)"); $pdo->exec("DELETE FROM $T WHERE id IN ($in)"); }
    if ($big > 0) passthru('php ' . escapeshellarg(__DIR__ . '/search-seed.php') . ' remove > ' . (stripos(PHP_OS, 'WIN') === 0 ? 'NUL' : '/dev/null'));
    update_option_meta('site_tagline', '');
    update_option_meta('qp_seo_settings', $o_settings ?: qp_seo_defaults());
    update_option_meta('qp_seo_enabled', $o_enabled ?: false);
    purge_files();
    echo "-- restored: module " . var_export((bool)get_option_meta('qp_seo_enabled'), true) . ", temporary posts removed: " . count($made) . "\n";
}
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
