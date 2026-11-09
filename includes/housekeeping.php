<?php
if (!defined('QLOPY_INIT')) { http_response_code(403); exit; }
// includes/housekeeping.php
// Daily cleanup of what the core keeps growing (core upgrade plan C17). Loaded by includes/updater.php, so the cron runner has it.
//
// Task qp_housekeeping (cron queue, once a day) removes:
//   - automatic backups beyond the number kept (Updater Settings), and download zips older than a day (includes/backups.php);
//   - mail log entries older than mail_log_days (default 90) and SMTP transcripts older than mail_transcript_days (default 14);
//   - finished cron tasks older than cron_done_days (default 7) and failed ones older than cron_failed_days (default 30);
//     pending, running and repeating tasks are never touched;
//   - cron log lines older than cron_log_days (default 30).
// It never removes content, uploads or manual backups. Settings: option qp_housekeeping (Settings > Storage & cleanup).
// The task is scheduled when an admin page loads and none is waiting (checked at most once a day, through an option).
// Public API: qp_housekeeping_settings(), qp_housekeeping_run(), qp_housekeeping_usage(), qp_housekeeping_ensure_scheduled().

const QP_HOUSEKEEPING_DEFAULTS = [
    'mail_log_days' => 90,
    'mail_transcript_days' => 14,
    'cron_done_days' => 7,
    'cron_failed_days' => 30,
    'cron_log_days' => 30,
];

function qp_housekeeping_settings(): array {
    $s = function_exists('get_option_meta') ? get_option_meta('qp_housekeeping') : null;
    $s = is_array($s) ? $s : [];
    $out = [];
    foreach (QP_HOUSEKEEPING_DEFAULTS as $k => $v) $out[$k] = max(1, (int)($s[$k] ?? $v));
    return $out;
}

/** Does this table exist? (A site may never have used mail or cron logging.) */
function qp_housekeeping_has_table(string $table): bool {
    static $seen = [];
    if (isset($seen[$table])) return $seen[$table];
    try {
        $st = db()->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
        $st->execute([$table]);
        return $seen[$table] = (int)$st->fetchColumn() > 0;
    } catch (Throwable $_) { return $seen[$table] = false; }
}

/** Run every cleanup now. Returns what was removed, per item. */
function qp_housekeeping_run(): array {
    $s = qp_housekeeping_settings();
    $out = ['backups' => 0, 'download_zips' => 0, 'mail_log' => 0, 'mail_transcripts' => 0, 'cron_tasks' => 0, 'cron_logs' => 0, 'errors' => []];
    if (function_exists('qlopy_backups_prune')) {
        try {
            qlopy_backups_protect();
            qlopy_backups_rename_legacy();
            $out['backups'] = qlopy_backups_prune();
            $out['download_zips'] = qlopy_backups_tmp_cleanup(86400);
        } catch (Throwable $e) { $out['errors'][] = 'backups: ' . $e->getMessage(); }
    }
    if (!function_exists('db') || !function_exists('table_name')) return $out;
    $pdo = db();
    // Mail: the log keeps the whole message (customer names and addresses), so it is not kept for ever.
    try {
        $log = table_name('mail_log'); $debug = table_name('mail_debug');
        if (qp_housekeeping_has_table($debug)) {
            $st = $pdo->prepare('DELETE FROM `' . $debug . '` WHERE created_at < ?');
            $st->execute([date('Y-m-d H:i:s', time() - $s['mail_transcript_days'] * 86400)]);
            $out['mail_transcripts'] = $st->rowCount();
        }
        if (qp_housekeeping_has_table($log)) {
            $cut = date('Y-m-d H:i:s', time() - $s['mail_log_days'] * 86400);
            if (qp_housekeeping_has_table($debug)) {
                $st = $pdo->prepare('DELETE d FROM `' . $debug . '` d JOIN `' . $log . '` l ON l.id = d.log_id WHERE l.created_at < ?');
                $st->execute([$cut]); $out['mail_transcripts'] += $st->rowCount();
            }
            $st = $pdo->prepare('DELETE FROM `' . $log . '` WHERE created_at < ?');
            $st->execute([$cut]);
            $out['mail_log'] = $st->rowCount();
        }
    } catch (Throwable $e) { $out['errors'][] = 'mail: ' . $e->getMessage(); }
    // Cron: finished and failed one-off tasks; never pending, running or repeating ones.
    try {
        $q = table_name('cron_queue');
        if (qp_housekeeping_has_table($q)) {
            $st = $pdo->prepare('DELETE FROM `' . $q . '` WHERE recurrence = 0 AND ((status = ? AND updated_at < ?) OR (status = ? AND updated_at < ?))');
            $st->execute(['done', time() - $s['cron_done_days'] * 86400, 'failed', time() - $s['cron_failed_days'] * 86400]);
            $out['cron_tasks'] = $st->rowCount();
        }
        $l = table_name('cron_logs');
        if (qp_housekeeping_has_table($l)) {
            $st = $pdo->prepare('DELETE FROM `' . $l . '` WHERE created_at < ?');
            $st->execute([time() - $s['cron_log_days'] * 86400]);
            $out['cron_logs'] = $st->rowCount();
        }
    } catch (Throwable $e) { $out['errors'][] = 'cron: ' . $e->getMessage(); }
    if (function_exists('update_option_meta')) update_option_meta('qp_housekeeping_last', ['at' => time(), 'result' => $out]);
    return $out;
}

