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

/*
 * Fault isolation (C10). A hook callback that throws is logged, attributed to its plugin and skipped: the
 * remaining callbacks still run, a filter keeps the value it had before the failing callback, and the request
 * goes on. A plugin file that throws while loading is skipped and the hooks it had added are taken back.
 * Define QP_HOOKS_STRICT (tests, development) to rethrow instead.
 */

/** A readable name for a callback: "Class::method", "function", or "Closure@path:line". */
function qp_callback_label($callback): string {
    try {
        if (is_string($callback)) return $callback;
        if (is_array($callback) && count($callback) === 2) {
            return (is_object($callback[0]) ? get_class($callback[0]) : (string)$callback[0]) . '::' . (string)$callback[1];
        }
        if ($callback instanceof Closure) {
            $r = new ReflectionFunction($callback);
            return 'Closure@' . qp_fault_short_path((string)$r->getFileName()) . ':' . $r->getStartLine();
        }
        if (is_object($callback)) return get_class($callback) . '::__invoke';
    } catch (Throwable $_) {}
    return 'callback';
}

/** The file a callback is defined in, used to name the plugin at fault. */
function qp_callback_file($callback): string {
    try {
        if ($callback instanceof Closure || is_string($callback)) return (string)(new ReflectionFunction($callback))->getFileName();
        if (is_array($callback) && count($callback) === 2) return (string)(new ReflectionMethod($callback[0], (string)$callback[1]))->getFileName();
        if (is_object($callback)) return (string)(new ReflectionMethod($callback, '__invoke'))->getFileName();
    } catch (Throwable $_) {}
    return '';
}

function qp_fault_short_path(string $file): string {
    $file = str_replace('\\', '/', $file);
    $pos = strpos($file, '/content/');
    return $pos === false ? basename($file) : substr($file, $pos + 1);
}

/** The plugin slug a file belongs to (content/plugins/<slug>/...), or '' for core and theme code. */
function qp_fault_plugin_of(string ...$files): string {
    foreach ($files as $file) {
        if (preg_match('~/plugins/([^/]+)/~', str_replace('\\', '/', $file), $m)) return $m[1];
    }
    return '';
}

/**
 * Records one fault: error_log line, per-request list (qp_hook_errors, for callers such as the cron queue that
 * must know a hook failed), and the persistent per-plugin record the Plugins screen shows.
 */
function qp_fault_record(string $where, string $plugin, Throwable $e, string $label = ''): void {
    $GLOBALS['qp_hook_error_count'] = (int)($GLOBALS['qp_hook_error_count'] ?? 0) + 1;
    $message = get_class($e) . ': ' . $e->getMessage() . ' in ' . qp_fault_short_path($e->getFile()) . ':' . $e->getLine();
    $GLOBALS['qp_hook_last_error'] = $where . ($label !== '' ? ' (' . $label . ')' : '') . ': ' . $message;
    if (count($GLOBALS['qp_hook_errors'] ?? []) < 50) {
        $GLOBALS['qp_hook_errors'][] = ['where' => $where, 'callback' => $label, 'plugin' => $plugin, 'message' => $message];
    }
    error_log('Qlopy: ' . ($plugin !== '' ? 'plugin ' . $plugin . ', ' : '') . $GLOBALS['qp_hook_last_error']);
    qp_fault_remember($plugin !== '' ? $plugin : 'core', $where, $label, $message, false);
}

/**
 * Exception classes a hook callback throws on purpose, as a message to the code that applied the hook. They pass
 * through apply_filters()/do_action() unchanged, exactly as before C10. InvalidArgumentException is the core's own
 * contract ("throw it from qpmeta_sanitize_field to refuse a value", includes/qpmeta.php). A plugin that uses its
 * own exception the same way registers it here; everything else a callback throws is a bug and is isolated.
 */
function qp_hooks_pass_through(string $class): void {
    $GLOBALS['qp_hook_pass_through'][$class] = true;
}

/**
 * For a hook whose callbacks have no reason to signal anything (a registry such as a list of extensions), the code
 * that owns it can ask for every exception to be isolated, signals included, so no single callback can abort it.
 */
function qp_hooks_isolate_all(string $hook): void {
    $GLOBALS['qp_hook_isolate_all'][$hook] = true;
}

