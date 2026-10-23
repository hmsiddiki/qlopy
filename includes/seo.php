<?php
if (!defined('QLOPY_INIT')) { http_response_code(403); exit; }
// includes/seo.php
// SEO module, step 1 of C3: titles, descriptions, canonical URLs, robots, Open Graph and Twitter tags (core upgrade plan).
// Off until the owner enables it on Settings > SEO (option qp_seo_enabled). Loaded from index.php only while enabled, so a site that does
// not use it pays nothing, and never on admin or AJAX requests.
//
// Facts this code relies on:
//  - Every address it prints is built on SITE_URL, never on the host a request arrived on (permalink_base is a filter that plugins such
//    as Any Hostname use to leak the arriving host into links; canonicals, sitemaps and structured data must not).
//  - Per-item values live in post meta: _seo_title, _seo_description, _seo_robots ("noindex", "nofollow"), _seo_canonical, _seo_og_image
//    (an attachment id or a URL). They are read through ml_get_meta() when the multilingual layer is present.
//  - Tags the active theme already prints (canonical, description, og:, twitter:, robots) are detected by scanning header.php and
//    functions.php, and skipped, so enabling the module on a site that prints its own tags does not create duplicates
//    (Settings > SEO can turn this off).
//
// Printed on the qp_head_tags action (fired from print_styles(), inside <head> for the bundled themes; the older qp_head action fires
// after header.php, in the body, so it cannot be used for head tags).
// Public API: qp_seo_title(), qp_seo_meta(), qp_seo_site_url($path), qp_seo_rebase($url), qp_seo_text($html, $max),
// filters qp_seo_meta, qp_seo_settings, qp_seo_title_parts, and the action qp_seo_head (after the tags, for plugins that add their own).

function qp_seo_enabled(): bool {
    return function_exists('get_option_meta') && filter_var(get_option_meta('qp_seo_enabled'), FILTER_VALIDATE_BOOLEAN);
}

function qp_seo_defaults(): array {
    return [
        'title_home'     => '%site% - %tagline%',
        'title_singular' => '%title% | %site%',
        'title_term'     => '%term% | %site%',
        'title_archive'  => '%title% | %site%',
        'title_search'   => 'Search: %query% | %site%',
        'title_404'      => 'Page not found | %site%',
        'title_paged'    => ' (page %page%)',
        'description'    => '',          // site-wide fallback
        'og_image'       => '',          // site-wide fallback: URL or attachment id
        'twitter_card'   => 'summary_large_image',
        'twitter_site'   => '',          // @handle
        'noindex_search' => true,
        'noindex_404'    => true,
        'noindex_terms_paged' => false,
        'respect_theme'  => true,        // skip tags the active theme prints itself
        'schema'         => true,        // structured data (JSON-LD), see seo-schema.php
        'org_type'       => 'Organization',  // or Person
        'org_name'       => '',          // empty: the site name
        'org_logo'       => '',          // URL or attachment id
        'org_same_as'    => '',          // profile addresses, one per line
        'language'       => 'en-US',
        'breadcrumb_home' => 'Home',
        'sitemap'        => true,
        'sitemap_post_types' => ['post', 'page'],
        'sitemap_images' => true,
        'sitemap_terms'  => true,
        'robots_block_all' => false,   // "discourage search engines": Disallow: /
        'robots_extra'   => '',        // lines added to robots.txt
    ];
}

function qp_seo_settings(): array {
    static $s = null;
    if ($s !== null) return $s;
    $stored = function_exists('get_option_meta') ? get_option_meta('qp_seo_settings') : null;
    $s = array_merge(qp_seo_defaults(), is_array($stored) ? $stored : []);
    if (function_exists('apply_filters')) { $f = apply_filters('qp_seo_settings', $s); if (is_array($f)) $s = $f; }
    return $s;
}

