<?php
if (!defined('QLOPY_INIT')) { http_response_code(403); exit; }
// includes/qp-cron.php
// Minimal cron core: lightweight hooks + scheduling helpers
if (!defined('QP_CRON_CORE_INCLUDED')) define('QP_CRON_CORE_INCLUDED', true);

function &qp_cron_hook_registry(): array { static $r = []; return $r; }

function add_qp_cron_action(string $hook, callable $cb) {
    // prefer global add_action if present
    if (function_exists('add_action')) return add_action($hook, $cb);
    $map = &qp_cron_hook_registry(); if (!isset($map[$hook])) $map[$hook] = []; $map[$hook][] = $cb; return true;
}

function do_qp_cron_action(string $hook, array $args = []) {
    if (function_exists('do_action')) return do_action($hook, $args);
    $map = &qp_cron_hook_registry(); $results = [];
    if (empty($map[$hook])) return $results;
    foreach ($map[$hook] as $cb) {
        // Defensive: normalize args so associative arrays become a single argument
        if (is_array($args)) {
            $keys = array_keys($args);
            $isList = ($keys === range(0, count($args) - 1));
            if (!$isList) {
                $callArgs = [$args];
            } else {
                $callArgs = $args;
            }
        } else {
            $callArgs = [$args];
        }
        $results[] = call_user_func_array($cb, $callArgs);
    }
    return $results;
}

function qp_cron_key_option(): string { return 'qp_cron_key'; }

function qp_cron_set_key(string $key) {
    // Normalize key: remove surrounding quotes and whitespace
    $normalized = $key;
    // strip surrounding single/double quotes and trim whitespace/newlines
    $normalized = preg_replace('/^["\'\s]+|["\'\s]+$/u', '', $normalized);
    $normalized = trim($normalized);
    if (function_exists('update_option_meta')) return update_option_meta(qp_cron_key_option(), $normalized);
    // fallback to site_options table
    if (!function_exists('db')) return false;
    $pdo = db(); $stmt = $pdo->prepare('INSERT INTO ' . table_name('site_options') . " (option_name, option_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE option_value = VALUES(option_value)");
    return (bool)$stmt->execute([qp_cron_key_option(), $normalized]);
}

function qp_cron_get_key() {
    if (function_exists('get_option_meta')) {
        $val = get_option_meta(qp_cron_key_option());
        if ($val === null) return null;
        $normalized = preg_replace('/^["\'\s]+|["\'\s]+$/u', '', $val);
        $normalized = trim($normalized);
        if ($normalized !== $val) {
            // persist normalized value
            if (function_exists('update_option_meta')) update_option_meta(qp_cron_key_option(), $normalized);
            return $normalized;
        }
        return $val;
    }
    if (!function_exists('db')) return null;
    $pdo = db(); $stmt = $pdo->prepare('SELECT option_value FROM ' . table_name('site_options') . ' WHERE option_name = ? LIMIT 1'); $stmt->execute([qp_cron_key_option()]);
    $v = $stmt->fetchColumn();
    if ($v === false || $v === null) return null;
    $normalized = preg_replace('/^["\'\s]+|["\'\s]+$/u', '', $v);
    $normalized = trim($normalized);
    if ($normalized !== $v) {
        // save normalized back to DB
        qp_cron_set_key($normalized);
        return $normalized;
    }
    return $v ?: null;
}

// Ensure a runner key exists; generate and save a normalized key if missing.
function qp_cron_ensure_key(int $bytes = 16) {
    $k = qp_cron_get_key();
    if ($k) return $k;
    try {
        $new = bin2hex(random_bytes($bytes));
    } catch (Exception $e) {
        $new = bin2hex(openssl_random_pseudo_bytes($bytes));
    }
    qp_cron_set_key($new);
    return qp_cron_get_key();
}

function qp_is_admin_request(): bool {
    if (defined('IN_ADMIN') && IN_ADMIN === true) return true;
    if (php_sapi_name() === 'cli') return true;
    return false;
}

