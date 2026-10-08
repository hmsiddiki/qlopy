<?php
if (!defined('QLOPY_INIT')) { http_response_code(403); exit; }
// includes/admin-ajax-mail.php
// Registers admin AJAX handlers for qp_mail settings and test send.

if (!function_exists('add_admin_action')) return; // ensure admin hooks are available

// helper to validate nonce (action 'qp_mail_settings') if available
function _qp_mail_verify_admin_nonce() {
    // Accept either the modern `_qp_nonce` or legacy `admin_nonce` field.
    $token = $_POST['_qp_nonce'] ?? $_POST['admin_nonce'] ?? $_GET['_qp_nonce'] ?? $_GET['admin_nonce'] ?? '';
    // Prefer new API if available, fall back to legacy admin_nonce helpers.
    if (function_exists('qp_admin_verify_nonce')) {
        return qp_admin_verify_nonce($token, 'qp_mail_settings');
    }
    if (function_exists('admin_nonce_verify')) {
        return admin_nonce_verify($token, 'qp_mail_settings');
    }
    // Fallback: some older installs stored nonces in PHP session under 'qp_nonces'
    if (PHP_SAPI !== 'cli' && session_status() !== PHP_SESSION_ACTIVE) {
        @session_start();
    }
    if (!empty($_SESSION) && !empty($_SESSION['qp_nonces']) && is_array($_SESSION['qp_nonces'])) {
        $action_key = 'qp_mail_settings';
        if (!empty($_SESSION['qp_nonces'][$action_key]) && !empty($_SESSION['qp_nonces'][$action_key][$token])) {
            // consume
            unset($_SESSION['qp_nonces'][$action_key][$token]);
            if (empty($_SESSION['qp_nonces'][$action_key])) unset($_SESSION['qp_nonces'][$action_key]);
            return true;
        }
    }
    return false;
}

add_admin_action('iitcm_admin_ajax_qp_mail_get_settings', function($req){
    $s = function_exists('qp_mail_get_settings') ? qp_mail_get_settings() : [];
    echo json_encode(['status'=>'success','settings'=>$s]);
    exit;
}, 10, 1);

// Provide a fresh nonce token for qp_mail_settings actions
add_admin_action('iitcm_admin_ajax_qp_mail_get_nonce', function($req){
    $token = '';
    if (function_exists('qp_admin_create_nonce')) {
        $token = qp_admin_create_nonce('qp_mail_settings');
    } else if (function_exists('admin_nonce_create')) {
        $token = admin_nonce_create('qp_mail_settings');
    }
    echo json_encode(['status' => $token ? 'success' : 'error', 'nonce' => $token]);
    exit;
}, 10, 1);

add_admin_action('iitcm_admin_ajax_qp_mail_save_settings', function($req){
    if (! _qp_mail_verify_admin_nonce()) { echo json_encode(['status'=>'error','message'=>'Invalid nonce']); exit; }
    $incoming = $_POST['settings'] ?? null;
    if (is_string($incoming)) {
        $decoded = json_decode($incoming, true);
        if (is_array($decoded)) $incoming = $decoded;
    }
    if (!is_array($incoming)) {
        echo json_encode(['status'=>'error','message'=>'Invalid settings']); exit;
    }
    // Only update secret fields if a non-empty value is provided. This avoids
    // overwriting existing encrypted credentials with empty strings when the
    // admin leaves the password field blank (no change).
    if (isset($incoming['smtp_password'])) {
        if ($incoming['smtp_password'] !== '') {
            $incoming['smtp_password'] = qp_mail_encrypt($incoming['smtp_password']);
        } else {
            unset($incoming['smtp_password']);
        }
    }
    if (isset($incoming['oauth_client_secret'])) {
        if ($incoming['oauth_client_secret'] !== '') {
            $incoming['oauth_client_secret'] = qp_mail_encrypt($incoming['oauth_client_secret']);
        } else {
            unset($incoming['oauth_client_secret']);
        }
    }
    if (isset($incoming['oauth_refresh_token'])) {
        if ($incoming['oauth_refresh_token'] !== '') {
            $incoming['oauth_refresh_token'] = qp_mail_encrypt($incoming['oauth_refresh_token']);
        } else { unset($incoming['oauth_refresh_token']); }
    }
    if (isset($incoming['dkim_private_key'])) {
        if ($incoming['dkim_private_key'] !== '') {
            $incoming['dkim_private_key'] = qp_mail_encrypt($incoming['dkim_private_key']);
        } else { unset($incoming['dkim_private_key']); }
    }
    $ok = function_exists('qp_mail_update_settings') ? qp_mail_update_settings($incoming) : false;
    echo json_encode(['status' => $ok ? 'success' : 'error']); exit;
}, 10, 1);

