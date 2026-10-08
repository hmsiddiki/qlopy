<?php
if (!defined('QLOPY_INIT')) { http_response_code(403); exit; }
// includes/qp-cron-queue.php
// Minimal DB-backed queue and logs for cron tasks
if (!defined('QP_CRON_QUEUE_INCLUDED')) define('QP_CRON_QUEUE_INCLUDED', true);

function qp_cron_queue_table(): string { return table_name('cron_queue'); }
function qp_cron_logs_table(): string { return table_name('cron_logs'); }

function qp_cron_queue_install(PDO $pdo = null): void {
    if ($pdo === null) $pdo = db();
    $table = qp_cron_queue_table();
    $sql = "CREATE TABLE IF NOT EXISTS `{$table}` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `hook` VARCHAR(191) NOT NULL,
        `args` TEXT,
        `origin` VARCHAR(60) DEFAULT 'unknown',
        `status` VARCHAR(20) NOT NULL DEFAULT 'pending',
        `attempts` INT NOT NULL DEFAULT 0,
        `max_attempts` INT NOT NULL DEFAULT 3,
        `next_run` INT NOT NULL DEFAULT 0,
        `recurrence` INT NOT NULL DEFAULT 0,
        `backoff` INT NOT NULL DEFAULT 60,
        `last_error` TEXT,
        `created_at` INT NOT NULL,
        `updated_at` INT NOT NULL,
        PRIMARY KEY (`id`),
        INDEX (`status`),
        INDEX (`next_run`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    $pdo->exec($sql);
    // Ensure `origin` column exists for older installations (migration)
    try {
        $pdo->exec("ALTER TABLE {$table} ADD COLUMN `origin` VARCHAR(60) DEFAULT 'unknown'");
    } catch (Throwable $_e) {
        // ignore - column may already exist or ALTER not supported
    }
    // Backfill any NULL/empty origins to 'unknown'
    try {
        $pdo->exec("UPDATE {$table} SET `origin` = 'unknown' WHERE `origin` IS NULL OR `origin` = ''");
    } catch (Throwable $_e) {
        // ignore
    }
}


function qp_cron_logs_install(PDO $pdo = null): void {
    if ($pdo === null) $pdo = db();
    $table = qp_cron_logs_table();
    $sql = "CREATE TABLE IF NOT EXISTS `{$table}` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `task_id` BIGINT UNSIGNED DEFAULT NULL,
        `hook` VARCHAR(191) DEFAULT NULL,
        `status` VARCHAR(20) DEFAULT NULL,
        `message` TEXT,
        `created_at` INT NOT NULL,
        PRIMARY KEY (`id`),
        INDEX (`task_id`),
        INDEX (`hook`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    $pdo->exec($sql);
}

function qp_cron_log($task_id, $hook, $status, $message = null) {
    // Allow admins to disable cron logging to prevent large cron_logs growth.
    // Default: logging is disabled unless site option `cron_logging` is enabled (truthy).
    $enabled = false;
    // Prefer the app helper if available
    if (function_exists('get_option_meta')) {
        $val = get_option_meta('cron_logging');
        if ($val !== null) {
            $enabled = is_bool($val) ? $val : ((string)$val === '1' || (int)$val === 1);
        }
    } else {
        // Fallback: read directly from DB (useful for CLI runner which may not include auth helpers)
        if (function_exists('db') && function_exists('table_name')) {
            try {
                $pdo = db();
                $stmt = $pdo->prepare("SELECT option_value FROM " . table_name('site_options') . " WHERE option_name = ? LIMIT 1");
                $stmt->execute(['cron_logging']);
                $v = $stmt->fetchColumn();
                if ($v !== false && $v !== null) {
                    $decoded = json_decode($v, true);
                    $val = is_null($decoded) ? $v : $decoded;
                    $enabled = is_bool($val) ? $val : ((string)$val === '1' || (int)$val === 1);
                }
            } catch (Throwable $_e) {
                $enabled = false;
            }
        }
    }
    if (!$enabled) {
        // Logging disabled: no-op and report success
        return true;
    }
    qp_cron_logs_install();
    $pdo = db();
    $stmt = $pdo->prepare('INSERT INTO ' . qp_cron_logs_table() . ' (task_id, hook, status, message, created_at) VALUES (?, ?, ?, ?, ?)');
    return (bool)$stmt->execute([$task_id ?: null, $hook ?: null, $status ?: null, $message ?: null, time()]);
}

/**
 * Periodic rotation/cleanup for cron logs.
 * Keeps only the most recent $keep rows when both cron logging and auto-clean are enabled.
 * Can be scheduled via qp_schedule_single_event() or queued as a recurring task.
 * Accepts optional args: [keep]
 */
function qp_cron_rotate_logs(array $args = []) {
    // Only run when logging and auto-clean are explicitly enabled
    $logging_on = false; $auto_on = false;
    if (function_exists('get_option_meta')) {
        $logging = get_option_meta('cron_logging');
        $auto = get_option_meta('cron_auto_clean');
        if ($logging !== null) $logging_on = is_bool($logging) ? $logging : ((string)$logging === '1' || (int)$logging === 1);
        if ($auto !== null) $auto_on = is_bool($auto) ? $auto : ((string)$auto === '1' || (int)$auto === 1);
    } else {
        // Fallback: direct DB read
        if (function_exists('db') && function_exists('table_name')) {
            try {
                $pdo = db();
                $stmt = $pdo->prepare("SELECT option_name, option_value FROM " . table_name('site_options') . " WHERE option_name IN ('cron_logging','cron_auto_clean')");
                $stmt->execute();
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                foreach ($rows as $r) {
                    $name = $r['option_name'] ?? '';
                    $v = $r['option_value'] ?? null;
                    $decoded = json_decode($v, true);
                    $val = is_null($decoded) ? $v : $decoded;
                    $flag = is_bool($val) ? $val : ((string)$val === '1' || (int)$val === 1);
                    if ($name === 'cron_logging') $logging_on = $flag;
                    if ($name === 'cron_auto_clean') $auto_on = $flag;
                }
            } catch (Throwable $_e) {
                return false;
            }
        }
    }
    $enabled = ($logging_on && $auto_on);
    if (!$enabled) return false;
    // Default retention: read site option `cron_logs_retention` (fallback 40)
    $keep = 40;
    if (function_exists('get_option_meta')) {
        $opt = get_option_meta('cron_logs_retention');
        if ($opt !== null && is_numeric($opt)) $keep = max(0, (int)$opt);
    } else {
        // CLI fallback: direct DB read
        if (function_exists('db') && function_exists('table_name')) {
            try {
                $pdo = db();
                $stmt = $pdo->prepare("SELECT option_value FROM " . table_name('site_options') . " WHERE option_name = ? LIMIT 1");
                $stmt->execute(['cron_logs_retention']);
                $v = $stmt->fetchColumn();
                if ($v !== false && $v !== null) {
                    $decoded = json_decode($v, true);
                    $val = is_null($decoded) ? $v : $decoded;
                    if (is_numeric($val)) $keep = max(0, (int)$val);
                }
            } catch (Throwable $_e) {
                // ignore and keep default
            }
        }
    }
    if (!empty($args[0]) && is_numeric($args[0])) $keep = (int)$args[0];
    if ($keep <= 0) return false;
    try {
        $pdo = db(); qp_cron_logs_install($pdo);
        $table = qp_cron_logs_table();
        $sql = "DELETE FROM {$table} WHERE id NOT IN (SELECT id FROM (SELECT id FROM {$table} ORDER BY created_at DESC LIMIT " . (int)$keep . ") AS t)";
        $pdo->exec($sql);
        return true;
    } catch (Throwable $_e) {
        return false;
    }
}

/**
 * Remove pending/running cron queue tasks by hook name.
 */
function qp_remove_tasks_by_hook(string $hook): int {
    $pdo = db(); qp_cron_queue_install($pdo);
    $stmt = $pdo->prepare('DELETE FROM ' . qp_cron_queue_table() . ' WHERE hook = ? AND status IN ("pending","running")');
    $stmt->execute([$hook]);
    return $stmt->rowCount();
}

// Register the rotate action so the queue runner can invoke it by hook name.
if (function_exists('add_qp_cron_action')) {
    add_qp_cron_action('qp_cron_rotate_logs', 'qp_cron_rotate_logs');
}

function qp_queue_task(string $hook, int $when = null, array $args = [], int $max_attempts = 3, int $backoff = 60, int $recurrence = 0): int {
    $pdo = db(); qp_cron_queue_install($pdo);
    // If there's already a pending or running task for this hook, return its id to avoid duplicates
    try {
        $stmtCheck = $pdo->prepare('SELECT id FROM ' . qp_cron_queue_table() . ' WHERE hook = ? AND status IN ("pending","running") LIMIT 1');
        $stmtCheck->execute([$hook]);
        $existing = $stmtCheck->fetchColumn();
        if ($existing) return (int)$existing;
    } catch (Throwable $_e) {
        // ignore check failures and continue to attempt insert
    }
    $now = time(); $ts = $when ?: $now;

    // Detect origin: prefer plugin/theme frames using configured directory names, then provided _origin, then admin/includes as fallback.
    $origin = 'unknown';
    $provided_origin = null;
    if (isset($args['_origin']) && is_string($args['_origin'])) {
        $provided_origin = substr($args['_origin'], 0, 60);
        // keep in args for now so callers aren't affected; we'll unset before persisting
    }

    // Load config to obtain directory names (safe-fallbacks used when config missing)
    $cfg = [];
    $cfgPath = __DIR__ . '/../config.php';
    if (file_exists($cfgPath)) {
        try { $cfg = @require $cfgPath; } catch (Throwable $_e) { $cfg = []; }
    }
    if (!is_array($cfg)) $cfg = [];
    $content_dir = isset($cfg['content_dir']) ? trim($cfg['content_dir'], "/\\ ") : 'content';
    $plugins_dir = isset($cfg['plugins_dir']) ? trim($cfg['plugins_dir'], "/\\ ") : 'plugins';
    $themes_dir = isset($cfg['themes_dir']) ? trim($cfg['themes_dir'], "/\\ ") : 'themes';
    $admin_dir = isset($cfg['admin_dir']) ? trim($cfg['admin_dir'], "/\\ ") : 'admin';

    // Prepare fragments to match within backtrace file paths
    $plugin_frag1 = '/' . strtolower($content_dir) . '/' . strtolower($plugins_dir) . '/';
    $plugin_frag2 = '/' . strtolower($plugins_dir) . '/';
    $theme_frag1 = '/' . strtolower($content_dir) . '/' . strtolower($themes_dir) . '/';
    $theme_frag2 = '/' . strtolower($themes_dir) . '/';
    $admin_frag = '/' . strtolower($admin_dir) . '/';

    // Look for plugin/theme frames first (more specific), with a slightly deeper backtrace depth
    $bt = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 12);
    $found = false;
    foreach ($bt as $frame) {
        if (empty($frame['file'])) continue;
        $f = strtolower(str_replace('\\', '/', $frame['file']));
        if (strpos($f, $plugin_frag1) !== false || strpos($f, $plugin_frag2) !== false) { $origin = 'plugin'; $found = true; break; }
        if (strpos($f, $theme_frag1) !== false || strpos($f, $theme_frag2) !== false) { $origin = 'theme'; $found = true; break; }
    }

    if (!$found) {
        // Prefer caller-provided _origin if plugin/theme were not detected
        if ($provided_origin) {
            $origin = $provided_origin;
        } else {
            // Fallback: detect admin or core from the backtrace (legacy behavior)
            foreach ($bt as $frame) {
                if (empty($frame['file'])) continue;
                $f = strtolower(str_replace('\\', '/', $frame['file']));
                if (strpos($f, $admin_frag) !== false) { $origin = 'admin'; $found = true; break; }
                if (strpos($f, '/includes/') !== false) { $origin = 'core'; $found = true; break; }
            }
        }
    }

    // Remove the caller-provided flag from args so stored payloads don't include it
    if (isset($args['_origin'])) unset($args['_origin']);

    $stmt = $pdo->prepare('INSERT INTO ' . qp_cron_queue_table() . ' (hook, args, origin, status, attempts, max_attempts, next_run, recurrence, backoff, last_error, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $ok = $stmt->execute([$hook, json_encode($args), $origin, 'pending', 0, (int)$max_attempts, $ts, (int)$recurrence, (int)$backoff, null, $now, $now]);
    if (!$ok) return 0; return (int)$pdo->lastInsertId();
}

function qp_list_queue(int $limit = 100) {
    $pdo = db(); qp_cron_queue_install($pdo);
    $sql = 'SELECT * FROM ' . qp_cron_queue_table() . ' ORDER BY next_run ASC LIMIT ' . (int)$limit;
    $stmt = $pdo->prepare($sql); $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (function_exists('format_site_datetime')) {
        foreach ($rows as &$r) {
            $r_next = isset($r['next_run']) ? (int)$r['next_run'] : null;
            $r_created = isset($r['created_at']) ? (int)$r['created_at'] : null;
            $r_updated = isset($r['updated_at']) ? (int)$r['updated_at'] : null;
            $r['next_run_formatted'] = $r_next ? format_site_datetime($r_next) : null;
            $r['created_at_formatted'] = $r_created ? format_site_datetime($r_created) : null;
            $r['updated_at_formatted'] = $r_updated ? format_site_datetime($r_updated) : null;
        }
        unset($r);
    }
    return $rows;
}

function qp_get_due_queue(int $limit = 10): array {
    $pdo = db(); qp_cron_queue_install($pdo);
    $now = time(); $sql = 'SELECT * FROM ' . qp_cron_queue_table() . ' WHERE status = ? AND next_run <= ? ORDER BY next_run ASC LIMIT ' . (int)$limit;
    $stmt = $pdo->prepare($sql); $stmt->execute(['pending', $now]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (function_exists('format_site_datetime')) {
        foreach ($rows as &$r) {
            $r_next = isset($r['next_run']) ? (int)$r['next_run'] : null;
            $r_created = isset($r['created_at']) ? (int)$r['created_at'] : null;
            $r_updated = isset($r['updated_at']) ? (int)$r['updated_at'] : null;
            $r['next_run_formatted'] = $r_next ? format_site_datetime($r_next) : null;
            $r['created_at_formatted'] = $r_created ? format_site_datetime($r_created) : null;
            $r['updated_at_formatted'] = $r_updated ? format_site_datetime($r_updated) : null;
        }
        unset($r);
    }
    return $rows;
}

function qp_remove_task_by_id(int $id): bool { $pdo = db(); qp_cron_queue_install($pdo); $stmt = $pdo->prepare('DELETE FROM ' . qp_cron_queue_table() . ' WHERE id = ?'); return (bool)$stmt->execute([$id]); }

function qp_get_task_origin(int $id): ?string {
    $pdo = db(); qp_cron_queue_install($pdo);
    $stmt = $pdo->prepare('SELECT origin FROM ' . qp_cron_queue_table() . ' WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $v = $stmt->fetchColumn();
    if ($v === false) return null;
    return $v === null ? null : (string)$v;
}

function qp_get_task_status(int $id): ?string {
    $pdo = db(); qp_cron_queue_install($pdo);
    $stmt = $pdo->prepare('SELECT status FROM ' . qp_cron_queue_table() . ' WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $v = $stmt->fetchColumn();
    if ($v === false) return null;
    return $v === null ? null : (string)$v;
}

function qp_run_task_by_id(int $id, bool $force = false) {
    $pdo = db(); qp_cron_queue_install($pdo);
    $now = time();
    try {
        if ($force) {
            $stmt = $pdo->prepare('UPDATE ' . qp_cron_queue_table() . ' SET status = ?, attempts = attempts + 1, updated_at = ? WHERE id = ?');
            $stmt->execute(['running', $now, $id]);
        } else {
            $stmt = $pdo->prepare('UPDATE ' . qp_cron_queue_table() . ' SET status = ?, attempts = attempts + 1, updated_at = ? WHERE id = ? AND status = ?');
            $stmt->execute(['running', $now, $id, 'pending']);
        }
        if ($stmt->rowCount() === 0) return ['error' => 'not_claimed'];
        $stmt = $pdo->prepare('SELECT * FROM ' . qp_cron_queue_table() . ' WHERE id = ? LIMIT 1'); $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC); if (!$row) return ['error'=>'not_found'];
        $hook = $row['hook']; $args = json_decode($row['args'] ?? '[]', true) ?: [];
            $out = do_qp_cron_action($hook, $args);
            // Special-case short messages for certain hooks
            if ($hook === 'qlopy_update_checker_login') {
                // For login-triggered update check, avoid dumping the full array
                if (is_array($out) && count($out) > 0) {
                    $msg = 'update found';
                } else {
                    $msg = 'not found';
                }
            } else {
                // Serialize result for logs: prefer readable scalar/bool/null, otherwise JSON
                if (is_null($out) || is_bool($out) || is_scalar($out)) {
                    $msg = var_export($out, true);
                } elseif (is_array($out) && count($out) === 0) {
                    // empty array usually means callbacks ran but returned no value; record as success
                    $msg = 'true';
                } else {
                    $msg = json_encode($out);
                }
            }
            qp_cron_log($id, $hook, 'success', $msg);
        $rec = (int)($row['recurrence'] ?? 0);
        if ($rec > 0) { $next = time() + $rec; $stmt = $pdo->prepare('UPDATE ' . qp_cron_queue_table() . ' SET status = ?, attempts = 0, last_error = NULL, next_run = ?, updated_at = ? WHERE id = ?'); $stmt->execute(['pending', $next, time(), $id]); }
        else { $stmt = $pdo->prepare('UPDATE ' . qp_cron_queue_table() . ' SET status = ?, updated_at = ? WHERE id = ?'); $stmt->execute(['done', time(), $id]); }
        return ['ran'=>[$id=>$out]];
    } catch (Throwable $e) {
        $stmt = $pdo->prepare('SELECT attempts, max_attempts, backoff FROM ' . qp_cron_queue_table() . ' WHERE id = ? LIMIT 1'); $stmt->execute([$id]);
        $info = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['attempts'=>1,'max_attempts'=>3,'backoff'=>60];
        $attempts = (int)$info['attempts']; $max = (int)$info['max_attempts']; $backoff = (int)$info['backoff'];
        $next = time() + ($backoff * $attempts);
        if ($attempts >= $max) {
            $stmt2 = $pdo->prepare('UPDATE ' . qp_cron_queue_table() . ' SET status = ?, last_error = ?, updated_at = ? WHERE id = ?'); $stmt2->execute(['failed', $e->getMessage(), time(), $id]);
            qp_cron_log($id, $hook ?? '', 'failed', $e->getMessage());
            return ['error'=>$e->getMessage()];
        } else {
            $stmt2 = $pdo->prepare('UPDATE ' . qp_cron_queue_table() . ' SET status = ?, next_run = ?, last_error = ?, updated_at = ? WHERE id = ?'); $stmt2->execute(['pending', $next, $e->getMessage(), time(), $id]);
            qp_cron_log($id, $hook ?? '', 'retry', $e->getMessage());
            return ['scheduled_retry'=>true];
        }
    }
}

function qp_process_queue(int $limit = 10): array {
    $pdo = db(); qp_cron_queue_install($pdo);
    $now = time(); $results = ['processed'=>[], 'errors'=>[]];
    $tasks = qp_get_due_queue($limit);
    foreach ($tasks as $task) {
        $id = (int)$task['id'];
        try {
            $stmt = $pdo->prepare('UPDATE ' . qp_cron_queue_table() . ' SET status = ?, attempts = attempts + 1, updated_at = ? WHERE id = ? AND status = ? AND next_run <= ?');
            $stmt->execute(['running', $now, $id, 'pending', $now]);
            if ($stmt->rowCount() === 0) continue;
            $stmt = $pdo->prepare('SELECT * FROM ' . qp_cron_queue_table() . ' WHERE id = ? LIMIT 1'); $stmt->execute([$id]); $row = $stmt->fetch(PDO::FETCH_ASSOC); if (!$row) continue;
            $hook = $row['hook']; $args = json_decode($row['args'] ?? '[]', true) ?: [];
                $out = do_qp_cron_action($hook, $args);
                // Special-case short messages for certain hooks
                if ($hook === 'qlopy_update_checker_login') {
                    if (is_array($out) && count($out) > 0) {
                        $msg = 'update found';
                    } else {
                        $msg = 'not found';
                    }
                } else {
                    // Serialize result for logs: prefer readable scalar/bool/null, otherwise JSON
                    if (is_null($out) || is_bool($out) || is_scalar($out)) {
                        $msg = var_export($out, true);
                    } elseif (is_array($out) && count($out) === 0) {
                        $msg = 'true';
                    } else {
                        $msg = json_encode($out);
                    }
                }
                qp_cron_log($id, $hook, 'success', $msg);
            $rec = (int)($row['recurrence'] ?? 0);
            if ($rec > 0) { $next = time() + $rec; $stmt = $pdo->prepare('UPDATE ' . qp_cron_queue_table() . ' SET status = ?, attempts = 0, last_error = NULL, next_run = ?, updated_at = ? WHERE id = ?'); $stmt->execute(['pending', $next, time(), $id]); }
            else { $stmt = $pdo->prepare('UPDATE ' . qp_cron_queue_table() . ' SET status = ?, updated_at = ? WHERE id = ?'); $stmt->execute(['done', time(), $id]); }
            $results['processed'][$id] = ['hook'=>$hook,'result'=>$out];
        } catch (Throwable $e) {
            try {
                $stmt = $pdo->prepare('SELECT attempts, max_attempts, backoff FROM ' . qp_cron_queue_table() . ' WHERE id = ? LIMIT 1'); $stmt->execute([$id]); $info = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['attempts'=>1,'max_attempts'=>3,'backoff'=>60];
                $attempts = (int)$info['attempts']; $max = (int)$info['max_attempts']; $backoff = (int)$info['backoff']; $next = time() + ($backoff * $attempts);
                if ($attempts >= $max) { $stmt2 = $pdo->prepare('UPDATE ' . qp_cron_queue_table() . ' SET status = ?, last_error = ?, updated_at = ? WHERE id = ?'); $stmt2->execute(['failed', $e->getMessage(), time(), $id]); qp_cron_log($id, $row['hook'] ?? '', 'failed', $e->getMessage()); $results['errors'][$id] = $e->getMessage(); }
                else { $stmt2 = $pdo->prepare('UPDATE ' . qp_cron_queue_table() . ' SET status = ?, next_run = ?, last_error = ?, updated_at = ? WHERE id = ?'); $stmt2->execute(['pending', $next, $e->getMessage(), time(), $id]); qp_cron_log($id, $row['hook'] ?? '', 'retry', $e->getMessage()); $results['errors'][$id] = 'scheduled_retry'; }
            } catch (Throwable $_e) { $results['errors'][$id] = 'db_error: ' . $_e->getMessage(); }
        }
    }
    return $results;
}

function qp_get_logs(int $limit = 200, ?string $hook = null, ?int $task_id = null): array {
    $pdo = db(); qp_cron_logs_install($pdo);
    $sql = 'SELECT * FROM ' . qp_cron_logs_table(); $conds=[]; $params=[];
    if ($hook) { $conds[]='hook=?'; $params[]=$hook; }
    if ($task_id) { $conds[]='task_id=?'; $params[]=(int)$task_id; }
    if (!empty($conds)) $sql .= ' WHERE '.implode(' AND ', $conds);
    $sql .= ' ORDER BY created_at DESC LIMIT '.(int)$limit;
    $stmt=$pdo->prepare($sql); $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (function_exists('format_site_datetime')) {
        foreach ($rows as &$r) {
            $r_created = $r['created_at'] ?? null;
            $r['created_at_formatted'] = $r_created ? format_site_datetime((int)$r_created) : $r_created;
        }
        unset($r);
    }
    return $rows;
}
