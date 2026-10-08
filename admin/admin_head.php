<?php
// Mark that this is an authorized entrypoint for includes
if (!defined('QLOPY_INIT')) define('QLOPY_INIT', true);
// Begin runtime guard: buffer to strip leading BOM/whitespace from includes
if (!ob_get_level()) { ob_start(); }
if (!function_exists('qp_flush_leading_output')) {
    function qp_flush_leading_output() {
        $buf = ob_get_contents();
        if ($buf === false) return;
        $clean = preg_replace('/^\x{FEFF}+/u', '', $buf);
        $clean = preg_replace('/^\s+/u', '', $clean);
        if ($clean !== $buf) { ob_clean(); echo $clean; }
    }
}
// Load config
$config = require __DIR__ . '/../config.php';
$content_dir = $config['content_dir'] ?? 'content';
$themes_dir = $config['themes_dir'] ?? 'themes';
$plugins_dir = $config['plugins_dir'] ?? 'plugins';
require_once __DIR__ . '/../auth.php';
// Initialize token-based auth (migrates to token if needed)
if (function_exists('auth_init_from_token')) {
    auth_init_from_token();
}
// Central session handling: start session early for admin pages so code
// can persist admin notices across redirects without starting sessions
// in individual pages.
if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_NONE) {
    @session_start();
}
// Mark admin context so cron scheduling and other admin-only helpers permit actions
if (!defined('IN_ADMIN')) define('IN_ADMIN', true);
require_once __DIR__ . '/../includes/assets.php';
// Load screens helper early so functions like current_admin_screen() are available
require_once __DIR__ . '/inc/screens.php';


// Load qp-cron core if present so admin pages can use its helpers (safe include)
$qp_cron_core = __DIR__ . '/../includes/qp-cron.php';
if (file_exists($qp_cron_core)) {
    require_once $qp_cron_core;
}
$qp_cron_queue = __DIR__ . '/../includes/qp-cron-queue.php';
if (file_exists($qp_cron_queue)) {
    require_once $qp_cron_queue;
}

// Ensure admin ajax hook helpers are available before updater registers admin actions
require_once __DIR__ . '/admin-ajax-hook.php';

// Register admin-side bridge for media filters so `do_admin_action('iitcm_admin_ajax_qp_media_filters', $req)`
// will delegate to the global `qp_media_filters` handler if present. This allows admin/ajax.php
// to dispatch via `do_admin_action` without returning 404 when the canonical handler is defined
// in shared/auth context.
if (function_exists('add_admin_action')) {
    add_admin_action('iitcm_admin_ajax_qp_media_filters', function($req){
        if (function_exists('do_action')) do_action('qp_media_filters', $req);
    }, 10, 1);

    // Server-side preview for date/time formats in General Settings
    add_admin_action('iitcm_admin_ajax_preview_date_format', function($req){
        $r = $_REQUEST ?? [];
        $date_fmt = trim($r['date_format'] ?? '');
        $time_fmt = trim($r['time_format'] ?? '');
        $fmt = trim($date_fmt . ' ' . $time_fmt);
        $now = date('c');
        if ($fmt !== '') {
            try {
                if (function_exists('format_site_datetime')) {
                    $preview = format_site_datetime($now, $fmt);
                } else {
                    $preview = date($fmt, strtotime($now));
                }
            } catch (Throwable $e) {
                $preview = date('Y-m-d H:i:s', strtotime($now));
            }
        } else {
            $preview = function_exists('format_site_datetime') ? format_site_datetime($now) : date('Y-m-d H:i:s', strtotime($now));
        }
        echo json_encode(['status'=>'success','preview'=>$preview]);
        exit;
    }, 10, 1);

    // Report current preview theme (for admin Themes page badge updates)
    add_admin_action('iitcm_admin_ajax_preview_theme_check', function($req){
        $slug = '';
        if (!empty($_COOKIE['qp_preview_theme'])) {
            $raw = preg_replace('/[^a-z0-9._\-]/i', '', $_COOKIE['qp_preview_theme']);
            if ($raw !== '') $slug = $raw;
        }
        echo json_encode(['status' => 'success', 'theme' => $slug]);
        exit;
    }, 10, 1);
}

// Load admin-specific AJAX handlers (comments, media admin helpers, etc.) if present
$admin_specific = __DIR__ . '/comments-admin.php';
if (file_exists($admin_specific)) {
    require_once $admin_specific;
}

// Include central updater and initialize checks + schedule recurring tasks
$updater_file = __DIR__ . '/../includes/updater.php';
if (file_exists($updater_file)) {
    require_once $updater_file;
    if (function_exists('central_init_update')) {
        central_init_update();
    }
    // Schedule recurring checker (6 hours) and initiator (12 hours) if qp-cron is available
    if (function_exists('qp_schedule_single_event') && function_exists('qp_next_scheduled')) {
        // use configured intervals (seconds)
        $settings = function_exists('qlopy_get_updater_settings') ? qlopy_get_updater_settings() : [];
        $checker_interval = (int)($settings['checker_interval'] ?? 21600);
        $initiator_interval = (int)($settings['initiator_interval'] ?? 43200);
        if (qp_next_scheduled('qlopy_update_checker') === null) {
            qp_schedule_single_event(time(), 'qlopy_update_checker', ['_origin' => 'admin'], $checker_interval);
        }
        if (qp_next_scheduled('qlopy_update_initiator') === null) {
            qp_schedule_single_event(time() + 60, 'qlopy_update_initiator', ['_origin' => 'admin'], $initiator_interval);
        }
    }
}

