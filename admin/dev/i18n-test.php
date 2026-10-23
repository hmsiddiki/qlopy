<?php
// Dev tool: checks the multilingual contract (includes/i18n.php) and the SEO module's use of it, in process and over real HTTP against
// the reference provider content/plugins/qp-i18n-example. CLI only.
//   php admin/dev/i18n-test.php [--base=https://host/path] [--resolve=127.0.0.1]
// Activates the example plugin and the SEO module for the HTTP part, then restores the plugin list and the SEO settings.
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
    curl_setopt_array($c, [CURLOPT_RESOLVE => ["$host:$port:$resolve"], CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60]);
    $b = (string)curl_exec($c); $code = curl_getinfo($c, CURLINFO_HTTP_CODE); curl_close($c);
    return ['code' => $code, 'body' => $b];
}
function alt_rows($html) { preg_match_all('/<link rel="alternate" hreflang="([^"]+)" href="([^"]+)">/', $html, $m, PREG_SET_ORDER); $o = []; foreach ($m as $r) $o[$r[1]] = html_entity_decode($r[2]); return $o; }
function meta_content($html, $attr, $name) { return preg_match('/<meta[^>]+' . $attr . '=["\']' . preg_quote($name, '/') . '["\'][^>]*content=["\']([^"\']*)/i', $html, $m) ? html_entity_decode($m[1]) : null; }
function link_href($html, $rel) { return preg_match('/<link[^>]+rel=["\']' . $rel . '["\'][^>]*href=["\']([^"\']*)/i', $html, $m) ? html_entity_decode($m[1]) : null; }
$site = rtrim($config['site_url'], '/');

echo "-- the contract, in process\n";
ok(!qp_i18n_enabled() && qp_current_language() === null && qp_languages() === [], 'no provider: single-language site, every reader answers empty');
qp_i18n_register('bad', ['languages' => function () { return ['en']; }]);   // no 'current'
ok(!$GLOBALS['qp_i18n_providers'], 'a provider without the required callables is ignored');
$cur = 'fr';
qp_i18n_register('a', [
    'languages' => function () { return [['code' => 'en', 'default' => true, 'locale' => 'en_GB'], 'fr', ['code' => 'bad code!'], ['code' => 'pt-BR', 'hreflang' => 'pt-br', 'rtl' => false]]; },
    'current' => function () use (&$cur) { return $cur; },
    'url' => function ($t, $id, $l) { return $l === 'xx' ? null : "https://other.example/$t/$id/$l"; },
]);
$L = qp_languages();
ok(array_keys($L) === ['en', 'fr', 'pt-BR'] && $L['en']['locale'] === 'en_GB' && $L['fr']['locale'] === 'fr' && $L['pt-BR']['hreflang'] === 'pt-br' && $L['en']['default'] === true, 'rows, bare codes and a bad code are normalised: ' . implode(',', array_keys($L)));
ok(qp_current_language() === 'fr' && qp_default_language() === 'en' && qp_i18n_enabled(), 'current and default language');
$GLOBALS['qp_i18n_providers'] = []; qp_i18n_register('names', ['languages' => function () { return ['en' => 'English', 'de' => 'German']; }, 'current' => function () { return 'de'; }]);
ok(qp_languages()['de']['name'] === 'German' && qp_languages()['en']['locale'] === 'en', 'a map of code => name is accepted');
ok(qp_translation_urls('post', 5) === [] && qp_translations('post', 5) === ['en' => 5, 'de' => 5], 'no url callable: no addresses, every language assumed for translations');
$GLOBALS['qp_i18n_providers'] = [];
qp_i18n_register('first', ['languages' => function () { return ['en']; }, 'current' => function () { return 'en'; }]);
qp_i18n_register('second', ['languages' => function () { return ['en', 'es']; }, 'current' => function () { return 'es'; }]);
ok(qp_current_language() === 'en', 'two providers: the first registered is used');
update_option_meta('qp_i18n_provider', 'second'); ok(qp_current_language() === 'es', 'the option picks one');
update_option_meta('qp_i18n_provider', ''); add_filter('qp_i18n_provider', function ($p, $all) { return $all['second']; }, 10, 2); ok(qp_current_language() === 'es', 'the filter picks one');
remove_all_filters_safe('qp_i18n_provider');
$GLOBALS['qp_i18n_providers'] = [];
qp_i18n_register('boom', ['languages' => function () { throw new RuntimeException('x'); }, 'current' => function () { throw new RuntimeException('y'); }, 'url' => function () { throw new RuntimeException('z'); }]);
ok(qp_current_language() === null && qp_languages() === [] && qp_translation_url('post', 1, 'en') === null, 'a provider that throws gives "no answer", never an error');
$GLOBALS['qp_i18n_providers'] = [];
$calls = 0;
qp_i18n_register('b', ['languages' => function () { return ['en', 'fr']; }, 'current' => function () { return 'en'; }, 'url' => function ($t, $id, $l) use (&$calls) { $calls++; return "https://x.example/$id/$l"; }]);
$bu = qp_translation_urls_batch('post', [1, 2, 2]);
ok($bu === [1 => ['en' => 'https://x.example/1/en', 'fr' => 'https://x.example/1/fr'], 2 => ['en' => 'https://x.example/2/en', 'fr' => 'https://x.example/2/fr']] && $calls === 4, 'without urls_batch the core asks per item and language, once each (4 calls)');
$GLOBALS['qp_i18n_providers'] = [];
$seen = [];
add_filter('ml_the_title', function ($t) use (&$seen) { $seen[] = 'legacy'; return $t . '-1'; }, 10, 1);
add_filter('qp_translate_title', function ($t) use (&$seen) { $seen[] = 'neutral'; return $t . '-2'; }, 10, 1);
ok(qp_i18n_filter('ml_the_title', 'qp_translate_title', 'T') === 'T-1-2' && $seen === ['legacy', 'neutral'], 'a content filter runs under the older name, then the neutral name');

