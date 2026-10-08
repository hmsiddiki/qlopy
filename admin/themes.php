<?php
// Admin Themes page: clean rebuild
// If requested directly, ensure admin bootstrap runs
if (!isset($available_themes) || !isset($content_dir) || !isset($themes_dir)) {
    require_once __DIR__ . '/admin_head.php';
}

$page_title = $page_title ?? 'Themes';
require_once __DIR__ . '/inc/header.php';

// Admin base path and ajax url
$admin_base = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
$admin_ajax_url = $admin_base . '/ajax.php';

// Determine current preview theme from cookie
$current_preview_theme = null;
if (!empty($_COOKIE['qp_preview_theme'])) {
    $slug = preg_replace('/[^a-z0-9._\-]/i', '', $_COOKIE['qp_preview_theme']);
    if ($slug !== '' && isset($available_themes[$slug]) || in_array($slug, $available_themes, true)) {
        $current_preview_theme = $slug;
    }
}

require_once __DIR__ . '/../includes/theme-helpers.php';
?>

<style>
.theme-thumb{position:relative;overflow:hidden}
.theme-badge{position:absolute;top:8px;left:8px;padding:4px 8px;border-radius:4px;font-size:12px;font-weight:600}
.theme-badge.active{background:#28a745;color:#fff;border:1px solid rgba(0,0,0,0.08)}
.theme-badge.preview{background:#0d6efd;color:#fff;border:1px solid rgba(0,0,0,0.08);left:auto;right:8px}
.theme-card .card-body{min-height:120px;display:flex;flex-direction:column}
.theme-card .card-title{margin-bottom:0.5rem}
.theme-card .btn-row{margin-top:auto}
.theme-card .btn-row .btn{margin-right:6px}
.theme-version{font-size:0.85em}
</style>

<div class="container mt-4">
  <div class="row">
    <div class="col-12">
      <h2>Themes</h2>
      <p class="text-muted">Available themes in <code><?= htmlspecialchars($content_dir . '/' . $themes_dir) ?></code></p>
    </div>
  </div>

  <div class="row">
    <?php
    foreach ($available_themes as $theme_slug => $theme_path_or_slug) {
        // Allow both indexed and assoc arrays
        $theme = is_string($theme_slug) ? $theme_slug : $theme_path_or_slug;
        $is_active = isset($active_theme) && $theme === $active_theme;
        $is_previewing = ($current_preview_theme === $theme);

        $theme_dir = rtrim($theme_dir_root, '/\\') . '/' . $theme . '/';

        // Screenshot
        $thumb_url = null;
        foreach ([
            'screenshot.png','screenshot.jpg','screenshot.jpeg','screenshot.webp'
        ] as $f) {
            if (file_exists($theme_dir . $f)) {
                $thumb_url = $admin_base . '/..' . '/' . $content_dir . '/' . $themes_dir . '/' . $theme . '/' . $f;
                break;
            }
        }

        // Theme header
        $style_file = $theme_dir . 'style.css';
        $meta = parse_theme_header($style_file);
        $theme_name = $meta['Theme Name'] ?? '';
        $theme_version = $meta['Version'] ?? '';
        $theme_author = $meta['Author'] ?? '';
        $theme_uri = $meta['ThemeURI'] ?? '';
        $theme_author_uri = $meta['AuthorURI'] ?? '';
        $theme_description = $meta['Description'] ?? '';
        $theme_license = $meta['License'] ?? '';
        $theme_license_uri = $meta['LicenseURI'] ?? '';
        $display_name = $theme_name ?: $theme;

        // Build preview URL using simple ?preview_theme= for admins
        $qlopy_root_path = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'])), '/');
        if (defined('SITE_URL') && SITE_URL) {
            $site_root = rtrim(SITE_URL, '/');
            $site_url_path = parse_url(SITE_URL, PHP_URL_PATH) ?: '';
            $site_url_path = rtrim($site_url_path, '/');
            if ($site_url_path === '' && $qlopy_root_path !== '') { $site_root .= $qlopy_root_path; }
        } else {
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? 'localhost');
            $site_root = $scheme . '://' . $host;
            if ($qlopy_root_path !== '') $site_root .= $qlopy_root_path;
        }
        $preview_href = $site_root . '/?preview_theme=' . rawurlencode($theme);
    ?>

    <div class="col-md-4">
      <div class="card mb-4 shadow-sm theme-card">
        <div class="theme-thumb">
          <?php if ($thumb_url): ?>
            <img src="<?= htmlspecialchars($thumb_url) ?>" class="card-img-top img-fluid" alt="<?= htmlspecialchars($display_name) ?> screenshot">
          <?php else: ?>
            <?php
              $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="600" height="360" viewBox="0 0 600 360"><rect width="100%" height="100%" fill="#f4f4f6"/><g fill="#c7c9cc"><rect x="30" y="30" width="540" height="240" rx="6"/></g><text x="50%" y="60%" dominant-baseline="middle" text-anchor="middle" fill="#9aa0a6" font-family="Arial, Helvetica, sans-serif" font-size="18">No screenshot available</text></svg>';
              $data = 'data:image/svg+xml;utf8,' . rawurlencode($svg);
            ?>
            <img src="<?= $data ?>" class="card-img-top img-fluid" alt="No screenshot">
          <?php endif; ?>
          <?php if ($is_active): ?><span class="theme-badge active">Active</span><?php endif; ?>
          <?php if ($is_previewing): ?><span class="theme-badge preview">Previewing</span><?php endif; ?>
        </div>

        <div class="card-body">
          <div>
            <h5 class="card-title"><?php echo htmlspecialchars($display_name); if (!empty($theme_version)) { echo ' <small class="text-muted theme-version">v'.htmlspecialchars($theme_version).'</small>'; } ?></h5>
            <?php if ($theme_author || !empty($theme_uri) || !empty($theme_author_uri)): ?>
              <div class="small text-muted">By
                <?php
                  $author_href = '';
                  if (!empty($theme_author_uri)) $author_href = $theme_author_uri;
                  elseif (!empty($theme_uri)) $author_href = $theme_uri;
                  if (!empty($theme_author)) {
                      if (!empty($author_href)) {
                          echo '<a href="' . htmlspecialchars($author_href) . '" target="_blank" rel="noopener noreferrer">' . htmlspecialchars($theme_author) . '</a>';
                      } else {
                          echo htmlspecialchars($theme_author);
                      }
                  } else {
                      if (!empty($author_href)) echo '<a href="' . htmlspecialchars($author_href) . '" target="_blank" rel="noopener noreferrer">' . htmlspecialchars($author_href) . '</a>';
                  }
                ?>
              </div>
            <?php endif; ?>
          </div>

          <div class="btn-row mt-3 text-right">
            <div class="btn-group" role="group">
              <?php if (!$is_active): ?>
                <button class="btn btn-sm btn-primary btn-activate" data-theme="<?= htmlspecialchars($theme) ?>">Activate</button>
              <?php else: ?>
                <button class="btn btn-sm btn-secondary" disabled>Active</button>
              <?php endif; ?>
              <a class="btn btn-sm btn-outline-secondary btn-preview" href="<?= htmlspecialchars($preview_href) ?>" target="_blank">Preview</a>
              <button class="btn btn-sm btn-outline-primary" data-toggle="modal" data-target="#themeModal-<?= htmlspecialchars($theme) ?>">Details</button>
            </div>
          </div>
        </div>

        <div style="position:absolute;top:8px;right:8px;text-align:right;">
          <?php
          $themeUpdate = null;
          if (function_exists('get_theme_update')) $themeUpdate = get_theme_update($theme);
          if ($themeUpdate) {
              echo '<div style="margin-bottom:6px;"><span class="badge" style="background:#ffecec;color:#a33;">Update v' . htmlspecialchars($themeUpdate['new_version'] ?? '') . '</span></div>';
              echo '<div><button class="btn btn-sm btn-warning" data-theme-update="' . htmlspecialchars($theme) . '">Update Now</button></div>';
          }
          ?>
        </div>
      </div>

      <div class="modal fade" id="themeModal-<?= htmlspecialchars($theme) ?>" tabindex="-1" role="dialog" aria-labelledby="themeModalLabel-<?= htmlspecialchars($theme) ?>" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered" role="document">
          <div class="modal-content">
            <div class="modal-header">
              <h5 class="modal-title" id="themeModalLabel-<?= htmlspecialchars($theme) ?>"><?php echo htmlspecialchars($display_name); if (!empty($theme_version)) { echo ' <small class="text-muted theme-version">v'.htmlspecialchars($theme_version).'</small>'; } ?></h5>
              <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
            </div>
            <div class="modal-body">
              <div class="row">
                <div class="col-md-6">
                  <?php if ($thumb_url): ?>
                    <img src="<?= htmlspecialchars($thumb_url) ?>" class="img-fluid border" alt="<?= htmlspecialchars($display_name) ?> screenshot">
                  <?php else: ?>
                    <?php
                      $admin_placeholder = __DIR__ . '/assets/images/theme-placeholder.png';
                      if (file_exists($admin_placeholder)) {
                          $ph_url = $admin_base . '/assets/images/theme-placeholder.png';
                          echo '<img src="' . htmlspecialchars($ph_url) . '" class="img-fluid border" alt="No screenshot">';
                      } else {
                          echo '<img src="' . htmlspecialchars($data) . '" class="img-fluid border" alt="No screenshot">';
                      }
                    ?>
                  <?php endif; ?>
                </div>
                <div class="col-md-6">
                  <h6>Author</h6>
                  <p>
                    <?php if ($theme_author || !empty($theme_author_uri)): ?>
                      <?php if (!empty($theme_author)) {
                          if (!empty($theme_author_uri)) { ?>
                            <a href="<?= htmlspecialchars($theme_author_uri) ?>" target="_blank" rel="noopener noreferrer"><?= htmlspecialchars($theme_author) ?></a>
                          <?php } else { ?>
                            <?= htmlspecialchars($theme_author) ?>
                          <?php }
                      } else { ?>
                          <a href="<?= htmlspecialchars($theme_author_uri) ?>" target="_blank" rel="noopener noreferrer"><?= htmlspecialchars($theme_author_uri) ?></a>
                      <?php } ?>
                    <?php else: ?>
                      <span class="text-muted">—</span>
                    <?php endif; ?>
                  </p>
                  <h6>Description</h6>
                  <p><?= $theme_description ? nl2br(htmlspecialchars($theme_description)) : '<span class="text-muted">No description provided.</span>' ?></p>
                  <h6>Folder</h6>
                  <p class="small text-muted"><?= htmlspecialchars($theme) ?></p>
                  <h6 class="mt-2">License</h6>
                  <p>
                    <?php if (!empty($theme_license_uri)): ?>
                      <?php $lic_label = !empty($theme_license) ? $theme_license : $theme_license_uri; ?>
                      <a href="<?= htmlspecialchars($theme_license_uri) ?>" target="_blank" rel="noopener noreferrer"><?= htmlspecialchars($lic_label) ?></a>
                    <?php elseif (!empty($theme_license)): ?>
                      <?= htmlspecialchars($theme_license) ?>
                    <?php else: ?>
                      <span class="text-muted">—</span>
                    <?php endif; ?>
                  </p>
                </div>
              </div>
            </div>
            <div class="modal-footer">
              <?php if (!$is_active): ?>
                <button class="btn btn-primary btn-activate" data-theme="<?= htmlspecialchars($theme) ?>">Activate</button>
              <?php else: ?>
                <button class="btn btn-secondary" disabled>Active</button>
              <?php endif; ?>
              <a class="btn btn-outline-secondary btn-preview" href="<?= htmlspecialchars($preview_href) ?>" target="_blank">Preview</a>
              <button type="button" class="btn btn-light" data-dismiss="modal">Close</button>
            </div>
          </div>
        </div>
      </div>

    </div>
    <?php } // end foreach ?>
  </div>
</div>

<script>
jQuery(function($){
  $(document).on('click', '.btn-activate', function(ev){
    ev.preventDefault();
    var $btn = $(this);
    var theme = $btn.data('theme');
    if (!theme) return;
    if (!confirm('Activate theme "' + theme + '"?')) return;
    $btn.prop('disabled', true).text('Activating...');
    $.ajax({
      url: window.ajaxurl || 'ajax.php',
      method: 'POST',
      data: { action: 'activate_theme', theme: theme },
      dataType: 'json'
    }).done(function(json){
      if (json && json.status === 'success') {
        window.location.reload();
      } else {
        alert('Error: ' + (json && json.message ? json.message : 'Unknown'));
        $btn.prop('disabled', false).text('Activate');
      }
    }).fail(function(xhr, status, err){
      alert('Ajax error: ' + (err || xhr.statusText || 'Unknown'));
      $btn.prop('disabled', false).text('Activate');
    });
  });
});
</script>

<script>
jQuery(function($){
  var POLL_INTERVAL = 10000; // 10s

  function updatePreviewBadges(currentSlug){
    $('.theme-card').each(function(){
      var $card = $(this);
      var slug = $card.find('[data-theme]').first().data('theme') || $card.find('[data-theme-update]').first().data('theme-update');
      var $thumb = $card.find('.theme-thumb');
      $thumb.find('.theme-badge.preview').remove();
      if (currentSlug && slug === currentSlug) {
        $('<span/>').addClass('theme-badge preview').text('Previewing').appendTo($thumb);
      }
    });
  }

  function pollPreviewState(){
    $.ajax({
      url: window.ajaxurl || 'ajax.php',
      method: 'POST',
      data: { action: 'preview_theme_check' },
      dataType: 'json'
    }).done(function(json){
      if (!json || json.status !== 'success') return;
      updatePreviewBadges(json.theme || '');
    });
  }

  pollPreviewState();
  setInterval(pollPreviewState, POLL_INTERVAL);

  $(document).on('click', 'a.btn-preview', function(){
    setTimeout(pollPreviewState, 2000);
  });
});
</script>

<script>
jQuery(function($){
  $(document).on('click', '[data-theme-update]', function(ev){
    ev.preventDefault();
    var $btn = $(this);
    var theme = $btn.data('theme-update') || $btn.attr('data-theme');
    if (!theme) return;
    if (!confirm('Schedule update for theme "' + theme + '" now?')) return;
    $btn.prop('disabled', true).text('Scheduling...');
    var fd = new FormData();
    fd.append('action','qlopy_schedule_update');
    fd.append('type','theme');
    fd.append('folder', theme);
    fetch(window.ajaxurl || 'ajax.php', { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function(r){ return r.json(); })
      .then(function(json){
        if (json && json.status === 'success') {
          alert('Update scheduled (task id: ' + (json.task_id || 'n/a') + ')');
          $btn.text('Scheduled');
        } else {
          alert('Failed: ' + (json && json.message ? json.message : 'Unknown'));
          $btn.prop('disabled', false).text('Update Now');
        }
      })
      .catch(function(){
        alert('Ajax error');
        $btn.prop('disabled', false).text('Update Now');
      });
  });
});
</script>

<?php require __DIR__ . '/inc/footer.php'; ?>
