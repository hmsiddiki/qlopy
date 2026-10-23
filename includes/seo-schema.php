<?php
if (!defined('QLOPY_INIT')) { http_response_code(403); exit; }
// includes/seo-schema.php
// SEO module, step 2 of C3: structured data (a JSON-LD graph) and breadcrumbs. Loaded by includes/seo.php.
//
// The graph for a page holds linked nodes (each with an @id), built once per request:
//   WebSite (with a search action), Organization (or Person), WebPage (CollectionPage for archives, SearchResultsPage for search),
//   Article (posts), BreadcrumbList. Every address is on SITE_URL (see seo.php).
//
// Extending it (qCommerce adds Product, Offer, AggregateRating this way):
//   add_filter('qp_seo_graph', function (array $nodes, array $ctx, array $meta) { $nodes['product'] = [...]; return $nodes; }, 10, 3);
// $nodes is keyed by a short name (website, organization, webpage, article, breadcrumb, or your own); return it changed: add a node,
// replace one, or unset one. Use qp_seo_id('webpage') etc. to point at the core nodes, e.g. 'isPartOf' => ['@id' => qp_seo_id('website')].
// Breadcrumb trail: filter qp_seo_breadcrumbs (array of ['name' =>, 'url' =>]); markup: qp_seo_breadcrumbs_html().

function qp_seo_id(string $node): string {
    $m = qp_seo_meta();
    switch ($node) {
        case 'website': return qp_seo_site_url('/') . '#website';
        case 'organization': return qp_seo_site_url('/') . '#organization';
        case 'webpage': return ($m['canonical'] !== '' ? $m['canonical'] : qp_seo_site_url('/')) . '#webpage';
        case 'article': return ($m['canonical'] !== '' ? $m['canonical'] : qp_seo_site_url('/')) . '#article';
        case 'breadcrumb': return ($m['canonical'] !== '' ? $m['canonical'] : qp_seo_site_url('/')) . '#breadcrumb';
    }
    return qp_seo_site_url('/') . '#' . $node;
}

/** The first term of a taxonomy attached to a post (for the breadcrumb), or null. */
function qp_seo_primary_term(int $post_id, string $taxonomy = 'category'): ?array {
    static $cache = [];   // the breadcrumb and the Article both ask: one query per request
    if ($post_id <= 0) return null;
    $ck = $post_id . '|' . $taxonomy;
    if (array_key_exists($ck, $cache)) return $cache[$ck];
    return $cache[$ck] = qp_seo_primary_term_query($post_id, $taxonomy);
}

function qp_seo_primary_term_query(int $post_id, string $taxonomy): ?array {
    try {
        $st = db()->prepare('SELECT t.* FROM ' . table_name('post_terms') . ' pt JOIN ' . table_name('taxonomy_terms') . ' t ON t.id = pt.term_id WHERE pt.post_id = ? AND t.taxonomy = ? ORDER BY t.term_order ASC, t.id ASC LIMIT 1');
        $st->execute([$post_id, $taxonomy]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $_) { return null; }
}

/** Trail for the current page: list of ['name' =>, 'url' =>]. The last item is the page itself (its url may be empty). */
function qp_seo_breadcrumbs(): array {
    static $t = null;
    if ($t !== null) return $t;
    $s = qp_seo_settings(); $c = qp_seo_context(); $m = qp_seo_meta();
    $home = ['name' => (string)($s['breadcrumb_home'] ?? 'Home'), 'url' => qp_seo_site_url('/')];
    $t = [$home];
    $post = $c['post'];
    if ($c['type'] === 'singular' && $post) {
        $pt = $c['post_type'];
        if ($pt !== 'page') {
            $types = function_exists('get_post_types') ? get_post_types() : [];
            $label = (string)($types[$pt]['label'] ?? $types[$pt]['name'] ?? ucfirst($pt));
            if (function_exists('permalink_for_post')) $t[] = ['name' => $label, 'url' => qp_seo_rebase(permalink_for_post('', $pt))];
            $term = qp_seo_primary_term((int)$post['id']);
            if ($term && function_exists('permalink_for_term')) $t[] = ['name' => qp_seo_term_name($term), 'url' => qp_seo_rebase(permalink_for_term($term['taxonomy'], $term['slug']))];
        }
        $t[] = ['name' => qp_seo_post_title($post), 'url' => $m['canonical']];
    } elseif ($c['type'] === 'term') {
        $t[] = ['name' => qp_seo_term_name($c['term']), 'url' => $m['canonical']];
    } elseif ($c['type'] === 'archive') {
        $t[] = ['name' => ucfirst((string)$c['post_type']), 'url' => $m['canonical']];
    } elseif ($c['type'] === 'other') {
        $t[] = ['name' => (string)($GLOBALS['page_title'] ?? qp_seo_title()), 'url' => $m['canonical']];
    } elseif ($c['type'] === 'search') {
        $t[] = ['name' => 'Search: ' . $c['query'], 'url' => ''];
    } elseif ($c['type'] === '404') {
        $t[] = ['name' => 'Page not found', 'url' => ''];
    }
    if (function_exists('apply_filters')) { $f = apply_filters('qp_seo_breadcrumbs', $t, $c); if (is_array($f)) $t = $f; }
    return $t;
}

/** Visible breadcrumb markup for themes: <nav aria-label="Breadcrumb"><ol>...</ol></nav>. The last item is not a link. */
function qp_seo_breadcrumbs_html(array $args = []): string {
    $trail = qp_seo_breadcrumbs();
    if (count($trail) < 2) return '';
    $e = function ($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); };
    $cls = $e($args['class'] ?? 'qp-breadcrumbs'); $sep = (string)($args['separator'] ?? '');
    $out = '<nav class="' . $cls . '" aria-label="Breadcrumb"><ol>';
    $last = count($trail) - 1;
    foreach ($trail as $i => $item) {
        $out .= '<li' . ($i === $last ? ' aria-current="page"' : '') . '>';
        $out .= ($i !== $last && $item['url'] !== '') ? '<a href="' . $e($item['url']) . '">' . $e($item['name']) . '</a>' : '<span>' . $e($item['name']) . '</span>';
        $out .= ($sep !== '' && $i !== $last) ? '<span class="qp-sep">' . $e($sep) . '</span>' : '';
        $out .= '</li>';
    }
    return $out . '</ol></nav>';
}

