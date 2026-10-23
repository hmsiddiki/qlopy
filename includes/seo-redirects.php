<?php
if (!defined('QLOPY_INIT')) { http_response_code(403); exit; }
// includes/seo-redirects.php
// SEO module, step 4 of C3: redirect manager and 404 log. Loaded by includes/seo.php, and on demand by the slug-change listener
// (includes/seo-purge.php) and the admin screen.
//
// Rules live in the table {prefix}seo_redirects and are compiled into uploads/seo-cache/redirects.php (a plain PHP array, so OPcache keeps
// it): a request is matched against that file only, never the database. Matching is on the path relative to the site folder, case
// insensitive, without the query string, with or without a trailing slash. Types: exact, prefix (everything under a path), regex
// (delimited by #, may use $1 in the target). Codes: 301, 302, 307, 308, and 410 (gone, no target). A rule redirects one hop; saving a
// rule that would loop or chain is refused. The query string is kept unless the rule says otherwise.
// Automatic rules: when a post's slug changes, a 301 from the old address to the new one is added (action qp_post_slug_changed),
// and rules that pointed at the old address are moved to the new one.
//
// 404 log: every GET that ends as a 404 is counted per path (hits, first and last seen, referrer), at most 60 new writes a minute, at most
// 1000 paths kept; a cron task (qp_seo_404_prune) removes entries older than 30 days and trims to the cap.
// API: qp_seo_redirect_add($source, $target, $code, $type, $opts), qp_seo_redirect_delete($id), qp_seo_redirect_list(), qp_seo_404_top($n).

require_once __DIR__ . '/seo-sitemap.php';   // qp_seo_sitemap_dir(): the compiled rules live next to the sitemap cache

const QP_SEO_SCHEMA_VERSION = 1;
const QP_SEO_404_CAP = 1000;

function qp_seo_redirect_table(): string { return table_name('seo_redirects'); }
function qp_seo_404_table(): string { return table_name('seo_404_log'); }

