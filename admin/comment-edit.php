<?php
require_once __DIR__ . '/admin_head.php';
require_once __DIR__ . '/../includes/comments.php';

if (!is_logged_in() || !current_user_can('manage_posts')) {
    header('Location: login.php'); exit;
}

$comment_id = isset($_GET['comment_id']) ? (int)$_GET['comment_id'] : (int)($_POST['comment_id'] ?? 0);
if ($comment_id <= 0) { echo 'Invalid comment id'; exit; }

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save'])) {
    $content = trim($_POST['content'] ?? '');
    $author_name = trim($_POST['author_name'] ?? '');
    $author_email = trim($_POST['author_email'] ?? '');
    $status = trim($_POST['status'] ?? 'approved');
    $ok1 = comment_update($comment_id, ['content'=>$content,'author_name'=>$author_name,'author_email'=>$author_email]);
    $ok2 = comment_update_status($comment_id, $status, qp_current_user_id());
    if ($ok1 || $ok2) {
        qp_set_admin_notice('Comment saved');
        header('Location: index.php?page=comments'); exit;
    } else {
        $error = 'Save failed';
    }
}

$c = comment_get_by_id($comment_id);
if (!$c) { echo 'Comment not found'; exit; }

$page_title = 'Edit Comment #' . $comment_id;
require_once __DIR__ . '/inc/header.php';
?>
<div class="container mt-3">
  <h2><?= htmlspecialchars($page_title) ?></h2>
  <?php if (!empty($error)): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>
  <form method="post">
    <input type="hidden" name="comment_id" value="<?= (int)$comment_id ?>">
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
    <button name="save" class="btn btn-primary" type="submit">Save</button>
    <a class="btn btn-secondary" href="index.php?page=comments">Cancel</a>
  </form>
</div>

<?php include __DIR__ . '/inc/footer.php'; exit; ?>