add_admin_action('iitcm_admin_ajax_qp_mail_send_test', function($req){
    if (! _qp_mail_verify_admin_nonce()) {
        // Allow admins authenticated via legacy PHP session to run the test as a best-effort compatibility
        $allowed_via_session = false;
        if (PHP_SAPI !== 'cli' && session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }
        if (!empty($_SESSION['user_id']) || !empty($_SESSION['user']) || !empty($_SESSION['qp_user'])) $allowed_via_session = true;
        if (!$allowed_via_session && function_exists('is_logged_in') && is_logged_in()) $allowed_via_session = true;
        if (!$allowed_via_session) { echo json_encode(['status'=>'error','message'=>'Invalid nonce']); exit; }
        // continue if allowed_via_session is true
    }
    $to = $_POST['to'] ?? ($_POST['email'] ?? '');
    $subject = $_POST['subject'] ?? 'Test email from qp_mail';
    $body = $_POST['body'] ?? "This is a test message generated by qp_mail.";
    if (!$to) { echo json_encode(['status'=>'error','message'=>'Recipient required']); exit; }
    $sent = false;
    if (function_exists('qp_mail')) {
        $sent = qp_mail($to, $subject, $body, [], []);
    } else {
        $sent = @mail($to, $subject, $body);
    }
    echo json_encode(['status' => $sent ? 'success' : 'error']); exit;
}, 10, 1);

// Queue and logs endpoints
add_admin_action('iitcm_admin_ajax_qp_mail_get_queue', function($req){
    $q = function_exists('qp_mail_get_queue') ? qp_mail_get_queue(200) : [];
    echo json_encode(['status'=>'success','queue'=>$q]); exit;
}, 10, 1);

add_admin_action('iitcm_admin_ajax_qp_mail_get_logs', function($req){
    $l = function_exists('qp_mail_get_logs') ? qp_mail_get_logs(200) : [];
    echo json_encode(['status'=>'success','logs'=>$l]); exit;
}, 10, 1);

add_admin_action('iitcm_admin_ajax_qp_mail_run_queue', function($req){
    if (! _qp_mail_verify_admin_nonce()) { echo json_encode(['status'=>'error','message'=>'Invalid nonce']); exit; }
    $limit = intval($_POST['limit'] ?? 50);
    $settings = function_exists('qp_mail_get_settings') ? qp_mail_get_settings() : [];
    $rate = isset($settings['rate_limit_per_minute']) ? intval($settings['rate_limit_per_minute']) : null;
    $res = function_exists('qp_mail_process_queue') ? qp_mail_process_queue($limit, intval($settings['queue_max_attempts'] ?? 5), $rate) : ['processed'=>0,'sent'=>0,'failed'=>0];
    echo json_encode(['status'=>'success','result'=>$res]); exit;
}, 10, 1);

add_admin_action('iitcm_admin_ajax_qp_mail_retry_item', function($req){
    if (! _qp_mail_verify_admin_nonce()) { echo json_encode(['status'=>'error','message'=>'Invalid nonce']); exit; }
    $id = intval($_POST['id'] ?? 0);
    if ($id <= 0) { echo json_encode(['status'=>'error','message'=>'Invalid id']); exit; }
    global $pdo;
    if (empty($pdo)) { echo json_encode(['status'=>'error','message'=>'DB not available']); exit; }
    try {
        $upd = $pdo->prepare('UPDATE qp_mail_queue SET attempts = 0, next_attempt_at = UTC_TIMESTAMP() WHERE id = ?');
        $upd->execute([$id]);
        echo json_encode(['status'=>'success']); exit;
    } catch (Throwable $e) { echo json_encode(['status'=>'error','message'=>$e->getMessage()]); exit; }
}, 10, 1);

