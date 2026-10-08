<?php
// admin/index.php
require_once __DIR__ . '/admin_head.php';
?>
<?php
//require_once __DIR__ . '/../includes/admin-menus.php';
$page = $_GET['page'] ?? 'dashboard';
global $admin_menus;

if ($page === 'dashboard') {
    include __DIR__ . '/dashboard.php';
    exit;
}

if (!isset($admin_menus[$page])) {
    echo "<h1>404 - Page Not Found</h1>";
    exit;
}

$menu_entry = $admin_menus[$page];
$page_title = htmlspecialchars($menu_entry['title']);
require_once __DIR__ . '/inc/header.php';
require_once __DIR__ . '/inc/navbar.php';
//echo "<h1>" . htmlspecialchars($menu['title']) . "</h1>";

if (  !current_user_can('manage_admin_pages')) {
     echo "<p>You do not have permission to access this page.</p>";
}else{

if (is_callable($menu_entry['callback'])) {
    call_user_func($menu_entry['callback']);
} elseif ($menu_entry['php_file'] && file_exists($menu_entry['php_file'])) {
    include $menu_entry['php_file'];
} else {
    echo "<p>No content available.</p>";
}
}
include __DIR__ . '/inc/footer.php';
?>
