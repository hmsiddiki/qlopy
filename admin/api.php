<?php
// Settings > API (core upgrade plan C4): which ways of signing in are on, CORS, limits, your keys and application passwords, webhooks.
require_once __DIR__ . '/admin_head.php';
require_once __DIR__ . '/../includes/rest.php';
require_once __DIR__ . '/../includes/rest-auth.php';
require_once __DIR__ . '/../includes/webhooks.php';
require_once __DIR__ . '/../includes/rest-oauth.php';
$page_title = 'API';

$canAdmin = current_user_can('manage_options');
// Everyone who can edit content may manage their own keys and application passwords; settings and webhooks stay with administrators.
if (!$canAdmin && !current_user_can('edit_posts')) {
    http_response_code(403);
    require_once __DIR__ . '/inc/header.php';
    require_once __DIR__ . '/inc/navbar.php';
    echo '<div class="container"><h1>Permission Denied</h1><p>You do not have permission to access this admin page.</p></div>';
    include __DIR__ . '/inc/footer.php';
    exit;
}

$me = get_logged_in_user();
$uid = (int)($me['id'] ?? 0);
$tabsAllowed = $canAdmin ? ['settings', 'keys', 'apps', 'webhooks', 'docs'] : ['keys'];
$tab = in_array($_GET['tab'] ?? $tabsAllowed[0], $tabsAllowed, true) ? ($_GET['tab'] ?? $tabsAllowed[0]) : $tabsAllowed[0];
// Administrators can look after another user's keys (for_user=ID); everyone else only their own.
$target = $uid;
if ($canAdmin && isset($_REQUEST['for_user']) && (int)$_REQUEST['for_user'] > 0 && qp_rest_user_row((int)$_REQUEST['for_user'])) $target = (int)$_REQUEST['for_user'];
$targetRow = qp_rest_user_row($target);
$h = function ($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); };
$s = qp_rest_settings(true);
$secretShown = null;   // a new key or password is shown once, in this response

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['api_action'])) {
    if (!qp_admin_verify_nonce($_POST['api_nonce'] ?? '', 'api_settings')) { qp_set_admin_notice('Invalid request (bad nonce)'); header('Location: api.php?tab=' . $tab); exit; }
    $act = (string)$_POST['api_action'];
    if (!$canAdmin && !in_array($act, ['create_key', 'revoke_key', 'create_app_password', 'revoke_app_password', 'revoke_all_tokens', 'revoke_session'], true)) { qp_set_admin_notice('You may only manage your own keys.'); header('Location: api.php'); exit; }
    $redirect = 'api.php?tab=' . $tab . ($target !== $uid ? '&for_user=' . $target : '');
    if ($act === 'save_settings') {
        $new = $s;
        foreach (['keys', 'app_passwords', 'jwt', 'oauth', 'webhook_allow_private'] as $k) $new[$k] = !empty($_POST[$k]);
        $origins = [];
        foreach (preg_split('/\r\n|\r|\n/', (string)($_POST['cors_origins'] ?? '')) as $o) {
            $o = rtrim(trim($o), '/');
            if ($o !== '' && preg_match('~^https?://[A-Za-z0-9.-]+(:\d{1,5})?$~', $o)) $origins[] = $o;
        }
        $new['cors_origins'] = array_values(array_unique($origins));
        $new['rate_ip'] = max(0, min(100000, (int)($_POST['rate_ip'] ?? 600)));
        $new['rate_key'] = max(0, min(100000, (int)($_POST['rate_key'] ?? 600)));
        $new['max_body'] = max(1024, min(52428800, (int)($_POST['max_body'] ?? 1048576)));
        $new['max_upload'] = max(1048576, min(268435456, (int)($_POST['max_upload'] ?? 10485760)));
        $new['jwt_access_ttl'] = max(60, min(86400, (int)($_POST['jwt_access_ttl'] ?? 900)));
        $new['jwt_refresh_ttl'] = max(3600, min(31536000, (int)($_POST['jwt_refresh_ttl'] ?? 2592000)));
        update_option_meta(QP_REST_DEFAULTS_KEY, $new);
        qp_set_admin_notice('API settings saved.');
    } elseif ($act === 'rotate_jwt') {
        $new = $s;
        $new['jwt_old_kids'] = array_values(array_unique(array_merge([$s['jwt_kid']], (array)$s['jwt_old_kids'])));
        $new['jwt_kid'] = 'k' . (time() % 100000000) . bin2hex(random_bytes(1));
        update_option_meta(QP_REST_DEFAULTS_KEY, $new);
        qp_set_admin_notice('Signing key rotated. Tokens signed with the old key keep working until they expire; use "Drop old keys" afterwards.');
    } elseif ($act === 'drop_old_kids') {
        $new = $s; $new['jwt_old_kids'] = [];
        update_option_meta(QP_REST_DEFAULTS_KEY, $new);
        qp_set_admin_notice('Tokens signed with an old key no longer work.');
    } elseif ($act === 'revoke_session') {
        qp_jwt_revoke_family($target, (string)($_POST['id'] ?? ''));
        qp_set_admin_notice('Session ended.');
    } elseif ($act === 'add_oauth_client') {
        [$cid, $err] = qp_oauth_client_create((string)($_POST['name'] ?? ''), preg_split('/\r\n|\r|\n/', (string)($_POST['redirects'] ?? '')) ?: []);
        if ($err) qp_set_admin_notice('App not saved: ' . $err);
        else $secretShown = ['kind' => 'Client ID', 'value' => $cid, 'how' => 'Give it to the app developer. There is no client secret: apps prove themselves with PKCE.'];
    } elseif ($act === 'delete_oauth_client') {
        qp_oauth_client_delete((string)($_POST['id'] ?? ''));
        qp_set_admin_notice('App removed. Its sessions were ended.');
    } elseif ($act === 'reset_limits') {
        $new = $s; $new['counter_epoch'] = (int)$s['counter_epoch'] + 1;
        update_option_meta(QP_REST_DEFAULTS_KEY, $new);
        qp_set_admin_notice('Rate limits and sign-in lockouts were reset.');
    } elseif ($act === 'revoke_all_tokens') {
        qp_jwt_revoke_all($target);
        qp_set_admin_notice('Token sessions were ended.');
    } elseif ($act === 'create_key') {
        $scopes = array_filter(preg_split('/[\s,]+/', (string)($_POST['scopes'] ?? 'read')), 'qp_rest_valid_scope');
        $rate = ($_POST['rate'] ?? '') === '' ? null : max(1, (int)$_POST['rate']);
        $exp = ($_POST['expires'] ?? '') === '' ? null : strtotime((string)$_POST['expires'] . ' 23:59:59');
        $secretShown = ['kind' => 'API key', 'value' => qp_api_key_create($target, (string)($_POST['name'] ?? ''), $scopes ?: ['read'], $rate, $exp ?: null)['key'], 'how' => 'Send it in the X-API-Key header.'];
    } elseif ($act === 'revoke_key') { qp_api_key_revoke($target, (string)($_POST['id'] ?? '')); qp_set_admin_notice('Key revoked.'); }
    elseif ($act === 'create_app_password') {
        $r = qp_app_password_create($target, (string)($_POST['name'] ?? ''));
        $secretShown = ['kind' => 'Application password', 'value' => $r['password'], 'how' => 'Use it with your username in HTTP Basic authentication. Spaces are optional.'];
    } elseif ($act === 'revoke_app_password') { qp_app_password_revoke($target, (string)($_POST['id'] ?? '')); qp_set_admin_notice('Application password revoked.'); }
    elseif ($act === 'add_webhook') {
        [$id, $err] = qp_webhook_subscribe(trim((string)($_POST['url'] ?? '')), array_map('strval', (array)($_POST['events'] ?? [])));
        if ($err) qp_set_admin_notice('Webhook not saved: ' . $err);
        else { $subs = qp_webhook_subscriptions(); $secretShown = ['kind' => 'Webhook signing secret', 'value' => $subs[$id]['secret'], 'how' => 'Verify the X-QP-Signature header with it. It stays visible on this tab.']; }
    } elseif ($act === 'delete_webhook') { qp_webhook_unsubscribe((string)($_POST['id'] ?? '')); qp_set_admin_notice('Webhook deleted.'); }
    elseif ($act === 'toggle_webhook') {
        $subs = qp_webhook_subscriptions(); $id = (string)($_POST['id'] ?? '');
        if (isset($subs[$id])) { $subs[$id]['active'] = !$subs[$id]['active']; $subs[$id]['fails'] = 0; qp_webhook_save_subscriptions($subs); }
        qp_set_admin_notice('Webhook updated.');
    } elseif ($act === 'test_webhook') {
        [$code, $err] = qp_webhook_test((string)($_POST['id'] ?? ''));
        qp_set_admin_notice($code >= 200 && $code < 300 ? "Test delivered (HTTP $code)." : 'Test failed: ' . ($err ?: "HTTP $code"));
    }
    if ($secretShown === null) { header('Location: ' . $redirect); exit; }
    $s = qp_rest_settings(true);
}

