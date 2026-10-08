<?php
require_once __DIR__ . '/admin_head.php';

// Restrict to users who can manage posts (media)
if (!check_permission('manage_posts')) {
  header('HTTP/1.1 403 Forbidden');
  echo 'Forbidden';
  exit;
}
$pdo = db();

// Compute dynamic admin URL path similar to admin-menus.php
// Dynamic admin ajax url (consistent with taxonomies.php)
$admin_base_path = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
$admin_ajax_url = $admin_base_path . '/ajax.php';

// Handle simple upload POST (non-AJAX fallback)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['file'])) {
  // collect optional upload options from the form
  $opts = [];
  if (!empty($_POST['folder'])) $opts['folder'] = preg_replace('/[^a-zA-Z0-9_-]+/', '', (string)$_POST['folder']);
  if (isset($_POST['use_date_folders'])) $opts['use_date_folders'] = filter_var($_POST['use_date_folders'], FILTER_VALIDATE_BOOLEAN);
  if (isset($_POST['skip_sizes'])) $opts['skip_sizes'] = filter_var($_POST['skip_sizes'], FILTER_VALIDATE_BOOLEAN);
  if (isset($_POST['compress_original'])) $opts['compress_original'] = filter_var($_POST['compress_original'], FILTER_VALIDATE_BOOLEAN);
  if (isset($_POST['original_quality'])) $opts['original_quality'] = intval($_POST['original_quality']);

  [$ok, $res] = qp_handle_upload_extensive($_FILES['file'], null, $opts);
  if ($ok) {
    header('Location: media.php?uploaded=1');
    exit;
  } else {
    $upload_error = $res;
  }
}

// Initial data
$page = 1;
$per_page = 24;
$items = [];

// Page title used by admin header <title>
$page_title = 'Media Library';