/** The configured site address, with an optional path. Never the arriving host. */
function qp_seo_site_url(string $path = ''): string {
    $base = rtrim((string)(defined('SITE_URL') ? SITE_URL : ''), '/');
    return $path === '' ? $base : $base . '/' . ltrim($path, '/');
}

/** Turn a URL built on any base (for example one a permalink_base filter changed) into the same address on SITE_URL. */
function qp_seo_rebase(string $url): string {
    $p = parse_url($url);
    if (!$p) return $url;
    $path = (string)($p['path'] ?? '/');
    $sitePath = rtrim((string)(parse_url(qp_seo_site_url(), PHP_URL_PATH) ?? ''), '/');
    if ($sitePath !== '' && stripos($path, $sitePath) === 0) $path = substr($path, strlen($sitePath));
    return qp_seo_site_url() . ($path === '' ? '/' : $path) . (isset($p['query']) ? '?' . $p['query'] : '');
}

/** Plain text of some HTML, shortened at a word boundary. */
function qp_seo_text($html, int $max = 155): string {
    $t = (string)$html;
    if (function_exists('qp_do_shortcodes')) { /* shortcodes may print markup; strip them instead of running them */ }
    $t = preg_replace('/\[[^\]]+\]/', ' ', $t);
    $t = html_entity_decode(strip_tags($t), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $t = trim(preg_replace('/\s+/u', ' ', $t));
    if (mb_strlen($t) <= $max) return $t;
    $cut = mb_substr($t, 0, $max);
    $sp = mb_strrpos($cut, ' ');
    if ($sp !== false && $sp > $max * 0.6) $cut = mb_substr($cut, 0, $sp);
    return rtrim($cut, " ,;:.-") . '…';
}

/**
 * All the per-item values the module reads for one post, in ONE query (the fields of the SEO box and the featured image id), cached for
 * the request. Each value is decoded and passed through the ml_get_meta filter exactly as get_post_meta() does, so the multilingual
 * layer still gets to answer per language.
 */
function qp_seo_item_meta_all(int $id): array {
    static $cache = [];
    if ($id <= 0) return [];
    if (isset($cache[$id])) return $cache[$id];
    $keys = ['_seo_title', '_seo_description', '_seo_robots', '_seo_canonical', '_seo_og_image', '_thumbnail_id'];
    $out = array_fill_keys($keys, null);
    try {
        $st = db()->prepare('SELECT meta_key, meta_value FROM ' . table_name('post_meta') . ' WHERE post_id = ? AND meta_key IN (' . implode(',', array_fill(0, count($keys), '?')) . ')');
        $st->execute(array_merge([$id], $keys));
        $lang = null;
        if (!qp_is_admin_script()) { if (function_exists('qp_current_language')) $lang = qp_current_language(); }
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $d = json_decode($r['meta_value'], true);
            $v = is_null($d) ? $r['meta_value'] : $d;
            if (function_exists('apply_filters')) $v = qp_i18n_filter('ml_get_meta', 'qp_translate_meta', $v, $id, $r['meta_key'], $lang);
            $out[$r['meta_key']] = $v;
        }
    } catch (Throwable $_) {}
    return $cache[$id] = $out;
}

function qp_seo_item_meta(int $id, string $key): string {
    if ($id <= 0) return '';
    $all = qp_seo_item_meta_all($id);
    $v = array_key_exists($key, $all) ? $all[$key] : get_post_meta($id, $key);
    return is_string($v) ? trim($v) : '';
}