add_admin_action('iitcm_admin_ajax_qp_mail_delete_item', function($req){
    if (! _qp_mail_verify_admin_nonce()) { echo json_encode(['status'=>'error','message'=>'Invalid nonce']); exit; }
    $id = intval($_POST['id'] ?? 0);
    if ($id <= 0) { echo json_encode(['status'=>'error','message'=>'Invalid id']); exit; }
    global $pdo;
    if (empty($pdo)) { echo json_encode(['status'=>'error','message'=>'DB not available']); exit; }
    try {
        $del = $pdo->prepare('DELETE FROM qp_mail_queue WHERE id = ?');
        $del->execute([$id]);
        echo json_encode(['status'=>'success']); exit;
    } catch (Throwable $e) { echo json_encode(['status'=>'error','message'=>$e->getMessage()]); exit; }
}, 10, 1);

add_admin_action('iitcm_admin_ajax_qp_mail_get_debug', function($req){
    $log_id = intval($_POST['log_id'] ?? 0);
    if ($log_id <= 0) { echo json_encode(['status'=>'error','message'=>'Invalid log id']); exit; }
    global $pdo;
    if (empty($pdo)) { echo json_encode(['status'=>'error','message'=>'DB not available']); exit; }
    try {
        $stmt = $pdo->prepare('SELECT transcript FROM qp_mail_debug WHERE log_id = ? ORDER BY id DESC LIMIT 1');
        $stmt->execute([$log_id]);
        $t = $stmt->fetchColumn();
        echo json_encode(['status'=>'success','transcript'=>$t]); exit;
    } catch (Throwable $e) { echo json_encode(['status'=>'error','message'=>$e->getMessage()]); exit; }
}, 10, 1);

add_admin_action('iitcm_admin_ajax_qp_mail_apply_migrations', function($req){
    if (! _qp_mail_verify_admin_nonce()) { echo json_encode(['status'=>'error','message'=>'Invalid nonce']); exit; }
    $ok = function_exists('qp_mail_install_tables') ? qp_mail_install_tables() : false;
    echo json_encode(['status'=>$ok ? 'success' : 'error']); exit;
}, 10, 1);

// Check whether required qp_mail tables exist (for fresh installs)
add_admin_action('iitcm_admin_ajax_qp_mail_check_migrations', function($req){
    global $pdo;
    if (empty($pdo)) { echo json_encode(['status'=>'error','message'=>'DB not available']); exit; }
    $needed = ['qp_mail_queue','qp_mail_log','qp_mail_debug'];
    $missing = [];
    try {
        foreach ($needed as $t) {
            try {
                $pdo->query('SELECT 1 FROM ' . $t . ' LIMIT 1');
            } catch (Throwable $_e) {
                $missing[] = $t;
            }
        }
    } catch (Throwable $e) {
        echo json_encode(['status'=>'error','message'=>'Check failed']); exit;
    }
    if (empty($missing)) echo json_encode(['status'=>'ready']); else echo json_encode(['status'=>'needs_migration','missing'=>$missing]);
    exit;
}, 10, 1);

add_admin_action('iitcm_admin_ajax_qp_mail_get_dkim_record', function($req){
    // returns a suggested DNS TXT record string for DKIM based on settings
    $s = function_exists('qp_mail_get_settings') ? qp_mail_get_settings() : [];
    $selector = $s['dkim_selector'] ?? '';
    $encKey = $s['dkim_private_key'] ?? '';
    $from = $s['from_email'] ?? '';
    if (empty($selector) || empty($encKey) || empty($from)) { echo json_encode(['status'=>'error','message'=>'DKIM not configured']); exit; }
    $priv = qp_mail_decrypt($encKey);
    // derive domain from from email
    $parts = explode('@', $from);
    $domain = $parts[1] ?? '';
    if (empty($domain)) { echo json_encode(['status'=>'error','message'=>'Invalid from email']); exit; }
    $txt = function_exists('qp_mail_dkim_dns_record') ? qp_mail_dkim_dns_record($selector, $domain, $priv) : '';
    echo json_encode(['status'=>'success','record'=>$txt]); exit;
}, 10, 1);

