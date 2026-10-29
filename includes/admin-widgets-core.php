<?php
if (!defined('QLOPY_INIT')) { http_response_code(403); exit; }
// includes/admin-widgets-core.php
// The widgets core puts on the admin Dashboard (C6, part B). Loaded the first time the dashboard registry is read.
// Plugins add their own with register_admin_widget(); `welcome` is the only one an owner is meant to remove for good.

function qp_dash_h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function qp_dash_count_dirs(string $dir): int {
    if (!is_dir($dir)) return 0;
    $n = 0;
    foreach (scandir($dir) as $d) if ($d[0] !== '.' && is_dir($dir . $d)) $n++;
    return $n;
}

register_admin_widget('at_a_glance', [
    'title' => 'At a glance', 'column' => 1, 'priority' => 'high', 'order' => 1, 'removable' => false,
    'callback' => function (array $s) {
        $pdo = db();
        $by = [];
        foreach ($pdo->query("SELECT post_type, COUNT(*) c FROM " . table_name('posts') . " WHERE status = 'published' GROUP BY post_type")->fetchAll(PDO::FETCH_ASSOC) as $r) $by[$r['post_type']] = (int)$r['c'];
        $types = function_exists('get_post_types') ? get_post_types() : [];
        echo '<ul class="list-unstyled mb-2">';
        foreach ($by as $pt => $c) {
            if ($pt === 'attachment') continue;
            $label = $types[$pt]['label'] ?? ucfirst($pt);
            echo '<li><a href="posts.php?post_type=' . rawurlencode($pt) . '"><strong>' . $c . '</strong> ' . qp_dash_h($label) . '</a></li>';
        }
        echo '</ul>';
        $pending = (int)$pdo->query("SELECT COUNT(*) FROM " . table_name('comments') . " WHERE status = 'pending'")->fetchColumn();
        echo '<p class="mb-1"><a href="comments.php"><strong>' . $pending . '</strong> comment' . ($pending === 1 ? '' : 's') . ' awaiting moderation</a></p>';
        global $plugin_dir, $theme_dir_root, $active_plugins, $active_theme;
        $np = is_array($active_plugins ?? null) ? count($active_plugins) : 0;
        echo '<p class="mb-0 text-muted small">' . $np . ' active plugin' . ($np === 1 ? '' : 's') . ' of ' . qp_dash_count_dirs((string)$plugin_dir) . ' installed · ' . qp_dash_count_dirs((string)$theme_dir_root) . ' themes, using <a href="themes.php">' . qp_dash_h($active_theme ?? 'default') . '</a>';
        if (defined('QLOPY_VERSION')) echo ' · Qlopy ' . qp_dash_h(QLOPY_VERSION);
        echo '</p>';
    },
]);