/** What the active theme already prints (cached in an option per theme and file times). */
function qp_seo_theme_tags(): array {
    static $t = null;
    if ($t !== null) return $t;
    $theme = defined('THEME_URL') ? basename(rtrim(THEME_URL, '/')) : 'default';
    $dir = qp_themes_path($theme);
    $files = [$dir . 'header.php', $dir . 'functions.php'];
    $sig = $theme . '|' . implode('|', array_map(function ($f) { return is_file($f) ? filemtime($f) : 0; }, $files));
    $cached = get_option_meta('qp_seo_theme_scan');
    if (is_array($cached) && ($cached['sig'] ?? '') === $sig) return $t = $cached['tags'];
    $src = '';
    foreach ($files as $f) if (is_file($f)) $src .= (string)file_get_contents($f);
    $t = [
        'canonical'   => (bool)preg_match('/rel=["\']canonical["\']/i', $src),
        'description' => (bool)preg_match('/name=["\']description["\']/i', $src),
        'robots'      => (bool)preg_match('/name=["\']robots["\']/i', $src),
        'og'          => (bool)preg_match('/property=["\']og:/i', $src),
        'twitter'     => (bool)preg_match('/name=["\']twitter:/i', $src),
        'jsonld'      => (bool)preg_match('/ld\+json/i', $src),
    ];
    if (function_exists('update_option_meta')) update_option_meta('qp_seo_theme_scan', ['sig' => $sig, 'tags' => $t]);
    return $t;
}

/** The current request as the SEO code sees it. */
function qp_seo_context(): array {
    static $c = null;
    if ($c !== null) return $c;
    $route = $_GET['route'] ?? 'frontpage';
    // A route the core does not know is a plugin's own page (qCommerce's shop, cart and so on): "other" while it answers, a 404 when it does not.
    $known = ['frontpage', 'singular', 'archive', 'search', '404'];
    $c = ['type' => in_array($route, $known, true) ? '404' : (http_response_code() === 404 ? '404' : 'other'), 'raw_route' => $route, 'post' => null, 'term' => null, 'taxonomy' => null, 'post_type' => null, 'paged' => max(1, (int)($_GET['paged'] ?? 1)), 'query' => ''];
    switch ($route) {
        case 'frontpage':
            $c['type'] = 'home';
            if (!empty($GLOBALS['post']) && ($GLOBALS['front_page_option'] ?? '') === 'static') $c['post'] = $GLOBALS['post'];
            break;
        case 'singular':
            $post = $GLOBALS['post'] ?? null;
            if (!is_array($post) && !empty($_GET['slug'])) {
                $pt = (string)($_GET['post_type'] ?? 'post');
                $st = db()->prepare('SELECT * FROM ' . table_name('posts') . ' WHERE slug = ? AND post_type = ? LIMIT 1'); $st->execute([$_GET['slug'], $pt]);
                $post = $st->fetch(PDO::FETCH_ASSOC) ?: null;
            }
            if (is_array($post)) { $c['type'] = 'singular'; $c['post'] = $post; $c['post_type'] = (string)($post['post_type'] ?? ''); }
            break;
        case 'archive':
            if (!empty($_GET['taxonomy']) && !empty($_GET['term'])) {
                $c['type'] = 'term'; $c['taxonomy'] = (string)$_GET['taxonomy'];
                $c['term'] = function_exists('get_term_by_slug') ? get_term_by_slug($c['taxonomy'], (string)$_GET['term']) : null;
                if (!$c['term']) $c['term'] = ['name' => ucfirst((string)$_GET['term']), 'slug' => (string)$_GET['term'], 'description' => ''];
            } else {
                $c['type'] = 'archive'; $c['post_type'] = (string)($_GET['post_type'] ?? 'post');
            }
            break;
        case 'search':
            $c['type'] = 'search'; $c['query'] = (string)($_GET['q'] ?? '');
            break;
    }
    return $c;
}

/** The path of this request relative to the site folder, with a leading slash and no query string. */
function qp_seo_request_path(): string {
    $p = (string)(parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?? '/');
    $base = rtrim((string)(parse_url(qp_seo_site_url(), PHP_URL_PATH) ?? ''), '/');
    if ($base !== '' && stripos($p, $base) === 0) $p = substr($p, strlen($base));
    return '/' . ltrim($p, '/');
}

