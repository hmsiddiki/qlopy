<?php
if (!defined('QLOPY_INIT')) { http_response_code(403); exit; }
/**
 * The site email: where the site sends messages meant for its owner (the contact form's last fallback, qCommerce's new-order
 * alert when its own recipient is blank, any plugin's notices). Settings > General > Site email; the installer fills it with the
 * installing administrator's email.
 *
 *   qp_admin_email()   the address to use: the saved site email, else the first administrator's email (sites installed before
 *                      the setting existed), else ''.
 *
 * Changing it is confirmed by email, as WordPress does: the new address is kept as pending and a link valid for 48 hours is sent
 * to it; the change applies only when a signed-in administrator opens that link (so a forged form plus an attacker's inbox is
 * not enough). The old address is told afterwards. Only a hash of the link's token is stored.
 *
 * Options: admin_email (string), admin_email_pending (['email', 'hash', 'expires', 'by'] or []).
 */

const QP_ADMIN_EMAIL_TTL = 172800;   // 48 hours

/** The email of the oldest account with the admin role, or ''. */
function qp_admin_email_first_admin(): string {
    if (!function_exists('db') || !function_exists('table_name')) return '';
    try {
        $st = db()->prepare('SELECT u.email FROM ' . table_name('users') . ' u JOIN ' . table_name('user_meta')
            . " m ON m.user_id = u.id AND m.meta_key = 'role' WHERE m.meta_value IN ('admin', '\"admin\"') ORDER BY u.id ASC LIMIT 1");
        $st->execute();
        $e = (string)($st->fetchColumn() ?: '');
        return filter_var($e, FILTER_VALIDATE_EMAIL) ? $e : '';
    } catch (Throwable $e) {
        return '';
    }
}

/** The saved site email ('' when none is saved yet). */
function qp_admin_email_saved(): string {
    $v = function_exists('get_option_meta') ? get_option_meta('admin_email') : null;
    $v = is_string($v) ? trim($v) : '';
    return filter_var($v, FILTER_VALIDATE_EMAIL) ? $v : '';
}

/** Where to send messages for the site owner. */
function qp_admin_email(): string {
    $e = qp_admin_email_saved();
    if ($e === '') $e = qp_admin_email_first_admin();
    return function_exists('apply_filters') ? (string)apply_filters('qp_admin_email', $e) : $e;
}

/** The change waiting for confirmation: ['email', 'expires', 'by'], or null (none, or expired). */
function qp_admin_email_pending(): ?array {
    $p = function_exists('get_option_meta') ? get_option_meta('admin_email_pending') : null;
    if (!is_array($p) || empty($p['email']) || empty($p['hash']) || (int)($p['expires'] ?? 0) < time()) return null;
    return $p;
}

function qp_admin_email_cancel(): void {
    update_option_meta('admin_email_pending', []);
}

/** The link that confirms a pending change. */
function qp_admin_email_confirm_url(string $token): string {
    $page = 'index.php?page=general-settings&qp_admin_email_confirm=' . rawurlencode($token);
    return function_exists('qp_admin_url') ? qp_admin_url($page) : rtrim(defined('SITE_URL') ? SITE_URL : '', '/') . '/admin/' . $page;
}

function qp_admin_email_site_name(): string {
    $n = function_exists('get_option_meta') ? get_option_meta('site_name') : '';
    return is_string($n) && trim($n) !== '' ? trim($n) : 'your site';
}

/**
 * Ask to change the site email to $new. Returns [status, message]: status is 'sent' (link emailed), 'same' (nothing to do),
 * 'invalid', or 'failed' (the confirmation email could not be sent; nothing is pending).
 */
function qp_admin_email_request(string $new, int $byUser = 0): array {
    $new = trim($new);
    if (!filter_var($new, FILTER_VALIDATE_EMAIL)) return ['invalid', 'The site email is not a valid address.'];
    if (strcasecmp($new, qp_admin_email_saved()) === 0) {
        qp_admin_email_cancel();
        return ['same', ''];
    }
    $pending = qp_admin_email_pending();
    if ($pending && strcasecmp($pending['email'], $new) === 0 && (int)($pending['sent'] ?? 0) > time() - 60) {
        return ['sent', 'A confirmation link was already sent to ' . $new . ' a moment ago. Open it to apply the change.'];
    }
    $token = bin2hex(random_bytes(24));
    update_option_meta('admin_email_pending', ['email' => $new, 'hash' => hash('sha256', $token), 'expires' => time() + QP_ADMIN_EMAIL_TTL, 'sent' => time(), 'by' => $byUser]);

    $site = qp_admin_email_site_name();
    $url = qp_admin_email_confirm_url($token);
    $h = static fn ($s): string => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    $body = '<p>Someone with administrator access to ' . $h($site) . ' asked to make this address the site email, where the site sends messages for its owner.</p>'
          . '<p>To confirm, open this link while signed in as an administrator (it works for 48 hours):</p>'
          . '<p><a href="' . $h($url) . '">' . $h($url) . '</a></p>'
          . '<p>If you did not ask for this, ignore this email: nothing changes.</p>';
    $ok = false;
    if (function_exists('qp_mail')) {
        try { $ok = (bool)qp_mail($new, '[' . $site . '] Confirm the new site email', $body, ['Content-Type' => 'text/html; charset=UTF-8']); }
        catch (Throwable $e) { $ok = false; }
    }
    if (!$ok) {
        qp_admin_email_cancel();
        return ['failed', 'The confirmation email to ' . $new . ' could not be sent, so the site email was not changed. Check Settings > Mail, then try again.'];
    }
    return ['sent', 'A confirmation link was sent to ' . $new . '. The site email changes when you open that link (within 48 hours).'];
}

/**
 * Apply a pending change from its link. The caller makes sure an administrator is signed in. Returns [ok, message].
 */
function qp_admin_email_confirm(string $token): array {
    $p = qp_admin_email_pending();
    if (!$p) return [false, 'This confirmation link has expired or was already used. Change the site email again to get a new one.'];
    if ($token === '' || !hash_equals((string)$p['hash'], hash('sha256', $token))) return [false, 'This confirmation link is not valid.'];
    $old = qp_admin_email_saved();
    update_option_meta('admin_email', $p['email']);
    qp_admin_email_cancel();
    if (function_exists('do_action')) do_action('qp_admin_email_changed', $p['email'], $old);
    // Tell the old address, so a change nobody meant to make does not go unnoticed.
    if ($old !== '' && strcasecmp($old, $p['email']) !== 0 && function_exists('qp_mail')) {
        $site = qp_admin_email_site_name();
        $h = static fn ($s): string => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
        try {
            qp_mail($old, '[' . $site . '] The site email was changed', '<p>The site email of ' . $h($site) . ' was changed from ' . $h($old) . ' to ' . $h($p['email'])
                . '. Messages for the site owner now go there.</p><p>If you did not make this change, sign in and check your administrator accounts.</p>', ['Content-Type' => 'text/html; charset=UTF-8']);
        } catch (Throwable $e) { /* the change itself is done */ }
    }
    return [true, 'The site email is now ' . $p['email'] . '.'];
}
