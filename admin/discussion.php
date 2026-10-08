<?php
require_once __DIR__ . '/admin_head.php';
$notice = function_exists('qp_get_admin_notice') ? qp_get_admin_notice() : null;
// Load stored settings with sane defaults similar to WP
$settings = function_exists('get_option_meta') ? (get_option_meta('discussion_settings') ?? []) : [];
$defaults = [
  // Pingback/trackback options removed for now — kept as placeholders for future use.
  // 'attempt_notify_back' => false,
  // 'allow_link_notifications' => true,
  'comments_open_default' => true,
  'require_name_email' => true,
  'require_registered' => false,
  'auto_close' => false,
  'auto_close_days' => 14,
  'show_cookies_optin' => true,
  'enable_threaded' => true,
  'thread_depth' => 5,
  'break_comments_pages' => false,
  'comments_per_page' => 50,
  'comments_page_display' => 'last', // last or first
  'comments_display_at_top' => 'older',
  'email_notify_anyone' => false,
  'email_moderation' => true,
  'before_comment_approval_manual' => false,
  'before_comment_approval_prior_approved' => true,
  'moderation_links_threshold' => 2,
  'moderation_keys' => '',
  'disallowed_keys' => '',
  'avatar_display' => true,
  'default_avatar' => 'mystery',
  'auto_approve_logged_in' => false,
];
$s = array_merge($defaults, $settings ?: []);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_discussion'])) {
  // Verify admin nonce
  if (!function_exists('qp_admin_verify_nonce') || !qp_admin_verify_nonce($_POST['discussion_nonce'] ?? '', 'discussion_settings')) {
    qp_set_admin_notice('Invalid request (bad nonce)');
    header('Location: discussion.php'); exit;
  }
  // Collect fields from POST and coerce types
  // Pingback/trackback handling not implemented yet; placeholders kept commented.
  // $s['attempt_notify_back'] = !empty($_POST['attempt_notify_back']);
  // $s['allow_link_notifications'] = !empty($_POST['allow_link_notifications']);
  $s['comments_open_default'] = !empty($_POST['comments_open_default']);
  $s['require_name_email'] = !empty($_POST['require_name_email']);
  $s['require_registered'] = !empty($_POST['require_registered']);
  $s['auto_close'] = !empty($_POST['auto_close']);
  $s['auto_close_days'] = max(0, (int)($_POST['auto_close_days'] ?? $s['auto_close_days']));
  $s['show_cookies_optin'] = !empty($_POST['show_cookies_optin']);
  $s['enable_threaded'] = !empty($_POST['enable_threaded']);
  $s['thread_depth'] = max(1, min(10, (int)($_POST['thread_depth'] ?? $s['thread_depth'])));
  $s['break_comments_pages'] = !empty($_POST['break_comments_pages']);
  $s['comments_per_page'] = max(1, (int)($_POST['comments_per_page'] ?? $s['comments_per_page']));
  $s['comments_page_display'] = in_array($_POST['comments_page_display'] ?? 'last', ['last','first']) ? $_POST['comments_page_display'] : 'last';
  $s['comments_display_at_top'] = in_array($_POST['comments_display_at_top'] ?? 'older', ['older','newer']) ? $_POST['comments_display_at_top'] : 'older';
  $s['email_notify_anyone'] = !empty($_POST['email_notify_anyone']);
  $s['email_moderation'] = !empty($_POST['email_moderation']);
  $s['before_comment_approval_manual'] = !empty($_POST['before_comment_approval_manual']);
  $s['before_comment_approval_prior_approved'] = !empty($_POST['before_comment_approval_prior_approved']);
  $s['moderation_links_threshold'] = max(0, (int)($_POST['moderation_links_threshold'] ?? $s['moderation_links_threshold']));
  $s['moderation_keys'] = trim($_POST['moderation_keys'] ?? $s['moderation_keys']);
  $s['disallowed_keys'] = trim($_POST['disallowed_keys'] ?? $s['disallowed_keys']);
  $s['avatar_display'] = !empty($_POST['avatar_display']);
  // Handle uploaded default avatar file (optional)
  if (!empty($_FILES['default_avatar_file']) && empty($_FILES['default_avatar_file']['error'])) {
    if (function_exists('qp_uploads_base')) {
      $tmp = $_FILES['default_avatar_file']['tmp_name'];
      $info = @getimagesize($tmp);
      if ($info !== false && in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_GIF], true)) {
        $ext = image_type_to_extension($info[2], false);
        $destDir = qp_uploads_base();
        if (!is_dir($destDir)) @mkdir($destDir, 0755, true);
        $destName = 'default-avatar.' . $ext;
        $destPath = $destDir . DIRECTORY_SEPARATOR . $destName;
        if (move_uploaded_file($tmp, $destPath)) {
          // store path relative to site root (uploads/<file>)
          $s['default_avatar_path'] = '/uploads/' . $destName;
          qp_set_admin_notice('Default avatar uploaded');
        }
      } else {
        qp_set_admin_notice('Uploaded avatar must be PNG/JPEG/GIF');
      }
    }
  }
  $s['default_avatar'] = trim($_POST['default_avatar'] ?? $s['default_avatar']);

  // Support selecting a default avatar from the media library (attachment id)
  if (!empty($_POST['default_avatar_attachment_id'])) {
    $aid = (int)$_POST['default_avatar_attachment_id'];
    if ($aid > 0) {
      $s['default_avatar_attachment_id'] = $aid;
      if (function_exists('qp_get_attachment_image_src')) {
        $img = qp_get_attachment_image_src($aid, 'full');
        if ($img) $s['default_avatar_path'] = $img[0];
      }
    }
  }
  $s['auto_approve_logged_in'] = !empty($_POST['auto_approve_logged_in']);

  if (function_exists('update_option_meta')) {
    update_option_meta('discussion_settings', $s);
    qp_set_admin_notice('Discussion settings saved');
  }
  header('Location: discussion.php'); exit;
}
$page_title = 'Discussion Settings';
if ( !current_user_can('manage_options') || !current_user_can('manage_comments') ) {
    http_response_code(403);
    require_once __DIR__ . '/inc/header.php';
    require_once __DIR__ . '/inc/navbar.php';
    echo '<div class="container"><h1>Permission Denied</h1><p>You do not have permission to access this admin page.</p></div>';
    include __DIR__ . '/inc/footer.php';
    exit;
}
// Include admin header AFTER processing POST to avoid "headers already sent" warnings
require_once __DIR__ . '/inc/header.php';
?>
<?php
// Backwards-compatible variables used by the form below
$require_name_email = isset($s['require_name_email']) ? (bool)$s['require_name_email'] : true;
$auto_approve = isset($s['auto_approve_logged_in']) ? (bool)$s['auto_approve_logged_in'] : false;
$comments_open_default = isset($s['comments_open_default']) ? (bool)$s['comments_open_default'] : true;