// Simple admin nonce helpers for CSRF protection on admin forms with TTL support
// Nonces are stored per-user in `user_meta` (meta_key: `qp_nonces`) so they
// don't rely on PHP sessions/files. This allows removing the legacy session
// handler and `data/` session storage once other session uses are migrated.
if (!function_exists('qp_admin_create_nonce')) {
    function qp_admin_create_nonce($action = 'default', int $ttl = 3600) {
        $user = get_logged_in_user();
        if (empty($user) || empty($user['id'])) return '';
        try { $token = bin2hex(random_bytes(12)); } catch (Exception $e) { $token = bin2hex(openssl_random_pseudo_bytes(12)); }
        $user_id = (int)$user['id'];
        $nonces = get_user_meta($user_id, 'qp_nonces') ?? [];
        if (!is_array($nonces)) $nonces = [];
        $now = time();
        // prune expired tokens across all actions
        foreach ($nonces as $act => $tokens) {
            if (!is_array($tokens)) continue;
            foreach ($tokens as $t => $exp) {
                if ($exp < $now) unset($nonces[$act][$t]);
            }
            if (empty($nonces[$act])) unset($nonces[$act]);
        }
        $nonces[$action] = $nonces[$action] ?? [];
        $nonces[$action][$token] = $now + (int)$ttl;
        update_user_meta($user_id, 'qp_nonces', $nonces);
        return $token;
    }
    function qp_admin_verify_nonce($token, $action = 'default') {
        if (empty($token)) return false;
        $user = get_logged_in_user();
        if (empty($user) || empty($user['id'])) return false;
        $user_id = (int)$user['id'];
        $nonces = get_user_meta($user_id, 'qp_nonces') ?? [];
        if (!is_array($nonces) || empty($nonces[$action]) || !is_array($nonces[$action])) return false;
        $tokens = $nonces[$action];
        $now = time();
        // remove expired tokens first
        foreach ($tokens as $t => $exp) { if ($exp < $now) unset($tokens[$t]); }
        if (empty($tokens)) { unset($nonces[$action]); update_user_meta($user_id, 'qp_nonces', $nonces); return false; }
        if (!isset($tokens[$token])) { $nonces[$action] = $tokens; update_user_meta($user_id, 'qp_nonces', $nonces); return false; }
        $exp = $tokens[$token];
        if ($exp < $now) { unset($tokens[$token]); $nonces[$action] = $tokens; update_user_meta($user_id, 'qp_nonces', $nonces); return false; }
        // consume this token
        unset($tokens[$token]);
        if (empty($tokens)) unset($nonces[$action]); else $nonces[$action] = $tokens;
        update_user_meta($user_id, 'qp_nonces', $nonces);
        return true;
    }
    function qp_nonce_field($action = 'default', int $ttl = 3600) {
        $t = qp_admin_create_nonce($action, $ttl);
        echo '<input type="hidden" name="_qp_nonce" value="' . htmlspecialchars($t) . '">';
    }
}

// Simple per-user admin notice helpers (flash-style)
if (!function_exists('qp_set_admin_notice')) {
    function qp_set_admin_notice($msg) {
        $user = get_logged_in_user();
        if (empty($user) || empty($user['id'])) return false;
        update_user_meta((int)$user['id'], 'qp_admin_notice', $msg);
        return true;
    }
    function qp_get_admin_notice() {
        $user = get_logged_in_user();
        if (empty($user) || empty($user['id'])) return null;
        $msg = get_user_meta((int)$user['id'], 'qp_admin_notice');
        if ($msg === null) return null;
        // Clear stored notice
        update_user_meta((int)$user['id'], 'qp_admin_notice', null);
        return $msg;
    }
}


if (!is_logged_in() && (!check_permission('manage_options') || !check_permission('manage_posts') || !check_permission('manage_users') || !check_permission('manage_themes') || !check_permission('manage_plugins') || !check_permission('manage_admin_pages') || !check_permission('manage_comments') || !check_permission('publish_posts'))) {
    header('Location: login.php');
    exit;
}


require_once __DIR__ . '/../includes/post-types.php';
require_once __DIR__ . '/../includes/qpmeta.php';

// Load admin AJAX handlers for core modules if present
$admin_ajax_handlers = __DIR__ . '/../includes/admin-ajax-mail.php';
if (file_exists($admin_ajax_handlers)) {
    require_once $admin_ajax_handlers;
}

// Ensure qp_mail core is available for admin pages and AJAX handlers
$qp_mail_core = __DIR__ . '/../includes/qp-mail.php';
if (file_exists($qp_mail_core)) {
    require_once $qp_mail_core;
}


// Load QP_Screen helpers to compute current admin screen id.
// NOTE: include moved later (after themes/plugins) so plugins/themes
// can register `admin_enqueue_{id}` callbacks before the per-screen
// enqueue hooks are fired.

// Menus API is loaded globally from auth.php
// Register default menu locations on admin init so they appear in Menus UI
if (function_exists('register_menu_location')) {
    register_menu_location('primary', 'Primary Menu');
}
require_once __DIR__ . '/../includes/admin-menus.php';

// by hooking `admin_enqueue_{screen_id}` via `add_admin_action()`.
// require_once __DIR__ . '/inc/screens.php'; // moved down after plugin/theme includes



// Load theme and plugin system (unchanged from your current system)
$theme_dir_root = __DIR__ . "/../$content_dir/$themes_dir/";
$available_themes = [];
if (is_dir($theme_dir_root)) {
    foreach (scandir($theme_dir_root) as $theme) {
        if ($theme[0] !== '.' && is_dir($theme_dir_root . $theme)) {
            $available_themes[] = $theme;
        }
    }
}
// Active theme: pick first if only one, else from DB or fallback
if (count($available_themes) === 1) {
    $active_theme = $available_themes[0];
    $stmt = $pdo->prepare("SELECT option_value FROM " . table_name('site_options') . " WHERE option_name = 'active_theme' LIMIT 1");
    $stmt->execute();
    $db_theme = $stmt->fetchColumn();
    if ($db_theme !== $active_theme) {
        $stmt_upd = $pdo->prepare("INSERT INTO " . table_name('site_options') . "(option_name, option_value) VALUES ('active_theme', ?) ON DUPLICATE KEY UPDATE option_value=VALUES(option_value)");
        $stmt_upd->execute([$active_theme]);
    }
} else {
    $stmt = $pdo->prepare("SELECT option_value FROM " . table_name('site_options') . " WHERE option_name = 'active_theme' LIMIT 1");
    $stmt->execute();
    $active_theme = $stmt->fetchColumn() ?: ($available_themes[0] ?? 'default');
}

