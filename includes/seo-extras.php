<?php
if (!defined('QLOPY_INIT')) { http_response_code(403); exit; }
// includes/seo-extras.php
// SEO module, step 5 of C3: the editor box, paging links (rel=next) and language alternates (hreflang). Loaded by includes/seo.php.

/** The "SEO" box in the post editor (Settings > SEO must be on). Field ids are the post meta keys read in seo.php. */
function qp_seo_register_metabox(): void {
    if (!function_exists('qpmeta_register_metabox')) return;
    $types = function_exists('get_post_types') ? array_values(array_diff(array_keys(get_post_types()), ['attachment'])) : ['post', 'page'];
    qpmeta_register_metabox('qp_seo_box', [
        'title' => 'SEO',
        'object_types' => ['post'],
        'post_types' => array_values(array_unique(array_merge(['post', 'page'], $types))),
        'context' => 'normal',
        'fields' => [
            ['id' => '_seo_title', 'name' => 'Title for search engines', 'type' => 'text', 'desc' => 'Replaces the title template for this page. Placeholders: %title%, %site%, %tagline%. Leave empty to use the template.'],
            ['id' => '_seo_description', 'name' => 'Description', 'type' => 'textarea', 'desc' => 'What search results and social cards show under the title. About 155 characters is the most that is usually displayed. Empty: taken from the text of the page.'],
            ['id' => '_seo_robots', 'name' => 'Search engines', 'type' => 'select', 'options' => ['' => 'Default (index and follow links)', 'noindex' => 'Do not show this page in search results', 'nofollow' => 'Do not follow links on this page', 'noindex,nofollow' => 'Neither show it nor follow its links'], 'desc' => 'A page marked here is also left out of the sitemap.'],
            ['id' => '_seo_canonical', 'name' => 'Canonical address', 'type' => 'text', 'desc' => 'Only when this page is a copy of another page: the full address of that page. Empty: this page\'s own address. A page that points elsewhere is left out of the sitemap.'],
            ['id' => '_seo_og_image', 'name' => 'Sharing image', 'type' => 'text', 'desc' => 'Address of the image for social cards, or a media id. Empty: the featured image, then the first image in the text, then the site default.'],
        ],
    ]);
}

/**
 * Number of pages of the current archive or term listing, or 0 when unknown. A template that ran its own query can set
 * $GLOBALS['qp_seo_max_pages']; otherwise the published posts are counted against the option posts_per_page (default 10, the
 * QP_Query default). Filter: qp_seo_total_pages ($n, $context).
 */
function qp_seo_total_pages(): int {
    $c = qp_seo_context();
    $n = 0;
    if (!empty($GLOBALS['qp_seo_max_pages'])) {
        $n = (int)$GLOBALS['qp_seo_max_pages'];
    } elseif (in_array($c['type'], ['archive', 'term'], true)) {
        $per = max(1, (int)(function_exists('get_option_meta') ? (get_option_meta('posts_per_page') ?: 10) : 10));
        try {
            $st = null;
            if ($c['type'] === 'archive') {
                $st = db()->prepare("SELECT COUNT(*) FROM " . table_name('posts') . " WHERE post_type = ? AND status = 'published' AND visibility = 'public'");
                $st->execute([$c['post_type']]);
            } elseif ((int)($c['term']['id'] ?? 0) > 0) {
                $st = db()->prepare("SELECT COUNT(*) FROM " . table_name('post_terms') . " pt JOIN " . table_name('posts') . " p ON p.id = pt.post_id WHERE pt.term_id = ? AND p.status = 'published' AND p.visibility = 'public'");
                $st->execute([(int)$c['term']['id']]);
            }
            if ($st) $n = (int)ceil(((int)$st->fetchColumn()) / $per);
        } catch (Throwable $_) { $n = 0; }
    }
    if (function_exists('apply_filters')) $n = (int)apply_filters('qp_seo_total_pages', $n, $c);
    return max(0, $n);
}

/**
 * Other-language versions of the current page: a list of ['lang' => 'fr', 'href' => 'https://...']. The core knows no languages:
 * the multilingual layer answers the filter qp_seo_alternates ($alts, $context, $meta); for sitemaps it answers
 * qp_seo_sitemap_alternates ($alts, $post_row, $loc). Addresses are rebased onto SITE_URL here, so a layer that built them on another
 * host cannot leak it. A row with 'lang' => 'x-default' is allowed. The locale for og:locale comes from the filter qp_seo_locale.
 */
/**
 * [lang => url] to alternate rows ['lang' => hreflang code, 'href' => url], plus x-default (the default language's address) when there is one.
 * Needs two or more languages with addresses; fewer gives no rows. The hreflang code is the language's own ('hreflang' field, else its code).
 */
function qp_seo_alternate_rows(array $urls): array {
    if (count($urls) < 2) return [];
    $rows = [];
    foreach ($urls as $lang => $u) {
        $info = function_exists('qp_language_info') ? qp_language_info((string)$lang) : null;
        $rows[] = ['lang' => $info ? $info['hreflang'] : (string)$lang, 'href' => (string)$u];
    }
    $def = function_exists('qp_default_language') ? qp_default_language() : null;
    if ($def !== null && isset($urls[$def])) $rows[] = ['lang' => 'x-default', 'href' => (string)$urls[$def]];
    return $rows;
}

/** The alternates the registered multilingual provider can give for this request (posts, terms and the front page). */
function qp_seo_provider_alternates(array $c): array {
    if (!function_exists('qp_i18n_enabled') || !qp_i18n_enabled() || count(qp_languages()) < 2) return [];
    if ($c['type'] === 'singular' && !empty($c['post']['id'])) return qp_seo_alternate_rows(qp_translation_urls('post', (int)$c['post']['id']));
    if ($c['type'] === 'term' && !empty($c['term']['id'])) return qp_seo_alternate_rows(qp_translation_urls('term', (int)$c['term']['id']));
    if ($c['type'] === 'home' && empty($c['post'])) return qp_seo_alternate_rows(qp_language_home_urls());
    return [];
}

function qp_seo_alternates(): array {
    static $a = null;
    if ($a !== null) return $a;
    $a = [];
    $default = qp_seo_provider_alternates(qp_seo_context());
    if (function_exists('apply_filters')) {
        $f = apply_filters('qp_seo_alternates', $default, qp_seo_context(), qp_seo_meta());
        foreach (is_array($f) ? $f : [] as $row) {
            if (!is_array($row) || empty($row['lang']) || empty($row['href'])) continue;
            if (!preg_match('/^(x-default|[a-z]{2,3}(-[A-Za-z0-9]{2,8})*)$/', (string)$row['lang'])) continue;
            $a[] = ['lang' => (string)$row['lang'], 'href' => qp_seo_rebase((string)$row['href'])];
        }
    }
    return $a;
}

function qp_seo_render_alternates(): void {
    if ((PHP_SAPI === 'cli' && !defined('QP_SEO_FORCE_RENDER')) || (defined('IN_ADMIN') && IN_ADMIN)) return;
    $alts = qp_seo_alternates();
    if (!$alts) return;
    $e = function ($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); };
    $out = '';
    foreach ($alts as $r) $out .= '<link rel="alternate" hreflang="' . $e($r['lang']) . '" href="' . $e($r['href']) . "\">\n";
    echo $out;
}

if (function_exists('add_action') && qp_seo_enabled()) add_action('qp_head_tags', 'qp_seo_render_alternates', 5, 0);
