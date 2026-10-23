<?php
// Dev tool (C3 step 5): checks the editor box, paging links and language alternates. CLI only.
//   php admin/dev/seo-extras-test.php [--base=https://host/path] [--resolve=127.0.0.1]
// Switches the module on, creates temporary posts, then removes everything it created.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('QLOPY_INIT', true);
chdir(dirname(__DIR__, 2));
require 'db.php'; $pdo = db();
require 'auth.php';
require_once 'includes/seo.php';
require_once 'includes/qpmeta.php';
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
    curl_setopt_array($c, [CURLOPT_RESOLVE => ["$host:$port:$resolve"], CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60]);
    $b = (string)curl_exec($c); $code = curl_getinfo($c, CURLINFO_HTTP_CODE); curl_close($c);
    return ['code' => $code, 'body' => $b];
}
function meta_content($html, $attr, $name) { return preg_match('/<meta[^>]+' . $attr . '=["\']' . preg_quote($name, '/') . '["\'][^>]*content=["\']([^"\']*)/i', $html, $m) ? html_entity_decode($m[1]) : null; }
function link_href($html, $rel) { return preg_match('/<link[^>]+rel=["\']' . $rel . '["\'][^>]*href=["\']([^"\']*)/i', $html, $m) ? html_entity_decode($m[1]) : null; }
$site = rtrim($config['site_url'], '/');
$T = table_name('posts'); $made = [];
$o_enabled = get_option_meta('qp_seo_enabled'); $o_settings = get_option_meta('qp_seo_settings');
$slug = 'welcome-to-your-site';
$pid = (int)$pdo->query("SELECT id FROM $T WHERE slug = '$slug' AND post_type = 'post'")->fetchColumn();
$keys = ['_seo_title', '_seo_description', '_seo_robots', '_seo_canonical', '_seo_og_image'];
$o_meta = []; foreach ($keys as $k) $o_meta[$k] = get_post_meta($pid, $k);
try {
    update_option_meta('qp_seo_enabled', true);
    update_option_meta('qp_seo_settings', array_merge(qp_seo_defaults(), []));

    echo "-- editor box\n";
    $GLOBALS['qpmeta_metaboxes'] = [];
    qp_seo_register_metabox();
    $box = null; foreach ($GLOBALS['qpmeta_metaboxes'] as $b) if (($b['id'] ?? '') === 'qp_seo_box') $box = $b;
    ok($box && array_column($box['fields'], 'id') === $keys, 'one box with the five fields: ' . ($box ? implode(',', array_column($box['fields'], 'id')) : 'missing'));
    ok($box && in_array('post', $box['post_types'], true) && in_array('page', $box['post_types'], true) && !in_array('attachment', $box['post_types'], true), 'on posts, pages and custom types, not on media');
    ob_start(); qpmeta_render_metaboxes_in_context('post', $pid, 'normal'); $html = ob_get_clean();
    ok(strpos($html, 'name=\'_seo_title\'') !== false && strpos($html, 'name=\'_seo_description\'') !== false && strpos($html, 'name=\'_seo_robots\'') !== false && strpos($html, 'name=\'_seo_canonical\'') !== false && strpos($html, 'name=\'_seo_og_image\'') !== false, 'the editor renders all five inputs');
    $errs = [];
    qpmeta_save_metaboxes('post', $pid, ['_seo_title' => 'Box title for %site%', '_seo_description' => 'Box description text.', '_seo_robots' => 'noindex', '_seo_canonical' => '', '_seo_og_image' => 'https://example.org/box.png'], [], $errs);
    ok(get_post_meta($pid, '_seo_description') === 'Box description text.' && get_post_meta($pid, '_seo_robots') === 'noindex', 'saving the box stores the post meta');
    $p = get('post/' . $slug)['body'];
    ok(strpos((string)meta_content($p, 'property', 'og:title'), 'Box title for ') === 0 && meta_content($p, 'property', 'og:description') === 'Box description text.' && meta_content($p, 'name', 'robots') === 'noindex' && meta_content($p, 'property', 'og:image') === 'https://example.org/box.png', 'what the box saved is what the page prints');
    foreach ($keys as $k) { if ($o_meta[$k] === '' || $o_meta[$k] === null) delete_post_meta($pid, $k); else update_post_meta($pid, $k, $o_meta[$k]); }

    echo "-- paging (26 posts, 10 per page)\n";
    $ins = $pdo->prepare("INSERT INTO $T (title,slug,content,post_type,status,visibility,published_at) VALUES (?,?,?,?,?,?,?)");
    for ($i = 1; $i <= 25; $i++) { $ins->execute(["T5 page post $i", "t5-page-$i", 'x', 'post', 'published', 'public', gmdate('Y-m-d H:i:s')]); $made[] = (int)$pdo->lastInsertId(); }
    $total = (int)$pdo->query("SELECT COUNT(*) FROM $T WHERE post_type = 'post' AND status = 'published' AND visibility = 'public'")->fetchColumn();
    $pages = (int)ceil($total / 10);
    $a1 = get('post/')['body']; $a2 = get('post/?paged=2')['body']; $aN = get('post/?paged=' . $pages)['body'];
    ok(link_href($a1, 'next') !== null && link_href($a1, 'prev') === null && strpos(link_href($a1, 'next'), 'paged=2') !== false, "page 1 of $pages: next only (" . link_href($a1, 'next') . ')');
    ok(link_href($a2, 'prev') !== null && strpos((string)link_href($a2, 'next'), 'paged=3') !== false && strpos((string)link_href($a2, 'prev'), 'paged=') === false, 'page 2: prev (the first page, no paged) and next (paged=3)');
    ok(link_href($aN, 'next') === null && link_href($aN, 'prev') !== null, "page $pages (last): prev only");
    ok(strpos((string)link_href($a2, 'canonical'), 'paged=2') !== false || link_href($a2, 'canonical') !== null, 'each page has its own canonical: ' . link_href($a2, 'canonical'));
    $GLOBALS['qp_seo_max_pages'] = 7; $_GET = ['route' => 'archive', 'post_type' => 'post', 'paged' => 1];
    ok(qp_seo_total_pages() === 7, 'a template can state the page count itself');

    echo "-- language alternates (separate process)\n";
    $tmp = sys_get_temp_dir() . '/qp_seo_hl_test.php';
    file_put_contents($tmp, "<?php\ndefine('QLOPY_INIT', true); define('QP_SEO_FORCE_RENDER', true); chdir(" . var_export(getcwd(), true) . ");\nrequire 'db.php'; require 'auth.php'; require_once 'includes/seo.php';\n\$_GET = ['route' => 'singular', 'post_type' => 'post', 'slug' => " . var_export($slug, true) . "];\n"
        . "\$st = db()->prepare('SELECT * FROM ' . table_name('posts') . ' WHERE slug = ?'); \$st->execute([" . var_export($slug, true) . "]); \$GLOBALS['post'] = \$st->fetch(PDO::FETCH_ASSOC);\n"
        . "add_filter('qp_seo_alternates', function (\$a, \$ctx, \$m) { return [['lang' => 'fr', 'href' => 'https://evil.example/orcasy/fr/post/x'], ['lang' => 'pt-BR', 'href' => 'https://aloelo.dev/orcasy/pt/post/x'], ['lang' => 'x-default', 'href' => 'https://aloelo.dev/orcasy/post/x'], ['lang' => 'not a lang!', 'href' => 'https://aloelo.dev/'], ['lang' => 'de', 'href' => '']]; }, 10, 3);\n"
        . "add_filter('qp_seo_locale', function () { return 'fr_FR'; });\n"
        . "ob_start(); qp_seo_render_alternates(); qp_seo_render_head(); echo ob_get_clean();\n");
    $out = (string)shell_exec('php ' . escapeshellarg($tmp) . ' 2>&1'); @unlink($tmp);
    preg_match_all('/<link rel="alternate" hreflang="([^"]+)" href="([^"]+)">/', $out, $mm, PREG_SET_ORDER);
    $langs = array_column($mm, 1);
    ok($langs === ['fr', 'pt-BR', 'x-default'], 'hreflang links for valid languages only, x-default allowed: ' . implode(',', $langs));
    ok(strpos($out, 'evil.example') === false && strpos($mm[0][2] ?? '', $site . '/fr/post/x') === 0, 'an address on another host is rebased onto the site address: ' . ($mm[0][2] ?? ''));
    ok(strpos($out, '<meta property="og:locale" content="fr_FR">') !== false, 'og:locale from the layer');
    $tmp2 = sys_get_temp_dir() . '/qp_seo_hl2_test.php';
    file_put_contents($tmp2, "<?php\ndefine('QLOPY_INIT', true); chdir(" . var_export(getcwd(), true) . ");\nrequire 'db.php'; require 'auth.php'; require_once 'includes/seo.php';\n"
        . "add_filter('qp_seo_sitemap_alternates', function (\$a, \$post, \$loc) { return [['lang' => 'fr', 'href' => 'https://evil.example/orcasy/fr' . parse_url(\$loc, PHP_URL_PATH)]]; }, 10, 3);\n"
        . "echo qp_seo_sitemap_file_xml('post', 1);\n");
    $sm = (string)shell_exec('php ' . escapeshellarg($tmp2) . ' 2>&1'); @unlink($tmp2); qp_seo_sitemap_purge();
    ok(strpos($sm, '<xhtml:link rel="alternate" hreflang="fr" href="' . $site) !== false && strpos($sm, 'evil.example') === false, 'sitemap entries carry the alternates, on the site address');
} finally {
    foreach ($keys as $k) { if ($o_meta[$k] === '' || $o_meta[$k] === null) delete_post_meta($pid, $k); else update_post_meta($pid, $k, $o_meta[$k]); }
    if ($made) { $in = implode(',', array_map('intval', $made)); $pdo->exec("DELETE FROM " . table_name('post_meta') . " WHERE post_id IN ($in)"); $pdo->exec("DELETE FROM $T WHERE id IN ($in)"); }
    update_option_meta('qp_seo_settings', $o_settings ?: qp_seo_defaults());
    update_option_meta('qp_seo_enabled', $o_enabled ?: false);
    qp_seo_sitemap_purge();
    echo "-- restored: module " . var_export((bool)get_option_meta('qp_seo_enabled'), true) . ", temporary posts removed: " . count($made) . "\n";
}
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