function qp_schedule_single_event(int $timestamp, string $hook, array $args = [], int $recurrence = 0) {
    // prefer queue if available
    if (file_exists(__DIR__ . '/qp-cron-queue.php')) {
        require_once __DIR__ . '/qp-cron-queue.php';
        return qp_queue_task($hook, $timestamp, $args, 3, 60, $recurrence);
    }
    return false;
}

function qp_next_scheduled(string $hook): ?int {
    if (file_exists(__DIR__ . '/qp-cron-queue.php')) {
        require_once __DIR__ . '/qp-cron-queue.php'; $pdo = db(); qp_cron_queue_install($pdo);
        $stmt = $pdo->prepare('SELECT MIN(next_run) FROM ' . qp_cron_queue_table() . ' WHERE hook = ? AND status IN ("pending","running")'); $stmt->execute([$hook]); $v = $stmt->fetchColumn(); return $v ? (int)$v : null;
    }
    return null;
}

/**
 * Return the next scheduled run for a hook as a site-formatted datetime string (or null).
 * Accepts an optional PHP date format string to override the site format.
 */
function qp_next_scheduled_formatted(string $hook, ?string $fmt = null): ?string {
    $next = qp_next_scheduled($hook);
    if ($next === null) return null;
    if (function_exists('format_site_datetime')) {
        return format_site_datetime((int)$next, $fmt);
    }
    return date($fmt ?? 'Y-m-d H:i:s', (int)$next);
}

function qp_get_scheduled_events(): array {
    if (file_exists(__DIR__ . '/qp-cron-queue.php')) { require_once __DIR__ . '/qp-cron-queue.php'; return qp_list_queue(500); }
    return [];
}

function qp_run_due_events(int $limit = 50): array {
    if (file_exists(__DIR__ . '/qp-cron-queue.php')) { require_once __DIR__ . '/qp-cron-queue.php'; return qp_process_queue($limit); }
    return ['processed'=>[], 'errors'=>[]];
}

// Process scheduled posts: find posts with status='scheduled' and published_at <= NOW()
function qp_process_scheduled_posts(int $limit = 50): array {
    if (!function_exists('db')) return ['published'=>[], 'errors'=>[]];
    $pdo = db();
    $table = table_name('posts');
    $published = [];
    $errors = [];

    try {
        // Select candidate posts to publish
        $limit = (int)$limit;
        $sql = "SELECT id FROM {$table} WHERE status = 'scheduled' AND published_at <= UTC_TIMESTAMP() ORDER BY published_at ASC LIMIT {$limit}";
        $stmt = $pdo->query($sql);
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_COLUMN) : [];
        if (empty($rows)) return ['published'=>[], 'errors'=>[]];

        $pdo->beginTransaction();
        $upd = $pdo->prepare("UPDATE {$table} SET status = 'published', updated_at = UTC_TIMESTAMP() WHERE id = ?");
        foreach ($rows as $id) {
            try {
                $upd->execute([$id]);
                // Fire hook for post publish if available
                if (function_exists('do_action')) do_action('qp_post_published', ['post_id' => (int)$id]);
                // Ensure cron log helper is available and record the publish so admin logs show it
                if (!function_exists('qp_cron_log') && file_exists(__DIR__ . '/qp-cron-queue.php')) {
                    require_once __DIR__ . '/qp-cron-queue.php';
                }
                if (function_exists('qp_cron_log')) {
                    // task_id is null for scheduled-post publishes; include post_id in message
                    qp_cron_log(null, 'qp_post_published', 'success', json_encode(['post_id' => (int)$id]));
                }
                $published[] = (int)$id;
            } catch (Exception $e) {
                $errors[] = "Failed to publish {$id}: " . $e->getMessage();
            }
        }
        $pdo->commit();
    } catch (Exception $e) {
        try { $pdo->rollBack(); } catch (Exception $_) {}
        $errors[] = $e->getMessage();
    }

    return ['published'=>$published, 'errors'=>$errors];
}
