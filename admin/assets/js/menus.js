(function($){
  const Menus = {
    state: { ajax: '', currentMenu: null, menus: {}, locations: {}, locationsMap: {}, boxes: { posts: {}, tax: {} } },
    init: function(ajaxUrl){ this.state.ajax = ajaxUrl; this.bind(); this.loadAll(); },
    api: function(data){ return $.ajax({ url: Menus.state.ajax, method:'POST', data:data, dataType:'json' }); },
    bind: function(){
      $('#createMenuBtn').on('click', function(){ const name = $('#newMenuName').val().trim(); if (!name) return; Menus.api({ action:'menus_create', menu_label:name }).done(d=>{ Menus.state.menus=d.menus||{}; Menus.state.currentMenu=d.current; Menus.refreshSelectors(); Menus.renderMenu(); $('#newMenuName').val(''); }); });
      // Duplicate current menu (label + items)
      $(document).off('click', '#duplicateMenuBtn').on('click', '#duplicateMenuBtn', function(){
        const cur = Menus.state.currentMenu; if (!cur) return;
        const src = Menus.state.menus[cur];
        const newLabel = prompt('Duplicate menu as:', src.label + ' Copy');
        if (!newLabel) return;
        Menus.api({ action:'menus_create', menu_label:newLabel }).done(d=>{
          Menus.state.menus = d.menus||{}; const newSlug = d.current;
          // copy items
          Menus.state.menus[newSlug].items = JSON.parse(JSON.stringify(src.items||[]));
          Menus.api({ action:'menus_save', menu_slug:newSlug, menu_label:newLabel, items: JSON.stringify(Menus.state.menus[newSlug].items) }).done(()=>{
            Menus.state.currentMenu = newSlug; Menus.refreshSelectors(); Menus.renderMenu(); Menus.message('Menu duplicated.');
          });
        });
      });
      $('#menuSelect').on('change', function(){ Menus.state.currentMenu = $(this).val(); Menus.renderMenu(); });
      $('#addCustomLink').on('click', function(){ const cur = Menus.state.currentMenu; if (!cur) return; const it = { id: Menus.newId(), title: $('#customTitle').val(), url: $('#customUrl').val(), class: $('#customClass').val(), children: [] }; Menus.state.menus[cur].items.push(it); Menus.renderMenu(); });
      $('#addSelectedPages').on('click', function(){ const cur = Menus.state.currentMenu; if (!cur) return; $('.page-check:checked').each(function(){
        const title = $(this).data('title');
        const url = $(this).data('url');
        const slug = $(this).data('slug')||'';
        Menus.state.menus[cur].items.push({ id: Menus.newId(), title: title, url: url, type: 'page', slug: slug, class: '', children: [] });
      }); Menus.renderMenu(); });

      // Options bar: create dynamic boxes for selected post types
      $(document).off('click', '#createPostTypeBoxes').on('click', '#createPostTypeBoxes', function(){
        $('#menuPanelOptions .opt-pt:checked').each(function(){
          const pt = $(this).val(); const label = $(this).closest('label').text().trim();
          Menus.createPostsBox(pt, label);
        });
        // Always ensure Pages box exists
        Menus.createPagesBox('Pages');
        const selectedPts = $('#menuPanelOptions .opt-pt:checked').map(function(){ return $(this).val(); }).get();
        const selectedTxs = $('#menuPanelOptions .opt-tax:checked').map(function(){ return $(this).val(); }).get();
        Menus.api({ action:'menus_set_panel_boxes', post_types: selectedPts, taxonomies: selectedTxs });
      });
      // Options bar: create dynamic boxes for selected taxonomies
      $(document).off('click', '#createTaxBoxes').on('click', '#createTaxBoxes', function(){
        $('#menuPanelOptions .opt-tax:checked').each(function(){
          const tx = $(this).val(); const label = $(this).closest('label').text().trim();
          Menus.createTaxBox(tx, label);
        });
        const selectedPts = $('#menuPanelOptions .opt-pt:checked').map(function(){ return $(this).val(); }).get();
        const selectedTxs = $('#menuPanelOptions .opt-tax:checked').map(function(){ return $(this).val(); }).get();
        Menus.api({ action:'menus_set_panel_boxes', post_types: selectedPts, taxonomies: selectedTxs });
      });
      // Delegate add to menu for dynamic posts boxes
      $(document).on('click', '.add-posts-box', function(){
        const cur = Menus.state.currentMenu; if (!cur) return;
        const box = $(this).closest('.dynamic-box');
        box.find('.post-check:checked').each(function(){
          const title = $(this).data('title');
          const url = $(this).data('url');
          const slug = $(this).data('slug')||'';
          const postType = $(this).data('post-type')||'post';
          Menus.state.menus[cur].items.push({ id: Menus.newId(), title: title, url: url, type: 'post', post_type: postType, slug: slug, class: '', children: [] });
        });
        // Uncheck added items for clarity
        box.find('.post-check:checked').prop('checked', false);
        Menus.renderMenu();
      });
      // Delegate add to menu for dynamic taxonomy boxes
      $(document).on('click', '.add-terms-box', function(){
        const cur = Menus.state.currentMenu; if (!cur) return;
        const box = $(this).closest('.dynamic-box');
        box.find('.term-check:checked').each(function(){
          const title = $(this).data('title');
          const url = $(this).data('url');
          const slug = $(this).data('slug')||'';
          const taxonomy = $(this).data('taxonomy')||'';
          Menus.state.menus[cur].items.push({ id: Menus.newId(), title: title, url: url, type: 'taxonomy', taxonomy: taxonomy, slug: slug, class: '', children: [] });
        });
        box.find('.term-check:checked').prop('checked', false);
        Menus.renderMenu();
      });
      // Delegate add to menu for dynamic pages box
      $(document).on('click', '.add-pages-box', function(){
        const cur = Menus.state.currentMenu; if (!cur) return;
        const box = $(this).closest('.dynamic-box');
        box.find('.page-check:checked').each(function(){
          const title = $(this).data('title');
          const url = $(this).data('url');
          const slug = $(this).data('slug')||'';
          Menus.state.menus[cur].items.push({ title: title, url: url, type: 'page', slug: slug, class: '', children: [] });
        });
        box.find('.page-check:checked').prop('checked', false);
        Menus.renderMenu();
      });

      $('#assignLocation').on('click', function(){ const loc = $('#locationSelect').val(); const cur = Menus.state.currentMenu; Menus.api({ action:'menus_assign_location', location_key:loc, menu_slug:cur }).done(d=>{ Menus.state.locationsMap=d.locations_map||{}; Menus.message('Location assigned.'); Menus.refreshSelectors(); });
      });
      // update assign button when location or menu selection changes
      $(document).on('change', '#locationSelect, #menuSelect', function(){ Menus.updateAssignButtonState(); });
      // when a location is selected, if a menu is assigned to it, switch the menu selector to that menu
      $(document).on('change', '#locationSelect', function(){
        const loc = $(this).val();
        if (!loc) return Menus.updateAssignButtonState();
        const mapped = Menus.state.locationsMap && Menus.state.locationsMap[loc];
        if (mapped && Menus.state.menus && Menus.state.menus[mapped]){
          Menus.state.currentMenu = mapped;
          Menus.refreshSelectors();
          Menus.renderMenu();
        } else {
          Menus.updateAssignButtonState();
        }
      });
      $('#saveMenuBtn').on('click', function(){ const cur = Menus.state.currentMenu; const m = Menus.state.menus[cur]; Menus.api({ action:'menus_save', menu_slug:cur, menu_label:m.label, items: JSON.stringify(m.items) }).done(()=>Menus.message('Menu saved.')); });
      $('#deleteMenuBtn').on('click', function(){ const cur = Menus.state.currentMenu; if (!cur) return; if (!confirm('Delete current menu?')) return; Menus.api({ action:'menus_delete', menu_slug:cur }).done(function(d){ Menus.state.menus = d.menus||{}; Menus.state.locationsMap = d.locations_map||{}; Menus.state.currentMenu = Object.keys(Menus.state.menus)[0] || null; Menus.refreshSelectors(); Menus.renderMenu(); Menus.message('Menu deleted.'); }); });
    },
    message: function(msg){ $('#menuMsg').text(msg); },
    loadAll: function(){
      Menus.api({ action:'menus_get_all' }).done(function(d){
        Menus.state.menus = d.menus || {};
        Menus.state.locations = d.locations || {};
        Menus.state.locationsMap = d.locations_map || {};
        // Refresh selectors to populate UI
        Menus.refreshSelectors();
        // If a location is present in the selector and has an assigned menu, prefer that as current
        const selLoc = $('#locationSelect').val();
        if (selLoc && Menus.state.locationsMap && Menus.state.locationsMap[selLoc] && Menus.state.menus[Menus.state.locationsMap[selLoc]]){
          Menus.state.currentMenu = Menus.state.locationsMap[selLoc];
        }
        // Otherwise if currentMenu is not set or invalid, pick first available menu
        if (!Menus.state.currentMenu || !Menus.state.menus[Menus.state.currentMenu]){
          const keys = Object.keys(Menus.state.menus || {});
          Menus.state.currentMenu = keys[0] || null;
        }
        Menus.updateAssignButtonState();
        if (Menus.state.currentMenu) Menus.renderMenu();
        Menus.initPanelBoxes();
      });
    },
    initPanelBoxes: function(){ Menus.api({ action:'menus_get_panel_boxes' }).done(function(d){ const boxes = (d.boxes||{}); const pts = boxes.post_types||[]; const txs = boxes.taxonomies||[]; $('#menuPanelOptions .opt-pt').each(function(){ const v = $(this).val(); if (pts.indexOf(v) !== -1) $(this).prop('checked', true); }); $('#menuPanelOptions .opt-tax').each(function(){ const v = $(this).val(); if (txs.indexOf(v) !== -1) $(this).prop('checked', true); });
      // Always create Pages box by default
      Menus.createPagesBox('Pages');
      // Create boxes for saved post types
      pts.forEach(pt=>{ const label = $('#menuPanelOptions .opt-pt[value="'+pt+'"]').closest('label').text().trim(); Menus.createPostsBox(pt, label || pt); });
      // Create boxes for saved taxonomies
      txs.forEach(tx=>{ const label = $('#menuPanelOptions .opt-tax[value="'+tx+'"]').closest('label').text().trim(); Menus.createTaxBox(tx, label || tx); });
    }); },
    loadPages: function(container){ Menus.api({ action:'get_pages_list' }).done(function(d){ const cont = $(container); cont.empty(); (d.pages||[]).forEach(p=> cont.append(`<label class='d-block'><input type='checkbox' class='page-check' data-url='${p.url}' data-title='${p.title}' data-slug='${p.slug||''}'> ${p.title}</label>`)); }); },
    loadPosts: function(postType, container){ const pt = postType || 'post'; Menus.api({ action:'get_posts_list', post_type: pt }).done(function(d){ const cont = $(container); cont.empty(); (d.posts||[]).forEach(p=> cont.append(`<label class='d-block'><input type='checkbox' class='post-check' data-url='${p.url}' data-title='${p.title}' data-slug='${p.slug||''}' data-post-type='${p.post_type||pt}'> ${p.title}</label>`)); }); },
    loadTerms: function(taxonomy, container){ const params = { action:'get_terms_list' }; if (taxonomy) params.taxonomy = taxonomy; Menus.api(params).done(function(d){ const cont = $(container); cont.empty(); (d.terms||[]).forEach(t=> { const label = (t.taxonomy ? `[${t.taxonomy}] ` : '') + (t.term||t.slug); cont.append(`<label class='d-block'><input type='checkbox' class='term-check' data-url='${t.url}' data-title='${t.term||t.slug}' data-slug='${t.slug||''}' data-taxonomy='${t.taxonomy||''}'> ${label}</label>`); }); }); },
    createPostsBox: function(postType, label){ if (Menus.state.boxes.posts[postType]) return; Menus.state.boxes.posts[postType] = true; const id = `postsContainer-${postType}`; const box = $(`<div class='card dynamic-box mt-3' data-post-type='${postType}'>
        <div class='card-header'>Posts (${label||postType})</div>
        <div class='card-body'>
          <div class='small text-muted'>Select ${label||postType} posts and click Add to Menu.</div>
          <div id='${id}'></div>
          <button class='btn btn-outline-primary mt-2 add-posts-box'>Add to Menu</button>
        </div>
      </div>`);
      $('#dynamicBoxes').append(box);
      Menus.loadPosts(postType, '#' + id);
    },
    createPagesBox: function(label){ if (Menus.state.boxes.posts['page']) return; Menus.state.boxes.posts['page'] = true; const id = 'pagesContainer-page'; const box = $(`<div class='card dynamic-box mt-3' data-post-type='page'>
        <div class='card-header'>Pages (${label||'Pages'})</div>
        <div class='card-body'>
          <div class='small text-muted'>Select pages and click Add to Menu.</div>
          <div id='${id}'></div>
          <button class='btn btn-outline-primary mt-2 add-pages-box'>Add to Menu</button>
        </div>
      </div>`);
      $('#dynamicBoxes').append(box);
      Menus.loadPages('#' + id);
    },
    createTaxBox: function(taxonomy, label){ if (Menus.state.boxes.tax[taxonomy]) return; Menus.state.boxes.tax[taxonomy] = true; const id = `taxContainer-${taxonomy}`; const box = $(`<div class='card dynamic-box mt-3' data-taxonomy='${taxonomy}'>
        <div class='card-header'>Taxonomy (${label||taxonomy})</div>
        <div class='card-body'>
          <div class='small text-muted'>Select ${label||taxonomy} terms and click Add to Menu.</div>
          <div id='${id}'></div>
          <button class='btn btn-outline-primary mt-2 add-terms-box'>Add to Menu</button>
        </div>
      </div>`);
      $('#dynamicBoxes').append(box);
      Menus.loadTerms(taxonomy, '#' + id);
    },
    refreshSelectors: function(){ const sel = $('#menuSelect'); sel.empty(); Object.keys(Menus.state.menus).forEach(slug=> sel.append(`<option value='${slug}'>${Menus.state.menus[slug].label}</option>`)); if (Menus.state.currentMenu) sel.val(Menus.state.currentMenu); const locSel = $('#locationSelect'); locSel.empty(); Object.keys(Menus.state.locations).forEach(k=>{ const selected = (Menus.state.locationsMap[k]===Menus.state.currentMenu)?'selected':''; locSel.append(`<option value='${k}' ${selected}>${Menus.state.locations[k]}</option>`); }); },
    refreshSelectors: function(){
      const sel = $('#menuSelect'); sel.empty();
      // build ordered list of menus: sort by label then move currentMenu to front
      const keys = Object.keys(Menus.state.menus || {});
      keys.sort((a,b)=>{ const A = (Menus.state.menus[a]?.label||'').toLowerCase(); const B = (Menus.state.menus[b]?.label||'').toLowerCase(); return A.localeCompare(B); });
      if (Menus.state.currentMenu){ const idx = keys.indexOf(Menus.state.currentMenu); if (idx > 0){ keys.splice(idx,1); keys.unshift(Menus.state.currentMenu); } }
      keys.forEach(slug => sel.append(`<option value='${slug}'>${Menus.state.menus[slug].label}</option>`));
      if (Menus.state.currentMenu) sel.val(Menus.state.currentMenu);
      const locSel = $('#locationSelect'); locSel.empty(); Object.keys(Menus.state.locations || {}).forEach(k=>{ const selected = (Menus.state.locationsMap[k]===Menus.state.currentMenu)?'selected':''; locSel.append(`<option value='${k}' ${selected}>${Menus.state.locations[k]}</option>`); });
      Menus.updateAssignButtonState();
    },

    updateAssignButtonState: function(){
      const cur = Menus.state.currentMenu;
      const loc = $('#locationSelect').val();
      const btn = $('#assignLocation');
      const assignedNote = $('#assignedNote');
      if (!loc){ btn.prop('disabled', true).text('Assign'); assignedNote.text(''); return; }
      const mapped = Menus.state.locationsMap && Menus.state.locationsMap[loc];
      if (mapped){
        const label = Menus.state.menus && Menus.state.menus[mapped] ? (Menus.state.menus[mapped].label || mapped) : mapped;
        assignedNote.text('Assigned: ' + label);
      } else {
        assignedNote.text('');
      }
      if (!cur){ btn.prop('disabled', true).text('Assign'); return; }
      if (mapped && mapped === cur){ btn.prop('disabled', true).text('Assigned'); }
      else { btn.prop('disabled', false).text('Assign'); }
    },
    renderMenu: function(){
      const list = $('#menuItems'); list.empty();
      // Ensure currentMenu points to a valid menu; fall back to first available
      let cur = Menus.state.currentMenu;
      const available = Object.keys(Menus.state.menus || {});
      if (!cur || available.indexOf(cur) === -1) {
        cur = available[0] || null;
        Menus.state.currentMenu = cur;
      }
      // Sync the select UI in case it got out of sync
      if (cur) $('#menuSelect').val(cur);
      const items = (Menus.state.menus[cur]?.items)||[];
      console.debug('[Menus] renderMenu current:', cur, 'available:', available, 'items:', items.length);
      // Build Nestable structure
      const dd = $('<div class="dd"></div>');
      const ol = $('<ol class="dd-list"></ol>');
      function buildList(arr){ const container = $('<ol class="dd-list"></ol>'); arr.forEach(it=>{ if (!it.id) it.id = Menus.newId(); const li = Menus.itemLi(it); container.append(li); }); return container; }
      items.forEach(it=> ol.append(Menus.itemLi(it)));
      dd.append(ol);
      list.append(dd);
      // initialize nestable (if plugin available)
      try{
        $('.dd').nestable({ maxDepth: 10 }).off('change').on('change', function(){
          const serialized = $(this).nestable('serialize');
          Menus.state.menus[cur].items = Menus.nestableToState(serialized);
          // re-render to refresh inner controls
          Menus.renderMenu();
        });
      }catch(e){ console.warn('Nestable not available', e); }
    },
    // Return a <li class='dd-item'> element for an item (recursively includes children)
    itemLi: function(item){
      const title = item.title||'(no title)'; const url = item.url||''; const cls = item.class||''; const itemId = item.id||Menus.newId(); item.id = itemId;
      const li = $(`<li class="dd-item" data-id="${itemId}"></li>`);
      // Build a small drag handle so buttons remain clickable (not inside the dd-handle)
      const row = $(`<div class="d-flex align-items-center justify-content-between px-2 py-1"></div>`);
      const drag = $(`<div class="drag-handle d-flex align-items-center" style="cursor:move;"><span class="me-2" style="font-size:16px;">☰</span><div><strong class="menu-title">${title}</strong><div class="small text-muted menu-url">${url}</div></div></div>`);
      // apply Nestable's handle class only to the drag element
      drag.addClass('dd-handle');
      const btns = $(`<div class="btns ms-2">
          <button type="button" class="btn btn-sm btn-outline-secondary toggle" aria-expanded="false">Edit</button>
          <button type="button" class="btn btn-sm btn-danger remove" title="Remove">\u00D7</button>
        </div>`);
      row.append(drag).append(btns);
      const fields = $(`<div class="item-fields p-2 bg-white border-top" style="display:none">
        <div class="row g-2">
          <div class="col-md-4"><label class="form-label">Title</label><input class="form-control title" value="${title}"></div>
          <div class="col-md-4"><label class="form-label">URL</label><input class="form-control url" value="${url}"></div>
          <div class="col-md-4"><label class="form-label">Class</label><input class="form-control class" value="${cls}"></div>
        </div>
      </div>`);
      li.append(row).append(fields);
      // children
      if (item.children && item.children.length){
        const childOl = $('<ol class="dd-list"></ol>');
        item.children.forEach(ch=> childOl.append(Menus.itemLi(ch)));
        li.append(childOl);
      }
      // handlers — stopPropagation on buttons so Nestable's drag behavior doesn't intercept clicks
      btns.find('.toggle').on('click', function(e){ e.preventDefault(); e.stopPropagation(); var expanded = $(this).attr('aria-expanded') === 'true'; $(this).attr('aria-expanded', (!expanded).toString()); fields.stop(true,true).slideToggle(140); });
      btns.find('.remove').on('click', function(e){ e.preventDefault(); e.stopPropagation(); const id = li.data('id'); if (!confirm('Remove this menu item?')) return; Menus.removeItemById(id); });
      // ensure inputs don't trigger drag when clicked
      fields.find('input').on('mousedown', function(e){ e.stopPropagation(); });
      fields.find('input.title').on('input', function(){ const v=$(this).val(); Menus.setItemById(itemId, 'title', v); drag.find('.menu-title').text(v||'(no title)'); });
      fields.find('input.url').on('input', function(){ Menus.setItemById(itemId, 'url', $(this).val()); drag.find('.menu-url').text($(this).val()||''); });
      fields.find('input.class').on('input', function(){ Menus.setItemById(itemId, 'class', $(this).val()); });
      return li;
    },
    // Find item object by id (returns the item object reference)
    getItemById: function(id){ const cur = Menus.state.currentMenu; if (!cur) return null; const items = Menus.state.menus[cur].items; const stack = [items]; while(stack.length){ const list = stack.shift(); for (let it of list){ if (it.id === id) return it; if (it.children && it.children.length) stack.push(it.children); } } return null; },
    setItemById: function(id, key, val){ const rec = Menus.getItemById(id); if (!rec) return; rec[key]=val; },
    duplicateItemById: function(id){ const cur = Menus.state.currentMenu; if (!cur) return; const found = Menus.findItemAndParentById(id); if (!found) return; const { list, index, item } = found; const copy = JSON.parse(JSON.stringify(item)); function reid(obj){ obj.id = Menus.newId(); if (obj.children) obj.children.forEach(ch=> reid(ch)); } reid(copy); list.splice(index+1,0,copy); Menus.renderMenu(); },
    // Convert nestable serialized structure into the menu state array by mapping ids back to item objects
    nestableToState: function(serialized){ const cur = Menus.state.currentMenu; if (!cur) return []; function mapNodes(nodes){ const out = []; (nodes||[]).forEach(n=>{ const src = Menus.getItemById(n.id); if (!src) return; const clone = JSON.parse(JSON.stringify(src)); clone.children = mapNodes(n.children || []); out.push(clone); }); return out; } return mapNodes(serialized); },
    setItemProp: function(path,key,val){ let ref = Menus.state.menus[Menus.state.currentMenu].items; for(let i=0;i<path.length;i++){ ref = (i===path.length-1)?ref[path[i]]:ref[path[i]].children; } ref[key]=val; },
    removeItem: function(path){ let ref = Menus.state.menus[Menus.state.currentMenu].items; for(let i=0;i<path.length-1;i++){ ref = ref[path[i]].children; } ref.splice(path[path.length-1],1); Menus.renderMenu(); },
      removeItemById: function(id){
        const cur = Menus.state.currentMenu; if (!cur) return;
        const items = Menus.state.menus[cur].items;
        const removed = Menus.removeInListById(items, id);
        console.debug('[Menus] Remove result for', id, '=>', removed);
        if (removed) Menus.renderMenu();
      },
      removeInListById: function(list, id){
          for (let i=0;i<list.length;i++){
            const it = list[i];
            if (it.id === id){
              console.debug('[Menus] Removing exact match id', id, 'title', it.title);
              // If item has children, promote them to the current level at the same index
              if (it.children && it.children.length){
                const children = it.children;
                // remove the item
                list.splice(i,1);
                // insert children where the item was
                for (let j = 0; j < children.length; j++){
                  list.splice(i + j, 0, children[j]);
                }
              } else {
                list.splice(i,1);
              }
              return { id: id, title: it.title };
            }
            if (it.children && it.children.length){
              const r = Menus.removeInListById(it.children, id);
              if (r) return r;
            }
          }
          return null;
      },
    enableDrag: function(container){ /* already set per-item */ },
    getPathItem: function(path){ let ref = Menus.state.menus[Menus.state.currentMenu].items; for(let i=0;i<path.length;i++){ ref = (i===path.length-1)?ref[path[i]]:ref[path[i]].children; } return ref; },
    deletePathItem: function(path){ let ref = Menus.state.menus[Menus.state.currentMenu].items; for(let i=0;i<path.length-1;i++){ ref = ref[path[i]].children; } ref.splice(path[path.length-1],1); },
    moveItemChild: function(fromPath, toPath){ const from = Menus.getPathItem(fromPath); Menus.deletePathItem(fromPath); let target = Menus.getPathItem(toPath); target.children = target.children||[]; target.children.push(from); Menus.renderMenu(); },
    moveItemBefore: function(fromPath, toPath){ const from = Menus.getPathItem(fromPath); Menus.deletePathItem(fromPath); let parent = Menus.getParentOfPath(toPath); const idx = toPath[toPath.length-1]; parent.splice(idx, 0, from); Menus.renderMenu(); },
    moveItemAfter: function(fromPath, toPath){ const from = Menus.getPathItem(fromPath); Menus.deletePathItem(fromPath); let parent = Menus.getParentOfPath(toPath); const idx = toPath[toPath.length-1]; parent.splice(idx+1, 0, from); Menus.renderMenu(); },
    getParentOfPath: function(path){ let list = Menus.state.menus[Menus.state.currentMenu].items; for (let i=0;i<path.length-1;i++){ list = list[path[i]].children; } return list; },
    // ID-based move helpers
    findItemAndParentById: function(id){ const cur = Menus.state.currentMenu; if (!cur) return null; const items = Menus.state.menus[cur].items;
      const stack = [{ parent: null, list: items }];
      while (stack.length){ const { parent, list } = stack.pop(); for (let i=0;i<list.length;i++){ const it = list[i]; if (it.id === id) return { parent, list, index: i, item: it }; if (it.children && it.children.length) stack.push({ parent: it, list: it.children }); } }
      return null;
    },
    moveItemChildById: function(fromId, toId){ const from = Menus.findItemAndParentById(fromId); const to = Menus.findItemAndParentById(toId); if (!from || !to) return; from.list.splice(from.index,1); to.item.children = to.item.children||[]; to.item.children.push(from.item); Menus.renderMenu(); },
    moveItemBeforeById: function(fromId, toId){ const from = Menus.findItemAndParentById(fromId); const to = Menus.findItemAndParentById(toId); if (!from || !to) return; // remove
      from.list.splice(from.index,1);
      const parentList = to.parent ? (to.parent.children||[]) : Menus.state.menus[Menus.state.currentMenu].items;
      if (!to.parent) { /* top-level already captured */ }
      const insertList = to.list; const idx = to.index; insertList.splice(idx, 0, from.item); Menus.renderMenu(); },
    moveItemAfterById: function(fromId, toId){ const from = Menus.findItemAndParentById(fromId); const to = Menus.findItemAndParentById(toId); if (!from || !to) return; from.list.splice(from.index,1); const insertList = to.list; const idx = to.index; insertList.splice(idx+1, 0, from.item); Menus.renderMenu(); },
    duplicateItem: function(path){ const item = JSON.parse(JSON.stringify(Menus.getPathItem(path))); item.id = Menus.newId(); let parent = Menus.getParentOfPath(path); const idx = path[path.length-1]; parent.splice(idx+1, 0, item); Menus.renderMenu(); },
    newId: function(){ return 'm_' + Date.now().toString(36) + Math.random().toString(36).slice(2,8); }
  };
  $(function(){
    var ajaxFromWindow = (typeof window.ajaxurl === 'string' && window.ajaxurl) ? window.ajaxurl : null;
    var dataAjax = $('#menuSelect').closest('[data-ajax-url]').data('ajax-url');
    // Fallback: compute base by removing last path segment (so /.../manager/menus.php -> /.../manager)
    var base = window.location.pathname.replace(/\/[^/]+$/,'');
    var ajax = ajaxFromWindow || dataAjax || (base + '/ajax.php');
    Menus.init(ajax);
    $('#createMenuBtn').after('<button class="btn btn-sm btn-outline-primary ms-2" id="duplicateMenuBtn">Duplicate</button>');
  });
})(jQuery);
