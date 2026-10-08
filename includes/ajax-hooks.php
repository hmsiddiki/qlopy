<?php
if (!defined('QLOPY_INIT')) { http_response_code(403); exit; }
// initializes the global hooks array if not set
if (!isset($GLOBALS['qlopy_actions'])) {
    // Structure: ['hook_name' => [priority => [ [callback, accepted_args], ... ] ]] ]
    $GLOBALS['qlopy_actions'] = [];
}

if (!isset($GLOBALS['qlopy_filters'])) {
    // Structure: ['filter_name' => [priority => [ [callback, accepted_args], ... ] ]] ]
    $GLOBALS['qlopy_filters'] = [];
}

/**
 * Register a callback to a hook with optional priority and args count.
 * @param string   $hook_name Name of the hook.
 * @param callable $callback  Function to call.
 * @param int      $priority  Priority order, default 10 (lower runs first).
 * @param int      $accepted_args Number of args callback accepts, default 1.
 */
function add_action(string $hook_name, callable $callback, int $priority = 10, int $accepted_args = 1): void {
    global $qlopy_actions;
    if (!isset($qlopy_actions[$hook_name])) {
        $qlopy_actions[$hook_name] = [];
    }
    if (!isset($qlopy_actions[$hook_name][$priority])) {
        $qlopy_actions[$hook_name][$priority] = [];
    }
    // prevent duplicate registration at same priority
    foreach ($qlopy_actions[$hook_name][$priority] as $registered) {
        if ($registered[0] === $callback) {
            return; // callback already registered
        }
    }
    $qlopy_actions[$hook_name][$priority][] = [$callback, $accepted_args];
}

/**
 * Register a filter callback.
 * @param string   $filter_name Name of the filter.
 * @param callable $callback  Function to call.
 * @param int      $priority  Priority order, default 10 (lower runs first).
 * @param int      $accepted_args Number of args callback accepts, default 1.
 */
function add_filter(string $filter_name, callable $callback, int $priority = 10, int $accepted_args = 1): void {
    global $qlopy_filters;
    if (!isset($qlopy_filters[$filter_name])) {
        $qlopy_filters[$filter_name] = [];
    }
    if (!isset($qlopy_filters[$filter_name][$priority])) {
        $qlopy_filters[$filter_name][$priority] = [];
    }
    // prevent duplicate registration at same priority
    foreach ($qlopy_filters[$filter_name][$priority] as $registered) {
        if ($registered[0] === $callback) {
            return; // callback already registered
        }
    }
    $qlopy_filters[$filter_name][$priority][] = [$callback, $accepted_args];
}

/**
 * Apply all filter callbacks to a value.
 * @param string $filter_name Name of filter.
 * @param mixed $value Value to filter.
 * @param mixed ...$args Additional arguments passed to callbacks.
 * @return mixed Filtered value.
 */
function apply_filters(string $filter_name, mixed $value, ...$args): mixed {
    global $qlopy_filters;
    if (empty($qlopy_filters[$filter_name])) {
        return $value; // no filters registered
    }
    ksort($qlopy_filters[$filter_name]);
    foreach ($qlopy_filters[$filter_name] as $priority => $callbacks) {
        foreach ($callbacks as $callback_info) {
            list($callback, $accepted_args) = $callback_info;
            $callback_args = array_merge([$value], array_slice($args, 0, $accepted_args - 1));
            $value = call_user_func_array($callback, $callback_args);
        }
    }
    return $value;
}

/**
 * Run all callbacks attached to a hook, in order of priority.
 * @param string $hook_name Name of hook.
 * @param mixed ...$args Arguments passed to callbacks.
 * @return bool True if callbacks executed, false if none.
 */
function do_action(string $hook_name, ...$args): bool {
    global $qlopy_actions;
    if (empty($qlopy_actions[$hook_name])) {
        return false; // no callbacks
    }
    ksort($qlopy_actions[$hook_name]);
    foreach ($qlopy_actions[$hook_name] as $priority => $callbacks) {
        foreach ($callbacks as $callback_info) {
            list($callback, $accepted_args) = $callback_info;
            $callback_args = array_slice($args, 0, $accepted_args);
            call_user_func_array($callback, $callback_args);
        }
    }
    return true;
}

// Frontend AJAX: comment submission and preview handlers
if (!function_exists('comment_insert')) {
    // comments API should be provided by includes/comments.php
    if (file_exists(__DIR__ . '/comments.php')) require_once __DIR__ . '/comments.php';
}