add_admin_action('iitcm_admin_ajax_qp_mail_get_oauth_url', function($req){
    // Returns an authorization URL for the configured provider (google|microsoft).
    // Ensure a session is started without emitting warnings if one already exists.
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    $settings = function_exists('qp_mail_get_settings') ? qp_mail_get_settings() : [];
    $provider = strtolower(trim($_POST['provider'] ?? ($settings['oauth_provider'] ?? 'google')));
    $clientId = $settings['oauth_client_id'] ?? '';
    if (empty($clientId)) { echo json_encode(['status'=>'error','message'=>'OAuth client_id not configured']); exit; }

    $redirect = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']) . '/mail-oauth-callback.php';

    $state = bin2hex(random_bytes(16));
    $_SESSION['qp_mail_oauth_state'] = $state;
    $_SESSION['qp_mail_oauth_provider'] = $provider;

    if ($provider === 'microsoft' || $provider === 'azure' || $provider === 'office365') {
        $scope = urlencode('offline_access https://outlook.office.com/SMTP.Send');
        $url = "https://login.microsoftonline.com/common/oauth2/v2.0/authorize?client_id=".urlencode($clientId)."&response_type=code&redirect_uri=".urlencode($redirect)."&response_mode=query&scope={$scope}&state={$state}";
    } else {
        // default Google
        $scope = urlencode('https://mail.google.com/');
        $url = "https://accounts.google.com/o/oauth2/v2/auth?client_id=".urlencode($clientId)."&response_type=code&redirect_uri=".urlencode($redirect)."&scope={$scope}&access_type=offline&prompt=consent&state={$state}";
    }
    echo json_encode(['status'=>'success','url'=>$url]); exit;
}, 10, 1);

add_admin_action('iitcm_admin_ajax_qp_mail_get_oauth_status', function($req){
    $s = function_exists('qp_mail_get_settings') ? qp_mail_get_settings() : [];
    $has_refresh = !empty($s['oauth_refresh_token']);
    $cache = function_exists('qp_mail_get_oauth_cache') ? qp_mail_get_oauth_cache() : ['access_token'=>'','expiry'=>0];
    $expires = $cache['expiry'] ?? 0;
    echo json_encode(['status'=>'success','authorized'=>$has_refresh ? 1 : 0,'access_expires_at'=>$expires]); exit;
}, 10, 1);