function qp_hook_is_signal(Throwable $e, string $hook = ''): bool {
    if ($hook !== '' && isset($GLOBALS['qp_hook_isolate_all'][$hook])) return false;
    if ($e instanceof InvalidArgumentException) return true;
    foreach ($GLOBALS['qp_hook_pass_through'] ?? [] as $class => $_) {
        if ($e instanceof $class) return true;
    }
    return false;
}

/** A hook callback threw. A signal exception (above) is rethrown; so is everything under QP_HOOKS_STRICT. */
function qp_hook_failed(string $hook, $callback, Throwable $e): void {
    if (qp_hook_is_signal($e, $hook) || (defined('QP_HOOKS_STRICT') && QP_HOOKS_STRICT)) throw $e;
    qp_fault_record('hook ' . $hook, qp_fault_plugin_of(qp_callback_file($callback), $e->getFile()), $e, qp_callback_label($callback));
}

/**
 * Faults kept across requests (option qp_plugin_faults, at most 30 entries): written once at the end of a request
 * that had a new fault, and at most every 10 minutes for the same one, so a failing hook on a busy page does not
 * write on every visit. A fatal error in a plugin is stored with fatal => true; the admin then skips that plugin
 * (see qp_plugin_paused()) until someone presses Resume on the Plugins screen.
 */
function qp_fault_remember(string $plugin, string $where, string $label, string $message, bool $fatal): void {
    $key = substr(md5($plugin . '|' . $where . '|' . $label), 0, 12);
    $GLOBALS['qp_fault_pending'][$key] = ['plugin' => $plugin, 'where' => $where, 'callback' => $label, 'message' => $message, 'fatal' => $fatal];
    if (empty($GLOBALS['qp_fault_shutdown'])) {
        $GLOBALS['qp_fault_shutdown'] = true;
        register_shutdown_function('qp_fault_flush');
    }
}

function qp_fault_flush(): void {
    $pending = $GLOBALS['qp_fault_pending'] ?? [];
    $GLOBALS['qp_fault_pending'] = [];
    if ($pending === [] || !function_exists('get_option_meta') || !function_exists('update_option_meta')) return;
    try {
        $stored = get_option_meta('qp_plugin_faults');
        $stored = is_array($stored) ? $stored : [];
        $now = time();
        $changed = false;
        foreach ($pending as $key => $fault) {
            $old = $stored[$key] ?? null;
            if ($old !== null && !$fault['fatal'] && ($now - (int)($old['last'] ?? 0)) < 600) continue;
            $stored[$key] = $fault + ['count' => (int)($old['count'] ?? 0) + 1, 'first' => (int)($old['first'] ?? $now), 'last' => $now];
            $changed = true;
        }
        if (!$changed) return;
        uasort($stored, static fn ($a, $b) => (int)$b['last'] <=> (int)$a['last']);
        update_option_meta('qp_plugin_faults', array_slice($stored, 0, 30, true));
    } catch (Throwable $_) {
        // Recording a fault must never become a fault of its own.
    }
}

/** @return array<string, array> Stored faults, newest first. */
function qp_plugin_faults(): array {
    if (!function_exists('get_option_meta')) return [];
    $stored = get_option_meta('qp_plugin_faults');
    return is_array($stored) ? $stored : [];
}

/** True when the plugin had a fatal error and nobody has pressed Resume yet. The admin skips such a plugin. */
function qp_plugin_paused(string $slug): bool {
    foreach (qp_plugin_faults() as $fault) {
        if (!empty($fault['fatal']) && ($fault['plugin'] ?? '') === $slug) return true;
    }
    return false;
}

/** Forgets a plugin's faults (Resume / Clear on the Plugins screen). */
function qp_plugin_faults_clear(string $slug = ''): void {
    if (!function_exists('update_option_meta')) return;
    $stored = qp_plugin_faults();
    foreach ($stored as $key => $fault) {
        if ($slug === '' || ($fault['plugin'] ?? '') === $slug) unset($stored[$key]);
    }
    update_option_meta('qp_plugin_faults', $stored);
}

/** Number of hook faults so far in this request; compare before and after a dispatch. */
function qp_hook_error_count(): int {
    return (int)($GLOBALS['qp_hook_error_count'] ?? 0);
}

/**
 * For AJAX entry points: when a handler failed after $before and nothing was sent yet, answer a JSON 500 instead of
 * an empty 200, so the script in the browser sees the failure.
 */
