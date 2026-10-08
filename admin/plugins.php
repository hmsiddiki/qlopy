<?php
require_once __DIR__ . '/admin_head.php';
// Load config
$config = require __DIR__ . '/../config.php';
$content_dir = $config['content_dir'] ?? 'content';
$plugins_dir = $config['plugins_dir'] ?? 'plugins';

//$plugin_dir = __DIR__ . '/../content/plugins/';
$plugin_dir = __DIR__ . "/../$content_dir/$plugins_dir/";
$available_plugins = [];
if (is_dir($plugin_dir)) {
    foreach (scandir($plugin_dir) as $plugin) {
        if ($plugin[0] !== '.' && is_dir($plugin_dir . $plugin)) {
            $available_plugins[] = $plugin;
        }
    }
}

$stmt = $pdo->prepare("SELECT option_value FROM " . table_name('site_options') . " WHERE option_name = 'active_plugins' LIMIT 1");
$stmt->execute();
$active_plugins_json = $stmt->fetchColumn();
$active_plugins = $active_plugins_json ? json_decode($active_plugins_json, true) : [];
// Normalize active plugins to an associative map of slug => true
if (!empty($active_plugins)) {
    $normalized = [];
    $is_assoc = false;
    foreach ($active_plugins as $k => $v) {
        if (!is_int($k)) { $is_assoc = true; break; }
    }
    if ($is_assoc) {
        foreach ($active_plugins as $k => $v) {
            $normalized[$k] = $v;
        }
    } else {
        foreach ($active_plugins as $v) {
            $normalized[$v] = true;
        }
    }
    $active_plugins = $normalized;
}

// Handle activate/deactivate requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['plugin']) && isset($_POST['action'])) {
    $plugin = $_POST['plugin'];
    $action = $_POST['action'];
    // POST received: plugin={$plugin} action={$action}
    if (in_array($plugin, $available_plugins, true)) {
        $plugin_file = $plugin_dir . $plugin . '/plugin.php';

        if ($action === 'activate') {
            // Before activating, ensure any required plugins declared in the header are present.
            $meta_check = parse_plugin_header($plugin_file);
            $req_str = $meta_check['Required plugin'] ?? $meta_check['Required Plugins'] ?? $meta_check['Requires Plugins'] ?? $meta_check['Requires'] ?? '';
            // Fallback: if header parser failed to find the required string, try scanning the file
            if (!$req_str && is_file($plugin_file) && is_readable($plugin_file)) {
                $contents_check = file_get_contents($plugin_file, false, null, 0, 32768);
                if ($contents_check !== false) {
                        if (preg_match('/^.*Required[^:\n]*:\s*(.+)$/mi', $contents_check, $mcheck)) {
                        $req_str = trim($mcheck[1]);
                    }
                }
            }
            $missing = [];
            if ($req_str) {
                preg_match_all('/\[([^\|\]]+)(?:\|([^\]]+))?\]/', $req_str, $mm_check, PREG_SET_ORDER);
                foreach ($mm_check as $it) {
                    $req_slug = trim($it[1]);
                    if ($req_slug === '') continue;
                    $req_file = $plugin_dir . $req_slug . '/plugin.php';
                    if (!is_file($req_file)) {
                        $missing[] = $req_slug . ' (not installed)';
                    } elseif (!array_key_exists($req_slug, $active_plugins)) {
                        $missing[] = $req_slug . ' (not active)';
                    }
                }
            }
            // Activation check completed for plugin: $plugin
            if (!empty($missing)) {
                $error_message = 'Cannot activate "' . htmlspecialchars($plugin) . '" — missing required plugin(s): ' . htmlspecialchars(implode(', ', $missing));
                // Do not activate; show message on the same page.
            } else {
                // Safe to activate
                $active_plugins[$plugin] = true;
                $stmt_upd = $pdo->prepare("INSERT INTO " . table_name('site_options') . " (option_name, option_value) VALUES ('active_plugins', ?) ON DUPLICATE KEY UPDATE option_value=VALUES(option_value)");
                $stmt_upd->execute([json_encode($active_plugins)]);
                if (is_file($plugin_file)) {
                    include_once $plugin_file;
                }
                if (function_exists('do_plugin_activation')) {
                    do_plugin_activation($plugin);
                }
                header('Location: plugins.php');
                exit;
            }
        } elseif ($action === 'deactivate') {
            // Remove the plugin being deactivated
            unset($active_plugins[$plugin]);

            // Find any active plugins that declare this plugin as required and deactivate them too
            $auto_deactivated = [];
            foreach (array_keys($active_plugins) as $ap) {
                // only consider plugins that are still active
                if (!array_key_exists($ap, $active_plugins)) continue;
                $ap_file = $plugin_dir . $ap . '/plugin.php';
                $ap_meta = parse_plugin_header($ap_file);
                $ap_req_str = $ap_meta['Required plugin'] ?? $ap_meta['Required Plugins'] ?? $ap_meta['Requires Plugins'] ?? $ap_meta['Requires'] ?? '';
                if (!$ap_req_str && is_file($ap_file) && is_readable($ap_file)) {
                    $contents_ap = file_get_contents($ap_file, false, null, 0, 32768);
                    if ($contents_ap !== false && preg_match('/^.*Required[^:\n]*:\s*(.+)$/mi', $contents_ap, $map)) {
                        $ap_req_str = trim($map[1]);
                    }
                }
                if ($ap_req_str) {
                    preg_match_all('/\[([^\|\]]+)(?:\|([^\]]+))?\]/', $ap_req_str, $reqs_ap, PREG_SET_ORDER);
                    foreach ($reqs_ap as $r) {
                        $req_slug = trim($r[1]);
                        if ($req_slug === '') continue;
                        if ($req_slug === $plugin) {
                            // deactivate dependent
                            unset($active_plugins[$ap]);
                            $auto_deactivated[] = $ap;
                            break;
                        }
                    }
                }
            }

            // persist updated active plugins once
            $stmt_upd = $pdo->prepare("INSERT INTO " . table_name('site_options') . " (option_name, option_value) VALUES ('active_plugins', ?) ON DUPLICATE KEY UPDATE option_value=VALUES(option_value)");
            $stmt_upd->execute([json_encode($active_plugins)]);

            // Call deactivation hooks for auto-deactivated plugins
            if (!empty($auto_deactivated) && function_exists('do_plugin_deactivation')) {
                foreach ($auto_deactivated as $ad) {
                    do_plugin_deactivation($ad);
                }
            }

            // Call deactivation hook for the originally requested plugin
            if (function_exists('do_plugin_deactivation')) {
                do_plugin_deactivation($plugin);
            }

            // Prepare admin message if any plugins were auto-deactivated
            if (!empty($auto_deactivated)) {
                $error_message = 'The following plugins were deactivated because they require "' . htmlspecialchars($plugin) . '": ' . htmlspecialchars(implode(', ', $auto_deactivated));
                // Persist notice across the redirect via per-user admin notice
                if (function_exists('qp_set_admin_notice')) qp_set_admin_notice($error_message);
            }

            header('Location: plugins.php');
            exit;
        }
    }
}

