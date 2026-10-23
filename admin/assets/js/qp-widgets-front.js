/* Appearance > Widgets (core upgrade plan C6, part A). Vanilla JS.
   The server returns the full state after every change, so the screen is always drawn from what is stored. */
(function () {
  'use strict';
  var root = document.getElementById('qpwf');
  if (!root) return;
  var cfg = JSON.parse(root.getAttribute('data-config'));
  var state = cfg.state;
  var typesEl = document.getElementById('qpwf-types');
  var areasEl = document.getElementById('qpwf-areas');
  var msg = document.getElementById('qpwf-msg');
  var openId = null;      // the widget whose settings are open
  var dragId = null;

  function say(t, bad) { msg.textContent = t || ''; msg.className = 'small mb-2 ' + (bad ? 'text-danger' : 'text-muted'); }
  function el(tag, cls, text) { var e = document.createElement(tag); if (cls) e.className = cls; if (text != null) e.textContent = text; return e; }

  function post(action, data) {
    var body = new URLSearchParams();
    body.set('action', action);
    body.set('_n', cfg.nonce);
    Object.keys(data || {}).forEach(function (k) { body.set(k, data[k]); });
    return fetch(cfg.ajax, { method: 'POST', credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: body })
      .then(function (r) { return r.json().catch(function () { return { status: 'error', message: 'Unexpected reply.' }; }); });
  }

  function apply(r, okText) {
    if (r.status === 'success') { if (r.state) state = r.state; say(okText || 'Saved.'); draw(); } else say(r.message || 'Could not save.', true);
    return r;
  }

  function findItem(id) {
    var all = [];
    state.areas.forEach(function (a) { all = all.concat(a.widgets); });
    all = all.concat(state.inactive);
    return all.filter(function (w) { return w.id === id; })[0] || null;
  }

  // ---- the layout sent to the server, built from the current state
  function layoutOf(s) {
    var l = { areas: {}, inactive: [] };
    s.areas.forEach(function (a) { l.areas[a.id] = a.widgets.map(function (w) { return w.id; }); });
    // widgets parked because the theme lacks their area stay where they are (they have `from`)
    s.inactive.forEach(function (w) { if (!w.from) l.inactive.push(w.id); });
    return l;
  }

  function relocate(id, areaId, index) {
    var s = JSON.parse(JSON.stringify(state));
    var item = null;
    s.areas.forEach(function (a) { a.widgets = a.widgets.filter(function (w) { if (w.id === id) { item = w; return false; } return true; }); });
    s.inactive = s.inactive.filter(function (w) { if (w.id === id) { item = w; return false; } return true; });
    if (!item) return;
    delete item.from;
    if (areaId === 'inactive') { if (index == null || index > s.inactive.length) index = s.inactive.length; s.inactive.splice(index, 0, item); }
    else {
      var a = s.areas.filter(function (x) { return x.id === areaId; })[0];
      if (!a) return;
      if (index == null || index > a.widgets.length) index = a.widgets.length;
      a.widgets.splice(index, 0, item);
    }
    post('qpw_front_layout', { layout: JSON.stringify(layoutOf(s)) }).then(function (r) { apply(r, 'Saved.'); });
  }

  // ---- drawing
  function drawTypes() {
    typesEl.innerHTML = '';
    var targets = state.areas.map(function (a) { return [a.id, a.name]; }).concat([['inactive', 'Inactive widgets']]);
    state.types.forEach(function (t) {
      var row = el('div', 'qpwf-type');
      var info = el('div'); info.appendChild(el('strong', null, t.name)); if (t.description) info.appendChild(el('small', null, t.description));
      row.appendChild(info);
      var sel = el('select', 'form-control form-control-sm w-auto');
      sel.setAttribute('aria-label', 'Add ' + t.name + ' to');
      targets.forEach(function (x) { var o = el('option', null, x[1]); o.value = x[0]; sel.appendChild(o); });
      var btn = el('button', 'btn btn-sm btn-primary', 'Add'); btn.type = 'button';
      btn.addEventListener('click', function () {
        post('qpw_front_add', { type: t.id, area: sel.value }).then(function (r) { if (r.status === 'success') openId = r.id; apply(r, t.name + ' added.'); });
      });
      var grp = el('div', 'd-flex'); grp.appendChild(sel); grp.appendChild(btn);
      row.appendChild(grp);
      typesEl.appendChild(row);
    });
  }

  function drawItem(w, areaId, index, count) {
    var li = el('li', 'qpwf-item'); li.setAttribute('data-id', w.id);
    var head = el('div', 'qpwf-item-head');
    var grab = el('span', 'text-muted', '⋮⋮'); grab.style.cursor = 'grab'; grab.title = 'Drag to move'; grab.setAttribute('aria-hidden', 'true');
    grab.addEventListener('mousedown', function () { li.setAttribute('draggable', 'true'); });
    li.addEventListener('dragstart', function (e) { if (li.getAttribute('draggable') !== 'true') return; dragId = w.id; e.dataTransfer.effectAllowed = 'move'; try { e.dataTransfer.setData('text/plain', w.id); } catch (x) {} setTimeout(function () { li.classList.add('is-dragging'); }, 0); });
    li.addEventListener('dragend', function () { li.classList.remove('is-dragging'); li.setAttribute('draggable', 'false'); dragId = null; });
    head.appendChild(grab);
    var name = el('span', 'qpwf-name'); name.appendChild(el('strong', null, w.name));
    if (w.title) name.appendChild(el('em', null, ' — ' + w.title));
    if (w.missing) name.appendChild(el('em', null, ' (its plugin is not active)'));
    if (w.from) name.appendChild(el('em', null, ' (waiting for area “' + w.from + '”)'));
    head.appendChild(name);
    function mini(label, aria, fn, disabled) {
      var b = el('button', 'btn btn-sm btn-outline-secondary', label); b.type = 'button'; b.setAttribute('aria-label', aria + ' ' + w.name); b.disabled = !!disabled;
      b.addEventListener('click', fn); head.appendChild(b); return b;
    }
    mini('↑', 'Move up', function () { relocate(w.id, areaId, index - 1); }, index === 0);
    mini('↓', 'Move down', function () { relocate(w.id, areaId, index + 1); }, index >= count - 1);
    var mv = el('select', 'form-control form-control-sm w-auto'); mv.setAttribute('aria-label', 'Move ' + w.name + ' to');
    var first = el('option', null, 'Move to…'); first.value = ''; mv.appendChild(first);
    state.areas.concat([{ id: 'inactive', name: 'Inactive widgets' }]).forEach(function (a) { if (a.id !== areaId) { var o = el('option', null, a.name); o.value = a.id; mv.appendChild(o); } });
    mv.addEventListener('change', function () { if (mv.value) relocate(w.id, mv.value, null); });
    head.appendChild(mv);
    var toggle = mini(openId === w.id ? 'Close' : 'Edit', openId === w.id ? 'Close' : 'Edit', function () { openId = openId === w.id ? null : w.id; draw(); });
    toggle.setAttribute('aria-expanded', openId === w.id ? 'true' : 'false');
    li.appendChild(head);
    if (openId === w.id && !w.missing) {
      var body = el('div', 'qpwf-item-body', 'Loading…');
      li.appendChild(body);
      post('qpw_front_form', { id: w.id }).then(function (r) {
        if (r.status !== 'success') { body.textContent = r.message || 'Could not load.'; return; }
        body.innerHTML = '';
        var form = document.createElement('form');
        form.innerHTML = r.html;
        var cond = r.cond || {};
        var box = el('fieldset', 'qpwf-cond border-top pt-2 mt-2');
        box.appendChild(el('legend', 'h6', 'Show this widget'));
        var auth = el('div', 'mb-1');
        [['any', 'to everyone'], ['in', 'only when signed in'], ['out', 'only when signed out']].forEach(function (a, i) {
          var lab = el('label'); var inp = document.createElement('input'); inp.type = 'radio'; inp.name = 'c_auth'; inp.value = a[0]; inp.checked = (cond.auth || 'any') === a[0];
          lab.appendChild(inp); lab.appendChild(document.createTextNode(' ' + a[1])); auth.appendChild(lab);
        });
        box.appendChild(auth);
        var pg = el('div', 'mb-1'); pg.appendChild(el('span', 'small text-muted mr-2', 'On these pages (none ticked = everywhere):'));
        [['front', 'Front page'], ['single', 'Single post/page'], ['page', 'Pages'], ['archive', 'Archives'], ['search', 'Search'], ['notfound', 'Not found']].forEach(function (p) {
          var lab = el('label'); var inp = document.createElement('input'); inp.type = 'checkbox'; inp.name = 'c_pages'; inp.value = p[0]; inp.checked = (cond.pages || []).indexOf(p[0]) >= 0;
          lab.appendChild(inp); lab.appendChild(document.createTextNode(' ' + p[1])); pg.appendChild(lab);
        });
        box.appendChild(pg);
        var pt = el('div', 'mb-2'); pt.appendChild(el('span', 'small text-muted mr-2', 'On these content types (none = any):'));
        (r.post_types || []).forEach(function (k) {
          var lab = el('label'); var inp = document.createElement('input'); inp.type = 'checkbox'; inp.name = 'c_pt'; inp.value = k; inp.checked = (cond.post_types || []).indexOf(k) >= 0;
          lab.appendChild(inp); lab.appendChild(document.createTextNode(' ' + (cfg.postTypes[k] || k))); pt.appendChild(lab);
        });
        box.appendChild(pt);
        form.appendChild(box);
        var save = el('button', 'btn btn-sm btn-primary', 'Save'); save.type = 'submit';
        var del = el('button', 'btn btn-sm btn-outline-danger ml-2', 'Delete'); del.type = 'button';
        form.appendChild(save); form.appendChild(del);
        form.addEventListener('submit', function (e) {
          e.preventDefault();
          var data = { id: w.id }, c = { pages: [], post_types: [] };
          new FormData(form).forEach(function (v, k) {
            var m = k.match(/^settings\[(.+)\]$/);
            if (m) data['settings[' + m[1] + ']'] = v;
            else if (k === 'c_auth') c.auth = v;
            else if (k === 'c_pages') c.pages.push(v);
            else if (k === 'c_pt') c.post_types.push(v);
          });
          data.cond = JSON.stringify(c);
          post('qpw_front_save', data).then(function (r2) { apply(r2, 'Widget saved.'); });
        });
        var armed = false;
        del.addEventListener('click', function () {
          if (!armed) { armed = true; del.textContent = 'Click again to delete'; setTimeout(function () { armed = false; del.textContent = 'Delete'; }, 4000); return; }
          post('qpw_front_delete', { id: w.id }).then(function (r2) { openId = null; apply(r2, 'Widget deleted.'); });
        });
        body.appendChild(form);
      });
    } else if (openId === w.id && w.missing) {
      var b2 = el('div', 'qpwf-item-body'); var d2 = el('button', 'btn btn-sm btn-outline-danger', 'Delete'); d2.type = 'button';
      d2.addEventListener('click', function () { post('qpw_front_delete', { id: w.id }).then(function (r2) { openId = null; apply(r2, 'Widget deleted.'); }); });
      b2.appendChild(d2); li.appendChild(b2);
    }
    return li;
  }

  function drawList(areaId, widgets) {
    var ul = el('ul', 'qpwf-list'); ul.setAttribute('data-area', areaId);
    if (!widgets.length) ul.appendChild(el('li', 'qpwf-empty', 'Nothing here yet.'));
    widgets.forEach(function (w, i) { ul.appendChild(drawItem(w, areaId, i, widgets.length)); });
    ul.addEventListener('dragover', function (e) {
      if (!dragId) return; e.preventDefault(); ul.classList.add('is-over');
    });
    ul.addEventListener('dragleave', function () { ul.classList.remove('is-over'); });
    ul.addEventListener('drop', function (e) {
      e.preventDefault(); ul.classList.remove('is-over');
      if (!dragId) return;
      var idx = widgets.length;
      var items = ul.querySelectorAll('.qpwf-item');
      for (var i = 0; i < items.length; i++) {
        var r = items[i].getBoundingClientRect();
        if (e.clientY < r.top + r.height / 2) { idx = i; break; }
      }
      var id = dragId; dragId = null;
      // moving down inside the same list: the removed item shifts the index
      var cur = widgets.map(function (w) { return w.id; }).indexOf(id);
      if (cur >= 0 && cur < idx) idx--;
      relocate(id, areaId, idx);
    });
    return ul;
  }

  function drawAreas() {
    areasEl.innerHTML = '';
    state.areas.forEach(function (a) {
      var card = el('div', 'card qpwf-area');
      var h = el('div', 'card-header'); h.appendChild(el('strong', null, a.name)); if (a.description) h.appendChild(el('div', 'small text-muted', a.description));
      card.appendChild(h); card.appendChild(drawList(a.id, a.widgets)); areasEl.appendChild(card);
    });
    var card = el('div', 'card qpwf-area');
    var h = el('div', 'card-header'); h.appendChild(el('strong', null, 'Inactive widgets')); h.appendChild(el('div', 'small text-muted', 'Widgets kept here are not shown. Widgets whose area the theme does not provide wait here and come back if the area returns.'));
    card.appendChild(h); card.appendChild(drawList('inactive', state.inactive)); areasEl.appendChild(card);
  }

  function draw() { drawTypes(); drawAreas(); }
  draw();
})();