/** A post's title in the language being shown (filters qp_translate_title / ml_the_title); the stored title on a single-language site. */
function qp_seo_post_title(array $post): string {
    $t = (string)($post['title'] ?? '');
    if ($t === '' || !function_exists('qp_i18n_filter')) return $t;
    $lang = function_exists('qp_current_language') ? qp_current_language() : null;
    $r = qp_i18n_filter('ml_the_title', 'qp_translate_title', $t, isset($post['id']) ? (int)$post['id'] : null, $lang);
    return is_string($r) ? $r : $t;
}

/** A post's text in the language being shown (filters qp_translate_content / ml_the_content). */
function qp_seo_post_content(array $post): string {
    $c = (string)($post['content'] ?? '');
    if ($c === '' || !function_exists('qp_i18n_filter')) return $c;
    $lang = function_exists('qp_current_language') ? qp_current_language() : null;
    $r = qp_i18n_filter('ml_the_content', 'qp_translate_content', $c, isset($post['id']) ? (int)$post['id'] : null, $lang);
    return is_string($r) ? $r : $c;
}

/** Name of a taxonomy term row (the terms table calls the column `term`). */
function qp_seo_term_name($term): string {
    return is_array($term) ? (string)($term['term'] ?? $term['name'] ?? '') : '';
}

function qp_seo_expand(string $tpl, array $vars): string {
    return trim(preg_replace('/\s+/', ' ', strtr($tpl, $vars)));
}

function qp_seo_site_vars(): array {
    $site = function_exists('qp_option_raw') ? (string)(qp_option_raw('site_name') ?: '') : '';
    $tag = function_exists('qp_option_raw') ? (string)(qp_option_raw('site_tagline') ?: '') : '';
    return ['%site%' => trim($site, '"'), '%tagline%' => trim($tag, '"'), '%sep%' => '|'];
}

/** The document title for this request, from the templates and the per-item override. Themes call this in their <title>. */
function qp_seo_title(): string {
    $s = qp_seo_settings(); $c = qp_seo_context(); $vars = qp_seo_site_vars();
    $post = $c['post']; $id = (int)($post['id'] ?? 0);
    $vars['%title%'] = $post ? qp_seo_post_title($post) : '';
    $vars['%term%'] = qp_seo_term_name($c['term']);
    $vars['%query%'] = $c['query'];
    if ($c['type'] === 'other') $vars['%title%'] = (string)($GLOBALS['page_title'] ?? '');
    $key = ['home' => 'title_home', 'singular' => 'title_singular', 'term' => 'title_term', 'archive' => 'title_archive', 'search' => 'title_search', 'other' => 'title_singular'][$c['type']] ?? 'title_404';
    $tpl = (string)$s[$key];
    if ($c['type'] === 'archive') { $vars['%title%'] = ucfirst((string)$c['post_type']); }
    if ($c['type'] === 'home' && $post) { $vars['%title%'] = qp_seo_post_title($post); }
    $override = $id ? qp_seo_item_meta($id, '_seo_title') : '';
    $title = qp_seo_expand($override !== '' ? $override : $tpl, $vars);
    $title = trim(preg_replace('/(\s*[|\-–]\s*)+$/u', '', $title));   // an empty tagline must not leave a dangling separator
    if ($c['paged'] > 1 && $s['title_paged'] !== '') $title .= ' ' . ltrim(str_replace('%page%', (string)$c['paged'], $s['title_paged']));
    if (function_exists('apply_filters')) { $f = apply_filters('qp_seo_title_parts', $title, $c); if (is_string($f)) $title = $f; }
    return $title;
}