$theme_dir = $theme_dir_root . $active_theme . '/';
if (file_exists($theme_dir . 'functions.php')) {
    include_once $theme_dir . 'functions.php';
}

$plugin_dir = __DIR__ . "/../$content_dir/$plugins_dir/";
define('PLUGIN_URL', SITE_URL . "/$content_dir/$plugins_dir");
    $stmt = $pdo->prepare("SELECT option_value FROM " . table_name('site_options') . " WHERE option_name = 'active_plugins' LIMIT 1");
$stmt->execute();
$active_plugins_json = $stmt->fetchColumn();
$active_plugins = $active_plugins_json ? json_decode($active_plugins_json, true) : [];
if (is_dir($plugin_dir)) {
    $existing_plugins = array_filter(scandir($plugin_dir), fn($d) => $d[0] !== '.' && is_dir($plugin_dir . $d));
    $updated_active_plugins = [];
    foreach ($active_plugins as $plugin => $v) {
        if (in_array($plugin, $existing_plugins)) {
            $updated_active_plugins[$plugin] = true;
        }
    }
    if ($updated_active_plugins != $active_plugins) {
        $stmt_upd = $pdo->prepare("INSERT INTO " . table_name('site_options') . "(option_name, option_value) VALUES ('active_plugins', ?) ON DUPLICATE KEY UPDATE option_value=VALUES(option_value)");
        $stmt_upd->execute([json_encode($updated_active_plugins)]);
        $active_plugins = $updated_active_plugins;
    }
    foreach ($active_plugins as $plugin => $_) {
        $plugin_file = $plugin_dir . $plugin . '/plugin.php';
        if (is_file($plugin_file)) {
            include_once $plugin_file;
        }
    }
}

define('THEME_URL', SITE_URL . "/$content_dir/$themes_dir/$active_theme");


// Flush any leading BOM/whitespace emitted during theme/plugin includes
qp_flush_leading_output();

// (debug logging removed)

// Now that themes/plugins have been loaded, include the screens helper so
// any plugin/theme that registered `admin_enqueue_{id}` will have its
// callback registered before we fire the per-screen enqueue hooks.
// (include moved just after the menus enqueue registration below)



/// will run after regular admin enqueues below

add_admin_action('admin_enqueue_menus', function($screen) {    
    // $screen is the array from current_admin_screen()
    // Enqueue a CSS file (appears in <head>)
   // enqueue_admin_style('qp-menu-css', 'assets/css/jquery.nestable.min.css', [], '1.0', 'all');

    // Enqueue a JS file in footer (depends on admin-jquery)
  //  enqueue_admin_script('qp-menu-js', 'assets/js/jquery.nestable.min.js', ['admin-jquery'], '1.0', true);
});

// screens helper will be included after 'init' so plugins can register callbacks during init

// Ensure the callback runs even if screens helper already fired; re-fire explicitly for menus
// (re-fire block removed; hooks will have fired on include with callback registered)



// Enqueue core admin assets: jQuery in header, qpmeta-tags in footer
if (function_exists('enqueue_admin_script')) {
    $asset_ver = time(); // Bust cache every request
    enqueue_admin_script('admin-jquery', 'assets/js/jquery.min.js', [], '1.12.4', false);
        // Nestable (drag/drop) assets from CDN
      //  enqueue_admin_style('nestable', 'https://cdn.jsdelivr.net/npm/nestable2@1.6.0/jquery.nestable.min.css', [], $asset_ver, 'all');
      //  enqueue_admin_script('nestable', 'https://cdn.jsdelivr.net/npm/nestable2@1.6.0/jquery.nestable.min.js', ['admin-jquery'], $asset_ver, true);
   enqueue_admin_style('bootstrap-css', 'assets/bt_4.6.2/css/bootstrap.min.css', [], '4.6.2', 'all');
   enqueue_admin_script('popper-js', 'assets/js/popper.min.js', ['admin-jquery'], '4.6.6', true);
   enqueue_admin_script('bootstrap', 'assets/bt_4.6.2/js/bootstrap.bundle.min.js', ['admin-jquery'], '4.6.2', true);
   enqueue_admin_script('admin-js', 'assets/js/admin.js', ['admin-jquery'], $asset_ver, true); 
   enqueue_admin_style('qp-css', 'assets/css/qp.css', [], '3.0', 'all');
   enqueue_admin_style('qpmodal-css', 'assets/css/qpmodal.css', [], $asset_ver, 'all');
      $screen = current_admin_screen();
    if ($screen['id'] === 'menus') {
      // Menus admin custom styles
    enqueue_admin_style('qp-menu-css', 'assets/css/jquery.nestable.min.css', [], '1.2', 'all');
         enqueue_admin_style('menus-admin', 'assets/css/menus-admin.css', [], $asset_ver, '');

    enqueue_admin_script('qp-menu-js', 'assets/js/jquery.nestable.min.js', ['admin-jquery'], '1.0', true);
     // Menus UI script
    enqueue_admin_script('menus', 'assets/js/menus.js', ['admin-jquery'], $asset_ver, true);    
    }
    if ($screen['id'] === 'category_list') {
        // Enqueue the lightweight, local qp-sortable implementation for taxonomy tables
      //  enqueue_admin_script('qp-sortable', 'assets/js/qp-sortable.js', ['admin-jquery'], $asset_ver, true);
        // Tiny init that wires .sortable() to the taxonomy table; enqueued only on the category list screen
      //  enqueue_admin_script('taxonomy-sortable-init', 'assets/js/taxonomy-sortable-init.js', ['admin-jquery','qp-sortable'], $asset_ver, true);
    }
    if ($screen['qtype'] === 'taxonomies') { 
        // Enqueue the lightweight, local qp-sortable implementation for taxonomy term lists
        enqueue_admin_script('qp-sortable', 'assets/js/qp-sortable.js', ['admin-jquery'], $asset_ver, true);
        // Tiny init that wires .sortable() to the taxonomy term lists; enqueued only on taxonomy term screens
        enqueue_admin_script('taxonomy-terms-sortable-init', 'assets/js/taxonomy-sortable-init.js', ['admin-jquery','qp-sortable'], $asset_ver, true);
    }
    
    // Ensure this loads after bootstrap and admin.js which are already in footer
    enqueue_admin_script('qlopy-media-modal', 'assets/js/qlopy-media-modal.js', ['admin-jquery','bootstrap','admin-js'], $asset_ver, true);
    // Centralized QPMeta admin behaviors (repeatables, file handling, modal integration)
    // Ensure lightweight sortable is available for repeatable panels
    enqueue_admin_script('qp-sortable', 'assets/js/qp-sortable.js', ['admin-jquery'], $asset_ver, true);
    enqueue_admin_script('qpmeta-admin', 'assets/js/qpmeta-admin.js', ['admin-jquery','qp-sortable'], $asset_ver, true);
    // TinyMCE core - use non-minified for debugging
    enqueue_admin_script('tinymce', 'assets/js/tinymce/tinymce.js', ['admin-jquery'], $asset_ver, true);
    // Editor initializer for post content + QPMeta richtext fields
    enqueue_admin_script('editor-init', 'assets/js/editor-init.js', ['admin-jquery','tinymce'], $asset_ver, true);
    enqueue_admin_script('qpmodal-js', 'assets/js/qpmodal.js', ['admin-jquery'], $asset_ver, true);
}