$notice = function_exists('qp_get_admin_notice') ? qp_get_admin_notice() : null;
$nonce = qp_admin_create_nonce('api_settings');
require_once __DIR__ . '/inc/header.php';
require_once __DIR__ . '/inc/navbar.php';
$base = (defined('SITE_URL') ? SITE_URL : '') . '/api/qlopy/v1';
$form = function ($action, $inner, $class = 'd-inline') use ($nonce, $h, $tab, $target) { return '<form method="post" class="' . $class . '"><input type="hidden" name="api_nonce" value="' . $h($nonce) . '"><input type="hidden" name="api_action" value="' . $h($action) . '"><input type="hidden" name="for_user" value="' . (int)$target . '">' . $inner . '</form>'; };
?>
<div class="container mt-4">
  <h2>API</h2>
  <?php if ($notice): ?><div class="alert alert-info"><?= $h($notice) ?></div><?php endif; ?>
  <?php if ($secretShown): ?>
    <div class="alert alert-warning"><strong><?= $h($secretShown['kind']) ?></strong> (shown once, copy it now):
      <pre class="mb-1 mt-2 p-2 bg-white border" style="white-space:pre-wrap;word-break:break-all"><?= $h($secretShown['value']) ?></pre>
      <small><?= $h($secretShown['how']) ?></small></div>
  <?php endif; ?>
  <ul class="nav nav-tabs mb-3">
    <?php foreach (array_intersect_key(['settings' => 'Settings', 'keys' => 'Keys and passwords', 'apps' => 'Apps', 'webhooks' => 'Webhooks', 'docs' => 'Reference'], array_flip($tabsAllowed)) as $k => $label): ?>
      <li class="nav-item"><a class="nav-link<?= $tab === $k ? ' active' : '' ?>" href="api.php?tab=<?= $k ?>"><?= $h($label) ?></a></li>
    <?php endforeach; ?>
  </ul>

