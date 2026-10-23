<?php
// Dev tool: checks the real qp-multilang plugin against the core's multilingual contract, the SEO module and the page cache, over real HTTP.
// CLI only.   php admin/dev/multilang-test.php [--base=https://host/path] [--resolve=127.0.0.1]
// Switches qp-multilang on with three languages (en default, fr, de) and one translated post, runs the checks, then restores the plugin list,
// the plugin's options, the post's meta, the SEO settings and the page cache.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('QLOPY_INIT', true);
chdir(dirname(__DIR__, 2));
require 'db.php'; $pdo = db();
require 'auth.php';
require_once 'includes/seo.php';
require_once 'includes/page-cache.php';
$GLOBALS['config'] = $config;
$opt = getopt('', ['base::', 'resolve::']);
$base = rtrim($opt['base'] ?? $config['site_url'], '/');
$host = parse_url($base, PHP_URL_HOST);
$port = parse_url($base, PHP_URL_PORT) ?: (parse_url($base, PHP_URL_SCHEME) === 'https' ? 443 : 80);
$resolve = $opt['resolve'] ?? '127.0.0.1';
$pass = 0; $fail = 0;
function ok($c, $m) { global $pass, $fail; if ($c) $pass++; else $fail++; echo ($c ? 'PASS ' : 'FAIL ') . $m . "\n"; }
function get($path, $o = []) {
    global $base, $host, $port, $resolve;
    $c = curl_init($base . '/' . ltrim($path, '/'));
    $h = $o['h'] ?? [];
    $opts = [CURLOPT_RESOLVE => ["$host:$port:$resolve"], CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0, CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 60, CURLOPT_HTTPHEADER => $h];
    if (!empty($o['cookie'])) $opts[CURLOPT_COOKIE] = $o['cookie'];
    curl_setopt_array($c, $opts);
    $r = (string)curl_exec($c); $hs = curl_getinfo($c, CURLINFO_HEADER_SIZE); $code = curl_getinfo($c, CURLINFO_HTTP_CODE); curl_close($c);
    $head = substr($r, 0, $hs); preg_match('/^X-QP-Cache:\s*(.*)$/mi', $head, $m);
    return ['code' => $code, 'head' => $head, 'body' => substr($r, $hs), 'cache' => trim($m[1] ?? ''), 'setcookie' => stripos($head, 'Set-Cookie: qp_lang') !== false];
}
function alt_rows($html) { preg_match_all('/<link rel="alternate" hreflang="([^"]+)" href="([^"]+)">/', $html, $m, PREG_SET_ORDER); $o = []; foreach ($m as $r) $o[$r[1]] = html_entity_decode($r[2]); return $o; }
function meta_content($html, $attr, $name) { return preg_match('/<meta[^>]+' . $attr . '=["\']' . preg_quote($name, '/') . '["\'][^>]*content=["\']([^"\']*)/i', $html, $m) ? html_entity_decode($m[1]) : null; }
function link_href($html, $rel) { return preg_match('/<link[^>]+rel=["\']' . $rel . '["\'][^>]*href=["\']([^"\']*)/i', $html, $m) ? html_entity_decode($m[1]) : null; }
$site = rtrim($config['site_url'], '/');
$T = table_name('posts'); $PM = table_name('post_meta'); $SO = table_name('site_options');
$slug = 'welcome-to-your-site';
$pid = (int)$pdo->query("SELECT id FROM $T WHERE slug = '$slug' AND post_type = 'post'")->fetchColumn();
$o_plugins = $pdo->query("SELECT option_value FROM $SO WHERE option_name = 'active_plugins'")->fetchColumn();
$o_ml = $pdo->query("SELECT option_value FROM $SO WHERE option_name = 'qp_multilang_options'")->fetchColumn();
$o_seo_on = get_option_meta('qp_seo_enabled'); $o_seo = get_option_meta('qp_seo_settings');
$o_meta = []; foreach (['i18n_title', 'i18n_content'] as $k) { $st = $pdo->prepare("SELECT meta_value FROM $PM WHERE post_id = ? AND meta_key = ?"); $st->execute([$pid, $k]); $o_meta[$k] = $st->fetchColumn(); }
$setOpt = function ($name, $value) use ($pdo, $SO) { $pdo->prepare("INSERT INTO $SO (option_name, option_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE option_value = VALUES(option_value)")->execute([$name, $value]); };
try {
    $plugins = json_decode($o_plugins, true) ?: []; $plugins['qp-multilang'] = true;
    $setOpt('active_plugins', json_encode($plugins));
    $setOpt('qp_multilang_options', json_encode(['languages' => ['en' => ['label' => 'English', 'locale' => 'en_US'], 'fr' => ['label' => 'Français', 'locale' => 'fr_FR'], 'de' => ['label' => 'Deutsch', 'locale' => '']], 'default' => 'en', 'components' => ['post_types' => ['post' => true], 'taxonomies' => []]]));
    $pdo->prepare("DELETE FROM $PM WHERE post_id = ? AND meta_key IN ('i18n_title','i18n_content')")->execute([$pid]);
    $ins = $pdo->prepare("INSERT INTO $PM (post_id, meta_key, meta_value) VALUES (?, ?, ?)");
    $ins->execute([$pid, 'i18n_title', json_encode(['en' => 'Welcome', 'fr' => 'Bienvenue'])]);
    $ins->execute([$pid, 'i18n_content', json_encode(['fr' => 'Bonjour le monde, ceci est le texte français.'])]);
    update_option_meta('qp_seo_enabled', true);
    update_option_meta('qp_seo_settings', array_merge(qp_seo_defaults(), []));
    qp_seo_sitemap_purge();

    echo "-- registration and SEO (no cookie: the default language)\n";
    $en = get('post/' . $slug); $alts = alt_rows($en['body']);
    ok($en['code'] === 200 && array_keys($alts) === ['en', 'fr', 'x-default'], 'hreflang for the languages the post really has text in, and x-default: ' . implode(',', array_keys($alts)));
    ok(($alts['en'] ?? '') === $site . '/post/' . $slug && ($alts['fr'] ?? '') === $site . '/post/' . $slug . '?lang=fr' && ($alts['x-default'] ?? '') === ($alts['en'] ?? 'x'), 'default language has no parameter, French is ?lang=fr, x-default is the default: ' . ($alts['fr'] ?? '-'));
    ok(!isset($alts['de']), 'German (no own text for this post) is not offered: a fallback copy would be duplicate content');
    ok(link_href($en['body'], 'canonical') === $site . '/post/' . $slug && meta_content($en['body'], 'property', 'og:locale') === 'en_US', 'English page: canonical without parameter, og:locale en_US');
    ok(alt_rows(get('sample-page')['body']) === [], 'a page type the plugin does not translate gets no alternates');

    echo "-- the French page\n";
    $fr = get('post/' . $slug . '?lang=fr');
    ok(strpos((string)meta_content($fr['body'], 'property', 'og:title'), 'Bienvenue') === 0, 'French title reaches the SEO tags through the older ml_the_title filter: ' . meta_content($fr['body'], 'property', 'og:title'));
    ok(link_href($fr['body'], 'canonical') === $site . '/post/' . $slug . '?lang=fr' && meta_content($fr['body'], 'property', 'og:locale') === 'fr_FR', 'French page: canonical is the ?lang=fr address, og:locale fr_FR');
    ok(strpos((string)meta_content($fr['body'], 'property', 'og:description'), 'Bonjour') === 0, 'French description comes from the French text');
    $frc = get('post/' . $slug, ['cookie' => 'qp_lang=fr']);
    ok(strpos((string)meta_content($frc['body'], 'property', 'og:title'), 'Bienvenue') === 0 && link_href($frc['body'], 'canonical') === $site . '/post/' . $slug . '?lang=fr', 'a visitor with the cookie sees French at the plain address and its canonical is the French address');
    $de = get('post/' . $slug . '?lang=de');
    ok(strpos((string)meta_content($de['body'], 'property', 'og:title'), 'Welcome') === 0 && link_href($de['body'], 'canonical') === $site . '/post/' . $slug, '?lang=de: no German text, so the default page and its own canonical');
    ok(meta_content($de['body'], 'property', 'og:locale') === 'de', 'a language with no locale set falls back to its code: ' . meta_content($de['body'], 'property', 'og:locale'));

    echo "-- sitemap\n";
    $sm = get('sitemap-post-1.xml')['body']; $pm = get('sitemap-page-1.xml')['body'];
    ok(preg_match_all('#<loc>[^<]*/post/' . preg_quote($slug, '#') . '[^<]*</loc>#', $sm) === 2 && strpos($sm, '<loc>' . $site . '/post/' . $slug . '?lang=fr</loc>') !== false && substr_count($sm, 'hreflang="fr"') === 2 && substr_count($sm, 'hreflang="x-default"') === 2, 'the translated post is listed twice (default and ?lang=fr), each with both alternates and x-default');
    ok(substr_count($sm, '<url>') === (int)$pdo->query("SELECT COUNT(*) FROM $T WHERE post_type = 'post' AND status = 'published' AND visibility = 'public'")->fetchColumn() + 1 && strpos($pm, 'hreflang') === false, 'other posts and the pages are listed once, without alternates');

    echo "-- what the plugin did before is unchanged (separate process with the plugin loaded)\n";
    $tmp = sys_get_temp_dir() . '/qp_ml_unchanged_test.php';
    file_put_contents($tmp, "<?php\ndefine('QLOPY_INIT', true); chdir(" . var_export(getcwd(), true) . ");\nrequire 'db.php'; require 'auth.php';\n\$_COOKIE['qp_lang'] = 'fr';\nrequire 'content/plugins/qp-multilang/plugin.php';\n"
        . "echo json_encode(['ml' => ml_get_current_lang(), 'core' => qp_current_language(), 'def' => qp_default_language(), 'langs' => array_keys(qp_languages()), 'fr' => qp_language_info('fr'), 'meta' => get_post_meta(" . (int)$pid . ", 'i18n_title'), 'on' => qp_i18n_enabled()]);\n");
    $u = json_decode((string)shell_exec('php ' . escapeshellarg($tmp) . ' 2>&1'), true); @unlink($tmp);
    ok(is_array($u) && $u['ml'] === 'fr' && $u['core'] === 'fr' && $u['def'] === 'en' && $u['langs'] === ['en', 'fr', 'de'] && $u['on'] === true, 'ml_get_current_lang() and the core\'s qp_current_language() agree: ' . json_encode($u));
    ok(is_array($u) && ($u['fr']['name'] ?? '') === 'Français' && ($u['fr']['locale'] ?? '') === 'fr_FR', 'the plugin\'s label becomes the language name');
    ok(is_array($u) && ($u['meta'] ?? '') === 'Bienvenue', 'post meta is still translated through the ml_get_meta filter');
    echo "-- page cache: one visitor's language is never served to another\n";
    qp_page_cache_enable(true, ['trusted_plugins' => []]); qp_page_cache_purge_all();
    $plugins2 = json_decode($o_plugins, true) ?: []; $plugins2 = array_intersect_key($plugins2, ['qcommerce' => 1]); $plugins2['qp-multilang'] = true;
    $setOpt('active_plugins', json_encode($plugins2)); qp_page_cache_purge_all();
    $u = 'post/' . $slug;
    get($u); // the plugin registers its cookie and Accept-Language variation, and declares itself compatible, as it loads
    $a1 = get($u); $a2 = get($u);
    ok(strpos($a2['cache'], 'HIT') === 0 && strpos((string)meta_content($a2['body'], 'property', 'og:title'), 'Welcome') === 0, 'no cookie, no header: the default-language page is cached (' . $a1['cache'] . ' then ' . $a2['cache'] . ')');
    $f1 = get($u, ['cookie' => 'qp_lang=fr']); $f2 = get($u, ['cookie' => 'qp_lang=fr']);
    ok(strpos((string)meta_content($f2['body'], 'property', 'og:title'), 'Bienvenue') === 0 && strpos($f2['cache'], 'HIT') === 0, 'cookie qp_lang=fr: the French page, kept apart (' . $f1['cache'] . ' then ' . $f2['cache'] . ')');
    $a3 = get($u); ok(strpos((string)meta_content($a3['body'], 'property', 'og:title'), 'Welcome') === 0, 'and the cookie-less visitor still gets English afterwards');
    $e1 = get($u, ['cookie' => 'qp_lang=en', 'h' => ['Accept-Language: fr']]);
    ok(strpos((string)meta_content($e1['body'], 'property', 'og:title'), 'Welcome') === 0, 'cookie en with a French browser: the cookie wins, English');
    $n1 = get($u, ['h' => ['Accept-Language: fr-FR,fr;q=0.9']]);
    ok(strpos((string)meta_content($n1['body'], 'property', 'og:title'), 'Bienvenue') === 0 && strpos($n1['cache'], 'HIT') !== 0, 'no cookie but a French browser: NOT served the cached English page; the plugin negotiates French (' . $n1['cache'] . ')');
    ok($n1['setcookie'] && strpos($n1['cache'], 'BYPASS') === 0, 'that response sets the language cookie, so it is never stored: ' . $n1['cache']);
    $a4 = get($u); ok(strpos((string)meta_content($a4['body'], 'property', 'og:title'), 'Welcome') === 0, 'the French browser did not poison the cookie-less copy');
    $q1 = get($u . '?lang=fr'); $q2 = get($u . '?lang=fr', ['cookie' => 'qp_lang=fr']); $q3 = get($u . '?lang=fr', ['cookie' => 'qp_lang=fr']);
    ok(strpos((string)meta_content($q3['body'], 'property', 'og:title'), 'Bienvenue') === 0 && strpos($q3['cache'], 'HIT') === 0, '?lang=fr with the cookie already set is cached (' . $q1['cache'] . ' / ' . $q2['cache'] . ' / ' . $q3['cache'] . ')');
    $st = qp_page_cache_state();
    ok(in_array('qp_lang', $st['vary_cookies'], true) && in_array('fr', $st['vary_al'], true) && in_array('qp-multilang', $st['declared'], true), 'the plugin registered its cookie, its languages and its compatibility with the cache');
} finally {
    qp_page_cache_enable(false);
    $setOpt('active_plugins', $o_plugins);
    if ($o_ml === false) $pdo->exec("DELETE FROM $SO WHERE option_name = 'qp_multilang_options'"); else $setOpt('qp_multilang_options', $o_ml);
    $pdo->prepare("DELETE FROM $PM WHERE post_id = ? AND meta_key IN ('i18n_title','i18n_content')")->execute([$pid]);
    foreach ($o_meta as $k => $v) { if ($v !== false) $pdo->prepare("INSERT INTO $PM (post_id, meta_key, meta_value) VALUES (?, ?, ?)")->execute([$pid, $k, $v]); }
    update_option_meta('qp_seo_settings', $o_seo ?: qp_seo_defaults());
    update_option_meta('qp_seo_enabled', $o_seo_on ?: false);
    qp_seo_sitemap_purge();
    echo "-- restored: plugins " . $pdo->query("SELECT option_value FROM $SO WHERE option_name = 'active_plugins'")->fetchColumn() . ", multilang options " . var_export($pdo->query("SELECT option_value FROM $SO WHERE option_name = 'qp_multilang_options'")->fetchColumn(), true) . ", seo " . var_export((bool)get_option_meta('qp_seo_enabled'), true) . "\n";
}
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
