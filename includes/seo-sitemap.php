<?php
if (!defined('QLOPY_INIT')) { http_response_code(403); exit; }
// includes/seo-sitemap.php
// SEO module, step 3 of C3: XML sitemaps and robots.txt. Loaded by includes/seo.php, served from index.php before any template.
//
// URLs (relative to the site address):
//   /sitemap.xml                       index of the files below
//   /sitemap-<provider>-<n>.xml        page n (1000 entries each) of one provider, e.g. sitemap-post-1.xml, sitemap-page-1.xml,
//                                      sitemap-term-category-1.xml (providers: a post type name, or term-<taxonomy>)
//   /robots.txt                        built from Settings > SEO; a physical robots.txt in the site folder always wins (the web server
//                                      answers it before PHP runs)
// Providers: the core registers one per public post type and one per taxonomy with a front route. A plugin adds or changes providers
// with the filter qp_seo_sitemap_providers (array keyed by provider name):
//   $p['myplugin-items'] = ['label' => 'My items', 'count' => function (): int {...},
//                           'entries' => function (int $offset, int $limit): array {...}];   // each entry: loc, lastmod?, images?, alternates?
// Per-entry changes or exclusion: filter qp_seo_sitemap_entry ($entry or null to drop it, $post row, $provider); hreflang alternates:
// the entry key 'alternates' => [['lang' => 'fr', 'href' => '...'], ...] (the multilingual layer fills it).
// Cost: a sitemap is built once, written to uploads/seo-cache/ and served from there; content changes delete the files (seo-purge.php);
// a build reads one page of 1000 rows by primary key order, never the whole table, and never runs for a bot twice in a row.
// Everything printed uses SITE_URL, never the arriving host.

require_once __DIR__ . '/seo-purge.php';

const QP_SITEMAP_CHUNK = 1000;
const QP_SITEMAP_TTL = 86400;

function qp_seo_sitemap_dir(): string {
    $d = (function_exists('qp_uploads_base') ? qp_uploads_base() : dirname(__DIR__) . '/uploads') . DIRECTORY_SEPARATOR . 'seo-cache';
    if (!is_dir($d)) @mkdir($d, 0775, true);
    return $d;
}

function qp_seo_sitemap_enabled(): bool {
    $s = qp_seo_settings();
    if (!empty($s['robots_block_all'])) return false;   // discouraging search engines: no sitemap is offered
    return !isset($s['sitemap']) || !empty($s['sitemap']);
}

/** Post types that appear in the sitemap: the owner's choice on Settings > SEO, default post and page. Filter: qp_seo_sitemap_post_types. */
function qp_seo_sitemap_post_types(): array {
    $s = qp_seo_settings();
    $chosen = $s['sitemap_post_types'] ?? ['post', 'page'];
    $types = function_exists('get_post_types') ? array_keys(get_post_types()) : ['post', 'page'];
    $out = array_values(array_intersect($chosen, array_merge($types, ['post', 'page'])));
    if (function_exists('apply_filters')) { $f = apply_filters('qp_seo_sitemap_post_types', $out); if (is_array($f)) $out = array_values(array_map('strval', $f)); }
    return $out;
}

function qp_seo_sitemap_iso(?string $mysql): string {
    if (!$mysql) return '';
    $t = strtotime($mysql . ' UTC');
    return $t ? gmdate('c', $t) : '';
}

function qp_seo_sitemap_post_url(array $post): string {
    if ($post['post_type'] === 'page' && function_exists('permalink_for_page')) return qp_seo_rebase(permalink_for_page($post['slug']));
    return function_exists('permalink_for_post') ? qp_seo_rebase(permalink_for_post($post['slug'], $post['post_type'])) : '';
}