<?php if ($tab === 'settings'): ?>
  <p class="text-muted">The API lives at <code><?= $h($base) ?></code>. Signing in from the site's own pages (cookie and nonce) is always on. The ways below are for other apps and are off until you turn them on. All of them need HTTPS.</p>
  <form method="post">
    <input type="hidden" name="api_nonce" value="<?= $h($nonce) ?>"><input type="hidden" name="api_action" value="save_settings">
    <h5>Ways to sign in</h5>
    <div class="form-check"><input class="form-check-input" type="checkbox" id="keys" name="keys" value="1"<?= $s['keys'] ? ' checked' : '' ?>><label class="form-check-label" for="keys"><strong>API keys</strong>: a long secret with scopes, shown once, can be revoked, has its own rate limit.</label></div>
    <div class="form-check"><input class="form-check-input" type="checkbox" id="app_passwords" name="app_passwords" value="1"<?= $s['app_passwords'] ? ' checked' : '' ?>><label class="form-check-label" for="app_passwords"><strong>Application passwords</strong>: your username plus a separate password for one app (HTTP Basic). Your account password never works here.</label></div>
    <div class="form-check"><input class="form-check-input" type="checkbox" id="jwt" name="jwt" value="1"<?= $s['jwt'] ? ' checked' : '' ?>><label class="form-check-label" for="jwt"><strong>Tokens (JWT)</strong>: for mobile and single-page apps. Short-lived access token plus a one-use refresh token.</label></div>
    <div class="form-check mb-3"><input class="form-check-input" type="checkbox" id="oauth" name="oauth" value="1"<?= !empty($s['oauth']) ? ' checked' : '' ?>><label class="form-check-label" for="oauth"><strong>Sign-in with apps (OAuth 2.0 + PKCE)</strong>: lets a third-party app act for a user after that user clicks Allow. Needs Tokens (JWT) too. Register apps on the Apps tab.</label></div>
    <div class="form-row">
      <div class="form-group col-md-3"><label for="jwt_access_ttl">Access token lifetime (seconds)</label><input class="form-control" type="number" id="jwt_access_ttl" name="jwt_access_ttl" value="<?= (int)$s['jwt_access_ttl'] ?>" min="60" max="86400"></div>
      <div class="form-group col-md-3"><label for="jwt_refresh_ttl">Refresh token lifetime (seconds)</label><input class="form-control" type="number" id="jwt_refresh_ttl" name="jwt_refresh_ttl" value="<?= (int)$s['jwt_refresh_ttl'] ?>" min="3600"></div>
    </div>
    <h5 class="mt-3">Other sites (CORS)</h5>
    <div class="form-group"><label for="cors_origins">Allowed origins, one per line</label><textarea class="form-control" id="cors_origins" name="cors_origins" rows="3" placeholder="https://app.example.com"><?= $h(implode("\n", (array)$s['cors_origins'])) ?></textarea>
      <small class="form-text text-muted">Empty means only this site's own pages can call the API from a browser. Cookie sessions are never allowed from another origin.</small></div>
    <h5 class="mt-3">Limits</h5>
    <div class="form-row">
      <div class="form-group col-md-3"><label for="rate_ip">Requests per minute per IP</label><input class="form-control" type="number" id="rate_ip" name="rate_ip" value="<?= (int)$s['rate_ip'] ?>" min="0"><small class="text-muted">0 = no limit</small></div>
      <div class="form-group col-md-3"><label for="rate_key">Requests per minute per key or token</label><input class="form-control" type="number" id="rate_key" name="rate_key" value="<?= (int)$s['rate_key'] ?>" min="0"></div>
      <div class="form-group col-md-3"><label for="max_body">Largest JSON request body (bytes)</label><input class="form-control" type="number" id="max_body" name="max_body" value="<?= (int)$s['max_body'] ?>" min="1024"></div>
      <div class="form-group col-md-3"><label for="max_upload">Largest file upload (bytes)</label><input class="form-control" type="number" id="max_upload" name="max_upload" value="<?= (int)$s['max_upload'] ?>" min="1048576"><small class="text-muted">PHP's own upload_max_filesize and post_max_size still apply.</small></div>
    </div>
    <div class="form-check mb-3"><input class="form-check-input" type="checkbox" id="wpriv" name="webhook_allow_private" value="1"<?= $s['webhook_allow_private'] ? ' checked' : '' ?>><label class="form-check-label" for="wpriv">Allow webhooks to private addresses and plain http (for development only)</label></div>
    <button class="btn btn-primary" type="submit">Save</button>
  </form>
  <hr>
  <h5>Token signing key</h5>
  <p class="small text-muted">Current key id: <code><?= $h($s['jwt_kid']) ?></code><?= $s['jwt_old_kids'] ? ' · still accepted: <code>' . $h(implode(', ', $s['jwt_old_kids'])) . '</code>' : '' ?>. A password change ends all of that user's tokens by itself.</p>
  <?= $form('rotate_jwt', '<button class="btn btn-outline-secondary btn-sm">Rotate signing key</button>') ?>
  <?= $s['jwt_old_kids'] ? $form('drop_old_kids', '<button class="btn btn-outline-danger btn-sm">Drop old keys</button>') : '' ?>
  <?= $form('revoke_all_tokens', '<button class="btn btn-outline-danger btn-sm">End my token sessions</button>') ?>
  <?= $form('reset_limits', '<button class="btn btn-outline-secondary btn-sm">Reset rate limits and lockouts</button>') ?>

