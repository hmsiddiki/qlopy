<?php
// admin/mail-oauth-callback.php
// OAuth redirect handler for qp_mail admin authorization flows.

require_once __DIR__ . '/admin_head.php';
require_once __DIR__ . '/../includes/qp-mail.php';

session_start();

$code = $_GET['code'] ?? '';
$state = $_GET['state'] ?? '';
$stored = $_SESSION['qp_mail_oauth_state'] ?? '';
$provider = $_SESSION['qp_mail_oauth_provider'] ?? 'google';

if (!$code || !$state || $state !== $stored) {
    // show error
    require_once __DIR__ . '/inc/header.php';
    echo '<div class="wrap container"><h1>OAuth Error</h1><div class="alert alert-danger">Invalid or missing OAuth state/code.</div></div>';
    require_once __DIR__ . '/inc/footer.php';
    exit;
}

// Get client_id/secret from settings
$settings = qp_mail_get_settings();
$clientId = $settings['oauth_client_id'] ?? '';
$clientSecret = qp_mail_decrypt($settings['oauth_client_secret'] ?? '');

if (!$clientId || !$clientSecret) {
    require_once __DIR__ . '/inc/header.php';
    echo '<div class="wrap container"><h1>OAuth Error</h1><div class="alert alert-danger">Client ID/Secret not configured in settings.</div></div>';
    require_once __DIR__ . '/inc/footer.php';
    exit;
}

// Build token endpoint and post data
if (in_array($provider, ['microsoft','azure','office365'])) {
    $tokenUrl = 'https://login.microsoftonline.com/common/oauth2/v2.0/token';
    $post = [
        'client_id' => $clientId,
        'client_secret' => $clientSecret,
        'grant_type' => 'authorization_code',
        'code' => $code,
        'redirect_uri' => (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']) . '/mail-oauth-callback.php'
    ];
} else {
    $tokenUrl = 'https://oauth2.googleapis.com/token';
    $post = [
        'client_id' => $clientId,
        'client_secret' => $clientSecret,
        'grant_type' => 'authorization_code',
        'code' => $code,
        'redirect_uri' => (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']) . '/mail-oauth-callback.php'
    ];
}

// POST using curl or file_get_contents
function qp_post_json_form($url, $data) {
    $body = http_build_query($data);
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
        $resp = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);
        if ($resp === false) return ['error'=>'curl_error:'.$err];
        $json = json_decode($resp, true);
        return is_array($json) ? $json : ['error'=>'invalid_json'];
    }
    $ctx = stream_context_create(['http'=>['method'=>'POST','header'=>'Content-Type: application/x-www-form-urlencoded','content'=>$body,'timeout'=>10]]);
    $resp = @file_get_contents($url, false, $ctx);
    if ($resp === false) return ['error'=>'request_failed'];
    $json = json_decode($resp, true);
    return is_array($json) ? $json : ['error'=>'invalid_json'];
}

$tokenResp = qp_post_json_form($tokenUrl, $post);

require_once __DIR__ . '/inc/header.php';
echo '<div class="wrap container">';
if (!is_array($tokenResp) || empty($tokenResp['refresh_token'])) {
    echo '<h1>OAuth Failed</h1><div class="alert alert-danger">Could not obtain a refresh token. Response: <pre>' . htmlspecialchars(print_r($tokenResp, true)) . '</pre></div>';
} else {
    // store encrypted refresh token and client secret
    $settings['oauth_refresh_token'] = qp_mail_encrypt($tokenResp['refresh_token']);
    $settings['oauth_client_id'] = $clientId;
    $settings['oauth_client_secret'] = qp_mail_encrypt($clientSecret);
    qp_mail_update_settings($settings);
    echo '<h1>OAuth Success</h1><div class="alert alert-success">Refresh token saved. You can now close this window and return to the Mail settings.</div>';
}
echo '</div>';
require_once __DIR__ . '/inc/footer.php';
exit;