/** Rows of one post type for one chunk, with the post meta the sitemap needs fetched in two queries, not one per post. */
function qp_seo_sitemap_post_entries(string $type, int $offset, int $limit): array {
    $pdo = db(); $s = qp_seo_settings();
    $st = $pdo->prepare('SELECT id, title, slug, content, post_type, status, visibility, published_at, created_at, updated_at FROM ' . table_name('posts')
        . " WHERE post_type = ? AND status = 'published' AND visibility = 'public' ORDER BY id ASC LIMIT " . (int)$limit . ' OFFSET ' . (int)$offset);
    $st->execute([$type]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) return [];
    $ids = array_map('intval', array_column($rows, 'id'));
    $in = implode(',', array_fill(0, count($ids), '?'));
    $meta = [];
    $mq = $pdo->prepare('SELECT post_id, meta_key, meta_value FROM ' . table_name('post_meta') . " WHERE meta_key IN ('_seo_robots','_seo_canonical','_thumbnail_id') AND post_id IN ($in)");
    $mq->execute($ids);
    foreach ($mq->fetchAll(PDO::FETCH_ASSOC) as $r) { $v = json_decode($r['meta_value'], true); $meta[(int)$r['post_id']][$r['meta_key']] = is_string($v) || is_int($v) ? (string)$v : (string)$r['meta_value']; }
    $entries = [];
    // On a multilingual site every language version is its own <url>, each listing all the versions as alternates. One batched
    // question per chunk to the provider (qp_i18n_register(): 'urls_batch', or 'url' per item and language).
    $langUrls = (function_exists('qp_i18n_enabled') && qp_i18n_enabled() && count(qp_languages()) >= 2) ? qp_translation_urls_batch('post', $ids) : [];
    foreach ($rows as $p) {
        $m = $meta[(int)$p['id']] ?? [];
        if (stripos($m['_seo_robots'] ?? '', 'noindex') !== false) continue;
        $loc = qp_seo_sitemap_post_url($p);
        if ($loc === '') continue;
        $canon = $m['_seo_canonical'] ?? '';
        if ($canon !== '' && rtrim($canon, '/') !== rtrim($loc, '/')) continue;   // the page says another address is the real one
        $e = ['loc' => $loc, 'lastmod' => qp_seo_sitemap_iso($p['updated_at'] ?: ($p['published_at'] ?: $p['created_at'])), 'images' => []];
        if (!isset($s['sitemap_images']) || $s['sitemap_images']) {
            $imgs = [];
            if (!empty($m['_thumbnail_id']) && function_exists('qp_get_attachment_url')) { $u = qp_get_attachment_url((int)$m['_thumbnail_id']); if ($u) $imgs[] = qp_seo_abs((string)$u); }
            if (preg_match_all('/<img[^>]+src=["\']([^"\']+)/i', (string)$p['content'], $mm)) foreach ($mm[1] as $u) $imgs[] = qp_seo_abs($u);
            $e['images'] = array_slice(array_values(array_unique(array_filter($imgs))), 0, 5);
        }
        $variants = [$e];
        $pl = $langUrls[(int)$p['id']] ?? [];
        if (count($pl) >= 2) {
            $alts = qp_seo_alternate_rows(array_map('qp_seo_rebase', $pl));
            $variants = [];
            foreach ($pl as $lang => $u) { $v = $e; $v['loc'] = qp_seo_rebase($u); $v['alternates'] = $alts; $variants[] = $v; }
        }
        foreach ($variants as $e) {
        if (function_exists('apply_filters')) {
            // other-language versions of this page when the provider has none (a plugin may answer; same shape as qp_seo_alternates())
            $alts = isset($e['alternates']) ? [] : apply_filters('qp_seo_sitemap_alternates', [], $p, $e['loc']);
            if (is_array($alts) && $alts) {
                $e['alternates'] = [];
                foreach ($alts as $r) { if (!empty($r['lang']) && !empty($r['href'])) $e['alternates'][] = ['lang' => (string)$r['lang'], 'href' => qp_seo_rebase((string)$r['href'])]; }
            }
            $e = apply_filters('qp_seo_sitemap_entry', $e, $p, $type); if (!is_array($e) || empty($e['loc'])) continue;
        }
        $entries[] = $e;
        }
    }
    // The home page leads the first page sitemap.
    if ($type === 'page' && $offset === 0) {
        array_unshift($entries, ['loc' => qp_seo_site_url('/'), 'lastmod' => '', 'images' => []]);
    }
    return $entries;
}

