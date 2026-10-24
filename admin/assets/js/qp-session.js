/* Keeps an open admin page signed in and, when the login has ended, offers a sign-in box over the page so unsaved work is not lost.
 *
 *  - Every 5 minutes, but only if the person used the page since the last check, asks admin/ajax.php (action qp_heartbeat). The server
 *    extends the login on that request.
 *  - When the tab becomes visible again, or the browser comes back online, checks at once: someone returning to a tab that sat idle
 *    sees the sign-in box immediately instead of losing a form on Save.
 *  - A 403 answer means the login has ended: the box (a frame on login.php?interim=1) appears. After signing in, the frame tells this
 *    page and the box closes; the page underneath is untouched.
 */
(function () {
  'use strict';
  if (!window.fetch || !window.ajaxurl) return;
  var INTERVAL = 5 * 60 * 1000, active = true, box = null, busy = false;

  function mark() { active = true; }
  ['keydown', 'mousedown', 'mousemove', 'scroll', 'touchstart'].forEach(function (ev) {
    window.addEventListener(ev, mark, { passive: true });
  });

  function loginUrl() { return String(window.ajaxurl).replace(/ajax\.php.*$/, 'login.php') + '?interim=1'; }

  function ping() {
    if (busy || box) return;
    busy = true;
    var body = new URLSearchParams({ action: 'qp_heartbeat' });
    fetch(window.ajaxurl, { method: 'POST', credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: body })
      .then(function (r) { if (r.status === 403 || r.status === 401 || r.redirected) showBox(); })
      .catch(function () { /* offline or server busy: try again at the next check */ })
      .then(function () { busy = false; });
  }

  function showBox() {
    if (box) return;
    box = document.createElement('div');
    box.setAttribute('role', 'dialog');
    box.setAttribute('aria-modal', 'true');
    box.setAttribute('aria-label', 'Your session has expired. Sign in to continue.');
    box.style.cssText = 'position:fixed;inset:0;z-index:2147483000;background:rgba(0,0,0,.6);display:flex;align-items:center;justify-content:center;padding:16px';
    var inner = document.createElement('div');
    inner.style.cssText = 'background:#fff;border-radius:6px;max-width:420px;width:100%;box-shadow:0 8px 32px rgba(0,0,0,.3);overflow:hidden';
    var note = document.createElement('p');
    note.style.cssText = 'margin:0;padding:12px 16px;background:#fff3cd;color:#664d03;font:14px/1.4 Arial,sans-serif';
    note.textContent = 'Your session has expired. Sign in again to continue; what you were doing on this page is kept.';
    var frame = document.createElement('iframe');
    frame.src = loginUrl();
    frame.title = 'Sign in';
    frame.style.cssText = 'border:0;width:100%;height:380px;display:block';
    inner.appendChild(note); inner.appendChild(frame); box.appendChild(inner);
    document.body.appendChild(box);
  }

  window.addEventListener('message', function (e) {
    if (e.origin !== window.location.origin || !e.data || e.data.type !== 'qp-reauth') return;
    if (box) { box.parentNode.removeChild(box); box = null; }
    active = true;
  });

  document.addEventListener('visibilitychange', function () { if (!document.hidden) ping(); });
  window.addEventListener('online', ping);
  setInterval(function () { if (active) { active = false; ping(); } }, INTERVAL);
})();