include __DIR__ . '/inc/header.php';
?>
<div class="container mt-4">
  <h1 class="mb-3">Media Library</h1>
  <?php if (!empty($_GET['uploaded'])): ?>
    <div class="alert alert-success">Upload successful.</div>
  <?php endif; ?>
  <?php if (!empty($upload_error)): ?>
    <div class="alert alert-danger">Upload failed: <?= htmlspecialchars($upload_error) ?></div>
  <?php endif; ?>

  <div class="mb-4">
    <div id="dropzone" class="p-4 border border-2 rounded text-center" style="background:#fafafa; position:relative; padding-bottom:122px;">
      <p class="mb-2">Drag & drop files here or click to select</p>
      <input type="file" id="fileInput" multiple style="display:none;" />
      <button class="btn btn-primary d-inline-flex align-items-center" id="selectBtn" type="button" style="position:relative;z-index:10;">
        <span id="selectBtnText">Select Files</span>
        <span id="selectSpinner" class="spinner-border spinner-border-sm ms-2" style="display:none;" role="status" aria-hidden="true"></span>
      </button>
      <!-- Fixed bottom bar keeps dropzone height constant -->
      <div id="uploadBar" style="display:flex;flex-direction:column;align-items:center;justify-content:center;width:100%;pointer-events:none;">
        <div id="uploadStatus" class="small text-muted text-center" style="width:100%;max-width:720px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">&nbsp;</div>
        <div style="width:100%;display:flex;justify-content:center;">
          <div style="flex:0 0 60%;max-width:60%;min-width:160px;">
            <div id="uploadProgress" class="progress" style="height:18px; opacity:0; transition:opacity .18s ease; pointer-events:none; position:relative;">
              <div class="progress-bar" role="progressbar" style="width:0%" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100"></div>
              <div class="progress-text position-absolute w-100 h-100 d-flex align-items-center justify-content-center small text-white">0%</div>
            </div>
          </div>
        </div>
        <div id="uploadError" class="text-danger small text-center" style="width:100%;max-width:720px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">&nbsp;</div>
      </div>
    </div>
    <?php
      $mimes = function_exists('qp_allowed_mimes') ? qp_allowed_mimes() : [];
      $exts = [];
      foreach ($mimes as $mime => $arr) {
        foreach ((array)$arr as $e) $exts[] = ltrim(strtolower($e), '.');
      }
      $exts = array_unique($exts);
      sort($exts);
      if (empty($exts)) {
        $exts = ['jpeg','png','gif','webp','pdf'];
      }
    ?>
    <small class="text-muted">Allowed: <?= htmlspecialchars(implode(', ', $exts)) ?></small>
  </div>
  <!-- Banner removed: uploads will auto-prepend new items directly -->

  <div class="d-flex justify-content-between align-items-center mb-2">
    <div>
      <button class="btn btn-sm btn-outline-secondary" id="bulkToggleBtn">Bulk Select</button>
      <button class="btn btn-outline-danger btn-sm ms-2" id="bulkDeleteBtn" style="display:none;" disabled>Delete Selected</button>
      <select id="mediaFilter" class="form-control form-control-sm d-inline-block ms-3 me-2" style="width:auto;vertical-align:middle;">
        <option value="all">All files</option>
        <option value="images">Images</option>
      </select>
      <select id="mediaMonth" class="form-control form-control-sm d-inline-block ms-2" style="width:auto;vertical-align:middle;">
        <option value="all">All months</option>
      </select>
    </div>
    <div class="small text-muted" id="selectionCount">0/0</div>
  </div>
  <div class="row row-cols-2 row-cols-sm-3 row-cols-md-4 row-cols-lg-6 g-3" id="media-grid">
    <?php foreach ($items as $item): $meta = qp_get_attachment_metadata((int)$item['id']); $thumb = qp_get_attachment_image_src((int)$item['id'], 'thumbnail'); ?>
      <div class="col pb-3">
        <div class="card h-100" data-id="<?= (int)$item['id'] ?>">
          <div class="position-absolute" style="z-index:2;">
            <input type="checkbox" class="form-check-input m-2 select-box d-none" data-id="<?= (int)$item['id'] ?>" />
          </div>
          <?php if ($thumb): ?>
            <div class="card-body p-2 d-flex align-items-center justify-content-center" style="min-height:150px;">
              <img src="<?= htmlspecialchars($thumb[0]) ?>" class="img-fluid" style="max-height:140px;" alt="">
            </div>
          <?php else: ?>
            <div class="card-body d-flex align-items-center justify-content-center" style="min-height:150px;background:#f5f5f5;">
              <span class="text-muted">No preview</span>
            </div>
          <?php endif; ?>
          <!-- footer intentionally cleared; spacing provided by pb-3 on card -->
        </div>
      </div>
    <?php endforeach; ?>
  </div>
  <div id="mediaOverlay" style="display:none;position:absolute;inset:0;background:rgba(255,255,255,0.7);z-index:40;align-items:center;justify-content:center;">
    <div class="spinner-border text-primary" role="status"><span class="d-none">Loading...</span></div>
  </div>
  <!-- Global drop overlay for whole-window drag/drop handling -->
  <div id="globalDropOverlay" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.25);z-index:2050;align-items:center;justify-content:center;pointer-events:none;">
    <div style="background:rgba(255,255,255,0.95);padding:18px;border-radius:8px;border:2px dashed #999;pointer-events:auto;">Drop files anywhere to upload</div>
  </div>

  <!-- Singleton Modal for details (moved outside loop) -->
  <div class="modal fade" tabindex="-1" id="mediaModal" aria-modal="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title">Media Details</h5>
          <button type="button" class="btn-close btn btn-sm btn-secondary" data-bs-dismiss="modal" aria-label="Close">Close</button>
        </div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-6">
              <div id="mdPreviewWrapper" class="border p-2" style="min-height:150px;display:flex;align-items:center;justify-content:center;background:#f9f9f9;">
                <span class="text-muted small">No preview</span>
              </div>
            </div>
            <div class="col-md-6">
              <input type="hidden" id="mdId" />
              <div class="mb-3">
                <label class="form-label">Title</label>
                <input type="text" class="form-control" id="mdTitle" />
              </div>
              <div class="mb-3">
                <label class="form-label">Alt Text</label>
                <input type="text" class="form-control" id="mdAlt" />
              </div>
              <div class="small text-muted" id="mdMeta"></div>
              <div class="small mt-2" id="mdExtra"></div>
              <div class="mt-3 d-flex gap-2" id="mdActions">
                <a id="mdOpen" class="btn btn-sm btn-outline-secondary" target="_blank" href="#">View</a>
                <button type="button" class="btn btn-sm btn-danger" id="mdDeleteBody">Delete</button>
              </div>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Close</button>
          <button type="button" class="btn btn-sm btn-primary" id="mdSave">Save</button>
        </div>
      </div>
    </div>
  </div>

  <div class="text-center py-3">
    <div id="infiniteSentinel" style="height:1px; width:100%;"></div>
    <button id="loadMore" class="btn btn-outline-primary" style="display:none;">Load more</button>
    <div id="loadMoreStatus" class="small text-muted mt-2"></div>
  </div>
  <div id="gridError" class="alert alert-danger mt-3" style="display:none;"></div>
</div>