/** All values for the current request. Filter: qp_seo_meta. */
function qp_seo_meta(): array {
    static $m = null;
    if ($m !== null) return $m;
    $s = qp_seo_settings(); $c = qp_seo_context(); $post = $c['post']; $id = (int)($post['id'] ?? 0);
    $vars = qp_seo_site_vars();
    $m = ['title' => qp_seo_title(), 'description' => '', 'canonical' => '', 'robots' => [], 'image' => '', 'og_type' => 'website', 'site_name' => $vars['%site%'],
          'published' => '', 'modified' => '', 'prev' => '', 'next' => '', 'locale' => ''];

    // description: item override, then the text of the item, then the term description, then the site default
    $desc = $id ? qp_seo_item_meta($id, '_seo_description') : '';
    if ($desc === '' && $post) $desc = qp_seo_text(qp_seo_post_content($post));
    if ($desc === '' && !empty($c['term']['description'])) $desc = qp_seo_text($c['term']['description']);
    if ($desc === '') $desc = (string)$s['description'];
    if ($desc === '') $desc = $vars['%tagline%'];
    $m['description'] = $desc;

    // canonical: always on SITE_URL
    $override = $id ? qp_seo_item_meta($id, '_seo_canonical') : '';
    if ($override !== '' && preg_match('#^https?://#i', $override)) $url = $override;
    elseif ($c['type'] === 'singular' && $post) $url = ($c['post_type'] === 'page' && function_exists('permalink_for_page')) ? qp_seo_rebase(permalink_for_page($post['slug'])) : (function_exists('permalink_for_post') ? qp_seo_rebase(permalink_for_post($post['slug'], $c['post_type'])) : qp_seo_site_url());
    elseif ($c['type'] === 'term' && function_exists('permalink_for_term')) $url = qp_seo_rebase(permalink_for_term($c['taxonomy'], (string)($c['term']['slug'] ?? $_GET['term'])));
    elseif ($c['type'] === 'archive' && function_exists('permalink_for_post')) $url = qp_seo_rebase(permalink_for_post('', $c['post_type']));
    elseif ($c['type'] === 'other') $url = qp_seo_site_url(qp_seo_request_path());
    elseif ($c['type'] === 'search' || $c['type'] === '404') $url = '';
    else $url = qp_seo_site_url('/');
    if ($url !== '' && $c['paged'] > 1 && in_array($c['type'], ['term', 'archive'], true)) {
        $url .= (strpos($url, '?') === false ? '?' : '&') . 'paged=' . $c['paged'];
    }
    $m['canonical'] = $url;
    // On a multilingual site the canonical is this page in the language being shown, as the provider addresses it (never another language's page).
    if ($override === '' && function_exists('qp_current_language') && qp_current_language() !== null) {
        $cur = qp_current_language(); $lu = null;
        if ($c['type'] === 'singular' && $id) $lu = qp_translation_url('post', $id, $cur);
        elseif ($c['type'] === 'term' && !empty($c['term']['id'])) $lu = qp_translation_url('term', (int)$c['term']['id'], $cur);
        elseif ($c['type'] === 'home' && !$post) { $h = qp_language_home_urls(); $lu = $h[$cur] ?? null; }
        if ($lu) { $url = qp_seo_rebase($lu); $m['canonical'] = $url; }
    }
    if ($url !== '' && in_array($c['type'], ['term', 'archive'], true)) {
        $total = qp_seo_total_pages();
        if ($total > $c['paged']) {
            $b0 = rtrim(preg_replace('/([?&])paged=\d+/', '', $url), '?&');
            $m['next'] = $b0 . (strpos($b0, '?') === false ? '?' : '&') . 'paged=' . ($c['paged'] + 1);
        }
    }
    if ($c['paged'] > 1 && $url !== '') {
        $base = preg_replace('/([?&])paged=\d+/', '', $url);
        $base = rtrim($base, '?&');
        $m['prev'] = $c['paged'] === 2 ? $base : $base . (strpos($base, '?') === false ? '?' : '&') . 'paged=' . ($c['paged'] - 1);
    }

    // robots
    $r = [];
    $item = $id ? strtolower(qp_seo_item_meta($id, '_seo_robots')) : '';
    if (strpos($item, 'noindex') !== false) $r[] = 'noindex';
    if (strpos($item, 'nofollow') !== false) $r[] = 'nofollow';
    if ($post && (($post['visibility'] ?? 'public') !== 'public' || ($post['status'] ?? 'published') !== 'published')) $r[] = 'noindex';
    if ($c['type'] === 'search' && $s['noindex_search']) $r[] = 'noindex';
    if ($c['type'] === '404' && $s['noindex_404']) $r[] = 'noindex';
    if ($c['paged'] > 1 && $c['type'] === 'term' && $s['noindex_terms_paged']) $r[] = 'noindex';
    if (!empty($s['robots_block_all'])) { $r[] = 'noindex'; $r[] = 'nofollow'; }   // "discourage search engines": the whole site
    $m['robots'] = array_values(array_unique($r));

    // social
    if ($c['type'] === 'singular') $m['og_type'] = ($c['post_type'] === 'post') ? 'article' : 'website';
    if ($post && $c['type'] === 'singular') {
        // published_at can be empty on posts saved by older code; the creation time is then the best answer
        $pub = !empty($post['published_at']) ? $post['published_at'] : ($post['created_at'] ?? '');
        $m['published'] = $pub !== '' ? gmdate('c', strtotime($pub . ' UTC')) : '';
        $m['modified'] = !empty($post['updated_at']) ? gmdate('c', strtotime($post['updated_at'] . ' UTC')) : '';
    }
    $m['image'] = qp_seo_image($post, $id, $s);
    $loc = 'en_US';
    if (function_exists('qp_current_language') && qp_current_language() !== null) { $info = qp_language_info(qp_current_language()); if ($info) $loc = $info['locale']; }
    if (function_exists('apply_filters')) { $loc = apply_filters('qp_seo_locale', $loc, $c); }
    if (is_string($loc) && $loc !== '') $m['locale'] = $loc;
    if (function_exists('apply_filters')) { $f = apply_filters('qp_seo_meta', $m, $c); if (is_array($f)) $m = $f; }
    return $m;
}

