<?php
require_once __DIR__ . '/admin_head.php';
require_once __DIR__ . '/../includes/comments.php';

$page_title = 'Comments';
if ( !current_user_can('moderate_comments')) {
    http_response_code(403);
    require_once __DIR__ . '/inc/header.php';
    require_once __DIR__ . '/inc/navbar.php';
    echo '<div class="container"><h1>Permission Denied</h1><p>You do not have permission to access this admin page.</p></div>';
    include __DIR__ . '/inc/footer.php';
    exit;
}
require_once __DIR__ . '/inc/header.php';
// Handle edit action inline: admin/comments.php?action=edit&id=NN
if (isset($_GET['action']) && $_GET['action'] === 'edit' && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    if ($id <= 0) { echo '<div class="alert alert-danger">Invalid ID</div>'; include __DIR__ . '/inc/footer.php'; exit; }
   
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_comment'])) {
        $content = trim($_POST['content'] ?? '');
        $author_name = trim($_POST['author_name'] ?? '');
        $author_email = trim($_POST['author_email'] ?? '');
        $status = trim($_POST['status'] ?? 'approved');
        $ok1 = comment_update($id, ['content'=>$content,'author_name'=>$author_name,'author_email'=>$author_email]);
        $ok2 = comment_update_status($id, $status, qp_current_user_id());
        if ($ok1 || $ok2) { qp_set_admin_notice('Comment saved'); header('Location: index.php?page=comments'); exit; }
        $error = 'Save failed';
    }
    $c = comment_get_by_id($id);
    if (!$c) { echo '<div class="alert alert-danger">Comment not found</div>'; include __DIR__ . '/inc/footer.php'; exit; }
    ?>
    <div class="container mt-3">
      <h2>Edit Comment #<?= (int)$c['id'] ?></h2>
      <?php if (!empty($error)): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>
      <form method="post">
        <input type="hidden" name="comment_id" value="<?= (int)$c['id'] ?>">
        <div class="form-group"><label>Author</label><input name="author_name" class="form-control" value="<?= htmlspecialchars($c['author_name'] ?? '') ?>"></div>
        <div class="form-group"><label>Email</label><input name="author_email" class="form-control" value="<?= htmlspecialchars($c['author_email'] ?? '') ?>"></div>
        <div class="form-group"><label>Content</label><textarea name="content" class="form-control" rows="6"><?= htmlspecialchars($c['content'] ?? '') ?></textarea></div>
        <div class="form-group"><label>Status</label>
          <select name="status" class="form-control">
            <option value="approved" <?= ($c['status']==='approved')?'selected':'' ?>>Approved</option>
            <option value="pending" <?= ($c['status']==='pending')?'selected':'' ?>>Pending</option>
            <option value="spam" <?= ($c['status']==='spam')?'selected':'' ?>>Spam</option>
          </select>
        </div>
        <button name="save_comment" class="btn btn-primary" type="submit">Save</button>
        <a class="btn btn-secondary" href="index.php?page=comments">Cancel</a>
      </form>
    </div>
    <?php
    include __DIR__ . '/inc/footer.php';
    exit;
}

// Simple list of recent comments
 $stmt = $pdo->query('SELECT * FROM ' . table_name('comments') . ' ORDER BY created_at DESC LIMIT 200');
 $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<div class="container mt-3">
  <h2>Comments</h2>
    <table class="table table-striped">
    <thead><tr><th>ID</th><th>Author</th><th>Content</th><th>Object</th><th>Status</th><th>Date</th><th>Actions</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td><?= (int)$r['id'] ?></td>
        <td><?php $display = function_exists('comment_get_display_name') ? comment_get_display_name($r) : ($r['author_name'] ?? 'Anonymous'); ?><?= htmlspecialchars($display) ?><br/><small><?= htmlspecialchars($r['author_email'] ?? '') ?></small></td>
        <td><?= nl2br(htmlspecialchars(substr($r['content'] ?? '', 0, 200))) ?></td>
        <td><?= htmlspecialchars(($r['object_type'] ?? '') . '#' . ($r['object_id'] ?? '')) ?></td>
        <td><?= htmlspecialchars($r['status'] ?? '') ?></td>
        <td><?= htmlspecialchars($r['created_at'] ?? '') ?></td>
        <td>
          <?php if (($r['status'] ?? '') !== 'approved'): ?>
            <button class="btn btn-sm btn-success" onclick="adminCommentAction('comment_approve', <?= (int)$r['id'] ?>)">Approve</button>
          <?php endif; ?>
          <?php if (($r['status'] ?? '') !== 'spam'): ?>
            <button class="btn btn-sm btn-warning" onclick="adminCommentAction('comment_mark_spam', <?= (int)$r['id'] ?>)">Spam</button>
          <?php endif; ?>
          <a class="btn btn-sm btn-primary" href="<?= htmlspecialchars('comments.php?action=edit&id=' . (int)$r['id']) ?>">Edit</a>
          <button class="btn btn-sm btn-danger" onclick="adminCommentAction('comment_delete', <?= (int)$r['id'] ?>)">Delete</button>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

<script>
async function adminCommentAction(action, id) {
  if (!confirm('Are you sure?')) return;
  const form = new FormData(); form.append('action', action); form.append('comment_id', id);
  const url = '<?= htmlspecialchars(get_admin_ajax_url()) ?>';
  try {
    const resp = await fetch(url, { method: 'POST', body: form, credentials: 'same-origin' });
    if (!resp.ok) { alert('Request failed: ' + resp.status); return; }
    const j = await resp.json(); if (j.status === 'success') location.reload(); else alert('Error: ' + (j.message || 'unknown'));
  } catch (err) { alert('Network: ' + err.message); }
}
</script>

<?php include __DIR__ . '/inc/footer.php'; exit; ?>