// Enqueue QPMeta tags UI on post edit/create screens so tag suggestions work
if (function_exists('enqueue_admin_script')) {
    $screen = current_admin_screen();
    $should_enqueue_qpmeta_tags = false;
    if (!empty($screen['qtype']) && $screen['qtype'] === 'posts') $should_enqueue_qpmeta_tags = true;
    if (!empty($screen['id']) && preg_match('/_(edit|create)$/', $screen['id'])) $should_enqueue_qpmeta_tags = true;
    if ($should_enqueue_qpmeta_tags) {
        enqueue_admin_script('qpmeta-tags', 'assets/js/qpmeta-tags.js', ['admin-jquery'], $asset_ver, true);
    }
}

// Simple hook bridge: allow themes/plugins to provide TinyMCE config
if (!function_exists('print_tinymce_config_bridge')) {
    function print_tinymce_config_bridge() {
        // Collect config via a global hook if provided
        $config = [];
        if (function_exists('apply_filter')) {
            // If a filter system exists
            $config = apply_filter('tinymce_config', $config, [
                'context' => 'post',
                'post_type' => $_GET['post_type'] ?? 'post'
            ]);
        } else if (function_exists('do_admin_action')) {
            // Alternatively, let plugins assign to a global
            global $TINYMCE_CONFIG;
            $TINYMCE_CONFIG = $TINYMCE_CONFIG ?? [];
            do_admin_action('register_tinymce_config', [
                'context' => 'post',
                'post_type' => $_GET['post_type'] ?? 'post'
            ]);
            $config = $TINYMCE_CONFIG;
        }
        echo "<script>window.getTinyMceConfig = function(ctx){ return " . json_encode($config) . "; };</script>";
    }
}

// Do not print here to avoid output before DOCTYPE; header.php will call this within <head>

// Compute admin ajax url once here and expose helper for templates
$admin_base_path = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
$admin_ajax_url = $admin_base_path . '/ajax.php';
if (!function_exists('get_admin_ajax_url')) {
    function get_admin_ajax_url() {
        global $admin_ajax_url;
        return $admin_ajax_url ?: 'ajax.php';
    }
}



// Admin AJAX for menus (file-based, no DB)
add_admin_action('iitcm_admin_ajax_menus_get_all', function($req){
    $data = menus_load_all();
    echo json_encode([
        'status' => 'success',
        'menus' => $data['menus'] ?? [],
        'locations' => $data['locations'] ?? [],
        'locations_map' => $data['locations_map'] ?? []
    ]);
    exit;
}, 10, 1);

// Persist options bar selections (post types and taxonomies to show boxes)
add_admin_action('iitcm_admin_ajax_menus_get_panel_boxes', function($req){
    $boxes = function_exists('get_option_meta') ? (get_option_meta('menus_panel_boxes') ?: []) : [];
    if (!is_array($boxes)) $boxes = [];
    $boxes['post_types'] = $boxes['post_types'] ?? [];
    $boxes['taxonomies'] = $boxes['taxonomies'] ?? [];
    echo json_encode(['status'=>'success','boxes'=>$boxes]);
    exit;
}, 10, 1);

add_admin_action('iitcm_admin_ajax_menus_set_panel_boxes', function($req){
    $pt = $req['post_types'] ?? [];
    $tx = $req['taxonomies'] ?? [];
    if (!is_array($pt)) $pt = [];
    if (!is_array($tx)) $tx = [];
    $boxes = [ 'post_types' => array_values(array_unique($pt)), 'taxonomies' => array_values(array_unique($tx)) ];
    if (function_exists('update_option_meta')) {
        update_option_meta('menus_panel_boxes', $boxes);
    }
    echo json_encode(['status'=>'success']);
    exit;
}, 10, 1);

