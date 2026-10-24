<?php
require_once __DIR__ . '/admin_head.php';

$page_title = 'General Settings';

if ( !current_user_can('manage_options')) {
    http_response_code(403);
    require_once __DIR__ . '/inc/header.php';
    require_once __DIR__ . '/inc/navbar.php';
    echo '<div class="container"><h1>Permission Denied</h1><p>You do not have permission to access this admin page.</p></div>';
    include __DIR__ . '/inc/footer.php';
    exit;
}
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/inc/header.php';
require_once __DIR__ . '/inc/navbar.php';

// Fetch all pages (post_type 'page') for static page front option
$stmt_pages = $pdo->prepare("SELECT id, title FROM " . table_name('posts') . " WHERE post_type='page' ORDER BY title");
$stmt_pages->execute();
$pages = $stmt_pages->fetchAll(PDO::FETCH_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $site_name = trim($_POST['site_name'] ?? '');
    $site_tagline = trim($_POST['site_tagline'] ?? '');
    $front_page_option = $_POST['front_page_option'] ?? 'theme'; // default 'theme'
    $front_page_id = intval($_POST['front_page_id'] ?? 0);
    $menu_max_depth = intval($_POST['menu_max_depth'] ?? 2);
    $assets_cache_ttl = intval($_POST['assets_cache_ttl'] ?? 0);
    $cache_backend = $_POST['cache_backend'] ?? 'auto';
    if (!in_array($cache_backend, ['auto', 'redis', 'memcached', 'apcu', 'file', 'none'], true)) $cache_backend = 'auto';

    // Validate selected page for static front page
    if ($front_page_option === 'static' && $front_page_id > 0) {
        $stmt_check = $pdo->prepare("SELECT COUNT(*) FROM " . table_name('posts') . " WHERE id = ? AND post_type='page'");
        $stmt_check->execute([$front_page_id]);
        if ($stmt_check->fetchColumn() == 0) {
            $front_page_id = 0; // reset if invalid
        }
    } else {
        $front_page_id = 0; // reset if not static
    }

    $options = [
        'site_name'          => $site_name,
        'site_tagline'       => $site_tagline,
        'front_page_option'  => $front_page_option,
        'front_page_id' => $front_page_id,
        'menu_max_depth'     => $menu_max_depth,
        'assets_cache_ttl'   => $assets_cache_ttl,
        'cache_backend'      => $cache_backend,
        // timezone & display formats
        'timezone_string'    => trim($_POST['timezone_string'] ?? ''),
        'date_format'        => trim($_POST['date_format'] ?? ''),
        'time_format'        => trim($_POST['time_format'] ?? ''),
        'week_starts_on'     => intval($_POST['week_starts_on'] ?? 1),
    ];

    foreach ($options as $name => $value) {
        $stmt = $pdo->prepare("INSERT INTO " . table_name('site_options') . " (option_name, option_value) VALUES (?, ?) 
                       ON DUPLICATE KEY UPDATE option_value=VALUES(option_value)");
        $stmt->execute([$name, $value]);
    }
    // Search (C1d): the mode is only a choice; the query falls back to LIKE until the index exists.
    $search_mode = ($_POST['search_mode'] ?? 'like') === 'fulltext' ? 'fulltext' : 'like';
    update_option_meta('qp_search_mode', $search_mode);
    // Login sessions: how long a login lasts after the last visit, "remember me", and logins kept per user.
    update_option_meta('qp_auth_settings', [
        'session_ttl'  => max(1, min(30, (int)($_POST['auth_session_days'] ?? 2))) * 86400,
        'remember_ttl' => max(1, min(365, (int)($_POST['auth_remember_days'] ?? 14))) * 86400,
        'max_tokens'   => max(1, min(100, (int)($_POST['auth_max_tokens'] ?? 10))),
    ]);
    // Scheduled tasks (C2): how due work gets run on this site.
    $cron_mode_post = $_POST['qp_cron_mode'] ?? 'worker';
    update_option_meta('qp_cron_mode', in_array($cron_mode_post, ['worker', 'after_response', 'ping', 'off'], true) ? $cron_mode_post : 'worker');
    update_option_meta('qp_cron_heavy_in_worker', !empty($_POST['qp_cron_heavy_in_worker']));
    if (function_exists('qp_cron_recompute_next_due')) { try { qp_cron_recompute_next_due(); } catch (Throwable $_) {} }
    // Page cache (C1e): switch, lifetime, device split, plugins the owner trusts, purge now.
    $pc_note = '';
    if (isset($_POST['qp_pc_form'])) {
        require_once __DIR__ . '/../includes/page-cache.php';
        $pc_trust = array_values(array_intersect(qp_page_cache_active_plugins(), array_map('strval', (array)($_POST['qp_pc_trust'] ?? []))));
        if (!empty($_POST['qp_pc_enable'])) {
            $pc_ttl = max(1, min(1440, (int)($_POST['qp_pc_ttl_min'] ?? 360))) * 60;
            qp_page_cache_enable(true, ['ttl' => $pc_ttl, 'device_split' => !empty($_POST['qp_pc_device']), 'trusted_plugins' => $pc_trust]);
            $pc_note = 'Page cache is on.';
        } else {
            qp_page_cache_enable(false);
            $pc_note = 'Page cache is off.';
        }
        if (!empty($_POST['qp_pc_purge'])) { qp_page_cache_purge_all(); $pc_note .= ' All cached pages were cleared.'; }
    }
    echo '<div class="alert alert-success mt-3">Settings saved.' . (!empty($pc_note) ? ' ' . htmlspecialchars($pc_note) : '') . '</div>';
    if (!empty($_POST['build_search_index'])) {
        require_once __DIR__ . '/../includes/schema.php';
        $ft = qp_schema_add_posts_fulltext_index();
        $ft_ok = in_array($ft['status'], ['created', 'exists'], true);
        echo '<div class="alert alert-' . ($ft_ok ? 'success' : 'warning') . ' mt-2">Search index: ' . htmlspecialchars($ft['status'] . ($ft['reason'] !== '' ? ' - ' . $ft['reason'] : '')) . '</div>';
    }

    if (!empty($_POST['build_meta_index'])) {
        require_once __DIR__ . '/../includes/schema.php';
        $mi = qp_schema_add_post_meta_index();
        $mi_ok = in_array($mi['status'], ['created', 'exists'], true);
        echo '<div class="alert alert-' . ($mi_ok ? 'success' : 'warning') . ' mt-2">Post meta index: ' . htmlspecialchars($mi['status'] . ($mi['reason'] !== '' ? ' - ' . $mi['reason'] : '')) . '</div>';
    }

    // Friendly notice if user requested deeper-than-default menus (3 or more)
    if ($menu_max_depth > 2) {
        echo '<div class="alert alert-warning mt-2">';
        echo 'You set Menu Max Depth to ' . htmlspecialchars($menu_max_depth) . '. Note: Bootstrap 4 and 5 do not provide full multi-level dropdown support out-of-the-box beyond two levels. For deeper menus consider using the <strong>Simple</strong> or <strong>Plain</strong> framework option, or adjust your theme to handle deep menus.';
        echo '</div>';
    }
}

function get_site_option($pdo, $name, $default='') {
    $stmt = $pdo->prepare("SELECT option_value FROM " . table_name('site_options') . " WHERE option_name = ? LIMIT 1");
    $stmt->execute([$name]);
    $val = $stmt->fetchColumn();
    return $val !== false ? $val : $default;
}

$site_name = get_site_option($pdo, 'site_name', 'My Qlopy');
$site_tagline = get_site_option($pdo, 'site_tagline', '');
$front_page_option = get_site_option($pdo, 'front_page_option', 'theme');
$front_page_id = intval(get_site_option($pdo, 'front_page_id', 0));
$menu_max_depth = intval(get_site_option($pdo, 'menu_max_depth', 2));
$assets_cache_ttl = intval(get_site_option($pdo, 'assets_cache_ttl', ''));
$cache_backend = trim((string)get_site_option($pdo, 'cache_backend', 'auto'), " \t\n\r\0\x0B\"");
$cache_active = function_exists('qp_cache_backend') ? (qp_cache_backend()['type'] ?? 'none') : 'none';
$search_mode = get_option_meta('qp_search_mode') === 'fulltext' ? 'fulltext' : 'like';
$ft_state = get_option_meta('qp_schema_posts_fulltext');
$ft_state = is_array($ft_state) ? $ft_state : [];
$ft_built = in_array($ft_state['status'] ?? '', ['created', 'exists'], true);
require_once __DIR__ . '/../includes/schema.php';
$mi_state = get_option_meta('qp_schema_post_meta_index');
$mi_state = is_array($mi_state) ? $mi_state : [];
$mi_built = qp_schema_index_exists(table_name('post_meta'), 'post_meta_key_post');
$cron_mode = function_exists('qp_cron_mode') ? qp_cron_mode() : 'worker';
$cron_heavy = function_exists('qp_cron_worker_runs_heavy') && qp_cron_worker_runs_heavy();
$cron_state = function_exists('qp_cron_state') ? qp_cron_state() : [];
$cron_fpm = function_exists('fastcgi_finish_request');
// New settings
$site_timezone = get_site_option($pdo, 'timezone_string', 'UTC');
$site_date_format = get_site_option($pdo, 'date_format', 'F j, Y');
$site_time_format = get_site_option($pdo, 'time_format', 'g:i a');
$site_week_start = intval(get_site_option($pdo, 'week_starts_on', 1));
?>

<div class="container mt-4">
<h2>General Settings</h2>
<form method="POST" novalidate>
    <div class="form-group">
        <label for="site_name">Site Name</label>
        <input id="site_name" name="site_name" type="text" class="form-control" value="<?= htmlspecialchars($site_name) ?>" required>
    </div>

    <div class="form-group">
        <label for="site_tagline">Site Tagline</label>
        <input id="site_tagline" name="site_tagline" type="text" class="form-control" value="<?= htmlspecialchars($site_tagline) ?>">
    </div>

    <!-- Permalink Structure removed: managed via dedicated Permalinks settings -->

    <div class="form-group">
        <label for="front_page_option">Front Page Option</label>
        <select id="front_page_option" name="front_page_option" class="form-control" onchange="togglePageSelector()">
            <option value="theme" <?= $front_page_option === 'theme' ? 'selected' : '' ?>>Theme Index Page</option>
            <option value="archive" <?= $front_page_option === 'archive' ? 'selected' : '' ?>>Archive Page</option>
            <option value="static" <?= $front_page_option === 'static' ? 'selected' : '' ?>>Static Page</option>
        </select>
    </div>

    <div class="form-group">
        <label for="menu_max_depth">Menu Max Depth</label>
        <input id="menu_max_depth" name="menu_max_depth" type="number" min="0" max="10" class="form-control" value="<?= htmlspecialchars($menu_max_depth) ?>">
        <small class="form-text text-muted">Maximum nested menu levels to render. Themes/plugins can still override via the <code>menu_max_depth</code> filter.</small>
    </div>

    <div class="form-group" id="pageSelectContainer">
        <label for="front_page_id">Select Page for Front Page</label>
        <select id="front_page_id" name="front_page_id" class="form-control" <?= $front_page_option !== 'static' ? 'disabled' : '' ?>>
            <option value="0">-- Select a Page --</option>
            <?php foreach ($pages as $page): ?>
                <option value="<?= $page['id'] ?>" <?= $front_page_id === intval($page['id']) ? 'selected' : '' ?>>
                    <?= htmlspecialchars($page['title']) ?>
                </option>
            <?php endforeach ?>
        </select>
    </div>

    <div class="form-group">
        <label for="assets_cache_ttl">Assets Cache TTL (seconds)</label>
        <input id="assets_cache_ttl" name="assets_cache_ttl" type="number" min="0" class="form-control" value="<?= htmlspecialchars($assets_cache_ttl) ?>">
        <small class="form-text text-muted">Server-side cache TTL for generated asset HTML. Set to 0 to disable caching. Default: 300s (short). In production you may want a larger value.</small>
    </div>

    <div class="form-group">
        <label for="cache_backend">Object Cache</label>
        <select id="cache_backend" name="cache_backend" class="form-control">
            <?php
            $cache_choices = ['auto' => 'Automatic (Redis, Memcached or APCu if the server answers)', 'redis' => 'Redis', 'memcached' => 'Memcached', 'apcu' => 'APCu', 'file' => 'Files in uploads/cache (works on any host)', 'none' => 'Off'];
            foreach ($cache_choices as $val => $label) {
                echo '<option value="' . $val . '"' . ($cache_backend === $val ? ' selected' : '') . '>' . htmlspecialchars($label) . '</option>';
            }
            ?>
        </select>
        <small class="form-text text-muted">Currently using: <strong><?= htmlspecialchars($cache_active) ?></strong>. If the chosen server does not answer, the cache is switched off instead of breaking the site. Choose Files on shared hosting without Redis, Memcached or APCu. <code>session_cache</code> in config.php overrides this.</small>
    </div>

    <div class="form-group">
        <label for="search_mode">Site Search</label>
        <select id="search_mode" name="search_mode" class="form-control">
            <option value="like"<?= $search_mode === 'like' ? ' selected' : '' ?>>Standard (finds text anywhere, slower on large catalogues)</option>
            <option value="fulltext"<?= $search_mode === 'fulltext' ? ' selected' : '' ?>>Fast index (whole words and word starts)</option>
        </select>
        <small class="form-text text-muted">
            Index status: <strong><?= $ft_built ? 'built' : htmlspecialchars(($ft_state['status'] ?? 'not built') . (!empty($ft_state['reason']) ? ' - ' . $ft_state['reason'] : '')) ?></strong>.
            Fast index looks words up in a database index instead of reading every post, so it stays quick with tens of thousands of products. It finds "shoes" when you type "shoe" but not "shoe" inside "horseshoe". Words under 3 letters and very common words still use the standard search. It is used only once the index is built.
        </small>
        <?php if (!$ft_built): ?>
        <div class="form-check mt-2">
            <input class="form-check-input" type="checkbox" id="build_search_index" name="build_search_index" value="1">
            <label class="form-check-label" for="build_search_index">Build the search index when I save (writes to posts wait a few seconds on large sites; best done at a quiet time)</label>
        </div>
        <?php endif; ?>
    </div>

    <div class="form-group">
        <label>Database index for custom fields</label>
        <div>Status: <strong><?= $mi_built ? 'built' : htmlspecialchars(($mi_state['status'] ?? 'not built') . (!empty($mi_state['reason']) ? ' - ' . $mi_state['reason'] : '')) ?></strong></div>
        <small class="form-text text-muted">An index on <code>post_meta (meta_key, post_id)</code> makes lookups by custom field name (prices, stock, product data) much faster. It is added while the site keeps running, and skipped, with the reason shown here, on tables that would lock or are very large. The site works the same without it.</small>
        <?php if (!$mi_built): ?>
        <div class="form-check mt-2">
            <input class="form-check-input" type="checkbox" id="build_meta_index" name="build_meta_index" value="1">
            <label class="form-check-label" for="build_meta_index">Build the index when I save</label>
        </div>
        <?php endif; ?>
    </div>

    <div class="form-group">
        <label for="qp_cron_mode">Scheduled Tasks</label>
        <select id="qp_cron_mode" name="qp_cron_mode" class="form-control">
            <?php
            $cron_choices = [
                'worker' => 'Built-in worker (recommended): runs due tasks in the background after a page has been sent',
                'after_response' => 'After the page only: a short 2-second run in the same request (for hosts that block loopback requests)',
                'ping' => 'External ping only: nothing runs on page loads; a crontab or web-cron service calls the runner',
                'off' => 'Off: nothing runs on page loads; run qp-cron.php from a server crontab',
            ];
            foreach ($cron_choices as $val => $label) {
                echo '<option value="' . $val . '"' . ($cron_mode === $val ? ' selected' : '') . '>' . htmlspecialchars($label) . '</option>';
            }
            ?>
        </select>
        <small class="form-text text-muted">
            Scheduled posts, queued mail and plugin jobs need something to run them. With a working crontab on <code>qp-cron.php</code> the built-in worker stays quiet by itself.
            This server: <strong><?= $cron_fpm ? 'can send the page first (PHP-FPM)' : 'cannot send the page first (not PHP-FPM): a run keeps the connection open for up to about 2 seconds, at most once every 30 seconds, only when something is due' ?></strong>.
            <?php if (!empty($cron_state['last_slice'])): ?>Last built-in run: <?= htmlspecialchars(format_site_datetime((int)$cron_state['last_slice']['at'])) ?> (<?= (int)$cron_state['last_slice']['tasks'] ?> tasks, <?= (int)$cron_state['last_slice']['posts'] ?> posts, <?= (int)$cron_state['last_slice']['mail'] ?> mails).<?php else: ?>The built-in worker has not run yet.<?php endif; ?>
            <?php if (isset($cron_state['loopback_ok']) && $cron_state['loopback_ok'] === false): ?><span class="text-warning"> The site could not call itself (loopback); it runs the short in-request slice instead.</span><?php endif; ?>
            See the <a href="cron.php">Cron screen</a> for the queue.
        </small>
        <div class="form-check mt-2">
            <input class="form-check-input" type="checkbox" id="qp_cron_heavy_in_worker" name="qp_cron_heavy_in_worker" value="1"<?= $cron_heavy ? ' checked' : '' ?>>
            <label class="form-check-label" for="qp_cron_heavy_in_worker">Also run heavy tasks (updates, imports, anything slow) from the built-in worker. Off by default so visitors never wait for them.</label>
        </div>
    </div>

    <?php
    require_once __DIR__ . '/../includes/page-cache.php';
    $pc = qp_page_cache_status();
    $pc_blockers = [];
    foreach ($pc['plugins'] as $pc_slug => $pc_p) {
        if ($pc_p['incompatible']) $pc_blockers[] = $pc_slug . ' (rewrites the whole page: pages are never stored while it is active)';
        elseif (!$pc_p['declared'] && !$pc_p['trusted']) $pc_blockers[] = $pc_slug . ' (not confirmed safe: pages are not stored until you trust it below)';
    }
    ?>
    <div class="form-group">
        <input type="hidden" name="qp_pc_form" value="1">
        <label>Page Cache for Visitors</label>
        <div class="form-check">
            <input class="form-check-input" type="checkbox" id="qp_pc_enable" name="qp_pc_enable" value="1"<?= $pc['enabled'] ? ' checked' : '' ?>>
            <label class="form-check-label" for="qp_pc_enable">Keep a finished copy of public pages for visitors who are not logged in (off by default)</label>
        </div>
        <small class="form-text text-muted">
            A cached page is served in a few milliseconds without loading the theme or plugins. Never cached: logged-in users, carts, searches, previews, forms that carry a security token, errors and any page that sets a cookie.
            Everything is cleared automatically when content, settings, the theme or plugins change, and after any save in the admin.
            Response header <code>X-QP-Cache</code> says HIT, MISS or BYPASS and why.
            Status: <strong><?= $pc['enabled'] ? 'on' : 'off' ?></strong><?php if ($pc['enabled']): ?>, <?= (int)$pc['pages'] ?><?= $pc['capped'] ? '+' : '' ?> pages stored (<?= round($pc['bytes'] / 1024) ?> KB)<?= $pc['purged_at'] ? ', last cleared ' . htmlspecialchars(format_site_datetime($pc['purged_at'])) : '' ?><?php endif; ?>.
        </small>
        <div class="form-row mt-2">
            <div class="col-auto">
                <label for="qp_pc_ttl_min" class="small mb-0">Keep a page for at most (minutes)</label>
                <input type="number" min="1" max="1440" class="form-control form-control-sm" id="qp_pc_ttl_min" name="qp_pc_ttl_min" value="<?= (int)round($pc['ttl'] / 60) ?>" style="width:110px">
            </div>
            <div class="col-auto pt-4">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="qp_pc_device" name="qp_pc_device" value="1"<?= $pc['device_split'] ? ' checked' : '' ?>>
                    <label class="form-check-label small" for="qp_pc_device">Keep separate copies for mobile and desktop</label>
                </div>
            </div>
        </div>
        <?php if ($pc['plugins']): ?>
        <div class="mt-2">
            <div class="small"><strong>Active plugins.</strong> A page is stored only if every active plugin says its pages are safe to cache, or you trust it here. Trust a plugin only if its pages look the same for every visitor and it clears the cache when what it shows changes (prices, stock, availability).</div>
            <table class="table table-sm mt-1" style="max-width:700px;">
                <?php foreach ($pc['plugins'] as $pc_slug => $pc_p): ?>
                <tr>
                    <td><?= htmlspecialchars($pc_slug) ?></td>
                    <td>
                        <?php if ($pc_p['incompatible']): ?><span class="text-danger small">cannot be combined with the cache</span>
                        <?php elseif ($pc_p['declared']): ?><span class="text-success small">says it is safe</span>
                        <?php else: ?>
                            <label class="small mb-0"><input type="checkbox" name="qp_pc_trust[]" value="<?= htmlspecialchars($pc_slug) ?>"<?= $pc_p['trusted'] ? ' checked' : '' ?>> I trust this plugin with cached pages</label>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </table>
        </div>
        <?php endif; ?>
        <?php if ($pc['enabled'] && $pc_blockers): ?>
            <div class="alert alert-warning small mt-2 mb-2">Right now pages are not being stored because of: <?= htmlspecialchars(implode('; ', $pc_blockers)) ?>.</div>
        <?php endif; ?>
        <div class="form-check mt-1">
            <input class="form-check-input" type="checkbox" id="qp_pc_purge" name="qp_pc_purge" value="1">
            <label class="form-check-label small" for="qp_pc_purge">Clear all cached pages when I save</label>
        </div>
    </div>

    <?php $opcache_on = extension_loaded('Zend OPcache') && filter_var(ini_get('opcache.enable'), FILTER_VALIDATE_BOOLEAN); ?>
    <div class="form-group">
        <label>PHP OPcache</label>
        <div><strong><?= $opcache_on ? 'On' : 'Off' ?></strong></div>
        <small class="form-text text-muted"><?= $opcache_on
            ? 'PHP keeps the compiled core in memory, so each page skips re-reading the source files.'
            : 'OPcache is off on this server, so PHP re-reads and re-compiles about 600 KB of core code on every page (measured at about 12 ms of a 37 ms page). Turn it on in php.ini (<code>zend_extension=opcache</code> and <code>opcache.enable=1</code>) or ask your host. This is a server setting; the site cannot change it.' ?></small>
    </div>

    <h4 class="mt-4">Localization & Date/Time</h4>
    <div class="form-group">
        <label for="timezone_string">Timezone</label>
        <select id="timezone_string" name="timezone_string" class="form-control">
            <?php
            $zones = DateTimeZone::listIdentifiers();
            foreach ($zones as $z) {
                $sel = ($z === $site_timezone) ? 'selected' : '';
                echo '<option value="' . htmlspecialchars($z) . '" ' . $sel . '>' . htmlspecialchars($z) . '</option>';
            }
            ?>
        </select>
        <small class="form-text text-muted">Choose either a city in the same timezone as you or a UTC (Coordinated Universal Time) offset.</small>
        <div class="mt-2">Universal time is : <span id="tzPreview"></span></div>
    </div>

    <div class="form-group">
        <label>Date Format</label>
        <?php
        $date_choices = [
            'F j, Y' => date('F j, Y'),
            'Y-m-d' => date('Y-m-d'),
            'm/d/Y' => date('m/d/Y'),
            'd/m/Y' => date('d/m/Y'),
            'd.m.Y' => date('d.m.Y'),
        ];
        foreach ($date_choices as $fmt => $example) {
            $checked = ($fmt === $site_date_format) ? 'checked' : '';
            echo '<div class="form-check">';
            echo '<input class="form-check-input" type="radio" name="date_format" id="date_' . md5($fmt) . '" value="' . htmlspecialchars($fmt) . '" ' . $checked . '>';
            echo '<label class="form-check-label" for="date_' . md5($fmt) . '">' . htmlspecialchars($example) . ' <small class="text-muted">' . htmlspecialchars($fmt) . '</small></label>';
            echo '</div>';
        }
        $custom_date = !in_array($site_date_format, array_keys($date_choices));
        echo '<div class="form-check mt-2"><input class="form-check-input" type="radio" name="date_format" id="date_custom" value="' . htmlspecialchars($site_date_format) . '" ' . ($custom_date ? 'checked' : '') . '><label class="form-check-label" for="date_custom">Custom:</label> <input type="text" name="date_format_custom" value="' . ($custom_date ? htmlspecialchars($site_date_format) : '') . '" class="form-control" style="width:220px;display:inline-block;margin-left:8px;"> </div>';
        ?>
        <div class="mt-1"><small class="form-text text-muted">Preview: <span id="datePreview"></span></small></div>
    </div>

    <div class="form-group">
        <label>Time Format</label>
        <?php
        $time_choices = [
            'g:i a' => date('g:i a'),
            'g:i A' => date('g:i A'),
            'H:i' => date('H:i'),
        ];
        foreach ($time_choices as $fmt => $example) {
            $checked = ($fmt === $site_time_format) ? 'checked' : '';
            echo '<div class="form-check">';
            echo '<input class="form-check-input" type="radio" name="time_format" id="time_' . md5($fmt) . '" value="' . htmlspecialchars($fmt) . '" ' . $checked . '>';
            echo '<label class="form-check-label" for="time_' . md5($fmt) . '">' . htmlspecialchars($example) . ' <small class="text-muted">' . htmlspecialchars($fmt) . '</small></label>';
            echo '</div>';
        }
        $custom_time = !in_array($site_time_format, array_keys($time_choices));
        echo '<div class="form-check mt-2"><input class="form-check-input" type="radio" name="time_format" id="time_custom" value="' . htmlspecialchars($site_time_format) . '" ' . ($custom_time ? 'checked' : '') . '><label class="form-check-label" for="time_custom">Custom:</label> <input type="text" name="time_format_custom" value="' . ($custom_time ? htmlspecialchars($site_time_format) : '') . '" class="form-control" style="width:220px;display:inline-block;margin-left:8px;"> </div>';
        ?>
        <div class="mt-1"><small class="form-text text-muted">Preview: <span id="timePreview"></span></small></div>
    </div>

    <div class="form-group">
        <label for="week_starts_on">Week Starts On</label>
        <select id="week_starts_on" name="week_starts_on" class="form-control" style="width:auto;display:inline-block;">
            <?php
            $days = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
            for ($i=0;$i<7;$i++) {
                $s = ($i === $site_week_start) ? 'selected' : '';
                echo '<option value="' . $i . '" ' . $s . '>' . $days[$i] . '</option>';
            }
            ?>
        </select>
    </div>

    <?php $qp_auth_cfg = qp_auth_settings(); ?>
    <h4 class="mt-4">Login sessions</h4>
    <div class="form-row">
        <div class="form-group col-md-4">
            <label for="auth_session_days">Stay signed in for (days)</label>
            <input type="number" min="1" max="30" class="form-control" id="auth_session_days" name="auth_session_days" value="<?= (int)round($qp_auth_cfg['session_ttl'] / 86400) ?>">
            <small class="form-text text-muted">Counted from your last visit: every visit starts the count again.</small>
        </div>
        <div class="form-group col-md-4">
            <label for="auth_remember_days">&ldquo;Remember me&rdquo; (days)</label>
            <input type="number" min="1" max="365" class="form-control" id="auth_remember_days" name="auth_remember_days" value="<?= (int)round($qp_auth_cfg['remember_ttl'] / 86400) ?>">
        </div>
        <div class="form-group col-md-4">
            <label for="auth_max_tokens">Sign-ins kept per user</label>
            <input type="number" min="1" max="100" class="form-control" id="auth_max_tokens" name="auth_max_tokens" value="<?= (int)$qp_auth_cfg['max_tokens'] ?>">
            <small class="form-text text-muted">When exceeded, the least recently used sign-in ends.</small>
        </div>
    </div>

    <button type="submit" class="btn btn-primary">Save Settings</button>
</form>
</div>

    <div class="container mt-4">
        <h3>Security</h3>
        <p>Rotate the site's security salt to immediately invalidate all other sessions (admins will be logged out). The account performing the rotation will remain logged in.</p>
        <button id="rotateSaltBtn" class="btn btn-warning">Rotate Security Salt</button>
        <div id="rotateResult" class="mt-2"></div>
    </div>

<script>
function togglePageSelector() {
    const frontOption = document.getElementById('front_page_option').value;
    const pageSelector = document.getElementById('front_page_id');
    if (pageSelector) pageSelector.disabled = frontOption !== 'static';
}
window.onload = togglePageSelector;
</script>

<script>
// Live previews for timezone/date/time
(function(){
    function updateTZPreview(){
        var sel = document.getElementById('timezone_string');
        if(!sel) return;
        try{
            var tz = sel.value;
            var now = new Date();
            // show UTC-based current time adjusted to tz by formatting via toLocaleString
            var opt = { timeZone: tz, hour12: true, year:'numeric', month:'long', day:'numeric', hour:'numeric', minute:'numeric', second:'numeric' };
            var s = now.toLocaleString(undefined, opt);
            document.getElementById('tzPreview').textContent = s;
        }catch(e){ document.getElementById('tzPreview').textContent = '' }
    }
    function updateDatePreview(){
        var fmt = document.querySelector('input[name="date_format"]:checked');
        var custom = document.querySelector('input[name="date_format_custom"]');
        var f = fmt ? fmt.value : (custom ? custom.value : 'F j, Y');
        // attempt to map PHP format tokens to a rough JS format used by toLocaleDateString
        var d = new Date();
        // simple fallback: show example by server-rendered example embedded earlier
        document.getElementById('datePreview').textContent = (new Date()).toLocaleDateString();
    }
    function updateTimePreview(){
        document.getElementById('timePreview').textContent = (new Date()).toLocaleTimeString();
    }
    document.getElementById('timezone_string')?.addEventListener('change', updateTZPreview);
    document.querySelectorAll('input[name="date_format"]').forEach(function(r){ r.addEventListener('change', updateDatePreview); });
    document.querySelectorAll('input[name="time_format"]').forEach(function(r){ r.addEventListener('change', updateTimePreview); });
    updateTZPreview(); updateDatePreview(); updateTimePreview();
})();
</script>

<script>
document.getElementById('rotateSaltBtn')?.addEventListener('click', function(){
    if (!confirm('Rotate the security salt? This will invalidate other users\' sessions. Continue?')) return;
    const btn = this;
    btn.disabled = true;
    btn.textContent = 'Rotating...';
    fetch('ajax.php', {
        method: 'POST',
        headers: {
            'Accept': 'application/json',
            'Content-Type': 'application/x-www-form-urlencoded'
        },
        body: new URLSearchParams({ action: 'rotate_salt' }),
        credentials: 'same-origin'
    }).then(r => r.json()).then(data => {
        const out = document.getElementById('rotateResult');
        if (data && data.status === 'success') {
            out.innerHTML = '<div class="alert alert-success">' + (data.message || 'Salt rotated') + '</div>';
        } else {
            out.innerHTML = '<div class="alert alert-danger">' + (data.message || 'Rotation failed') + '</div>';
        }
    }).catch(err => {
        document.getElementById('rotateResult').innerHTML = '<div class="alert alert-danger">Request failed</div>';
    }).finally(() => {
        btn.disabled = false;
        btn.textContent = 'Rotate Security Salt';
    });
});
</script>

<?php require __DIR__ . '/inc/footer.php'; ?>
