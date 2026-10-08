<?php
if (!defined('QLOPY_INIT')) define('QLOPY_INIT', true);
require_once __DIR__ . '/../auth.php';

// Centralized logout: revoke token and clear cookie
if (function_exists('qp_clear_auth_cookie')) {
    qp_clear_auth_cookie(true);
} else {
    // fallback: best-effort manual clear
    $cookie = $_COOKIE[QP_SESSION_COOKIE] ?? '';
    if ($cookie) {
        $parts = explode('|', $cookie);
        if (count($parts) >= 2 && function_exists('revoke_session_token')) {
            revoke_session_token($parts[0], (int)$parts[1]);
        }
    }
    setcookie(QP_SESSION_COOKIE, '', time() - 42000, '/', '', true, true);
    unset($_COOKIE[QP_SESSION_COOKIE]);
}

header('Location: login.php');
exit;