function qp_seo_sitemap_providers(): array {
    static $p = null;
    if ($p !== null) return $p;
    $p = [];
    $pdo = db();
    foreach (qp_seo_sitemap_post_types() as $type) {
        $p[$type] = [
            'label' => ucfirst($type),
            'count' => function () use ($pdo, $type) { $st = $pdo->prepare("SELECT COUNT(*) FROM " . table_name('posts') . " WHERE post_type = ? AND status = 'published' AND visibility = 'public'"); $st->execute([$type]); return (int)$st->fetchColumn(); },
            'entries' => function (int $o, int $l) use ($type) { return qp_seo_sitemap_post_entries($type, $o, $l); },
        ];
    }
    $s = qp_seo_settings();
    if (!isset($s['sitemap_terms']) || $s['sitemap_terms']) {
        $taxes = function_exists('get_taxonomies') ? get_taxonomies() : [];
        foreach ($taxes as $tax => $def) {
            if (empty($def['front_route'])) continue;
            $p['term-' . $tax] = [
                'label' => ucfirst($tax),
                'count' => function () use ($pdo, $tax) { $st = $pdo->prepare('SELECT COUNT(*) FROM ' . table_name('taxonomy_terms') . ' WHERE taxonomy = ?'); $st->execute([$tax]); return (int)$st->fetchColumn(); },
                'entries' => function (int $o, int $l) use ($pdo, $tax) {
                    $st = $pdo->prepare('SELECT slug FROM ' . table_name('taxonomy_terms') . ' WHERE taxonomy = ? ORDER BY id ASC LIMIT ' . (int)$l . ' OFFSET ' . (int)$o); $st->execute([$tax]);
                    $out = [];
                    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $slug) { if (function_exists('permalink_for_term')) $out[] = ['loc' => qp_seo_rebase(permalink_for_term($tax, $slug)), 'lastmod' => '', 'images' => []]; }
                    return $out;
                },
            ];
        }
    }
    if (function_exists('apply_filters')) { $f = apply_filters('qp_seo_sitemap_providers', $p); if (is_array($f)) $p = $f; }
    return $p;
}

function qp_seo_xml($v): string { return htmlspecialchars((string)$v, ENT_XML1 | ENT_QUOTES, 'UTF-8'); }

function qp_seo_sitemap_render_urlset(array $entries): string {
    $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:image="http://www.google.com/schemas/sitemap-image/1.1" xmlns:xhtml="http://www.w3.org/1999/xhtml">' . "\n";
    foreach ($entries as $e) {
        $xml .= '<url><loc>' . qp_seo_xml($e['loc']) . '</loc>';
        if (!empty($e['lastmod'])) $xml .= '<lastmod>' . qp_seo_xml($e['lastmod']) . '</lastmod>';
        foreach (($e['alternates'] ?? []) as $a) { if (!empty($a['lang']) && !empty($a['href'])) $xml .= '<xhtml:link rel="alternate" hreflang="' . qp_seo_xml($a['lang']) . '" href="' . qp_seo_xml($a['href']) . '"/>'; }
        foreach (($e['images'] ?? []) as $img) $xml .= '<image:image><image:loc>' . qp_seo_xml($img) . '</image:loc></image:image>';
        $xml .= "</url>\n";
    }
    return $xml . "</urlset>\n";
}

/** Cache file contents or null. A file older than the TTL counts as missing. */
function qp_seo_sitemap_cached(string $name): ?string {
    $f = qp_seo_sitemap_dir() . DIRECTORY_SEPARATOR . $name;
    if (!is_file($f) || (time() - (int)@filemtime($f)) > QP_SITEMAP_TTL) return null;
    $c = @file_get_contents($f);
    return $c === false ? null : $c;
}

function qp_seo_sitemap_store(string $name, string $xml): void {
    $f = qp_seo_sitemap_dir() . DIRECTORY_SEPARATOR . $name;
    $tmp = $f . '.' . bin2hex(random_bytes(4)) . '.tmp';
    if (@file_put_contents($tmp, $xml, LOCK_EX) !== false) { if (!@rename($tmp, $f)) { @unlink($f); @rename($tmp, $f); } @unlink($tmp); }
}

function qp_seo_sitemap_index_xml(): string {
    $c = qp_seo_sitemap_cached('sitemap.xml'); if ($c !== null) return $c;
    $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
    foreach (qp_seo_sitemap_providers() as $key => $p) {
        $n = (int)call_user_func($p['count']);
        if ($n <= 0) continue;
        $pages = (int)ceil($n / QP_SITEMAP_CHUNK);
        for ($i = 1; $i <= $pages; $i++) $xml .= '<sitemap><loc>' . qp_seo_xml(qp_seo_site_url('/sitemap-' . $key . '-' . $i . '.xml')) . "</loc></sitemap>\n";
    }
    $xml .= "</sitemapindex>\n";
    qp_seo_sitemap_store('sitemap.xml', $xml);
    return $xml;
}