add_admin_action('iitcm_admin_ajax_menus_create', function($req){
    $label = trim($req['menu_label'] ?? '');
    $slug = strtolower(preg_replace('/[^a-z0-9]+/i','-', $label));
    if (!$label) { echo json_encode(['status'=>'error','message'=>'Menu name required']); exit; }
    $data = menus_load_all();
    if (!isset($data['menus'])) $data['menus'] = [];
    if (!isset($data['menus'][$slug])) $data['menus'][$slug] = [ 'label' => $label, 'items' => [] ];
    menus_save_all($data);
    echo json_encode(['status'=>'success','menus'=>$data['menus'],'current'=>$slug]);
    exit;
}, 10, 1);

add_admin_action('iitcm_admin_ajax_menus_save', function($req){
    $slug = $req['menu_slug'] ?? '';
    $label = $req['menu_label'] ?? $slug;
    $itemsJson = $req['items'] ?? '[]';
    $items = json_decode($itemsJson, true);
    if (!is_array($items)) $items = [];
    set_menu_structure($slug, $label, $items);
    echo json_encode(['status'=>'success']);
    exit;
}, 10, 1);

add_admin_action('iitcm_admin_ajax_menus_assign_location', function($req){
    $loc = $req['location_key'] ?? '';
    $slug = $req['menu_slug'] ?? '';
    $data = menus_load_all();
    $data['locations_map'][$loc] = $slug;
    menus_save_all($data);
    echo json_encode(['status'=>'success','locations_map'=>$data['locations_map']]);
    exit;
}, 10, 1);

add_admin_action('iitcm_admin_ajax_menus_delete', function($req){
    $slug = $req['menu_slug'] ?? '';
    $data = menus_load_all();
    if (isset($data['menus'][$slug])) {
        unset($data['menus'][$slug]);
        // Remove any location mapping to this slug
        if (!empty($data['locations_map'])) {
            foreach ($data['locations_map'] as $k => $v) {
                if ($v === $slug) { unset($data['locations_map'][$k]); }
            }
        }
    }
    // Persist deletion and return updated menus + locations_map so client can sync
    menus_save_all($data);
    echo json_encode(['status'=>'success', 'menus' => $data['menus'] ?? [], 'locations_map' => $data['locations_map'] ?? []]);
    exit;
}, 10, 1);

// Admin AJAX: check whether a preview token exists (used by admin UI per-token polling)
add_admin_action('iitcm_admin_ajax_preview_token_check', function($req){
    // minimal permission check: admin bootstrap already verifies manage_options
    require_once __DIR__ . '/../includes/theme-helpers.php';
    $token = trim($req['token'] ?? '');
    $token = preg_replace('/[^a-z0-9]/i', '', $token);
    if ($token === '') {
        echo json_encode(['status'=>'error','message'=>'token required']);
        exit;
    }
    $theme = validate_preview_token($token);
    if ($theme) {
        echo json_encode(['status'=>'success','exists' => true, 'theme' => $theme]);
    } else {
        echo json_encode(['status'=>'success','exists' => false]);
    }
    exit;
}, 10, 1);

// Admin AJAX: activate a theme
add_admin_action('iitcm_admin_ajax_activate_theme', function($req){
    global $theme_dir_root, $pdo;
    $theme = trim($req['theme'] ?? '');
    if ($theme === '') {
        echo json_encode(['status'=>'error','message'=>'theme required']); exit;
    }
    if (preg_match('/[^a-z0-9_\-]/i', $theme)) {
        echo json_encode(['status'=>'error','message'=>'invalid theme name']); exit;
    }
    $target = $theme_dir_root . $theme;
    if (!is_dir($target)) {
        echo json_encode(['status'=>'error','message'=>'theme not found']); exit;
    }
    try {
        $stmt = $pdo->prepare("INSERT INTO " . table_name('site_options') . " (option_name, option_value) VALUES ('active_theme', ?) ON DUPLICATE KEY UPDATE option_value=VALUES(option_value)");
        $stmt->execute([$theme]);
        echo json_encode(['status'=>'success','theme'=>$theme]);
        exit;
    } catch (Exception $e) {
        echo json_encode(['status'=>'error','message'=>$e->getMessage()]);
        exit;
    }
}, 10, 1);

// Admin AJAX: check if any preview tokens are currently valid (used by admin UI to start polling)



// Provide pages list for adding to menu (basic: all published pages)
add_admin_action('iitcm_admin_ajax_get_pages_list', function($req){
    $pdo = db();
    // Show pages regardless of status to ensure list populates
    $stmt = $pdo->prepare('SELECT id, title, slug FROM ' . table_name('posts') . ' WHERE post_type = ? ORDER BY id DESC LIMIT 200');
    $stmt->execute(['page']);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    // Compute absolute base URL respecting subfolder depth
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? '';
    $scriptDir = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/');
    // Strip trailing configured admin dir from base path
    $segments = array_filter(explode('/', $scriptDir), 'strlen');
    $admin_dir = $GLOBALS['admin_dir'] ?? ($GLOBALS['config']['admin_dir'] ?? 'admin');
    if (!empty($segments) && end($segments) === $admin_dir) { array_pop($segments); }
    $basePath = '/' . implode('/', $segments);
    if ($basePath === '/') { $basePath = ''; }
    $base = $scheme . '://' . $host . $basePath . '/';
    $pages = array_map(function($r) use ($base){
        $pretty = function_exists('permalink_for_page') ? permalink_for_page($r['slug']) : ($base . 'index.php?page=' . rawurlencode($r['slug']));
        return [
            'id' => (int)$r['id'],
            'title' => $r['title'],
            'url' => $pretty,
            'type' => 'page',
            'slug' => $r['slug'],
        ];
    }, $rows);
    echo json_encode(['status'=>'success','pages'=>$pages]);
    exit;
}, 10, 1);

