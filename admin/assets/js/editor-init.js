; // Clean single IIFE implementation
;(function($){
    var ajaxUrl = window.ajaxurl || (function(){
      var base = (window.location.pathname.split('/').slice(0,-1).join('/')) || '';
      return base + '/ajax.php';
    })();

    function ensureId($el){
      var id = $el.attr('id');
      if (!id){ id = 'ta_'+Math.random().toString(36).slice(2); $el.attr('id', id); }
      return id;
    }

    // The toolbar, the same in the post editor and every qpmeta rich-text field. Row 2 (WordPress's "kitchen sink") is shown or hidden
    // by the last button of row 1; the choice is remembered across editors and page loads.
    var TOOLBAR = [
      'blocks | bold italic | bullist numlist blockquote | alignleft aligncenter alignright | link addmedia | code qpsink',
      'underline strikethrough hr | forecolor backcolor | pastetext removeformat | charmap table | outdent indent | undo redo | fullscreen'
    ];
    var SINK_KEY = 'qpTinyKitchenSink';
    function sinkOpen(){ try { return localStorage.getItem(SINK_KEY) === '1'; } catch(e) { return false; } }
    // Added to the admin page once: hides row 2 of an editor whose container has .qp-tiny-sink-off, and tightens the gaps between button
    // groups (TinyMCE's are wide) so each row stays on one line on a normal screen.
    function sinkStyle(){
      if (document.getElementById('qp-tiny-sink-css')) return;
      var s = document.createElement('style');
      s.id = 'qp-tiny-sink-css';
      s.textContent = '.qp-tiny-sink-off .tox-toolbar-overlord > .tox-toolbar:nth-child(n+2){display:none}' +
        '.qp-tiny .tox-toolbar__group{padding:0 4px}';
      document.head.appendChild(s);
    }

    // Buttons every editor gets on top of TinyMCE's own: the row 2 toggle and the toolbar that appears on a clicked image.
    function registerExtras(editor){
      editor.ui.registry.addToggleButton('qpsink', {
        icon: 'more-drawer',
        tooltip: 'Toolbar toggle',
        onAction: function(api){
          var open = !api.isActive();
          api.setActive(open);
          try { localStorage.setItem(SINK_KEY, open ? '1' : '0'); } catch(e) {}
          var c = editor.getContainer();
          if (c) c.classList.toggle('qp-tiny-sink-off', !open);
        },
        onSetup: function(api){ api.setActive(sinkOpen()); return function(){}; }
      });
      editor.on('init', function(){
        sinkStyle();
        var c = editor.getContainer();
        if (c) { c.classList.add('qp-tiny'); c.classList.toggle('qp-tiny-sink-off', !sinkOpen()); }
      });

      // Image alignment, the WordPress way: a class (alignleft / aligncenter / alignright) on the <img>, styled by the theme on the site.
      editor.ui.registry.addButton('qpalignnone', {
        icon: 'align-none',
        tooltip: 'No alignment',
        onAction: function(){
          ['alignleft', 'aligncenter', 'alignright'].forEach(function(f){ editor.formatter.remove(f); });
          editor.nodeChanged();
        }
      });
      editor.ui.registry.addContextToolbar('qpimage', {
        predicate: function(node){ return node.nodeName === 'IMG'; },
        items: 'alignleft aligncenter alignright qpalignnone | image',
        position: 'node',
        scope: 'node'
      });
    }

    function initTinyOnTextarea($ta, config){
      // Skip if inside hidden prototype; initialize only when visible
      var isHiddenProto = $ta.closest('.qpmeta-repeatable-prototype').length > 0 || !$ta.is(':visible');
      if (isHiddenProto) return;
      ensureId($ta);
      var domEl = $ta.get(0);
      // Ensure the textarea is editable before initializing TinyMCE
      try { $ta.prop('disabled', false).removeAttr('disabled').removeAttr('readonly'); } catch(e) {}
      var baseConfig = {
        // Use direct target to avoid selector conflicts in cloned groups
        target: domEl,
        menubar: false,
        branding: false,
        promotion: false,
        plugins: 'link lists image code charmap table fullscreen',
        // no default image toolbar button; the custom `addmedia` button opens the media library
        toolbar: TOOLBAR,
        block_formats: 'Paragraph=p; Heading 1=h1; Heading 2=h2; Heading 3=h3; Heading 4=h4; Heading 5=h5; Heading 6=h6;',
        toolbar_mode: 'wrap',
        convert_urls: true,
        relative_urls: false,
        remove_script_host: false,
        paste_as_text: true,
        // What survives saving. class and style are kept (image alignment classes, colours, indents, WordPress content brought over).
        valid_elements: 'a[href|target|rel|title|class],strong/b,em/i,u,s,strike,del,sub,sup,blockquote[class|style],code,pre[class],br,hr,' +
          'span[class|style],p[class|style],div[class|style],h1[class|style|id],h2[class|style|id],h3[class|style|id],h4[class|style|id],h5[class|style|id],h6[class|style|id],' +
          'ul[class|style],ol[class|style|start|type],li[class|style],img[src|alt|title|width|height|class|style|loading],' +
          'table[class|style|border|cellpadding|cellspacing|width],caption,colgroup,col[width|span|style],thead,tbody,tfoot,' +
          'tr[class|style],td[class|style|colspan|rowspan|width],th[class|style|colspan|rowspan|width|scope],figure[class|style],figcaption[class]',
        // Alignment on an image (or figure / table) is a class, as WordPress does it, not an inline float; text keeps text-align.
        formats: {
          alignleft:   [{ selector: 'p,h1,h2,h3,h4,h5,h6,td,th,div,ul,ol,li,pre,blockquote', styles: { textAlign: 'left' } },   { selector: 'img,figure,table', classes: 'alignleft' }],
          aligncenter: [{ selector: 'p,h1,h2,h3,h4,h5,h6,td,th,div,ul,ol,li,pre,blockquote', styles: { textAlign: 'center' } }, { selector: 'img,figure,table', classes: 'aligncenter' }],
          alignright:  [{ selector: 'p,h1,h2,h3,h4,h5,h6,td,th,div,ul,ol,li,pre,blockquote', styles: { textAlign: 'right' } },  { selector: 'img,figure,table', classes: 'alignright' }]
        },
        // Inside the editor only (its own iframe): how those classes look while writing. The site's look is up to its theme.
        content_style: 'img{max-width:100%;height:auto}' +
          '.alignleft{float:left;margin:.3em 1.5em 1em 0}.alignright{float:right;margin:.3em 0 1em 1.5em}' +
          '.aligncenter{display:block;margin-left:auto;margin-right:auto}' +
          'body::after{content:"";display:table;clear:both}',
        images_upload_handler: function(blobInfo, progress){
          return new Promise(function(resolve, reject){
            var fd = new FormData();
            fd.append('action','qp_media_upload');
            fd.append('file', blobInfo.blob(), blobInfo.filename());
            $.ajax({
              url: ajaxUrl,
              method:'POST',
              data: fd,
              processData:false,
              contentType:false,
              dataType:'json',
              xhr: function(){
                var xhr = new window.XMLHttpRequest();
                if (xhr.upload && typeof progress === 'function') {
                  xhr.upload.addEventListener('progress', function(e){
                    if (e.lengthComputable) {
                      try { progress(Math.round((e.loaded / e.total) * 100)); } catch(_e) {}
                    }
                  });
                }
                return xhr;
              }
            }).done(function(resp){
              var url = '';
              if (resp && resp.data) {
                url = resp.data.url || resp.data.thumb || '';
              } else if (resp && resp.url) {
                url = resp.url;
              }
              function getCmsBase(){
                if (typeof window.qlopyCmsBase === 'string') return window.qlopyCmsBase;   // from config.php (admin/inc/header.php)
                var parts = window.location.pathname.split('/');
                var idx = parts.indexOf('admin');
                if (idx > 0) { return '/' + parts.slice(1, idx).join('/'); }
                return '/' + (parts.filter(Boolean)[0] || '');
              }
              function toAbsolute(u){
                if (!u) return u;
                var origin = window.location.origin || (window.location.protocol + '//' + window.location.host);
                var cmsBase = getCmsBase();
                if (/^https?:\/\//i.test(u)) return u;
                var ud = window.qlopyUploadsDir || 'uploads';
                if (u.indexOf('../' + ud + '/') === 0) {
                  return origin + cmsBase + '/' + ud + '/' + u.replace('../' + ud + '/','');
                }
                if (u.indexOf('/' + ud + '/') === 0) {
                  return origin + cmsBase + u;
                }
                if (u.indexOf(cmsBase + '/' + ud + '/') === 0) {
                  return origin + u;
                }
                try { return new URL(u, origin + cmsBase + '/').toString(); } catch(e) { return u; }
              }
              if (url) { resolve(toAbsolute(url)); } else { reject('No URL in response'); }
            }).fail(function(){ reject('Upload failed'); });
          });
        }
      };
      if (typeof window.getTinyMceConfig === 'function') {
        try {
          var extra = window.getTinyMceConfig({ context: 'post', post_type: $('input[name=post_type]').val() || 'post' });
          baseConfig = $.extend(true, {}, baseConfig, extra||{});
        } catch(e){}
      }
      var finalConfig = $.extend(true, {}, baseConfig, config||{});
      // Ensure editor starts in design mode and is focusable
      finalConfig.readonly = false;
      // Remove 'required' from textarea to avoid HTML5 validation blocking hidden fields
      $ta.removeAttr('required').prop('required', false);
      // Wrap any provided setup to also register our custom addmedia button
      finalConfig.setup = (finalConfig.setup ? (function(orig){
        return function(editor){
          try { orig(editor); } catch(e){}
          try { registerExtras(editor); } catch(e){}
          // register Add Media button
          try {
            editor.ui.registry.addButton('addmedia', {
              icon: 'image',
              text: 'Media',
              tooltip: 'Media',
              onAction: function() {
                // open modal in all-file, multi-select mode
                if (window.qlopyMedia && typeof window.qlopyMedia.openModal === 'function') {
                  window.__TINYMCE_UI_ACTIVE__ = true;
                  window.qlopyMedia.openModal({ multiple: true, fileMode: 'all', onInsert: function(items){
                    try { window.__TINYMCE_UI_ACTIVE__ = false; } catch(e){}
                    if (!items) return;
                    var arr = Array.isArray(items) ? items : [items];
                    var adminAjax = window.qlopyAdminAjax || 'ajax.php';
                    // Ensure each item has a real file URL; if not, fetch details from server
                    var fetches = arr.map(function(it){
                      return new Promise(function(resolve){
                        if (it && it.url && it.url !== '') return resolve(it);
                        var id = it && (it.id || it.post_id || it.ID);
                        if (!id) return resolve(it);
                        $.post(adminAjax, { action: 'qp_media_get', post_id: id }, function(resp){
                          if (resp && resp.status === 'success') {
                            var meta = resp.meta || {};
                            it.url = (meta.file && meta.file.url) ? meta.file.url : (it.url || '');
                            it.title = (resp.post && resp.post.title) ? resp.post.title : (it.title || '');
                          }
                          resolve(it);
                        }, 'json').fail(function(){ resolve(it); });
                      });
                    });
                    Promise.all(fetches).then(function(resolved){
                      var ins = '';
                      resolved.forEach(function(it){
                        var url = it.url || '';
                        var title = (it.title||'');
                        var u = (url||'').split('?')[0];
                        var ext = (it.extension || (u.split('.').pop()||'')).toLowerCase();
                        if (['jpg','jpeg','png','gif','webp','avif','bmp','svg'].indexOf(ext) !== -1) {
                          ins += '<img src="'+ url.replace(/"/g,'\\"') +'" alt="'+ (title.replace?title.replace(/"/g,'\\"') : '') +'" />';
                        } else if (url) {
                          ins += '[embed]'+ url +'[/embed]';
                        } else {
                          var link = it.id ? ((window.qlopyAdminUrl || '') + 'media.php?attachment=' + encodeURIComponent(it.id)) : '#';
                          ins += '<a href="'+ link +'">'+ (title || 'Download file') +'</a>';
                        }
                      });
                      try { editor.insertContent(ins); editor.fire('change'); } catch(e){ console.error(e); }
                    });
                  } });
                }
              }
            });
          } catch(e) { }

          editor.on('init', function(){
            try { editor.setMode('design'); } catch(e){}
            // Don't auto-focus to prevent page scroll jumping when multiple editors exist
          });
        };
      })(finalConfig.setup) : function(editor){
        try { registerExtras(editor); } catch(e){}
        try {
          editor.ui.registry.addButton('addmedia', {
            icon: 'image',
            text: 'Media',
            tooltip: 'Media',
            onAction: function() {
              if (window.qlopyMedia && typeof window.qlopyMedia.openModal === 'function') {
                window.__TINYMCE_UI_ACTIVE__ = true;
                window.qlopyMedia.openModal({ multiple: true, fileMode: 'all', onInsert: function(items){
                  try { window.__TINYMCE_UI_ACTIVE__ = false; } catch(e){}
                  if (!items) return;
                  var arr = Array.isArray(items) ? items : [items];
                  var adminAjax = window.qlopyAdminAjax || 'ajax.php';
                  var fetches = arr.map(function(it){
                    return new Promise(function(resolve){
                      if (it && it.url && it.url !== '') return resolve(it);
                      var id = it && (it.id || it.post_id || it.ID);
                      if (!id) return resolve(it);
                      $.post(adminAjax, { action: 'qp_media_get', post_id: id }, function(resp){
                        if (resp && resp.status === 'success') {
                          var meta = resp.meta || {};
                          it.url = (meta.file && meta.file.url) ? meta.file.url : (it.url || '');
                          it.title = (resp.post && resp.post.title) ? resp.post.title : (it.title || '');
                        }
                        resolve(it);
                      }, 'json').fail(function(){ resolve(it); });
                    });
                  });
                  Promise.all(fetches).then(function(resolved){
                    var ins = '';
                    resolved.forEach(function(it){
                      var url = it.url || '';
                      var title = (it.title||'');
                      var u = (url||'').split('?')[0];
                      var ext = (it.extension || (u.split('.').pop()||'')).toLowerCase();
                      if (['jpg','jpeg','png','gif','webp','avif','bmp','svg'].indexOf(ext) !== -1) {
                        ins += '<img src="'+ url.replace(/"/g,'\\"') +'" alt="'+ (title.replace?title.replace(/"/g,'\\"') : '') +'" />';
                      } else if (url) {
                        ins += '[embed]'+ url +'[/embed]';
                      } else {
                        var link = it.id ? ((window.qlopyAdminUrl || '') + 'media.php?attachment=' + encodeURIComponent(it.id)) : '#';
                        ins += '<a href="'+ link +'">'+ (title || 'Download file') +'</a>';
                      }
                    });
                    try { editor.insertContent(ins); editor.fire('change'); } catch(e){ console.error(e); }
                  });
                } });
              }
            }
          });
        } catch(e) {}
        editor.on('init', function(){
          try { editor.setMode('design'); } catch(e){}
        });
      });
      if (window.tinymce && typeof tinymce.init === 'function') {
        // Remove any existing instance tied to this element id before re-init
        try {
          var id = $ta.attr('id');
          if (id) {
            var existing = tinymce.get(id);
            if (existing) existing.remove();
          }
        } catch(e){}
        // Filter TinyMCE instrumentation log without muting other logs
        try {
          if (window.console && console.log) {
            var _origLog = console.log;
            console.log = function(){
              try {
                if (arguments && typeof arguments[0] === 'string' && /\[TinyMCE\] instrumentation active/i.test(arguments[0])) {
                  return; // skip this specific TinyMCE message
                }
              } catch(_e) {}
              return _origLog.apply(console, arguments);
            };
          }
        } catch(e){}
        tinymce.init(finalConfig);
      }
    }

    function destroyTinyOnTextarea($ta){
      var id = $ta.attr('id');
      if (window.tinymce && id) {
        var ed = tinymce.get(id);
        if (ed){ ed.remove(); }
      }
    }

    // Expose global helpers so other admin scripts can initialize/destroy
    // TinyMCE instances for dynamically shown/collapsed containers.
    window.qpmetaInitTiny = function($container){
      try {
        var $ctx = $container && $container.jquery ? $container : ($container ? $($container) : $(document));
        $ctx.find('textarea.qpmeta-richtext, .qpmeta-richtext textarea').each(function(){ initTinyOnTextarea($(this)); });
      } catch(e) {}
    };
    window.qpmetaDestroyTiny = function($container){
      try {
        var $ctx = $container && $container.jquery ? $container : ($container ? $($container) : $(document));
        $ctx.find('textarea.qpmeta-richtext, .qpmeta-richtext textarea').each(function(){ destroyTinyOnTextarea($(this)); });
      } catch(e) {}
    };

      // duplicate nested IIFE removed

    // Initialize editors on DOM ready
    $(function(){
      // Main post content: prefer #content or [name=content]
      var $main = $('textarea#content, textarea[name=content], textarea.post-content').first();
      if ($main.length) {
        initTinyOnTextarea($main, {
            // Keep blocks dropdown for main editor
            toolbar: TOOLBAR,
            contextmenu: 'paste | link image inserttable'
          });
      }

      // QPMeta/metabox editors (with the blocks dropdown, like the main editor)
      $('textarea.qpmeta-richtext, .qpmeta-richtext textarea').each(function(){
        var $ta = $(this);
        initTinyOnTextarea($ta, {
          toolbar: TOOLBAR,
        });
      });

      // Init TinyMCE for dynamically added repeatable group fields
      $(document).on('qpmeta-field-added', function(_e, $container){
        try {
          var $ctx = ($container && $container.jquery) ? $container : $container ? $(($container.nodeType ? $container : null)) : $();
          ($ctx.length ? $ctx : $(document)).find('textarea.qpmeta-richtext, .qpmeta-richtext textarea').each(function(){
            var $ta = $(this);
            initTinyOnTextarea($ta, {
              toolbar: TOOLBAR,
            });
          });
        } catch(e) {}
      });

      // Cleanly remove TinyMCE when a repeatable group is removed
      $(document).on('click', '.qpmeta-repeat-remove', function(){
        var $item = $(this).closest('.qpmeta-repeat-item');
        $item.find('textarea.qpmeta-richtext, .qpmeta-richtext textarea').each(function(){
          destroyTinyOnTextarea($(this));
        });
      });
    });

    })(jQuery);
