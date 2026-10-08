<?php
// Simple comments template for default theme
// Core comments API is provided by includes/comments.php and is loaded by the front controller (index.php)
$object_type = $object_type ?? 'post';
// Resolve object id from provided variables or the global $post
if (empty($object_id)) {
  $object_id = 0;
  if (isset($post) && is_array($post) && isset($post['id'])) {
    $object_id = (int)$post['id'];
  } elseif (isset($post) && is_object($post) && isset($post->ID)) {
    $object_id = (int)$post->ID;
  } elseif (!empty($GLOBALS['post'])) {
    $gp = $GLOBALS['post'];
    if (is_array($gp) && isset($gp['id'])) $object_id = (int)$gp['id'];
    else if (is_object($gp) && isset($gp->ID)) $object_id = (int)$gp->ID;
  }
}
if (!$object_id) { echo '<!-- no comments: no object id -->'; return; }
  // Respect pagination and ordering settings
  $ds = function_exists('get_option_meta') ? (get_option_meta('discussion_settings') ?? []) : [];
  $per_page = isset($ds['comments_per_page']) ? max(1, (int)$ds['comments_per_page'] ) : 50;
  $display = $ds['comments_page_display'] ?? 'last';
  $order_at_top = $ds['comments_display_at_top'] ?? 'older';
  $order_dir = ($order_at_top === 'older') ? 'ASC' : 'DESC';
  $break_pages = !empty($ds['break_comments_pages']);
  $total = get_comments_number((int)$object_id, $object_type);
  // Default paging - may be overridden for threaded view which paginates top-level threads
  $last_page = $per_page > 0 ? max(1, (int)ceil($total / $per_page)) : 1;
  $page = isset($_GET['cpage']) ? max(1, (int)$_GET['cpage']) : ($display === 'last' ? $last_page : 1);
  $comments = comment_list_for((int)$object_id, $object_type, $page, $per_page, 'created_at', $order_dir);
