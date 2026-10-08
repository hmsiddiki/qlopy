<?php
require_once __DIR__ . '/admin_head.php';
$page_title = $page_title ?? 'Mail';
if ( !current_user_can('manage_options')) {
    http_response_code(403);
    require_once __DIR__ . '/inc/header.php';
    require_once __DIR__ . '/inc/navbar.php';
    echo '<div class="container"><h1>Permission Denied</h1><p>You do not have permission to access this admin page.</p></div>';
    include __DIR__ . '/inc/footer.php';
    exit;
}
require_once __DIR__ . '/inc/header.php';
// Minimal Mail admin UI - Settings / Queue / Logs (placeholders)
// Determine active tab from GET
$allowed_tabs = ['settings','queue','logs'];
$active_tab = 'settings';
if (!empty($_GET['tab']) && in_array($_GET['tab'], $allowed_tabs, true)) {
    $active_tab = $_GET['tab'];
}
?>
<div class="wrap container">
    <h1>Mail</h1>
    <div id="qp-mail-migration-banner" class="alert alert-warning" style="display:none; margin-top:.5rem">
        Mail DB tables appear to be missing: <strong id="qp-mail-missing-list"></strong>
        <button class="btn btn-sm btn-primary" id="qp-mail-migrate-banner-btn" style="margin-left:.5rem">Apply Migrations</button>
    </div>
    <ul class="nav nav-tabs" id="qp-mail-tabs">
        <li class="nav-item"><a class="nav-link <?php echo $active_tab==='settings' ? 'active' : ''; ?>" href="?tab=settings">Settings</a></li>
        <li class="nav-item"><a class="nav-link <?php echo $active_tab==='queue' ? 'active' : ''; ?>" href="?tab=queue">Queue</a></li>
        <li class="nav-item"><a class="nav-link <?php echo $active_tab==='logs' ? 'active' : ''; ?>" href="?tab=logs">Logs</a></li>
    </ul>

    <div id="qp-mail-content" style="margin-top:1rem">
        <div data-pane="settings" style="display:<?php echo $active_tab==='settings' ? 'block' : 'none'; ?>">
            <form id="qp-mail-settings-form">
                <?php qp_nonce_field('qp_mail_settings', 3600); ?>
                <?php if (function_exists('admin_nonce_field')) { echo admin_nonce_field('qp_mail_settings'); } ?>
                <div class="form-row">
                    <div class="form-group col-md-6">
                        <label>Transport</label>
                        <select name="transport" class="form-control">
                            <option value="mail">PHP mail()</option>
                            <option value="smtp">SMTP</option>
                        </select>
                    </div>
                    <div class="form-group col-md-6">
                        <label>From Email</label>
                        <input type="email" name="from_email" class="form-control">
                    </div>
                </div>

                <div id="qp-smtp-settings" style="display:none" class="mt-3 border p-3 rounded">
                    <h5>SMTP Settings</h5>
                    <div class="form-row">
                        <div class="form-group col-md-6"><label>SMTP Host</label><input name="smtp_host" class="form-control"></div>
                        <div class="form-group col-md-2"><label>Port</label><input name="smtp_port" class="form-control"></div>
                        <div class="form-group col-md-4"><label>Encryption</label>
                            <select name="smtp_encryption" class="form-control"><option value="">None</option><option value="tls">TLS</option><option value="ssl">SSL</option></select>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group col-md-6"><label>Username</label><input name="smtp_username" class="form-control"></div>
                        <div class="form-group col-md-6"><label>Password</label><input name="smtp_password" type="password" class="form-control" autocomplete="new-password"></div>
                    </div>
                    <div class="form-row">
                        <div class="form-group col-md-4"><label>Auth Method</label>
                            <select name="smtp_auth" class="form-control"><option value="1">Login</option><option value="0">None</option></select>
                        </div>
                        <div class="form-group col-md-4"><label>From Name</label><input name="from_name" class="form-control"></div>
                        <div class="form-group col-md-4"><label>Reply-To</label><input name="reply_to" class="form-control"></div>
                    </div>
                </div>

                <div class="mt-3 border p-3 rounded">
                    <h5>OAuth2 (Gmail/Office365)</h5>
                    <div class="form-row">
                        <div class="form-group col-md-4"><label>Client ID</label><input name="oauth_client_id" class="form-control"></div>
                        <div class="form-group col-md-3"><label>Client Secret</label><input name="oauth_client_secret" type="password" class="form-control"></div>
                        <div class="form-group col-md-3"><label>Provider</label>
                            <select name="oauth_provider" class="form-control">
                                <option value="google">Google (Gmail)</option>
                                <option value="microsoft">Microsoft (Office365)</option>
                            </select>
                        </div>
                        <div class="form-group col-md-2" style="display:flex;align-items:flex-end"><button id="qp-oauth-authorize" type="button" class="btn btn-sm btn-outline-primary">Authorize</button></div>
                        <div class="form-group col-md-2" style="display:flex;align-items:flex-end"><button id="qp-oauth-revoke" type="button" class="btn btn-sm btn-outline-danger">Revoke</button></div>
                    </div>
                    <p class="small text-muted">Paste a Refresh Token here if you have one. OAuth flows are handled externally for now.</p>
                    <div class="form-group"><label>Refresh Token</label><input name="oauth_refresh_token" class="form-control"></div>
                    <div class="mt-2"><span id="qp-oauth-status" class="small text-muted"></span></div>
                </div>

                <div class="mt-3 border p-3 rounded">
                    <h5>DKIM</h5>
                    <div class="form-row">
                        <div class="form-group col-md-3"><label>Enable DKIM</label><select name="dkim_enabled" class="form-control"><option value="0">No</option><option value="1">Yes</option></select></div>
                        <div class="form-group col-md-9"><label>DKIM Selector</label><input name="dkim_selector" class="form-control"></div>
                    </div>
                    <div class="form-group"><label>Private Key (PEM)</label><textarea name="dkim_private_key" class="form-control" rows="4"></textarea></div>
                    <p class="small text-muted">If provided, messages will be DKIM-signed before sending.</p>
                    <div class="mt-2">
                        <button type="button" id="qp-dkim-record" class="btn btn-sm btn-outline-secondary">Show DKIM DNS record</button>
                        <button type="button" id="qp-dkim-check" class="btn btn-sm btn-outline-secondary" style="margin-left:.5rem">Check DKIM DNS</button>
                        <button type="button" id="qp-dkim-send-test" class="btn btn-sm btn-outline-primary" style="margin-left:.5rem">Send DKIM Test</button>
                        <span id="qp-dkim-record-output" style="margin-left:.5rem"></span>
                        <span id="qp-dkim-check-output" style="margin-left:.5rem"></span>
                    </div>
                </div>

                <div class="mt-3 border p-3 rounded">
                    <h5>Queue / Retry</h5>
                    <div class="form-row">
                        <div class="form-group col-md-3"><label>Use Queue</label><select name="use_queue" class="form-control"><option value="0">No</option><option value="1">Yes</option></select></div>
                        <div class="form-group col-md-3"><label>Batch Size</label><input name="queue_batch" type="number" class="form-control"></div>
                        <div class="form-group col-md-3"><label>Max Attempts</label><input name="queue_max_attempts" type="number" class="form-control"></div>
                        <div class="form-group col-md-3"><label>Rate Limit /min</label><input name="rate_limit_per_minute" type="number" class="form-control"></div>
                    </div>
                </div>

                <div class="mt-3">
                    <button class="btn btn-primary" id="qp-mail-save">Save</button>
                    <button class="btn btn-secondary" id="qp-mail-send-test">Send Test</button>
                    <button class="btn btn-outline-primary" id="qp-mail-run-queue">Run Queue</button>
                    <button class="btn btn-outline-secondary" id="qp-mail-migrate" style="display:none">Apply Migrations</button>
                </div>
            </form>
        </div>
        <div data-pane="queue" style="display:<?php echo $active_tab==='queue' ? 'block' : 'none'; ?>">
            <h4>Queue</h4>
            <div id="qp-mail-queue-list">Loading...</div>
        </div>
        <div data-pane="logs" style="display:<?php echo $active_tab==='logs' ? 'block' : 'none'; ?>">
            <h4>Logs</h4>
            <div id="qp-mail-logs-list">Loading...</div>
        </div>
    </div>

    <script>
    (function(){
        // Debugging disabled in admin UI
        function qpDebugBox(){ return null; }
        function qpDebug(){ /* no-op */ }
            const ajaxUrl = window.ajaxurl || 'ajax.php';
            qpDebug('mail script initialized; ajaxUrl=' + ajaxUrl);
            try {
                const globalNonce = (document.querySelector('input[name="_qp_nonce"]') && document.querySelector('input[name="_qp_nonce"]').value) || '';
                const formNonceEl = document.querySelector('#qp-mail-settings-form input[name="_qp_nonce"]');
                const formNonce = formNonceEl ? formNonceEl.value : '';
                const adminNonce = (document.querySelector('input[name="admin_nonce"]') && document.querySelector('input[name="admin_nonce"]').value) || '';
                qpDebug('global nonce: ' + (globalNonce || 'none'));
                qpDebug('form nonce: ' + (formNonce || 'none'));
                qpDebug('admin_nonce: ' + (adminNonce || 'none'));
                qpDebug('document.cookie: ' + (document.cookie || ''));
            } catch(e) { qpDebug('nonce read error'); }
          // Wrap global fetch and add `credentials: 'same-origin'` for POSTs targeting `ajaxUrl` when absent.
        (function(){
            try {
                const _qp_orig_fetch = window.fetch.bind(window);
                window.fetch = function(url, opts){
                    try {
                        const target = (typeof url === 'string') ? url : (url && url.url) ? url.url : '';
                        if (target === ajaxUrl && opts && opts.method && opts.method.toUpperCase() === 'POST') {
                            if (!opts.credentials) {
                                opts = Object.assign({}, opts, { credentials: 'same-origin' });
                            }
                        }
                    } catch(e) {}
                    return _qp_orig_fetch(url, opts);
                };
            } catch(e) {}
        })();
      
        function qs(sel, ctx=document){return ctx.querySelector(sel);} 
        function qsa(sel, ctx=document){return Array.from(ctx.querySelectorAll(sel));}

        // Robust nonce accessor: prefer a form-scoped id if available, else fallback
        function getAdminNonce() {
            try {
                // Ensure form nonce element has a stable id for unambiguous selection
                const form = document.getElementById('qp-mail-settings-form');
                if (form) {
                    const el = form.querySelector('input[name="_qp_nonce"]');
                    if (el && !el.id) el.id = 'qp-mail-settings-nonce';
                }
                const byId = document.getElementById('qp-mail-settings-nonce');
                if (byId && byId.value) return byId.value;
                // fallback to any input inside form
                const formEl = document.querySelector('#qp-mail-settings-form input[name="_qp_nonce"]');
                if (formEl && formEl.value) return formEl.value;
                // fallback to global anchor
                const any = document.querySelector('input[name="_qp_nonce"]');
                if (any && any.value) return any.value;
                return '';
            } catch(e) { return ''; }
        }

        // Fetch a fresh server-issued nonce for qp_mail_settings
        function getFreshNonce(){
            return fetch(ajaxUrl, { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:'action=qp_mail_get_nonce' })
            .then(r=>r.text()).then(txt=>{ try { const j = JSON.parse(txt); if (j && j.status==='success' && j.nonce) return j.nonce; } catch(e){} return getAdminNonce(); });
        }
        // Tabs are handled by server via ?tab=... links; no client-side toggling needed.

        // Load settings
        function loadSettings(){
            fetch(ajaxUrl, { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body: 'action=qp_mail_get_settings' })
            .then(r=>r.json()).then(j=>{
                if (j.status==='success' && j.settings){
                    for (const k in j.settings) {
                        // Do not pre-fill sensitive secret inputs (passwords, tokens, private keys)
                        if (['smtp_password','oauth_client_secret','oauth_refresh_token','dkim_private_key'].includes(k)) continue;
                        const el = qs('[name="'+k+'"]'); if (el) {
                            try { el.value = j.settings[k]; } catch(e) {}
                        }
                    }
                    // prefill oauth provider select
                    if (j.settings.oauth_provider) {
                        const prov = qs('select[name="oauth_provider"]'); if (prov) prov.value = j.settings.oauth_provider;
                    }
                    // show smtp block if transport is smtp
                    if ((j.settings.transport || 'mail') === 'smtp') qs('select[name="transport"]').value = 'smtp';
                }
                toggleSmtp();
            }).catch(console.error);
        }

            // DKIM record button
            const dkimBtn = qs('#qp-dkim-record');
            if (dkimBtn) dkimBtn.addEventListener('click', function(){
                const out = qs('#qp-dkim-record-output'); out.textContent = 'Checking...';
                fetch(ajaxUrl, { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body: 'action=qp_mail_get_dkim_record' }).then(r=>r.json()).then(j=>{
                    if (j.status==='success') { out.textContent = j.record; } else { out.textContent = 'Not configured'; }
                }).catch(e=>{ out.textContent = 'Error'; });
            });

            const dkimCheckBtn = qs('#qp-dkim-check');
            const dkimCheckOut = qs('#qp-dkim-check-output');
            if (dkimCheckBtn) dkimCheckBtn.addEventListener('click', function(){
                dkimCheckOut.textContent = 'Checking DNS...';
                fetch(ajaxUrl, { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body: 'action=qp_mail_check_dkim_dns' }).then(r=>r.json()).then(j=>{
                    if (j.status==='success') {
                        dkimCheckOut.textContent = j.public_match ? 'OK (public key matches DNS)' : 'Mismatch or not found';
                    } else dkimCheckOut.textContent = 'No DNS records';
                }).catch(e=>{ dkimCheckOut.textContent = 'Error'; });
            });

            const dkimSendBtn = qs('#qp-dkim-send-test');
            if (dkimSendBtn) dkimSendBtn.addEventListener('click', function(){
                const to = prompt('DKIM test recipient', 'postmaster@' + (document.querySelector('input[name="from_email"]').value.split('@')[1] || 'example.com'));
                if (!to) return;
                dkimCheckOut.textContent = 'Sending...';
                getFreshNonce().then(function(nonce){
                    const payload = 'action=qp_mail_send_dkim_test&_qp_nonce=' + encodeURIComponent(nonce) + '&to=' + encodeURIComponent(to);
                    return fetch(ajaxUrl, { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body: payload });
                }).then(r=>r.json()).then(j=>{
                    if (j.status==='success' || j.status==='error') {
                        const w = window.open('about:blank','_blank');
                        w.document.write('<h3>DKIM Test Result</h3><pre>'+ (j.transcript ? j.transcript.replace(/</g,'&lt;') : (j.error||'No transcript')) +'</pre>');
                        dkimCheckOut.textContent = j.status==='success' ? (j.sent ? 'Sent' : 'Failed') : 'Failed';
                    } else {
                        dkimCheckOut.textContent = 'Error sending';
                    }
                }).catch(e=>{ dkimCheckOut.textContent = 'Error'; });
            });

        function toggleSmtp(){ const v = qs('select[name="transport"]').value; qs('#qp-smtp-settings').style.display = v === 'smtp' ? 'block' : 'none'; }
        qs('select[name="transport"]').addEventListener('change', toggleSmtp);

        qs('#qp-mail-save').addEventListener('click', function(e){ e.preventDefault();
            const form = new FormData(qs('#qp-mail-settings-form'));
            const obj = {};
            for (const pair of form.entries()) obj[pair[0]] = pair[1];
            getFreshNonce().then(function(nonce){
                qpDebug('save: nonce=' + nonce + ' el=' + (document.getElementById('qp-mail-settings-nonce') ? document.getElementById('qp-mail-settings-nonce').outerHTML : 'none'));
                const payload = 'action=qp_mail_save_settings&_qp_nonce=' + encodeURIComponent(nonce) + '&settings=' + encodeURIComponent(JSON.stringify(obj));
                return fetch(ajaxUrl, { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body: payload });
            }).then(r=>r.json()).then(j=>{ alert(j.status==='success' ? 'Saved' : 'Error saving: ' + (j.message||'unknown')); }).catch(console.error);
        });

        // OAuth authorize
        const oauthBtn = qs('#qp-oauth-authorize');
        if (oauthBtn) oauthBtn.addEventListener('click', function(){
            const provider = 'google'; // or fetch a select if you add it later
            // include credentials and handle non-JSON responses (login HTML, redirects)
            fetch(ajaxUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=qp_mail_get_oauth_url&provider=' + encodeURIComponent(provider)
            }).then(async (res) => {
                const ct = (res.headers.get('content-type') || '');
                const txt = await res.text();
                if (!res.ok) {
                    let msg = txt;
                    if (ct.indexOf('application/json') !== -1) {
                        try { const parsed = JSON.parse(txt); msg = parsed.message || JSON.stringify(parsed); } catch(e) { msg = txt.slice(0,1000); }
                    }
                    throw new Error('Server returned error (HTTP ' + res.status + '): ' + msg);
                }
                if (ct.indexOf('application/json') === -1) {
                    // probably an HTML login page or redirect — surface it to the admin
                    throw new Error('Expected JSON but received HTML/other. Response preview:\n' + txt.slice(0, 1000));
                }
                try { return JSON.parse(txt); } catch(e) { throw new Error('Invalid JSON response: ' + txt.slice(0,1000)); }
            }).then(j=>{
                if (j.status==='success' && j.url) window.open(j.url,'_blank'); else alert('Unable to build OAuth URL: '+(j.message||'error'));
            }).catch(err=>{
                console.error('OAuth authorize error', err);
                alert('OAuth authorize failed — see console for details.\n' + (err && err.message ? err.message : 'unknown'));
            });
        });

        const revokeBtn = qs('#qp-oauth-revoke');
        const statusSpan = qs('#qp-oauth-status');
        function refreshOauthStatus(){
            fetch(ajaxUrl, { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body: 'action=qp_mail_get_oauth_status' }).then(r=>r.json()).then(j=>{
                if (j.status==='success') {
                    if (j.authorized==1) {
                        const d = j.access_expires_at ? new Date(j.access_expires_at*1000) : null;
                        statusSpan.textContent = 'Authorized. Access token expires: ' + (d? d.toLocaleString() : 'unknown');
                    } else statusSpan.textContent = 'Not authorized.';
                }
            }).catch(()=>{ if (statusSpan) statusSpan.textContent = 'Status unavailable'; });
        }
        if (revokeBtn) revokeBtn.addEventListener('click', function(){
            if (!confirm('Revoke stored OAuth tokens?')) return;
            getFreshNonce().then(function(nonce){
                return fetch(ajaxUrl, { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body: 'action=qp_mail_revoke_oauth&_qp_nonce='+encodeURIComponent(nonce) });
            })
            .then(r=>r.json()).then(j=>{
                if (j.status==='success') {
                    let msg = 'Local tokens cleared.';
                    if (j.revoked_remote) msg += ' Remote token revoked.'; else msg += ' Remote revoke not confirmed.';
                    alert(msg);
                    refreshOauthStatus();
                } else {
                    alert('Revoke failed: ' + (j.message||''));
                }
            }).catch(e=>{ alert('Network error during revoke'); });
        });
        refreshOauthStatus();

        qs('#qp-mail-send-test').addEventListener('click', function(e){ e.preventDefault();
            const to = prompt('Test recipient email', 'no-reply@example.com'); if (!to) return;
            const body = prompt('Message body','This is a test message from qp_mail.');
            getFreshNonce().then(function(nonce){
                qpDebug('send-test: nonce=' + nonce + ' el=' + (document.getElementById('qp-mail-settings-nonce') ? document.getElementById('qp-mail-settings-nonce').outerHTML : 'none'));
                const payload = 'action=qp_mail_send_test&_qp_nonce=' + encodeURIComponent(nonce) + '&to=' + encodeURIComponent(to) + '&body=' + encodeURIComponent(body || '');
                qpDebug('qp_mail: sending test payload ' + payload);
                try { qpDebug('qp_mail: cookies at send: ' + (document.cookie || '')); } catch(e) {}
                return fetch(ajaxUrl, { method:'POST', credentials: 'same-origin', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body: payload });
            })
            .then(async (res) => {
                const ct = (res.headers.get('content-type') || '').toLowerCase();
                const txt = await res.text();
                qpDebug('qp_mail: raw response (status ' + res.status + ') ' + txt.slice(0,2000));
                let j = null;
                if (ct.indexOf('application/json') !== -1) {
                    try { j = JSON.parse(txt); } catch (e) { throw new Error('Invalid JSON response: ' + txt.slice(0,1000)); }
                } else {
                    // try best-effort parse, otherwise show raw text to user
                    try { j = JSON.parse(txt); } catch (e) { alert('Server returned non-JSON response:\n' + txt); throw new Error('Non-JSON response'); }
                }
                if (j && j.status === 'success') {
                    alert('Sent');
                } else {
                    const status = j && j.status ? j.status : 'no-status';
                    const msg = j && j.message ? j.message : (j ? JSON.stringify(j) : 'no-message');
                    alert('Send failed: ' + status + ' ' + msg);
                }
            }).catch(err => { console.error(err); alert('Send failed — network/parse error: ' + (err && err.message ? err.message : 'unknown')); });
        });

        qs('#qp-mail-run-queue').addEventListener('click', function(e){ e.preventDefault();
            getFreshNonce().then(function(nonce){
                const payload = 'action=qp_mail_run_queue&_qp_nonce=' + encodeURIComponent(nonce) + '&limit=50';
                return fetch(ajaxUrl, { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body: payload });
            })
            .then(r=>r.json()).then(j=>{ if (j.status==='success') { alert('Run complete. Sent:'+j.result.sent+' Failed:'+j.result.failed); loadQueue(); loadLogs(); } else alert('Run failed'); }).catch(console.error);
        });

        qs('#qp-mail-migrate').addEventListener('click', function(e){ e.preventDefault();
            if (!confirm('Apply DB migrations for qp_mail?')) return;
            getFreshNonce().then(function(nonce){
                const payload = 'action=qp_mail_apply_migrations&_qp_nonce=' + encodeURIComponent(nonce);
                return fetch(ajaxUrl, { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body: payload });
            }).then(r=>r.json()).then(j=>{ alert(j.status==='success' ? 'Migrations applied' : 'Migration failed'); }).catch(console.error);
        });

        function loadQueue(){ fetch(ajaxUrl, { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body: 'action=qp_mail_get_queue' }).then(r=>r.json()).then(j=>{
            if (j.status==='success') {
                const container = qs('#qp-mail-queue-list'); container.innerHTML = '';
                if (!j.queue || j.queue.length===0) { container.innerHTML = '<div class="alert alert-info">Queue is empty</div>'; return; }
                const table = document.createElement('table'); table.className='table table-sm table-striped';
                const thead = document.createElement('thead'); thead.innerHTML='<tr><th>ID</th><th>Recipients</th><th>Attempts</th><th>Next</th><th>Actions</th></tr>'; table.appendChild(thead);
                const tb = document.createElement('tbody');
                j.queue.forEach(item=>{
                    const tr = document.createElement('tr');
                    tr.innerHTML = '<td>'+item.id+'</td><td>'+ (item.payload? (JSON.parse(item.payload).to||JSON.parse(item.payload).recipients||'') : '') +'</td><td>'+item.attempts+'</td><td>'+item.next_attempt_at+'</td>';
                    const actionsTd = document.createElement('td');
                    const retryBtn = document.createElement('button'); retryBtn.className='btn btn-sm btn-outline-primary mr-1'; retryBtn.textContent='Retry'; retryBtn.addEventListener('click', function(){ if (!confirm('Retry item '+item.id+'?')) return; getFreshNonce().then(function(nonce){ return fetch(ajaxUrl,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'action=qp_mail_retry_item&_qp_nonce='+encodeURIComponent(nonce)+'&id='+encodeURIComponent(item.id)}); }).then(r=>r.json()).then(j=>{ if (j.status==='success') { alert('Retried'); loadQueue(); } else alert('Error'); }); });
                    const delBtn = document.createElement('button'); delBtn.className='btn btn-sm btn-outline-danger'; delBtn.textContent='Delete'; delBtn.addEventListener('click', function(){ if (!confirm('Delete item '+item.id+'?')) return; getFreshNonce().then(function(nonce){ return fetch(ajaxUrl,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'action=qp_mail_delete_item&_qp_nonce='+encodeURIComponent(nonce)+'&id='+encodeURIComponent(item.id)}); }).then(r=>r.json()).then(j=>{ if (j.status==='success') { alert('Deleted'); loadQueue(); } else alert('Error'); }); });
                    actionsTd.appendChild(retryBtn); actionsTd.appendChild(delBtn); tr.appendChild(actionsTd);
                    tb.appendChild(tr);
                }); table.appendChild(tb); container.appendChild(table);
            }
        }).catch(console.error);} 

        function loadLogs(){ fetch(ajaxUrl, { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body: 'action=qp_mail_get_logs' }).then(r=>r.json()).then(j=>{
            if (j.status==='success') {
                const container = qs('#qp-mail-logs-list'); container.innerHTML = '';
                if (!j.logs || j.logs.length===0) { container.innerHTML = '<div class="alert alert-info">No logs</div>'; return; }
                const table = document.createElement('table'); table.className='table table-sm table-striped';
                table.innerHTML = '<thead><tr><th>ID</th><th>Recipients</th><th>Subject</th><th>Status</th><th>When</th><th>Debug</th></tr></thead>';
                const tb = document.createElement('tbody');
                j.logs.forEach(l=>{
                    const tr = document.createElement('tr');
                    tr.innerHTML = '<td>'+l.id+'</td><td>'+l.recipients+'</td><td>'+ (l.subject||'') +'</td><td>'+l.status+'</td><td>'+ (l.created_at_formatted || l.created_at || '') +'</td>';
                    const dbgBtn = document.createElement('button'); dbgBtn.className='btn btn-sm btn-outline-secondary'; dbgBtn.textContent='Transcript'; dbgBtn.addEventListener('click', function(){ fetch(ajaxUrl,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'action=qp_mail_get_debug&log_id='+encodeURIComponent(l.id)}).then(r=>r.json()).then(j=>{ if (j.status==='success') { const w = window.open('about:blank','_blank'); w.document.write('<pre>'+ (j.transcript ? j.transcript.replace(/</g,'&lt;') : 'No transcript') +'</pre>'); } else alert('No transcript'); }); });
                    const td = document.createElement('td'); td.appendChild(dbgBtn); tr.appendChild(td);
                    tb.appendChild(tr);
                }); table.appendChild(tb); container.appendChild(table);
            }
        }).catch(console.error);} 

        function checkMigrations(){
            try {
                fetch(ajaxUrl, { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body: 'action=qp_mail_check_migrations' })
                .then(r=>r.json()).then(j=>{
                    if (j && j.status === 'needs_migration'){
                        const missing = Array.isArray(j.missing) ? j.missing : (j.missing ? [j.missing] : []);
                        const listEl = qs('#qp-mail-missing-list'); if (listEl) listEl.textContent = missing.join(', ');
                        const banner = qs('#qp-mail-migration-banner'); if (banner) banner.style.display = 'block';
                        const migrateBtn = qs('#qp-mail-migrate'); if (migrateBtn) migrateBtn.style.display = 'inline-block';
                        const bannerBtn = qs('#qp-mail-migrate-banner-btn'); if (bannerBtn) bannerBtn.addEventListener('click', function(){ if (migrateBtn) migrateBtn.click(); });
                    }
                }).catch(()=>{});
            } catch(e){}
        }

        const initialTab = <?php echo json_encode($active_tab); ?>;
        if (initialTab === 'settings') {
            loadSettings();
        } else if (initialTab === 'queue') {
            loadQueue();
        } else if (initialTab === 'logs') {
            loadLogs();
        }
        checkMigrations();
    })();
    </script>

<?php require_once __DIR__ . '/inc/footer.php'; ?>