register_admin_widget('activity', [
    'title' => 'Recent activity', 'column' => 3, 'priority' => 'core', 'order' => 1, 'lazy' => true, 'cache' => 30,
    'callback' => function (array $s) {
        $pdo = db();
        $posts = $pdo->query("SELECT id, title, post_type, status, COALESCE(published_at, created_at) AS at FROM " . table_name('posts') . " WHERE post_type <> 'attachment' AND status <> 'trash' ORDER BY updated_at DESC, id DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
        echo '<h3 class="h6">Latest content</h3>';
        if (!$posts) echo '<p class="text-muted small">Nothing yet.</p>';
        else {
            echo '<ul class="list-unstyled small">';
            foreach ($posts as $p) echo '<li><a href="posts.php?post_type=' . rawurlencode($p['post_type']) . '&amp;action=edit&amp;id=' . (int)$p['id'] . '">' . qp_dash_h($p['title'] ?: '(no title)') . '</a> <span class="text-muted">' . qp_dash_h($p['status']) . ' · ' . qp_dash_h(date('M j', strtotime((string)$p['at']))) . '</span></li>';
            echo '</ul>';
        }
        $cm = $pdo->query("SELECT id, author_name, content, status, created_at FROM " . table_name('comments') . " WHERE status IN ('approved','pending') ORDER BY created_at DESC, id DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
        echo '<h3 class="h6 mt-3">Latest comments</h3>';
        if (!$cm) echo '<p class="text-muted small mb-0">No comments yet.</p>';
        else {
            echo '<ul class="list-unstyled small mb-0">';
            foreach ($cm as $c) echo '<li><strong>' . qp_dash_h($c['author_name'] ?: 'Someone') . '</strong>: ' . qp_dash_h(mb_strimwidth(strip_tags((string)$c['content']), 0, 80, '…')) . ($c['status'] === 'pending' ? ' <span class="badge badge-warning">pending</span>' : '') . '</li>';
            echo '</ul>';
        }
    },
]);

register_admin_widget('site_health', [
    'title' => 'Site health', 'column' => 2, 'priority' => 'high', 'order' => 1, 'removable' => false, 'lazy' => true, 'cache' => 60,
    'callback' => function (array $s) {
        $rows = [];
        $add = function ($status, $label, $detail = '') use (&$rows) { $rows[] = [$status, $label, $detail]; };
        $add(version_compare(PHP_VERSION, '8.0.0', '>=') ? 'ok' : 'bad', 'PHP ' . PHP_VERSION, version_compare(PHP_VERSION, '8.0.0', '>=') ? '' : 'Qlopy needs PHP 8.0 or newer.');
        try { $add('ok', 'Database', (string)db()->query('SELECT VERSION()')->fetchColumn()); } catch (Throwable $e) { $add('bad', 'Database', 'Cannot query the database.'); }
        $cfg = $GLOBALS['config'] ?? [];
        $root = dirname(__DIR__) . '/';
        foreach ([[$cfg['uploads_dir'] ?? 'uploads', 'Uploads folder'], [$cfg['content_dir'] ?? 'content', 'Content folder']] as [$d, $lbl]) {
            $add(is_writable($root . $d) ? 'ok' : 'bad', $lbl . ' writable', is_writable($root . $d) ? '' : 'Updates, uploads and caches need to write here.');
        }
        $https = defined('SITE_URL') && stripos(SITE_URL, 'https://') === 0;
        $add($https ? 'ok' : 'warn', 'HTTPS', $https ? '' : 'The site address does not use https.');
        // Scheduled tasks (C2)
        if (function_exists('qp_cron_mode')) {
            $mode = qp_cron_mode();
            $due = function_exists('qp_cron_next_due') ? qp_cron_next_due() : null;
            $st = function_exists('qp_cron_state') ? qp_cron_state() : [];
            $runnerFresh = !empty($st['runner_at']) && (time() - (int)$st['runner_at']) < 900;
            if ($runnerFresh && ($mode === 'off' || $mode === 'ping')) $add('ok', 'Scheduled tasks', 'Run by ' . (string)($st['runner'] ?? 'server crontab') . ' (last run ' . max(0, (int)((time() - (int)$st['runner_at']) / 60)) . ' min ago)');
            elseif ($mode === 'off') $add('warn', 'Scheduled tasks', 'Switched off in Settings and no server crontab has reported in for 15 minutes: scheduled posts and queued mail will not run.');
            elseif ($due !== null && $due < time() - 900) $add('warn', 'Scheduled tasks', 'Overdue by ' . max(1, (int)((time() - $due) / 60)) . ' minutes. See Settings > Cron.');
            else $add('ok', 'Scheduled tasks', 'Mode: ' . $mode);
        }
        // Page cache (C1)
        $pc = function_exists('qp_page_cache_state') ? qp_page_cache_state() : null;
        $add('ok', 'Page cache', !empty($pc['enabled']) ? 'On' : 'Off (you can turn it on in Settings > General)');
        // Updates: the last update that did not finish (kept as one small record; see Settings > Updates)
        $uf = get_option_meta('qlopy_last_update_failure');
        if (is_array($uf) && !empty($uf['code']) && (int)($uf['time'] ?? 0) > time() - 7 * 86400) $add('warn', 'Updates', 'The last update did not finish (' . $uf['code'] . '). See Settings > Updates.');
        // Plugins that are listed as active but whose files are gone
        global $plugin_dir, $active_plugins;
        $missing = [];
        foreach (is_array($active_plugins ?? null) ? array_keys($active_plugins) : [] as $pl) if (!is_file(rtrim((string)$plugin_dir, '/\\') . '/' . $pl . '/plugin.php')) $missing[] = $pl;
        $add($missing ? 'warn' : 'ok', 'Plugins', $missing ? 'Missing files: ' . implode(', ', $missing) : 'All active plugins are present.');
        $icon = ['ok' => '&#10003;', 'warn' => '&#9888;', 'bad' => '&#10005;'];
        $cls = ['ok' => 'text-success', 'warn' => 'text-warning', 'bad' => 'text-danger'];
        echo '<ul class="list-unstyled mb-0 small">';
        foreach ($rows as [$st, $lbl, $det]) echo '<li><span class="' . $cls[$st] . '" aria-hidden="true">' . $icon[$st] . '</span> <span class="sr-only">' . $st . ':</span> <strong>' . qp_dash_h($lbl) . '</strong>' . ($det !== '' ? ' <span class="text-muted">' . qp_dash_h($det) . '</span>' : '') . '</li>';
        echo '</ul>';
    },
]);

register_admin_widget('welcome', [
    'title' => 'Welcome', 'display' => 'banner', 'span' => 'full', 'priority' => 'high', 'order' => 0,
    'callback' => function (array $s) {
        $site = function_exists('get_option_meta') ? (get_option_meta('site_name') ?: 'your site') : 'your site';
        echo '<div class="qpw-welcome"><div class="qpw-welcome-lead"><p class="qpw-kicker">Getting started</p><h2>Welcome to ' . qp_dash_h($site) . ' <small class="qpw-powered">powered by Qlopy' . (defined('QLOPY_VERSION') ? ' <span class="qpw-ver">v' . qp_dash_h(QLOPY_VERSION) . '</span>' : '') . '</small></h2><p>Three quick steps, then this panel is out of your way. Dismiss it any time; Screen options brings it back.</p></div>';
        echo '<ol class="qpw-steps"><li><a href="posts.php?post_type=post"><b>1</b><span>Write your first post</span></a></li><li><a href="menus.php"><b>2</b><span>Build your menu</span></a></li><li><a href="widgets.php"><b>3</b><span>Fill your sidebars</span></a></li></ol></div>';
    },
]);
