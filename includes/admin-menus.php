<?php
if (!defined('QLOPY_INIT')) { http_response_code(403); exit; }
$config = require __DIR__ . '/../config.php';
$admins_dir = $config['admin_dir'] ?? 'admin';
$admin_dir_path = __DIR__ . "/../$admins_dir/";

// Dynamically figure Qlopy base path from current script URL
$current_script_path = dirname($_SERVER['SCRIPT_NAME']);
if (substr($current_script_path, -strlen($admins_dir)) === $admins_dir) {
    $qlopy_base_path = substr($current_script_path, 0, -strlen($admins_dir));
    $qlopy_base_path = rtrim($qlopy_base_path, '/');
} else {
    $qlopy_base_path = '';
}

$admin_url_path = $qlopy_base_path . '/' . trim($admins_dir, '/') . '/'; // e.g. "/advcms/admin/"

$admin_menus = [];

function register_admin_menu($slug, $title, $php_file = null, $callback = null, $parent = null, $screen_id = null, $icon = null) {
    global $admin_menus, $admin_dir_path, $admin_url_path;

    if (!$php_file && !$callback) {
        throw new InvalidArgumentException('Provide PHP file path or callback for admin menu');
    }

    if ($callback !== null) {
        $url = $admin_url_path . 'index.php?page=' . rawurlencode($slug);
    } else {
        $file_name = basename($php_file);
        $url = $admin_url_path . $file_name;
    }

    // store screen_id if provided. If absent, consumer code can use the
    // slug or URL to derive a screen id. Screen ids must be lowercase and
    // may contain dashes/underscores. This lets caller avoid a separate
    // registration step for screen mapping.
    $admin_menus[$slug] = [
        'title' => $title,
        'php_file' => $php_file,
        'callback' => $callback,
        'url' => $url,
        'parent' => $parent,
        'screen_id' => $screen_id,
        'icon' => $icon,
    ];
}


// Media Library
register_admin_menu('media', 'Media', $admin_dir_path . 'media.php', null, null, 'media_library','qp-qlopy-photo-video');
// Settings parent (no dedicated page file) — use index.php as harmless placeholder URL
/*register_admin_menu('users', 'Users', null, function () use ($admin_dir_path) {
    include $admin_dir_path . 'users.php';
});*/
register_admin_menu('comments', 'Comments', $admin_dir_path . 'comments.php', null, null, 'comments','qp-chat-1');
register_admin_menu('users', 'Users', $admin_dir_path . 'users.php', null, null, 'users','qp-users');
// Appearance
register_admin_menu('appearance', 'Appearance', $admin_dir_path . 'index.php', null, null, 'appearance','qp-color-brush');
register_admin_menu('menus', 'Menus', $admin_dir_path . 'menus.php', null, 'appearance', 'menus');
register_admin_menu('themes', 'Themes', $admin_dir_path . 'themes.php', null, 'appearance', 'themes');
// Settings
register_admin_menu('settings', 'Settings', $admin_dir_path . 'index.php', null, null, 'settings','qp-sliders');
register_admin_menu('general-settings', 'Genral Settings', $admin_dir_path . 'general-settings.php', null, 'settings', 'general-settings');
register_admin_menu('permalinks', 'Permalinks', $admin_dir_path . 'permalinks.php', null, 'settings', 'permalinks');
register_admin_menu('mail', 'Mail', $admin_dir_path . 'mail.php', null, 'settings', 'mail');
register_admin_menu('cron', 'Cron', $admin_dir_path . 'cron.php', null, 'settings', 'cron');
register_admin_menu('updates', 'Updates', $admin_dir_path . 'updates.php', null, 'settings', 'updates');

// Discussion (comments) settings
register_admin_menu('discussion', 'Discussion', $admin_dir_path . 'discussion.php', null, 'settings', 'discussion');

register_admin_menu('plugins', 'Plugins', $admin_dir_path . 'plugins.php', null, null, 'plugins','qp-plug');