echo "-- backward compatibility with qp-multilang's older functions (separate process)\n";
$tmp = sys_get_temp_dir() . '/qp_i18n_legacy_test.php';
file_put_contents($tmp, "<?php\ndefine('QLOPY_INIT', true); chdir(" . var_export(getcwd(), true) . ");\nfunction ml_get_current_lang() { return 'fr'; }\nfunction ml_get_default_lang() { return 'en'; }\nfunction ml_get_languages() { return ['en' => 'English', 'fr' => 'French']; }\nrequire 'db.php'; require 'auth.php';\n"
    . "echo json_encode(['on' => qp_i18n_enabled(), 'cur' => qp_current_language(), 'def' => qp_default_language(), 'langs' => array_keys(qp_languages()), 'urls' => qp_translation_urls('post', 1)]);\n");
$leg = json_decode((string)shell_exec('php ' . escapeshellarg($tmp)), true); @unlink($tmp);
ok($leg === ['on' => true, 'cur' => 'fr', 'def' => 'en', 'langs' => ['en', 'fr'], 'urls' => []], 'no provider but ml_* functions: language list and current language still work, no addresses: ' . json_encode($leg));

echo "-- the SEO module with the reference provider (HTTP)\n";
$o_plugins = $pdo->query("SELECT option_value FROM " . table_name('site_options') . " WHERE option_name = 'active_plugins'")->fetchColumn();
$o_enabled = get_option_meta('qp_seo_enabled'); $o_settings = get_option_meta('qp_seo_settings');
$setPlugins = function ($json) use ($pdo) { $pdo->prepare("UPDATE " . table_name('site_options') . " SET option_value = ? WHERE option_name = 'active_plugins'")->execute([$json]); };
$slug = 'welcome-to-your-site';
try {
    $plugins = json_decode($o_plugins, true) ?: []; $plugins['qp-i18n-example'] = true; $setPlugins(json_encode($plugins));
    update_option_meta('qp_seo_enabled', true);
    update_option_meta('qp_seo_settings', array_merge(qp_seo_defaults(), []));
    qp_seo_sitemap_purge();

    $en = get('post/' . $slug); $alts = alt_rows($en['body']);
    ok($en['code'] === 200 && array_keys($alts) === ['en', 'fr', 'pt-BR', 'x-default'], 'post: hreflang for each language and x-default: ' . implode(',', array_keys($alts)));
    ok(($alts['en'] ?? '') === $site . '/post/' . $slug && ($alts['fr'] ?? '') === $site . '/post/' . $slug . '?lang=fr' && ($alts['x-default'] ?? '') === ($alts['en'] ?? 'x'), 'addresses are on the site address, English without a parameter, x-default is English');
    ok(link_href($en['body'], 'canonical') === $site . '/post/' . $slug && meta_content($en['body'], 'property', 'og:locale') === 'en_US', 'English page: canonical without a parameter, og:locale en_US');
    $fr = get('post/' . $slug . '?lang=fr');
    ok(link_href($fr['body'], 'canonical') === $site . '/post/' . $slug . '?lang=fr', 'French page: the canonical is the French address: ' . link_href($fr['body'], 'canonical'));
    ok(meta_content($fr['body'], 'property', 'og:locale') === 'fr_FR', 'French page: og:locale fr_FR');
    ok(alt_rows($fr['body']) === $alts, 'the French page lists the same alternates');
    ok(strpos((string)meta_content($fr['body'], 'property', 'og:title'), '[FR]') !== false || strpos($fr['body'], '[FR]') !== false, 'the neutral title filter reaches the page: the example marks French titles [FR]');
    $home = get(''); $ha = alt_rows($home['body']);
    ok(array_keys($ha) === ['en', 'fr', 'pt-BR', 'x-default'] && $ha['fr'] === $site . '/?lang=fr', 'front page: hreflang from home_url: ' . ($ha['fr'] ?? '-'));
    $sm = get('sitemap-post-1.xml')['body'];
    ok(substr_count($sm, '<url>') === 3 * (int)$pdo->query("SELECT COUNT(*) FROM " . table_name('posts') . " WHERE post_type = 'post' AND status = 'published' AND visibility = 'public'")->fetchColumn(), 'sitemap: each post is listed once per language (3 languages)');
    ok(strpos($sm, '<loc>' . $site . '/post/' . $slug . '?lang=fr</loc>') !== false && substr_count($sm, 'hreflang="pt-BR"') >= 3 && strpos($sm, 'hreflang="x-default"') !== false, 'sitemap: the French address is its own entry; every entry lists all alternates and x-default');
    update_option_meta('qp_seo_settings', array_merge(qp_seo_defaults(), []));
} finally {
    $setPlugins($o_plugins);
    update_option_meta('qp_seo_settings', $o_settings ?: qp_seo_defaults());
    update_option_meta('qp_seo_enabled', $o_enabled ?: false);
    qp_seo_sitemap_purge();
    echo "-- restored: plugins " . $pdo->query("SELECT option_value FROM " . table_name('site_options') . " WHERE option_name = 'active_plugins'")->fetchColumn() . ", module " . var_export((bool)get_option_meta('qp_seo_enabled'), true) . "\n";
}
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);

function remove_all_filters_safe($name) { if (isset($GLOBALS['qlopy_filters'][$name])) unset($GLOBALS['qlopy_filters'][$name]); }
