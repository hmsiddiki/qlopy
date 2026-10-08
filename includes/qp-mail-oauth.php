<?php
if (!defined('QLOPY_INIT')) { http_response_code(403); exit; }
// includes/qp-mail-oauth.php
// Lightweight XOAUTH2 provider for PHPMailer when League packages aren't installed.

if (!defined('QLP_INCLUDED_QP_MAIL_OAUTH')) define('QLP_INCLUDED_QP_MAIL_OAUTH', true);

class QP_Mailer_OAuthProvider implements \PHPMailer\PHPMailer\OAuthTokenProvider
{
    protected $clientId;
    protected $clientSecret;
    protected $refreshToken;
    protected $userEmail;
    protected $provider; // 'google' or 'microsoft'
    protected $tenant;

    public function __construct(array $opts)
    {
        $this->clientId = $opts['clientId'] ?? '';
        $this->clientSecret = $opts['clientSecret'] ?? '';
        $this->refreshToken = $opts['refreshToken'] ?? '';
        $this->userEmail = $opts['userEmail'] ?? '';
        $this->provider = strtolower($opts['provider'] ?? 'google');
        $this->tenant = $opts['tenant'] ?? 'common';
    }

    protected function http_post(string $url, array $data): array
    {
        $body = http_build_query($data);
        // prefer curl
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            $resp = curl_exec($ch);
            $err = curl_error($ch);
            curl_close($ch);
            if ($resp === false) return ['error' => $err ?: 'curl_error'];
            $json = json_decode($resp, true);
            return is_array($json) ? $json : ['error' => 'invalid_json'];
        }
        // fallback to file_get_contents
        $opts = [
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
                'content' => $body,
                'timeout' => 10,
            ]
        ];
        $context = stream_context_create($opts);
        $resp = @file_get_contents($url, false, $context);
        if ($resp === false) return ['error' => 'request_failed'];
        $json = json_decode($resp, true);
        return is_array($json) ? $json : ['error' => 'invalid_json'];
    }

    // Exchange refresh token for access token. Returns array with access_token/expires_in or ['error'=>...]
    protected function fetchAccessToken()
    {
        if ($this->provider === 'microsoft' || $this->provider === 'azure' || $this->provider === 'office365') {
            $tenant = $this->tenant ?: 'common';
            $url = "https://login.microsoftonline.com/{$tenant}/oauth2/v2.0/token";
            $data = [
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
                'grant_type' => 'refresh_token',
                'refresh_token' => $this->refreshToken,
                'scope' => 'https://outlook.office.com/SMTP.Send offline_access'
            ];
            return $this->http_post($url, $data);
        }

        // default to Google
        $url = 'https://oauth2.googleapis.com/token';
        $data = [
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'grant_type' => 'refresh_token',
            'refresh_token' => $this->refreshToken,
        ];
        return $this->http_post($url, $data);
    }

    // Build the base64 oauth string expected by PHPMailer
    public function getOauth64()
    {
        // Try persistent cache (qp_mail settings) first if available
        try {
            if (function_exists('qp_mail_get_oauth_cache')) {
                $cache = qp_mail_get_oauth_cache();
                if (!empty($cache['access_token']) && !empty($cache['expiry']) && time() < intval($cache['expiry'])) {
                    $access = $cache['access_token'];
                    $str = 'user=' . $this->userEmail . "\001auth=Bearer " . $access . "\001\001";
                    return base64_encode($str);
                }
            }
        } catch (Throwable $_t) { /* ignore cache errors */ }

        // No valid cache found - fetch a fresh access token
        $resp = $this->fetchAccessToken();
        if (!is_array($resp) || empty($resp['access_token'])) {
            throw new Exception('OAuth token refresh failed: ' . json_encode($resp));
        }
        $access = $resp['access_token'];
        $expires = isset($resp['expires_in']) ? intval($resp['expires_in']) : 3600;

        // Store in persistent cache if available
        try {
            if (function_exists('qp_mail_set_oauth_cache')) {
                qp_mail_set_oauth_cache($access, time() + max(60, $expires - 60));
            }
        } catch (Throwable $_t) { }

        $str = 'user=' . $this->userEmail . "\001auth=Bearer " . $access . "\001\001";
        return base64_encode($str);
    }
}
