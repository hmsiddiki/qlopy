<?php
// Dev tool (C3 step 6): checks qCommerce's use of the SEO module (the worked plugin example) over real HTTP. CLI only.
//   php admin/dev/seo-qcommerce-test.php [--base=https://host/path] [--resolve=127.0.0.1]
// Switches the module on, runs the checks, then restores the SEO settings. Needs qCommerce active and the sample product.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('QLOPY_INIT', true);
chdir(dirname(__DIR__, 2));
require 'db.php'; $pdo = db();
require 'auth.php';
require_once 'includes/seo.php';
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
    curl_setopt_array($c, [CURLOPT_RESOLVE => ["$host:$port:$resolve"], CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0, CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 60]);
    $r = (string)curl_exec($c); $hs = curl_getinfo($c, CURLINFO_HEADER_SIZE); $code = curl_getinfo($c, CURLINFO_HTTP_CODE); curl_close($c);
    return ['code' => $code, 'head' => substr($r, 0, $hs), 'body' => substr($r, $hs)];
}
function blocks($html) { preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $m); return $m[1]; }
function meta_content($html, $attr, $name) { return preg_match('/<meta[^>]+' . $attr . '=["\']' . preg_quote($name, '/') . '["\'][^>]*content=["\']([^"\']*)/i', $html, $m) ? html_entity_decode($m[1]) : null; }
function link_href($html, $rel) { return preg_match('/<link[^>]+rel=["\']' . $rel . '["\'][^>]*href=["\']([^"\']*)/i', $html, $m) ? html_entity_decode($m[1]) : null; }
$site = rtrim($config['site_url'], '/');
$o_enabled = get_option_meta('qp_seo_enabled'); $o_settings = get_option_meta('qp_seo_settings');
$pid = (int)$pdo->query("SELECT id FROM " . table_name('posts') . " WHERE slug = 'sample-widget' AND post_type = 'product'")->fetchColumn();
try {
    update_option_meta('qp_seo_settings', array_merge(qp_seo_defaults(), []));

    echo "-- module off: qCommerce prints its own product data as before\n";
    update_option_meta('qp_seo_enabled', false);
    $off = get('product/sample-widget'); $b = blocks($off['body']);
    ok(count($b) === 1 && strpos($b[0], '"@type":"Product"') !== false && strpos($b[0], '"@context":"https://schema.org"') !== false, 'one standalone Product block with its own context');

    echo "-- module on: the product joins the page's graph\n";
    update_option_meta('qp_seo_enabled', true);
    $on = get('product/sample-widget'); $b = blocks($on['body']); $j = count($b) === 1 ? json_decode($b[0], true) : null;
    ok($j !== null && count($b) === 1, 'exactly one JSON-LD block on the product page (no second Product block)');
    $types = $j ? array_column($j['@graph'], '@type') : [];
    ok(in_array('Product', $types, true) && in_array('WebPage', $types, true) && in_array('BreadcrumbList', $types, true), 'graph holds Product with WebPage and BreadcrumbList: ' . implode(',', $types));
    $prod = null; $wp = null; foreach ($j['@graph'] ?? [] as $n) { if ($n['@type'] === 'Product') $prod = $n; if ($n['@type'] === 'WebPage') $wp = $n; }
    ok($prod && ($prod['name'] ?? '') === 'Sample Widget' && ($prod['sku'] ?? '') === 'SAMPLE-1', 'name and SKU: ' . ($prod['name'] ?? '-') . ' / ' . ($prod['sku'] ?? '-'));
    ok($prod && ($prod['offers']['@type'] ?? '') === 'Offer' && preg_match('/^\d+(\.\d+)?$/', (string)($prod['offers']['price'] ?? '')) && !empty($prod['offers']['priceCurrency']) && strpos((string)($prod['offers']['availability'] ?? ''), 'schema.org/') !== false, 'offer with price, currency and availability: ' . json_encode($prod['offers'] ?? []));
    ok($prod && ($prod['mainEntityOfPage']['@id'] ?? '') === ($wp['@id'] ?? 'x') && ($wp['about']['@id'] ?? '') === ($prod['@id'] ?? 'y'), 'Product and WebPage point at each other');
    ok($prod && strpos($prod['url'] ?? '', $site) === 0 && strpos($prod['offers']['url'] ?? $site, $site) === 0, 'addresses on the site address: ' . ($prod['url'] ?? '-'));
    ok(strpos($on['body'], '"@context":"https:\/\/schema.org"') === false || substr_count($on['body'], 'schema.org"') >= 1, 'the graph has a single @context');
    $breadcrumb = null; foreach ($j['@graph'] ?? [] as $n) if ($n['@type'] === 'BreadcrumbList') $breadcrumb = $n;
    ok($breadcrumb && end($breadcrumb['itemListElement'])['name'] === 'Sample Widget', 'breadcrumb ends with the product');

    echo "-- private pages and the shop\n";
    $cart = get('cart/'); $robots = meta_content($cart['body'], 'name', 'robots');
    ok($cart['code'] === 200 && $robots === 'noindex' && stripos($cart['head'], 'X-Robots-Tag: noindex') !== false, 'cart: noindex in the tag and the header (' . $robots . ')');
    ok(link_href($cart['body'], 'canonical') === null || strpos($cart['body'], 'rel="canonical"') === false, 'cart: no canonical');
    foreach (['checkout/' => 'checkout', 'my-orders/' => 'account'] as $u => $n) { $r = get($u); ok(meta_content($r['body'], 'name', 'robots') === 'noindex' || $r['code'] !== 200, "$n page is noindex (or not shown to a visitor: " . $r['code'] . ')'); }
    $shop = get('shop/');
    ok($shop['code'] === 200 && meta_content($shop['body'], 'name', 'robots') === 'index,follow' && link_href($shop['body'], 'canonical') === $site . '/shop', 'shop page: indexable, canonical is the address qCommerce links to: ' . link_href($shop['body'], 'canonical'));
    ok(strpos(meta_content($shop['body'], 'property', 'og:title') ?? '', 'Page not found') === false, 'the shop is not mistaken for a 404');
    $sj = blocks($shop['body']); $sg = count($sj) === 1 ? json_decode($sj[0], true) : null;
    ok($sg !== null && in_array('WebPage', array_column($sg['@graph'], '@type'), true), 'the shop page has a WebPage graph');

    echo "-- robots.txt and sitemap\n";
    $rb = get('robots.txt')['body'];
    ok(strpos($rb, '/cart/') !== false && strpos($rb, '/checkout/') !== false && strpos($rb, '/qcommerce-payment/') !== false && strpos($rb, '/qcommerce-download') !== false, 'robots.txt disallows cart, checkout, payment and download addresses');
    qp_seo_sitemap_purge(); $ix = get('sitemap.xml')['body'];
    ok(strpos($ix, '/sitemap-product-1.xml') !== false, 'products are in the sitemap index');
    $pm = get('sitemap-product-1.xml')['body'];
    ok(strpos($pm, '/product/sample-widget') !== false, 'the sample product is listed');
    // visibility: a private product is not listed
    $T = table_name('posts');
    $pdo->prepare("INSERT INTO $T (title,slug,content,post_type,status,visibility,published_at) VALUES ('Private widget','t6-private-widget','x','product','published','private',?)")->execute([gmdate('Y-m-d H:i:s')]);
    $privId = (int)$pdo->lastInsertId(); do_action('save_post', $privId);
    $pm2 = get('sitemap-product-1.xml')['body']; ok(strpos($pm2, 't6-private-widget') === false, 'a private product is not listed');
    $pdo->exec("DELETE FROM $T WHERE id = $privId"); qp_seo_sitemap_purge();
} finally {
    update_option_meta('qp_seo_settings', $o_settings ?: qp_seo_defaults());
    update_option_meta('qp_seo_enabled', $o_enabled ?: false);
    qp_seo_sitemap_purge();
    echo "-- restored: module " . var_export((bool)get_option_meta('qp_seo_enabled'), true) . "\n";
}
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
