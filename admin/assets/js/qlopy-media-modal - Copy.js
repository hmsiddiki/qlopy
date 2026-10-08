/* qlopy-media-modal.js
   Reusable media modal and featured-image UI for Qlopy admin screens.
   Expects `window.qlopyAdminAjax` to be set to the admin ajax URL.
*/
(function($){
  if (!$) return;
  function buildMediaModal(){
    var $modal = $(
      '<div class="qpmedia-modal" style="position:fixed;left:0;top:0;right:0;bottom:0;z-index:2000;display:flex;align-items:center;justify-content:center;">'
      + '<div class="qpmedia-backdrop" style="position:absolute;left:0;top:0;right:0;bottom:0;background:rgba(0,0,0,0.5);"></div>'
      + '<div class="qpmedia-panel" style="position:relative;z-index:2001;background:#fff;width:900px;max-width:95%;height:80%;border-radius:6px;overflow:hidden;display:flex;flex-direction:column;">'
      + '<div style="padding:10px;border-bottom:1px solid #eee;display:flex;align-items:center;justify-content:space-between;">'
      + '<strong>Select Media</strong>'
      + '<div style="display:flex;gap:8px;align-items:center;>'
      + '<button type="button" class="btn btn-sm btn-secondary qpmedia-close">Close</button>'
      + '</div>'
      + '</div>'
      + '<div style="display:flex;flex:1;overflow:hidden;">'
      + '<div style="width:240px;border-right:1px solid #eee;padding:10px;box-sizing:border-box;">'
      + '<div style="margin-bottom:8px;"><button class="btn btn-sm btn-primary qpmedia-tab-library">Library</button> <button class="btn btn-sm btn-outline-secondary qpmedia-tab-upload">Upload</button></div>'
      + '<div class="qpmedia-upload-area" style="display:none;">'
      + '<div class="qpmedia-upload-dropbox" style="border:2px dashed #bbb;border-radius:6px;padding:18px;text-align:center;cursor:pointer;">'
      + 'Click or drop files here to upload<br><small class="text-muted qpmedia-allowed">Allowed: images (.jpg .jpeg .png .gif .webp)</small>'
      + '</div>'
      + '<input type="file" class="qpmedia-upload-input" multiple style="display:none;">'
      + '<div class="qpmedia-upload-status small text-muted mt-2" style="margin-top:8px;"></div>'
      + '<div class="qpmedia-upload-list" style="margin-top:8px;"></div>'
      + '</div>'
      + '<div class="qpmedia-library-controls" style="margin-top:12px;"><small class="text-muted">Click an image to select it.</small></div>'
      + '</div>'
      + '<div class="qpmedia-content" style="flex:1;overflow:auto;padding:12px;position:relative;">'
      + '<div class="qpmedia-drop-overlay" style="position:absolute;left:0;top:0;right:0;bottom:0;display:none;align-items:center;justify-content:center;background:rgba(255,255,255,0.9);z-index:5;">'
      + '<div style="border:2px dashed #999;padding:30px;border-radius:8px;text-align:center;">Drop files here to upload</div>'
      + '</div>'
      + '<div class="qpmedia-grid" style="display:flex;flex-wrap:wrap;gap:10px;position:relative;z-index:1;"></div>'
      + '<div class="qpmedia-pagination text-center mt-2" style="display:none;"></div>'
      + '</div>'
      + '</div>'
      + '<div style="padding:10px;border-top:1px solid #eee;text-align:right;">'
      + '<button type="button" class="btn btn-sm btn-primary qpmedia-insert-btn">Insert Image</button>'
      + '</div>'
      + '</div>'
      + '</div>');
    return $modal;
  }

  function openMediaModal(arg1, arg2){
    // Support legacy signature: openMediaModal($triggerField, callback)
    var adminAjax = window.qlopyAdminAjax || '/admin/ajax.php';
    var opts = {};
    if (arg1 && arg1.jquery) {
      opts.trigger = arg1;
      if (typeof arg2 === 'function') opts.onInsert = arg2;
      else if (typeof arg2 === 'object') opts = Object.assign(opts, arg2);
    } else if (typeof arg1 === 'object') {
      opts = arg1;
    } else if (typeof arg1 === 'function') {
      opts.onInsert = arg1;
    }

    opts.multiple = !!opts.multiple;
    // fileMode: 'images' | 'all' — controls allowed upload extensions and library filter
    // default to 'all' so non-image files (pdf/doc) appear unless caller requests images-only
    opts.fileMode = opts.fileMode || 'all';
    opts.preselect = Array.isArray(opts.preselect) ? opts.preselect : (opts.preselect ? [opts.preselect] : []);

    var $m = buildMediaModal();
    var $grid = $m.find('.qpmedia-grid');
    var $uploadArea = $m.find('.qpmedia-upload-area');
    var $uploadInput = $m.find('.qpmedia-upload-input');
    var $uploadStatus = $m.find('.qpmedia-upload-status');
    var $uploadList = $m.find('.qpmedia-upload-list');
    var $dropOverlay = $m.find('.qpmedia-drop-overlay');
    var $insertBtn = $m.find('.qpmedia-insert-btn');
    var per = 24;
    // progress UI for uploads
    var $uploadProgress = $('<div class="qpmedia-upload-progress" style="display:none;margin-top:8px;"><div class="progress"><div class="progress-bar" role="progressbar" style="width:0%"></div></div></div>');
    $uploadArea.append($uploadProgress);
    // loading spinner overlay for library reload
    var $gridSpinner = $('<div class="qpmedia-loading" style="position:absolute;left:0;top:0;right:0;bottom:0;display:none;align-items:center;justify-content:center;background:rgba(255,255,255,0.85);z-index:6;"><div class="spinner-border" role="status"><span class="sr-only">Loading...</span></div></div>');
    $m.find('.qpmedia-content').append($gridSpinner);
    // debug panel removed
    // disable insert until selection exists
    $insertBtn.prop('disabled', true);

    var selected = [];

    function updateInsertState(){
      var any = getSelectedItems().length > 0;
      $insertBtn.prop('disabled', !any);
    }

    function setSelected(ids){ selected = ids.map(function(v){ return String(v); }); $grid.find('.qpmedia-item').removeClass('selected').find('.qpmedia-select-badge').hide(); selected.forEach(function(id){ var $it = $grid.find('.qpmedia-item[data-id="'+id+'"]').addClass('selected'); $it.find('.qpmedia-select-badge').show(); }); updateInsertState(); }

    function getSelectedItems(){
      var out = [];
      $grid.find('.qpmedia-item.selected').each(function(){
        var $it = $(this);
        out.push({ id: $it.data('id'), url: $it.data('url') || $it.find('img').attr('src') });
      });
      return out;
    }

    function loadLibrary(p, highlightIds){
      $grid.empty(); $m.find('.qpmedia-pagination').hide(); $gridSpinner.show();
      $.post(adminAjax, { action: 'qp_media_list', p: p, per_page: per }, function(resp){
        if (!resp || resp.status !== 'success') { $grid.html('<div class="text-danger">Failed to load media</div>'); $gridSpinner.hide(); return; }
        // optionally filter returned items client-side when requesting images only
        var items = resp.items || [];
        if (opts.fileMode === 'images') {
          items = items.filter(function(it){
            var url = (it.url||it.thumb||'').split('?')[0];
            var ext = (url.split('.').pop()||'').toLowerCase();
            return ['jpg','jpeg','png','gif','webp','bmp','svg'].indexOf(ext) !== -1;
          });
        }

        items.forEach(function(it){
          // prefer server-provided thumbnail; if missing, derive extension from server data or URL
          var thumb = it.thumb || '';
          if (!thumb) {
            var ext = (it.extension || '').toLowerCase();
            if (!ext && it.url) {
              try { ext = (it.url.split('?')[0].split('.').pop()||'').toLowerCase(); } catch(e) { ext = ''; }
            }
            var imageExts = ['jpg','jpeg','png','gif','webp','bmp','svg'];
            // if extension is an image type and url exists, use the URL as the thumbnail
            if (ext && imageExts.indexOf(ext) !== -1 && it.url) {
              thumb = it.url;
            } else if (ext) {
              // non-image: generate SVG placeholder with extension label
              try {
                var label = (ext || 'file').toUpperCase();
                var svg = '<svg xmlns="http://www.w3.org/2000/svg" width="120" height="90">'
                        + '<rect width="100%" height="100%" fill="#f3f4f6"/>'
                        + '<text x="50%" y="50%" dominant-baseline="middle" text-anchor="middle"'
                        + ' font-family="Arial, Helvetica, sans-serif" font-size="16" fill="#374151">' + label + '</text>'
                        + '</svg>';
                thumb = 'data:image/svg+xml;utf8,' + encodeURIComponent(svg);
              } catch(e){ thumb = it.url || ''; }
            } else {
              // no extension info at all — fall back to url (may be previewable) or empty
              thumb = it.url || '';
            }
          }
          var $item = $('<div class="qpmedia-item" data-id="'+it.id+'" data-url="'+(it.url||'')+'" style="width:120px;text-align:center;cursor:pointer;position:relative;">');
          var $img = $('<img>').attr('src', thumb).css({width:'120px',height:'90px',objectFit:'cover',border:'1px solid #ddd'});
          var $badge = $('<div class="qpmedia-select-badge" style="position:absolute;left:6px;top:6px;width:26px;height:26px;border-radius:50%;background:#3b82f6;color:#fff;display:none;align-items:center;justify-content:center;font-weight:700;z-index:3;">✓</div>');
          var $label = $('<div style="font-size:12px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">'+(it.title||'')+'</div>');
          $item.append($badge).append($img).append($label);
          $item.on('click', function(e){
            var id = String(it.id);
            if (opts.multiple) {
              if ($item.hasClass('selected')) { $item.removeClass('selected'); $item.find('.qpmedia-select-badge').hide(); selected = selected.filter(function(x){ return x !== id; }); }
              else { if (selected.indexOf(id) === -1) { $item.addClass('selected'); $item.find('.qpmedia-select-badge').show(); selected.push(id); } }
            } else {
              $grid.find('.qpmedia-item').removeClass('selected').find('.qpmedia-select-badge').hide(); $item.addClass('selected'); $item.find('.qpmedia-select-badge').show(); selected = [id];
              // in single mode, optionally close immediately? we'll wait for Insert click per new behavior
            }
            updateInsertState();
          });
          $grid.append($item);
        });
        if (resp.total && resp.total > per) {
          var pages = Math.ceil(resp.total / per);
          var $pag = $m.find('.qpmedia-pagination').empty().show();
          for (var i=1;i<=pages;i++) {
            var $pbtn = $('<button class="btn btn-sm btn-outline-secondary mr-1" style="margin:2px;">'+i+'</button>');
            (function(pi){ $pbtn.on('click', function(){ loadLibrary(pi); }); })(i);
            $pag.append($pbtn);
          }
        }
        if (opts.preselect && opts.preselect.length) setSelected(opts.preselect);
        if (Array.isArray(highlightIds) && highlightIds.length) {
          // auto-select uploaded items by default
          setSelected(highlightIds.map(function(x){ return String(x); }));
        }
        $gridSpinner.hide();
        if (Array.isArray(highlightIds) && highlightIds.length) {
          // scroll to first highlighted
          var hid = String(highlightIds[0]);
          var $h = $grid.find('.qpmedia-item[data-id="'+hid+'"]'); if ($h.length) { $h.css('outline','3px solid #3b82f6'); setTimeout(function(){ $h.css('outline',''); }, 2000); }
        }
      }, 'json').fail(function(){ $grid.html('<div class="text-danger">Server error</div>'); $gridSpinner.hide(); });
    }

    // tab handlers
    $m.find('.qpmedia-tab-library').on('click', function(){ $uploadArea.hide(); $dropOverlay.hide(); loadLibrary(1); });
    $m.find('.qpmedia-tab-upload').on('click', function(){ $uploadArea.show(); loadLibrary(1); });
    $m.find('.qpmedia-close').on('click', function(){ $m.remove(); });

    // Drop area on entire modal content. Use drag counter to avoid flicker when entering children.
    var dragCounter = 0;
    $m.on('dragenter', function(e){ e.preventDefault(); dragCounter++; $dropOverlay.show(); });
    $m.on('dragover', function(e){ e.preventDefault(); });
    $m.on('dragleave', function(e){ e.preventDefault(); dragCounter--; if (dragCounter <= 0) { dragCounter = 0; $dropOverlay.hide(); } });
    $m.on('drop', function(e){
      e.preventDefault(); dragCounter = 0; $dropOverlay.hide(); var dt = e.originalEvent.dataTransfer; if (!dt) return; var files = dt.files; if (!files || !files.length) return; handleUploadFiles(files); });

    // If drag ends (cancel), hide overlay
    $(document).on('dragend.qlopymedia', function(){ dragCounter = 0; $dropOverlay.hide(); });

    $uploadInput.on('change', function(){ var files = this.files; if (!files || !files.length) return; handleUploadFiles(files); });
    // make the visual dropbox clickable to open file picker
    $m.find('.qpmedia-upload-dropbox').on('click', function(){ $uploadInput.trigger('click'); });

    // adjust allowed types and UI text based on fileMode
    var allowedText = 'Allowed: any file type';
    if (opts.fileMode === 'images') {
      $uploadInput.attr('accept','image/*');
      allowedText = 'Allowed: images (.jpg .jpeg .png .gif .webp .svg)';
      $m.find('.qpmedia-allowed').text(allowedText);
    } else {
      $uploadInput.removeAttr('accept');
      // Fetch allowed extensions from server and display them
      $m.find('.qpmedia-allowed').text('Loading allowed types...');
      $.post(adminAjax, { action: 'qp_media_allowed_mimes' }, function(resp){
        if (resp && resp.status === 'success' && Array.isArray(resp.extensions) && resp.extensions.length) {
          var list = resp.extensions.map(function(e){ return '.' + e; }).join(' ');
          $m.find('.qpmedia-allowed').text('Allowed: ' + list);
        } else {
          $m.find('.qpmedia-allowed').text('Allowed: any file type');
        }
      }, 'json').fail(function(){ $m.find('.qpmedia-allowed').text('Allowed: any file type'); });
    }

    function handleUploadFiles(files){
      $uploadStatus.text('Uploading...');
      $uploadProgress.show();
      $uploadProgress.find('.progress-bar').css('width','0%');
      $uploadList.empty();
      var uploadedIds = [];
      var total = files.length, done = 0;
      for (var i=0;i<files.length;i++) {
        (function(f, idx){
          var rowId = 'qpuploadrow-' + Date.now() + '-' + idx;
          var $row = $('<div class="qpmedia-upload-row" id="'+rowId+'" style="display:flex;align-items:center;justify-content:space-between;padding:6px 8px;border:1px solid #eee;border-radius:4px;margin-bottom:6px;"></div>');
          var $left = $('<div style="display:flex;align-items:center;gap:10px;"></div>');
          var $name = $('<div style="font-size:13px;">'+(f.name||'file')+'</div>');
          var $statusIcon = $('<div class="text-muted" style="width:80px;text-align:right;"><span class="spinner-border spinner-border-sm" role="status"></span></div>');
          $left.append($name);
          $row.append($left).append($statusIcon);
          $uploadList.append($row);

          // client-side validation of extension when images only
            if (opts.fileMode === 'images') {
            var ext = (f.name || '').split('.').pop().toLowerCase();
            if (['jpg','jpeg','png','gif','webp','bmp','svg'].indexOf(ext) === -1) {
              $statusIcon.html('<span style="color:#d9534f;">Invalid type</span>');
              done++; var pct = Math.round((done/total)*100); $uploadProgress.find('.progress-bar').css('width', pct+'%');
              // continue to next file
              if (done >= total) {
                $uploadStatus.text('Upload complete');
                setTimeout(function(){ $uploadStatus.text(''); }, 1500);
                // switch to library view and highlight uploaded IDs (dedup)
                $uploadArea.hide(); $dropOverlay.hide();
                uploadedIds = Array.from(new Set(uploadedIds.map(String))).map(String);
                loadLibrary(1, uploadedIds);
                setTimeout(function(){ $uploadProgress.hide(); $uploadProgress.find('.progress-bar').css('width','0%'); }, 800);
              }
              return;
            }
          }

          var fd = new FormData(); fd.append('action','qp_media_upload'); fd.append('file', f);
          $.ajax({ url: adminAjax, method: 'POST', data: fd, processData: false, contentType: false, dataType: 'text' }).done(function(text){
            var resp = null; try { resp = JSON.parse(text); } catch(e) { resp = null; }
            if (resp && (resp.status === 'success' || resp.success === true)) {
              var data = resp.data || resp; var id = data.post_id || null; if (id) { if (uploadedIds.indexOf(String(id)) === -1) uploadedIds.push(String(id)); $statusIcon.html('<span style="color:#28a745;">OK</span>'); }
              else { $statusIcon.html('<span style="color:#d9534f;">No ID</span>'); }
            } else {
              $statusIcon.html('<span style="color:#d9534f;">Failed</span>');
            }
          }).fail(function(){ $statusIcon.html('<span style="color:#d9534f;">Error</span>'); }).always(function(){
            done++; var pct = Math.round((done/total)*100); $uploadProgress.find('.progress-bar').css('width', pct+'%');
            if (done >= total) {
              $uploadStatus.text('Upload complete');
              setTimeout(function(){ $uploadStatus.text(''); }, 1500);
              // auto-select uploaded items and switch to library (avoid duplicate loads)
              $uploadArea.hide(); $dropOverlay.hide();
              uploadedIds = Array.from(new Set(uploadedIds.map(String))).map(String);
              loadLibrary(1, uploadedIds);
              // hide progress after small delay
              setTimeout(function(){ $uploadProgress.hide(); $uploadProgress.find('.progress-bar').css('width','0%'); }, 800);
            }
          });
        })(files[i], i);
      }
    }

    // Insert button behavior
    $insertBtn.on('click', function(){
      var items = getSelectedItems();
      if (!items.length) { alert('No image selected'); return; }
      if (typeof opts.onInsert === 'function') {
        if (opts.multiple) opts.onInsert(items);
        else opts.onInsert(items[0]);
      }
      $m.remove();
    });

    // initial load
    if (opts.preselect && opts.preselect.length) setSelected(opts.preselect);
    $m.find('.qpmedia-tab-library').trigger('click');
    $('body').append($m);
    return $m;
  }

  // Public init function for converting the qpmeta featured_image field
  function initFeaturedImageBoxes(){
    $('.qpmeta-field-wrap[data-field-name="featured_image"]').each(function(){
      var $wrap = $(this);
      // hide raw file input control
      $wrap.find('.qpmeta-file-input').hide();
      var $hidden = $wrap.find('.qpmeta-file-id');
      var $preview = $wrap.find('.qpmeta-file-preview');
      var currentId = $hidden.val() || '';
      // Build featured area
      var $box = $('<div class="featured-image-box" style="border:1px dashed #ddd;padding:10px;text-align:center;">');
      var $img = $('<img class="featured-image-preview" src="" style="max-width:100%;max-height:200px;display:block;margin:0 auto 8px;"/>');
      var $setBtn = $('<button type="button" class="btn btn-sm btn-primary">Set featured image</button>');
      var $removeBtn = $('<button type="button" class="btn btn-sm btn-outline-secondary" style="margin-left:8px;">Remove featured image</button>');
      $box.append($img).append($('<div>').append($setBtn).append($removeBtn));
      // Replace preview area with our box
      $preview.empty().append($box);

      var objectType = $wrap.data('object-type') || 'post';
      var objectId = $wrap.data('object-id') || 0;

      function refreshPreviewById(id){
        if (!id || id === '' || id === '0') { $img.hide().attr('src',''); $removeBtn.hide(); return; }
        var adminAjax = window.qlopyAdminAjax || '/admin/ajax.php';
        $.post(adminAjax, { action: 'qp_media_batch_get', ids: String(id) }, function(resp){
          if (resp && resp.status === 'success' && resp.items && resp.items.length) {
            var item = resp.items[0];
            var thumb = item.thumb || item.url || '';
            if (thumb) { $img.attr('src', thumb).show(); $removeBtn.show(); } else { $img.hide(); $removeBtn.show(); }
          }
        }, 'json');
      }

      // initial preview
      if (currentId) refreshPreviewById(currentId);

      var $status = $('<div class="qpmeta-save-status small text-muted" style="margin-top:6px;"></div>');
      $box.append($status);

      function saveFieldValue(val, cb){
        var adminAjax = window.qlopyAdminAjax || '/admin/ajax.php';
        $status.text('Saving...');
        $.post(adminAjax, { action: 'qpmeta_save_field_value', object_type: objectType, object_id: objectId, field_name: 'featured_image', field_value: String(val) }, function(r){
          if (r && (r.status === 'success' || r.success === true)) { $status.text('Saved'); if (cb) cb(true); setTimeout(function(){ $status.text(''); }, 1500); }
          else { $status.text('Save failed'); if (cb) cb(false); }
        }, 'json').fail(function(){ $status.text('Save error'); if (cb) cb(false); });
      }

      $setBtn.on('click', function(){
        var pre = $hidden.val() ? [$hidden.val()] : [];
        openMediaModal({ multiple: false, fileMode: 'images', preselect: pre, onInsert: function(item){
          var id = null;
          if (!item) return;
          if (Array.isArray(item)) id = item.length ? item[0].id : null;
          else id = item.id || item;
          if (id) { $hidden.val(id); refreshPreviewById(id); saveFieldValue(id); }
        } });
      });

      $removeBtn.on('click', function(){ if (!confirm('Remove featured image?')) return; $hidden.val(''); $img.attr('src','').hide(); $removeBtn.hide(); saveFieldValue(''); });
      if (!currentId) $removeBtn.hide();
    });
  }

  // Auto-init on document ready
  jQuery(function(){ initFeaturedImageBoxes(); });

  // Export for manual init if needed
  window.qlopyMedia = window.qlopyMedia || {};
  window.qlopyMedia.openModal = openMediaModal;
  window.qlopyMedia.initFeaturedImageBoxes = initFeaturedImageBoxes;

})(jQuery);