add_admin_action('iitcm_admin_ajax_qp_mail_revoke_oauth', function($req){
    if (! _qp_mail_verify_admin_nonce()) { echo json_encode(['status'=>'error','message'=>'Invalid nonce']); exit; }
    // Attempt provider revoke for Google (best-effort). Then clear stored tokens locally.
    $s = function_exists('qp_mail_get_settings') ? qp_mail_get_settings() : [];
    $provider = strtolower($s['oauth_provider'] ?? 'google');
    $refresh_enc = $s['oauth_refresh_token'] ?? '';
    $refresh = $refresh_enc ? qp_mail_decrypt($refresh_enc) : '';
    $revoked = false;
    if ($provider === 'google' && $refresh) {
        // Google token revoke endpoint
        $url = 'https://oauth2.googleapis.com/revoke';
        $body = http_build_query(['token' => $refresh]);
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
            $resp = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            $revoked = ($code === 200 || $code === 400); // 200 OK, 400 token already invalid
        } else {
            $resp = @file_get_contents($url . '?token=' . urlencode($refresh));
            $revoked = $resp !== false;
        }
    }
    // Attempt Microsoft revoke via Graph API using client credentials (requires app permissions)
    if (($provider === 'microsoft' || $provider === 'azure' || $provider === 'office365') && !$revoked) {
        $clientId = $s['oauth_client_id'] ?? '';
        $clientSecret = qp_mail_decrypt($s['oauth_client_secret'] ?? '');
        $tenant = $s['oauth_tenant'] ?? ($s['oauth_tenant_id'] ?? 'common');
        $targetUser = $s['smtp_username'] ?? ($s['from_email'] ?? '');
        if ($clientId && $clientSecret && $targetUser) {
            // Get app token via client credentials
            $tokenUrl = "https://login.microsoftonline.com/{$tenant}/oauth2/v2.0/token";
            $body = http_build_query(['client_id'=>$clientId,'client_secret'=>$clientSecret,'scope'=>'https://graph.microsoft.com/.default','grant_type'=>'client_credentials']);
            if (function_exists('curl_init')) {
                $ch = curl_init($tokenUrl);
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
                $resp = curl_exec($ch);
                $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                if ($code >= 200 && $code < 300) {
                    $json = json_decode($resp, true);
                    $appToken = $json['access_token'] ?? '';
                }
            } else {
                $resp = @file_get_contents($tokenUrl, false, stream_context_create(['http'=>['method'=>'POST','header'=>'Content-Type: application/x-www-form-urlencoded','content'=>$body]]));
                $json = $resp ? json_decode($resp, true) : null;
                $appToken = $json['access_token'] ?? '';
            }
            if (!empty($appToken)) {
                // Try to find user by userPrincipalName
                $userId = null;
                $searchUrl = 'https://graph.microsoft.com/v1.0/users/'.urlencode($targetUser);
                $hdr = ['Authorization: Bearer ' . $appToken, 'Accept: application/json'];
                if (function_exists('curl_init')) {
                    $ch = curl_init($searchUrl);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch, CURLOPT_HTTPHEADER, $hdr);
                    $resp = curl_exec($ch);
                    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    curl_close($ch);
                    if ($code >=200 && $code <300) { $j = json_decode($resp, true); $userId = $j['id'] ?? null; }
                } else {
                    $resp = @file_get_contents($searchUrl, false, stream_context_create(['http'=>['header'=>'Authorization: Bearer ' . $appToken . "\r\nAccept: application/json\r\n"]]));
                    $j = $resp ? json_decode($resp, true) : null;
                    $userId = $j['id'] ?? null;
                }
                // If direct fetch failed, try filter search
                if (empty($userId)) {
                    $filterUrl = 'https://graph.microsoft.com/v1.0/users?$filter=userPrincipalName%20eq%20' . rawurlencode("'" . $targetUser . "'");
                    if (function_exists('curl_init')) {
                        $ch = curl_init($filterUrl);
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                        curl_setopt($ch, CURLOPT_HTTPHEADER, $hdr);
                        $resp = curl_exec($ch);
                        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                        curl_close($ch);
                        if ($code >=200 && $code <300) { $j = json_decode($resp, true); if (!empty($j['value'][0]['id'])) $userId = $j['value'][0]['id']; }
                    } else {
                        $resp = @file_get_contents($filterUrl, false, stream_context_create(['http'=>['header'=>'Authorization: Bearer ' . $appToken . "\r\nAccept: application/json\r\n"]]));
                        $j = $resp ? json_decode($resp, true) : null; if (!empty($j['value'][0]['id'])) $userId = $j['value'][0]['id'];
                    }
                }
                if (!empty($userId)) {
                    $revokeUrl = 'https://graph.microsoft.com/v1.0/users/' . rawurlencode($userId) . '/revokeSignInSessions';
                    if (function_exists('curl_init')) {
                        $ch = curl_init($revokeUrl);
                        curl_setopt($ch, CURLOPT_POST, true);
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                        curl_setopt($ch, CURLOPT_HTTPHEADER, $hdr);
                        $resp = curl_exec($ch);
                        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                        curl_close($ch);
                        $revoked = ($code === 204 || ($code >=200 && $code<300));
                    } else {
                        $ctx = stream_context_create(['http'=>['method'=>'POST','header'=>'Authorization: Bearer ' . $appToken . "\r\nAccept: application/json\r\n"]]);
                        $resp = @file_get_contents($revokeUrl, false, $ctx);
                        $revoked = $resp !== false;
                    }
                }
            }
        }
    }
    // Clear local tokens regardless (best-effort conservatively)
    $ok = function_exists('qp_mail_clear_oauth') ? qp_mail_clear_oauth() : false;
    echo json_encode(['status' => $ok ? 'success' : 'error', 'revoked_remote' => $revoked]); exit;
}, 10, 1);