// Provide posts list (published) for adding to menu
add_admin_action('iitcm_admin_ajax_get_posts_list', function($req){
    $pdo = db();
    $post_type = $req['post_type'] ?? 'post';
    $stmt = $pdo->prepare('SELECT id, title, slug FROM ' . table_name('posts') . ' WHERE post_type = ? AND status = "published" ORDER BY created_at DESC LIMIT 200');
    $stmt->execute([$post_type]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $items = array_map(function($r) use ($post_type){
        $url = function_exists('permalink_for_post') ? permalink_for_post($r['slug'], $post_type) : ((defined('SITE_URL')?SITE_URL:'') . '/index.php?route=singular&post_type='.$post_type.'&slug=' . rawurlencode($r['slug']));
        return [
            'id' => (int)$r['id'],
            'title' => $r['title'],
            'url' => $url,
            'type' => 'post',
            'post_type' => $post_type,
            'slug' => $r['slug'],
        ];
    }, $rows);
    echo json_encode(['status'=>'success','posts'=>$items]);
    exit;
}, 10, 1);

// AJAX: return cron queue entries (JSON)
add_admin_action('iitcm_admin_ajax_cron_queue', function($req){
    $limit = isset($req['limit']) ? (int)$req['limit'] : 200;
    $page = isset($req['page']) ? max(1, (int)$req['page']) : 1;
    $per_page = isset($req['per_page']) ? max(1, (int)$req['per_page']) : 20;
    $status_filter = isset($req['status']) ? trim((string)$req['status']) : '';
    $data = [];
    $total = 0;
    if (function_exists('qp_list_queue')) {
        $rows = qp_list_queue($limit);
        // Optionally filter by status
        if ($status_filter === 'pending') {
            $rows = array_values(array_filter($rows, function($r){ return strtolower($r['status'] ?? '') === 'pending'; }));
        } elseif ($status_filter === 'other') {
            $rows = array_values(array_filter($rows, function($r){ return strtolower($r['status'] ?? '') !== 'pending'; }));
        }
        $total = count($rows);
        // Slice for pagination
        $offset = ($page - 1) * $per_page;
        $page_rows = array_slice($rows, $offset, $per_page);
        // Build action forms for each row including a fresh admin nonce per form
        $data = array_map(function($r){
            $row = $r;
            // create a nonce token for this action set
            $token = function_exists('qp_admin_create_nonce') ? qp_admin_create_nonce('qp_cron_action') : '';
            $status = strtolower($r['status'] ?? '');
            $isDone = in_array($status, ['done','failed'], true);
            // Run button: disable if task is finished; change class to outline-dark when finished
            $runDisabled = $isDone ? ' disabled' : '';
            $runClass = $isDone ? 'btn btn-sm btn-outline-dark qp-action-run' : 'btn btn-sm btn-success qp-action-run';
            $runBtn = '<form method="post" style="display:inline-block"><input type="hidden" name="action" value="run_task"><input type="hidden" name="task_id" value="' . (int)$r['id'] . '"><input type="hidden" name="_qp_nonce" value="' . htmlspecialchars($token) . '"><button class="' . $runClass . '" type="submit"' . $runDisabled . '>Run</button></form>';
            $forceToken = function_exists('qp_admin_create_nonce') ? qp_admin_create_nonce('qp_cron_action') : $token;
            $forceDisabled = $isDone ? ' disabled' : '';
            $forceClass = $isDone ? 'btn btn-sm btn-outline-dark qp-action-force' : 'btn btn-sm btn-danger qp-action-force';
            $forceBtn = '<form method="post" style="display:inline-block;margin-left:6px"><input type="hidden" name="action" value="run_task"><input type="hidden" name="task_id" value="' . (int)$r['id'] . '"><input type="hidden" name="force" value="1"><input type="hidden" name="_qp_nonce" value="' . htmlspecialchars($forceToken) . '"><button class="' . $forceClass . '" type="submit"' . $forceDisabled . '>Force</button></form>';
            $removeToken = function_exists('qp_admin_create_nonce') ? qp_admin_create_nonce('qp_cron_action') : $token;
            $removeClass = $isDone ? 'btn btn-sm btn-danger qp-action-remove' : 'btn btn-sm btn-outline-dark qp-action-remove';
            $origin = strtolower(trim((string)($r['origin'] ?? '')));
            // Protect remove action only when task is in progress (pending or running).
            $protect_remove = in_array($status, ['pending','running'], true);
            if ($protect_remove) {
                $removeBtn = '<button class="btn btn-sm btn-outline-dark" type="button" disabled title="Protected (status: ' . htmlspecialchars($status) . ')">Remove</button>';
            } else {
                $removeBtn = '<form method="post" style="display:inline-block;margin-left:6px" onsubmit="return confirm(\'Remove task ' . (int)$r['id'] . '? This cannot be undone.\');"><input type="hidden" name="action" value="remove_task"><input type="hidden" name="task_id" value="' . (int)$r['id'] . '"><input type="hidden" name="_qp_nonce" value="' . htmlspecialchars($removeToken) . '"><button class="' . $removeClass . '" type="submit">Remove</button></form>';
            }
            $row['actions'] = $runBtn . $forceBtn . $removeBtn;
            return $row;
        }, $page_rows);
    }
    $pagination = ['page' => $page, 'per_page' => $per_page, 'total' => $total, 'total_pages' => $per_page>0? (int)ceil($total / $per_page) : 1];
    echo json_encode(['status'=>'success','queue'=>$data,'pagination'=>$pagination]);
    exit;
}, 10, 1);

// AJAX handlers for running/forcing/removing tasks via admin ajax
add_admin_action('iitcm_admin_ajax_cron_run', function($req){
    if (!check_permission('manage_options')) { echo json_encode(['status'=>'error','message'=>'No permission']); exit; }
    $task_id = isset($req['task_id']) ? (int)$req['task_id'] : 0;
    $nonce = $req['_qp_nonce'] ?? '';
    if (!function_exists('qp_admin_verify_nonce') || !qp_admin_verify_nonce($nonce, 'qp_cron_action')) { echo json_encode(['status'=>'error','message'=>'Invalid nonce']); exit; }
    $force = !empty($req['force']);
    if (!function_exists('qp_run_task_by_id')) { echo json_encode(['status'=>'error','message'=>'Run API missing']); exit; }
    $res = qp_run_task_by_id($task_id, (bool)$force);
    echo json_encode(['status'=>'success','result'=>$res]); exit;
}, 10, 1);

add_admin_action('iitcm_admin_ajax_cron_remove', function($req){
    if (!check_permission('manage_options')) { echo json_encode(['status'=>'error','message'=>'No permission']); exit; }
    $task_id = isset($req['task_id']) ? (int)$req['task_id'] : 0;
    $nonce = $req['_qp_nonce'] ?? '';
    if (!function_exists('qp_admin_verify_nonce') || !qp_admin_verify_nonce($nonce, 'qp_cron_action')) { echo json_encode(['status'=>'error','message'=>'Invalid nonce']); exit; }
    if (!function_exists('qp_remove_task_by_id')) { echo json_encode(['status'=>'error','message'=>'Remove API missing']); exit; }
    // Prevent removal of tasks that are still pending/running via admin UI
    $status = null;
    if (function_exists('qp_get_task_status')) $status = qp_get_task_status($task_id);
    if (in_array(strtolower((string)$status), ['pending','running'], true)) { echo json_encode(['status'=>'error','message'=>'Protected task status: ' . ($status?:'unknown')]); exit; }
    $ok = qp_remove_task_by_id($task_id);
    echo json_encode(['status'=>'success','removed'=>(bool)$ok]); exit;
}, 10, 1);

// AJAX: bulk remove selected cron tasks (secure with admin nonce)
add_admin_action('iitcm_admin_ajax_cron_bulk_remove', function($req){
    if (!check_permission('manage_options')) { echo json_encode(['status'=>'error','message'=>'No permission']); exit; }
    $ids = $req['task_ids'] ?? [];
    $nonce = $req['_qp_nonce'] ?? '';
    if (!function_exists('qp_admin_verify_nonce') || !qp_admin_verify_nonce($nonce, 'qp_cron_action')) { echo json_encode(['status'=>'error','message'=>'Invalid nonce']); exit; }
    if (!function_exists('qp_remove_task_by_id')) { echo json_encode(['status'=>'error','message'=>'Remove API missing']); exit; }
    $removed = [];
    $blocked = [];
    foreach ((array)$ids as $id) {
        $id = (int)$id; if ($id <= 0) continue;
        $status = null; if (function_exists('qp_get_task_status')) $status = qp_get_task_status($id);
        if (in_array(strtolower((string)$status), ['pending','running'], true)) { $blocked[] = $id; continue; }
        $ok = qp_remove_task_by_id($id);
        if ($ok) $removed[] = $id;
    }
    echo json_encode(['status'=>'success','removed'=>$removed,'blocked'=>$blocked]); exit;
}, 10, 1);

// AJAX: return a fresh admin nonce for cron actions (useful before bulk ops)
add_admin_action('iitcm_admin_ajax_cron_get_nonce', function($req){
    if (!check_permission('manage_options')) { echo json_encode(['status'=>'error','message'=>'No permission']); exit; }
    $token = function_exists('qp_admin_create_nonce') ? qp_admin_create_nonce('qp_cron_action') : '';
    echo json_encode(['status'=>'success','nonce'=>$token]); exit;
}, 10, 1);

// AJAX: return recent cron logs (JSON)
add_admin_action('iitcm_admin_ajax_cron_logs', function($req){
    // Supports pagination: accepts 'page' (1-based) and 'per_page' or fallback to 'limit'
    $per_page = isset($req['per_page']) ? max(1, (int)$req['per_page']) : (isset($req['limit']) ? max(1,(int)$req['limit']) : 200);
    $page = isset($req['page']) ? max(1, (int)$req['page']) : 1;
    $offset = ($page - 1) * $per_page;
    $pdo = db();
    qp_cron_logs_install($pdo);
    // Total count
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM ' . qp_cron_logs_table());
    $stmt->execute();
    $total = (int)$stmt->fetchColumn();
    // Fetch page of logs
    $sql = 'SELECT * FROM ' . qp_cron_logs_table() . ' ORDER BY created_at DESC LIMIT :lim OFFSET :off';
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':lim', (int)$per_page, PDO::PARAM_INT);
    $stmt->bindValue(':off', (int)$offset, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $total_pages = $per_page > 0 ? (int)ceil($total / $per_page) : 1;
    echo json_encode(['status'=>'success','logs'=>$rows,'pagination'=>['page'=>$page,'per_page'=>$per_page,'total'=>$total,'total_pages'=>$total_pages]]);
    exit;
}, 10, 1);

// AJAX: preview how many rows would be deleted by rotation
add_admin_action('iitcm_admin_ajax_cron_rotation_preview', function($req){
    if (!check_permission('manage_options')) { echo json_encode(['status'=>'error','message'=>'No permission']); exit; }
    $nonce = $req['_qp_nonce'] ?? '';
    if (!function_exists('qp_admin_verify_nonce') || !qp_admin_verify_nonce($nonce, 'qp_cron_action')) { echo json_encode(['status'=>'error','message'=>'Invalid nonce']); exit; }
    try {
        $pdo = db(); qp_cron_logs_install($pdo);
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM ' . qp_cron_logs_table()); $stmt->execute(); $total = (int)$stmt->fetchColumn();
        $retention = 40;
        if (function_exists('get_option_meta')) {
            $opt = get_option_meta('cron_logs_retention'); if ($opt !== null && is_numeric($opt)) $retention = (int)$opt;
        } else {
            // CLI fallback
            $stmt2 = $pdo->prepare("SELECT option_value FROM " . table_name('site_options') . " WHERE option_name = ? LIMIT 1");
            $stmt2->execute(['cron_logs_retention']); $v = $stmt2->fetchColumn(); if ($v !== false && $v !== null) { $dec = json_decode($v, true); $val = is_null($dec) ? $v : $dec; if (is_numeric($val)) $retention = (int)$val; }
        }
        $to_delete = max(0, $total - max(0, $retention));
        echo json_encode(['status'=>'success','total'=>$total,'retention'=>$retention,'to_delete'=>$to_delete]); exit;
    } catch (Throwable $e) { echo json_encode(['status'=>'error','message'=>$e->getMessage()]); exit; }
}, 10, 1);

// AJAX: run rotation now (admin-triggered)
add_admin_action('iitcm_admin_ajax_cron_run_rotation', function($req){
    if (!check_permission('manage_options')) { echo json_encode(['status'=>'error','message'=>'No permission']); exit; }
    $nonce = $req['_qp_nonce'] ?? '';
    if (!function_exists('qp_admin_verify_nonce') || !qp_admin_verify_nonce($nonce, 'qp_cron_action')) { echo json_encode(['status'=>'error','message'=>'Invalid nonce']); exit; }
    try {
        if (!file_exists(__DIR__ . '/../includes/qp-cron-queue.php')) { echo json_encode(['status'=>'error','message'=>'Rotation code missing']); exit; }
        require_once __DIR__ . '/../includes/qp-cron-queue.php';
        $retention = 40;
        if (function_exists('get_option_meta')) { $opt = get_option_meta('cron_logs_retention'); if ($opt !== null && is_numeric($opt)) $retention = (int)$opt; }
        $ok = qp_cron_rotate_logs([$retention]);
        $pdo = db(); qp_cron_logs_install($pdo); $stmt = $pdo->prepare('SELECT COUNT(*) FROM ' . qp_cron_logs_table()); $stmt->execute(); $total_after = (int)$stmt->fetchColumn();
        echo json_encode(['status'=>$ok ? 'success' : 'error','kept'=>$total_after,'retention'=>$retention,'message'=>$ok ? 'Rotation completed' : 'Rotation skipped or failed']); exit;
    } catch (Throwable $e) { echo json_encode(['status'=>'error','message'=>$e->getMessage()]); exit; }
}, 10, 1);

// Provide taxonomy terms for adding to menu
add_admin_action('iitcm_admin_ajax_get_terms_list', function($req){
    $pdo = db();
    $taxonomy = $req['taxonomy'] ?? null;
    if ($taxonomy) {
        $stmt = $pdo->prepare('SELECT id, term, slug FROM ' . table_name('taxonomy_terms') . ' WHERE taxonomy = ? ORDER BY term ASC LIMIT 200');
        $stmt->execute([$taxonomy]);
    } else {
        $stmt = $pdo->query('SELECT id, taxonomy, term, slug FROM ' . table_name('taxonomy_terms') . ' ORDER BY taxonomy, term ASC LIMIT 400');
    }
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $items = [];
    foreach ($rows as $r) {
        $tax = $taxonomy ?: ($r['taxonomy'] ?? 'category');
        $slug = $r['slug'];
        $url = function_exists('permalink_for_term') ? permalink_for_term($tax, $slug) : ((defined('SITE_URL')?SITE_URL:'') . '/index.php?route=archive&taxonomy='.$tax.'&term=' . rawurlencode($slug));
        $items[] = [
            'id' => (int)($r['id'] ?? 0),
            'taxonomy' => $tax,
            'term' => $r['term'] ?? $slug,
            'slug' => $slug,
            'url' => $url,
            'type' => 'taxonomy'
        ];
    }
    echo json_encode(['status'=>'success','terms'=>$items]);
    exit;
}, 10, 1);

// Register Post Tags metabox for Posts (post_type = post)
add_admin_action('init', function() {
    qpmeta_register_metabox('post_tags_metabox', [
        'title' => 'Post Tags',
        'object_types' => ['post'],
        'post_types' => ['post'],
        'context' => 'side', // moved to right sidebar
        'fields' => [
            [
                'id' => 'post_tags',
                'name' => 'Tags',
                'type' => 'tags',
                'taxonomy' => 'post-tag',
                'taxonomy_menu_visible' => false,
                'desc' => 'Add tags to this post. Type to search or create.'
            ]
        ]
    ]);
});

if (function_exists('add_admin_action')) {
// AJAX save handler (single definition)   so plugin and theme dont need to add it repeatedly
add_admin_action('iitcm_admin_ajax_qpmeta_save', function($request) {
    $object_type = $request['object_type'] ?? '';
    $object_id   = isset($request['object_id']) ? (int)$request['object_id'] : 0;
    if (!$object_type || !$object_id) { echo json_encode(['success' => false, 'error' => 'Missing object_type or object_id']); exit; }
    $errors  = [];
    $success = qpmeta_save_metaboxes($object_type, $object_id, $request, $_FILES, $errors);
    echo json_encode(['success' => $success, 'error' => $errors ? implode('; ', $errors) : null]);
    exit;
}, 10, 1);
}

/* had to put there to ensure default image sizes are registered in the admin context and can be modified from themes and plugins by add_filter */
if (function_exists('add_admin_action')) add_admin_action('init', function(){ qp_register_default_image_sizes(); }, 20);

do_admin_action('init'); // Plugins and theme can hook here
do_action('init'); // Legacy hook for plugins that use the old naming convention

// Now fire per-screen hooks (screens helper was included earlier)
if (function_exists('qp_fire_screen_hooks')) {
    qp_fire_screen_hooks();
}
// else: screens helper not available; nothing to do
?>