/** The sitemap for one file name (sitemap-<provider>-<n>.xml), or null when there is no such provider or page. */
function qp_seo_sitemap_file_xml(string $key, int $page): ?string {
    $providers = qp_seo_sitemap_providers();
    if (!isset($providers[$key]) || $page < 1) return null;
    $name = 'sitemap-' . $key . '-' . $page . '.xml';
    $c = qp_seo_sitemap_cached($name); if ($c !== null) return $c;
    $p = $providers[$key];
    if ($page > max(1, (int)ceil(((int)call_user_func($p['count'])) / QP_SITEMAP_CHUNK))) return null;
    $entries = call_user_func($p['entries'], ($page - 1) * QP_SITEMAP_CHUNK, QP_SITEMAP_CHUNK);
    $xml = qp_seo_sitemap_render_urlset(is_array($entries) ? $entries : []);
    qp_seo_sitemap_store($name, $xml);
    return $xml;
}

function qp_seo_robots_txt(): string {
    $s = qp_seo_settings();
    $site = parse_url(qp_seo_site_url(), PHP_URL_PATH); $site = rtrim((string)$site, '/');
    $admin = '/' . trim((string)(function_exists('get_config') ? (get_config()['admin_dir'] ?? 'admin') : 'admin'), '/');
    $lines = ['User-agent: *'];
    if (!empty($s['robots_block_all'])) {
        $lines[] = 'Disallow: /';
    } else {
        $dis = [$admin . '/', '/ajax.php', '/install.php', '/qp-cron.php', '/?qp_cron'];
        if (!empty($s['noindex_search']) && function_exists('permalink_get_patterns')) { $sp = (string)(permalink_get_patterns()['search'] ?? ''); $sp = trim(explode('%q%', $sp)[0], '/'); if ($sp !== '') $dis[] = '/' . $sp . '/'; }
        if (function_exists('apply_filters')) { $f = apply_filters('qp_seo_robots_disallow', $dis); if (is_array($f)) $dis = $f; }
        foreach (array_unique($dis) as $d) $lines[] = 'Disallow: ' . $site . '/' . ltrim((string)$d, '/');
        $lines[] = 'Allow: ' . $site . '/' . qp_uploads_dirname() . '/';
    }
    $extra = trim((string)($s['robots_extra'] ?? ''));
    if ($extra !== '') { $lines[] = ''; foreach (preg_split('/\R/', $extra) as $l) $lines[] = rtrim($l); }
    if (qp_seo_sitemap_enabled()) { $lines[] = ''; $lines[] = 'Sitemap: ' . qp_seo_site_url('/sitemap.xml'); }
    $txt = implode("\n", $lines) . "\n";
    if (function_exists('apply_filters')) { $f = apply_filters('qp_seo_robots_txt', $txt); if (is_string($f)) $txt = $f; }
    return $txt;
}

function qp_seo_serve_body(string $type, string $body, string $etagSeed = ''): void {
    $etag = '"' . md5($body) . '"';
    header('Content-Type: ' . $type);
    header('X-Robots-Tag: noindex');
    header('Cache-Control: public, max-age=3600');
    header('ETag: ' . $etag);
    if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim($_SERVER['HTTP_IF_NONE_MATCH']) === $etag) { http_response_code(304); exit; }
    echo $body;
    exit;
}

/**
 * Called from index.php with the request path relative to the site (no leading or trailing slash). Serves and exits for
 * robots.txt, sitemap.xml and sitemap-<provider>-<n>.xml; returns for every other path.
 */
function qp_seo_serve_special(string $uri): void {
    if ($uri === 'robots.txt') {
        if (is_file(dirname(__DIR__) . '/robots.txt')) return;   // a physical file is the owner's: never replaced
        qp_seo_serve_body('text/plain; charset=UTF-8', qp_seo_robots_txt());
    }
    if (!qp_seo_sitemap_enabled()) return;
    if ($uri === 'sitemap.xml') qp_seo_serve_body('application/xml; charset=UTF-8', qp_seo_sitemap_index_xml());
    if (preg_match('/^sitemap-([a-z0-9_\-]+)-(\d+)\.xml$/i', $uri, $m)) {
        $xml = qp_seo_sitemap_file_xml($m[1], (int)$m[2]);
        if ($xml === null) { http_response_code(404); header('Content-Type: text/plain; charset=UTF-8'); header('X-Robots-Tag: noindex'); echo "Not found\n"; exit; }
        qp_seo_serve_body('application/xml; charset=UTF-8', $xml);
    }
}
