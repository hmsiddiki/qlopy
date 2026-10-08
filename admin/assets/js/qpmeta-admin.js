/* Centralized QPMeta admin JS (extracted from includes/qpmeta.php)
   - Preserves original behavior but prefers `window.ajaxurl` when available
   - Handles repeatables, conditionals, ajax submission, file upload/delete preview
*/
(function($){
    // Ensure this file only initializes once
    if (window.__qpmeta_admin_initialized) return;
    window.__qpmeta_admin_initialized = true;

    jQuery(function($){
        // Use the global `window.ajaxurl` exclusively (no fallback)
        function getAdminAjax(){
            return window.ajaxurl;
        }

        // Repeatable add
        $(document).on('click', '.qpmeta-repeat-add', function(e) {
            var group = $(this).closest('.qpmeta-repeat-group');
            var proto = group.find('.qpmeta-repeatable-prototype').first();
            var clone = proto.clone();
            clone.removeClass('qpmeta-repeatable-prototype').show();

            // Destroy any TinyMCE instances in prototype before cloning
            clone.find('textarea.qpmeta-richtext').each(function(){
                var oldId = $(this).attr('id');
                if (oldId && window.tinymce && tinymce.get(oldId)) {
                    tinymce.get(oldId).remove();
                }
            });

            // Clear values and generate unique IDs for cloned fields
            clone.find('input,textarea,select').each(function(){
                $(this).val('');
                if($(this).hasClass('qpmeta-richtext')){
                    var newId = 'qpmeta_' + Math.random().toString(36).substr(2, 9);
                    $(this).attr('id', newId);
                }
            });
            // Ensure panel body is collapsed for new items
            clone.find('.qpmeta-panel-body').hide();
            // Update header title from first text input if present and enforce collapse
            try { updateRepeatHeaders(group, true); } catch(e) {}
            $(this).before(clone);
            // Re-init sortable on this group to include new item
            try { group.sortable({ items: '> .qpmeta-repeat-item:not(.qpmeta-repeatable-prototype)', handle: '.qpmeta-panel-handle', update: function(){ updateRepeatHeaders(group, false); } }); } catch(e) {}
            $(document).trigger('qpmeta-field-added', [clone]);
            e.preventDefault();
        });

        // Repeatable remove
        $(document).on('click', '.qpmeta-repeat-remove', function(e) {
            var group = $(this).closest('.qpmeta-repeat-group');
            if (group.find('.qpmeta-repeat-item:not(.qpmeta-repeatable-prototype)').length > 1) {
                $(this).closest('.qpmeta-repeat-item').remove();
                try { updateRepeatHeaders(group, true); } catch(e) {}
            }
            e.preventDefault();
        });

        // Conditional fields
        $('[data-qpmeta-conditional]').each(function() {
            var wrap = $(this);
            var cond = JSON.parse(wrap.attr('data-qpmeta-conditional'));
            var fieldName = cond.field;
            // try direct name, and array-style name (name[])
            var trigger = $('[name="' + fieldName + '"]');
            if (!trigger.length) trigger = $('[name="' + fieldName + '[]"]');

            // Fallback: find inputs within element that declares the field by data-field-name
            if (!trigger.length) trigger = $('[data-field-name="' + fieldName + '"]').find('input,select,textarea');

            function getTriggerValue() {
                if (!trigger || trigger.length === 0) return '';
                // If group (radio/checkbox), prefer checked item
                var checked = trigger.filter(':checked');
                if (checked.length) return checked.val();
                // Otherwise use first element's value or checkbox state
                var first = trigger.eq(0);
                if (!first || !first.length) return '';
                if (first.is(':checkbox')) return first.is(':checked') ? first.val() : '';
                return first.val();
            }

            function check() {
                var val = getTriggerValue();
                if (String(val) === String(cond.value)) {
                    wrap.show();
                } else {
                    wrap.hide();
                }
            }

            if (trigger.length) {
                // listen to change and input for broader coverage
                trigger.on('change input', check);
                // initial check
                check();
            }
        });

        // AJAX form submission
        $(document).on('submit', '.qpmeta-metabox-form[data-ajax-url], .qpmeta-metabox-form', function(e) {
            e.preventDefault();
            var form = $(this);
            var msg = form.find('.qpmeta-msg');
            if (msg.length === 0) { msg = $('<span class="qpmeta-msg" style="margin-left:8px;font-size:0.9em;"></span>'); form.append(msg); }
            msg.text('Saving...').css('color', '#666');

            // Disable prototype inputs immediately before building FormData
            try { form.find('.qpmeta-repeatable-prototype').find('input,textarea,select').prop('disabled', true); } catch(e) {}

            var fd = new FormData(this);
            fd.append('object_id', form.data('object-id'));
            fd.append('object_type', form.data('object-type'));
            fd.append('action', 'qpmeta_save');

            var ajaxUrl = getAdminAjax(form);

            $.ajax({
                url: ajaxUrl,
                type: 'POST',
                data: fd,
                processData: false,
                contentType: false,
                dataType: 'json'
            }).done(function(data){
                if(data && (data.success || data.status == 'success')) {
                    msg.css('color', 'green').text('Saved!');
                    setTimeout(function(){ location.reload(); }, 1000);
                } else {
                    var err = (data && (data.error || data.message)) || 'Unknown';
                    msg.css('color', 'red').text('Error: ' + err);
                }
            }).fail(function() {
                msg.css('color', 'red').text('AJAX error');
            });
        });

        // File upload change
        $(document).on('change', '.qpmeta-file-input', function(e){
            var $input = $(this);
            var files = this.files;
            if (!files || files.length === 0) { return; }
            var $fieldset = $input.closest('.qpmeta-metabox');
            var objectType = $fieldset.data('object-type');
            var objectId = parseInt($fieldset.data('object-id') || '0', 10);
            var isMultiple = $input.prop('multiple');
            var $previewWrap = $input.closest('.qpmeta-field-wrap').find('.qpmeta-file-preview');
            var $hiddenId = $input.closest('.qpmeta-field-wrap').find('.qpmeta-file-id');
            var $msg = $fieldset.find('.qpmeta-msg');
            if ($msg.length === 0) { $msg = $('<span class="qpmeta-msg" style="margin-left:8px;font-size:0.9em;"></span>'); $input.after($msg); }
            $msg.text('Uploading...').css('color', '#666');
            var uploadedIds = [];
            if (isMultiple) {
                var existingVal = $hiddenId.val();
                if (existingVal) { try { uploadedIds = JSON.parse(existingVal); if (!Array.isArray(uploadedIds)) uploadedIds = []; } catch(e){ uploadedIds = []; } }
            }
            var fieldId = $input.data('storage-id') || $input.attr('name');
            var ajaxUrl = getAdminAjax($fieldset);

            if (!isMultiple) {
                var prevId = $hiddenId.val();
                if (prevId) {
                    $msg.text('Deleting previous image...').css('color', '#666');
                    $.ajax({ url: ajaxUrl, type: 'POST', dataType: 'json', data: { action: 'qp_media_delete', post_id: prevId } })
                    .done(function(resp){ $hiddenId.val(''); $previewWrap.empty(); $msg.text('Uploading...').css('color', '#666'); uploadNext(0); })
                    .fail(function(){ $msg.text('Delete error, uploading new image...').css('color', 'red'); uploadNext(0); });
                    return;
                }
            }

            function handleUpload(file, doneCb) {
                var fd = new FormData(); fd.append('action', 'qp_media_upload'); fd.append('file', file);
                if (objectId > 0 && objectType) { fd.append('parent_id', objectId); fd.append('parent_type', objectType); if (fieldId) fd.append('field_id', fieldId); fd.append('is_multiple', isMultiple ? '1' : '0'); }
                $.ajax({ url: ajaxUrl, type: 'POST', data: fd, processData: false, contentType: false, dataType: 'text' })
                .done(function(text){ var resp = null; try { resp = JSON.parse(text); } catch(e) { resp = null; } var ok = false; var pid = null; var url = '';
                    if (resp && typeof resp === 'object') {
                        if (resp.status === 'success' && resp.data && resp.data.post_id) { ok = true; pid = resp.data.post_id; url = (resp.data.thumb || resp.data.url) || ''; }
                        else if (resp.success === true && resp.data && resp.data.post_id) { ok = true; pid = resp.data.post_id; url = (resp.data.thumb || resp.data.url) || ''; }
                        else if (resp.data && resp.data.post_id) { ok = true; pid = resp.data.post_id; url = (resp.data.thumb || resp.data.url) || ''; }
                    }
                    if (!ok) { var m = text && String(text).match(/"post_id"\s*:\s*(\d+)/); if (m && m[1]) { ok = true; pid = parseInt(m[1],10); } var mu = text && String(text).match(/"(thumb|url)"\s*:\s*"([^"]+)"/); if (mu && mu[2]) { url = mu[2]; } }
                    if (ok && pid) {
                        uploadedIds.push(pid);
                        if (url) {
                            var item = $('<div>').addClass('qpmeta-file-preview-item').attr('data-attach-id', pid).css({position:'relative',display:'inline-block',marginRight:'8px'});
                            // set data-file-name from URL when available
                            try { var fname = decodeURIComponent((url.split('/').pop()||'').split('?')[0]); item.attr('data-file-name', fname); } catch(e) {}
                            var img = $('<img>').attr('src', url).css({maxWidth:'150px',maxHeight:'150px',border:'1px solid #ddd',padding:'4px'});
                            var btn = $('<button>').attr({type:'button',title:'Remove'}).addClass('qpmeta-file-remove').css({position:'absolute',top:'2px',right:'2px',border:'none',background:'#000',color:'#fff',width:'22px',height:'22px',lineHeight:'22px',textAlign:'center',borderRadius:'50%',opacity:0.8}).html('&times;');
                            item.append(img).append(btn);
                            $previewWrap.append(item);
                        }
                        $msg.css('color','green').text('Uploaded');
                    } else {
                        $msg.css('color','red').text('Upload failed: ' + (resp && (resp.message || resp.error) || text || 'Unknown'));
                    }
                    // refresh indexes and make sortable
                    try { makePreviewSortable($previewWrap); } catch(e) {}
                    try { updateHiddenOrder($previewWrap); } catch(e) {}
                    doneCb();
                }).fail(function(){ $msg.css('color','red').text('Upload error'); doneCb(); });
            }

            if (!isMultiple) $previewWrap.empty();
            function uploadNext(idx) { if (idx >= files.length) { $hiddenId.val(isMultiple ? JSON.stringify(uploadedIds) : (uploadedIds[0] || '')); try { $input.val(''); $input.get(0).value = ''; } catch(e){} return; } handleUpload(files[idx], function(){ uploadNext(idx+1); }); }
            uploadNext(0);
        });

        // Remove uploaded file
        $(document).on('click', '.qpmeta-file-remove', function(e){
            e.preventDefault();
            var $btn = $(this);
            var $previewItem = $btn.closest('.qpmeta-file-preview-item');
            var $wrap = $btn.closest('.qpmeta-field-wrap');
            var $fieldset = $btn.closest('.qpmeta-metabox');
            var ajaxUrl = getAdminAjax($fieldset);
            var $msg = $fieldset.find('.qpmeta-msg'); if ($msg.length === 0) { $msg = $('<span class="qpmeta-msg" style="margin-left:8px;font-size:0.9em;"></span>'); $wrap.append($msg); }

            var fieldName = $wrap.attr('data-field-name');
            var objectType = $fieldset.attr('data-object-type');
            var objectId = parseInt($fieldset.attr('data-object-id') || '0', 10);
            var attachmentId = parseInt($previewItem.attr('data-attach-id') || '0', 10);
            var isMultiple = $previewItem.siblings('.qpmeta-file-preview-item').length > 0 || $previewItem.parent().find('.qpmeta-file-preview-item').length > 1;

            if (!attachmentId || !ajaxUrl || !fieldName) { $previewItem.remove(); return; }

            $msg.text('Deleting...').css('color', '#666');
            $.ajax({ url: ajaxUrl, type: 'POST', dataType: 'json', data: { action: 'qp_media_delete', post_id: attachmentId } })
            .done(function(resp){
                if (resp && resp.status === 'success') {
                    $.ajax({ url: ajaxUrl, type: 'POST', dataType: 'json', data: { action: 'qpmeta_get_field_value', object_type: objectType, object_id: objectId, field_name: fieldName } })
                    .done(function(getResp){
                        if (getResp && getResp.status === 'success') {
                            var currentValue = getResp.value; var newValue;
                            if (isMultiple) { var attachmentIds = []; try { attachmentIds = JSON.parse(currentValue); if (!Array.isArray(attachmentIds)) attachmentIds = []; } catch(e) { attachmentIds = Array.isArray(currentValue) ? currentValue : []; } var idx = attachmentIds.indexOf(attachmentId); if (idx > -1) attachmentIds.splice(idx, 1); newValue = JSON.stringify(attachmentIds); } else { newValue = ''; }
                            $.ajax({ url: ajaxUrl, type: 'POST', dataType: 'json', data: { action: 'qpmeta_save_field_value', object_type: objectType, object_id: objectId, field_name: fieldName, field_value: newValue } })
                            .done(function(){ $previewItem.remove(); $msg.css('color','green').text('Deleted'); setTimeout(function(){ $msg.text(''); }, 2000); });
                                        try { var $pv = $wrap.find('.qpmeta-file-preview'); updateHiddenOrder($pv); } catch(e) {}
                        }
                    });
                    try { window.dispatchEvent(new CustomEvent('qpmedia:deleted', { detail: { id: attachmentId } })); } catch(e){}
                } else { $msg.css('color', 'red').text('Delete failed: ' + (resp && resp.message || 'Unknown')); }
            }).fail(function(){ $msg.css('color', 'red').text('Delete error'); });
        });

        // Modal selector for file fields: open qlopy media modal, then save selected IDs via AJAX
        $(document).on('click', '.qpmeta-open-modal', function(e){
            e.preventDefault();
            var $btn = $(this);
            var $wrap = $btn.closest('.qpmeta-field-wrap');
            // Prefer storage id (may include option prefix) so AJAX save updates correct meta key
            var fieldName = $btn.data('storage-id') || $wrap.attr('data-field-name') || $btn.data('field-name');
            var $fieldset = $btn.closest('.qpmeta-metabox');
            var objectType = $fieldset.data('object-type');
            var objectId = parseInt($fieldset.data('object-id') || '0', 10);
            var $hidden = $wrap.find('.qpmeta-file-id');
            var ajaxUrl = getAdminAjax($fieldset);

            // build preselect array from existing hidden value
            var pre = [];
            var hv = $hidden.val();
            if (hv) {
                try { var parsed = JSON.parse(hv); if (Array.isArray(parsed)) pre = parsed.map(function(v){ return String(v); }); else if (parsed) pre = [String(parsed)]; } catch(e){ if (/^\d+$/.test(String(hv))) pre = [String(hv)]; }
            }

            var multiple = ($btn.data('multiple') === 1 || $btn.data('multiple') === '1' || $btn.data('multiple') === true || $btn.attr('data-multiple') === '1');
            var filetype = String($btn.data('filetype') || 'all');
            var opts = { multiple: !!multiple, preselect: pre };

            if (filetype === 'images') { opts.fileMode = 'images'; }
            else if (filetype && filetype !== 'all') {
                // parse comma separated extensions
                var exts = String(filetype).split(',').map(function(s){ return s.trim().replace(/^\./,'').toLowerCase(); }).filter(Boolean);
                if (exts.length) { opts.extensions = exts; }
            }

            if (!window.qlopyMedia || typeof window.qlopyMedia.openModal !== 'function') {
                alert('Media modal not available');
                return;
            }

            window.qlopyMedia.openModal(Object.assign(opts, {
                onInsert: function(selection){
                    var ids = [];
                    if (!selection) return;
                    if (Array.isArray(selection)) {
                        ids = selection.map(function(it){ return it && (it.id || it) ? String(it.id || it) : null; }).filter(Boolean);
                    } else {
                        ids = [ String(selection.id || selection) ];
                    }
                    var fieldValue = multiple ? JSON.stringify(ids) : (ids[0] || '');
                    // Save via qpmeta_save_field_value
                    $.post(ajaxUrl, { action: 'qpmeta_save_field_value', object_type: objectType, object_id: objectId, field_name: fieldName, field_value: fieldValue }, function(resp){
                        if (resp && (resp.status === 'success' || resp.success === true)) {
                            // update hidden input
                            try { $hidden.val(fieldValue); } catch(e){}
                            // refresh preview area
                            if (ids.length) {
                                $.post(ajaxUrl, { action: 'qp_media_batch_get', ids: ids.join(',') }, function(listResp){
                                    var $preview = $wrap.find('.qpmeta-file-preview');
                                    $preview.empty();
                                    if (listResp && listResp.status === 'success' && Array.isArray(listResp.items)) {
                                        listResp.items.forEach(function(it){
                                            var pid = it.id;
                                            var thumb = it.thumb || it.url || '';
                                            if (thumb) {
                                                var $item = $('<div>').addClass('qpmeta-file-preview-item').attr('data-attach-id', pid).css({position:'relative',display:'inline-block',marginRight:'8px'});
                                                // prefer explicit title/name if available
                                                if (it.title) { $item.attr('data-file-name', it.title); }
                                                var $img = $('<img>').attr('src', thumb).css({maxWidth:'150px',maxHeight:'150px',border:'1px solid #ddd',padding:'4px'});
                                                var $btn = $('<button>').attr({type:'button',title:'Remove'}).addClass('qpmeta-file-remove').css({position:'absolute',top:'2px',right:'2px',border:'none',background:'#000',color:'#fff',width:'22px',height:'22px',lineHeight:'22px',textAlign:'center',borderRadius:'50%',opacity:0.8}).html('&times;');
                                                $item.append($img).append($btn);
                                                $preview.append($item);
                                            } else {
                                                var $link = $('<a>').attr('href', it.url || '#').attr('target','_blank').text(it.title || ('File '+pid));
                                                var $rm = $('<button>').attr('type','button').addClass('qpmeta-file-remove btn btn-sm btn-outline-danger ml-2').text('Remove');
                                                var $cont = $('<div>').append($link).append($rm);
                                                $preview.append($cont);
                                            }
                                        });
                                            // initialize sortable behavior if this field expects it and refresh indexes
                                            if ($btn.data('input-type') === 'filesortable' || $preview.hasClass('qpmeta-filesortable')) {
                                                makePreviewSortable($preview);
                                                updateHiddenOrder($preview);
                                            }
                                    }
                                }, 'json');
                            } else {
                                $wrap.find('.qpmeta-file-preview').empty();
                            }
                        } else {
                            alert('Save failed');
                        }
                    }, 'json').fail(function(){ alert('Save error'); });
                }
            }));
        });

        // Helper: refresh numeric indexes shown on preview items
        function refreshPreviewIndexes($preview) {
            if (!$preview || !$preview.length) return;
            $preview.find('.qpmeta-file-preview-item').each(function(i){
                var $it = $(this);
                var $badge = $it.find('.qpmeta-file-index');
                var txt = String(i+1) + '.';
                if ($badge.length) {
                    $badge.text(txt);
                } else {
                    var $span = $('<span>').addClass('qpmeta-file-index').text(txt);
                    $it.prepend($span);
                }
                // Add filename caption (first 8 chars) at bottom of item
                var $name = $it.find('.qpmeta-file-name');
                if (!$name.length) {
                    var fname = $it.data('file-name') || '';
                    if (!fname) {
                        var $img = $it.find('img').first();
                        var src = $img.attr ? $img.attr('src') || '' : '';
                        if (src) {
                            try { fname = decodeURIComponent((src.split('/').pop()||'').split('?')[0]); } catch(e) { fname = (src.split('/').pop()||''); }
                        }
                    }
                    if (!fname) fname = '';
                    var short = fname.length > 8 ? fname.substr(0,8) + '...' : fname;
                    var $fn = $('<div>').addClass('qpmeta-file-name').text(short);
                    $it.append($fn);
                } else {
                    // keep existing but ensure it shows short form
                    var existing = $name.text() || '';
                    if (existing && existing.length > 12) $name.text(existing.substr(0,8)+'...');
                }
            });
        }

        // Panel toggle via header button
        $(document).on('click', '.qpmeta-panel-toggle', function(e){
            var $btn = $(this);
            var $item = $btn.closest('.qpmeta-repeat-item');
            var $body = $item.find('.qpmeta-panel-body').first();
            $body.slideToggle(120, function(){
                if ($body.is(':visible')) {
                    if (window.qpmetaInitTiny) try { window.qpmetaInitTiny($item); } catch(e){}
                } else {
                    if (window.qpmetaDestroyTiny) try { window.qpmetaDestroyTiny($item); } catch(e){}
                }
            });
            e.preventDefault();
        });

        // Before any form submit in admin that includes qpmeta, disable prototype inputs
        $(document).on('submit', 'form', function(){
            var $f = $(this);
            if ($f.find('.qpmeta-metabox').length) {
                $f.find('.qpmeta-repeatable-prototype').find('input,textarea,select').prop('disabled', true);
            }
        });

        // Update a single item's title from its first text-like field
        function updateItemTitle($it, idx) {
            var title = (idx+1) + '. ';
            var $preview = $it.find('input[type=text].qpmeta-preview, input[type=text], textarea').first();
            if ($preview && $preview.length) {
                var val = $preview.val() || $preview.attr('placeholder') || '';
                if (val && String(val).trim() !== '') title += String(val).trim(); else title += (idx+1);
            } else {
                title += (idx+1);
            }
            $it.find('.qpmeta-panel-title').first().text(title);
        }

        // Update header titles and optionally enforce collapse state
        function updateRepeatHeaders($group, forceCollapse) {
            forceCollapse = !!forceCollapse;
            $group = $group && $group.length ? $group : $('.qpmeta-repeat-group');
            $group.each(function(){
                var $g = $(this);
                // operate only on real items, not prototype
                var $items = $g.find('> .qpmeta-repeat-item').not('.qpmeta-repeatable-prototype');
                $items.each(function(i){
                    var $it = $(this);
                    updateItemTitle($it, i);
                    if (forceCollapse) {
                        var $body = $it.find('.qpmeta-panel-body').first();
                        if (i === 0) {
                            $body.show();
                            if (window.qpmetaInitTiny) try { window.qpmetaInitTiny($it); } catch(e){}
                        } else {
                            $body.hide();
                            if (window.qpmetaDestroyTiny) try { window.qpmetaDestroyTiny($it); } catch(e){}
                        }
                    }
                });
                // ensure prototype is at end of group
                var $proto = $g.find('> .qpmeta-repeat-item.qpmeta-repeatable-prototype');
                if ($proto.length) { $g.append($proto); }
            });
        }

        // Live update header text only when preview input changes (don't collapse)
        $(document).on('input change', '.qpmeta-repeat-group input[type=text], .qpmeta-repeat-group textarea', function(){
            var $it = $(this).closest('.qpmeta-repeat-item');
            var $group = $(this).closest('.qpmeta-repeat-group');
            var idx = $group.find('> .qpmeta-repeat-item').index($it);
            try { updateItemTitle($it, idx); } catch(e) {}
        });

        // Normalize repeatable markup: if server didn't render panel headers,
        // wrap existing item content into panel structure so toggle/handle work.
        function normalizeRepeatGroups(){
            $('.qpmeta-repeat-group').each(function(){
                var $g = $(this);
                $g.find('> .qpmeta-repeat-item').each(function(){
                    var $it = $(this);
                    if ($it.find('.qpmeta-panel-header').length === 0) {
                        // move existing children into panel-body
                        var inner = $it.contents();
                        // create panel structure
                        var isProto = $it.hasClass('qpmeta-repeatable-prototype');
                        var bodyDisplay = isProto ? 'none' : 'block';
                        var $panel = $("<div class='qpmeta-panel' style='border:1px solid #ddd;background:#f9f9f9;'></div>");
                        var $header = $("<div class='qpmeta-panel-header' style='display:flex;align-items:center;padding:8px;'></div>");
                        var $handle = $("<span class='qpmeta-panel-handle' title='Drag to reorder' style='cursor:move;margin-right:8px;'>☰</span>");
                        var $title = $("<strong class='qpmeta-panel-title' style='flex:1;'></strong>");
                        var $toggle = $("<button type='button' class='qpmeta-panel-toggle btn btn-sm btn-secondary' style='margin-left:8px;'>Toggle</button>");
                        var $body = $("<div class='qpmeta-panel-body' style='display:"+bodyDisplay+";padding:10px;'></div>");
                        $header.append($handle).append($title).append($toggle);
                        $panel.append($header).append($body);
                        $it.empty().append($panel);
                        $body.append(inner);
                    }
                });
            });
        }

        try { normalizeRepeatGroups(); } catch(e) {}

        // Initialize sortable for all repeat groups on load
        try {
            $('.qpmeta-repeat-group').each(function(){
                var $g = $(this);
                $g.sortable({ items: '> .qpmeta-repeat-item:not(.qpmeta-repeatable-prototype)', handle: '.qpmeta-panel-handle', update: function(){ updateRepeatHeaders($g, false); } });
                try { updateRepeatHeaders($g, true); } catch(e) {}
            });
        } catch(e) {}
        // After sortable init ensure headers/collapse state are consistent
        try { $('.qpmeta-repeat-group').each(function(){ updateRepeatHeaders($(this), true); }); } catch(e) {}

        // Helper: update the hidden input order from the preview and refresh indexes
        function updateHiddenOrder($preview) {
            if (!$preview || !$preview.length) return;
            var $wrap = $preview.closest('.qpmeta-field-wrap');
            var $hidden = $wrap.find('.qpmeta-file-id');
            if (!$hidden || !$hidden.length) return;
            var arr = [];
            $preview.find('.qpmeta-file-preview-item').each(function(){ arr.push(String($(this).attr('data-attach-id'))); });
            $hidden.val(JSON.stringify(arr));
            refreshPreviewIndexes($preview);
        }

        // Initialize sortable previews on page load for existing filesortable fields
        function makePreviewSortable($preview) {
            if (!$preview || !$preview.length) return;
            var $wrap = $preview.closest('.qpmeta-field-wrap');
            var $hidden = $wrap.find('.qpmeta-file-id');
            // ensure items are draggable
            $preview.find('.qpmeta-file-preview-item').attr('draggable', true);

            $preview.off('dragstart.qpmeta dragover.qpmeta dragleave.qpmeta drop.qpmeta');
            $preview.on('dragstart.qpmeta', '.qpmeta-file-preview-item', function(e){
                var id = $(this).attr('data-attach-id');
                try { e.originalEvent.dataTransfer.setData('text/plain', id); } catch(err) {}
                $(this).addClass('qp-dragging');
            });
            $preview.on('dragover.qpmeta', '.qpmeta-file-preview-item', function(e){ e.preventDefault(); $(this).addClass('qp-drag-over'); });
            $preview.on('dragleave.qpmeta', '.qpmeta-file-preview-item', function(e){ $(this).removeClass('qp-drag-over'); });
            $preview.on('drop.qpmeta', '.qpmeta-file-preview-item', function(e){
                e.preventDefault();
                var draggedId = null;
                try { draggedId = e.originalEvent.dataTransfer.getData('text/plain'); } catch(err) { draggedId = null; }
                var $dragged = $preview.find('[data-attach-id="' + draggedId + '"]').first();
                var $target = $(this);
                if ($dragged.length && $target.length && $dragged[0] !== $target[0]) {
                    $target.before($dragged);
                    updateHiddenOrder($preview);
                }
                $preview.find('.qp-drag-over').removeClass('qp-drag-over');
                $preview.find('.qp-dragging').removeClass('qp-dragging');
            });

            // make sure hidden value matches current order initially and show indexes
            updateHiddenOrder($preview);
        }

        // Auto-init any existing previews marked as filesortable
        $('.qpmeta-file-preview.qpmeta-filesortable').each(function(){ makePreviewSortable($(this)); });

        // leave tags UI to qpmeta-tags.js
    });
})(jQuery);
