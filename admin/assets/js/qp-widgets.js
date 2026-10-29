/* Admin dashboard widgets (core upgrade plan C6, part B). Vanilla JS; no jQuery needed.
   Boxes move with the mouse (drag the handle), with the arrow buttons (keyboard and phone), and fold with the toggle.
   The server stays the authority: it refuses a layout that hides a box that may not be hidden. */
(function () {
  'use strict';
  var wrap = document.getElementById('qpw-wrap');
  if (!wrap) return;
  var cfg = JSON.parse(wrap.getAttribute('data-config'));
  var grid = document.getElementById('qpw-grid');
  var msg = document.getElementById('qpw-msg');
  var saveTimer = null;

  function say(text, bad) {
    if (!msg) return;
    msg.textContent = text || '';
    msg.className = 'small mt-2 ' + (bad ? 'text-danger' : 'text-muted');
  }

  function post(action, data) {
    var body = new URLSearchParams();
    body.set('action', action);
    body.set('_n', cfg.nonce);
    body.set('screen', cfg.screen);
    Object.keys(data || {}).forEach(function (k) {
      var v = data[k];
      if (v && typeof v === 'object' && !(v instanceof String)) {
        Object.keys(v).forEach(function (kk) { body.set(k + '[' + kk + ']', v[kk]); });
      } else body.set(k, v);
    });
    return fetch(cfg.ajax, { method: 'POST', credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: body })
      .then(function (r) { return r.json().catch(function () { return { status: 'error', message: 'Unexpected reply.' }; }); });
  }

  function boxes() { return Array.prototype.slice.call(grid.querySelectorAll('.qpw-box')); }
  function cols() { return Array.prototype.slice.call(grid.querySelectorAll('.qpw-col')); }

  function layout() {
    var l = { cols: cols().length, order: [], hidden: [], collapsed: [] };
    cols().forEach(function (c) {
      l.order.push(Array.prototype.map.call(c.querySelectorAll('.qpw-box'), function (b) { return b.getAttribute('data-id'); }));
    });
    Array.prototype.forEach.call(wrap.querySelectorAll('.qpw-banner[data-hidden]'), function (b) { l.hidden.push(b.getAttribute('data-id')); });
    boxes().forEach(function (b) {
      if (b.hasAttribute('data-hidden')) l.hidden.push(b.getAttribute('data-id'));
      if (b.classList.contains('is-collapsed')) l.collapsed.push(b.getAttribute('data-id'));
    });
    return l;
  }

  function save() {
    clearTimeout(saveTimer);
    saveTimer = setTimeout(function () {
      say('Saving…');
      post('qpw_layout_save', { layout: JSON.stringify(layout()) }).then(function (r) {
        if (r.status === 'success') say('Layout saved.');
        else { say(r.message || 'Could not save.', true); }
      }).catch(function () { say('Could not reach the server.', true); });
    }, 350);
  }

  // ---- lazy content
  function loadBody(box) {
    var lazy = box.querySelector('.qpw-lazy[data-lazy]');
    if (!lazy || lazy.getAttribute('data-loading')) return;
    lazy.setAttribute('data-loading', '1');
    post('qpw_widget_load', { id: box.getAttribute('data-id') }).then(function (r) {
      var body = box.querySelector('.qpw-body');
      if (r.status === 'success') body.innerHTML = r.html;
      else body.innerHTML = '<p class="text-muted mb-0">This box could not load.</p>';
    }).catch(function () {
      box.querySelector('.qpw-body').innerHTML = '<p class="text-muted mb-0">This box could not load.</p>';
    });
  }
  function loadAllVisible() {
    boxes().forEach(function (b) {
      if (!b.hasAttribute('data-hidden') && !b.classList.contains('is-collapsed')) loadBody(b);
    });
  }

  // ---- columns
  function setCols(n) {
    var cur = cols();
    while (cur.length < n) {
      var d = document.createElement('div');
      d.className = 'qpw-col';
      grid.appendChild(d);
      cur = cols();
    }
    while (cur.length > n) {
      var last = cur.pop();
      while (last.firstChild) cur[cur.length - 1].appendChild(last.firstChild);
      grid.removeChild(last);
    }
    cols().forEach(function (c, i) { c.setAttribute('data-col', i); });
    grid.className = 'qpw-grid qpw-cols-' + n;
    var bn = document.getElementById('qpw-banners');
    if (bn) bn.className = 'qpw-grid qpw-banners qpw-cols-' + n;
  }

  // ---- rich text inside boxes: moving an editor's iframe in the page resets it, so take editors down first and put them back after
  function editorsDown(box) { try { if (window.tinymce) tinymce.triggerSave(); if (window.qpmetaDestroyTiny && window.jQuery) window.qpmetaDestroyTiny(window.jQuery(box)); } catch (e) { /* editor not ready */ } }
  function editorsUp(box) { try { if (window.qpmetaInitTiny && window.jQuery && !box.hidden) window.qpmetaInitTiny(window.jQuery(box)); } catch (e) { /* ignore */ } }

  // ---- moving
  function move(box, dir) {
    editorsDown(box);
    var moved = movePlace(box, dir);
    editorsUp(box);
    return moved;
  }
  function movePlace(box, dir) {
    var col = box.parentNode;
    var all = cols();
    var ci = all.indexOf(col);
    if (dir === 'up' && box.previousElementSibling) col.insertBefore(box, box.previousElementSibling);
    else if (dir === 'down' && box.nextElementSibling) col.insertBefore(box.nextElementSibling, box);
    else if ((dir === 'left' && ci > 0) || (dir === 'right' && ci < all.length - 1)) {
      var target = all[ci + (dir === 'left' ? -1 : 1)];
      var ref = target.children[Math.min(Array.prototype.indexOf.call(col.children, box), target.children.length)] || null;
      target.insertBefore(box, ref);
    } else return false;
    say((box.querySelector('.qpw-title').textContent) + ' moved.');
    save();
    return true;
  }

  grid.addEventListener('click', function (e) {
    var t = e.target.closest('button');
    if (!t) return;
    var box = t.closest('.qpw-box');
    if (!box) return;
    if (t.classList.contains('qpw-move')) {
      var moved = move(box, t.getAttribute('data-dir'));
      if (moved) { var again = box.querySelector('.qpw-move[data-dir="' + t.getAttribute('data-dir') + '"]'); if (again) again.focus(); }
    } else if (t.classList.contains('qpw-toggle')) {
      var c = box.classList.toggle('is-collapsed');
      box.querySelector('.qpw-body').hidden = c;
      t.setAttribute('aria-expanded', c ? 'false' : 'true');
      if (!c) { loadBody(box); editorsUp(box); }
      save();
    } else if (t.classList.contains('qpw-gear')) {
      var f = box.querySelector('.qpw-settings');
      f.hidden = !f.hidden;
      t.setAttribute('aria-expanded', f.hidden ? 'false' : 'true');
    }
  });

  // settings form of a configurable box
  grid.addEventListener('submit', function (e) {
    var form = e.target;
    if (form.classList.contains('qpw-settings')) {
      e.preventDefault();
      var box = form.closest('.qpw-box');
      var s = {};
      new FormData(form).forEach(function (v, k) { var m = k.match(/^settings\[(.+)\]$/); if (m) s[m[1]] = v; });
      post('qpw_widget_settings', { id: box.getAttribute('data-id'), settings: s }).then(function (r) {
        if (r.status !== 'success') { say(r.message || 'Could not save.', true); return; }
        say('Settings saved.');
        form.hidden = true;
        var body = box.querySelector('.qpw-body');
        body.innerHTML = '<div class="qpw-lazy text-muted small" data-lazy="1">Loading…</div>';
        loadBody(box);
      });
    }
  });

  // ---- drag and drop (mouse); the arrow buttons cover keyboard and touch
  var dragging = null;
  grid.addEventListener('mousedown', function (e) {
    var h = e.target.closest('.qpw-handle');
    if (h) h.closest('.qpw-box').setAttribute('draggable', 'true');
  });
  grid.addEventListener('dragstart', function (e) {
    var box = e.target.closest && e.target.closest('.qpw-box');
    if (!box || box.getAttribute('draggable') !== 'true') return;
    dragging = box;
    e.dataTransfer.effectAllowed = 'move';
    try { e.dataTransfer.setData('text/plain', box.getAttribute('data-id')); } catch (x) { /* old browsers */ }
    editorsDown(box);
    setTimeout(function () { box.classList.add('is-dragging'); }, 0);
  });
  grid.addEventListener('dragover', function (e) {
    if (!dragging) return;
    var col = e.target.closest('.qpw-col');
    if (!col) return;
    e.preventDefault();
    var after = null;
    Array.prototype.some.call(col.querySelectorAll('.qpw-box:not(.is-dragging):not([hidden])'), function (b) {
      var r = b.getBoundingClientRect();
      if (e.clientY < r.top + r.height / 2) { after = b; return true; }
      return false;
    });
    if (after) col.insertBefore(dragging, after); else col.appendChild(dragging);
  });
  grid.addEventListener('dragend', function () {
    if (!dragging) return;
    dragging.classList.remove('is-dragging');
    dragging.setAttribute('draggable', 'false');
    editorsUp(dragging);
    dragging = null;
    save();
  });

  // ---- banners: dismiss = hide (saved like a hidden box; Screen options brings it back)
  wrap.addEventListener('click', function (e) {
    var d = e.target.closest('.qpw-dismiss');
    if (!d) return;
    var b = d.closest('.qpw-banner');
    b.hidden = true; b.setAttribute('data-hidden', '1');
    var cb = document.getElementById('qpw-show-' + b.getAttribute('data-id'));
    if (cb) cb.checked = false;
    say('Dismissed. Screen options brings it back.');
    save();
  });

  // ---- screen options
  var optBtn = document.getElementById('qpw-screen-options');
  var optPanel = document.getElementById('qpw-options');
  optBtn.addEventListener('click', function () {
    optPanel.hidden = !optPanel.hidden;
    optBtn.setAttribute('aria-expanded', optPanel.hidden ? 'false' : 'true');
  });
  document.getElementById('qpw-cols').addEventListener('change', function () { setCols(parseInt(this.value, 10)); save(); });
  optPanel.addEventListener('change', function (e) {
    if (!e.target.classList.contains('qpw-show')) return;
    var box = wrap.querySelector('[data-id="' + e.target.getAttribute('data-id') + '"].qpw-box, [data-id="' + e.target.getAttribute('data-id') + '"].qpw-banner');
    if (!box) return;
    if (e.target.checked) { box.hidden = false; box.removeAttribute('data-hidden'); if (box.classList.contains('qpw-box') && !box.classList.contains('is-collapsed')) { loadBody(box); editorsUp(box); } }
    else { box.hidden = true; box.setAttribute('data-hidden', '1'); }
    save();
  });
  var resetBtn = document.getElementById('qpw-reset');
  var resetArmed = false;
  resetBtn.addEventListener('click', function () {
    if (!resetArmed) { resetArmed = true; resetBtn.textContent = 'Click again to reset'; setTimeout(function () { resetArmed = false; resetBtn.textContent = 'Reset layout'; }, 4000); return; }
    post('qpw_layout_reset', {}).then(function () { location.reload(); });
  });
  var defBtn = document.getElementById('qpw-save-default');
  if (defBtn) defBtn.addEventListener('click', function () {
    post('qpw_layout_default', { layout: JSON.stringify(layout()) }).then(function (r) { say(r.status === 'success' ? 'Saved as the starting layout for users who have not arranged their own.' : (r.message || 'Could not save.'), r.status !== 'success'); });
  });

  loadAllVisible();
})();
