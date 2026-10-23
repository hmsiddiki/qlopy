<?php
// Dev tool (C3 step 6): validates the structured data the module prints against the published requirements of the schema.org types
// and Google's rich-result guidelines for them (Article, Product with Offer and AggregateRating, BreadcrumbList, WebSite search box,
// Organization), and times the module on a large site. CLI only. No network access: the rules are written out below, not fetched.
//   php admin/dev/seo-validate-test.php [--base=https://host/path] [--resolve=127.0.0.1] [--big=50000]
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
function get($path) {
    global $base, $host, $port, $resolve;
    $c = curl_init($base . '/' . ltrim($path, '/'));
    curl_setopt_array($c, [CURLOPT_RESOLVE => ["$host:$port:$resolve"], CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60]);
    $b = (string)curl_exec($c); $code = curl_getinfo($c, CURLINFO_HTTP_CODE); curl_close($c);
    return ['code' => $code, 'body' => $b];
}
function graph_of($html) { if (!preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $m) || count($m[1]) !== 1) return null; $j = json_decode($m[1][0], true); return is_array($j) ? $j : null; }
function nodes($g, $t) { return array_values(array_filter($g['@graph'] ?? [], function ($n) use ($t) { return ($n['@type'] ?? '') === $t; })); }
function isoDate($v) { return is_string($v) && (bool)preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(Z|[+-]\d{2}:\d{2})$/', $v) && strtotime($v) !== false; }
function absUrl($v) { return is_string($v) && (bool)preg_match('#^https?://[^\s]+$#', $v); }
$site = rtrim($config['site_url'], '/');

/** Rules, per type: required properties (a page without them is not eligible for the rich result), and typed checks. */
$rules = [
    'Article' => function ($n) { $e = []; foreach (['headline', 'datePublished'] as $p) if (empty($n[$p])) $e[] = "missing $p"; if (isset($n['headline']) && mb_strlen($n['headline']) > 110) $e[] = 'headline over 110 characters'; if (isset($n['datePublished']) && !isoDate($n['datePublished'])) $e[] = 'datePublished not ISO 8601'; if (isset($n['dateModified']) && !isoDate($n['dateModified'])) $e[] = 'dateModified not ISO 8601'; if (isset($n['author']) && (($n['author']['@type'] ?? '') === '' || empty($n['author']['name']))) $e[] = 'author without type or name'; return $e; },
    'Product' => function ($n) { $e = []; if (empty($n['name'])) $e[] = 'missing name'; if (empty($n['offers']) && empty($n['aggregateRating']) && empty($n['review'])) $e[] = 'needs offers, aggregateRating or review'; $o = $n['offers'] ?? null; if ($o) { $t = $o['@type'] ?? ''; if ($t === 'Offer') { foreach (['price', 'priceCurrency'] as $p) if (!isset($o[$p]) || $o[$p] === '') $e[] = "offer missing $p"; if (isset($o['price']) && !preg_match('/^\d+(\.\d{1,2})?$/', (string)$o['price'])) $e[] = 'price not a plain decimal'; if (isset($o['priceCurrency']) && !preg_match('/^[A-Z]{3}$/', $o['priceCurrency'])) $e[] = 'priceCurrency not ISO 4217'; if (isset($o['availability']) && !preg_match('#^https://schema\.org/(InStock|OutOfStock|PreOrder|BackOrder|LimitedAvailability|SoldOut|Discontinued|OnlineOnly|InStoreOnly)$#', $o['availability'])) $e[] = 'availability not a schema.org value'; } elseif ($t === 'AggregateOffer') { foreach (['lowPrice', 'priceCurrency'] as $p) if (!isset($o[$p])) $e[] = "aggregate offer missing $p"; } else { $e[] = 'offers has an unknown type'; } } if (isset($n['aggregateRating'])) { $r = $n['aggregateRating']; foreach (['ratingValue', 'reviewCount'] as $p) if (!isset($r[$p])) $e[] = "aggregateRating missing $p"; if (isset($r['ratingValue']) && ((float)$r['ratingValue'] < 1 || (float)$r['ratingValue'] > 5)) $e[] = 'ratingValue outside 1 to 5'; } return $e; },
    'BreadcrumbList' => function ($n) { $e = []; $items = $n['itemListElement'] ?? []; if (count($items) < 2) $e[] = 'fewer than two items'; $pos = array_column($items, 'position'); if ($pos !== range(1, count($items))) $e[] = 'positions are not 1..n'; foreach ($items as $i => $it) { if (($it['@type'] ?? '') !== 'ListItem') $e[] = 'item without ListItem type'; if (empty($it['name'])) $e[] = 'item without name'; if ($i < count($items) - 1 && !absUrl($it['item'] ?? null)) $e[] = 'a non-final item without an address'; } return $e; },
    'WebSite' => function ($n) { $e = []; foreach (['url', 'name'] as $p) if (empty($n[$p])) $e[] = "missing $p"; $a = $n['potentialAction'][0] ?? null; if ($a) { if (($a['@type'] ?? '') !== 'SearchAction') $e[] = 'action is not a SearchAction'; if (strpos($a['target']['urlTemplate'] ?? '', '{search_term_string}') === false) $e[] = 'urlTemplate lacks {search_term_string}'; if (($a['query-input'] ?? '') !== 'required name=search_term_string') $e[] = 'query-input does not match the template'; } return $e; },
    'Organization' => function ($n) { $e = []; if (empty($n['name'])) $e[] = 'missing name'; if (isset($n['logo']) && !absUrl($n['logo']['url'] ?? null)) $e[] = 'logo without an address'; foreach (($n['sameAs'] ?? []) as $u) if (!absUrl($u)) $e[] = 'sameAs holds a non-address'; return $e; },
    'WebPage' => function ($n) { $e = []; foreach (['@id', 'url', 'name'] as $p) if (empty($n[$p])) $e[] = "missing $p"; if (isset($n['url']) && !absUrl($n['url'])) $e[] = 'url is not absolute'; return $e; },
    'CollectionPage' => function ($n) { return empty($n['url']) ? ['missing url'] : []; },
    'SearchResultsPage' => function ($n) { return empty($n['url']) ? ['missing url'] : []; },
];
$check = function ($label, $html) use ($rules, $site) {
    $g = graph_of($html);
    if ($g === null) { ok(false, "$label: one valid JSON-LD block"); return; }
    $errors = [];
    foreach ($g['@graph'] as $n) {
        $t = $n['@type'] ?? '';
        if (!isset($rules[$t])) { $errors[] = "$t: no rule for this type"; continue; }
        foreach ($rules[$t]($n) as $e) $errors[] = "$t: $e";
        if (isset($n['@id']) && !absUrl($n['@id'])) $errors[] = "$t: @id is not an address";
    }
    $ids = array_filter(array_column($g['@graph'], '@id')); if (count($ids) !== count(array_unique($ids))) $errors[] = 'duplicate @id';
    $urls = []; array_walk_recursive($g, function ($v, $k) use (&$urls) { if (in_array($k, ['url', 'item', '@id', 'contentUrl', 'urlTemplate'], true) && is_string($v)) $urls[] = $v; });
    foreach ($urls as $u) if (strpos($u, $site) !== 0 && strpos($u, 'https://example.org') !== 0 && strpos($u, 'https://schema.org') !== 0) $errors[] = 'foreign address ' . $u;
    ok(!$errors, "$label: " . implode(', ', array_unique(array_column($g['@graph'], '@type'))) . ($errors ? ' -> ' . implode('; ', $errors) : ''));
};
$o_enabled = get_option_meta('qp_seo_enabled'); $o_settings = get_option_meta('qp_seo_settings');
$T = table_name('posts');
try {
    update_option_meta('qp_seo_enabled', true);
    update_option_meta('qp_seo_settings', array_merge(qp_seo_defaults(), ['org_name' => 'Orcasy', 'org_logo' => 'https://example.org/logo.png', 'org_same_as' => 'https://example.org/orcasy']));
    echo "-- rich-result requirements on live pages\n";
    $check('home', get('')['body']);
    $check('post', get('post/welcome-to-your-site')['body']);
    $check('page', get('sample-page')['body']);
    $check('product', get('product/sample-widget')['body']);
    $check('shop', get('shop/')['body']);
    $check('search', get('index.php?route=search&q=widget')['body']);
    $check('post list', get('post/')['body']);

    if ($big > 0) {
        echo "-- speed with $big products in the table\n";
        passthru('php ' . escapeshellarg(__DIR__ . '/search-seed.php') . ' seed ' . (int)$big . ' zipf > ' . (stripos(PHP_OS, 'WIN') === 0 ? 'NUL' : '/dev/null'));
        $timeit = function ($path, $n = 40) { $c = curl_init(); $t = []; for ($i = 0; $i < $n + 5; $i++) { curl_setopt_array($c, [CURLOPT_URL => $GLOBALS['base'] . '/' . $path, CURLOPT_RESOLVE => [$GLOBALS['host'] . ':' . $GLOBALS['port'] . ':' . $GLOBALS['resolve']], CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0, CURLOPT_RETURNTRANSFER => true]); $a = microtime(true); curl_exec($c); if ($i >= 5) $t[] = (microtime(true) - $a) * 1000; } curl_close($c); sort($t); return $t[(int)(count($t) / 2)]; };
        $pages = ['post' => 'post/welcome-to-your-site', 'product' => 'product/sample-widget', 'home' => ''];
        update_option_meta('qp_seo_enabled', false); $off = []; foreach ($pages as $k => $p) $off[$k] = $timeit($p);
        update_option_meta('qp_seo_enabled', true); $on = []; foreach ($pages as $k => $p) $on[$k] = $timeit($p);
        foreach ($pages as $k => $p) echo sprintf("   %-8s module off %.1f ms, on %.1f ms (%+.1f ms)\n", $k, $off[$k], $on[$k], $on[$k] - $off[$k]);
        ok(max(array_map(function ($k) use ($on, $off) { return $on[$k] - $off[$k]; }, array_keys($pages))) < 6, 'the module adds under 6 ms to a page on a large site (median round trip, includes noise)');
        $t = microtime(true); get('index.php?route=archive&post_type=product&paged=3'); $t_arch = (microtime(true) - $t) * 1000;
        echo sprintf("   a product archive page with rel=prev/next (counts 50,000 rows): %.0f ms\n", $t_arch);
        ok($t_arch < 800, 'an archive page with paging links stays under 800 ms with 50,000 products');
    }
} finally {
    if ($big > 0) passthru('php ' . escapeshellarg(__DIR__ . '/search-seed.php') . ' remove > ' . (stripos(PHP_OS, 'WIN') === 0 ? 'NUL' : '/dev/null'));
    update_option_meta('qp_seo_settings', $o_settings ?: qp_seo_defaults());
    update_option_meta('qp_seo_enabled', $o_enabled ?: false);
    qp_seo_sitemap_purge();
    echo "-- restored: module " . var_export((bool)get_option_meta('qp_seo_enabled'), true) . ", seed rows left: " . (int)$pdo->query("SELECT COUNT(*) FROM $T WHERE slug LIKE 'bench-seed-%'")->fetchColumn() . "\n";
}
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