<script>
// jQuery-based media library logic
window.openMedia = function(btn) { /* fallback if inline still exists */ $(btn).trigger('click'); };
$(function(){
  const $grid = $('#media-grid');
  const $loadMore = $('#loadMore');
  const $bulkDeleteBtn = $('#bulkDeleteBtn');
  const $selectionCount = $('#selectionCount');
  const $totalCount = $('#selectionCount'); // reuse element to show total too
  const $uploadStatus = $('#uploadStatus');
  const $uploadError = $('#uploadError');
  const perPage = 24;
  let totalItems = 0;
  let currentPage = 1;
  let newlyUploadedIds = [];
  const HIGHLIGHT_MS = 4000; // ms to keep the newly-added highlight
  let loading = false;
  let reachedEnd = false;
  let filterMode = 'all';
  let monthFilter = 'all';
  // Keep a reference to the IntersectionObserver to disconnect later
  let ioObserver = null;
  const itemIndexMap = [];
  const itemSet = new Set();
  const mediaModalEl = document.getElementById('mediaModal');
  const mediaModal = new bootstrap.Modal(mediaModalEl);
  // Seed itemIndexMap from any server-rendered cards so keyboard nav includes them
  $('#media-grid .card[data-id]').each(function(){
    const id = parseInt($(this).data('id') || '0', 10);
    if (id && itemIndexMap.indexOf(id) === -1) {
      itemIndexMap.push(id);
      itemSet.add(id);
    }
  });
  // Add selection visual CSS
  const style = `
    <style>
      .card.selected { box-shadow: 0 0 0 3px rgba(0,123,255,0.25); }
    </style>`;
  $('head').append(style);
  // Additional highlight style for newly added items
  const highlightStyle = `
    <style>
      @keyframes qp-pulse {
        0% { box-shadow: 0 0 0 0 rgba(0,123,255,0.18); }
        50% { box-shadow: 0 0 0 10px rgba(0,123,255,0.08); }
        100% { box-shadow: 0 0 0 0 rgba(0,123,255,0.0); }
      }
      .card.newly-added { animation: qp-pulse 1.2s ease-in-out infinite; }
      .progress-text { pointer-events:none; font-weight:600; }
    </style>`;
  $('head').append(highlightStyle);
  // Fallback explicit close binding in case data-bs-dismiss fails
  $('#mediaModal .btn-close, #mediaModal [data-bs-dismiss="modal"]').on('click', function(e){
    e.preventDefault();
    mediaModal.hide();
  });
  // Cleanup on hide
  $('#mediaModal').on('hidden.bs.modal', function(){
    $('#mdPreviewWrapper').html('<span class="text-muted small">No preview</span>');
    $('#mdId').val('');
  });

  // Helper for admin AJAX calls. Accepts optional ajax options as third arg.
  function adminPost(data, isForm=false, ajaxOpts){
    // Prefer the runtime-injected `window.ajaxurl` for flexibility; fallback to
    // `window.qlopyAdminAjax` (older name) or the server-provided PHP var.
    const url = (window.ajaxurl || window.qlopyAdminAjax || '<?= $admin_ajax_url ?>');
    const opts = Object.assign({ url, method:'POST', data: data }, ajaxOpts || {});
    if (isForm){ opts.processData = false; opts.contentType = false; }
    return $.ajax(opts);
  }

  function parseJsonSafeAjax(jqXHR){
    // Already parsed if JSON; else treat as error
    if (typeof jqXHR === 'object' && jqXHR !== null && jqXHR.status && jqXHR.message) return jqXHR; // already shaped
    return jqXHR; // jQuery auto-parses JSON when possible
  }

  function escapeHtml(s){
    return $('<div/>').text(s ?? '').html();
  }

  function showGridError(msg){
    $('#gridError').text(msg || 'An error occurred.').show();
  }

  function renderItems(items){
    items.forEach(it => {
      if (itemSet.has(it.id)) return; // avoid duplicates
      const card = `
          <div class="col pb-3">
            <div class="card h-100" data-id="${it.id}">
              <div class="position-absolute" style="z-index:2;">
                <input type="checkbox" class="form-check-input m-2 select-box d-none" data-id="${it.id}" />
              </div>
              <div class="card-body p-2 d-flex align-items-center justify-content-center" style="min-height:150px;">
                ${it.thumb ? `<img src="${it.thumb}" class="img-fluid" style="max-height:140px;" alt="">` : `<span class="text-muted">No preview</span>`}
              </div>
            </div>
          </div>`;
      $grid.append(card);
      itemIndexMap.push(it.id);
      itemSet.add(it.id);
    });
    updateSelectionState();
  }

  // Load a page of media items (infinite scroll / manual load)
  function loadPage(){
    if (loading || reachedEnd) return;
    loading = true;
    $('#loadMore').prop('disabled', true).text('Loading…');
    // Capture current filter values at the start of the request so concurrent
    // calls (e.g. from IntersectionObserver) don't pick up a later change.
    const reqFileMode = (filterMode && filterMode !== 'all') ? filterMode : null;
    const reqMonth = (monthFilter && monthFilter !== 'all') ? monthFilter : null;
    const req = { action:'qp_media_list', p: currentPage, per_page: perPage };
    if (reqFileMode) req.file_mode = reqFileMode;
    if (reqMonth) req.uploaded_month = reqMonth;
    adminPost(req)
      .done(resp => {
        const j = parseJsonSafeAjax(resp);
        if (j && j.status === 'success') {
          const items = j.items || [];
          renderItems(items);
          const total = j.total || 0;
          totalItems = total;
          // update counts display according to bulkMode
          updateSelectionState();
          const totalLoaded = $('#media-grid .col').length;
          if (totalLoaded >= total || items.length === 0) {
            reachedEnd = true;
            $('#loadMore').prop('disabled', true).removeClass('btn-outline-primary').addClass('btn-outline-secondary').text('No more items');
            $('#loadMoreStatus').text('All items are loaded.');
            if (ioObserver) { try { ioObserver.disconnect(); } catch(e){} }
          } else {
            currentPage++;
          }
        } else {
          showGridError((j && j.message) || 'Failed to load media.');
        }
      })
      .fail(xhr => { showGridError(xhr && xhr.responseText ? xhr.responseText : 'Request failed'); })
      .always(()=>{ loading = false; if (!reachedEnd) { $('#loadMore').prop('disabled', false).text('Load more'); } });
  }

  function openById(id){
    adminPost({ action:'qp_media_get', post_id:id })
      .done(resp => {
        const j = parseJsonSafeAjax(resp);
        if (j.status !== 'success') return;
        const it = j.post; const meta = j.meta || {}; const alt = j.alt || '';
        $('#mdId').val(it.id);
        $('#mdTitle').val(it.title || '');
        $('#mdAlt').val(alt || '');
        const file = meta.file || {};
        let previewHtml = '<span class="text-muted small">No preview</span>';
        if (file.mime && file.mime.startsWith('image/')) {
          previewHtml = `<img src="${file.url}" alt="${escapeHtml(alt || '')}" class="img-fluid" />`;
        } else if (file.mime === 'application/pdf') {
          previewHtml = `<iframe src="${file.url}" class="w-100" style="min-height:400px;" title="PDF"></iframe>`;
        } else if (file.ext && (file.ext === 'doc' || file.ext === 'docx')) {
          previewHtml = `<div class="p-3 w-100"><p class="mb-2">Document preview unavailable.</p><a href="${file.url}" target="_blank" class="btn btn-sm btn-outline-secondary">Download</a></div>`;
        } else if (file.url) {
          previewHtml = `<div class="p-3 w-100"><p class="mb-2">No inline preview.</p><a href="${file.url}" target="_blank" class="btn btn-sm btn-outline-secondary">Download</a></div>`;
        }
        $('#mdPreviewWrapper').html(previewHtml);
        $('#mdMeta').text(`${file.mime || ''} • ${Math.round((file.size||0)/1024)} KB`);
        $('#mdExtra').html(`<div>Uploaded: ${escapeHtml(it.created_at_formatted || it.created_at || '')}</div><div>By: ${escapeHtml(it.author_name || '')}</div>`);
        // set Open link to actual file URL if available
        if (file.url) {
          $('#mdOpen').attr('href', file.url).show();
        } else {
          $('#mdOpen').attr('href', '#').hide();
        }
        // Ensure aria-hidden is not set while opening (avoid focus/aria mismatch warnings)
        const $modal = $('#mediaModal');
       $modal.removeAttr('aria-hidden');
        mediaModal.show();
         $modal.removeAttr('aria-hidden');
         $(this).attr('aria-modal','true');
      });
  }

  // Accessibility: ensure aria-hidden is consistent and focus is moved into the modal
  $('#mediaModal').on('show.bs.modal', function(){
    $(this).removeAttr('aria-hidden');
    $(this).attr('aria-modal','true');
  });
  $('#mediaModal').on('shown.bs.modal', function(){
    // Move focus to primary action inside modal to avoid descendant focus being hidden
    const $primary = $('#mdSave');
    if ($primary.length) $primary.trigger('focus');
  });
  $('#mediaModal').on('hidden.bs.modal', function(){
    // restore aria-hidden to true when modal is fully hidden for compatibility
    //$(this).attr('aria-hidden','true');
    $(this).removeAttr('aria-hidden');
    $(this).attr('aria-modal','true');
  });

  // Card click: in normal mode open modal; in bulk mode toggle checkbox selection
  let bulkMode = false;
  $(document).on('click', '.card[data-id]', function(e){
    // Ignore clicks on action links
    if ($(e.target).closest('a, button').length) return;
    const $card = $(this);
    const id = $card.data('id');
    if (bulkMode){
      const $cb = $card.find('.select-box');
      $cb.prop('checked', !$cb.prop('checked'));
      updateSelectionState();
      return;
    }
    openById(id);
  });

  $('#bulkToggleBtn').on('click', function(){
    bulkMode = !bulkMode;
    $('.select-box').toggleClass('d-none', !bulkMode).prop('checked', false);
    $('#bulkDeleteBtn').toggle(bulkMode);
    $(this).text(bulkMode ? 'Exit Bulk' : 'Bulk Select');
    // keep selection/count area visible; format changes depending on bulk mode
    // disable/enable filter while bulk selecting
    $('#mediaFilter,#mediaMonth').prop('disabled', bulkMode);
    updateSelectionState();
  });

  // Filter change: update filterMode and reload grid
  $('#mediaFilter').on('change', function(){ filterMode = $(this).val() || 'all'; reloadGrid(); });
  $('#mediaMonth').on('change', function(){ monthFilter = $(this).val() || 'all'; reloadGrid(); });

  $('#mdSave').on('click', function(){
    const id = $('#mdId').val();
    adminPost({ action:'qp_media_update', post_id:id, title:$('#mdTitle').val(), alt:$('#mdAlt').val() })
      .done(resp => {
        const j = resp;
        if (j.status === 'success') {
          // Fetch updated item to refresh card without reloading page
          adminPost({ action:'qp_media_get', post_id:id }).done(r2 => {
            if (r2.status === 'success') {
              const it2 = r2.post || {}; const alt2 = r2.alt || '';
              // Update card title
              const $card = $grid.find(`.card[data-id='${id}']`);
              $card.find('.card-body .small').text(it2.title || '').attr('title', it2.title || '');
              // If image currently shown in modal, adjust its alt before hiding
              const $img = $('#mdPreviewWrapper img');
              if ($img.length) { $img.attr('alt', alt2 || ''); }
            }
            mediaModal.hide();
          });
        } else {
          showGridError(j.message || 'Save failed');
        }
      })
      .fail(xhr => showGridError(xhr.responseText || 'Save failed'));
  });

  // Modal delete (footer) and modal body delete share the same deletion logic
  function handleModalDelete(id){
    if (!id) return;
    if (!confirm('Delete this file?')) return;
    adminPost({ action:'qp_media_delete', post_id:id })
      .done(resp => {
        const j = resp;
        if (j.status === 'success'){
          // Remove the deleted card and try to fill gap by loading one more page if possible
          mediaModal.hide();
          const $card = $(`.card[data-id='${id}']`);
          if ($card.length) {
            const $col = $card.closest('.col');
            $col.remove();
            // Update internal structures
            const nid = parseInt(id,10);
            itemSet.delete(nid);
            const idx = itemIndexMap.indexOf(nid);
            if (idx !== -1) itemIndexMap.splice(idx,1);
            // update total
            totalItems = Math.max(0, (totalItems || 0) - 1);
            const totalLoadedNow = $('#media-grid .col').length;
            // update display centrally
            updateSelectionState();
          }
          // Do not trigger a full or paginated reload after a single delete;
          // removing the card and updating counts is sufficient and avoids
          // loading the entire list unexpectedly.
        } else {
          showGridError(j.message || 'Delete failed');
        }
      })
      .fail(xhr => showGridError(xhr.responseText || 'Delete failed'));
  }

  $(document).on('click', '#mdDeleteBody', function(){
    const id = $('#mdId').val();
    handleModalDelete(id);
  });

  // (no-op) inline delete wiring consolidated with '#mdDelete, #mdDeleteBody' handler above

  function updateSelectionState(){
    const selected = $('.select-box:checked').map(function(){ return $(this).data('id'); }).get();
    const totalLoaded = $('#media-grid .col').length;
    // When not in bulk mode, hide the "0 selected" part and show only counts
    if (bulkMode) {
      $selectionCount.text(`${selected.length} selected • ${totalLoaded}/${totalItems}`);
    } else {
      $selectionCount.text(`${totalLoaded}/${totalItems}`);
    }
    $bulkDeleteBtn.prop('disabled', selected.length === 0);
    // Visual indicator for selected cards
    $('.card[data-id]').each(function(){
      const $c = $(this);
      const id = $c.data('id');
      if (selected.indexOf(id) !== -1) $c.addClass('selected'); else $c.removeClass('selected');
    });
  }

  function selectedCount(){
    return $('.select-box:checked').length;
  }
  $(document).on('change', '.select-box', updateSelectionState);

  $bulkDeleteBtn.on('click', function(){
    const ids = $('.select-box:checked').map(function(){ return $(this).data('id'); }).get();
    if (!ids.length) return;
    if (!confirm(`Delete ${ids.length} item(s)?`)) return;
    adminPost({ action:'qp_media_bulk_delete', ids:ids.join(',') })
      .done(resp => {
        const j = resp;
        if (j.status === 'success'){
          ids.forEach(id=>{ const nid = parseInt(id,10); const $card = $(`.card[data-id='${id}']`); if ($card.length) $card.closest('.col').remove(); itemSet.delete(nid); const idx = itemIndexMap.indexOf(nid); if (idx !== -1) itemIndexMap.splice(idx,1); });
          // adjust total and update counts
          totalItems = Math.max(0, (totalItems || 0) - ids.length);
          updateSelectionState();
        } else {
          showGridError(j.message || 'Bulk delete failed');
        }
      })
      .fail(xhr => showGridError(xhr.responseText || 'Bulk delete failed'));
  });

  // Infinite scroll: window scroll near bottom
  $(window).on('scroll', function(){
    if (reachedEnd || loading) return;
    const scrollTop = $(window).scrollTop();
    const windowHeight = $(window).height();
    const docHeight = $(document).height();
    if (scrollTop + windowHeight >= docHeight - 400){ loadPage(); }
  });

  // Also trigger when the sentinel enters viewport via IntersectionObserver
  if ('IntersectionObserver' in window) {
    ioObserver = new IntersectionObserver((entries) => {
      entries.forEach(entry => {
        if (entry.isIntersecting) { loadPage(); }
      });
    }, { root: null, rootMargin: '200px', threshold: 0 });
    const sentinel = document.getElementById('infiniteSentinel');
    if (sentinel) ioObserver.observe(sentinel);
  }

  // Manual button remains as fallback (hidden)
  $('#loadMore').on('click', function(){ if (!reachedEnd) loadPage(); });
  // Note: banner and Show button removed. New uploads are fetched and
  // prepended automatically in `handleFiles()` once uploads complete.
  
  // Auto-load initial pages if content is short; show button fallback if needed
  setTimeout(function(){
    if (!reachedEnd) {
      const windowHeight = $(window).height();
      const docHeight = $(document).height();
      if (docHeight - windowHeight < 100) { loadPage(); }
      // If IntersectionObserver not supported, show button fallback
      if (!('IntersectionObserver' in window)) {
        $('#loadMore').show();
      }
    }
  }, 400);

  // Drag & Drop
  const $dropzone = $('#dropzone');
  const $fileInput = $('#fileInput');
  $('#selectBtn').on('click', ()=> $fileInput.trigger('click'));
  const $globalDrop = $('#globalDropOverlay');
  // Helper: detect whether the drag event contains real files (from OS/file picker).
  // Return true only when `dataTransfer.files` has entries OR any `dataTransfer.items` has kind === 'file'.
  // This avoids treating in-page DOM drags (images, links) as file uploads.
  function isFileDrag(ev){
    try{
      const oev = ev && ev.originalEvent ? ev.originalEvent : ev;
      if (!oev || !oev.dataTransfer) return false;
      const dt = oev.dataTransfer;
      // Strong indicator: files list contains real File objects
      if (dt.files && dt.files.length && dt.files.length > 0) return true;
      // Fallback: some browsers expose items with kind === 'file' when dragging from OS
      if (dt.items && dt.items.length){
        for (let i = 0; i < dt.items.length; i++){
          try{ const it = dt.items[i]; if (it && (it.kind === 'file' || (it.type && it.type.indexOf('image/') === 0))) return true; }catch(_){}
        }
      }
    }catch(_){ }
    return false;
  }
  // Helper: detect whether the drag started inside the media grid (an internal drag)
  function isInternalGridDrag(ev){
    try{
      const oev = ev && ev.originalEvent ? ev.originalEvent : ev;
      const tgt = oev && oev.target ? oev.target : (ev && ev.target ? ev.target : null);
      if (!tgt) return false;
      // use jQuery to check ancestry
      try{ if ($(tgt).closest && $(tgt).closest('#media-grid').length) return true; }catch(_){ }
    }catch(_){ }
    return false;
  }
  // Robust flag: set when a dragstart originates inside the media grid
  let _isInternalDrag = false;
  // Use capture on document to reliably catch dragstart/dragend for internal drags
  $(document).on('dragstart', function(e){
    try{
      const tgt = e && e.target ? e.target : null;
      if (tgt && $(tgt).closest && $(tgt).closest('#media-grid').length){ _isInternalDrag = true; }
      else _isInternalDrag = false;
    }catch(_){ _isInternalDrag = false; }
  });
  $(document).on('dragend drop', function(e){ _isInternalDrag = false; });
  // Local dropzone behavior
  $dropzone.on('dragenter dragover', function(e){
    // Ignore drags initiated within the grid (internal thumbnail drags)
    if (_isInternalDrag) return;
    // Only show overlay for actual file drags (including files dragged over the grid)
    if (!isFileDrag(e)) return;
    e.preventDefault(); e.stopPropagation();
    $dropzone.addClass('border-primary'); $globalDrop.show();
  });
  $dropzone.on('dragleave', function(e){
    if (_isInternalDrag) return;
    if (!isFileDrag(e)) return;
    e.preventDefault(); e.stopPropagation();
    $dropzone.removeClass('border-primary'); $globalDrop.hide();
  });
  $dropzone.on('drop', function(e){
    if (_isInternalDrag) return;
    if (!isFileDrag(e)) return;
    e.preventDefault(); e.stopPropagation();
    $dropzone.removeClass('border-primary'); $globalDrop.hide();
    const files = e.originalEvent.dataTransfer.files;
    if (files && files.length) handleFiles(files);
  });
  // Make whole document droppable: show overlay and accept files anywhere, but only for file drags
  $(document).on('dragenter dragover', function(e){ try{ if (_isInternalDrag) return; if (!isFileDrag(e)) return; e.preventDefault(); e.stopPropagation(); $globalDrop.show(); }catch(_e){} });
  $(document).on('dragleave', function(e){ try{ if (_isInternalDrag) return; if (!isFileDrag(e)) return; e.preventDefault(); e.stopPropagation(); $globalDrop.hide(); }catch(_e){} });
  $(document).on('drop', function(e){ try{ if (_isInternalDrag) return; if (!isFileDrag(e)) return; e.preventDefault(); e.stopPropagation(); $globalDrop.hide(); const dt = e.originalEvent.dataTransfer; if (dt){ const files = dt.files; if (files && files.length) handleFiles(files); } }catch(_e){} });
  $fileInput.on('change', function(e){ const files = e.target.files; if (files && files.length) handleFiles(files); });

  // Populate filter options (extensions and months) via AJAX
  (function initFilters(){
    adminPost({ action: 'qp_media_filters' })
      .done(function(resp){
        const j = resp;
        if (j && j.status === 'success'){
          // extensions
          const exts = Array.isArray(j.extensions) ? j.extensions : [];
          const $filter = $('#mediaFilter');
          // remove any existing dynamic ext options (keep 'all' and 'images')
          $filter.find('option.dynamic-ext').remove();
          exts.forEach(function(ext){
            // skip 'jpg/png' confusion: just add raw extension
            const val = ext; const label = ext.toUpperCase();
            $filter.append('<option class="dynamic-ext" value="'+val+'">'+label+'</option>');
          });
          // months
          const months = Array.isArray(j.months) ? j.months : [];
          const $month = $('#mediaMonth');
          $month.empty().append('<option value="all">All months</option>');
          months.forEach(function(m){ $month.append('<option value="'+m.value+'">'+m.label+'</option>'); });
        }
      });
  })();

  function handleFiles(fileList){
    const list = Array.from(fileList);
    let done = 0;
    $uploadStatus.text(`Uploading ${list.length} file(s)...`);
    $uploadError.hide().text('');
    // cumulative progress tracking
    const totalBytes = list.reduce((s,f)=> s + (f.size || 0), 0);
    let uploadedBytes = 0;
    const $progress = $('#uploadProgress');
    const $bar = $progress.find('.progress-bar');
    if (totalBytes > 0) { $progress.css({'opacity':1,'pointer-events':'auto'}); $bar.css('width','0%').attr('aria-valuenow',0); $progress.find('.progress-text').text('0%'); }
    function next(){
      if (!list.length){
        $uploadStatus.text('Upload complete. Processing new items...');
        // If we have newly uploaded IDs, fetch them in batch and prepend immediately
        if (newlyUploadedIds.length) {
          const idsCopy = newlyUploadedIds.slice();
          // Clear the pending list immediately to avoid duplicates if new uploads start
          newlyUploadedIds = [];
          adminPost({ action: 'qp_media_batch_get', ids: idsCopy.join(',') })
            .done(resp => {
              const j = parseJsonSafeAjax(resp);
              if (j && j.status === 'success' && Array.isArray(j.items)){
                prependNewItems(j.items);
              } else {
                showGridError(j.message || 'Failed to fetch new items');
              }
            })
            .fail(() => showGridError('Failed to fetch new items'))
            .always(() => { $uploadStatus.text('Upload complete.'); setTimeout(()=>{ $progress.css({'opacity':0,'pointer-events':'none'}); $bar.css('width','0%').attr('aria-valuenow',0); $progress.find('.progress-text').text('0%'); }, 600); });
        } else {
          $uploadStatus.text('Upload complete.');
          setTimeout(()=>{ $progress.css({'opacity':0,'pointer-events':'none'}); $bar.css('width','0%').attr('aria-valuenow',0); $progress.find('.progress-text').text('0%'); }, 400);
        }
        return;
      }
      const f = list.shift();
      uploadFile(f, function(deltaLoaded){
        // update cumulative uploaded bytes and progress bar
        uploadedBytes += deltaLoaded;
        const pct = totalBytes ? Math.round((uploadedBytes/totalBytes)*100) : 0;
        $bar.css('width', pct + '%').attr('aria-valuenow', pct);
      })
        .then((resp)=>{
          // collect newly uploaded id if present
          try { if (resp && resp.data && resp.data.post_id) newlyUploadedIds.push(resp.data.post_id); } catch(e){}
          done++; $uploadStatus.text(`Uploaded ${done} of ${fileList.length}`); next();
        })
        .catch(err => { done++; $uploadStatus.text(`Uploaded ${done} of ${fileList.length}`); $uploadError.text(err?.message || 'Upload failed').show(); next(); });
    }
    next();
  }

  // Upload a single file. onProgress(deltaLoaded) will be called with incremental loaded bytes for the file.
  function uploadFile(file, onProgress){
    const form = new FormData();
    form.append('file', file);
    form.append('action', 'qp_media_upload');
    return new Promise((resolve, reject) => {
      let prevLoaded = 0;
      const ajaxOpts = {
        xhr: function(){
          const xhr = new window.XMLHttpRequest();
          xhr.upload.addEventListener('progress', function(e){
            if (e.lengthComputable){
              const loaded = e.loaded || 0;
              const delta = Math.max(0, loaded - prevLoaded);
              prevLoaded = loaded;
              try { if (typeof onProgress === 'function') onProgress(delta, e.total); } catch(e){}
            }
          }, false);
          return xhr;
        }
      };
      adminPost(form, true, ajaxOpts)
        .done(function(resp){
          if (resp && resp.status === 'success') {
            resolve(resp);
          } else {
            const msg = (resp && (resp.message || resp.error)) || (resp && resp.data && resp.data.message) || 'Upload failed';
            reject(new Error(msg));
          }
        })
        .fail(function(xhr){
          const text = xhr && xhr.responseText ? xhr.responseText : 'Upload failed';
          reject(new Error(text));
        });
    });
  }

  // Reload grid from server (page 1) and replace current items without full page reload
  function reloadGrid(){
    // reset internal state
    currentPage = 1;
    reachedEnd = false;
    itemIndexMap.length = 0;
    itemSet.clear();
    $grid.empty();
    // show overlay
    const $overlay = $('#mediaOverlay');
    $overlay.css('display','flex');
    $('#loadMoreStatus').text('Refreshing...');
    // Temporarily disconnect observer to avoid overlapping loads while we
    // refresh the grid for new filters.
    try { if (ioObserver) { ioObserver.disconnect(); } } catch(e) {}
    const req = { action:'qp_media_list', p: currentPage, per_page: perPage };
    if (filterMode && filterMode !== 'all') req.file_mode = filterMode;
    if (monthFilter && monthFilter !== 'all') req.uploaded_month = monthFilter;
    adminPost(req)
      .done(resp => {
        const j = parseJsonSafeAjax(resp);
            if (j.status === 'success'){
              const items = j.items || [];
              renderItems(items);
              const total = j.total || 0;
              totalItems = total;
              // use centralized display update
              updateSelectionState();
              const totalLoaded = $('#media-grid .col').length;
              if (totalLoaded >= total || items.length === 0) {
            reachedEnd = true;
            $('#loadMore').prop('disabled', true).removeClass('btn-outline-primary').addClass('btn-outline-secondary').text('No more items');
            $('#loadMoreStatus').text('All items are loaded.');
          } else {
            currentPage = 2; // next page to load
            $('#loadMore').prop('disabled', false).text('Load more');
            $('#loadMoreStatus').text('');
          }
        } else {
          showGridError(j.message || 'Failed to refresh media.');
        }
      })
      .fail(xhr => { showGridError(xhr.responseText || 'Request failed'); })
      .always(() => {
        $overlay.hide();
        // Recreate and attach IntersectionObserver so it uses the refreshed
        // state (currentPage, filters, etc.). This avoids previously
        // disconnected observers remaining inactive.
        try {
          if ('IntersectionObserver' in window) {
            const sentinelEl = document.getElementById('infiniteSentinel');
            if (sentinelEl) {
              try { ioObserver = new IntersectionObserver((entries) => { entries.forEach(entry => { if (entry.isIntersecting) { loadPage(); } }); }, { root: null, rootMargin: '200px', threshold: 0 }); ioObserver.observe(sentinelEl); } catch(e) { /* ignore */ }
            }
          }
        } catch(e) {}
      });
  }

  // banner feature removed — uploads auto-prepend new items directly

  // Prepend new items (array of post objects) while preserving scroll position
  function prependNewItems(items){
    if (!items || !items.length) return;
    // increase total count
    totalItems += items.length;
    const oldScroll = $(window).scrollTop();
    // render columns into a temporary container to measure height
    const $tmp = $('<div style="position:relative"></div>');
    items.forEach(it => {
      if (itemSet.has(it.id)) return;
      const col = $("<div class='col pb-3'></div>");
      const $card = $("<div class='card h-100' data-id='"+it.id+"'></div>");
      $card.append(`<div class="position-absolute" style="z-index:2;"><input type="checkbox" class="form-check-input m-2 select-box d-none" data-id="${it.id}" /></div>`);
      const body = $(`<div class="card-body p-2 d-flex align-items-center justify-content-center" style="min-height:150px;"></div>`);
      if (it.thumb) body.append(`<img src="${it.thumb}" class="img-fluid" style="max-height:140px;" alt="">`);
      else body.append('<span class="text-muted">No preview</span>');
      $card.append(body);
      col.append($card);
      $tmp.append(col);
    });
    // Insert at top of grid
    const $cols = $tmp.children();
    $grid.prepend($cols);
    // add highlight class to newly inserted cards then remove after animation
    $cols.find('.card').addClass('newly-added');
    setTimeout(() => { $cols.find('.card.newly-added').removeClass('newly-added'); }, HIGHLIGHT_MS);
    // update internal maps
    $cols.each(function(){ const id = $(this).find('.card').data('id'); if (id) { itemIndexMap.unshift(id); itemSet.add(id); } });
    // measure added height and adjust scroll to keep viewport stable
    const addedHeight = $cols.toArray().reduce((acc, el)=> acc + $(el).outerHeight(true), 0);
    $(window).scrollTop(oldScroll + addedHeight);
    updateSelectionState();
    // update top-right count centrally
    updateSelectionState();
  }

  // Fetch items by IDs with retry logic, returns a jQuery promise
  function fetchAndPrepend(ids, attempts){
    attempts = attempts || 3;
    const d = $.Deferred();
    (function attempt(tries, delay){
      adminPost({ action: 'qp_media_batch_get', ids: ids.join(',') })
        .done(resp => {
          const j = parseJsonSafeAjax(resp);
          if (j && j.status === 'success' && Array.isArray(j.items)){
            prependNewItems(j.items);
            d.resolve(j.items);
          } else {
            if (tries > 1) setTimeout(()=> attempt(tries-1, delay*2 || 300), delay || 300);
            else d.reject(j);
          }
        })
        .fail(() => {
          if (tries > 1) setTimeout(()=> attempt(tries-1, delay*2 || 300), delay || 300);
          else d.reject();
        });
    })(attempts, 300);
    return d.promise();
  }

  // Keyboard navigation in modal
  $(document).on('keydown', function(e){
    const $modal = $('#mediaModal');
    if (!$modal.hasClass('show')) return;
    const currentId = parseInt($('#mdId').val() || '0', 10);
    const idx = itemIndexMap.indexOf(currentId);
    if (e.key === 'ArrowRight' && idx >= 0 && idx < itemIndexMap.length - 1){ openById(itemIndexMap[idx+1]); }
    else if (e.key === 'ArrowLeft' && idx > 0){ openById(itemIndexMap[idx-1]); }
  });

  // Initial load
  loadPage();
});
</script>
<?php include __DIR__ . '/inc/footer.php'; ?>