?>
<div id="comments">
  <h3>Comments (<?= count($comments) ?>)</h3>
  <div class="comment-list">
    <?php
      // Use nested tree rendering when threaded comments are enabled in settings
      $ds = function_exists('get_option_meta') ? (get_option_meta('discussion_settings') ?? []) : [];
      // default to enabled/thread_depth when settings absent (admin defaults exist but option may be unset)
      $enable_threaded = array_key_exists('enable_threaded', $ds) ? (bool)$ds['enable_threaded'] : true;
      $thread_depth = array_key_exists('thread_depth', $ds) ? max(1, min(10, (int)$ds['thread_depth'])) : 3;
      $treeData = $enable_threaded ? comment_tree_for((int)$object_id, $object_type, $thread_depth, $order_dir) : null;

      $render_comment = function($c, $depth = 0) use (&$render_comment, $enable_threaded, $thread_depth, $ds, $object_id) {
        // Determine visibility before emitting any HTML to avoid partial output
        $status = $c['status'] ?? 'approved';
        if ($status !== 'approved') {
          $can_view = false;
          if (function_exists('is_logged_in') && is_logged_in()) {
            $uid = function_exists('qp_current_user_id') ? qp_current_user_id() : 0;
            $post_author_id = null;
            if (!empty($GLOBALS['post']) && is_array($GLOBALS['post']) && isset($GLOBALS['post']['author_id'])) {
              $post_author_id = (int)$GLOBALS['post']['author_id'];
            } else {
              if (function_exists('qp_get_posts')) {
                $pp = qp_get_posts(['p' => (int)$object_id, 'posts_per_page' => 1]);
                if (!empty($pp) && isset($pp[0]['author_id'])) $post_author_id = (int)$pp[0]['author_id'];
              }
            }
            if ($uid && $post_author_id && $uid === $post_author_id) $can_view = true;
            if (!$can_view && function_exists('current_user_can')) {
              if (current_user_can('moderate_comments') || current_user_can('manage_posts') || current_user_can('manage_options')) $can_view = true;
            }
          }
          if (!$can_view) return;
        }
        $badge_label = '';
        $badge_color = '';
        if ($status !== 'approved') {
          if ($status === 'pending') { $badge_label = 'Awaiting approval'; $badge_color = '#ffc107'; }
          elseif (in_array($status, ['awaiting_file', 'file_pending'])) { $badge_label = 'Awaiting file approval'; $badge_color = '#17a2b8'; }
        }
        ?>
        <div id="comment-<?= (int)$c['id'] ?>" class="comment" style="display:flex;gap:12px;align-items:flex-start;margin-bottom:16px;">
            <?php $show_avatar = array_key_exists('avatar_display', $ds) ? (bool)$ds['avatar_display'] : true; ?>
            <div class="comment-avatar" style="flex:0 0 56px;">
            <?php
              if ($show_avatar) {
                if (function_exists('get_avatar')) {
                  if (!empty($c['user_id'])) echo get_avatar((int)$c['user_id'], 48);
                  else echo get_avatar($c['author_email'] ?? '', 48);
                } else {
                  if (function_exists('qp_default_avatar_url')) echo '<img src="' . htmlspecialchars(qp_default_avatar_url()) . '" style="width:48px;height:48px;border-radius:4px;border:1px solid #ddd;" alt="avatar">';
                }
              }
            ?>
          </div>
          <div class="comment-body" style="flex:1;">
            <div style="display:flex;align-items:center;gap:8px;">
              <div class="comment-author"><?= htmlspecialchars(function_exists('comment_get_display_name') ? comment_get_display_name($c) : ($c['author_name'] ?: 'Anonymous')) ?></div>
              <?php
                $status = $c['status'] ?? 'approved';
                // Only show non-approved comments to the post author or users who can moderate comments
                if ($status !== 'approved') {
                  $can_view = false;
                  if (function_exists('is_logged_in') && is_logged_in()) {
                    $uid = function_exists('qp_current_user_id') ? qp_current_user_id() : 0;
                    $post_author_id = null;
                    if (!empty($GLOBALS['post']) && is_array($GLOBALS['post']) && isset($GLOBALS['post']['author_id'])) {
                      $post_author_id = (int)$GLOBALS['post']['author_id'];
                    } else {
                      if (function_exists('qp_get_posts')) {
                        $pp = qp_get_posts(['p' => (int)$object_id, 'posts_per_page' => 1]);
                        if (!empty($pp) && isset($pp[0]['author_id'])) $post_author_id = (int)$pp[0]['author_id'];
                      }
                    }
                    if ($uid && $post_author_id && $uid === $post_author_id) $can_view = true;
                    if (!$can_view && function_exists('current_user_can')) {
                      if (current_user_can('moderate_comments') || current_user_can('manage_posts') || current_user_can('manage_options')) $can_view = true;
                    }
                  }
                  if (!$can_view) return;
                  $badge_label = '';
                  $badge_color = '';
                  if ($status === 'pending') { $badge_label = 'Awaiting approval'; $badge_color = '#ffc107'; }
                  elseif (in_array($status, ['awaiting_file', 'file_pending'])) { $badge_label = 'Awaiting file approval'; $badge_color = '#17a2b8'; }
                } else {
                  $badge_label = '';
                  $badge_color = '';
                }
                if ($badge_label):
              ?>
                <span class="comment-badge" style="display:inline-block;padding:2px 8px;border-radius:12px;color:#212529;font-size:12px;background:<?= htmlspecialchars($badge_color) ?>;"><?= htmlspecialchars($badge_label) ?></span>
              <?php endif; ?>
              <?php if ($enable_threaded && $depth < $thread_depth): ?>
                <a href="#reply-to-<?= (int)$c['id'] ?>" class="comment-reply-link" data-comment-id="<?= (int)$c['id'] ?>" style="margin-left:8px;font-size:0.9em;">Reply</a>
              <?php endif; ?>
            </div>
            <div class="comment-meta"><?= htmlspecialchars($c['created_at'] ?? '') ?></div>
            <div class="comment-content" style="margin-top:6px;"><?= nl2br(htmlspecialchars($c['content'])) ?></div>
          </div>
        </div>
        <?php
        // Render children if present and depth permit
        if (!empty($c['children']) && $depth + 1 < $thread_depth) {
          echo '<div class="comment-children" style="margin-left:56px;margin-top:8px;">';
          foreach ($c['children'] as $child) {
            $render_comment($child, $depth + 1);
          }
          echo '</div>';
        }
        
      };

      if ($enable_threaded && is_array($treeData)) {
        $roots = $treeData['tree'];
        $total_roots = count($roots);
        if ($break_pages && $per_page > 0) {
          $last_page = max(1, (int)ceil($total_roots / $per_page));
          $page = isset($_GET['cpage']) ? max(1, (int)$_GET['cpage']) : ($display === 'last' ? $last_page : 1);
          $start = ($page - 1) * $per_page;
          $roots_page = array_slice($roots, $start, $per_page);
        } else {
          $roots_page = $roots;
          $total_roots = count($roots);
        }
        foreach ($roots_page as $root) {
          $render_comment($root, 0);
        }
      } else {
        $show_avatar = array_key_exists('avatar_display', $ds) ? (bool)$ds['avatar_display'] : true;
        foreach ($comments as $c) {
          // Skip non-approved comments for public viewers
          $status = $c['status'] ?? 'approved';
          if ($status !== 'approved') {
            $can_view = false;
            if (function_exists('is_logged_in') && is_logged_in()) {
              $uid = function_exists('qp_current_user_id') ? qp_current_user_id() : 0;
              $post_author_id = null;
              if (!empty($GLOBALS['post']) && is_array($GLOBALS['post']) && isset($GLOBALS['post']['author_id'])) {
                $post_author_id = (int)$GLOBALS['post']['author_id'];
              } else {
                if (function_exists('qp_get_posts')) {
                  $pp = qp_get_posts(['p' => (int)$object_id, 'posts_per_page' => 1]);
                  if (!empty($pp) && isset($pp[0]['author_id'])) $post_author_id = (int)$pp[0]['author_id'];
                }
              }
              if ($uid && $post_author_id && $uid === $post_author_id) $can_view = true;
              if (!$can_view && function_exists('current_user_can')) {
                if (current_user_can('moderate_comments') || current_user_can('manage_posts') || current_user_can('manage_options')) $can_view = true;
              }
            }
            if (!$can_view) continue;
          }
    ?>
      <div id="comment-<?= (int)$c['id'] ?>" class="comment" style="display:flex;gap:12px;align-items:flex-start;margin-bottom:16px;">
        <div class="comment-avatar" style="flex:0 0 56px;">
          <?php
            if ($show_avatar) {
              if (function_exists('get_avatar')) {
                if (!empty($c['user_id'])) echo get_avatar((int)$c['user_id'], 48);
                else echo get_avatar($c['author_email'] ?? '', 48);
              } else {
                if (function_exists('qp_default_avatar_url')) echo '<img src="' . htmlspecialchars(qp_default_avatar_url()) . '" style="width:48px;height:48px;border-radius:4px;border:1px solid #ddd;" alt="avatar">';
              }
            }
          ?>
        </div>
        <div class="comment-body" style="flex:1;">
          <div style="display:flex;align-items:center;gap:8px;">
            <div class="comment-author"><?= htmlspecialchars(function_exists('comment_get_display_name') ? comment_get_display_name($c) : ($c['author_name'] ?: 'Anonymous')) ?></div>
          </div>
          <div class="comment-meta"><?= htmlspecialchars($c['created_at'] ?? '') ?></div>
          <div class="comment-content" style="margin-top:6px;"><?= nl2br(htmlspecialchars($c['content'])) ?></div>
        </div>
      </div>
    <?php }
      }
    ?>
    
  </div>

  <?php if (comments_open((int)$object_id, $object_type)): ?>
    <?php
      // Show messages from non-AJAX redirect handlers (comment_error, comment_submitted)
      $msg_html = '';
      if (!empty($_GET['comment_error'])) {
          $err = $_GET['comment_error'];
          if ($err === 'missing_fields') $msg_html = '<div class="alert alert-danger">Please fill in all required fields before submitting your comment.</div>';
          else $msg_html = '<div class="alert alert-danger">Error submitting comment: ' . htmlspecialchars($err) . '</div>';
      } elseif (!empty($_GET['comment_submitted'])) {
          $status = $_GET['comment_status'] ?? 'pending';
          if ($status === 'approved') $msg_html = '<div class="alert alert-success">Your comment was posted.</div>';
          elseif ($status === 'pending') $msg_html = '<div class="alert alert-info">Your comment is awaiting moderation.</div>';
          else $msg_html = '<div class="alert alert-info">Your comment was received. Status: ' . htmlspecialchars($status) . '</div>';
      }
      if ($msg_html) echo $msg_html;
    
        // pagination controls
    if ($per_page > 0) {
      if ($enable_threaded && $break_pages) {
        $pages_total = $total_roots ?? 0;
        $show_pages = $pages_total > $per_page;
      } else {
        $show_pages = $total > $per_page;
      }
      if ($show_pages) {
        $base_url = htmlspecialchars(preg_replace('/([?&])cpage=\d+/','', $_SERVER['REQUEST_URI']));
        echo '<nav class="mt-3"><ul class="pagination">';
        for ($p=1;$p<=$last_page;$p++) {
          $active = $p === $page ? ' active' : '';
          $url = $base_url . (strpos($base_url,'?') === false ? '?' : '&') . 'cpage=' . $p . '#comments';
          echo '<li class="page-item' . $active . '"><a class="page-link" href="' . $url . '">' . $p . '</a></li>';
        }
        echo '</ul></nav>';
      }
    }

    ?>
    <?php
      $require_registered = !empty($ds['require_registered']);
      if ($require_registered && !(function_exists('is_logged_in') && is_logged_in())): ?>
        <div class="alert alert-info">You must be <a href="/login.php">logged in</a> to post a comment.</div>
      <?php else: ?>
    <form id="commentForm" method="post" action="<?= htmlspecialchars($_SERVER['REQUEST_URI']) ?>">
      <input type="hidden" name="action" value="comment_submit">
      <input type="hidden" name="object_type" value="<?= htmlspecialchars($object_type) ?>">
      <input type="hidden" name="object_id" value="<?= (int)$object_id ?>">
      <input type="hidden" name="parent_id" id="comment_parent_id" value="">
      <?php if (function_exists('is_logged_in') && is_logged_in()):
          $lu = function_exists('get_logged_in_user') ? get_logged_in_user() : null;
          $disp = '';
          $email = '';
          if (!empty($lu)) {
            // Prefer nicename first (site short name), then display_name, then first+last, then username
            $nic = function_exists('get_user_meta') ? get_user_meta($lu['id'], 'nicename') : '';
            if (!empty($nic)) { $disp = $nic; }
            elseif (!empty($lu['display_name'])) { $disp = $lu['display_name']; }
            else {
              $fn = function_exists('get_user_meta') ? get_user_meta($lu['id'], 'first_name') : '';
              $ln = function_exists('get_user_meta') ? get_user_meta($lu['id'], 'last_name') : '';
              if (!empty($fn) || !empty($ln)) { $disp = trim($fn . ' ' . $ln); }
              else { $disp = $lu['username'] ?? ''; }
            }
            $email = $lu['email'] ?? '';
          }
      ?>
        <input type="hidden" name="author_name" value="<?= htmlspecialchars($disp) ?>">
        <input type="hidden" name="author_email" value="<?= htmlspecialchars($email) ?>">
        <p>Posting as <strong><?= htmlspecialchars($disp) ?></strong></p>
      <?php else:
        $cookie_name = $_COOKIE['comment_author'] ?? '';
        $cookie_email = $_COOKIE['comment_author_email'] ?? '';
      ?>
        <div class="form-group"><label>Your name</label><input name="author_name" class="form-control" value="<?= htmlspecialchars($cookie_name) ?>"></div>
        <div class="form-group"><label>Your email</label><input name="author_email" class="form-control" value="<?= htmlspecialchars($cookie_email) ?>"></div>
        <?php if (!empty($ds['show_cookies_optin'])): ?>
          <div class="form-check mt-2 mb-2">
            <input type="checkbox" class="form-check-input" id="save_cookies" name="save_cookies" <?= $cookie_name || $cookie_email ? 'checked' : '' ?>>
            <label class="form-check-label" for="save_cookies">Save my name and email in this browser for future comments</label>
          </div>
        <?php endif; ?>
      <?php endif; ?>
        <div class="form-group"><label>Comment</label><textarea name="content" class="form-control" rows="4"></textarea></div>
        <button class="btn btn-primary" type="submit">Post comment</button>
      </form>
      <script>
      (function(){
        var form = document.getElementById('commentForm');
        if (!form) return;
        form.addEventListener('submit', function(){
          try {
            var save = document.getElementById('save_cookies');
            var name = document.querySelector('[name="author_name"]') ? document.querySelector('[name="author_name"]').value : '';
            var email = document.querySelector('[name="author_email"]') ? document.querySelector('[name="author_email"]').value : '';
            var setCookie = function(k,v,days){
              var ex = '';
              if (typeof days === 'number') { var d = new Date(); d.setTime(d.getTime() + (days*24*60*60*1000)); ex = '; expires=' + d.toUTCString(); }
              document.cookie = encodeURIComponent(k) + '=' + encodeURIComponent(v || '') + ex + '; path=/';
            };
            var delCookie = function(k){ document.cookie = encodeURIComponent(k) + '=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/'; };
            if (save && save.checked) {
              setCookie('comment_author', name, 365);
              setCookie('comment_author_email', email, 365);
            } else {
              delCookie('comment_author');
              delCookie('comment_author_email');
            }
          } catch (e) { /* ignore */ }
        }, false);
      })();
      </script>
      <?php endif; ?>
  <?php else: ?>
    <p>Comments are closed.</p>
  <?php endif; ?>
</div>