function qp_ajax_report_hook_failure(int $before): void {
    if (qp_hook_error_count() <= $before || headers_sent()) return;
    if (ob_get_level() > 0 && ob_get_length() > 0) return;
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'success' => false, 'message' => 'A plugin failed while handling this request. The error was logged.']);
}

/**
 * Starts recording hook registrations for the plugin file about to be included, so a plugin that fails half-way
 * can have its hooks taken back. Cost: one isset() per add_action/add_filter while a plugin file loads, and a
 * small list that lives only until qp_hooks_log_stop(); nothing is walked or copied.
 */
function qp_hooks_snapshot(string $slug = ''): array {
    $GLOBALS['qp_hook_log'] = [];
    $GLOBALS['qp_loading_plugin'] = $slug;   // a fatal error while this is set happened while loading this plugin
    return [];
}

function qp_hooks_log_stop(): void {
    unset($GLOBALS['qp_hook_log'], $GLOBALS['qp_loading_plugin']);
}

/** Takes back every registration recorded since qp_hooks_snapshot() (newest first; registrations only append). */
function qp_hooks_restore(array $_snapshot = []): void {
    foreach (array_reverse($GLOBALS['qp_hook_log'] ?? []) as [$registry, $hook, $priority]) {
        if (!isset($GLOBALS[$registry][$hook][$priority])) continue;
        array_pop($GLOBALS[$registry][$hook][$priority]);
        if ($GLOBALS[$registry][$hook][$priority] === []) unset($GLOBALS[$registry][$hook][$priority]);
        if (empty($GLOBALS[$registry][$hook])) unset($GLOBALS[$registry][$hook]);
    }
    $GLOBALS['qp_hook_log'] = [];
}

/**
 * Called by every plugin loader when plugin.php threw: takes back the hooks it added and records the fault.
 * The include itself stays in the loader (global scope, as plugin files expect).
 */
function qp_plugin_load_failed(string $slug, Throwable $e, array $snapshot): void {
    if (defined('QP_HOOKS_STRICT') && QP_HOOKS_STRICT) throw $e;
    qp_hooks_restore($snapshot);
    $GLOBALS['qp_plugin_load_errors'][$slug] = $e->getMessage();
    qp_fault_record('loading plugin.php', $slug, $e);
}

/**
 * A fatal error (uncatchable: a redeclared function, memory exhausted, a compile error) in a plugin file is recorded.
 * It pauses the plugin in the admin only when it would lock the owner out: it happened while the plugin file was
 * loading, or during an admin request. A fatal on one public page (a heavy query running out of memory) is listed
 * on the Plugins screen but pauses nothing.
 */
function qp_fault_on_shutdown(): void {
    $error = error_get_last();
    if (!$error || !in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) return;
    $loading = (string)($GLOBALS['qp_loading_plugin'] ?? '');
    $plugin = $loading !== '' ? $loading : qp_fault_plugin_of((string)$error['file']);
    if ($plugin === '') return;
    $pause = $loading !== '' || (defined('IN_ADMIN') && IN_ADMIN);
    $GLOBALS['qp_fault_pending'] = [];
    qp_fault_remember($plugin, $loading !== '' ? 'fatal error while loading' : 'fatal error', '', $error['message'] . ' in ' . qp_fault_short_path((string)$error['file']) . ':' . $error['line'], $pause);
    qp_fault_flush();
}
if (empty($GLOBALS['qp_fault_shutdown_fatal'])) {
    $GLOBALS['qp_fault_shutdown_fatal'] = true;
    register_shutdown_function('qp_fault_on_shutdown');
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
    if (isset($GLOBALS['qp_hook_log'])) $GLOBALS['qp_hook_log'][] = ['qlopy_actions', $hook_name, $priority];
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
    if (isset($GLOBALS['qp_hook_log'])) $GLOBALS['qp_hook_log'][] = ['qlopy_filters', $filter_name, $priority];
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
            try {
                $value = call_user_func_array($callback, $callback_args);
            } catch (Throwable $e) {
                qp_hook_failed($filter_name, $callback, $e); // $value stays what it was before this callback
            }
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
            try {
                call_user_func_array($callback, $callback_args);
            } catch (Throwable $e) {
                qp_hook_failed($hook_name, $callback, $e);
            }
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
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['action']) && $_POST['action'] === 'comment_submit' && !(isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')) {
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