add_action('ajax_comment_submit', function($req) {
    header('Content-Type: application/json');
    $object_type = isset($req['object_type']) ? (string)$req['object_type'] : 'post';
    $object_id = isset($req['object_id']) ? (int)$req['object_id'] : 0;
    $content = trim($req['content'] ?? '');
    $author_name = trim($req['author_name'] ?? '');
    $author_email = trim($req['author_email'] ?? '');
    if ($object_id <= 0 || $content === '') { echo json_encode(['error' => 'missing_fields']); return; }
    $data = [
        'object_type' => $object_type,
        'object_id' => $object_id,
        'content' => $content,
        'author_name' => $author_name,
        'author_email' => $author_email,
        'parent_id' => isset($req['parent_id']) ? (int)$req['parent_id'] : null,
        'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
    ];
    if (function_exists('comment_insert')) {
        $res = comment_insert($data);
        if (is_array($res) && isset($res['error'])) { echo json_encode(['error' => $res['error'], 'message' => $res['message'] ?? null]); return; }
        // After insert, inspect status and optionally enqueue notifications
        try {
            if (function_exists('comment_get_by_id')) {
                $c = comment_get_by_id((int)$res);
                $ds = function_exists('get_option_meta') ? (get_option_meta('discussion_settings') ?? []) : [];
                // If moderation notification enabled and comment pending -> notify admins
                if (!empty($ds['email_moderation']) && isset($c['status']) && $c['status'] === 'pending' && function_exists('qp_mail_enqueue')) {
                    // collect admin emails
                    $admins = [];
                    try {
                        $pdo = db();
                        $stmt = $pdo->query('SELECT id,email FROM ' . table_name('users'));
                        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                            $role = function_exists('get_user_meta') ? (get_user_meta((int)$row['id'], 'role') ?? 'subscriber') : 'subscriber';
                            if ($role === 'admin') $admins[] = $row['email'];
                        }
                    } catch (Throwable $_e) { }
                    if (!empty($admins)) {
                        $subject = 'Comment held for moderation';
                        $message = '<p>A new comment by ' . htmlspecialchars($c['author_name'] ?? 'Anonymous') . ' was held for moderation on object ' . htmlspecialchars($c['object_type'] ?? '') . ' #' . intval($c['object_id'] ?? 0) . '.</p>';
                        $message .= '<p>Comment:</p><blockquote>' . nl2br(htmlspecialchars($c['content'] ?? '')) . '</blockquote>';
                        qp_mail_enqueue(['to'=>$admins,'subject'=>$subject,'message'=>$message]);
                    }
                }
                // Email notify anyone: send on any new comment if enabled
                if (!empty($ds['email_notify_anyone']) && isset($c['status']) && $c['status'] === 'approved' && function_exists('qp_mail_enqueue')) {
                    $admins = [];
                    try {
                        $pdo = db();
                        $stmt = $pdo->query('SELECT id,email FROM ' . table_name('users'));
                        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                            $role = function_exists('get_user_meta') ? (get_user_meta((int)$row['id'], 'role') ?? 'subscriber') : 'subscriber';
                            if ($role === 'admin') $admins[] = $row['email'];
                        }
                    } catch (Throwable $_e) { }
                    if (!empty($admins)) {
                        $subject = 'New comment posted';
                        $message = '<p>A new comment by ' . htmlspecialchars($c['author_name'] ?? 'Anonymous') . ' was posted.</p>';
                        $message .= '<p>Comment:</p><blockquote>' . nl2br(htmlspecialchars($c['content'] ?? '')) . '</blockquote>';
                        qp_mail_enqueue(['to'=>$admins,'subject'=>$subject,'message'=>$message]);
                    }
                }
            }
        } catch (Throwable $_e) { }
        echo json_encode(['success' => true, 'id' => $res]); return;
    }
    echo json_encode(['error' => 'no_comment_api']);
}, 10, 1);

add_action('ajax_comment_preview', function($req) {
    header('Content-Type: application/json');
    $content = trim($req['content'] ?? '');
    $author = trim($req['author_name'] ?? 'Anonymous');
    if ($content === '') { echo json_encode(['error' => 'empty_content']); return; }
    $preview = '<div class="comment-preview"><div class="comment-author">' . htmlspecialchars($author) . '</div><div class="comment-content">' . nl2br(htmlspecialchars($content)) . '</div></div>';
    echo json_encode(['success' => true, 'html' => $preview]); return;
}, 10, 1);

