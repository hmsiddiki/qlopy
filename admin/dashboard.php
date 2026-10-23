<?php
// admin/dashboard.php
// The Dashboard is the renderer of the admin widget registry (core upgrade plan C6, part B; includes/admin-widgets.php).

require_once __DIR__ . '/admin_head.php';

$page_title = 'Dashboard';
require_once __DIR__ . '/inc/header.php';
require_once __DIR__ . '/inc/navbar.php';
?>
<link rel="stylesheet" href="assets/css/qp-widgets.css?v=<?= (int)@filemtime(__DIR__ . "/assets/css/qp-widgets.css") ?>">
<div class="container-fluid mt-3">
  <h1 class="h3 mb-3">Dashboard</h1>
  <?php qp_admin_widgets_render('dashboard'); ?>
</div>
<script src="assets/js/qp-widgets.js?v=<?= (int)@filemtime(__DIR__ . "/assets/js/qp-widgets.js") ?>"></script>

<?php include __DIR__ . '/inc/footer.php'; ?>
