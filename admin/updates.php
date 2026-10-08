<?php
// Admin Updates page: central updates dashboard
require_once __DIR__ . '/admin_head.php';
$page_title = 'Updates';
if ( !current_user_can('manage_options')) {
    http_response_code(403);
    require_once __DIR__ . '/inc/header.php';
    require_once __DIR__ . '/inc/navbar.php';
    echo '<div class="container"><h1>Permission Denied</h1><p>You do not have permission to access this admin page.</p></div>';
    include __DIR__ . '/inc/footer.php';
    exit;
}
require_once __DIR__ . '/inc/header.php';

$available = qlopy_get_available_updates();
$core = get_core_update();
?>
 
<style>
/* Slightly improved table styling using the admin's bootstrap */
.updates-table {  border-collapse:collapse; background:#fff; }
.updates-table th, .updates-table td { padding:12px; border-bottom:1px solid #eee; }
.updates-actions { display:flex; gap:8px; align-items:center; }
.badge-up { padding:4px 8px; border-radius:4px; background:#ffecec; color:#a33; font-weight:600; }
.updates-notice { margin-bottom:12px; }
.small-mono { font-family:monospace; white-space:pre-wrap; }
</style>
<div class="container mt-4">
<h1>Updates</h1>
<p class="text-muted">Central update dashboard — shows available core, theme and plugin updates.</p>

<div class="updates-notice">
  <div style="margin-bottom:8px;">
    <button id="checkNow" class="btn btn-primary">Check Now</button>
    <button id="runInitiator" class="btn btn-secondary">Run Initiator (schedule available updates)</button>
    <span id="updatesSpinner" style="display:inline-block;margin-left:8px;vertical-align:middle"></span>
  </div>
  <div id="updatesAlert" role="status" aria-live="polite"></div>
</div>

<?php if ($core): ?>
  <h3>Core Update</h3>
  <div style="margin-bottom:14px;">
    <div><strong>New version: </strong><?= htmlspecialchars($core['new_version'] ?? '') ?></div>
    <div style="margin-top:8px;" class="updates-actions">
      <button class="actions-form btn btn-outline-secondary" data-update-type="core" data-update-folder="">Update Now</button>
    </div>
  </div>
<?php endif; ?>

<h3>Available Updates (<?php echo count($available); ?>)</h3>
<table class="updates-table table-responsive">
  <thead><tr><th>Type</th><th>Folder/Name</th><th>New Version</th><th>Actions</th></tr></thead>
  <tbody>
  <?php foreach ($available as $it): ?>
    <tr>
      <td><?= htmlspecialchars($it['type'] ?? '') ?></td>
      <td><?= htmlspecialchars($it['folder'] ?? '') ?></td>
      <td><?= htmlspecialchars($it['new_version'] ?? '') ?></td>
    <?php /*  <td style="max-width:360px;overflow:hidden;white-space:nowrap;text-overflow:ellipsis;"><?= htmlspecialchars($it['update_url'] ?? '') ?></td> */ ?>
      <td>
        <div class="updates-actions">
          <?php if (!empty($it['force_required'])): ?>
            <span class="badge-up">Force Required</span>
          <?php endif; ?>
          <button class="btn-schedule btn btn-sm btn-primary" data-type="<?= htmlspecialchars($it['type'] ?? '') ?>" data-folder="<?= htmlspecialchars($it['folder'] ?? '') ?>">Update Now</button>
          <a href="#" class="btn-details btn btn-sm btn-outline-secondary" data-manifest='<?= json_encode($it['manifest'] ?? []) ?>' style="margin-left:6px;">Manifest</a>
          <?php if (!empty($it['manifest']['migration_sql'])): ?>
                    <button class="btn-preview-migration btn btn-sm btn-outline-warning" data-sql='<?= htmlspecialchars($it['manifest']['migration_sql']) ?>' style="margin-left:6px;">Preview Migration</button>
          <?php endif; ?>
        </div>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
         
<!-- JSON viewer for updates result -->
<div id="updatesViewer" class="small-mono" style="margin-top:18px; border:1px solid #eee; padding:8px; background:#fff; max-height:400px; overflow:auto;"></div>

<!-- Toast container -->
<div id="toastContainer" aria-live="polite" aria-atomic="true" style="position:fixed;right:20px;top:20px;z-index:1050"></div>

<script>
(function($){
  var ajaxurl = window.ajaxurl || 'ajax.php';

  // jQuery-based post helper for simple key/value payloads
  function post(action, data){ data = data || {}; data.action = action; return $.post(ajaxurl, data, null, 'json'); }

  // Bootstrap toast helper (falls back to inline alert)
  function showToast(message, type, ttl){ ttl = ttl || 5000; var $container = $('#toastContainer'); if (!$container.length) return;
    var $toast = $(
      '<div class="toast" role="alert" aria-live="assertive" aria-atomic="true">' +
        '<div class="toast-header">' +
          '<strong class="mr-auto">' + (type? type.toUpperCase() : 'NOTICE') + '</strong>' +
          '<small class="text-muted ml-2">now</small>' +
          '<button type="button" class="ml-2 mb-1 close" data-dismiss="toast">&times;</button>' +
        '</div>' +
        '<div class="toast-body">' + (message||'') + '</div>' +
      '</div>'
    );
    $container.append($toast);
    try {
      if (window.bootstrap && bootstrap.Toast) {
        var t = new bootstrap.Toast($toast[0], { delay: ttl }); t.show();
      } else if ($.fn.toast) {
        $toast.toast({ delay: ttl }); $toast.toast('show');
      } else {
        setTimeout(function(){ $toast.fadeOut(300, function(){ $(this).remove(); }); }, ttl);
      }
    } catch (e) { setTimeout(function(){ $toast.remove(); }, ttl); }
  }

  function notify(msg, type){ $('#updatesAlert').html('<div class="alert alert-' + (type||'info') + ' small" role="alert">' + (msg||'') + '</div>'); showToast(msg, type); setTimeout(function(){ $('#updatesAlert').empty(); }, 6000); }

  function setBtnBusy($btn, text){ if (!$btn || !$btn.length) return; $btn.data('_orig', $btn.text()); $btn.prop('disabled', true).text(text); }
  function clearBtnBusy($btn){ if (!$btn || !$btn.length) return; $btn.prop('disabled', false); var o = $btn.data('_orig'); if (o) $btn.text(o); }

  $(document).ready(function(){
    $('#checkNow').on('click', function(){ var $btn = $(this); setBtnBusy($btn, 'Checking...'); post('qlopy_check_updates', {}).done(function(json){ clearBtnBusy($btn); if (json && json.status === 'success') {
          $('#updatesViewer').empty(); // render via collapsible viewer if desired
          if (json.updates) { // simple render as JSON tree (keep simple)
            $('#updatesViewer').text(JSON.stringify(json.updates, null, 2));
          }
          // render returned available updates into the table so admin sees them immediately
          (function renderUpdates(updates){ updates = updates || []; var $tbody = $('.updates-table tbody'); $tbody.empty(); updates.forEach(function(it){ var $tr = $('<tr>'); $tr.append($('<td>').text(it.type||'')); $tr.append($('<td>').text(it.folder||'')); $tr.append($('<td>').text(it.new_version || (it.manifest && it.manifest.version) || '')); $tr.append($('<td>').text(it.update_url || '').css({'max-width':'360px','overflow':'hidden','white-space':'nowrap','text-overflow':'ellipsis'})); var $actions = $('<td>'); var $div = $('<div>').addClass('updates-actions'); if (it.force_required) $div.append($('<span>').addClass('badge-up').text('Force Required')); var $btn = $('<button>').addClass('btn-schedule btn btn-sm btn-primary').attr({'data-type':it.type||'','data-folder':it.folder||''}).text('Update Now'); $div.append($btn); var $a = $('<a>').attr('href','#').addClass('btn-details btn btn-sm btn-outline-secondary').attr('data-manifest', JSON.stringify(it.manifest||{})).css('margin-left','6px').text('Manifest'); $div.append($a); if (it.manifest && it.manifest.migration_sql) { $div.append($('<button>').addClass('btn-preview-migration btn btn-sm btn-outline-warning').attr('data-sql', it.manifest.migration_sql).css('margin-left','6px').text('Preview Migration')); } $actions.append($div); $tr.append($actions); $tbody.append($tr); }); $('.updates-table h3'); // noop
            // update header count
            $('h3').each(function(){ if ($(this).text().indexOf('Available Updates') === 0) { $(this).text('Available Updates (' + updates.length + ')'); } });
            // bind events
            bindScheduleButtons(); bindPreviewButtons(); bindDetails();
          })(json.updates || []);
        } else { notify('Check failed: ' + (json && json.message ? json.message : 'Unknown'),'danger'); } }).fail(function(){ clearBtnBusy($btn); notify('Ajax error','danger'); }); });

    $('#runInitiator').on('click', function(){ var $btn = $(this); setBtnBusy($btn,'Scheduling...'); post('qlopy_run_initiator', {}).done(function(json){ clearBtnBusy($btn); if (json && json.status === 'success') { notify('Initiator scheduled ' + (json.scheduled ? json.scheduled.length : 0) + ' items.','success'); } else { notify('Initiator failed: ' + (json && json.message ? json.message : 'Unknown'),'danger'); } }).fail(function(){ clearBtnBusy($btn); notify('Ajax error','danger'); }); });

    function bindScheduleButtons(){ $('.btn-schedule').off('click').on('click', function(){ var $btn = $(this); var type = $btn.attr('data-type'); var folder = $btn.attr('data-folder'); if (!confirm('Schedule update for ' + type + (folder?(' - ' + folder):'') + ' now?')) return; setBtnBusy($btn,'Scheduling...'); post('qlopy_schedule_update', { type: type, folder: folder }).done(function(json){ clearBtnBusy($btn); if (json && json.status === 'success') notify('Scheduled (task id: ' + (json.task_id||'n/a') + ')','success'); else notify('Failed: ' + (json && json.message ? json.message : 'Unknown'),'danger'); }).fail(function(){ clearBtnBusy($btn); notify('Ajax error','danger'); }); }); }

    function bindPreviewButtons(){ $('.btn-preview-migration').off('click').on('click', function(){ var sql = $(this).attr('data-sql'); if (!sql) { notify('No inline migration SQL available','warning'); return; } var fd = new FormData(); fd.append('action','qlopy_preview_migration'); fd.append('sql', sql); $.ajax({ url: ajaxurl, method: 'POST', data: fd, processData: false, contentType: false, dataType: 'json' }).done(function(json){ if (json && json.status === 'success') { notify('Preview statements: ' + (json.statements?json.statements.length:0),'info'); // build modal
            var stmts = json.statements || []; var $container = $('#migrationModalBody').empty(); stmts.forEach(function(s){ var $div = $('<div>').css('margin-bottom','8px'); var $cb = $('<input type="checkbox">').prop('checked', true).attr('data-index', s.index).css('margin-right','8px'); var $label = $('<label>').css({'font-family':'monospace','display':'block'}).text((s.is_risky? '[RISKY] ':'') + s.statement).attr('data-index', s.index); $div.append($cb).append($label); $container.append($div); }); $('#migrationDryRunBtn').off('click').on('click', function(){ var fd2 = new FormData(); fd2.append('action','qlopy_dry_run_migration'); fd2.append('sql', sql); $.ajax({ url: ajaxurl, method: 'POST', data: fd2, processData: false, contentType: false, dataType: 'json' }).done(function(res){ if (res && res.status === 'success') notify('Dry-run succeeded. No changes applied.','success'); else notify('Dry-run failed','danger'); }).fail(function(){ notify('Ajax error during dry-run','danger'); }); }); $('#migrationApplyBtn').off('click').on('click', function(){ var selected = []; $('#migrationModalBody input[type=checkbox]').each(function(){ if ($(this).is(':checked')){ var idx = $(this).attr('data-index'); var lbl = $(this).siblings('label').text().replace(/^\[RISKY\]\s*/,'').trim(); selected.push({ index: idx, statement: lbl }); } }); if (!selected.length) return notify('No statements selected','warning'); if (!confirm('Apply ' + selected.length + ' selected statements now? This is destructive.')) return; var fd3 = new FormData(); fd3.append('action','qlopy_apply_selected_migration'); fd3.append('statements', JSON.stringify(selected)); $.ajax({ url: ajaxurl, method: 'POST', data: fd3, processData: false, contentType: false, dataType: 'json' }).done(function(res){ if (res && res.status === 'success') { notify('Applied successfully. Backup: ' + (res.backup || 'n/a'),'success'); $('#migrationModalClose').click(); } else if (res && res.details) { var d = res.details; var failedIdx = d.failed_orig_index ?? d.failed_index ?? null; if (failedIdx !== null) { var $el = $('#migrationModalBody').find('label[data-index="'+failedIdx+'"]'); if ($el.length) { $el.css('background','#ffd6d6')[0].scrollIntoView({behavior:'smooth', block:'center'}); } notify('Apply failed at statement index ' + failedIdx,'danger'); } else notify('Apply failed','danger'); } else notify('Apply failed','danger'); }).fail(function(){ notify('Ajax error during apply','danger'); }); }); $('#migrationModal').show(); } else notify('Preview failed','danger'); }).fail(function(){ notify('Ajax error','danger'); }); }); }

    function bindDetails(){ $('.btn-details').off('click').on('click', function(e){ e.preventDefault(); var m = $(this).attr('data-manifest'); try { var obj = JSON.parse(m); alert(JSON.stringify(obj, null, 2)); } catch(e){ alert('No manifest'); } }); }

    // expose helpers globally for other scripts
    window.qp_post = post;
    window.qp_notify = notify;
    // expose btn helpers for other script blocks
    window.setBtnBusy = setBtnBusy;
    window.clearBtnBusy = clearBtnBusy;
    // expose showToast for legacy callers
    window.showToast = showToast;
    // Initial bindings
    bindScheduleButtons(); bindPreviewButtons(); bindDetails();
  });

})(jQuery);
</script>

<script>
// jQuery-based settings loader/saver using the shared qp_post and toast helpers
(function($){
  var p = window.qp_post || function(action, data){ data = data || {}; data.action = action; return $.post(window.ajaxurl || 'ajax.php', data, null, 'json'); };

  function loadSettings(){
    p('qlopy_get_settings', {}).done(function(json){ if (json && json.status === 'success'){
      var s = json.settings || {};
      $('#checkerInterval').val(s.checker_interval || 21600);
      $('#initiatorInterval').val(s.initiator_interval || 43200);
      $('#allowRisky').prop('checked', !!s.allow_risky_migrations);
      $('#enableLogging').prop('checked', !!s.enable_logging);
      $('#requireSigned').prop('checked', !!s.require_signed_manifests);
      $('#backupRetention').val(s.backup_retention || 10);
      $('#stopAutoUpdates').prop('checked', !!s.stop_auto_updates);
      $('#settingsResult').text('Loaded');
    } else { $('#settingsResult').text('Failed to load settings'); } }).fail(function(){ $('#settingsResult').text('Ajax error'); });
  }

  // Bind handlers on DOM ready and defensively use global helpers if available
  $(function(){
    var setBusy = window.setBtnBusy || function($b, txt){ if ($b && $b.length) { $b.data('_orig',$b.text()); $b.prop('disabled', true).text(txt); } };
    var clearBusy = window.clearBtnBusy || function($b){ if ($b && $b.length) { $b.prop('disabled', false); var o = $b.data('_orig'); if (o) $b.text(o); } };

    $('#reloadSettings').off('click').on('click', function(){ loadSettings(); });

    $('#saveSettings').off('click').on('click', function(){
      var $btn = $(this); setBusy($btn, 'Saving...');
      var data = {
        checker_interval: parseInt($('#checkerInterval').val(), 10) || 21600,
        initiator_interval: parseInt($('#initiatorInterval').val(), 10) || 43200,
        allow_risky_migrations: $('#allowRisky').is(':checked') ? 1 : 0,
        enable_logging: $('#enableLogging').is(':checked') ? 1 : 0,
        require_signed_manifests: $('#requireSigned').is(':checked') ? 1 : 0,
        backup_retention: parseInt($('#backupRetention').val(), 10) || 10,
        stop_auto_updates: $('#stopAutoUpdates').is(':checked') ? 1 : 0
      };
      p('qlopy_save_settings', data).done(function(json){ clearBusy($btn); if (json && json.status === 'success') { $('#settingsResult').text('Saved'); (window.showToast||function(m,t){ alert(m); })('Settings saved — scheduling will use new intervals on next admin init.','success'); } else { $('#settingsResult').text('Save failed'); (window.showToast||function(m,t){ alert(m); })('Save failed','danger'); } }).fail(function(){ clearBusy($btn); (window.showToast||function(m,t){ alert(m); })('Ajax error','danger'); });
    });

    // initial load
    loadSettings();
  });
})(jQuery);
</script>

<div style="margin-top:18px;">
  <button id="viewLogs" class="actions-form btn btn-outline-secondary">View Recent Update Logs</button>
  <div id="logsArea" style="margin-top:8px; font-family:monospace; white-space:pre-wrap; max-height:320px; overflow:auto; border:1px solid #eee; padding:8px; background:#fafafa;"></div>
</div>

<div style="margin-top:18px;">
  <h3>Backups</h3>
  <button id="listBackups" class="actions-form btn btn-outline-secondary">List Backups</button>
  <div id="backupsArea" style="margin-top:8px; font-family:monospace; white-space:pre-wrap; max-height:320px; overflow:auto; border:1px solid #eee; padding:8px; background:#fff;"></div>
</div>

<div style="margin-top:18px; border-top:1px solid #eee; padding-top:12px;">
  <h3>Updater Settings</h3>
  <div style="max-width:720px;">
    <label>Checker interval (seconds): <input id="checkerInterval" type="number" min="60" class="form-control form-control-sm" /></label><br>
    <label>Initiator interval (seconds): <input id="initiatorInterval" type="number" min="60" class="form-control form-control-sm" /></label><br>
    <label>Allow risky migrations: <input id="allowRisky" type="checkbox" class="form-check-input" /></label><br>
    <label>Backup retention (keep N backups): <input id="backupRetention" type="number" min="1" class="form-control form-control-sm" /></label><br>
    <label>Require signed manifests: <input id="requireSigned" type="checkbox" class="form-check-input" /></label><br>
    <label>Enable logging: <input id="enableLogging" type="checkbox" class="form-check-input" /></label><br>
    <label>Stop auto updates by default: <input id="stopAutoUpdates" type="checkbox" class="form-check-input" /></label><br>
    <button id="saveSettings" type="button" class="btn btn-primary">Save Settings</button>
    <button id="reloadSettings" type="button" class="btn btn-outline-secondary">Reload</button>
    <div id="settingsResult" style="margin-top:8px;font-family:monospace;"></div>
  </div>
</div>

<div style="margin-top:18px; border-top:1px solid #eee; padding-top:12px;">
  <h3>Trusted Keys</h3>
  <div style="max-width:720px;">
    <p>Upload public PEM keys trusted for manifest signature verification. Keys are stored under `uploads/updates/keys`.</p>
    <div class="input-group mb-3">
      <div class="input-group-prepend">
        <span class="input-group-text" id="inputGroupFileAddon01">Upload</span>
      </div>
      <div class="custom-file">
        <input type="file" class="custom-file-input" id="keyFile" aria-describedby="inputGroupFileAddon01">
        <label class="custom-file-label" for="keyFile">Choose file</label>
      </div>
    </div>
    <button id="uploadKey" class="btn btn-primary" type="button">Upload Key</button>
    <div id="keysList" style="margin-top:8px;font-family:monospace;"></div>
  </div>
</div>

<script>
(function($){
  // Use global qp_post if available (defined by the main jQuery handlers), otherwise fall back to $.post wrapper
  var p = window.qp_post || function(action, data){ data = data || {}; data.action = action; return $.post(window.ajaxurl || 'ajax.php', data, null, 'json'); };

  function loadKeys(){
    p('qlopy_list_keys', {}).done(function(j){
      var $el = $('#keysList').empty();
      if (j && j.status === 'success' && Array.isArray(j.keys)) {
        j.keys.forEach(function(k){
          var $row = $('<div>').css({margin:'4px 0'}).text(k + ' ');
          var $btn = $('<button>').addClass('btn btn-sm btn-outline-danger').text('Delete').on('click', function(){
            if (!confirm('Delete key ' + k + '?')) return;
            p('qlopy_delete_key', { id: k }).done(function(rr){ if (rr && rr.status === 'success') loadKeys(); else showToast('Delete failed','danger'); }).fail(function(){ showToast('Ajax error','danger'); });
          });
          $row.append($btn);
          $el.append($row);
        });
      } else {
        $el.text('(no keys)');
      }
    }).fail(function(){ showToast('Ajax error','danger'); });
  }

  $('#uploadKey').on('click', function(){
    var f = document.getElementById('keyFile').files[0];
    if (!f) return showToast('Select a PEM file','warning');
    var fd = new FormData(); fd.append('action','qlopy_upload_key'); fd.append('keyfile', f);
    $.ajax({ url: window.ajaxurl || 'ajax.php', method: 'POST', data: fd, processData: false, contentType: false, dataType: 'json' })
      .done(function(res){ if (res && res.status === 'success') { showToast('Uploaded: ' + res.id,'success'); loadKeys(); } else { showToast('Upload failed','danger'); } })
      .fail(function(){ showToast('Ajax error','danger'); });
  });

  // Update custom-file label when a file is selected (Bootstrap 4)
  $('#keyFile').on('change', function(){
    var fileName = $(this).val().split('\\').pop();
    $(this).siblings('.custom-file-label').addClass('selected').text(fileName || 'Choose file');
  });

  // initial load
  $(function(){ loadKeys(); });
})(jQuery);
</script>

<script>
(function($){
  $('#viewLogs').on('click', function(){
    var $btn = $(this); setBtnBusy($btn, 'Loading...');
    var p = window.qp_post || function(a,d){ d = d||{}; d.action = a; return $.post(window.ajaxurl||'ajax.php', d, null, 'json'); };
    p('qlopy_get_update_logs', {}).done(function(json){ clearBtnBusy($btn); var $area = $('#logsArea').empty(); if (json && json.status === 'success') {
      if (Array.isArray(json.logs)) $area.text(JSON.stringify(json.logs, null, 2)); else $area.text(String(json.logs || ''));
    } else {
      $area.text('Failed to fetch logs: ' + (json && json.message ? json.message : 'Unknown'));
    } }).fail(function(){ clearBtnBusy($btn); showToast('Ajax error','danger'); });
  });
})(jQuery);
</script>

<script>
(function($){
  $('#listBackups').off('click').on('click', function(){
    var $btn = $(this);
    try {
      setBtnBusy($btn, 'Loading...');
      var p = window.qp_post || function(a,d){ d=d||{}; d.action=a; return $.post(window.ajaxurl||'ajax.php', d, null, 'json'); };
      p('qlopy_list_backups', {}).done(function(json){
        clearBtnBusy($btn);
        var $area = $('#backupsArea').empty();
        console.log('qlopy_list_backups ->', json);
        if (json && json.status === 'success') {
          if (Array.isArray(json.backups) && json.backups.length) {
            var $table = $('<table class="table table-sm">').append('<thead><tr><th>Label</th><th>DB</th><th>Created</th><th>Actions</th></thead>');
            var $tbody = $('<tbody>');
            json.backups.forEach(function(b){
              var $tr = $('<tr>');
              $tr.append($('<td>').text(b.label || b.name));
              $tr.append($('<td>').text(b.has_db? 'Yes':'No'));
              $tr.append($('<td>').text(b.created_at_formatted || b.created_at || ''));
              var $actions = $('<td>');
              var $restore = $('<button>').addClass('btn btn-sm btn-danger mr-2').text('Restore');
              var $download = $('<button>').addClass('btn btn-sm btn-outline-secondary mr-2').text('Download');
              var $delete = $('<button>').addClass('btn btn-sm btn-outline-danger').text('Delete');
              $actions.append($restore).append($download).append($delete);
              $tr.append($actions);
              $tbody.append($tr);

              $restore.on('click', function(){ if (!confirm('Restore backup "'+(b.name||b.label)+'"?')) return; var $r = $(this); setBtnBusy($r,'Restoring...'); p('qlopy_restore_backup', { backup: b.name }).done(function(res){ clearBtnBusy($r); if (res && res.status === 'success') { showToast('Restored: '+(b.name||b.label),'success'); } else { showToast('Restore failed','danger'); } }).fail(function(){ clearBtnBusy($r); showToast('Ajax error','danger'); }); });
              $download.on('click', function(){ var $d = $(this); setBtnBusy($d,'Preparing...'); p('qlopy_request_backup_download', { backup: b.name, ttl: 300 }).done(function(r){ clearBtnBusy($d); if (r && r.status === 'success' && r.url) { window.open(r.url,'_blank'); } else { showToast('Zip failed','danger'); } }).fail(function(){ clearBtnBusy($d); showToast('Ajax error','danger'); }); });
              $delete.on('click', function(){ if (!confirm('Delete backup "'+(b.name||b.label)+'"?')) return; var $d = $(this); setBtnBusy($d,'Deleting...'); p('qlopy_delete_backup', { backup: b.name }).done(function(rr){ clearBtnBusy($d); if (rr && rr.status === 'success') { showToast('Deleted backup','success'); $tr.remove(); } else { showToast('Delete failed','danger'); } }).fail(function(){ clearBtnBusy($d); showToast('Ajax error','danger'); }); });
            });
            $table.append($tbody); $area.append($table);
          } else {
            $area.text('No backups found.');
          }
        } else {
          $area.text('Failed to list backups: ' + (json && json.message ? json.message : 'Unknown'));
          if (json && json.message) showToast(json.message,'danger');
        }
      }).fail(function(jqXHR, textStatus, err){ clearBtnBusy($btn); console.error('qlopy_list_backups fail', textStatus, err, jqXHR && jqXHR.responseText); showToast('Ajax error while listing backups','danger'); });
    } catch (ex) { clearBtnBusy($btn); console.error('listBackups exception', ex); showToast('Unexpected error','danger'); }
  });
})(jQuery);
</script>

<!-- Migration Preview Modal -->
<div id="migrationModal" style="display:none; position:fixed; left:0; top:0; right:0; bottom:0; background:rgba(0,0,0,0.5); z-index:9999;">
  <div style="width:90%; max-width:900px; margin:40px auto; background:#fff; padding:16px; border-radius:6px; box-shadow:0 4px 20px rgba(0,0,0,0.2);">
    <h3>Migration Preview</h3>
    <div id="migrationModalBody" style="max-height:400px; overflow:auto; padding:8px; border:1px solid #eee; background:#fafafa;"></div>
    <div style="margin-top:12px; display:flex; gap:8px; justify-content:flex-end;">
      <button id="migrationDryRunBtn" type="button" class="btn btn-outline-secondary">Dry-Run</button>
      <button id="migrationApplyBtn" type="button" class="btn btn-warning" style="background:#c33;color:#fff;">Apply Selected</button>
      <button id="migrationModalClose" type="button" class="btn btn-secondary">Close</button>
    </div>
  </div>
</div>

<script>
document.getElementById('migrationModalClose').addEventListener('click', function(){ document.getElementById('migrationModal').style.display = 'none'; });
</script>
</div>
<?php require_once __DIR__ . '/inc/footer.php'; ?>