function qp_seo_search_target(): string {
    $pat = function_exists('permalink_get_patterns') ? (permalink_get_patterns()['search'] ?? '') : '';
    if (function_exists('permalink_get_structure') && permalink_get_structure() === 'pretty' && $pat !== '') {
        return qp_seo_site_url(str_replace('%q%', '{search_term_string}', $pat));
    }
    return qp_seo_site_url('/index.php?route=search&q={search_term_string}');
}

function qp_seo_author_name(int $user_id): string {
    static $cache = [];
    if ($user_id <= 0) return '';
    if (isset($cache[$user_id])) return $cache[$user_id];
    return $cache[$user_id] = qp_seo_author_name_lookup($user_id);
}

function qp_seo_author_name_lookup(int $user_id): string {
    // One direct query for the display name; get_user_meta() would cost several. Falls back to the account name.
    try {
        $st = db()->prepare('SELECT meta_value FROM ' . table_name('user_meta') . " WHERE user_id = ? AND meta_key = 'display_name' LIMIT 1");
        $st->execute([$user_id]);
        $v = $st->fetchColumn();
        if ($v !== false) { $d = json_decode((string)$v, true); $v = is_string($d) ? $d : (string)$v; if (trim($v) !== '') return trim($v); }
        $st = db()->prepare('SELECT username FROM ' . table_name('users') . ' WHERE id = ? LIMIT 1');
        $st->execute([$user_id]);
        return trim((string)$st->fetchColumn());
    } catch (Throwable $_) { return ''; }
}
/** The graph as a list of nodes keyed by name (before the filter turns it into a flat list). */
function qp_seo_graph_nodes(): array {
    $s = qp_seo_settings(); $c = qp_seo_context(); $m = qp_seo_meta(); $post = $c['post'];
    $site = trim((string)qp_seo_site_vars()['%site%']);
    $lang = (string)($s['language'] ?? 'en-US');
    $nodes = [];

    $nodes['website'] = ['@type' => 'WebSite', '@id' => qp_seo_id('website'), 'url' => qp_seo_site_url('/'), 'name' => $site, 'inLanguage' => $lang,
        'publisher' => ['@id' => qp_seo_id('organization')],
        'potentialAction' => [['@type' => 'SearchAction', 'target' => ['@type' => 'EntryPoint', 'urlTemplate' => qp_seo_search_target()], 'query-input' => 'required name=search_term_string']]];
    $tag = trim((string)qp_seo_site_vars()['%tagline%']); if ($tag !== '') $nodes['website']['description'] = $tag;

    $org = ['@type' => (($s['org_type'] ?? 'Organization') === 'Person') ? 'Person' : 'Organization', '@id' => qp_seo_id('organization'), 'name' => ($s['org_name'] ?? '') !== '' ? $s['org_name'] : $site, 'url' => qp_seo_site_url('/')];
    $logo = qp_seo_abs((string)($s['org_logo'] ?? ''));
    if ($logo !== '') { $org['logo'] = ['@type' => 'ImageObject', '@id' => qp_seo_site_url('/') . '#logo', 'url' => $logo, 'contentUrl' => $logo]; $org['image'] = ['@id' => qp_seo_site_url('/') . '#logo']; }
    $same = array_values(array_filter(array_map('trim', preg_split('/\R/', (string)($s['org_same_as'] ?? ''))), function ($u) { return preg_match('#^https?://#i', $u); }));
    if ($same) $org['sameAs'] = $same;
    $nodes['organization'] = $org;

    $type = ['term' => 'CollectionPage', 'archive' => 'CollectionPage', 'search' => 'SearchResultsPage'][$c['type']] ?? 'WebPage';
    $page = ['@type' => $type, '@id' => qp_seo_id('webpage'), 'url' => $m['canonical'] !== '' ? $m['canonical'] : qp_seo_site_url('/'), 'name' => $m['title'], 'isPartOf' => ['@id' => qp_seo_id('website')], 'inLanguage' => $lang];
    if ($m['description'] !== '') $page['description'] = $m['description'];
    if ($m['published'] !== '') $page['datePublished'] = $m['published'];
    if ($m['modified'] !== '') $page['dateModified'] = $m['modified'];
    if ($m['image'] !== '') { $page['primaryImageOfPage'] = ['@type' => 'ImageObject', 'url' => $m['image']]; $page['image'] = ['@type' => 'ImageObject', 'url' => $m['image']]; }
    if (count(qp_seo_breadcrumbs()) > 1) $page['breadcrumb'] = ['@id' => qp_seo_id('breadcrumb')];
    $nodes['webpage'] = $page;

    if ($c['type'] === 'singular' && $post && ($c['post_type'] === 'post')) {
        $art = ['@type' => 'Article', '@id' => qp_seo_id('article'), 'mainEntityOfPage' => ['@id' => qp_seo_id('webpage')], 'headline' => mb_substr(qp_seo_post_title($post), 0, 110),
            'isPartOf' => ['@id' => qp_seo_id('webpage')], 'inLanguage' => $lang, 'publisher' => ['@id' => qp_seo_id('organization')]];
        if ($m['published'] !== '') $art['datePublished'] = $m['published'];
        if ($m['modified'] !== '') $art['dateModified'] = $m['modified'];
        if ($m['image'] !== '') $art['image'] = ['@type' => 'ImageObject', 'url' => $m['image']];
        if ($m['description'] !== '') $art['description'] = $m['description'];
        $author = qp_seo_author_name((int)($post['author_id'] ?? 0));
        if ($author !== '') $art['author'] = ['@type' => 'Person', 'name' => $author];
        $term = qp_seo_primary_term((int)$post['id']); if ($term) $art['articleSection'] = qp_seo_term_name($term);
        $art['wordCount'] = str_word_count(qp_seo_text(qp_seo_post_content($post), 100000));
        $nodes['article'] = $art;
    }

    $trail = qp_seo_breadcrumbs();
    if (count($trail) > 1) {
        $items = []; $i = 0;
        foreach ($trail as $it) {
            $i++;
            $row = ['@type' => 'ListItem', 'position' => $i, 'name' => $it['name']];
            if ($it['url'] !== '' ) $row['item'] = $it['url'];
            $items[] = $row;
        }
        $nodes['breadcrumb'] = ['@type' => 'BreadcrumbList', '@id' => qp_seo_id('breadcrumb'), 'itemListElement' => $items];
    }
    return $nodes;
}

