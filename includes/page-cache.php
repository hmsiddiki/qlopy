<?php
if (!defined('QLOPY_INIT')) { http_response_code(403); exit; }
// includes/page-cache.php
// Page cache for anonymous visitors, storing and purging half (core upgrade plan, step C1e). The serving half is
// includes/page-cache-early.php. Loaded from index.php only while the cache is switched on, and by admin screens and plugins that purge.
//
// Public API for plugins and themes:
//   qp_page_cache_declare_compatible(string $plugin_slug)  call from plugin.php: "my pages are safe to cache; I purge what I change"
//   qp_page_cache_bypass_cookie(string $name)              requests carrying this cookie are never cached or served from cache
//   qp_page_cache_bypass_path(string $prefix)              paths under this prefix (relative to the site) are never cached
//   qp_page_cache_vary_cookie(string $name)                the cookie's value becomes part of the cache key (currency, language)
//   qp_page_cache_mark_uncacheable(string $reason)         this response must not be stored (call when a page is personal)
//   qp_page_cache_purge_all()  qp_page_cache_purge_url($url)  qp_page_cache_purge_post($post_id)
// Core marks a response uncacheable by itself when it creates a nonce, sets a cookie, is not a complete 200 HTML page, or when an
// active plugin has not declared itself compatible (or been trusted by the owner in Settings).

function qp_pc_early_loaded(): void {
    if (!function_exists('qp_pc_dir')) require_once __DIR__ . '/page-cache-early.php';
}

function qp_page_cache_dir(): string {
    qp_pc_early_loaded();
    global $config;
    return qp_pc_dir(is_array($config ?? null) ? $config : (function_exists('get_config') ? get_config() : []));
}

function qp_page_cache_state(): array {
    qp_pc_early_loaded();
    return qp_pc_read_state(qp_page_cache_dir());
}

function qp_page_cache_enabled(): bool {
    $s = qp_page_cache_state();
    return !empty($s['enabled']);
}

