<?php
if (!defined('QLOPY_INIT')) { http_response_code(403); exit; }
// Default install-complete listener: enqueue welcome email to admin
// Safe to include during installer even before full bootstrap.
if (!function_exists('add_action')) {
    // Try to include hook helpers if not already loaded
    $hooks = __DIR__ . '/ajax-hooks.php';
    if (file_exists($hooks)) require_once $hooks;
}
// Ensure mail helper is available (enqueue will persist for later delivery)
if (!function_exists('qp_mail_enqueue')) {
    $mail = __DIR__ . '/qp-mail.php';
    if (file_exists($mail)) require_once $mail;
}

add_action('qp_install_complete', function($admin_id, $admin_user, $admin_email, $site_name, $site_url) {
    // Don't break install if mail system unavailable
    if (!function_exists('qp_mail_enqueue')) return;

    $subject = "Welcome to {$site_name}";

    // Plain-text fallback
    $plain = "Hello " . ($admin_user ?: 'Administrator') . ",\n\n";
    $plain .= "Your site has been created successfully at: {$site_url}\n\n";
    $plain .= "You can log in as {$admin_user} using the credentials you provided during installation.\n\n";
    $plain .= "Regards,\nThe Site Team";

    // HTML message
    $html = '<!doctype html><html><body>' .
        '<p>Hello ' . htmlspecialchars($admin_user ?: 'Administrator') . ',</p>' .
        '<p>Your site has been created successfully at: <a href="' . htmlspecialchars($site_url) . '">' . htmlspecialchars($site_url) . '</a></p>' .
        '<p>You can log in as <strong>' . htmlspecialchars($admin_user) . '</strong> using the credentials you provided during installation.</p>' .
        '<p>Regards,<br/>The Site Team</p>' .
        '</body></html>';

    // Enqueue email (quietly ignore failures). Use headers hinting HTML but PHPMailer will render HTML body.
    try {
        qp_mail_enqueue([
            'to' => $admin_email,
            'subject' => $subject,
            'message' => $html,
            'headers' => ['X-QP-Plain'=>'1'], // hint only
            'attachments' => [],
        ]);
    } catch (Throwable $_e) {
        // ignore - installer should not fail for mail enqueue problems
    }
}, 10, 5);