/** The JSON-LD document for this request (array), after the qp_seo_graph filter. Empty array when there is nothing to say. */
function qp_seo_graph(): array {
    static $g = null;
    if ($g !== null) return $g;
    $nodes = qp_seo_graph_nodes();
    if (function_exists('apply_filters')) { $f = apply_filters('qp_seo_graph', $nodes, qp_seo_context(), qp_seo_meta()); if (is_array($f)) $nodes = $f; }
    $list = [];
    foreach ($nodes as $n) { if (is_array($n) && !empty($n['@type'])) $list[] = $n; }
    return $g = $list ? ['@context' => 'https://schema.org', '@graph' => $list] : [];
}

/** True when the module prints the JSON-LD graph on this site: on, not switched off, and the theme prints none of its own. */
function qp_seo_schema_active(): bool {
    if (!function_exists('qp_seo_enabled') || !qp_seo_enabled()) return false;
    $s = qp_seo_settings();
    if (isset($s['schema']) && !$s['schema']) return false;
    if ($s['respect_theme'] && !empty(qp_seo_theme_tags()['jsonld'])) return false;
    return true;
}

function qp_seo_render_schema(): void {
    if ((PHP_SAPI === 'cli' && !defined('QP_SEO_FORCE_RENDER')) || (defined('IN_ADMIN') && IN_ADMIN)) return;
    $s = qp_seo_settings();
    if (isset($s['schema']) && !$s['schema']) return;
    if ($s['respect_theme'] && !empty(qp_seo_theme_tags()['jsonld'])) return;
    $c = qp_seo_context();
    if ($c['type'] === '404') return;
    $g = qp_seo_graph();
    if (!$g) return;
    // JSON_HEX_TAG keeps "</script>" inside a title or description from ending the block.
    echo '<script type="application/ld+json">' . json_encode($g, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) . "</script>\n";
}

if (function_exists('add_action')) add_action('qp_head_tags', 'qp_seo_render_schema', 6, 0);