// Handle non-AJAX comment form POST submissions (redirect back with status)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'comment_submit' && !(isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')) {
    $object_type = isset($_POST['object_type']) ? (string)$_POST['object_type'] : 'post';
    $object_id = isset($_POST['object_id']) ? (int)$_POST['object_id'] : 0;
    $content = trim($_POST['content'] ?? '');
    $author_name = trim($_POST['author_name'] ?? '');
    $author_email = trim($_POST['author_email'] ?? '');
    $redirect = $_SERVER['HTTP_REFERER'] ?? (defined('SITE_URL') ? SITE_URL . '/' : '/');
    if ($object_id <= 0 || $content === '') {
        $sep = (strpos($redirect, '?') === false) ? '?' : '&';
        header('Location: ' . $redirect . $sep . 'comment_error=missing_fields#comments'); exit;
    }
    $data = [
        'object_type' => $object_type,
        'object_id' => $object_id,
        'content' => $content,
        'author_name' => $author_name,
        'author_email' => $author_email,
        'parent_id' => isset($_POST['parent_id']) ? (int)$_POST['parent_id'] : null,
        'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null
    ];
    if (function_exists('comment_insert')) {
        $result = comment_insert($data);
        if (is_array($result) && isset($result['error'])) { $sep = (strpos($redirect, '?') === false) ? '?' : '&'; header('Location: ' . $redirect . $sep . 'comment_error=' . urlencode($result['error']) . '#comments'); exit; }
        $status = 'pending';
        try {
            if (function_exists('comment_get_by_id')) {
                $c = comment_get_by_id((int)$result);
                if ($c && !empty($c['status'])) {
                    $status = $c['status'];
                    $ds = function_exists('get_option_meta') ? (get_option_meta('discussion_settings') ?? []) : [];
                    if (!empty($ds['email_moderation']) && $status === 'pending' && function_exists('qp_mail_enqueue')) {
                        // notify admins
                        $admins = [];
                        try {
                            $pdo = db();
                            $stmt = $pdo->query('SELECT id,email FROM ' . table_name('users'));
                            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                                $role = function_exists('get_user_meta') ? (get_user_meta((int)$row['id'], 'role') ?? 'subscriber') : 'subscriber';
                                if ($role === 'admin') $admins[] = $row['email'];
                            }
                        } catch (Throwable $_e) { }
                        if (!empty($admins)) {
                            $subject = 'Comment held for moderation';
                            $message = '<p>A new comment by ' . htmlspecialchars($c['author_name'] ?? 'Anonymous') . ' was held for moderation on object ' . htmlspecialchars($c['object_type'] ?? '') . ' #' . intval($c['object_id'] ?? 0) . '.</p>';
                            $message .= '<p>Comment:</p><blockquote>' . nl2br(htmlspecialchars($c['content'] ?? '')) . '</blockquote>';
                            qp_mail_enqueue(['to'=>$admins,'subject'=>$subject,'message'=>$message]);
                        }
                    }
                    if (!empty($ds['email_notify_anyone']) && $status === 'approved' && function_exists('qp_mail_enqueue')) {
                        $admins = [];
                        try {
                            $pdo = db();
                            $stmt = $pdo->query('SELECT id,email FROM ' . table_name('users'));
                            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                                $role = function_exists('get_user_meta') ? (get_user_meta((int)$row['id'], 'role') ?? 'subscriber') : 'subscriber';
                                if ($role === 'admin') $admins[] = $row['email'];
                            }
                        } catch (Throwable $_e) { }
                        if (!empty($admins)) {
                            $subject = 'New comment posted';
                            $message = '<p>A new comment by ' . htmlspecialchars($c['author_name'] ?? 'Anonymous') . ' was posted.</p>';
                            $message .= '<p>Comment:</p><blockquote>' . nl2br(htmlspecialchars($c['content'] ?? '')) . '</blockquote>';
                            qp_mail_enqueue(['to'=>$admins,'subject'=>$subject,'message'=>$message]);
                        }
                    }
                }
            }
        } catch (Throwable $_e) {}
        $sep = (strpos($redirect, '?') === false) ? '?' : '&';
        header('Location: ' . $redirect . $sep . 'comment_submitted=1&comment_status=' . urlencode($status) . '#comment-' . intval($result)); exit;
    }
    $sep = (strpos($redirect, '?') === false) ? '?' : '&'; header('Location: ' . $redirect . $sep . 'comment_error=no_comment_api#comments'); exit;
}