<?php elseif ($tab === 'keys'): ?>
  <?php $keys = qp_api_keys_for($target); $pws = qp_app_passwords_for($target); $fmt = function ($t) { return $t ? date('Y-m-d H:i', (int)$t) : 'never'; }; ?>
  <?php if ($canAdmin): ?>
  <form method="get" class="form-inline mb-3"><input type="hidden" name="tab" value="keys"><label class="mr-2" for="for_user">User</label>
    <select class="form-control form-control-sm mr-2" id="for_user" name="for_user" onchange="this.form.submit()">
    <?php foreach (db()->query('SELECT id, username FROM ' . table_name('users') . ' ORDER BY username LIMIT 500')->fetchAll(PDO::FETCH_ASSOC) as $u): ?>
      <option value="<?= (int)$u['id'] ?>"<?= (int)$u['id'] === $target ? ' selected' : '' ?>><?= $h($u['username']) ?></option>
    <?php endforeach; ?></select><noscript><button class="btn btn-sm btn-secondary">Show</button></noscript></form>
  <?php endif; ?>
  <?php if (!$canAdmin && !$s['keys'] && !$s['app_passwords'] && !$s['jwt']): ?><div class="alert alert-info">The site owner has not turned on keys, application passwords or tokens yet. You can create them now; they work once those are enabled.</div><?php endif; ?>
  <h5>API keys for <?= $h($targetRow['username'] ?? '') ?></h5>
  <table class="table table-sm"><thead><tr><th>Name</th><th>Scopes</th><th>Created</th><th>Last used</th><th>Last IP</th><th>Limit/min</th><th>Expires</th><th></th></tr></thead><tbody>
  <?php foreach ($keys as $id => $k): ?>
    <tr><td><?= $h($k['name']) ?> <small class="text-muted"><?= $h($id) ?></small></td><td><code><?= $h(implode(' ', (array)$k['scopes'])) ?></code></td><td><?= $h($fmt($k['created'])) ?></td><td><?= $h($fmt($k['last_used'])) ?></td><td><?= $h($k['last_ip']) ?></td><td><?= $k['rate'] ? (int)$k['rate'] : '<span class="text-muted">default</span>' ?></td><td><?= $k['expires'] ? $h(date('Y-m-d', (int)$k['expires'])) : '—' ?></td>
      <td><?= $form('revoke_key', '<input type="hidden" name="id" value="' . $h($id) . '"><button class="btn btn-sm btn-outline-danger">Revoke</button>') ?></td></tr>
  <?php endforeach; if (!$keys): ?><tr><td colspan="8" class="text-muted">No keys yet.</td></tr><?php endif; ?></tbody></table>
  <?= $form('create_key', '<div class="form-row align-items-end"><div class="col-md-3"><label>Name</label><input class="form-control" name="name" required maxlength="60"></div><div class="col-md-3"><label>Scopes</label><input class="form-control" name="scopes" value="read" placeholder="read write posts:read"></div><div class="col-md-2"><label>Limit/min</label><input class="form-control" type="number" name="rate" min="1" placeholder="default"></div><div class="col-md-2"><label>Expires</label><input class="form-control" type="date" name="expires"></div><div class="col-md-2"><button class="btn btn-primary">Create key</button></div></div>', 'mb-4') ?>
  <p class="small text-muted">Scopes: <code>*</code> everything, <code>read</code> / <code>write</code> all resources, or <code>posts:read</code>, <code>pages:write</code>, <code>terms:*</code>, <code>media:read</code>, <code>users:read</code>, <code>options:write</code>. A key can never do more than its user may.</p>
  <?php $sessions = qp_rest_user_meta_list($target, 'qp_jwt_refresh'); $clientNames = qp_oauth_clients(); ?>
  <h5 class="mt-4">Token sessions and connected apps</h5>
  <table class="table table-sm"><thead><tr><th>Started by</th><th>Scopes</th><th>Started</th><th>Last refreshed</th><th>IP</th><th>Ends</th><th></th></tr></thead><tbody>
  <?php foreach ($sessions as $fid => $f): if (($f['exp'] ?? 0) < time()) continue; ?>
    <tr><td><?= !empty($f['client']) ? $h($clientNames[$f['client']]['name'] ?? $f['client']) : 'password sign-in' ?></td><td><code><?= $h(implode(' ', (array)$f['scopes'])) ?></code></td><td><?= $h($fmt($f['created'])) ?></td><td><?= $h($fmt($f['rotated'] ?? 0)) ?></td><td><?= $h($f['ip'] ?? '') ?></td><td><?= $h(date('Y-m-d', (int)$f['exp'])) ?></td>
      <td><?= $form('revoke_session', '<input type="hidden" name="id" value="' . $h($fid) . '"><button class="btn btn-sm btn-outline-danger">End</button>') ?></td></tr>
  <?php endforeach; if (!$sessions): ?><tr><td colspan="7" class="text-muted">No token sessions.</td></tr><?php endif; ?></tbody></table>
  <h5 class="mt-4">Application passwords</h5>
  <table class="table table-sm"><thead><tr><th>Name</th><th>Created</th><th>Last used</th><th>Last IP</th><th></th></tr></thead><tbody>
  <?php foreach ($pws as $id => $p): ?>
    <tr><td><?= $h($p['name']) ?> <small class="text-muted"><?= $h($id) ?></small></td><td><?= $h($fmt($p['created'])) ?></td><td><?= $h($fmt($p['last_used'])) ?></td><td><?= $h($p['last_ip']) ?></td>
      <td><?= $form('revoke_app_password', '<input type="hidden" name="id" value="' . $h($id) . '"><button class="btn btn-sm btn-outline-danger">Revoke</button>') ?></td></tr>
  <?php endforeach; if (!$pws): ?><tr><td colspan="5" class="text-muted">No application passwords yet.</td></tr><?php endif; ?></tbody></table>
  <?= $form('create_app_password', '<div class="form-row align-items-end"><div class="col-md-5"><label>App name</label><input class="form-control" name="name" required maxlength="60"></div><div class="col-md-3"><button class="btn btn-primary">Create password</button></div></div>') ?>

