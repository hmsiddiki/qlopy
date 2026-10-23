<?php
// Dev tool (C3 step 2): checks structured data (JSON-LD) and breadcrumbs over real HTTP and through the provider API. CLI only.
//   php admin/dev/seo-schema-test.php [--base=https://host/path] [--resolve=127.0.0.1]
// Switches the module on, runs the checks, then restores the SEO settings and the test post.
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
function get($path) {
    global $base, $host, $port, $resolve;
    $c = curl_init($base . '/' . ltrim($path, '/'));
    curl_setopt_array($c, [CURLOPT_RESOLVE => ["$host:$port:$resolve"], CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30]);
    $b = (string)curl_exec($c); $code = curl_getinfo($c, CURLINFO_HTTP_CODE); curl_close($c);
    return ['code' => $code, 'body' => $b];
}
function blocks($html) { preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $m); return $m[1]; }
function nodes_of($html) { $b = blocks($html); if (count($b) !== 1) return null; $j = json_decode($b[0], true); return is_array($j) ? $j : null; }
function types($j) { $t = []; foreach ($j['@graph'] ?? [] as $n) { $t[] = $n['@type']; } return $t; }
function node($j, $type) { foreach ($j['@graph'] ?? [] as $n) if (($n['@type'] ?? '') === $type) return $n; return null; }
/** Every {"@id": x} reference must point at a node that defines x. */
function refs_resolve($j, &$missing) {
    $defs = [];
    $collect = function ($v) use (&$collect, &$defs) { if (!is_array($v)) return; if (isset($v['@id']) && count($v) > 1) $defs[$v['@id']] = true; foreach ($v as $x) $collect($x); };
    $collect($j['@graph']);
    $missing = [];
    $walk = function ($v, $top) use (&$walk, $defs, &$missing) {
        if (!is_array($v)) return;
        if (!$top && count($v) === 1 && isset($v['@id']) && empty($defs[$v['@id']])) $missing[] = $v['@id'];
        foreach ($v as $x) $walk($x, false);
    };
    foreach ($j['@graph'] as $n) $walk($n, true);
    return !$missing;
}
function urls_of($v, &$out) { if (is_array($v)) { foreach ($v as $k => $x) { if (in_array($k, ['url', 'item', 'contentUrl', '@id'], true) && is_string($x)) $out[] = $x; urls_of($x, $out); } } }
$slug = 'welcome-to-your-site';
$post = $pdo->query("SELECT * FROM " . table_name('posts') . " WHERE slug = '$slug' AND post_type = 'post'")->fetch(PDO::FETCH_ASSOC);
$pid = (int)$post['id'];
$o_enabled = get_option_meta('qp_seo_enabled'); $o_settings = get_option_meta('qp_seo_settings');
$site = rtrim($config['site_url'], '/');
try {
    update_option_meta('qp_seo_enabled', true);
    update_option_meta('qp_seo_settings', array_merge(qp_seo_defaults(), ['org_name' => 'Orcasy Test Org', 'org_logo' => 'https://example.org/logo.png', 'org_same_as' => "https://example.org/profile\nnot-a-url\nhttps://social.example/orcasy"]));

    echo "-- home page\n";
    $h = get(''); $j = nodes_of($h['body']);
    ok($j !== null && ($j['@context'] ?? '') === 'https://schema.org', 'one valid JSON-LD block with the schema.org context');
    ok($j && array_values(array_intersect(types($j), ['WebSite', 'Organization', 'WebPage'])) === ['WebSite', 'Organization', 'WebPage'], 'WebSite, Organization, WebPage: ' . implode(',', $j ? types($j) : []));
    ok($j && !in_array('BreadcrumbList', types($j), true), 'no breadcrumb list on the home page');
    $ws = $j ? node($j, 'WebSite') : null; $sa = $ws['potentialAction'][0] ?? [];
    ok(($sa['@type'] ?? '') === 'SearchAction' && strpos($sa['target']['urlTemplate'] ?? '', $site) === 0 && strpos($sa['target']['urlTemplate'], '{search_term_string}') !== false && ($sa['query-input'] ?? '') === 'required name=search_term_string', 'search action: ' . ($sa['target']['urlTemplate'] ?? ''));
    $org = $j ? node($j, 'Organization') : null;
    ok(($org['name'] ?? '') === 'Orcasy Test Org' && ($org['logo']['url'] ?? '') === 'https://example.org/logo.png' && ($org['sameAs'] ?? []) === ['https://example.org/profile', 'https://social.example/orcasy'], 'organization name, logo and sameAs (invalid address dropped)');
    $miss = []; ok($j && refs_resolve($j, $miss), 'every @id reference resolves' . ($miss ? ': ' . implode(',', $miss) : ''));

    echo "-- a post\n";
    $p = get('post/' . $slug); $j = nodes_of($p['body']);
    ok($j && in_array('Article', types($j), true) && in_array('BreadcrumbList', types($j), true) && in_array('WebPage', types($j), true), 'Article, WebPage and BreadcrumbList: ' . implode(',', $j ? types($j) : []));
    $art = $j ? node($j, 'Article') : null; $bc = $j ? node($j, 'BreadcrumbList') : null;
    ok(($art['headline'] ?? '') === (string)$post['title'], 'Article headline is the title');
    ok(isset($art['datePublished']) && (bool)preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\+00:00|Z)$/', $art['datePublished']) && isset($art['dateModified']), 'ISO 8601 dates: ' . ($art['datePublished'] ?? '-'));
    ok(isset($art['publisher']['@id']) && ($art['mainEntityOfPage']['@id'] ?? '') === (node($j, 'WebPage')['@id'] ?? 'x'), 'Article links to the publisher and the page');
    $items = $bc['itemListElement'] ?? []; $pos = array_column($items, 'position');
    ok(count($items) >= 2 && $pos === range(1, count($items)) && ($items[0]['item'] ?? '') === $site . '/' && end($items)['name'] === (string)$post['title'], 'breadcrumb positions 1..' . count($items) . ', Home first, post last: ' . implode(' > ', array_column($items, 'name')));
    $miss = []; ok($j && refs_resolve($j, $miss), 'every @id reference resolves' . ($miss ? ': ' . implode(',', $miss) : ''));
    $urls = []; urls_of($j, $urls); $bad = array_filter($urls, function ($u) use ($site) { return strpos($u, $site) !== 0 && strpos($u, 'https://example.org') !== 0 && strpos($u, 'https://social.example') !== 0; });
    ok(!$bad, 'every address in the graph is on the site address (or one the owner entered)' . ($bad ? ': ' . implode(',', $bad) : ''));

    echo "-- pages\n";
    $se = get('index.php?route=search&q=hello'); $sj = nodes_of($se['body']);
    ok($sj && node($sj, 'SearchResultsPage') !== null, 'search results are a SearchResultsPage');
    $nf = get('qp-no-such-page-xyz'); ok($nf['code'] === 404 && count(blocks($nf['body'])) === 0, '404 page carries no structured data');

    echo "-- safety\n";
    $evil = '</script><script>alert(1)</script> & "q"';
    $pdo->prepare('UPDATE ' . table_name('posts') . ' SET title = ? WHERE id = ?')->execute([$evil, $pid]);
    $x = get('post/' . $slug); $bl = blocks($x['body']); $xj = nodes_of($x['body']);
    ok(count($bl) === 1 && $xj && ($xj['@graph'] ? true : false) && strpos($bl[0], '</script>') === false && node($xj, 'Article')['headline'] === $evil, 'a title containing </script> cannot end the JSON-LD block, and round-trips intact');
    $pdo->prepare('UPDATE ' . table_name('posts') . ' SET title = ? WHERE id = ?')->execute([$post['title'], $pid]);

    echo "-- switch\n";
    update_option_meta('qp_seo_settings', array_merge(qp_seo_defaults(), ['schema' => false]));
    ok(count(blocks(get('post/' . $slug)['body'])) === 0, 'structured data off: no block, other tags still printed: ' . (strpos(get('post/' . $slug)['body'], '<!-- qp-seo -->') !== false ? 'yes' : 'no'));

    echo "-- provider API and breadcrumb markup (in process)\n";
    update_option_meta('qp_seo_settings', array_merge(qp_seo_defaults(), []));
    $_GET = ['route' => 'singular', 'post_type' => 'post', 'slug' => $slug]; $GLOBALS['post'] = $post;
    add_filter('qp_seo_graph', function ($nodes, $ctx, $meta) {
        $nodes['product'] = ['@type' => 'Product', '@id' => qp_seo_id('webpage') . '-product', 'name' => 'Widget', 'offers' => ['@type' => 'Offer', 'price' => '19.99', 'priceCurrency' => 'USD'], 'mainEntityOfPage' => ['@id' => qp_seo_id('webpage')]];
        unset($nodes['organization']);
        return $nodes;
    }, 10, 3);
    $g = qp_seo_graph(); $ty = array_column($g['@graph'], '@type');
    ok(in_array('Product', $ty, true) && !in_array('Organization', $ty, true), 'a plugin can add a node and remove one: ' . implode(',', $ty));
    $tmp = sys_get_temp_dir() . '/qp_seo_bc_test.php';
    file_put_contents($tmp, "<?php\ndefine('QLOPY_INIT', true); chdir(" . var_export(getcwd(), true) . ");\nrequire 'db.php'; require 'auth.php'; require_once 'includes/seo.php';\n\$_GET = ['route' => 'singular', 'post_type' => 'post', 'slug' => " . var_export($slug, true) . "];\n\$GLOBALS['post'] = " . var_export($post, true) . ";\nadd_filter('qp_seo_breadcrumbs', function (\$t) { \$t[] = ['name' => '<b>Extra</b> & more', 'url' => '']; return \$t; });\necho qp_seo_breadcrumbs_html(['separator' => '/']);\n");
    $html = (string)shell_exec('php ' . escapeshellarg($tmp)); @unlink($tmp);
    ok(strpos($html, '<nav class="qp-breadcrumbs" aria-label="Breadcrumb"><ol>') === 0 && substr_count($html, 'aria-current="page"') === 1 && substr_count($html, '<li') >= 3, 'breadcrumb markup: nav, list, one current item');
    ok(strpos($html, '<b>Extra') === false && strpos($html, '&lt;b&gt;Extra&lt;/b&gt; &amp; more') !== false, 'names are escaped in the markup');
} finally {
    $pdo->prepare('UPDATE ' . table_name('posts') . ' SET title = ? WHERE id = ?')->execute([$post['title'], $pid]);
    update_option_meta('qp_seo_settings', $o_settings ?: qp_seo_defaults());
    update_option_meta('qp_seo_enabled', $o_enabled ?: false);
    echo "-- restored: module " . var_export((bool)get_option_meta('qp_seo_enabled'), true) . ", post title " . $pdo->query('SELECT title FROM ' . table_name('posts') . ' WHERE id = ' . $pid)->fetchColumn() . "\n";
}
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