/**
 * What each item uses now: [key => [label, bytes, rows|null]]. Table sizes come from information_schema (an estimate MySQL keeps;
 * free space inside the table file is only returned to the disk by OPTIMIZE TABLE).
 */
function qp_housekeeping_usage(): array {
    $out = [];
    if (function_exists('qlopy_list_backups')) {
        $auto = 0; $manual = 0; $na = 0; $nm = 0;
        foreach (qlopy_list_backups() as $b) {
            if ($b['kind'] === 'manual') { $manual += (int)$b['size']; $nm++; } else { $auto += (int)$b['size']; $na++; }
        }
        $out['backups_auto'] = ['Automatic backups (updates, database)', $auto, $na];
        $out['backups_manual'] = ['Manual full backups', $manual, $nm];
        $tmp = 0; $nt = 0;
        foreach ((array)glob(qlopy_updates_tmp_dir() . DIRECTORY_SEPARATOR . '*.zip') as $f) { $tmp += (int)@filesize($f); $nt++; }
        $out['download_zips'] = ['Backup download zips', $tmp, $nt];
    }
    if (function_exists('db') && function_exists('table_name')) {
        foreach (['mail_log' => 'Mail log', 'mail_debug' => 'Mail SMTP transcripts', 'mail_queue' => 'Mail queue', 'cron_queue' => 'Cron tasks', 'cron_logs' => 'Cron log', 'login_log' => 'Login log', 'seo_404_log' => '404 log'] as $t => $label) {
            $name = table_name($t);
            try {
                $st = db()->prepare('SELECT DATA_LENGTH + INDEX_LENGTH, TABLE_ROWS FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
                $st->execute([$name]);
                $r = $st->fetch(PDO::FETCH_NUM);
                if ($r) $out[$t] = [$label, (int)$r[0], (int)$r[1]];
            } catch (Throwable $_) {}
        }
    }
    return $out;
}

/** Make sure the daily task is waiting in the queue. Checked at most once a day (option qp_housekeeping_checked). */
function qp_housekeeping_ensure_scheduled(bool $force = false): void {
    if (!function_exists('get_option_meta') || !function_exists('update_option_meta')) return;
    if (!$force && (int)get_option_meta('qp_housekeeping_checked') > time()) return;
    update_option_meta('qp_housekeeping_checked', time() + 86400);
    if (!function_exists('qp_next_scheduled') && is_file(__DIR__ . '/qp-cron.php')) require_once __DIR__ . '/qp-cron.php';
    if (function_exists('qp_next_scheduled') && function_exists('qp_schedule_single_event') && qp_next_scheduled('qp_housekeeping') === null) {
        qp_schedule_single_event(time() + 3600, 'qp_housekeeping', [], 86400);
    }
}

// The handler of the cron task qp_housekeeping is registered in auth.php (every entry point), so the visitor's cron slice, which does
// not load includes/updater.php, has it too.