<?php elseif ($tab === 'apps'): ?>
  <?php $clients = qp_oauth_clients(); ?>
  <p class="text-muted">Apps that may ask a user for access (OAuth 2.0 authorization code with PKCE). Authorization page: <code><?= $h(qp_rest_site_origin() . parse_url(defined('SITE_URL') ? SITE_URL : '', PHP_URL_PATH)) ?>/api/qlopy/oauth/authorize</code> · token endpoint: <code><?= $h($base) ?>/oauth/token</code>.
    <?= qp_oauth_enabled() ? '' : '<strong class="text-danger">Switched off:</strong> turn on Tokens (JWT) and Sign-in with apps under Settings.' ?></p>
  <table class="table table-sm"><thead><tr><th>App</th><th>Client ID</th><th>Redirect addresses</th><th></th></tr></thead><tbody>
  <?php foreach ($clients as $cid => $c): ?>
    <tr><td><?= $h($c['name']) ?></td><td><code><?= $h($cid) ?></code></td><td class="small text-break"><?= $h(implode("\n", $c['redirects'])) ?></td>
      <td><?= $form('delete_oauth_client', '<input type="hidden" name="id" value="' . $h($cid) . '"><button class="btn btn-sm btn-outline-danger">Remove</button>') ?></td></tr>
  <?php endforeach; if (!$clients): ?><tr><td colspan="4" class="text-muted">No apps yet.</td></tr><?php endif; ?></tbody></table>
  <form method="post"><input type="hidden" name="api_nonce" value="<?= $h($nonce) ?>"><input type="hidden" name="api_action" value="add_oauth_client">
    <div class="form-group"><label for="oa_name">App name (shown to users on the Allow page)</label><input class="form-control" id="oa_name" name="name" required maxlength="80"></div>
    <div class="form-group"><label for="oa_red">Redirect addresses, one per line</label><textarea class="form-control" id="oa_red" name="redirects" rows="3" required placeholder="https://app.example.com/callback&#10;com.example.app:/oauth"></textarea>
      <small class="text-muted">https, <code>http://localhost</code> for development, or an app scheme. Matched exactly.</small></div>
    <button class="btn btn-primary">Add app</button></form>