// Nonce for CSRF protection
$discussion_nonce = function_exists('qp_admin_create_nonce') ? qp_admin_create_nonce('discussion_settings') : '';
?>
<div class="container mt-3">
  <h2>Discussion Settings</h2>
   <?php if ($notice): ?>
        <div class="alert alert-success"><?= htmlspecialchars($notice) ?></div>
    <?php endif ?>

  <form method="post" enctype="multipart/form-data">
    <input type="hidden" name="discussion_nonce" value="<?= htmlspecialchars($discussion_nonce) ?>">

    <h4>Basic</h4>
    <div class="form-check">
      <input type="checkbox" class="form-check-input" id="require_name_email" name="require_name_email" <?= $require_name_email ? 'checked' : '' ?>>
      <label class="form-check-label" for="require_name_email">Users must provide name and email to comment</label>
    </div>
    <div class="form-check">
      <input type="checkbox" class="form-check-input" id="require_registered" name="require_registered" <?= !empty($s['require_registered']) ? 'checked' : '' ?>>
      <label class="form-check-label" for="require_registered">Users must be registered and logged in to comment</label>
    </div>
    <div class="form-check">
      <input type="checkbox" class="form-check-input" id="auto_approve_logged_in" name="auto_approve_logged_in" <?= $auto_approve ? 'checked' : '' ?>>
      <label class="form-check-label" for="auto_approve_logged_in">Automatically approve comments from logged-in users</label>
    </div>
    <div class="form-check">
      <input type="checkbox" class="form-check-input" id="comments_open_default" name="comments_open_default" <?= $comments_open_default ? 'checked' : '' ?>>
      <label class="form-check-label" for="comments_open_default">Allow comments by default</label>
    </div>

    <hr>
    <h4>Auto-close & cookies</h4>
    <div class="form-check">
      <input type="checkbox" class="form-check-input" id="auto_close" name="auto_close" <?= !empty($s['auto_close']) ? 'checked' : '' ?>>
      <label class="form-check-label" for="auto_close">Automatically close comments on posts older than</label>
    </div>
    <div class="mt-2 mb-3">
      <label for="auto_close_days">Days</label>
      <input type="number" id="auto_close_days" name="auto_close_days" class="form-control" value="<?= (int)$s['auto_close_days'] ?>">
    </div>
    <div class="form-check">
      <input type="checkbox" class="form-check-input" id="show_cookies_optin" name="show_cookies_optin" <?= !empty($s['show_cookies_optin']) ? 'checked' : '' ?>>
      <label class="form-check-label" for="show_cookies_optin">Show "Save my name, email in this browser" checkbox</label>
    </div>

    <hr>
    <h4>Threading & Pagination</h4>
    <div class="form-check">
      <input type="checkbox" class="form-check-input" id="enable_threaded" name="enable_threaded" <?= !empty($s['enable_threaded']) ? 'checked' : '' ?>>
      <label class="form-check-label" for="enable_threaded">Enable threaded (nested) comments</label>
    </div>
    <div class="mt-2 mb-3">
      <label for="thread_depth">Max thread depth</label>
      <input type="number" id="thread_depth" name="thread_depth" class="form-control" value="<?= (int)$s['thread_depth'] ?>">
    </div>
    <div class="form-check">
      <input type="checkbox" class="form-check-input" id="break_comments_pages" name="break_comments_pages" <?= !empty($s['break_comments_pages']) ? 'checked' : '' ?>>
      <label class="form-check-label" for="break_comments_pages">Break comments into pages with</label>
    </div>
    <div class="mt-2 mb-3">
      <label for="comments_per_page">Comments per page</label>
      <input type="number" id="comments_per_page" name="comments_per_page" class="form-control" value="<?= (int)$s['comments_per_page'] ?>">
    </div>
    <div class="mb-3">
      <label for="comments_page_display">Default page shown</label>
      <select id="comments_page_display" name="comments_page_display" class="form-control">
        <option value="last" <?= ($s['comments_page_display'] === 'last') ? 'selected' : '' ?>>Last page (newest)</option>
        <option value="first" <?= ($s['comments_page_display'] === 'first') ? 'selected' : '' ?>>First page (oldest)</option>
      </select>
    </div>
    <div class="mb-3">
      <label for="comments_display_at_top">Display order</label>
      <select id="comments_display_at_top" name="comments_display_at_top" class="form-control">
        <option value="older" <?= ($s['comments_display_at_top'] === 'older') ? 'selected' : '' ?>>Older comments at top</option>
        <option value="newer" <?= ($s['comments_display_at_top'] === 'newer') ? 'selected' : '' ?>>Newer comments at top</option>
      </select>
    </div>

    <hr>
    <h4>Notifications & Moderation</h4>
    <div class="form-check">
      <input type="checkbox" class="form-check-input" id="email_notify_anyone" name="email_notify_anyone" <?= !empty($s['email_notify_anyone']) ? 'checked' : '' ?>>
      <label class="form-check-label" for="email_notify_anyone">Email me whenever anyone posts a comment</label>
    </div>
    <div class="form-check">
      <input type="checkbox" class="form-check-input" id="email_moderation" name="email_moderation" <?= !empty($s['email_moderation']) ? 'checked' : '' ?>>
      <label class="form-check-label" for="email_moderation">Email me when a comment is held for moderation</label>
    </div>
    <div class="form-check mt-2">
      <input type="checkbox" class="form-check-input" id="before_comment_approval_manual" name="before_comment_approval_manual" <?= !empty($s['before_comment_approval_manual']) ? 'checked' : '' ?>>
      <label class="form-check-label" for="before_comment_approval_manual">Comment must be manually approved</label>
    </div>
    <div class="form-check mt-2">
      <input type="checkbox" class="form-check-input" id="before_comment_approval_prior_approved" name="before_comment_approval_prior_approved" <?= !empty($s['before_comment_approval_prior_approved']) ? 'checked' : '' ?>>
      <label class="form-check-label" for="before_comment_approval_prior_approved">Automatically approve comments from previously approved commenters</label>
    </div>
    <div class="mt-3">
      <label for="moderation_links_threshold">Moderate comments with more than N links</label>
      <input type="number" id="moderation_links_threshold" name="moderation_links_threshold" class="form-control" value="<?= (int)$s['moderation_links_threshold'] ?>">
    </div>
    <div class="mt-3">
      <label for="moderation_keys">Comment moderation keys (one per line)</label>
      <textarea id="moderation_keys" name="moderation_keys" class="form-control" rows="3"><?= htmlspecialchars($s['moderation_keys']) ?></textarea>
    </div>
    <div class="mt-3">
      <label for="disallowed_keys">Disallowed comment keys (one per line)</label>
      <textarea id="disallowed_keys" name="disallowed_keys" class="form-control" rows="3"><?= htmlspecialchars($s['disallowed_keys']) ?></textarea>
    </div>

    <hr>
    <h4>Avatars</h4>
    <div class="form-check">
      <input type="checkbox" class="form-check-input" id="avatar_display" name="avatar_display" <?= !empty($s['avatar_display']) ? 'checked' : '' ?>>
      <label class="form-check-label" for="avatar_display">Display avatars next to comments</label>
    </div>
    
    <div class="mt-2 mb-3">
      <label>Site Default Avatar</label>
      <?php
        $current_default = '';
        if (!empty($s['default_avatar_path'])) $current_default = $s['default_avatar_path'];
        else if (function_exists('qp_uploads_url_base')) $current_default = rtrim(qp_uploads_url_base(), '/') . '/default-avatar.png';
      ?>
      <?php if ($current_default): ?>
        <div class="mb-2"><img src="<?= htmlspecialchars($current_default) ?>" alt="Default avatar" style="max-width:96px;height:auto;border:1px solid #ddd;padding:4px;background:#fff"></div>
      <?php endif; ?>
      <div class="mb-2">
        <input type="hidden" id="default_avatar_attachment_id" name="default_avatar_attachment_id" value="<?= htmlspecialchars($s['default_avatar_attachment_id'] ?? '') ?>">
        <div id="default-avatar-preview" class="mb-2">
          <?php if (!empty($s['default_avatar_path'])): ?>
            <img id="default-avatar-img" src="<?= htmlspecialchars($s['default_avatar_path']) ?>" alt="Default avatar" style="max-width:96px;height:auto;border:1px solid #ddd;padding:4px;background:#fff">
          <?php else: ?>
            <img id="default-avatar-img" src="" alt="Default avatar" style="display:none;max-width:96px;height:auto;border:1px solid #ddd;padding:4px;background:#fff">
          <?php endif; ?>
        </div>
        <div class="mb-2">
          <button type="button" id="choose_default_avatar" class="btn btn-sm btn-outline-primary">Choose from Media Library</button>
          <button type="button" id="remove_default_avatar" class="btn btn-sm btn-outline-secondary">Remove</button>
        </div>
        <div class="form-text">Or upload a new image via the media library. The media modal will upload files if needed.</div>
        <input type="file" name="default_avatar_file" accept="image/*" style="display:none;">
      </div>
    </div>

    <button name="save_discussion" class="btn btn-primary mt-3" type="submit">Save Settings</button>
  </form>
