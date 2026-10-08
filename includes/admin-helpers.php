<?php
if (!defined('QLOPY_INIT')) { http_response_code(403); exit; }
// Simple admin nonce helpers. Prefer central `qp_admin_create_nonce`/`qp_admin_verify_nonce`.
// When the central API is unavailable, fall back to storing per-user nonces
// in user_meta under the `qp_nonces` key. This removes reliance on PHP
// session storage so the legacy session handler can be safely removed.

function admin_nonce_create($action = '') {
    if (function_exists('qp_admin_create_nonce')) {
        return qp_admin_create_nonce($action, 3600);
    }
    $user = null;
    if (function_exists('get_logged_in_user')) $user = get_logged_in_user();
    if (empty($user) || empty($user['id'])) return '';
    $user_id = (int)$user['id'];
    try { $token = bin2hex(random_bytes(12)); } catch (Exception $e) { $token = bin2hex(openssl_random_pseudo_bytes(12)); }
    $nonces = get_user_meta($user_id, 'qp_nonces') ?? [];
    if (!is_array($nonces)) $nonces = [];
    $now = time();
    // prune expired
    foreach ($nonces as $act => $tokens) {
        if (!is_array($tokens)) continue;
        foreach ($tokens as $t => $exp) {
            if ($exp < $now) unset($nonces[$act][$t]);
        }
        if (empty($nonces[$act])) unset($nonces[$act]);
    }
    $action_key = ($action ?: 'default');
    $nonces[$action_key] = $nonces[$action_key] ?? [];
    $nonces[$action_key][$token] = $now + 3600;
    update_user_meta($user_id, 'qp_nonces', $nonces);
    return $token;
}

function admin_nonce_field($action = '') {
    $token = admin_nonce_create($action);
    return '<input type="hidden" name="admin_nonce" value="' . htmlspecialchars($token, ENT_QUOTES) . '">';
}

function admin_nonce_verify($token, $action = '', $max_age = 900) {
    if (function_exists('qp_admin_verify_nonce')) {
        return qp_admin_verify_nonce($token, $action);
    }
    if (empty($token)) return false;
    $user = null;
    if (function_exists('get_logged_in_user')) $user = get_logged_in_user();
    if (empty($user) || empty($user['id'])) return false;
    $user_id = (int)$user['id'];
    $nonces = get_user_meta($user_id, 'qp_nonces') ?? [];
    if (!is_array($nonces) || empty($nonces[$action]) || !is_array($nonces[$action])) return false;
    $tokens = $nonces[$action];
    $now = time();
    // remove expired first
    foreach ($tokens as $t => $exp) { if ($exp < $now) unset($tokens[$t]); }
    if (empty($tokens)) { unset($nonces[$action]); update_user_meta($user_id, 'qp_nonces', $nonces); return false; }
    if (!isset($tokens[$token])) { $nonces[$action] = $tokens; update_user_meta($user_id, 'qp_nonces', $nonces); return false; }
    $exp = $tokens[$token];
    if ($exp < $now) { unset($tokens[$token]); $nonces[$action] = $tokens; update_user_meta($user_id, 'qp_nonces', $nonces); return false; }
    // consume
    unset($tokens[$token]);
    if (empty($tokens)) unset($nonces[$action]); else $nonces[$action] = $tokens;
    update_user_meta($user_id, 'qp_nonces', $nonces);
    return true;
}

?>