<?php elseif ($tab === 'webhooks'): ?>
  <?php $subs = qp_webhook_subscriptions(); $events = qp_webhook_events(); $log = get_option_meta('qp_webhook_log'); $log = is_array($log) ? $log : []; ?>
  <p class="text-muted">Qlopy sends a signed POST to your address when something happens. Failed deliveries are retried (1 min, 5 min, 30 min, 2 h, 6 h). Needs the scheduled-task runner (Settings > Cron).</p>
  <table class="table table-sm"><thead><tr><th>Address</th><th>Events</th><th>Status</th><th>Secret</th><th></th></tr></thead><tbody>
  <?php foreach ($subs as $id => $w): ?>
    <tr><td class="text-break"><?= $h($w['url']) ?></td><td><small><?= $h(implode(', ', (array)$w['events'])) ?></small></td>
      <td><?= $w['active'] ? '<span class="badge badge-success">on</span>' : '<span class="badge badge-secondary">off</span>' ?> <?= (int)$w['fails'] > 0 ? '<small class="text-danger">' . (int)$w['fails'] . ' failures in a row</small>' : '' ?></td>
      <td><code class="small"><?= $h($w['secret']) ?></code></td>
      <td class="text-nowrap"><?= $form('test_webhook', '<input type="hidden" name="id" value="' . $h($id) . '"><button class="btn btn-sm btn-outline-secondary">Send test</button>') ?>
        <?= $form('toggle_webhook', '<input type="hidden" name="id" value="' . $h($id) . '"><button class="btn btn-sm btn-outline-secondary">' . ($w['active'] ? 'Turn off' : 'Turn on') . '</button>') ?>
        <?= $form('delete_webhook', '<input type="hidden" name="id" value="' . $h($id) . '"><button class="btn btn-sm btn-outline-danger">Delete</button>') ?></td></tr>
  <?php endforeach; if (!$subs): ?><tr><td colspan="5" class="text-muted">No webhooks yet.</td></tr><?php endif; ?></tbody></table>
  <form method="post" class="mb-4"><input type="hidden" name="api_nonce" value="<?= $h($nonce) ?>"><input type="hidden" name="api_action" value="add_webhook">
    <div class="form-group"><label for="wh_url">Address (https)</label><input class="form-control" id="wh_url" name="url" type="url" required placeholder="https://example.com/hooks/qlopy"></div>
    <div class="form-group"><span class="d-block">Events</span>
      <?php foreach ($events as $ev => $label): ?><div class="form-check"><input class="form-check-input" type="checkbox" name="events[]" id="ev_<?= $h($ev) ?>" value="<?= $h($ev) ?>"><label class="form-check-label" for="ev_<?= $h($ev) ?>"><code><?= $h($ev) ?></code> <small class="text-muted"><?= $h($label) ?></small></label></div><?php endforeach; ?></div>
    <button class="btn btn-primary">Add webhook</button></form>
  <h5>Recent deliveries</h5>
  <table class="table table-sm"><thead><tr><th>When</th><th>Event</th><th>Result</th><th>HTTP</th><th>ms</th><th>Note</th></tr></thead><tbody>
  <?php foreach (array_slice($log, 0, 30) as $l): ?>
    <tr><td><?= $h(date('Y-m-d H:i:s', (int)$l['t'])) ?></td><td><code><?= $h($l['event']) ?></code></td><td><?= $h($l['status']) ?> (attempt <?= (int)$l['attempt'] ?>)</td><td><?= (int)($l['code'] ?? 0) ?></td><td><?= (int)($l['ms'] ?? 0) ?></td><td class="small"><?= $h($l['error'] ?? '') ?></td></tr>
  <?php endforeach; if (!$log): ?><tr><td colspan="6" class="text-muted">Nothing sent yet.</td></tr><?php endif; ?></tbody></table>

