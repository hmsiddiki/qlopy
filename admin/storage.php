<?php
// Settings > Storage & cleanup (core upgrade plan C17): what the core keeps growing, how long it is kept, and a "Clean now" button.
// The cleanup itself is includes/housekeeping.php (daily task qp_housekeeping); backups are in includes/backups.php.
require_once __DIR__ . '/admin_head.php';
$page_title = 'Storage & cleanup';

if (!current_user_can('manage_options')) {
    http_response_code(403);
    require_once __DIR__ . '/inc/header.php';
    require_once __DIR__ . '/inc/navbar.php';
    echo '<div class="container"><h1>Permission Denied</h1><p>You do not have permission to access this admin page.</p></div>';
    include __DIR__ . '/inc/footer.php';
    exit;
}

$nonce = function_exists('qp_admin_create_nonce') ? qp_admin_create_nonce('storage_cleanup') : '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!function_exists('qp_admin_verify_nonce') || !qp_admin_verify_nonce($_POST['storage_nonce'] ?? '', 'storage_cleanup')) {
        qp_set_admin_notice('Invalid request (bad nonce)');
        header('Location: storage.php'); exit;
    }
    if (isset($_POST['save_storage'])) {
        $s = [];
        foreach (QP_HOUSEKEEPING_DEFAULTS as $k => $v) $s[$k] = max(1, min(3650, (int)($_POST[$k] ?? $v)));
        update_option_meta('qp_housekeeping', $s);
        if (isset($_POST['backup_retention']) && function_exists('qlopy_set_updater_settings')) {
            qlopy_set_updater_settings(['backup_retention' => max(1, min(100, (int)$_POST['backup_retention']))]);
        }
        qp_set_admin_notice('Cleanup settings saved.');
    } elseif (isset($_POST['clean_now'])) {
        $r = qp_housekeeping_run();
        $parts = [];
        foreach (['backups' => 'old backups', 'download_zips' => 'download zips', 'mail_log' => 'mail log entries', 'mail_transcripts' => 'SMTP transcripts', 'cron_tasks' => 'finished cron tasks', 'cron_logs' => 'cron log lines'] as $k => $label) {
            if (!empty($r[$k])) $parts[] = (int)$r[$k] . ' ' . $label;
        }
        qp_set_admin_notice(($parts ? 'Removed ' . implode(', ', $parts) . '.' : 'Nothing was old enough to remove.') . ($r['errors'] ? ' Problems: ' . implode('; ', $r['errors']) : ''));
    }
    header('Location: storage.php'); exit;
}

qp_housekeeping_ensure_scheduled(true);   // opening this screen makes sure the daily task is waiting
$notice = function_exists('qp_get_admin_notice') ? qp_get_admin_notice() : null;
$s = qp_housekeeping_settings();
$u = qlopy_get_updater_settings();
$usage = qp_housekeeping_usage();
$last = get_option_meta('qp_housekeeping_last');
$next = function_exists('qp_next_scheduled') ? qp_next_scheduled('qp_housekeeping') : null;
$h = static fn ($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$fmt = static function (int $b): string { $u = ['B', 'KB', 'MB', 'GB']; $i = 0; $n = (float)$b; while ($n >= 1024 && $i < 3) { $n /= 1024; $i++; } return ($i ? number_format($n, 1) : (string)(int)$n) . ' ' . $u[$i]; };
require_once __DIR__ . '/inc/header.php';
?>
<div class="container mt-4">
  <h2>Storage &amp; cleanup</h2>
  <?php if ($notice): ?><div class="alert alert-info"><?= $h($notice) ?></div><?php endif; ?>
  <p class="text-muted small">Logs, finished tasks and automatic backups are removed once a day when they are older than the limits below. Content, uploads and manual full backups are never removed by this. Mail is kept for a limited time because each logged email holds the customer's name and address.</p>

  <h4 class="mt-4">Space used</h4>
  <table class="table table-sm" style="max-width:720px">
    <thead><tr><th>Item</th><th class="text-right">Size</th><th class="text-right">Entries</th></tr></thead>
    <tbody>
    <?php $total = 0; foreach ($usage as $row): $total += $row[1]; ?>
      <tr><td><?= $h($row[0]) ?></td><td class="text-right"><?= $h($fmt($row[1])) ?></td><td class="text-right"><?= $row[2] === null ? '' : (int)$row[2] ?></td></tr>
    <?php endforeach; ?>
      <tr class="font-weight-bold"><td>Total</td><td class="text-right"><?= $h($fmt($total)) ?></td><td></td></tr>
    </tbody>
  </table>
  <p class="small text-muted">Table sizes are MySQL's own estimate. After a large cleanup, the database file keeps its size until the table is optimised by the host; the rows are gone either way.
    Backups are listed on <a href="updates.php">Updates</a>.</p>

  <h4 class="mt-4">How long things are kept</h4>
  <form method="post" style="max-width:720px">
    <input type="hidden" name="storage_nonce" value="<?= $h($nonce) ?>">
    <div class="form-row">
      <div class="form-group col-md-6"><label for="backup_retention">Automatic backups kept (newest)</label><input class="form-control" type="number" min="1" max="100" id="backup_retention" name="backup_retention" value="<?= (int)($u['backup_retention'] ?? 3) ?>"></div>
      <div class="form-group col-md-6"><label for="mail_log_days">Mail log, days</label><input class="form-control" type="number" min="1" max="3650" id="mail_log_days" name="mail_log_days" value="<?= (int)$s['mail_log_days'] ?>"></div>
      <div class="form-group col-md-6"><label for="mail_transcript_days">SMTP transcripts, days</label><input class="form-control" type="number" min="1" max="3650" id="mail_transcript_days" name="mail_transcript_days" value="<?= (int)$s['mail_transcript_days'] ?>"></div>
      <div class="form-group col-md-6"><label for="cron_done_days">Finished cron tasks, days</label><input class="form-control" type="number" min="1" max="3650" id="cron_done_days" name="cron_done_days" value="<?= (int)$s['cron_done_days'] ?>"></div>
      <div class="form-group col-md-6"><label for="cron_failed_days">Failed cron tasks, days</label><input class="form-control" type="number" min="1" max="3650" id="cron_failed_days" name="cron_failed_days" value="<?= (int)$s['cron_failed_days'] ?>"></div>
      <div class="form-group col-md-6"><label for="cron_log_days">Cron log, days</label><input class="form-control" type="number" min="1" max="3650" id="cron_log_days" name="cron_log_days" value="<?= (int)$s['cron_log_days'] ?>"></div>
    </div>
    <button class="btn btn-primary" type="submit" name="save_storage" value="1">Save</button>
  </form>

  <h4 class="mt-4">Clean now</h4>
  <p class="small text-muted">
    Last run: <?= is_array($last) && !empty($last['at']) ? $h(function_exists('format_site_datetime') ? format_site_datetime((int)$last['at']) : date('c', (int)$last['at'])) : 'not yet' ?>.
    Next daily run: <?= $next ? $h(function_exists('format_site_datetime') ? format_site_datetime((int)$next) : date('c', (int)$next)) : 'not scheduled' ?>.
  </p>
  <form method="post"><input type="hidden" name="storage_nonce" value="<?= $h($nonce) ?>"><button class="btn btn-outline-danger" type="submit" name="clean_now" value="1">Clean now</button></form>
</div>
<?php include __DIR__ . '/inc/footer.php'; ?>