$page_title = 'Manage Plugins';


if ( !current_user_can('manage_plugins')) {
    http_response_code(403);
    require_once __DIR__ . '/inc/header.php';
    require_once __DIR__ . '/inc/navbar.php';
    echo '<div class="container"><h1>Permission Denied</h1><p>You do not have permission to access this admin page.</p></div>';
    include __DIR__ . '/inc/footer.php';
    exit;
}

require_once __DIR__ . '/inc/header.php';
//require_once __DIR__ . '/inc/navbar.php';
?>
<div class="container mt-4">
<h2 style="margin-bottom:16px;">Plugin Manager</h2>

<?php
// Restore any persisted admin notice (set before redirect)
if (empty($error_message) && function_exists('qp_get_admin_notice')) {
    $prev = qp_get_admin_notice();
    if (!empty($prev)) $error_message = $prev;
}
// if activation failed due to missing dependency, show an admin notice here
if (!empty($error_message)) {
    echo '<div style="margin:8px 0;padding:10px;border-left:4px solid #d63638;background:#fff6f6;color:#900;">' . htmlspecialchars($error_message) . '</div>';
}
// Helper: read plugin header metadata from plugin.php (similar to WP-style headers)
function parse_plugin_header(string $filepath): array {
    $defaults = [
        'Plugin Name' => '',
        'Plugin URI' => '',
        'Description' => '',
        'Version' => '',
        'Author' => '',
        'Author URI' => '',
        'License' => '',
        // Optional dependency header(s) — plugin authors may declare required plugin slugs here.
        // Supported header names (case-insensitive):
        //  - Required plugin
        //  - Required Plugins
        //  - Requires Plugins
        //  - Requires
        // Example value: [other-plugin|Other Plugin], [extra-plugin|Extra Plugin]
        'Required plugin' => '',
        'Required Plugins' => '',
        'Requires Plugins' => '',
        'Requires' => '',
    ];
    if (!is_file($filepath) || !is_readable($filepath)) {
       // @file_put_contents(__DIR__ . '/.plugins_parse.log', date('c') . " parse_plugin_header: missing or unreadable file={$filepath} exists=" . (is_file($filepath)?'1':'0') . " readable=" . (is_readable($filepath)?'1':'0') . "\n", FILE_APPEND | LOCK_EX);
        return $defaults;
    }
    $contents = file_get_contents($filepath, false, null, 0, 8192);
    $lines = preg_split('/\r?\n/', $contents);
    $meta = $defaults;
    foreach ($lines as $line) {
        $line = trim($line);
        if (strpos($line, ':') === false) continue;
        foreach ($defaults as $key => $_) {
            if (stripos($line, $key . ':') === 0) {
                $meta[$key] = trim(substr($line, strlen($key) + 1));
            }
        }
        // stop reading headers after an empty line
        if ($line === '') break;
    }
    return $meta;
}