function qp_seo_redirects_install(bool $force = false): void {
    static $done = false;
    if ($done && !$force) return;
    if (!$force && function_exists('get_option_meta') && (int)get_option_meta('qp_seo_schema') >= QP_SEO_SCHEMA_VERSION) { $done = true; return; }
    $pdo = db();
    $pdo->exec('CREATE TABLE IF NOT EXISTS `' . qp_seo_redirect_table() . '` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `match_type` VARCHAR(10) NOT NULL DEFAULT \'exact\',
        `source` VARCHAR(500) NOT NULL,
        `source_hash` CHAR(40) NOT NULL,
        `target` VARCHAR(1000) NOT NULL DEFAULT \'\',
        `code` SMALLINT NOT NULL DEFAULT 301,
        `keep_query` TINYINT NOT NULL DEFAULT 1,
        `enabled` TINYINT NOT NULL DEFAULT 1,
        `auto` TINYINT NOT NULL DEFAULT 0,
        `hits` INT UNSIGNED NOT NULL DEFAULT 0,
        `last_hit` INT UNSIGNED NOT NULL DEFAULT 0,
        `note` VARCHAR(255) NOT NULL DEFAULT \'\',
        `created_at` INT UNSIGNED NOT NULL DEFAULT 0,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uniq_rule` (`match_type`, `source_hash`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $pdo->exec('CREATE TABLE IF NOT EXISTS `' . qp_seo_404_table() . '` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `path_hash` CHAR(40) NOT NULL,
        `path` VARCHAR(500) NOT NULL,
        `referrer` VARCHAR(500) NOT NULL DEFAULT \'\',
        `hits` INT UNSIGNED NOT NULL DEFAULT 1,
        `first_seen` INT UNSIGNED NOT NULL,
        `last_seen` INT UNSIGNED NOT NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uniq_path` (`path_hash`),
        KEY `last_seen` (`last_seen`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    if (function_exists('update_option_meta')) update_option_meta('qp_seo_schema', QP_SEO_SCHEMA_VERSION);
    $done = true;
}

/** '/path' form used for matching and storing: decoded, no query, no duplicate or trailing slash, lower case. */
function qp_seo_norm_path(string $path): string {
    $path = (string)(parse_url($path, PHP_URL_PATH) ?? $path);
    $path = rawurldecode($path);
    $path = preg_replace('#/+#', '/', '/' . $path);
    $path = rtrim($path, '/');
    return $path === '' ? '/' : strtolower($path);
}

/** The path of a full address relative to the site folder (what the router sees). */
function qp_seo_relative_path(string $url): string {
    $p = (string)(parse_url($url, PHP_URL_PATH) ?? '/');
    $base = rtrim((string)(parse_url(qp_seo_site_url(), PHP_URL_PATH) ?? ''), '/');
    if ($base !== '' && stripos($p, $base) === 0) $p = substr($p, strlen($base));
    return qp_seo_norm_path($p);
}

/**
 * Path of the current compiled rule file, or '' when there is none. Every compile writes a new file name (redirects-<version>.php) and
 * then updates the one-line pointer redirects.ver, so OPcache can never serve an older copy of a rewritten file (it revalidates a file
 * only every few seconds, and not at all across the command line and the web server).
 */
function qp_seo_redirects_file(): string {
    $d = qp_seo_sitemap_dir();
    $v = trim((string)@file_get_contents($d . DIRECTORY_SEPARATOR . 'redirects.ver'));
    return ($v !== '' && ctype_alnum($v) && is_file($d . DIRECTORY_SEPARATOR . 'redirects-' . $v . '.php')) ? $d . DIRECTORY_SEPARATOR . 'redirects-' . $v . '.php' : '';
}

/** Rebuild the compiled rule file from the table. Called after every change. */
function qp_seo_redirects_compile(): void {
    qp_seo_redirects_install();
    $rows = db()->query('SELECT * FROM `' . qp_seo_redirect_table() . '` WHERE enabled = 1 ORDER BY id ASC')->fetchAll(PDO::FETCH_ASSOC);
    $exact = []; $rules = [];
    foreach ($rows as $r) {
        $item = ['id' => (int)$r['id'], 'target' => $r['target'], 'code' => (int)$r['code'], 'keep' => (int)$r['keep_query']];
        if ($r['match_type'] === 'exact') { $exact[qp_seo_norm_path($r['source'])] = $item; }
        else { $rules[] = $item + ['type' => $r['match_type'], 'source' => $r['match_type'] === 'prefix' ? qp_seo_norm_path($r['source']) : $r['source']]; }
    }
    $php = "<?php\n// Compiled by includes/seo-redirects.php: do not edit.\nreturn " . var_export(['exact' => $exact, 'rules' => $rules], true) . ";\n";
    $d = qp_seo_sitemap_dir(); $ver = dechex((int)(microtime(true) * 1000)) . bin2hex(random_bytes(2));
    $new = $d . DIRECTORY_SEPARATOR . 'redirects-' . $ver . '.php';
    if (@file_put_contents($new, $php, LOCK_EX) !== false) {
        $ptr = $d . DIRECTORY_SEPARATOR . 'redirects.ver'; $tmp = $ptr . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($tmp, $ver, LOCK_EX) !== false) { if (!@rename($tmp, $ptr)) { @unlink($ptr); @rename($tmp, $ptr); } @unlink($tmp); }
        // older compiled files are no longer pointed at; keep the previous one for a request that read the old pointer a moment ago
        $old = glob($d . DIRECTORY_SEPARATOR . 'redirects-*.php') ?: [];
        // newest first by the version in the name (file times have one-second resolution: two compiles in a second would tie)
        $verOf = function ($p) { $b = basename($p, '.php'); return hexdec(substr($b, 10, -4)); };
        usort($old, function ($a, $b) use ($verOf) { return $verOf($b) <=> $verOf($a); });
        foreach (array_slice($old, 2) as $o) @unlink($o);
    }
    if (function_exists('qp_page_cache_purge_all')) qp_page_cache_purge_all();   // a stored page may now be a redirect
}

/** Resolve a target to an absolute address on SITE_URL (site-relative paths) or leave an absolute http(s) address as it is. */
function qp_seo_redirect_target_url(string $target): string {
    if (preg_match('#^https?://#i', $target)) return $target;
    return qp_seo_site_url('/' . ltrim($target, '/'));
}

/**
 * Add a rule. Returns ['ok' => bool, 'id' => int, 'error' => string]. $opts: keep_query (bool), note (string), auto (bool), enabled (bool).
 * Refuses a rule that redirects to itself, whose target is the source of another rule (a chain), or that is the target of another
 * rule's chain back to its source (a loop), and refuses a regex that does not compile.
 */
function qp_seo_redirect_add(string $source, string $target, int $code = 301, string $type = 'exact', array $opts = []): array {
    qp_seo_redirects_install();
    $source = trim($source); $target = trim($target);
    if (!in_array($type, ['exact', 'prefix', 'regex'], true)) return ['ok' => false, 'id' => 0, 'error' => 'Unknown match type.'];
    if (!in_array($code, [301, 302, 307, 308, 410], true)) return ['ok' => false, 'id' => 0, 'error' => 'Unknown status code.'];
    if ($source === '') return ['ok' => false, 'id' => 0, 'error' => 'The old address is empty.'];
    if ($code !== 410) {
        if ($target === '') return ['ok' => false, 'id' => 0, 'error' => 'The new address is empty.'];
        if (!preg_match('#^(https?://|/)#i', $target)) return ['ok' => false, 'id' => 0, 'error' => 'The new address must start with / or http(s)://.'];
        if (preg_match('#^(javascript|data):#i', $target)) return ['ok' => false, 'id' => 0, 'error' => 'That address is not allowed.'];
    } else { $target = ''; }
    if ($type === 'regex') {
        if (@preg_match('#' . str_replace('#', '\#', $source) . '#i', '') === false) return ['ok' => false, 'id' => 0, 'error' => 'The pattern is not a valid regular expression.'];
        $stored = $source;
    } else {
        $stored = qp_seo_norm_path($source);
        if ($stored === '/' && $type === 'exact') return ['ok' => false, 'id' => 0, 'error' => 'The home page cannot be redirected with an exact rule.'];
    }
    if ($type === 'exact' && $code !== 410) {
        $tpath = qp_seo_relative_path(qp_seo_redirect_target_url($target));
        if (preg_match('#^(https?://)#i', $target) && stripos($target, qp_seo_site_url()) !== 0) { /* another site: no loop possible */ }
        elseif ($tpath === $stored) return ['ok' => false, 'id' => 0, 'error' => 'The old and new address are the same.'];
        else {
            // chain or loop: does the target already redirect somewhere?
            $st = db()->prepare('SELECT target FROM `' . qp_seo_redirect_table() . '` WHERE match_type = \'exact\' AND source_hash = ? AND enabled = 1 AND code <> 410');
            $st->execute([sha1($tpath)]);
            $next = $st->fetchColumn();
            if ($next !== false) {
                if (qp_seo_relative_path(qp_seo_redirect_target_url((string)$next)) === $stored) return ['ok' => false, 'id' => 0, 'error' => 'That would make a loop: the new address already redirects back here.'];
                return ['ok' => false, 'id' => 0, 'error' => 'The new address is itself redirected (' . $tpath . ' goes on to ' . $next . '). Point this rule at the final address instead.'];
            }
        }
    }
    try {
        $st = db()->prepare('INSERT INTO `' . qp_seo_redirect_table() . '` (match_type, source, source_hash, target, code, keep_query, enabled, auto, note, created_at) VALUES (?,?,?,?,?,?,?,?,?,?)
            ON DUPLICATE KEY UPDATE target = VALUES(target), code = VALUES(code), keep_query = VALUES(keep_query), enabled = VALUES(enabled), note = VALUES(note)');
        $st->execute([$type, mb_substr($stored, 0, 500), sha1($stored), mb_substr($target, 0, 1000), $code, !isset($opts['keep_query']) || $opts['keep_query'] ? 1 : 0, !isset($opts['enabled']) || $opts['enabled'] ? 1 : 0, !empty($opts['auto']) ? 1 : 0, mb_substr((string)($opts['note'] ?? ''), 0, 255), time()]);
        $id = (int)db()->lastInsertId();
        if ($id === 0) { $q = db()->prepare('SELECT id FROM `' . qp_seo_redirect_table() . '` WHERE match_type = ? AND source_hash = ?'); $q->execute([$type, sha1($stored)]); $id = (int)$q->fetchColumn(); }
    } catch (Throwable $e) { return ['ok' => false, 'id' => 0, 'error' => $e->getMessage()]; }
    qp_seo_redirects_compile();
    return ['ok' => true, 'id' => $id, 'error' => ''];
}

function qp_seo_redirect_delete(int $id): void {
    qp_seo_redirects_install();
    db()->prepare('DELETE FROM `' . qp_seo_redirect_table() . '` WHERE id = ?')->execute([$id]);
    qp_seo_redirects_compile();
}

function qp_seo_redirect_set_enabled(int $id, bool $on): void {
    qp_seo_redirects_install();
    db()->prepare('UPDATE `' . qp_seo_redirect_table() . '` SET enabled = ? WHERE id = ?')->execute([$on ? 1 : 0, $id]);
    qp_seo_redirects_compile();
}

function qp_seo_redirect_list(int $limit = 500): array {
    qp_seo_redirects_install();
    return db()->query('SELECT * FROM `' . qp_seo_redirect_table() . '` ORDER BY id DESC LIMIT ' . (int)$limit)->fetchAll(PDO::FETCH_ASSOC);
}

/** Find the rule for a path (relative to the site, normalised) in the compiled file. Returns ['url' => ..., 'code' => ..., 'id' => ...] or null. */
function qp_seo_redirect_match(string $path, string $query = ''): ?array {
    static $cfg = false;
    if ($cfg === false) {
        $f = qp_seo_redirects_file();
        $cfg = $f !== '' ? (include $f) : null;
        if (!is_array($cfg)) $cfg = null;
    }
    if (!$cfg) return null;
    $p = qp_seo_norm_path($path);
    $hit = $cfg['exact'][$p] ?? null; $m = null;
    if (!$hit) {
        foreach ($cfg['rules'] as $r) {
            if ($r['type'] === 'prefix') {
                if ($p === $r['source'] || strpos($p . '/', $r['source'] . '/') === 0) { $hit = $r; break; }
            } else {
                if (@preg_match('#' . str_replace('#', '\#', $r['source']) . '#i', $p, $mm)) { $hit = $r; $m = $mm; break; }
            }
        }
    }
    if (!$hit) return null;
    $url = '';
    if ($hit['code'] !== 410) {
        $t = (string)$hit['target'];
        if ($m !== null) $t = preg_replace_callback('/\$(\d)/', function ($x) use ($m) { return $m[(int)$x[1]] ?? ''; }, $t);
        $url = qp_seo_redirect_target_url($t);
        if (!empty($hit['keep']) && $query !== '' && strpos($url, '?') === false) $url .= '?' . $query;
    }
    return ['url' => $url, 'code' => (int)$hit['code'], 'id' => (int)$hit['id']];
}

/** Called from index.php with the request path relative to the site. Redirects and exits on a match; returns otherwise. */
function qp_seo_maybe_redirect(string $uri): void {
    if (PHP_SAPI === 'cli') return;
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($method !== 'GET' && $method !== 'HEAD') return;
    $hit = qp_seo_redirect_match('/' . $uri, (string)($_SERVER['QUERY_STRING'] ?? ''));
    if (!$hit) return;
    $id = $hit['id'];
    register_shutdown_function(function () use ($id) {
        try { db()->prepare('UPDATE `' . qp_seo_redirect_table() . '` SET hits = hits + 1, last_hit = ? WHERE id = ?')->execute([time(), $id]); } catch (Throwable $_) {}
    });
    header('X-Redirect-By: Qlopy SEO');
    header('X-Robots-Tag: noindex');
    if ($hit['code'] === 410) {
        http_response_code(410); header('Content-Type: text/plain; charset=UTF-8'); header('Cache-Control: public, max-age=300');
        echo "Gone\n"; exit;
    }
    header('Cache-Control: ' . (in_array($hit['code'], [301, 308], true) ? 'public, max-age=300' : 'no-store'));
    header('Location: ' . $hit['url'], true, $hit['code']);
    exit;
}

// ---- slug changes ----

/** A post's slug changed: send the old address to the new one, and move rules that pointed at the old address. */
function qp_seo_on_slug_changed($post_id, $old_slug, $new_slug, $post_type): void {
    if ($old_slug === '' || $new_slug === '' || $old_slug === $new_slug) return;
    if (!function_exists('permalink_for_post') || !function_exists('permalink_for_page')) return;
    $mk = function ($slug) use ($post_type) { return $post_type === 'page' ? permalink_for_page($slug) : permalink_for_post($slug, $post_type); };
    $old_url = qp_seo_rebase($mk($old_slug)); $new_url = qp_seo_rebase($mk($new_slug));
    $old_path = qp_seo_relative_path($old_url); $new_path = qp_seo_relative_path($new_url);
    if ($old_path === $new_path) return;
    qp_seo_redirects_install();
    $pdo = db();
    // a rule that sent visitors from the new address elsewhere (an earlier rename back) would now be a loop
    $pdo->prepare('DELETE FROM `' . qp_seo_redirect_table() . '` WHERE match_type = \'exact\' AND source_hash = ?')->execute([sha1($new_path)]);
    // rules that pointed at the old address now point at the new one (no chains)
    $pdo->prepare('UPDATE `' . qp_seo_redirect_table() . '` SET target = ? WHERE target IN (?, ?)')->execute([$new_url, $old_url, $old_path]);
    qp_seo_redirect_add($old_path, $new_url, 301, 'exact', ['auto' => true, 'note' => 'Slug changed (post ' . (int)$post_id . ')']);
}

// ---- 404 log ----

function qp_seo_404_log(): void {
    if (PHP_SAPI === 'cli' || http_response_code() !== 404) return;
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') return;
    $path = qp_seo_norm_path((string)($_SERVER['REQUEST_URI'] ?? '/'));
    $base = rtrim((string)(parse_url(qp_seo_site_url(), PHP_URL_PATH) ?? ''), '/');
    if ($base !== '' && stripos($path, strtolower($base)) === 0) $path = substr($path, strlen($base)) ?: '/';
    // at most 60 writes a minute: a bot sweeping thousands of paths must not turn the log into a write storm
    if (function_exists('qp_cron_state_update')) {
        $allowed = true;
        qp_cron_state_update(function ($s) use (&$allowed) {
            $w = array_values(array_filter($s['seo404_w'] ?? [], function ($t) { return $t > time() - 60; }));
            if (count($w) >= 60) { $allowed = false; return null; }
            $w[] = time(); $s['seo404_w'] = $w; return $s;
        });
        if (!$allowed) return;
    }
    try {
        qp_seo_redirects_install();
        $ref = mb_substr((string)($_SERVER['HTTP_REFERER'] ?? ''), 0, 500);
        db()->prepare('INSERT INTO `' . qp_seo_404_table() . '` (path_hash, path, referrer, hits, first_seen, last_seen) VALUES (?,?,?,1,?,?)
            ON DUPLICATE KEY UPDATE hits = hits + 1, last_seen = VALUES(last_seen), referrer = IF(VALUES(referrer) <> \'\', VALUES(referrer), referrer)')
            ->execute([sha1($path), mb_substr($path, 0, 500), $ref, time(), time()]);
    } catch (Throwable $_) {}
}

function qp_seo_404_top(int $n = 100): array {
    qp_seo_redirects_install();
    return db()->query('SELECT * FROM `' . qp_seo_404_table() . '` ORDER BY hits DESC, last_seen DESC LIMIT ' . (int)$n)->fetchAll(PDO::FETCH_ASSOC);
}

function qp_seo_404_clear(): void { qp_seo_redirects_install(); db()->exec('DELETE FROM `' . qp_seo_404_table() . '`'); }

/** Cron task: drop entries not seen for 30 days and keep at most QP_SEO_404_CAP paths (the most hit). */
function qp_seo_404_prune(): int {
    qp_seo_redirects_install();
    $pdo = db(); $t = qp_seo_404_table();
    $n = $pdo->exec("DELETE FROM `$t` WHERE last_seen < " . (time() - 30 * 86400));
    $count = (int)$pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
    if ($count > QP_SEO_404_CAP) {
        $cut = $pdo->query("SELECT id FROM `$t` ORDER BY hits DESC, last_seen DESC LIMIT 1 OFFSET " . (QP_SEO_404_CAP - 1))->fetchColumn();
        if ($cut) { $ids = $pdo->query("SELECT id FROM `$t` ORDER BY hits DESC, last_seen DESC LIMIT 100000 OFFSET " . QP_SEO_404_CAP)->fetchAll(PDO::FETCH_COLUMN); if ($ids) $n += $pdo->exec("DELETE FROM `$t` WHERE id IN (" . implode(',', array_map('intval', $ids)) . ')'); }
    }
    return (int)$n;
}

// The listeners for qp_post_slug_changed and the qp_seo_404_prune task are registered in seo-purge.php, which every entry point loads.
