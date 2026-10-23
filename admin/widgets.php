<?php
// admin/widgets.php
// Appearance > Widgets (core upgrade plan C6, part A): place widgets in the widget areas the active theme registers.

require_once __DIR__ . '/admin_head.php';

$page_title = 'Widgets';
if (!current_user_can('manage_widgets') && !current_user_can('manage_options')) {
    http_response_code(403);
    require_once __DIR__ . '/inc/header.php';
    require_once __DIR__ . '/inc/navbar.php';
    echo '<div class="container"><h1>Permission Denied</h1><p>You do not have permission to access this admin page.</p></div>';
    include __DIR__ . '/inc/footer.php';
    exit;
}

require_once __DIR__ . '/inc/header.php';
require_once __DIR__ . '/inc/navbar.php';

$cfg = [
    'ajax' => get_admin_ajax_url(),
    'nonce' => qp_create_nonce('qpw_front'),
    'state' => qp_widgets_admin_state(),
    'postTypes' => function_exists('get_post_types') ? array_map(function ($p) { return $p['label'] ?? ''; }, get_post_types()) : ['post' => 'Posts', 'page' => 'Pages'],
];
?>
<link rel="stylesheet" href="assets/css/qp-widgets.css?v=<?= (int)@filemtime(__DIR__ . "/assets/css/qp-widgets.css") ?>">
<div class="container-fluid mt-3">
  <h1 class="h3 mb-1">Widgets</h1>
  <p class="text-muted">Add widgets to the areas your theme provides, drag them into the order you want, or use the buttons and menus on each widget (they work with a keyboard and on a phone).</p>
  <?php if (!qp_widget_areas()): ?>
    <div class="alert alert-info">The active theme does not register any widget areas yet. A theme adds one with <code>register_widget_area('sidebar', ['name' =&gt; 'Sidebar'])</code> and prints it with <code>dynamic_widget_area('sidebar')</code>. Widgets you add to Inactive are kept until then.</div>
  <?php endif; ?>
  <div id="qpwf-msg" class="small mb-2" role="status" aria-live="polite"></div>
  <div id="qpwf" class="qpwf-layout" data-config="<?= htmlspecialchars(json_encode($cfg), ENT_QUOTES, 'UTF-8') ?>">
    <div><h2 class="h5">Available widgets</h2><div id="qpwf-types"></div></div>
    <div id="qpwf-areas"></div>
  </div>
</div>
<script src="assets/js/qp-widgets-front.js?v=<?= (int)@filemtime(__DIR__ . "/assets/js/qp-widgets-front.js") ?>"></script>
<?php include __DIR__ . '/inc/footer.php'; ?>