add_admin_action('iitcm_admin_ajax_qp_mail_send_dkim_test', function($req){
    if (! _qp_mail_verify_admin_nonce()) { echo json_encode(['status'=>'error','message'=>'Invalid nonce']); exit; }
    $to = $_POST['to'] ?? ($_POST['email'] ?? '');
    if (!$to) { echo json_encode(['status'=>'error','message'=>'Recipient required']); exit; }
    $settings = function_exists('qp_mail_get_settings') ? qp_mail_get_settings() : [];
    if (empty($settings['dkim_enabled']) || empty($settings['dkim_selector']) || empty($settings['dkim_private_key'])) {
        echo json_encode(['status'=>'error','message'=>'DKIM not configured']); exit;
    }
    // Build PHPMailer instance and send a single message capturing debug output
    try {
        require_once __DIR__ . '/qp-mail.php';
        $mail = qp_mail_prepare_phpmailer($settings);
        $mail->addAddress($to);
        $mail->Subject = 'DKIM test ' . date('c');
        $mail->Body = 'This is a DKIM test message.';
        $debug = [];
        $mail->Debugoutput = function($str,$lvl) use (&$debug){ $debug[] = trim($str); };
        $sent = $mail->send();
        $out = ['status'=> $sent ? 'success' : 'error', 'sent' => $sent, 'error' => $mail->ErrorInfo, 'transcript' => implode("\n", $debug)];
        echo json_encode($out); exit;
    } catch (Throwable $e) {
        echo json_encode(['status'=>'error','message'=>$e->getMessage()]); exit;
    }
}, 10, 1);

add_admin_action('iitcm_admin_ajax_qp_mail_check_dkim_dns', function($req){
    // Returns DNS TXT records for selector._domainkey.domain and compares p value with provided private key
    $s = function_exists('qp_mail_get_settings') ? qp_mail_get_settings() : [];
    $selector = $s['dkim_selector'] ?? '';
    $encKey = $s['dkim_private_key'] ?? '';
    $from = $s['from_email'] ?? '';
    if (empty($selector) || empty($encKey) || empty($from)) { echo json_encode(['status'=>'error','message'=>'DKIM not configured']); exit; }
    $priv = qp_mail_decrypt($encKey);
    $parts = explode('@', $from);
    $domain = $parts[1] ?? '';
    if (empty($domain)) { echo json_encode(['status'=>'error','message'=>'Invalid from email']); exit; }
    $dnsName = $selector . '._domainkey.' . $domain;
    $records = [];
    if (function_exists('dns_get_record')) {
        $txts = dns_get_record($dnsName, DNS_TXT);
        foreach ($txts as $t) {
            $records[] = $t['txt'] ?? '';
        }
    } else {
        // fallback: try dig via shell if available
        $out = [];
        @exec('nslookup -type=txt ' . escapeshellarg($dnsName), $out);
        if ($out) $records = $out;
    }
    $match = false;
    // derive public key from private
    $pubBase = '';
    if (function_exists('openssl_pkey_get_private') && ($p = @openssl_pkey_get_private($priv))) {
        $det = openssl_pkey_get_details($p);
        if (!empty($det['key'])) {
            $pub = $det['key'];
            $pubBase = preg_replace('/-----BEGIN PUBLIC KEY-----|-----END PUBLIC KEY-----|\s+/', '', $pub);
        }
    }
    foreach ($records as $r) {
        if (stripos($r, 'p=') !== false) {
            // extract p value
            if (preg_match('/p=([A-Za-z0-9+/=]+)/', $r, $m)) {
                $pval = $m[1];
                if ($pubBase && trim($pval) === trim($pubBase)) { $match = true; break; }
            }
        }
    }
    echo json_encode(['status'=>'success','dns_name'=>$dnsName,'records'=>$records,'public_match'=>$match]); exit;
}, 10, 1);