</div>
<script>
jQuery(function($){
  $('#choose_default_avatar').on('click', function(){
    var pre = $('#default_avatar_attachment_id').val() ? [$('#default_avatar_attachment_id').val()] : [];
    if (window.qlopyMedia && typeof qlopyMedia.openModal === 'function') {
      qlopyMedia.openModal({ multiple: false, fileMode: 'images', preselect: pre, onInsert: function(item){
        var id = null, url = null;
        if (!item) return;
        if (Array.isArray(item)) { id = item.length ? item[0].id : null; url = item.length ? item[0].url : null; }
        else { id = item.id || item; url = item.url || null; }
        if (!url && id) {
          var adminAjax = window.qlopyAdminAjax || '/admin/ajax.php';
          $.post(adminAjax, { action: 'qp_media_batch_get', ids: String(id) }, function(resp){
            if (resp && resp.status === 'success' && resp.items && resp.items.length) {
              var it = resp.items[0]; var thumb = it.thumb || it.url || '';
              $('#default_avatar_attachment_id').val(id);
              if (thumb) { $('#default-avatar-img').attr('src', thumb).show(); } else { $('#default-avatar-img').hide(); }
            }
          }, 'json');
        } else {
          $('#default_avatar_attachment_id').val(id);
          if (url) $('#default-avatar-img').attr('src', url).show(); else $('#default-avatar-img').hide();
        }
      }});
    } else {
      alert('Media modal not available.');
    }
  });
  $('#remove_default_avatar').on('click', function(){ if (!confirm('Remove default avatar selection?')) return; $('#default_avatar_attachment_id').val(''); $('#default-avatar-img').attr('src','').hide(); });
});
</script>
<?php include __DIR__ . '/inc/footer.php'; exit; ?>