/** Image URL for social cards: item override, featured image, first image in the content, site default. */
function qp_seo_image($post, int $id, array $s): string {
    $resolve = function ($v) {
        $v = trim((string)$v);
        if ($v === '') return '';
        if (ctype_digit($v) && function_exists('qp_get_attachment_image_src')) { $src = qp_get_attachment_image_src((int)$v, 'full'); return is_array($src) ? qp_seo_abs((string)$src[0]) : ''; }
        return qp_seo_abs($v);
    };
    if ($id) {
        $o = $resolve(qp_seo_item_meta($id, '_seo_og_image')); if ($o !== '') return $o;
        $tid = qp_seo_item_meta_all($id)['_thumbnail_id'] ?? null;
        if (!$tid && function_exists('get_post_thumbnail_id')) $tid = get_post_thumbnail_id($id);   // older posts keep the image under the legacy key
        if ($tid && ctype_digit((string)$tid)) { $o = $resolve((string)$tid); if ($o !== '') return $o; }
        if (!empty($post['content']) && preg_match('/<img[^>]+src=["\']([^"\']+)/i', $post['content'], $mm)) return qp_seo_abs($mm[1]);
    }
    return $resolve($s['og_image']);
}

function qp_seo_abs(string $u): string {
    if ($u === '') return '';
    if (preg_match('#^https?://#i', $u)) return $u;
    return $u[0] === '/' ? qp_seo_site_url() . $u : qp_seo_site_url('/' . $u);
}