// Minimal CSS to approximate WordPress plugin list table
?>
<style>
.wp-list-table { width:100%; border-collapse:collapse; background:#fff; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, "Noto Sans", "Liberation Sans", sans-serif; }
.wp-list-table th, .wp-list-table td { padding:12px 14px; border-bottom:1px solid #e6e6e6; vertical-align:top; }
.wp-list-table th { text-align:left; font-weight:600; color:#333; background:#fafafa; }
.plugin-row .plugin-title { font-size:16px; margin:0; display:block; color:#222; }
.plugin-description { color:#555; margin-top:6px; }
.row-actions { margin-top:6px; font-size:13px; }
.row-actions a, .row-actions button { margin-right:12px; color:#0073aa; text-decoration:none; background:none; border:0; padding:0; cursor:pointer; }
.plugin-active { background:#f7fff7; }
.plugin-inactive { background:#fffaf0; }
.badge { display:inline-block; padding:3px 6px; border-radius:3px; background:#f3f3f3; color:#333; font-size:12px; margin-left:8px; }
.actions-form button { padding:6px 10px; border-radius:4px; border:1px solid #ddd; background:#f7f7f7; cursor:pointer; }
.plugin-meta { color:#666; font-size:13px; }
@media (max-width:700px) { .wp-list-table th, .wp-list-table td { padding:10px; } }
</style>

<table class="wp-list-table widefat plugins">
    <thead>
        <tr>
            <th>Plugin</th>
            <th style="width:90px;">Version</th>
            <th style="width:200px;">Author</th>
            <th style="width:140px;">Actions</th>
        </tr>
    </thead>
    <tbody>
<?php foreach ($available_plugins as $plugin):
        $plugin_file = $plugin_dir . $plugin . '/plugin.php';
        // Dependencies are declared in plugin header using the bracket syntax; no extra file inclusion.
    $meta = parse_plugin_header($plugin_file);
    $req_str_display_debug = $meta['Required plugin'] ?? $meta['Required Plugins'] ?? $meta['Requires Plugins'] ?? $meta['Requires'] ?? '';
    $display_name = $meta['Plugin Name'] ?: $plugin;
    $desc = $meta['Description'] ?: '';
    $version = $meta['Version'] ?: '';
    $author = $meta['Author'] ?: '';
    $author_uri = $meta['Author URI'] ?: '';
    $plugin_uri = $meta['Plugin URI'] ?: '';
    $is_active = isset($active_plugins[$plugin]);
?>
    <tr class="plugin-row <?= $is_active ? 'plugin-active' : 'plugin-inactive' ?>">
        <td>
            <strong class="plugin-title"><?= htmlspecialchars($display_name) ?></strong>
            <?php if ($desc): ?><div class="plugin-description"><?= htmlspecialchars($desc) ?></div><?php endif; ?>
            <?php
            // Parse dependency declarations from plugin header using syntax: [slug|Nice Name]
            $req_str_display = $meta['Required plugin'] ?? $meta['Required Plugins'] ?? $meta['Requires Plugins'] ?? $meta['Requires'] ?? '';
            // Fallback at render time as well
            if (!$req_str_display && is_file($plugin_file) && is_readable($plugin_file)) {
                $contents_disp = file_get_contents($plugin_file, false, null, 0, 32768);
                    if ($contents_disp !== false && preg_match('/^.*Required[^:\n]*:\s*(.+)$/mi', $contents_disp, $mdisp)) {
                    $req_str_display = trim($mdisp[1]);
                }
            }
            if ($req_str_display) {
                preg_match_all('/\[([^\|\]]+)(?:\|([^\]]+))?\]/', $req_str_display, $mm, PREG_SET_ORDER);
                if (!empty($mm)) {
                    $pieces = [];
                    // dependency parsing for rendering
                    foreach ($mm as $item) {
                        $req_slug = trim($item[1]);
                        $req_name = isset($item[2]) ? trim($item[2]) : $req_slug;
                        if ($req_slug === '') continue;
                        $req_file = $plugin_dir . $req_slug . '/plugin.php';
                        if (is_file($req_file)) {
                            $label = array_key_exists($req_slug, $active_plugins) ? 'Active' : 'Inactive';
                        } else {
                            $label = 'Not Present';
                        }
                        $pieces[] = htmlspecialchars($req_name) . ' (' . $label . ')';
                    }
                    // render pieces prepared
                    if (!empty($pieces)) {
                        echo '<div style="margin-top:6px;font-size:13px;color:#666;"><strong>Required Plugin:</strong> ' . implode(', ', $pieces) . '</div>';
                    }
                }
            }
            ?>
            <div class="row-actions">
                <!-- Activation/Deactivation action moved into row actions (compact link-style) -->
                <form method="post" style="display:inline; margin:0;">
                    <input type="hidden" name="plugin" value="<?= htmlspecialchars($plugin) ?>">
                    <?php if ($is_active): ?>
                        <button type="submit" name="action" value="deactivate">Deactivate</button>
                    <?php else: ?>
                        <button type="submit" name="action" value="activate">Activate</button>
                    <?php endif; ?>
                </form>
            </div>
        </td>
        <td><?= htmlspecialchars($version) ?></td>
        <td class="plugin-meta"><?php if ($author): ?><?php if ($author_uri): ?><a href="<?= htmlspecialchars($author_uri) ?>" target="_blank"><?= htmlspecialchars($author) ?></a><?php else: ?><?= htmlspecialchars($author) ?><?php endif; ?><?php endif; ?></td>
        <td>
            <?php
            // Show update indicator if available
            $updateInfo = null;
            if (function_exists('get_plugin_update')) {
                $updateInfo = get_plugin_update($plugin);
            }
            if ($updateInfo) {
                echo '<span class="badge" style="background:#ffecec;color:#a33;">Update available: v' . htmlspecialchars($updateInfo['new_version'] ?? '') . '</span>';
                echo ' <button class="badge" data-plugin-update="' . htmlspecialchars($plugin) . '" style="background:#dbefff;color:#084;cursor:pointer;border:0;padding:4px 8px;border-radius:4px;">Update Now</button>';
            }
            if ($is_active) { echo '<span class="badge" style="background:#e8f7e8;color:#2b6b2b;">Active</span>'; }
            else { echo '<span class="badge" style="background:#fff8e6;color:#8a6d00;">Inactive</span>'; }
            ?>
        </td>
    </tr>
<?php endforeach; ?>
    </tbody>
</table>
        </div>

<script>
// Small AJAX handler: hook Update Now buttons on plugin list
document.addEventListener('click', function(e){
    var btn = e.target.closest('[data-plugin-update]');
    if (!btn) return;
    e.preventDefault();
    var plugin = btn.getAttribute('data-plugin-update');
    if (!plugin) return;
    if (!confirm('Schedule update for plugin "' + plugin + '" now?')) return;
    btn.disabled = true;
    var form = new FormData();
    form.append('action','qlopy_schedule_update');
    form.append('type','plugin');
    form.append('folder',plugin);
    fetch(window.ajaxurl || 'ajax.php', { method: 'POST', body: form, credentials: 'same-origin' }).then(function(resp){ return resp.json(); }).then(function(json){
        if (json && json.status === 'success') {
            alert('Update scheduled (task id: ' + (json.task_id || 'n/a') + ').');
            btn.textContent = 'Scheduled';
        } else {
            alert('Failed: ' + (json && json.message ? json.message : 'Unknown'));
            btn.disabled = false;
        }
    }).catch(function(err){ alert('Ajax error'); btn.disabled = false; });
});
</script>


<?php include __DIR__ . '/inc/footer.php'; ?>

