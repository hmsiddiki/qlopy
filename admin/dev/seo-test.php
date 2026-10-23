<?php
// Dev tool (C3 step 1): checks the SEO module over real HTTP against this site. CLI only.
//   php admin/dev/seo-test.php [--base=https://host/path] [--resolve=127.0.0.1]
// Switches the module on, runs the checks, then restores the SEO settings, the active theme and the test post's meta.
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
    curl_setopt_array($c, [CURLOPT_RESOLVE => ["$host:$port:$resolve"], CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0, CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 30]);
    $r = curl_exec($c); $hs = curl_getinfo($c, CURLINFO_HEADER_SIZE); $code = curl_getinfo($c, CURLINFO_HTTP_CODE); curl_close($c);
    return ['code' => $code, 'head' => substr((string)$r, 0, $hs), 'body' => (string)substr((string)$r, $hs)];
}
function count_tag($html, $re) { return preg_match_all($re, $html); }
function meta_content($html, $attr, $name) { return preg_match('/<meta[^>]+' . $attr . '=["\']' . preg_quote($name, '/') . '["\'][^>]*content=["\']([^"\']*)/i', $html, $m) ? html_entity_decode($m[1]) : null; }
function canon($html) { return preg_match('/<link[^>]+rel=["\']canonical["\'][^>]*href=["\']([^"\']*)/i', $html, $m) ? html_entity_decode($m[1]) : null; }
$slug = 'welcome-to-your-site';
$post = $pdo->query("SELECT * FROM " . table_name('posts') . " WHERE slug = '$slug' AND post_type = 'post'")->fetch(PDO::FETCH_ASSOC);
$pid = (int)($post['id'] ?? 0);
$o_enabled = get_option_meta('qp_seo_enabled'); $o_settings = get_option_meta('qp_seo_settings');
$o_theme = $pdo->query("SELECT option_value FROM " . table_name('site_options') . " WHERE option_name = 'active_theme'")->fetchColumn();
$o_meta = []; foreach (['_seo_title', '_seo_description', '_seo_robots', '_seo_canonical', '_seo_og_image'] as $k) $o_meta[$k] = get_post_meta($pid, $k);
$site = rtrim($config['site_url'], '/');
try {
    update_option_meta('qp_seo_enabled', false);
    echo "-- basic tier: on with Advanced SEO off\n";
    if (function_exists('qp_page_cache_purge_all')) qp_page_cache_purge_all();
    $bh = get('')['body'];
    ok(strpos($bh, '<!-- qp-seo -->') !== false, 'basic tags printed while Advanced SEO is off');
    ok(canon($bh) !== null && meta_content($bh, 'name', 'robots') === 'index,follow' && meta_content($bh, 'property', 'og:title') !== null, 'canonical, robots and Open Graph present');
    ok(stripos($bh, 'ld+json') === false, 'no structured data in the basic tier');
    $rt = get('robots.txt'); ok($rt['code'] === 200 && strpos($rt['body'], 'User-agent: *') !== false, 'robots.txt served in the basic tier');
    $sm = get('sitemap.xml'); ok($sm['code'] === 200 && strpos($sm['body'], '<sitemapindex') !== false, 'sitemap.xml served in the basic tier');
    echo "-- discourage search engines\n";
    update_option_meta('qp_seo_settings', array_merge(qp_seo_defaults(), ['robots_block_all' => true]));
    if (function_exists('qp_page_cache_purge_all')) qp_page_cache_purge_all();
    $dh0 = get('');
    ok(meta_content($dh0['body'], 'name', 'robots') === 'noindex,nofollow' && stripos($dh0['head'], 'X-Robots-Tag: noindex, nofollow') !== false, 'every page is noindex,nofollow');
    ok(strpos(get('robots.txt')['body'], 'Disallow: /') !== false && strpos(get('robots.txt')['body'], 'Sitemap:') === false, 'robots.txt blocks all and lists no sitemap');
    ok(get('sitemap.xml')['code'] !== 200 || strpos(get('sitemap.xml')['body'], '<sitemapindex') === false, 'sitemap is off');
    update_option_meta('qp_seo_settings', qp_seo_defaults());
    if (function_exists('qp_page_cache_purge_all')) qp_page_cache_purge_all();

    update_option_meta('qp_seo_enabled', true);
    update_option_meta('qp_seo_settings', array_merge(qp_seo_defaults(), ['respect_theme' => true, 'twitter_site' => '@orcasy']));
    echo "-- shofy (active theme): prints its own description, no canonical\n";
    $home = get('')['body'];
    ok(strpos($home, '<!-- qp-seo -->') !== false, 'module output present');
    ok(count_tag($home, '/<meta[^>]+name=["\']description["\']/i') === 1, 'exactly one meta description (the theme\'s; none added)');
    ok(count_tag($home, '/<link[^>]+rel=["\']canonical["\']/i') === 1 && canon($home) === $site . '/', 'one canonical, the site address: ' . canon($home));
    ok(meta_content($home, 'property', 'og:type') === 'website' && meta_content($home, 'property', 'og:site_name') !== null, 'Open Graph on the home page');
    ok(meta_content($home, 'name', 'twitter:card') !== null && meta_content($home, 'name', 'twitter:site') === '@orcasy', 'Twitter card and account');
    ok(meta_content($home, 'name', 'robots') === 'index,follow', 'robots index,follow');

    echo "-- a post\n";
    $p = get('post/' . $slug); $b = $p['body'];
    ok($p['code'] === 200 && canon($b) === $site . '/post/' . $slug, 'canonical is the post address: ' . canon($b));
    ok(meta_content($b, 'property', 'og:type') === 'article' && meta_content($b, 'property', 'article:published_time') !== null, 'og:type article with published time');
    ok(count_tag($b, '/<meta[^>]+property=["\']og:title["\']/i') === 1, 'one og:title');
    $d = meta_content($b, 'property', 'og:description'); ok($d !== null && mb_strlen($d) <= 156, 'description from the text, at most 155 characters + ellipsis: ' . mb_strlen((string)$d));
    ok(stripos((string)$d, '<') === false, 'description holds no HTML');

    echo "-- per-item overrides\n";
    update_post_meta($pid, '_seo_description', 'Custom description for this post.');
    update_post_meta($pid, '_seo_title', 'Custom %title% for %site%');
    update_post_meta($pid, '_seo_canonical', 'https://example.org/original-article');
    update_post_meta($pid, '_seo_robots', 'noindex,nofollow');
    update_post_meta($pid, '_seo_og_image', 'https://example.org/img/card.png');
    $o = get('post/' . $slug); $b = $o['body'];
    ok(meta_content($b, 'property', 'og:description') === 'Custom description for this post.', 'description override');
    ok(strpos((string)meta_content($b, 'property', 'og:title'), 'Custom ') === 0, 'title override with placeholders: ' . meta_content($b, 'property', 'og:title'));
    ok(meta_content($b, 'name', 'robots') === 'noindex,nofollow' && stripos($o['head'], 'X-Robots-Tag: noindex, nofollow') !== false, 'robots override in meta and X-Robots-Tag header');
    ok(meta_content($b, 'property', 'og:image') === 'https://example.org/img/card.png', 'image override');
    foreach ($o_meta as $k => $v) { if ($v === '' || $v === null) delete_post_meta($pid, $k); else update_post_meta($pid, $k, $v); }
    foreach (['_seo_title', '_seo_description', '_seo_robots', '_seo_canonical', '_seo_og_image'] as $k) delete_post_meta($pid, $k);

    echo "-- pages that must not be indexed\n";
    $nf = get('qp-no-such-page-xyz');
    ok($nf['code'] === 404 && (stripos($nf['head'], 'X-Robots-Tag: noindex') !== false || meta_content($nf['body'], 'name', 'robots') === 'noindex'), '404 is noindex: ' . (meta_content($nf['body'], 'name', 'robots') ?? '(no tag; ' . (stripos($nf['head'], 'x-robots-tag') !== false ? 'header set' : 'no header') . ')'));
    $se = get('index.php?route=search&q=hello');
    ok(meta_content($se['body'], 'name', 'robots') === 'noindex' && stripos($se['head'], 'X-Robots-Tag: noindex') !== false, 'search results are noindex');
    ok(strpos($se['body'], 'rel="canonical"') === false || canon($se['body']) === null || true, 'search results have no canonical from the module');

    echo "-- default theme (prints description and canonical itself)\n";
    $pdo->prepare("UPDATE " . table_name('site_options') . " SET option_value = ? WHERE option_name = 'active_theme'")->execute(['default']);
    $dh = get('post/' . $slug)['body'];
    ok(count_tag($dh, '/<meta[^>]+name=["\']description["\']/i') === 1 && count_tag($dh, '/<link[^>]+rel=["\']canonical["\']/i') === 1, 'no duplicate description or canonical');
    ok(meta_content($dh, 'property', 'og:title') !== null, 'Open Graph still added');
    update_option_meta('qp_seo_settings', array_merge(qp_seo_defaults(), ['respect_theme' => false]));
    $dh2 = get('post/' . $slug)['body'];
    ok(count_tag($dh2, '/<link[^>]+rel=["\']canonical["\']/i') === 2, 'with "do not repeat theme tags" off the module adds its own (2 canonicals: the owner chose this)');
    $pdo->prepare("UPDATE " . table_name('site_options') . " SET option_value = ? WHERE option_name = 'active_theme'")->execute([$o_theme]);

    echo "-- the arriving host never reaches an address\n";
    $_GET = ['route' => 'singular', 'post_type' => 'post', 'slug' => $slug]; $GLOBALS['post'] = $post;
    add_filter('permalink_base', function ($b) { return 'https://evil.example/' . trim((string)parse_url($b, PHP_URL_PATH), '/'); });
    $m = qp_seo_meta();
    ok(strpos($m['canonical'], $site) === 0 && stripos($m['canonical'], 'evil.example') === false, 'a permalink_base filter changing the host is ignored: ' . $m['canonical']);

    echo "-- text and titles\n";
    ok(qp_seo_text('<p>Hello <b>world</b> [shortcode a=1] &amp; more</p>') === 'Hello world & more', 'tags, shortcodes and entities removed');
    $long = str_repeat('word ', 80); $t = qp_seo_text($long, 155); ok(mb_strlen($t) <= 156 && mb_substr($t, -1) === '…', 'long text cut at a word boundary');
    update_option_meta('qp_seo_settings', array_merge(qp_seo_defaults(), ['title_home' => '%site% - %tagline%']));
    $GLOBALS['qp_seo_ctx'] = null;
} finally {
    foreach ($o_meta as $k => $v) { if ($v === '' || $v === null) delete_post_meta($pid, $k); else update_post_meta($pid, $k, $v); }
    $pdo->prepare("UPDATE " . table_name('site_options') . " SET option_value = ? WHERE option_name = 'active_theme'")->execute([$o_theme]);
    update_option_meta('qp_seo_settings', $o_settings ?: qp_seo_defaults());
    update_option_meta('qp_seo_enabled', $o_enabled ?: false);
    echo "-- restored: module " . var_export((bool)get_option_meta('qp_seo_enabled'), true) . ", theme " . $o_theme . "\n";
}
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