<?php else: ?>
  <?php $routes = []; require_once __DIR__ . '/../includes/rest-routes.php'; do_action('qp_rest_init'); foreach (qp_rest_all_routes() as [$ns, $route, $hd]) $routes[] = [$hd['methods'], '/' . $ns . preg_replace('~\(\?P<([a-z_]+)>[^)]*\)~i', '{$1}', $route), $hd]; ?>
  <p>Machine-readable description: <a href="<?= $h($base) ?>/openapi.json" target="_blank" rel="noopener"><code><?= $h($base) ?>/openapi.json</code></a> (import it into Postman, Insomnia or a code generator).</p>
  <table class="table table-sm"><thead><tr><th>Method</th><th>Route</th><th>Who can call it</th><th>Scope</th></tr></thead><tbody>
  <?php foreach ($routes as [$methods, $path, $hd]): ?>
    <tr><td><code><?= $h(implode(' ', $methods)) ?></code></td><td><code><?= $h($path) ?></code> <small class="text-muted"><?= $h($hd['summary'] ?? '') ?></small></td><td><?= !empty($hd['public']) ? 'anyone' : 'signed in, checked per route' ?></td><td><code><?= $h($hd['scope'] ?? 'auto') ?></code></td></tr>
  <?php endforeach; ?></tbody></table>
<?php endif; ?>
</div>
<?php include __DIR__ . '/inc/footer.php'; ?>