/** Switch the cache on or off. Off removes the state file, so index.php stops loading anything for it. */
function qp_page_cache_enable(bool $on, array $opts = []): void {
    qp_pc_early_loaded();
    $dir = qp_page_cache_dir();
    if (!$on) {
        @unlink($dir . DIRECTORY_SEPARATOR . 'state.json');
        // Nothing can serve these any more: remove them (in slices if there are many) and stop the cleanup task.
        $budget = 5000; qp_pc_rmdir($dir . DIRECTORY_SEPARATOR . 'pages', $budget);
        if (is_dir($dir . DIRECTORY_SEPARATOR . 'pages')) @file_put_contents($dir . DIRECTORY_SEPARATOR . 'leftover.txt', (string)time());
        if (function_exists('qp_remove_tasks_by_hook')) { try { qp_remove_tasks_by_hook('qp_page_cache_gc_task'); } catch (Throwable $_) {} }
        return;
    }
    // Keep the folder from being browsed or downloaded on Apache; see includes/server-snippets/README.md for nginx.
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    if (is_dir($dir) && !is_file($dir . DIRECTORY_SEPARATOR . '.htaccess')) {
        @file_put_contents($dir . DIRECTORY_SEPARATOR . '.htaccess', "Require all denied
<IfModule !mod_authz_core.c>
Deny from all
</IfModule>
");
        @file_put_contents($dir . DIRECTORY_SEPARATOR . 'index.html', '');
    }
    qp_pc_state_update($dir, function ($s) use ($opts) {
        $defaults = [
            'enabled' => true,
            'gen' => (int)($s['gen'] ?? 1) + 1,   // a fresh generation: nothing stored earlier can be served
            'ttl' => 21600,
            'cache_control' => 'public, max-age=0, must-revalidate',
            'device_split' => false,
            'bypass_cookies' => ['qp_token', 'qp_preview_theme', 'qp_install_token', 'qcommerce_cart', 'qcommerce_buy_now', 'qcommerce_notices'],
            'bypass_cookie_prefixes' => ['qp_post_access_'],   // password-protected posts: the visitor who entered the password sees more
            'bypass_query' => ['s', 'preview', 'preview_theme', 'qp_preview', 'exit_preview', 'qp_cron', 'qp_cron_worker', 'qp_dbg'],
            'bypass_paths' => [],
            'vary_cookies' => ['qcommerce_currency'],
            'vary_al' => [],
            'trusted_plugins' => [],
            'declared' => [],
            'daily_purge' => '',
            'next_purge' => 0,
        ];
        // Keep what plugins registered earlier and what the owner chose before; options passed now win.
        $merged = array_merge($defaults, array_intersect_key($s, $defaults), $opts);
        $merged['enabled'] = true;
        $merged['gen'] = (int)($s['gen'] ?? 1) + 1;
        // Rule lists: what core ships now plus what plugins and the owner added earlier (a newer core may add rules).
        foreach (['bypass_cookies', 'bypass_cookie_prefixes', 'bypass_query', 'vary_cookies', 'vary_al'] as $list) {
            $merged[$list] = array_values(array_unique(array_merge($defaults[$list], $s[$list] ?? [], $opts[$list] ?? [])));
        }
        return $merged;
    });
    // Cleanup of old generations every 15 minutes, as a light cron task (needs the scheduler files).
    if (function_exists('qp_schedule_single_event') || is_file(__DIR__ . '/qp-cron.php')) {
        try {
            require_once __DIR__ . '/qp-cron.php'; require_once __DIR__ . '/qp-cron-queue.php';
            if (qp_next_scheduled('qp_page_cache_gc_task') === null) qp_schedule_single_event(time() + 900, 'qp_page_cache_gc_task', [], 900);
        } catch (Throwable $_) {}
    }
}

// ---- registration by plugins (rules are persisted so the early file can use them before any plugin loads) ----

function qp_page_cache_register(string $list, string $value): void {
    if ($value === '') return;
    $s = $GLOBALS['qp_pc_state'] ?? null;
    if (!is_array($s)) { if (!qp_page_cache_enabled()) return; $s = qp_page_cache_state(); }
    if (in_array($value, $s[$list] ?? [], true)) return;
    qp_pc_state_update(qp_page_cache_dir(), function ($cur) use ($list, $value) {
        if (empty($cur['enabled'])) return null;
        $cur[$list] = array_values(array_unique(array_merge($cur[$list] ?? [], [$value])));
        $cur['gen'] = (int)($cur['gen'] ?? 1) + 1;  // a new rule can change what is safe: start a fresh generation
        return $cur;
    });
    unset($GLOBALS['qp_pc_state']);
}
function qp_page_cache_bypass_cookie(string $name): void { qp_page_cache_register('bypass_cookies', $name); }
function qp_page_cache_bypass_query(string $key): void { qp_page_cache_register('bypass_query', $key); }

/**
 * Purge everything once a day at HH:MM:SS (server time zone), checked on the first request after that time. For content that changes
 * by the clock without anyone saving anything (a sale price that starts or ends on a date). Idempotent; call at plugin load.
 */
function qp_page_cache_purge_daily(string $hms = '00:00:05'): void {
    qp_pc_early_loaded();
    $s = $GLOBALS['qp_pc_state'] ?? null;
    if (!is_array($s)) { if (!qp_page_cache_enabled()) return; $s = qp_page_cache_state(); }
    if ((string)($s['daily_purge'] ?? '') === $hms && !empty($s['next_purge'])) return;
    qp_pc_state_update(qp_page_cache_dir(), function ($cur) use ($hms) {
        if (empty($cur['enabled'])) return null;
        $cur['daily_purge'] = $hms; $cur['next_purge'] = qp_pc_next_daily($hms);
        return $cur;
    });
}

/** For plugins: something shown to visitors changed (stock, a review, a price). Purges now and again when the request ends. */
function qp_page_cache_purge_all_soon(): void { qp_page_cache_on_change(); }
function qp_page_cache_bypass_path(string $prefix): void { qp_page_cache_register('bypass_paths', '/' . trim($prefix, '/')); }
function qp_page_cache_vary_cookie(string $name): void { qp_page_cache_register('vary_cookies', $name); }

/** The site's language codes, when a plugin picks the language from the browser's Accept-Language header: the negotiated one is part of the key. */
function qp_page_cache_vary_accept_language(array $codes): void {
    foreach ($codes as $c) { if (is_string($c) && $c !== '') qp_page_cache_register('vary_al', strtolower($c)); }
}

function qp_page_cache_declare_compatible(string $plugin_slug): void {
    $GLOBALS['qp_pc_declared'][$plugin_slug] = true;
    // Remember it for the Settings screen (admin requests do not run front-end plugin code). Written once per plugin.
    $s = $GLOBALS['qp_pc_state'] ?? null;
    if (!is_array($s)) { if (!qp_page_cache_enabled()) return; $s = qp_page_cache_state(); }
    if (!in_array($plugin_slug, $s['declared'] ?? [], true)) {
        qp_pc_state_update(qp_page_cache_dir(), function ($cur) use ($plugin_slug) {
            if (empty($cur['enabled'])) return null;
            $cur['declared'] = array_values(array_unique(array_merge($cur['declared'] ?? [], [$plugin_slug])));
            return $cur;
        });
    }
}

/** Plugins known to take over or rewrite the whole page, so a stored copy would differ from what visitors get. Never stored, even if trusted. */
function qp_page_cache_incompatible_plugins(): array {
    $l = ['any-hostname-qlopy'];
    if (function_exists('apply_filters')) { $f = apply_filters('qp_page_cache_incompatible_plugins', $l); if (is_array($f)) $l = array_values(array_map('strval', $f)); }
    return $l;
}

/** Active plugin slugs, from the stored option. */
function qp_page_cache_active_plugins(): array {
    $raw = function_exists('qp_option_raw') ? qp_option_raw('active_plugins') : null;
    $active = $raw ? json_decode($raw, true) : [];
    $out = [];
    if (is_array($active)) foreach ($active as $plugin => $v) {
        $slug = is_string($plugin) && !is_numeric($plugin) ? $plugin : (is_string($v) ? $v : null);
        if ($slug) $out[] = $slug;
    }
    return $out;
}

/** Everything the Settings screen shows. */
function qp_page_cache_status(): array {
    $s = qp_page_cache_state();
    $dir = qp_page_cache_dir();
    $count = 0; $bytes = 0;
    if (!empty($s['gen'])) {
        $base = $dir . DIRECTORY_SEPARATOR . 'pages' . DIRECTORY_SEPARATOR . 'g' . (int)$s['gen'];
        if (is_dir($base)) {
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
            foreach ($it as $fi) { if ($fi->isFile() && substr($fi->getFilename(), -6) === '.cache') { $count++; $bytes += $fi->getSize(); } if ($count >= 5000) break; }
        }
    }
    $plugins = [];
    $bad = qp_page_cache_incompatible_plugins();
    foreach (qp_page_cache_active_plugins() as $slug) {
        $plugins[$slug] = ['declared' => in_array($slug, $s['declared'] ?? [], true), 'trusted' => in_array($slug, $s['trusted_plugins'] ?? [], true), 'incompatible' => in_array($slug, $bad, true)];
    }
    return ['enabled' => !empty($s['enabled']), 'gen' => (int)($s['gen'] ?? 0), 'ttl' => (int)($s['ttl'] ?? 21600), 'device_split' => !empty($s['device_split']), 'purged_at' => (int)($s['purged_at'] ?? 0), 'pages' => $count, 'bytes' => $bytes, 'capped' => $count >= 5000, 'plugins' => $plugins];
}

function qp_page_cache_mark_uncacheable(string $reason): void {
    $GLOBALS['qp_pc_block'][$reason] = true;
}

// ---- purge ----

/** Everything stored becomes unreachable at once (a new generation); old files are removed in the background. */
function qp_page_cache_purge_all(bool $gc = true): void {
    qp_pc_early_loaded();
    $dir = qp_page_cache_dir();
    if (!is_file($dir . DIRECTORY_SEPARATOR . 'state.json')) return;
    qp_pc_state_update($dir, function ($s) { if (empty($s['enabled'])) return null; $s['gen'] = (int)($s['gen'] ?? 1) + 1; $s['purged_at'] = time(); return $s; });
    if ($gc) qp_page_cache_gc(300);
}

/** Remove every stored variant of one URL (any host, cookie or device variant). $url may be a full URL or a site-relative path. */
function qp_page_cache_purge_url(string $url): void {
    qp_pc_early_loaded();
    $dir = qp_page_cache_dir();
    $state = qp_pc_read_state($dir);
    if (empty($state['gen'])) return;
    global $config;
    $cfg = is_array($config ?? null) ? $config : [];
    $p = parse_url($url);
    $path = rawurldecode((string)($p['path'] ?? '/'));
    $base = rtrim((string)(parse_url((string)($cfg['site_url'] ?? ''), PHP_URL_PATH) ?? ''), '/');
    if ($base !== '' && stripos($path, $base) === 0) $path = substr($path, strlen($base));
    $q = []; parse_str((string)($p['query'] ?? ''), $q);
    foreach (QP_PC_IGNORED_QUERY as $k) unset($q[$k]);
    ksort($q);
    $dk = sha1('/' . trim($path, '/') . '?' . http_build_query($q));
    qp_pc_rmdir($dir . DIRECTORY_SEPARATOR . 'pages' . DIRECTORY_SEPARATOR . 'g' . (int)$state['gen'] . DIRECTORY_SEPARATOR . substr($dk, 0, 2) . DIRECTORY_SEPARATOR . $dk);
}

/** Purge a post's own URL. Pages that list or link it (home, archives, menus) are handled by the plugin or by purge_all(). */
function qp_page_cache_purge_post(int $post_id): void {
    if (function_exists('get_permalink')) {
        $u = get_permalink($post_id);
        if (is_string($u) && $u !== '') { qp_page_cache_purge_url($u); return; }
    }
    qp_page_cache_purge_all();
}

function qp_pc_rmdir(string $path, int &$budget = PHP_INT_MAX): void {
    if (!is_dir($path)) return;
    foreach (@scandir($path) ?: [] as $e) {
        if ($e === '.' || $e === '..') continue;
        if ($budget <= 0) return;
        $f = $path . DIRECTORY_SEPARATOR . $e;
        if (is_dir($f)) qp_pc_rmdir($f, $budget); else { @unlink($f); $budget--; }
    }
    if ($budget > 0) @rmdir($path);
}

/** Delete page files of old generations, at most $max files per call (a cron task finishes the rest). Returns files left to remove. */
function qp_page_cache_gc(int $max = 300): int {
    qp_pc_early_loaded();
    $dir = qp_page_cache_dir();
    $state = qp_pc_read_state($dir);
    $keep = 'g' . (int)($state['gen'] ?? 1);
    $pages = $dir . DIRECTORY_SEPARATOR . 'pages';
    $left = 0; $budget = $max;
    foreach (@scandir($pages) ?: [] as $g) {
        if ($g === '.' || $g === '..' || $g === $keep || $g[0] !== 'g') continue;
        qp_pc_rmdir($pages . DIRECTORY_SEPARATOR . $g, $budget);
        if (is_dir($pages . DIRECTORY_SEPARATOR . $g)) $left++;
    }
    return $left;
}

// ---- storing ----

/**
 * Called from index.php right after auth.php, before any plugin or template runs: start the outermost output buffer, whose callback
 * sees the whole page and decides at the end of the request whether it may be stored. (A shutdown function cannot do this reliably:
 * inner buffers and plugin shutdown handlers may still hold or have flushed part of the page when it runs.)
 */
function qp_page_cache_begin(): void {
    if (PHP_SAPI === 'cli') return;
    $state = $GLOBALS['qp_pc_state'] ?? null;
    if (!is_array($state) || !empty($GLOBALS['qp_pc_reason'])) {
        // A bypassed request still says so in a response header, so the owner can see why.
        $r = $GLOBALS['qp_pc_reason'] ?? 'off';
        if (!headers_sent()) header('X-QP-Cache: BYPASS; ' . $r);
        return;
    }
    $GLOBALS['qp_pc_active'] = true;
    ob_start('qp_page_cache_ob');
}

/** Output callback of the outermost buffer: collect the page, and at the very end decide whether to store it. */
function qp_page_cache_ob($chunk, $flags) {
    static $all = '';
    if ($flags & PHP_OUTPUT_HANDLER_CLEAN) {
        if (($flags & PHP_OUTPUT_HANDLER_FINAL) && !empty($GLOBALS['qp_pc_drain_ok'])) {
            $all .= $chunk; // index.php's own shutdown handler takes the page with ob_get_clean() and echoes it unchanged for visitors
        } else {
            $all = '';
            // ob_get_clean() by anyone else (a plugin that rewrites the page): what is stored would differ from what is sent.
            if ($flags & PHP_OUTPUT_HANDLER_FINAL) $GLOBALS['qp_pc_block']['drained'] = true;
        }
    } else {
        $all .= $chunk;
    }
    if ($flags & PHP_OUTPUT_HANDLER_FINAL) {
        try { qp_page_cache_store($all); } catch (Throwable $e) { error_log('qp page cache store failed: ' . $e->getMessage()); }
        $all = '';
    }
    return $chunk;
}

function qp_page_cache_plugins_blocking(array $state): ?string {
    $raw = function_exists('qp_option_raw') ? qp_option_raw('active_plugins') : null;
    $active = $raw ? json_decode($raw, true) : [];
    if (!is_array($active)) return null;
    $trusted = $state['trusted_plugins'] ?? [];
    $bad = qp_page_cache_incompatible_plugins();
    foreach ($active as $plugin => $v) {
        $slug = is_string($plugin) && !is_numeric($plugin) ? $plugin : (is_string($v) ? $v : null);
        if (!$slug) continue;
        if (in_array($slug, $bad, true)) return $slug; // never stored, whatever the owner trusts
        if (!empty($GLOBALS['qp_pc_declared'][$slug]) || in_array($slug, $trusted, true)) continue;
        return $slug;
    }
    return null;
}

/** Store the finished page when everything about it is safe, and say what happened in X-QP-Cache. */
function qp_page_cache_store(string $body): void {
    try {
        $state = $GLOBALS['qp_pc_state'] ?? [];
        $why = array_keys($GLOBALS['qp_pc_block'] ?? []);
        $code = http_response_code();
        if ($code !== 200) $why[] = 'status:' . $code;
        $ct = ''; $cookie = false;
        foreach (headers_list() as $h) {
            if (stripos($h, 'Set-Cookie:') === 0) $cookie = true;
            elseif (stripos($h, 'Content-Type:') === 0) $ct = trim(substr($h, 13));
            elseif (stripos($h, 'Location:') === 0) $why[] = 'redirect';
        }
        if ($cookie) $why[] = 'set-cookie';
        if ($ct !== '' && stripos($ct, 'text/html') !== 0) $why[] = 'type';
        if ($blocker = qp_page_cache_plugins_blocking($state)) $why[] = 'plugin:' . $blocker;
        // Complete means it ends with the closing tag (a trailing comment is allowed): a page cut short by an error is never stored.
        if ($body === '' || !preg_match('~</html>\s*(<!--.*?-->\s*)*$~is', $body)) $why[] = 'incomplete';
        elseif (strlen($body) > 2 * 1024 * 1024) $why[] = 'too-large';
        // Defence in depth: a signed form token in the page means it belongs to one visitor.
        elseif (preg_match('/qp2\.[A-Za-z0-9_-]{20,}\.[A-Za-z0-9_-]{20,}/', $body)) $why[] = 'nonce-in-body';
        if ($why) { if (!headers_sent()) header('X-QP-Cache: BYPASS; ' . implode(',', array_unique($why))); return; }

        global $config;
        $cfg = is_array($config ?? null) ? $config : [];
        qp_pc_early_loaded();
        $dir = qp_pc_dir($cfg);
        [$dk, $fk] = qp_pc_key($state, $cfg);
        $file = qp_pc_file($dir, $state, $dk, $fk);
        $d = dirname($file);
        if (!is_dir($d)) @mkdir($d, 0775, true);
        $etag = '"' . md5($body) . '"';
        $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
        $meta = json_encode(['t' => time(), 'status' => 200, 'ct' => $ct !== '' ? $ct : 'text/html; charset=UTF-8', 'etag' => $etag]);
        if (@file_put_contents($tmp, $meta . "\n" . $body, LOCK_EX) !== false) {
            if (!@rename($tmp, $file)) { @unlink($file); @rename($tmp, $file); }
            @unlink($tmp);
            // At most 20 variants of one URL (cookie and host combinations): beyond that the oldest are dropped.
            $files = glob($d . DIRECTORY_SEPARATOR . '*.cache') ?: [];
            if (count($files) > 20) { usort($files, function ($a, $b) { return filemtime($a) <=> filemtime($b); }); foreach (array_slice($files, 0, count($files) - 20) as $old) @unlink($old); }
            if (!headers_sent()) { header('X-QP-Cache: MISS; stored'); header('ETag: ' . $etag); header('Cache-Control: ' . (string)($state['cache_control'] ?? 'public, max-age=0, must-revalidate')); }
        }
    } catch (Throwable $e) {
        error_log('qp page cache finish failed: ' . $e->getMessage());
    }
}

// ---- automatic purging (step C1e.2) ----
// Any change that can alter what an anonymous visitor sees purges everything. Cheap (one small write: a new generation), and
// correct without knowing which pages list which content (home, archives, menus, widgets, related posts, counts). Plugins that
// know better can purge less with qp_page_cache_purge_url(); core stays on purge-all. Admin POST requests are covered as a whole
// by a safety net in admin/admin_head.php, so a plugin screen that writes its own tables is covered too.

/** Options that change at runtime without changing any page. Everything else purges. Filter: qp_page_cache_ignore_option. */
function qp_page_cache_option_matters(string $name): bool {
    static $ignore = ['qp_cron', 'qp_schema', 'qp_search_mode', 'qp_page_cache', 'cron_', 'qlopy_update', 'qlopy_last', 'qlopy_allow_risky', 'qp_mail_queue', 'qp_mail_settings', 'rewrite_', '_transient'];
    foreach ($ignore as $p) if (strpos($name, $p) === 0) return false;
    if (function_exists('apply_filters') && apply_filters('qp_page_cache_ignore_option', false, $name)) return false;
    return true;
}

/** Purge now (so the next request is a MISS), and again when this request ends (a request that changed several things, or raced with a store). */
function qp_page_cache_on_change(): void {
    static $armed = false;
    qp_page_cache_purge_all(false);
    if (!$armed) {
        $armed = true;
        register_shutdown_function(function () { qp_page_cache_purge_all(true); });
    }
}

if (!function_exists('add_action') && is_file(__DIR__ . '/ajax-hooks.php')) require_once __DIR__ . '/ajax-hooks.php';
if (function_exists('add_action')) {
    $qp_pc_any = function (...$a) { qp_page_cache_on_change(); };
    foreach (['save_post', 'delete_post', 'qp_post_published', 'set_post_thumbnail', 'delete_post_thumbnail',
              'create_term', 'edit_term', 'delete_term', 'comment_inserted', 'comment_status_changed', 'comment_deleted'] as $qp_pc_hook) {
        add_action($qp_pc_hook, $qp_pc_any, 10, 0);
    }
    add_action('qp_option_updated', function ($name = '', $value = null) {
        if (is_string($name) && qp_page_cache_option_matters($name)) qp_page_cache_on_change();
    }, 10, 2);
    // Cleanup task: remove files of old generations in slices.
    add_action('qp_page_cache_gc_task', function () { qp_page_cache_gc(500); }, 10, 0);
    unset($qp_pc_any, $qp_pc_hook);
}