/** Print the tags on qp_head. */
function qp_seo_render_head(): void {
    if ((PHP_SAPI === 'cli' && !defined('QP_SEO_FORCE_RENDER')) || (defined('IN_ADMIN') && IN_ADMIN)) return;
    $s = qp_seo_settings(); $m = qp_seo_meta(); $theme = $s['respect_theme'] ? qp_seo_theme_tags() : [];
    $e = function ($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); };
    $out = "<!-- qp-seo -->\n";
    if (empty($theme['description']) && $m['description'] !== '') $out .= '<meta name="description" content="' . $e($m['description']) . "\">\n";
    if (empty($theme['robots'])) {
        $out .= '<meta name="robots" content="' . ($m['robots'] ? $e(implode(',', $m['robots'])) : 'index,follow') . "\">\n";
    }
    if (empty($theme['canonical']) && $m['canonical'] !== '') $out .= '<link rel="canonical" href="' . $e($m['canonical']) . "\">\n";
    if ($m['prev'] !== '') $out .= '<link rel="prev" href="' . $e($m['prev']) . "\">\n";
    if ($m['next'] !== '') $out .= '<link rel="next" href="' . $e($m['next']) . "\">\n";
    if (empty($theme['og'])) {
        $out .= '<meta property="og:locale" content="' . $e($m['locale'] ?: 'en_US') . "\">\n";
        $out .= '<meta property="og:type" content="' . $e($m['og_type']) . "\">\n";
        $out .= '<meta property="og:title" content="' . $e($m['title']) . "\">\n";
        if ($m['description'] !== '') $out .= '<meta property="og:description" content="' . $e($m['description']) . "\">\n";
        if ($m['canonical'] !== '') $out .= '<meta property="og:url" content="' . $e($m['canonical']) . "\">\n";
        if ($m['site_name'] !== '') $out .= '<meta property="og:site_name" content="' . $e($m['site_name']) . "\">\n";
        if ($m['image'] !== '') $out .= '<meta property="og:image" content="' . $e($m['image']) . "\">\n";
        if ($m['og_type'] === 'article') {
            if ($m['published'] !== '') $out .= '<meta property="article:published_time" content="' . $e($m['published']) . "\">\n";
            if ($m['modified'] !== '') $out .= '<meta property="article:modified_time" content="' . $e($m['modified']) . "\">\n";
        }
    }
    if (empty($theme['twitter'])) {
        $card = $m['image'] !== '' ? $s['twitter_card'] : 'summary';
        $out .= '<meta name="twitter:card" content="' . $e($card) . "\">\n";
        if ($s['twitter_site'] !== '') $out .= '<meta name="twitter:site" content="' . $e($s['twitter_site']) . "\">\n";
        $out .= '<meta name="twitter:title" content="' . $e($m['title']) . "\">\n";
        if ($m['description'] !== '') $out .= '<meta name="twitter:description" content="' . $e($m['description']) . "\">\n";
        if ($m['image'] !== '') $out .= '<meta name="twitter:image" content="' . $e($m['image']) . "\">\n";
    }
    echo $out;
    if ($m['robots'] && !headers_sent()) header('X-Robots-Tag: ' . implode(', ', $m['robots']));
    if (function_exists('do_action')) do_action('qp_seo_head', $m);
}

if (function_exists('add_action')) add_action('qp_head_tags', 'qp_seo_render_head', 5, 0);

// Basic tier (always on): head tags above, sitemaps and robots.txt, paging links. Advanced tier (Settings > SEO > "Advanced SEO"):
// structured data, redirect manager and 404 log, hreflang, editor box.
require_once __DIR__ . '/seo-sitemap.php';  // sitemaps and robots.txt
require_once __DIR__ . '/seo-extras.php';    // rel=next helpers always; hreflang and the editor box only in the advanced tier
if (qp_seo_enabled()) {
    require_once __DIR__ . '/seo-schema.php';   // structured data and breadcrumbs
    require_once __DIR__ . '/seo-redirects.php'; // redirect manager and 404 log
    if (PHP_SAPI !== 'cli' && !(defined('IN_ADMIN') && IN_ADMIN)) register_shutdown_function('qp_seo_404_log');
}
