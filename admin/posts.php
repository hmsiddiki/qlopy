<?php
require_once __DIR__ . '/admin_head.php';

$post_type = $_GET['post_type'] ?? 'post';
$action = $_GET['action'] ?? '';

$registered_post_types = get_post_types();
if (!isset($registered_post_types[$post_type])) {
    die("Invalid post type.");
}
if (isset($_GET['action']) &&  $_GET['action'] == 'add') {
    $ptitle = 'Add New ';
}elseif (isset($_GET['action']) &&  $_GET['action'] == 'edit') {
    $ptitle = 'Edit ';
} else {
    $ptitle = 'Manage ';
}
$page_title = $ptitle . ($registered_post_types[$post_type]['label'] ?? ucfirst($post_type));

//require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/inc/header.php';
echo '<div class="container mt-4">';
//require_once __DIR__ . '/../includes/qpmeta.php'; // QPMeta for metaboxes

// Dynamic admin ajax url
$admin_base_path = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
$admin_ajax_url = $admin_base_path . '/ajax.php';

if (empty($action)) {
    // List posts
    // Status filter via GET param (WP-style links use ?status=...)
    $status = $_GET['status'] ?? 'all';

    // Counts for status links
    $counts = [];
    $cstmt = $pdo->prepare("SELECT COUNT(*) FROM " . table_name('posts') . " WHERE post_type = ? AND status != 'trash'");
    $cstmt->execute([$post_type]); $counts['all'] = (int)$cstmt->fetchColumn();
    $cstmt = $pdo->prepare("SELECT COUNT(*) FROM " . table_name('posts') . " WHERE post_type = ? AND status = 'published'");
    $cstmt->execute([$post_type]); $counts['published'] = (int)$cstmt->fetchColumn();
    $cstmt = $pdo->prepare("SELECT COUNT(*) FROM " . table_name('posts') . " WHERE post_type = ? AND status = 'draft'");
    $cstmt->execute([$post_type]); $counts['draft'] = (int)$cstmt->fetchColumn();
    $cstmt = $pdo->prepare("SELECT COUNT(*) FROM " . table_name('posts') . " WHERE post_type = ? AND status = 'trash'");
    $cstmt->execute([$post_type]); $counts['trash'] = (int)$cstmt->fetchColumn();

    // Filters: search, taxonomy, month
    $search = trim($_GET['s'] ?? '');
    $selected_date = $_GET['m'] ?? ''; // expected format YYYY-MM
    $tax_filters = [];
    $taxonomies = function_exists('get_taxonomies_for_post_type') ? get_taxonomies_for_post_type($post_type) : [];
    foreach ($taxonomies as $tax_key => $tax_args) {
      $param = $_GET['tax_' . $tax_key] ?? '';
      if ($param !== '') $tax_filters[$tax_key] = $param;
    }

    // Build WHERE parts and params
    $where = ["post_type = ?"];
    $params = [$post_type];

    if ($status === 'all') {
      $where[] = "status != 'trash'";
    } elseif ($status === 'trash') {
      $where[] = "status = 'trash'";
    } else {
      $where[] = "status = ?";
      $params[] = $status;
    }

    if ($search !== '') {
      $where[] = "(title LIKE ? OR content LIKE ? OR slug LIKE ?)";
      $like = '%' . str_replace('%','\\%',$search) . '%';
      $params[] = $like; $params[] = $like; $params[] = $like;
    }

    if ($selected_date) {
      // match YYYY-MM
      $where[] = "created_at LIKE ?";
      $params[] = $selected_date . '%';
    }

    // Allow plugins/themes to modify WHERE parts and params before building SQL
    if (function_exists('apply_filters')) {
      $modified = apply_filters('admin_posts_query_where', ['where' => $where, 'params' => $params, 'tax_filters' => $tax_filters, 'search' => $search, 'selected_date' => $selected_date, 'status' => $status], $post_type);
      if (is_array($modified)) {
        if (isset($modified['where']) && is_array($modified['where'])) $where = $modified['where'];
        if (isset($modified['params']) && is_array($modified['params'])) $params = $modified['params'];
      }
    }

    // Prepare default columns early so we can support ordering when building SQL
    $default_columns_for_query = [
      'cb' => ['label' => '<input type="checkbox" id="checkAll" />', 'class' => '', 'sortable' => false],
      'id' => ['label' => 'ID', 'class' => 'col-id', 'sortable' => false],
      'title' => ['label' => 'Title', 'class' => 'col-title', 'sortable' => false],
      'slug' => ['label' => 'Slug', 'class' => 'col-slug', 'sortable' => false],
      'status' => ['label' => 'Status', 'class' => 'col-status', 'sortable' => false],
      'created_at' => ['label' => 'Created At', 'class' => 'col-created', 'sortable' => true],
    ];
    if (function_exists('get_post_list_columns_for_type')) {
      $cols_meta_for_query = get_post_list_columns_for_type($post_type, $default_columns_for_query);
    } else {
      $cols_meta_for_query = $default_columns_for_query;
    }

    // Hide ID column by default unless the post type explicitly requests it
    $show_id_for_type = $registered_post_types[$post_type]['show_id_column'] ?? false;
    if (!$show_id_for_type && isset($cols_meta_for_query['id'])) {
      unset($cols_meta_for_query['id']);
    }

    // Determine ORDER BY clause using orderby/order GET params if column is sortable and maps to a real column
    $orderby = $_GET['orderby'] ?? '';
    $order = strtolower($_GET['order'] ?? 'desc');
    $order = ($order === 'asc') ? 'ASC' : 'DESC';
    $valid_post_cols = ['id','created_at','title','slug','status'];
    if ($orderby && isset($cols_meta_for_query[$orderby]) && !empty($cols_meta_for_query[$orderby]['sortable']) && in_array($orderby, $valid_post_cols)) {
      $sql_order = "ORDER BY " . $orderby . " " . $order;
    } else {
      $sql_order = "ORDER BY id DESC";
    }

    // Pagination params
    $per_page = isset($_GET['per_page']) ? max(1, min(200, (int)$_GET['per_page'])) : 20;
    $paged = max(1, (int)($_GET['paged'] ?? 1));
    $offset = ($paged - 1) * $per_page;

    // Build base WHERE SQL and add taxonomy filters as IN-subqueries (term ids are integers)
    $base_where_sql = implode(' AND ', $where);
    $base_sql = "FROM " . table_name('posts') . " WHERE " . $base_where_sql;
    if (!empty($tax_filters)) {
      foreach ($tax_filters as $tax => $term_id) {
        $term_id = (int)$term_id;
        if ($term_id > 0) {
          $base_sql .= " AND id IN (SELECT post_id FROM " . table_name('post_terms') . " WHERE term_id = " . $term_id . ")";
        }
      }
    }

    // Total count for pager
    $count_sql = "SELECT COUNT(*) AS cnt " . $base_sql;
    $count_stmt = $pdo->prepare($count_sql);
    $count_stmt->execute($params);
    $total_count = (int)$count_stmt->fetchColumn();
    $total_pages = max(1, (int)ceil($total_count / $per_page));

    // Final data query with ordering and LIMIT/OFFSET
    // LIMIT/OFFSET are inlined as integers to avoid drivers that quote bound values for LIMIT
    $sql = "SELECT id, title, slug, status, created_at " . $base_sql . " " . $sql_order . " LIMIT " . intval($per_page) . " OFFSET " . intval($offset);
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $posts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Prepare visible taxonomies (only those with screen_list !== false) and their term lists
    $visible_taxonomies = [];
    $terms_by_tax = [];
    if (!empty($taxonomies)) {
      foreach ($taxonomies as $tax_key => $tax_args) {
        if (isset($tax_args['screen_list']) && $tax_args['screen_list'] === false) continue;
        $visible_taxonomies[$tax_key] = $tax_args;
        $tstmt = $pdo->prepare("SELECT id, term FROM " . table_name('taxonomy_terms') . " WHERE taxonomy = ? ORDER BY term ASC");
        $tstmt->execute([$tax_key]);
        $terms_by_tax[$tax_key] = $tstmt->fetchAll(PDO::FETCH_ASSOC);
      }
    }

    // Months available
    $months = [];
    $mstmt = $pdo->prepare("SELECT DISTINCT DATE_FORMAT(created_at, '%Y-%m') AS ym FROM " . table_name('posts') . " WHERE post_type = ? ORDER BY ym DESC");
    $mstmt->execute([$post_type]);
    while ($r = $mstmt->fetch(PDO::FETCH_ASSOC)) { if ($r['ym']) $months[] = $r['ym']; }
    ?>
    
    <h2><?= htmlspecialchars($page_title) ?></h2>
 
    <div class="mb-3 d-flex justify-content-between align-items-center">
      <div>
        <a class="btn btn-success" href="posts.php?post_type=<?= urlencode($post_type) ?>&action=add">Add New <?= htmlspecialchars($registered_post_types[$post_type]['label'] ?? ucfirst($post_type)) ?></a>
      </div>
      <div class="status-links">
        <?php
          $base = 'posts.php?post_type=' . urlencode($post_type);
        ?>
        <a href="<?= $base ?>&status=all" <?= $status === 'all' ? 'style="font-weight:600"' : '' ?>>All (<?= $counts['all'] ?>)</a>
         | <a href="<?= $base ?>&status=published" <?= $status === 'published' ? 'style="font-weight:600"' : '' ?>>Published (<?= $counts['published'] ?>)</a>
         | <a href="<?= $base ?>&status=draft" <?= $status === 'draft' ? 'style="font-weight:600"' : '' ?>>Draft (<?= $counts['draft'] ?>)</a>
         | <a href="<?= $base ?>&status=trash" <?= $status === 'trash' ? 'style="font-weight:600"' : '' ?>>Trash (<?= $counts['trash'] ?>)</a>
      </div>
    </div>

    <div class="mb-2 parent-flex">
      <!-- Bulk actions (separate form, placed before filters) -->
      <form id="postsBulkForm" method="post" class="f-element" style="gap:8px;margin-right:12px;">
        <select id="bulkAction" name="bulk_action" class="form-control form-control-sm" style="width:auto;display:inline-block;">
          <option value="">Bulk Actions</option>
          <option value="trash">Move to Trash</option>
          <?php if ($status === 'trash'): ?>
            <option value="restore">Restore</option>
          <?php endif; ?>
          <option value="delete_permanent">Delete Permanently</option>
        </select>
        <button type="button" id="applyBulk" class="btn btn-sm btn-secondary">Apply</button>
      </form>

      <form id="postsListForm" method="get" class="f-element" style="gap:8px;">
        <input type="hidden" name="post_type" value="<?= htmlspecialchars($post_type) ?>" />
        <input type="hidden" name="status" value="<?= htmlspecialchars($status) ?>" />

        <?php if (!empty($visible_taxonomies)): foreach ($visible_taxonomies as $tax_key => $tax_args): ?>
          <select name="tax_<?= htmlspecialchars($tax_key) ?>" class="form-control form-control-sm" style="width:auto;">
            <option value=""><?= htmlspecialchars($tax_args['label'] ?? ucfirst($tax_key)) ?> (All)</option>
            <?php foreach ($terms_by_tax[$tax_key] ?? [] as $term): ?>
              <option value="<?= $term['id'] ?>" <?= (isset($tax_filters[$tax_key]) && (int)$tax_filters[$tax_key] === (int)$term['id']) ? 'selected' : '' ?>><?= htmlspecialchars($term['term']) ?></option>
            <?php endforeach; ?>
          </select>
        <?php endforeach; endif; ?>

        <select name="m" class="form-control form-control-sm" style="width:auto;">
          <option value="">All dates</option>
          <?php foreach ($months as $ym): $d = DateTime::createFromFormat('!Y-m', $ym); $label = $d ? $d->format('F Y') : $ym; ?>
            <option value="<?= $ym ?>" <?= $selected_date === $ym ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
          <?php endforeach; ?>
        </select>

        <button type="submit" class="btn btn-sm btn-secondary">Filter</button>
        <a href="posts.php?post_type=<?= urlencode($post_type) ?>&status=<?= urlencode($status) ?>" class="btn btn-sm btn-outline-secondary">Reset</a>

        <!-- bulk controls moved to their own form above -->

        <?php
          // Hook: allow plugins/themes to inject additional filter controls for this post type.
          if (function_exists('do_action')) {
              do_action('admin_posts_filter_area', $post_type, $visible_taxonomies, $terms_by_tax, $tax_filters, $selected_date);
          }
        ?>
      </form>

      <form id="postsSearchForm" method="get" class="f-element" style="gap:6px;">
        <input type="hidden" name="post_type" value="<?= htmlspecialchars($post_type) ?>" />
        <input type="hidden" name="status" value="<?= htmlspecialchars($status) ?>" />
        <?php foreach ($tax_filters as $tk => $tv): ?>
          <input type="hidden" name="tax_<?= htmlspecialchars($tk) ?>" value="<?= htmlspecialchars($tv) ?>" />
        <?php endforeach; ?>
        <input type="search" name="s" class="form-control form-control-sm" placeholder="Search posts..." value="<?= htmlspecialchars($search) ?>" style="width:150px;" />
        <button type="submit" class="btn btn-sm btn-secondary">Search</button>
      </form>
    </div>

      <table class="table table-striped table-bordered table-responsive-md">
        <thead class="thead-dark">
          <tr>
            <?php
                // Default columns. Plugins/themes can modify via filters or register helpers.
                $default_columns = [
                'cb' => ['label' => '<input type="checkbox" id="checkAll" />', 'class' => '', 'sortable' => false],
                'id' => ['label' => 'ID', 'class' => 'col-id', 'sortable' => false],
                'title' => ['label' => 'Title', 'class' => 'col-title', 'sortable' => false],
                'slug' => ['label' => 'Slug', 'class' => 'col-slug', 'sortable' => false],
                'status' => ['label' => 'Status', 'class' => 'col-status', 'sortable' => false],
                'created_at' => ['label' => 'Created At', 'class' => 'col-created', 'sortable' => true],
                ];

                // If the post-types helper exists, prefer its merged columns (supports position)
                if (isset($cols_meta_for_query)) {
                  $cols_meta = $cols_meta_for_query;
                } elseif (function_exists('get_post_list_columns_for_type')) {
                  $cols_meta = get_post_list_columns_for_type($post_type, $default_columns);
                } else {
                  // allow filters that return simple arrays (backwards compatible)
                  if (function_exists('apply_filters')) {
                    $maybe = apply_filters("manage_{$post_type}_posts_columns", array_map(function($v){ return is_array($v) ? $v['label'] : $v; }, $default_columns), $post_type);
                    $maybe = apply_filters('manage_posts_columns', $maybe, $post_type);
                  } else {
                    $maybe = array_map(function($v){ return is_array($v) ? $v['label'] : $v; }, $default_columns);
                  }
                  // normalize into meta shape
                  $cols_meta = [];
                  foreach ($maybe as $k => $v) {
                    if (is_array($v)) {
                      $cols_meta[$k] = array_merge(['label'=> $k, 'class'=>'', 'sortable'=>false], $v);
                    } else {
                      $cols_meta[$k] = ['label' => (string)$v, 'class' => '', 'sortable' => false];
                    }
                  }
                }
                // Hide ID column by default unless post type opts-in via `show_id_column` flag
                $show_id_for_type = $registered_post_types[$post_type]['show_id_column'] ?? false;
                if (!$show_id_for_type && isset($cols_meta['id'])) {
                  unset($cols_meta['id']);
                }

                // Allow plugins to change column meta (classes/labels/sortable)
                if (function_exists('apply_filters')) {
                  $cols_meta = apply_filters("manage_{$post_type}_posts_columns_meta", $cols_meta, $post_type);
                  $cols_meta = apply_filters('manage_posts_columns_meta', $cols_meta, $post_type);
                }

                // Determine current orderby/order from GET
                $orderby = $_GET['orderby'] ?? '';
                $order = strtolower($_GET['order'] ?? 'desc');
                foreach ($cols_meta as $col_key => $meta) {
                $th_class = isset($meta['class']) ? ' class="' . htmlspecialchars($meta['class']) . '"' : '';
                echo '<th data-col="' . htmlspecialchars($col_key) . '"' . $th_class . '>';
                // sortable header link
                $label = $meta['label'] ?? $col_key;
                if (!empty($meta['sortable'])) {
                  // toggle order
                  $next_order = ($orderby === $col_key && $order === 'asc') ? 'desc' : 'asc';
                  $qs = $_GET; $qs['orderby'] = $col_key; $qs['order'] = $next_order;
                  $url = htmlspecialchars('posts.php?' . http_build_query($qs));
                  echo '<a href="' . $url . '">' . htmlspecialchars((string)$label) . '</a>';
                  if ($orderby === $col_key) echo ' <small>(' . strtoupper($order) . ')</small>';
                } else {
                  echo $label;
                }
                echo '</th>';
                }
            ?>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($posts as $post): ?>
          <tr data-post-id="<?= $post['id'] ?>">
            <?php foreach ($cols_meta as $col_key => $meta): ?>
              <td data-col="<?= htmlspecialchars($col_key) ?>"<?= !empty($meta['class']) ? ' class="' . htmlspecialchars($meta['class']) . '"' : '' ?>>
                <?php
                  // Allow post-type-specific or generic handlers to output column content.
                  $handled = false;
                  $action_specific = "manage_{$post_type}_posts_custom_column";
                  $action_generic = 'manage_posts_custom_column';
                  if (function_exists('do_action')) {
                      $handled = do_action($action_specific, $col_key, $post) || do_action($action_generic, $col_key, $post);
                  }

                  // If not handled by actions, check if meta provides a render callable
                  if (!$handled && !empty($meta['render']) && is_callable($meta['render'])) {
                      call_user_func($meta['render'], $post, $col_key, $post_type);
                      $handled = true;
                  }

                  if (!$handled) {
                      // Default rendering for built-in columns
                      switch ($col_key) {
                        case 'cb':
                          echo '<input type="checkbox" class="rowCheck" value="' . htmlspecialchars($post['id']) . '" />';
                          break;
                        case 'id':
                          echo (int)$post['id'];
                          break;
                        case 'title':
                          $title_html = htmlspecialchars($post['title']);
                          echo $title_html;
                          // Row actions under title (WP-style links)
                          $rowLinks = [];
                          if ($post['status'] === 'trash') {
                            $rowLinks[] = '<a href="#" class="row-action-restore" data-id="' . htmlspecialchars($post['id']) . '">Restore</a>';
                            $rowLinks[] = '<a href="#" class="row-action-delete-perm text-danger" data-id="' . htmlspecialchars($post['id']) . '">Delete Permanently</a>';
                          } else {
                            $rowLinks[] = '<a href="posts.php?post_type=' . urlencode($post_type) . '&action=edit&id=' . urlencode($post['id']) . '">Edit</a>';
                            $rowLinks[] = '<a href="#" class="row-action-trash text-warning" data-id="' . htmlspecialchars($post['id']) . '">Move to Trash</a>';
                          }
                          echo '<div class="row-actions small" style="margin-top:6px">' . implode(' | ', $rowLinks) . '</div>';
                          break;
                        case 'slug':
                          echo htmlspecialchars($post['slug']);
                          break;
                        case 'status':
                          echo htmlspecialchars($post['status']);
                          break;
                        case 'created_at':
                          if (function_exists('format_site_datetime')) {
                            echo htmlspecialchars(format_site_datetime($post['created_at']));
                          } else {
                            echo htmlspecialchars($post['created_at']);
                          }
                          break;
                        case 'actions':
                          // Actions are now shown under the Title column as links.
                          // Keep this column empty to avoid duplicate controls unless a plugin populates it.
                          break;
                        default:
                          // Unknown column; nothing to render by default
                          break;
                      }
                  }
                ?>
              </td>
            <?php endforeach; ?>
          </tr>
          <?php endforeach ?>
        </tbody>
      </table>
      </table>

      <?php // Pager controls ?>
      <?php if (!empty($total_pages) && $total_pages > 1): ?>
        <nav aria-label="Posts pagination" class="mt-2">
          <ul class="pagination">
            <?php
              $qs = $_GET;
              for ($p = 1; $p <= $total_pages; $p++):
                $qs['paged'] = $p;
                $qs['per_page'] = $per_page;
                $url = 'posts.php?' . http_build_query($qs);
            ?>
            <li class="page-item <?= $p === $paged ? 'active' : '' ?>"><a class="page-link" href="<?= htmlspecialchars($url) ?>"><?= $p ?></a></li>
            <?php endfor; ?>
          </ul>
        </nav>
      <?php endif; ?>

      </form>
    <script>
    jQuery(function($){
      var adminAjax = '<?= $admin_ajax_url ?>';

      // Per-row: move to trash
      $(document).on('click', '.btn-trash-post', function(){
        if(!confirm('Move this post to Trash?')) return;
        var id = $(this).data('id');
        $.post(adminAjax, { action: 'trash_post', post_id: id }, function(resp){
          if (resp && resp.status === 'success') location.reload();
          else alert('Error: ' + (resp && resp.message ? resp.message : 'Unknown'));
        }, 'json');
      });

      // Per-row: restore
      $(document).on('click', '.btn-restore-post', function(){
        var id = $(this).data('id');
        $.post(adminAjax, { action: 'restore_post', post_id: id }, function(resp){
          if (resp && resp.status === 'success') location.reload();
          else alert('Error: ' + (resp && resp.message ? resp.message : 'Unknown'));
        }, 'json');
      });

      // Per-row: permanent delete
      $(document).on('click', '.btn-delete-perm', function(){
        if(!confirm('Permanently delete this post and its attachments? This cannot be undone.')) return;
        var id = $(this).data('id');
        $.post(adminAjax, { action: 'delete_post_permanent', post_id: id }, function(resp){
          if (resp && resp.status === 'success') location.reload();
          else alert('Error: ' + (resp && resp.message ? resp.message : 'Unknown'));
        }, 'json');
      });

      // Bulk: select all
      $('#checkAll').on('change', function(){ $('.rowCheck').prop('checked', $(this).prop('checked')); });

      // Bulk apply: send a single request to `bulk_posts` with action and ids
      $('#applyBulk').on('click', function(){
        var act = $('#bulkAction').val();
        if (!act) return alert('Select a bulk action');
        var ids = $('.rowCheck:checked').map(function(){ return $(this).val(); }).get();
        if (!ids.length) return alert('Select at least one post');
        var confirmMsg = act === 'trash' ? 'Move selected posts to Trash?' : (act === 'restore' ? 'Restore selected posts?' : 'Permanently delete selected posts? This cannot be undone.');
        if (!confirm(confirmMsg)) return;

        $.post(adminAjax, { action: 'bulk_posts', bulk_action: act, ids: ids }, function(resp){
          // Expecting JSON { success: true, processed: <n>, errors: [...] } or similar
          if (resp && (resp.success || resp.status === 'success')) {
            var processed = resp.processed || resp.count || 0;
            // Optionally inform the user about processed count
            if (processed) {
              alert('Processed ' + processed + ' items.');
            }
            location.reload();
          } else {
            var err = resp && (resp.error || resp.message) ? (resp.error || resp.message) : 'Unknown error';
            alert('Bulk action failed: ' + err);
          }
        }, 'json').fail(function(){ alert('Bulk request failed (network or server error).'); });
      });

      // Row-actions under title (WP-style links)
      $(document).on('click', '.row-action-trash', function(e){
        e.preventDefault();
        if(!confirm('Move this post to Trash?')) return;
        var id = $(this).data('id');
        $.post(adminAjax, { action: 'trash_post', post_id: id }, function(resp){
          if (resp && resp.status === 'success') location.reload();
          else alert('Error: ' + (resp && resp.message ? resp.message : 'Unknown'));
        }, 'json');
      });

      $(document).on('click', '.row-action-restore', function(e){
        e.preventDefault();
        var id = $(this).data('id');
        $.post(adminAjax, { action: 'restore_post', post_id: id }, function(resp){
          if (resp && resp.status === 'success') location.reload();
          else alert('Error: ' + (resp && resp.message ? resp.message : 'Unknown'));
        }, 'json');
      });

      $(document).on('click', '.row-action-delete-perm', function(e){
        e.preventDefault();
        if(!confirm('Permanently delete this post and its attachments? This cannot be undone.')) return;
        var id = $(this).data('id');
        $.post(adminAjax, { action: 'delete_post_permanent', post_id: id }, function(resp){
          if (resp && resp.status === 'success') location.reload();
          else alert('Error: ' + (resp && resp.message ? resp.message : 'Unknown'));
        }, 'json');
      });
    });
    </script>
    <?php
} elseif (in_array($action, ['add', 'edit'])) {
    $post_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
    $post_data = null;
    if ($action === 'edit' && $post_id > 0) {
        $stmt = $pdo->prepare("SELECT * FROM " . table_name('posts') . " WHERE id = ? AND post_type = ?");
        $stmt->execute([$post_id, $post_type]);
        $post_data = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$post_data) die('Post not found');
    }
    $taxonomies = get_taxonomies_for_post_type($post_type);

    // Helper to render hierarchical select options with indent for parent dropdown
    function render_parent_options($parent_id, $terms_by_parent, $depth, $edit_term_parent_id, $exclude_id = 0) {
        if (empty($terms_by_parent[$parent_id])) return;
        foreach ($terms_by_parent[$parent_id] as $term) {
            if ($term['id'] == $exclude_id) continue; // Prevent self as parent
            $selected = ($term['id'] == $edit_term_parent_id) ? 'selected' : '';
            echo '<option value="' . $term['id'] . '" ' . $selected . '>';
            echo str_repeat('&nbsp;&nbsp;&nbsp;', $depth) . htmlspecialchars($term['term']);
            echo '</option>';
            render_parent_options($term['id'], $terms_by_parent, $depth + 1, $edit_term_parent_id, $exclude_id);
        }
    }
    ?>
    <h2><?= $action === 'add' ? 'Add New' : 'Edit' ?> <?= htmlspecialchars($registered_post_types[$post_type]['label'] ?? ucfirst($post_type)) ?></h2>

    <?php
      $pt_args = $registered_post_types[$post_type] ?? [];
      $pt_supports = $pt_args['supports'] ?? ['title','slug','editor','comments'];
      $supports_has = function($name) use ($pt_supports) {
        if (!is_array($pt_supports)) return false;
        foreach ($pt_supports as $k => $v) {
          if ((is_int($k) && $v === $name) || (is_string($k) && $k === $name)) return true;
        }
        return false;
      };
    ?>

    <form id="postForm" enctype="multipart/form-data">
      <input type="hidden" name="post_id" value="<?= $post_data['id'] ?? 0 ?>" />
      <input type="hidden" name="post_type" value="<?= htmlspecialchars($post_type) ?>" />
      <div class="row">
        <div class="col-md-8">
          <div id="formResult" class="mt-3"></div>
          <?php if ($supports_has('title')): ?>
          <div class="form-group">
            <label>Title</label>
            <input type="text" name="title" class="form-control" <?= $action === 'add' ? 'required' : '' ?> value="<?= htmlspecialchars($post_data['title'] ?? '') ?>" />
            <?php
              // If multilang enabled for this post type, render per-language title inputs
              $ml_opt = function_exists('ml_get_option') ? ml_get_option() : [];
              $enabled_pts = $ml_opt['components']['post_types'] ?? [];
              if (!empty($enabled_pts[$post_type]) && function_exists('ml_get_languages')) {
                  $langs = ml_get_languages();
                  $i18n = get_post_meta($post_data['id'] ?? 0, 'i18n_title') ?: [];
                  if (!is_array($i18n)) {
                      if (is_string($i18n) && strlen($i18n)>0) { $dec = json_decode($i18n,true); if (json_last_error()===JSON_ERROR_NONE && is_array($dec)) $i18n=$dec; else $i18n=[]; } else { $i18n = []; }
                  }
                  $default = ml_get_default_lang();
                  foreach ($langs as $lang => $ldata) {
                      if ($lang === $default) continue;
                      $label = htmlspecialchars($ldata['label'] ?? $lang);
                      $val = $i18n[$lang] ?? '';
                      echo "<div style='margin-top:6px;'><label>" . htmlspecialchars($registered_post_types[$post_type]['label'] ?? $post_type) . " Title (" . $label . ")</label>";
                      echo "<input type='text' name='title__i18n[" . htmlspecialchars($lang) . "]' class='form-control' value='" . htmlspecialchars($val) . "' />";
                      echo "</div>";
                  }
              }
            ?>
          </div>
          <?php else: ?>
            <!-- Title not supported for this post type; server will auto-generate. -->
          <?php endif; ?>

        
          <?php
            // Build a permalink template for this post type where "%slug%" will be substituted client-side.
            // Use permalink_for_page for the `page` post type so root-page patterns ('/%slug%') render without a '/page/' prefix.
            if ($post_type === 'page' && function_exists('permalink_for_page')) {
              $permalink_template = permalink_for_page('%slug%');
            } elseif (function_exists('permalink_for_post')) {
              $permalink_template = permalink_for_post('%slug%', $post_type);
              // Prefer the registered post type `slug` for the admin preview
              if (function_exists('get_post_types')) {
                $rpts = get_post_types();
                $reg_slug = $rpts[$post_type]['slug'] ?? '';
                $reg_slug = is_string($reg_slug) ? trim($reg_slug, '/') : '';
                if ($reg_slug !== '' && $reg_slug !== $post_type) {
                  $base_site = defined('SITE_URL') ? SITE_URL : ((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? '');
                  // Replace first occurrence of /{post_type}/ with /{reg_slug}/ in the path portion only
                  if ($base_site && str_starts_with($permalink_template, $base_site)) {
                    $path = substr($permalink_template, strlen($base_site));
                    $newPath = preg_replace('#/'.preg_quote($post_type, '#').'/#', '/'.$reg_slug.'/', $path, 1);
                    $permalink_template = $base_site . $newPath;
                  } else {
                    $permalink_template = preg_replace('#/'.preg_quote($post_type, '#').'/#', '/'.$reg_slug.'/', $permalink_template, 1);
                  }
                }
              }
            } else {
                $base_site = defined('SITE_URL') ? SITE_URL : ((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? '');
                $permalink_template = $base_site . '/index.php?route=singular&post_type=' . rawurlencode($post_type) . '&slug=%slug%';
            }
            $pos = strpos($permalink_template, '%slug%');
            if ($pos !== false) {
                $permalink_prefix = substr($permalink_template, 0, $pos);
                $permalink_suffix = substr($permalink_template, $pos + 6);
            } else {
                $permalink_prefix = $permalink_template;
                $permalink_suffix = '';
            }
          ?>

          <?php if ($supports_has('slug')): ?>
          <div class="input-group mb-3" id="slug-input-group" data-template="<?= htmlspecialchars($permalink_template, ENT_QUOTES) ?>">
            <div class="input-group-prepend">
              <span class="input-group-text" id="slug-addon3"><?= htmlspecialchars($permalink_prefix) ?></span>
            </div>
            <input type="text" class="form-control" id="slug-url" name="slug" aria-describedby="slug-addon3" value="<?= htmlspecialchars($post_data['slug'] ?? '') ?>" <?php if ($action === 'edit') echo 'readonly'; ?> >
            <?php if ($permalink_suffix !== ''): ?>
            <div class="input-group-append"><span class="input-group-text" id="slug-addon-suffix"><?= htmlspecialchars($permalink_suffix) ?></span></div>
            <?php endif; ?>
            <?php if ($action === 'edit' && $post_id > 0) { ?>
              <div class="input-group-append">
                <button class="btn btn-outline-secondary" type="button">Edit</button>
              </div>
            <?php } ?>
          </div>

          <div class="mb-2"><small>Permalink: <a href="#" id="slug-full-preview" target="_blank"><?= htmlspecialchars(str_replace('%slug%', ($post_data['slug'] ?? ''), $permalink_template)) ?></a></small></div>
          <?php else: ?>
            <input type="hidden" name="slug" value="<?= htmlspecialchars($post_data['slug'] ?? '') ?>" />
          <?php endif; ?>

          <?php if ($supports_has('editor')): ?>
          <div class="form-group">
            <label>Content</label>
            <textarea name="content" class="form-control" rows="12" <?= $action === 'add' ? 'required' : '' ?>><?= htmlspecialchars($post_data['content'] ?? '') ?></textarea>
            <?php
              // Per-language content inputs
              if (!empty($enabled_pts[$post_type]) && function_exists('ml_get_languages')) {
                  $langs = ml_get_languages();
                  $i18n = get_post_meta($post_data['id'] ?? 0, 'i18n_content') ?: [];
                  if (!is_array($i18n)) {
                      if (is_string($i18n) && strlen($i18n)>0) { $dec = json_decode($i18n,true); if (json_last_error()===JSON_ERROR_NONE && is_array($dec)) $i18n=$dec; else $i18n=[]; } else { $i18n = []; }
                  }
                  $default = ml_get_default_lang();
                  foreach ($langs as $lang => $ldata) {
                      if ($lang === $default) continue;
                      $label = htmlspecialchars($ldata['label'] ?? $lang);
                      $val = $i18n[$lang] ?? '';
                      echo "<div style='margin-top:6px;'><label>Content (" . $label . ")</label>";
                      echo "<textarea name='content__i18n[" . htmlspecialchars($lang) . "]' class='form-control qpmeta-richtext' rows='6'>" . htmlspecialchars($val) . "</textarea>";
                      echo "</div>";
                  }
              }
            ?>
            <!-- Tabs and TinyMCE initialized by editor-init.js -->
          </div>
          <?php else: ?>
            <!-- Editor not supported for this post type; content will be empty/auto-managed. -->
          <?php endif; ?>

          <?php // Left-side metaboxes (normal) - only render if editing existing post
            if ($action === 'edit' && $post_id > 0) {
              qpmeta_render_metaboxes_in_context('post', $post_data['id'] ?? null, 'normal');
            }
          ?>
        </div>
        <div class="col-md-4">
          <div class="card mb-3">
            <div class="card-header">Publish</div>
            <div class="card-body">
              <div class="mb-2">
                
                <div id="postStatusLine" class="d-flex justify-content-between align-items-center">
                 <span id="postStatusLabel"><span class="d-inline">Status: </span><?= htmlspecialchars(ucfirst(str_replace('_',' ',$post_data['status'] ?? 'draft'))) ?></span>
                  <?php if (($action === 'edit' && $post_id > 0) || $action === 'add'): ?>
                  <a href="#" id="postStatusEditLink">Edit</a>
                  <?php endif; ?>
                </div>
                <div id="postStatusEdit" style="display:none;margin-top:8px;">
                  <div class="d-flex">
                    <select name="status" id="postStatus" class="form-control">
                      <?php foreach (['draft','published','pending_review','scheduled'] as $statusOpt): ?>
                        <option value="<?= $statusOpt ?>" <?= ($post_data['status'] ?? '') === $statusOpt ? 'selected' : '' ?>><?= ucfirst(str_replace('_',' ',$statusOpt)) ?></option>
                      <?php endforeach; ?>
                    </select>
                    <div class="ms-2 d-flex">
                      <button type="button" id="postStatusOk" class="btn btn-sm btn-primary">OK</button>
                      <button type="button" id="postStatusCancel" class="btn btn-sm btn-secondary">Cancel</button>
                    </div>
                  </div>
                </div>
              </div>

              <div class="mb-2">
                
                <div id="postVisibilityLine" class="d-flex justify-content-between align-items-center">
                  <?php $vis = $post_data['visibility'] ?? 'public'; ?>
                  <div><span class="d-inline">Visibility: </span ><span id="postVisibilityLabel"><?= htmlspecialchars(ucfirst($vis === 'password' ? 'Password protected' : $vis)) ?></span></div>
                  <?php if (($action === 'edit' && $post_id > 0) || $action === 'add'): ?>
                    <div><a href="#" id="postVisibilityEditLink">Edit</a></div>
                  <?php endif; ?>
                </div>
                <div id="postVisibilityEdit" style="display:none;margin-top:8px;">
                  <div>
                    <div class="form-check"><input class="form-check-input" type="radio" name="visibility" id="visPublic" value="public"><label class="form-check-label" for="visPublic">Public</label></div>
                    <div class="form-check"><input class="form-check-input" type="radio" name="visibility" id="visPassword" value="password"><label class="form-check-label" for="visPassword">Password protected</label></div>
                    <div class="form-check"><input class="form-check-input" type="radio" name="visibility" id="visPrivate" value="private"><label class="form-check-label" for="visPrivate">Private</label></div>
                    <div id="passwordField" style="display:none;margin-top:8px;"><input type="text" name="post_password" class="form-control" placeholder="Password to view" value="<?= htmlspecialchars($post_data['post_password'] ?? '') ?>" /></div>
                    <div class="mt-2"><button type="button" id="postVisibilityOk" class="btn btn-sm btn-primary">OK</button> <button type="button" id="postVisibilityCancel" class="btn btn-sm btn-secondary">Cancel</button></div>
                  </div>
                </div>
              </div>

              <div class="mb-2">
                
                <?php
                  $pa = $post_data['published_at'] ?? '';
                  $pa_date = '';
                  $pa_time = '';
                  $pa_label = '';
                  if ($pa) {
                      if (function_exists('format_site_datetime')) {
                          $pa_date = format_site_datetime($pa, 'Y-m-d');
                          $pa_time = format_site_datetime($pa, 'H:i');
                          $pa_label = format_site_datetime($pa);
                      } else {
                          $ts = strtotime($pa);
                          $pa_date = $ts ? date('Y-m-d', $ts) : '';
                          $pa_time = $ts ? date('H:i', $ts) : '';
                          $pa_label = $ts ? date('Y-m-d H:i:s', $ts) : $pa;
                      }
                  }
                ?>
                <div id="postPublishLine" class="d-flex justify-content-between align-items-center">
                  <div><span id="postPublishLabelContainer" >Publish: </span><span id="postPublishLabel" ><small><?= $pa ? htmlspecialchars($pa_label) : 'Immediate' ?></small></span> </div>
                  <?php if (($action === 'edit' && $post_id > 0) || $action === 'add'): ?>
                    <div><a href="#" id="postPublishEditLink">Edit</a></div>
                  <?php endif; ?>
                </div>
                <div id="postPublishEdit" style="display:none;margin-top:8px;">
                  <div class="d-flex">
                    <input type="date" id="publishDate" class="form-control me-2" value="<?= $pa_date ?>" />
                    <input type="time" id="publishTime" class="form-control" value="<?= $pa_time ?>" />
                  </div>
                  <input type="hidden" name="published_at" id="publishedAtHidden" value="<?= htmlspecialchars($post_data['published_at'] ?? '') ?>">
                  <div class="mt-2 "><button type="button" id="postPublishOk" class="btn btn-sm btn-primary">OK</button> <button type="button" id="postPublishCancel" class="btn btn-sm btn-secondary">Cancel</button></div>
                  <small class="form-text text-muted">Leave blank for immediate publish or set a future date to schedule.</small>
                </div>
              </div>
            </div>
              <div class="card-footer">
              <?php if ($supports_has('comments')): ?>
                <div class="form-group mb-2">
                  <?php
                    $co = null;
                    if ($post_data) {
                        $pm = get_post_meta($post_data['id'], 'comments_open');
                        if ($pm !== null) $co = (bool)$pm;
                    }
                    if ($co === null) {
                        $ds = function_exists('get_option_meta') ? (get_option_meta('discussion_settings') ?? []) : [];
                        $co = isset($ds['comments_open_default']) ? (bool)$ds['comments_open_default'] : true;
                    }
                  ?>
                  <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="comments_open" name="comments_open" value="1" <?= $co ? 'checked' : '' ?> />
                    <label class="form-check-label" for="comments_open">Allow comments</label>
                  </div>
                </div>
              <?php endif; ?>
              <button type="submit" id="primaryPublishBtn" class="btn btn-primary w-100"><?= $action === 'add' ? 'Publish' : 'Update' ?></button>
            </div>
          </div>

          <?php // Page Template selector (only for page post type)
            if ($post_type === 'page'):
              // Determine active theme directory
              $td = ($GLOBALS['theme_dir'] ?? ($theme_dir_root . ($active_theme ?? '') . '/'));
              $templates = [];
              if (is_dir($td)) {
                  $files = glob($td . 'template-*.php');
                  if ($files) {
                      foreach ($files as $file) {
                          $basename = basename($file);
                          // Read first chunk to find Template Name
                          $head = file_get_contents($file, false, null, 0, 8192);
                          $name = null;
                          if (preg_match('/Template\s*Name\s*:\s*(.+)/i', $head, $m)) {
                              $name = trim($m[1]);
                          }
                          if (!$name) {
                              // Fallback to filename without extension
                              $name = ucwords(str_replace(['template-','-','_','.php'], ['','',' ','',''], $basename));
                          }
                          $templates[$basename] = $name;
                      }
                  }
              }
              $current_template = ($post_data && !empty($post_data['id'])) ? get_post_meta($post_data['id'], 'page_template') : null;
          ?>
          <div class="form-group">
            <label>Page Template</label>
            <select name="page_template" class="form-control">
              <option value="">Default</option>
              <?php foreach ($templates as $file => $label): ?>
                <option value="<?= htmlspecialchars($file) ?>" <?= ($current_template === $file) ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
              <?php endforeach; ?>
            </select>
            <small class="form-text text-muted">Choose a custom template from the active theme (files named <code>template-*.php</code>).</small>
          </div>
          <?php endif; ?>

      <?php if (!empty($taxonomies)): ?>
      <div class="form-group">
      
        <?php foreach ($taxonomies as $tax_key => $tax_args):
          // Skip rendering this taxonomy on the post edit screen when registry
          // indicates it should not be listed there (e.g. the `post-tag` taxonomy
          // is managed by the QPMeta tags metabox and should not render here).
          if (($tax_args['screen_list'] ?? true) === false) continue;
          $stmt = $pdo->prepare("SELECT id, term, parent_id, term_order FROM " . table_name('taxonomy_terms') . " WHERE taxonomy = ? ORDER BY COALESCE(parent_id,0), term_order ASC, term ASC");
          $stmt->execute([$tax_key]);
          $terms = $stmt->fetchAll(PDO::FETCH_ASSOC);

          $post_terms = [];
          if ($post_data) {
              $stmt2 = $pdo->prepare("SELECT term_id FROM " . table_name('post_terms') . " WHERE post_id = ?");
              $stmt2->execute([$post_data['id']]);
              $post_terms = $stmt2->fetchAll(PDO::FETCH_COLUMN);
          }

          // Build terms tree indexed by parent_id
          $terms_by_parent = [];
          foreach ($terms as $term) {
              $parent_id = $term['parent_id'] ?? 0;
              if (!isset($terms_by_parent[$parent_id])) {
                  $terms_by_parent[$parent_id] = [];
              }
              $terms_by_parent[$parent_id][] = $term;
          }

            // Helper function to render taxonomies recursively as checkboxes
            if (!function_exists('render_terms_tree')) {
            function render_terms_tree($parent_id, $terms_by_parent, $post_terms, $depth, $tax_key) {
              if (empty($terms_by_parent[$parent_id])) return;
              foreach ($terms_by_parent[$parent_id] as $term) {
                $checked = in_array($term['id'], $post_terms) ? 'checked' : '';
                ?>
                <div class="form-check" style="margin-left: <?= 20 * $depth ?>px;">
                  <input type="checkbox"
                     class="form-check-input term-checkbox"
                     id="tax-<?= htmlspecialchars($tax_key) ?>-term-<?= $term['id'] ?>"
                     name="terms[<?= htmlspecialchars($tax_key) ?>][]"
                     value="<?= $term['id'] ?>"
                     <?= $checked ?>>
                  <label class="form-check-label" for="tax-<?= htmlspecialchars($tax_key) ?>-term-<?= $term['id'] ?>">
                    <?= htmlspecialchars($term['term']) ?>
                  </label>
                </div>
                <?php
                render_terms_tree($term['id'], $terms_by_parent, $post_terms, $depth + 1, $tax_key);
              }
            }
            }
          ?>
          <div class="border p-2 mb-3" data-taxonomy="<?= htmlspecialchars($tax_key) ?>">
            <strong><?= htmlspecialchars($tax_args['label'] ?? ucfirst($tax_key)) ?></strong>
            <div class="taxonomy-terms">
              <?php render_terms_tree(0, $terms_by_parent, $post_terms, 0, $tax_key); ?>
            </div>

            <div class="mt-3 border-top pt-2">
              <label>Add new <?= htmlspecialchars($tax_args['label'] ?? $tax_key) ?> term</label>
              <div class="form-row align-items-center">
                <div class="col-auto">
                  <input type="text" class="form-control form-control-sm new-term-input" placeholder="New term name" />
                </div>
                <?php if (!empty($tax_args['hierarchical'])): ?>
                <div class="col-auto">
                  <select class="form-control form-control-sm new-term-parent">
                    <option value="">No parent</option>
                    <?php render_parent_options(0, $terms_by_parent, 0, null); ?>
                  </select>
                </div>
                <?php endif; ?>
                <div class="col-auto">
                  <button class="btn btn-sm btn-success add-term-btn" type="button">Add</button>
                </div>
                <div class="col-auto add-term-result text-success"></div>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

          <?php // Right-side metaboxes (side) - render featured image inline after taxonomies, then render remaining side metaboxes
            if ($action === 'edit' && $post_id > 0) {
              $pt_args = $registered_post_types[$post_type] ?? [];
              if (!empty($pt_args['has_featured'])) {
                  // Render a compact featured image field here (hidden id + preview area) so it appears after taxonomies
                  if ($post_data) {
                    $pid = (int)($post_data['id'] ?? 0);
                    $thumb_id = function_exists('get_post_thumbnail_id') ? get_post_thumbnail_id($pid) : null;
                    if (!empty($thumb_id)) {
                      $current_feat = (string)$thumb_id;
                    } else {
                      $current_feat = get_post_meta($pid, 'featured_image') ?? '';
                    }
                  } else {
                    $current_feat = '';
                  }
                  ?>
                  <div class="form-group">
                    <label>Featured Image</label>
                    <div class="qpmeta-field-wrap" data-field-name="featured_image" data-object-type="post" data-object-id="<?= (int)($post_data['id'] ?? 0) ?>">
                      <input type="hidden" name="featured_image" value="<?= htmlspecialchars($current_feat) ?>" class="qpmeta-file-id">
                      <div class="qpmeta-file-preview"></div>
                    </div>
                  </div>
                  <?php
                  // Remove any registered featured_image metabox for this post type so qpmeta doesn't render it again
                  $mb_id = 'featured_image_' . $post_type;
                  if (!empty($GLOBALS['qpmeta_metaboxes']) && is_array($GLOBALS['qpmeta_metaboxes'])) {
                      foreach ($GLOBALS['qpmeta_metaboxes'] as $k => $mb) {
                          if (!empty($mb['id']) && $mb['id'] === $mb_id) {
                              unset($GLOBALS['qpmeta_metaboxes'][$k]);
                              break;
                          }
                      }
                      // Reindex array
                      $GLOBALS['qpmeta_metaboxes'] = array_values($GLOBALS['qpmeta_metaboxes']);
                  }
              }
              // Render remaining side metaboxes (tags, other side boxes)
              qpmeta_render_metaboxes_in_context('post', $post_data['id'] ?? null, 'side');
            }
          ?>
       
          
        </div>
      </div>

    </form>
         
    
    <script>
    jQuery(function($){

      var adminAjax = '<?= $admin_ajax_url ?>';

      // Pretty-slug normalization (lowercase, spaces -> hyphens, remove invalid chars, collapse hyphens)
      function prettySlug(s) {
        if (!s) return '';
        s = String(s).toLowerCase().trim();
        // replace non-alphanum with hyphen
        s = s.replace(/[^a-z0-9\-]+/g, '-');
        // collapse multiple hyphens
        s = s.replace(/\-+/g, '-');
        // trim hyphens
        s = s.replace(/^\-+|\-+$/g, '');
        return s;
      }

      // Live slug preview: replace %slug% in template with pretty slug for text and encoded slug for href
      function updateSlugPreviewFromTemplate() {
        var $group = $('#slug-input-group');
        if (!$group.length) return;
        var tpl = $group.data('template') || '';
        var raw = $('#slug-url').val() || '';
        var pretty = prettySlug(raw);
        var enc = encodeURIComponent(pretty);
        var preview = tpl.replace(/%slug%/g, enc);
        $('#slug-full-preview').attr('href', preview).text(preview.replace(enc, pretty));
      }

      // Inline Edit flow for slug: Show/Edit/OK/Cancel
      function enableSlugInlineEdit() {
        var $group = $('#slug-input-group');
        if (!$group.length) return;
        var $input = $('#slug-url');
        var $editBtn = $group.find('button:contains("Edit")');
        if (!$editBtn.length) return;

        $editBtn.on('click', function(){
          // Replace Edit button with OK and Cancel
          var $ok = $('<button type="button" class="btn btn-outline-secondary">OK</button>');
          var $cancel = $('<button type="button" class="btn btn-outline-secondary ms-1">Cancel</button>');
          $editBtn.hide();
          $group.find('.input-group-append').append($ok).append($cancel);
          $input.prop('readonly', false).focus().select();

          $ok.on('click', function(){
            // Normalize slug to pretty form
            var raw = $input.val() || '';
            var slug = prettySlug(raw);
            if (!slug) { alert('Please enter a valid slug'); return; }
            // Set normalized value
            $input.val(slug);
            // Save slug via standalone endpoint
            var postType = $('input[name="post_type"]').val() || '';
            var postId = parseInt($('input[name="post_id"]').val() || '0', 10) || 0;
            function doSave(s, attempt) {
              $.post(adminAjax, { action: 'save_slug', post_type: postType, post_id: postId, slug: s }, function(resp){
                if (!resp || resp.status !== 'success') {
                  alert('Slug save failed: ' + (resp && resp.message ? resp.message : 'unknown'));
                  cleanup();
                  return;
                }
                if (resp.available) {
                  $input.val(resp.suggestion);
                  updateSlugPreviewFromTemplate();
                  cleanup();
                } else {
                  // server suggested alternative (e.g., -2) — apply and retry once
                  if (resp.suggestion && attempt < 2) {
                    $input.val(resp.suggestion);
                    doSave(resp.suggestion, attempt + 1);
                  } else {
                    alert('Slug taken and no suggestion available');
                    cleanup();
                  }
                }
              }, 'json').fail(function(){ alert('Slug save failed (network)'); cleanup(); });
            }
            doSave(slug, 0);

            function cleanup() {
              $input.prop('readonly', true);
              $cancel.remove();
              $ok.remove();
              $editBtn.show();
            }
          });

          $cancel.on('click', function(){
            // revert to previous value (use pretty of current or server-stored)
            var orig = '<?= htmlspecialchars($post_data['slug'] ?? '') ?>';
            $input.val(orig);
            updateSlugPreviewFromTemplate();
            $input.prop('readonly', true);
            $group.find('button:contains("OK")').remove();
            $cancel.remove();
            $editBtn.show();
          });
        });
      }

      // Bind events
      $(document).on('input change', '#slug-url', function(){ updateSlugPreviewFromTemplate(); });

      // Auto-generate slug from title on Add screen until user edits the slug manually
      var isAddScreen = <?= ($action === 'add') ? 'true' : 'false' ?>;
      var autoSlugEnabled = isAddScreen;
      if (isAddScreen) {
        $(document).on('input', 'input[name="title"]', function(){
          if (!autoSlugEnabled) return;
          var title = $(this).val() || '';
          var s = prettySlug(title);
          $('#slug-url').val(s);
          updateSlugPreviewFromTemplate();
        });
        // If user edits slug manually, stop auto-updating
        $(document).on('input', '#slug-url', function(){ autoSlugEnabled = false; });
      }

      // initialize on load
      updateSlugPreviewFromTemplate();
      enableSlugInlineEdit();

      // Show inline editors automatically on Add screen (one-liner behavior)
      if (isAddScreen) {
        // open inline for status, visibility and publish
        $('#postStatusEditLink').length && $('#postStatusEditLink').trigger('click');
        $('#postVisibilityEditLink').length && $('#postVisibilityEditLink').trigger('click');
        $('#postPublishEditLink').length && $('#postPublishEditLink').trigger('click');
      }

      function humanizeStatus(val) { return val ? (val.replace(/_/g, ' ').replace(/\b[a-z]/g, function(m){return m.toUpperCase();})) : ''; }

      // Helper to open inline control inside a line
      function openInline($line, $controlElems) {
        $line.find('a').hide();
        $line.append($controlElems);
      }

      // Helper to close inline: remove controls and show link
      function closeInline($line) {
        $line.find('.inline-control').remove();
        $line.find('a').show();
      }

      // Status inline edit: clone the server-rendered block so reopening reflects the
      // authoritative value and changes persist. Copy back only on OK.
      $(document).on('click', '#postStatusEditLink', function(e){ e.preventDefault();
        var $line = $('#postStatusLine');
        if ($line.find('.inline-control').length) return;
        var $label = $('#postStatusLabel');

        // Clone the server-rendered edit block's inner container (keeps structure)
        var $controls = $('#postStatusEdit').find('> div').clone(true);

        // Remove ids/for in cloned content and detach names from cloned inputs
        $controls.find('[id]').each(function(){ $(this).removeAttr('id'); });
        $controls.find('label').removeAttr('for');
        $controls.find('select,input,textarea').each(function(){
          var $el = $(this);
          var nm = $el.attr('name');
          if (nm) {
            $el.attr('data-clone-name', nm);
            // remove name so clone doesn't participate in form submission
            $el.removeAttr('name');
          }
        });

        // Initialize cloned select from authoritative select value
        var currentStatus = $('#postStatus').val() || '';
        $controls.find('select[data-clone-name="status"]').val(currentStatus);

        $controls.addClass('inline-control').css('display','inline-block');
        $label.hide(); openInline($line, $controls);

        // locate OK/Cancel inside clone or create them if missing
        var $ok = $controls.find('button').filter(function(){ return $(this).text().trim().toLowerCase() === 'ok'; }).first();
        var $cancel = $controls.find('button').filter(function(){ return $(this).text().trim().toLowerCase() === 'cancel'; }).first();
        if (!$ok.length) $ok = $('<button class="btn btn-sm btn-primary inline-control">OK</button>').appendTo($controls);
        if (!$cancel.length) $cancel = $('<button class="btn btn-sm btn-secondary inline-control ms-1">Cancel</button>').appendTo($controls);

        $ok.on('click', function(){
          var v = $controls.find('select[data-clone-name="status"]').val() || '';
          // persist to the real select
          $('#postStatus').val(v);
          $label.text(humanizeStatus(v)).show();
          closeInline($line);
        });

        $cancel.on('click', function(){
          $label.show(); closeInline($line);
        });
      });

      // Visibility inline edit: clean, robust handler
      $(document).on('click', '#postVisibilityEditLink', function(e){
        e.preventDefault();
        var $line = $('#postVisibilityLine');
        if ($line.find('.inline-control').length) return;
        var $label = $('#postVisibilityLabel');

        // Clone the server-rendered edit block's inner container (keeps structure)
        var $controls = $('#postVisibilityEdit').find('> div').clone(true);

        // Remove ids from cloned form controls to avoid duplicates, but keep wrapper structure
        $controls.find('[id]').each(function(){
          var tag = this.tagName && this.tagName.toLowerCase();
          if (tag && ['input','label','button','select','textarea'].indexOf(tag) !== -1) $(this).removeAttr('id');
        });

        // Remove ids and label `for` attributes inside the clone to avoid accidentally
        // targeting the real form controls. Preserve original names in data attr
        // so we can copy values back on OK. For visibility radios we give the
        // clone a unique internal group name so they act as a single-select group
        // among themselves but do not share the real `visibility` name.
        $controls.find('[id]').each(function(){
          var tag = this.tagName && this.tagName.toLowerCase();
          if (tag && ['input','label','button','select','textarea'].indexOf(tag) !== -1) $(this).removeAttr('id');
        });
        $controls.find('label').removeAttr('for');

        var cloneGroupName = 'inline_vis_clone_' + Math.floor(Math.random() * 1000000);
        $controls.find('input,select,textarea').each(function(){
          var $el = $(this);
          var nm = $el.attr('name');
          if (nm) {
            $el.attr('data-clone-name', nm);
            if (nm === 'visibility') {
              // give cloned visibility inputs a shared, unique name so they
              // become an exclusive radio group among themselves
              $el.attr('name', cloneGroupName).addClass('inline-visibility-radio');
            } else {
              // remove name for other cloned controls so they don't participate
              // in the real form submission
              $el.removeAttr('name');
            }
          }
        });

        // Initialize cloned radios to reflect current real input state and copy password value
        var currentVis = $('input[name="visibility"]:checked').val() || 'public';
        $controls.find('.inline-visibility-radio[value="' + currentVis + '"]').prop('checked', true);
        // copy password value into cloned input so user sees saved value (clone has data-clone-name)
        var existingPw = $('input[name="post_password"]').val() || '';
        $controls.find('input[data-clone-name="post_password"]').val(existingPw);

        $controls.addClass('inline-control').css('display','inline-block');
        $label.hide(); openInline($line, $controls);

        // locate OK/Cancel inside clone or create simple ones if missing
        var $ok = $controls.find('button').filter(function(){ return $(this).text().trim().toLowerCase() === 'ok'; }).first();
        var $cancel = $controls.find('button').filter(function(){ return $(this).text().trim().toLowerCase() === 'cancel'; }).first();
        if (!$ok.length) $ok = $('<button class="btn btn-sm btn-primary inline-control">OK</button>').appendTo($controls);
        if (!$cancel.length) $cancel = $('<button class="btn btn-sm btn-secondary inline-control ms-1">Cancel</button>').appendTo($controls);

        // Show/hide cloned password field on radio change inside clone
        $controls.find('.inline-visibility-radio').on('change', function(){
          var v = $controls.find('.inline-visibility-radio:checked').val() || 'public';
          if (v === 'password') $controls.find('input[data-clone-name="post_password"]').closest('div').show();
          else $controls.find('input[data-clone-name="post_password"]').closest('div').hide();
        });

        // initialize visibility of clone's password field
        if ($controls.find('.inline-visibility-radio:checked').val() === 'password') {
          $controls.find('input[data-clone-name="post_password"]').closest('div').show();
        } else {
          $controls.find('input[data-clone-name="post_password"]').closest('div').hide();
        }

        // OK handler: copy values back to real inputs and update label
        $ok.on('click', function(){
          var v = $controls.find('.inline-visibility-radio:checked').val() || 'public';
          var pw = $controls.find('input[data-clone-name="post_password"]').val() || '';
          // set real inputs
          $('input[name="visibility"]').prop('checked', false);
          $('input[name="visibility"][value="'+v+'"]').prop('checked', true);
          $('input[name="post_password"]').val(pw);
          var lab = v === 'password' ? 'Password protected' : (v === 'private' ? 'Private' : 'Public');
          $('#postVisibilityLabel').text(lab).show();
          // ensure main password field visibility matches selection
          if (v === 'password') $('#passwordField').show(); else $('#passwordField').hide();
          closeInline($line);
        });

        $cancel.on('click', function(){
          $label.show(); closeInline($line);
        });

      });

      // Publish inline edit: reuse existing server-rendered `#postPublishEdit` block
      $(document).on('click', '#postPublishEditLink', function(e){ e.preventDefault();
        var $line = $('#postPublishLine');
        if ($line.find('.inline-control').length) return;
        var $label = $('#postPublishLabel');
        var $label_cont = $('#postPublishLabelContainer');
        var $controls = $('#postPublishEdit');

        // Remember original parent so we can restore controls when done
        var $origParent = $controls.parent();

        // Detach the existing edit block and append it into the line as the inline control
        $controls.detach();
        $controls.addClass('inline-control').show().css('display','block');
        // Hide the publish label and the edit link in the line, then append the controls (moving them in the DOM)
        $label.hide();
        $label_cont.hide();
        $line.find('a').hide();
        $line.append($controls);

        // Use the real inputs and buttons (avoid duplicating IDs); unbind previous handlers first
        var $ok = $controls.find('#postPublishOk').off('click');
        var $cancel = $controls.find('#postPublishCancel').off('click');
        var $date = $controls.find('#publishDate').first();
        var $time = $controls.find('#publishTime').first();

        function restoreControls() {
          // hide and move the controls back to their original parent
          $controls.hide();
          $origParent.append($controls);
          $controls.removeClass('inline-control');
          $line.find('a').show();
          $label.show();
          $label_cont.show();
        }

        $ok.on('click', function(){
            var d = $date.val(); var t = $time.val(); var publishedAt = '';
            if (d) { publishedAt = d + ' ' + (t || '00:00') + ':00'; }
            $('#publishedAtHidden').val(publishedAt);
            $('#postPublishLabel').text(publishedAt ? publishedAt : 'Immediate').show();
            if (publishedAt) {
              var ts = Date.parse(publishedAt.replace(' ', 'T'));
              if (!isNaN(ts) && ts > Date.now()) {
                $('#postStatus').val('scheduled');
                $('#postStatusLabel').text(humanizeStatus('scheduled'));
              }
            }
            restoreControls();
        });

        $cancel.on('click', function(){
          restoreControls();
        });
      });


      // Auto-select parents on child checked
      function autoSelectParent($checkbox) {
        let $current = $checkbox;
        while (true) {
          let curMargin = parseInt($current.closest('.form-check').css('margin-left')) || 0;
          if(curMargin === 0) break;
          let parentMargin = curMargin - 20;
          let $siblings = $current.closest('.taxonomy-terms').find('.form-check');
          let $parentCheck = null;
          $siblings.each(function() {
            if(parseInt($(this).css('margin-left')) === parentMargin) {
              if($(this).nextAll().find('#' + $current.attr('id')).length > 0 || $(this).prevAll().find('#' + $current.attr('id')).length > 0){
                // Not a reliable check for parent - use sequential DOM approach below
                return true; // continue loop
              }
              $parentCheck = $(this).find('input[type=checkbox]').first();
              return false; // break loop
            }
          });
          if($parentCheck && $checkbox.prop('checked')) {
            $parentCheck.prop('checked', true);
            $current = $parentCheck;
          } else {
            break;
          }
        }
      }

      // On checkbox change, propagate parent selection
      $(document).on('change', '.term-checkbox', function(){
        if($(this).prop('checked')) {
          autoSelectParent($(this));
        }
      });

      // On page load, auto-select parents for checked checkboxes
      $('.term-checkbox:checked').each(function(){
        autoSelectParent($(this));
      });

      // Submit post form with FormData for qpmeta/metabox support
      // Ensure published_at hidden field is populated, and visibility/password toggling works
      $('#postForm').submit(function(e){
        e.preventDefault();
        if (window.tinymce && typeof tinymce.triggerSave === 'function') {
          tinymce.triggerSave();
        }
        // Compose published_at from date/time inputs if present
        var dateVal = $('#publishDate').val();
        var timeVal = $('#publishTime').val();
        var publishedAt = '';
        if (dateVal) {
          publishedAt = dateVal + ' ' + (timeVal || '00:00') + ':00';
        }
        $('#publishedAtHidden').val(publishedAt);

        // If editing an already published post, disallow scheduling a future date from client side
        var existingStatus = '<?= addslashes($post_data['status'] ?? '') ?>';
        var selectedStatus = $('#postStatus').val();
        if (existingStatus === 'published' && selectedStatus === 'published' && publishedAt) {
          var ts = Date.parse(publishedAt.replace(' ','T'));
          if (!isNaN(ts) && ts > Date.now()) {
            $.alert('Scheduling a future publish is not allowed for posts that are already published. Use backdate instead.');
            return;
          }
        }
        var formData = new FormData(this);
        var ajaxAction = '<?= $action === 'add' ? 'add_post' : 'update_post' ?>';
        formData.append('action', ajaxAction);
        $.ajax({
          url: '<?= $admin_ajax_url ?>',
          method: 'POST',
          data: formData,
          processData: false,
          contentType: false,
          dataType: 'json',
          success: function(resp) {
            if(resp.status === 'success') {
              $('#formResult').html('<div class="alert alert-success">'+resp.message+'</div>');
              if ('<?= $action ?>' === 'add' && resp.post_id) {
                window.location.href = 'posts.php?post_type=<?= urlencode($post_type) ?>&action=edit&id=' + resp.post_id;
              }
            } else {
              $('#formResult').html('<div class="alert alert-danger">'+resp.message+'</div>');
              $.alert(resp.message);
            }
          },
          error: function() {
            $('#formResult').html('<div class="alert alert-danger">Server error</div>');
            $.alert('Server error');
          }
        });
      });

      // Initialize visibility radio state and toggle password field when the real
      // visibility input changes (affects the server-rendered `#passwordField`).
      (function(){
        var initVis = '<?= htmlspecialchars($post_data['visibility'] ?? 'public') ?>';
        // set server radios to correct checked state
        $('input[name="visibility"][value="' + initVis + '"]').prop('checked', true);
        if (initVis === 'password') $('#passwordField').show(); else $('#passwordField').hide();
      })();

      $(document).on('change', 'input[name="visibility"]', function(){
        var v = $('input[name="visibility"]:checked').val() || 'public';
        if (v === 'password') {
          $('#passwordField').show();
        } else {
          $('#passwordField').hide();
        }
      });

      // Add new term ajax (no reload), refresh checkboxes and parent select, auto-check new and parents
      $('.add-term-btn').click(function(){
        let $container = $(this).closest('[data-taxonomy]');
        let taxonomy = $container.data('taxonomy');
        let termNameInput = $container.find('.new-term-input');
        let parentSelect = $container.find('.new-term-parent');
        let resultDiv = $container.find('.add-term-result');

        let termName = termNameInput.val().trim();
        let parentId = parentSelect.val();

        if(termName === '') {
          resultDiv.text('Please enter a term name').css('color', 'red');
          return;
        }

        $.post('<?= $admin_ajax_url ?>', {
          action: 'add_term',
          taxonomy: taxonomy,
          term: termName,
          parent_id: parentId
        }, function(resp){
          if(resp.status === 'success') {
            resultDiv.text('Term added! Refreshing...').css('color', 'green');
            $.post('<?= $admin_ajax_url ?>', {
              action: 'fetch_terms',
              taxonomy: taxonomy,
              post_id: <?= json_encode($post_data['id'] ?? 0) ?>
            }, function(data){
              if(data.status === 'success') {
                let termsDiv = $container.find('.taxonomy-terms');
                termsDiv.empty();

                function renderTerms(terms, parent_id, depth) {
                  terms.filter(t => (t.parent_id ?? 0) == parent_id).forEach(function(term) {
                    let margin = 20 * depth;
                    let checked = term.checked ? 'checked' : '';
                    let checkbox = $('<div>').addClass('form-check').css('margin-left', margin + 'px');
                    let input = $('<input>').attr({
                      type: 'checkbox',
                      id: 'tax-' + taxonomy + '-term-' + term.id,
                      name: 'terms[' + taxonomy + '][]',
                      value: term.id,
                      class: 'form-check-input term-checkbox'
                    }).prop('checked', checked);
                    let label = $('<label>').attr('for', 'tax-' + taxonomy + '-term-' + term.id).addClass('form-check-label').text(term.term);
                    checkbox.append(input).append(label);
                    termsDiv.append(checkbox);
                    renderTerms(terms, term.id, depth + 1);
                  });
                }
                renderTerms(data.terms, 0, 0);

                // Update parent select
                parentSelect.empty().append('<option value="">No parent</option>');
                data.terms.forEach(term => {
                  parentSelect.append($('<option>').val(term.id).text(term.term));
                });

                // Auto-check newly added term and its parents
                setTimeout(function() {
                  let $newCheckbox = termsDiv.find('#tax-' + taxonomy + '-term-' + resp.term_id);
                  $newCheckbox.prop('checked', true);
                  $newCheckbox.each(function(){
                    autoSelectParent($(this));
                  });
                }, 100);

                termNameInput.val('');
                parentSelect.val('');
                resultDiv.text('');
              } else {
                resultDiv.text('Failed to reload terms').css('color', 'red');
              }
            }, 'json');
          } else {
            resultDiv.text(resp.message).css('color', 'red');
          }
        }, 'json').fail(function(){
          resultDiv.text('Server error').css('color', 'red');
        });
      });
    });
    </script>

<?php
} ?>
 </div>

<?php
require __DIR__ . '/inc/footer.php';
?>
