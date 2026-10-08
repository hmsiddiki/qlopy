<?php
// Admin Cron Manager UI
// Keep POST handling before any output to allow redirects
require_once __DIR__ . '/admin_head.php';
// Ensure cron includes available
if (!file_exists(__DIR__ . '/../includes/qp-cron.php')) {
    if (function_exists('qp_set_admin_notice')) qp_set_admin_notice('Cron core not installed (includes/qp-cron.php missing)');
}

// Handle POST actions (Repair, Rotate Key, Schedule, Run, Remove)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $nonce = $_POST['_qp_nonce'] ?? '';
    if (!function_exists('qp_admin_verify_nonce') || !qp_admin_verify_nonce($nonce, 'qp_cron_action')) {
        if (function_exists('qp_set_admin_notice')) qp_set_admin_notice('Invalid CSRF token.');
        header('Location: cron.php'); exit;
    }
    if ($action === 'repair_tables') {
        if (function_exists('qp_cron_queue_install')) qp_cron_queue_install();
        if (function_exists('qp_cron_logs_install')) qp_cron_logs_install();
        if (function_exists('qp_set_admin_notice')) qp_set_admin_notice('Cron tables repaired/created.');
        header('Location: cron.php'); exit;
    }
    if ($action === 'toggle_cron_logging') {
        // Checkbox sends value only when checked. We'll store '1' or '0'.
        $val = !empty($_POST['cron_logging']) ? 1 : 0;
        $auto = !empty($_POST['cron_auto_clean']) ? 1 : 0;
        $retention = isset($_POST['cron_logs_retention']) ? (int)$_POST['cron_logs_retention'] : 40;
        if (function_exists('update_option_meta')) {
            update_option_meta('cron_logging', $val);
            update_option_meta('cron_auto_clean', $auto);
            update_option_meta('cron_logs_retention', $retention);
            if (function_exists('qp_set_admin_notice')) qp_set_admin_notice('Cron logging ' . ($val ? 'enabled' : 'disabled') . '. Auto-clean ' . ($auto ? 'enabled' : 'disabled') . '.');
            // Schedule or unschedule periodic rotation job depending on options
            // Only attempt scheduling if cron core/queue is available
            if (file_exists(__DIR__ . '/../includes/qp-cron.php') && file_exists(__DIR__ . '/../includes/qp-cron-queue.php')) {
                // ensure includes loaded for helper functions
                require_once __DIR__ . '/../includes/qp-cron.php';
                require_once __DIR__ . '/../includes/qp-cron-queue.php';
                $hook = 'qp_cron_rotate_logs';
                // If both logging and auto_clean enabled, ensure a recurring scheduled job exists
                    if ($val && $auto) {
                    $next = function_exists('qp_next_scheduled') ? qp_next_scheduled($hook) : null;
                    if ($next === null) {
                        // schedule to run every 30 minutes
                        if (function_exists('qp_schedule_single_event')) {
                            qp_schedule_single_event(time() + 60, $hook, [], 1800);
                        }
                    }
                } else {
                    // remove any pending/running rotate tasks
                    if (function_exists('qp_remove_tasks_by_hook')) qp_remove_tasks_by_hook($hook);
                }
            }
        } else {
            if (function_exists('qp_set_admin_notice')) qp_set_admin_notice('Unable to update option: update_option_meta not available.');
        }
        header('Location: cron.php'); exit;
    }

    if ($action === 'run_rotation_now') {
        // Trigger immediate rotation using configured retention
        if (file_exists(__DIR__ . '/../includes/qp-cron-queue.php')) {
            require_once __DIR__ . '/../includes/qp-cron-queue.php';
            $retention = 40;
            if (function_exists('get_option_meta')) {
                $opt = get_option_meta('cron_logs_retention');
                if ($opt !== null && is_numeric($opt)) $retention = (int)$opt;
            }
            $ok = qp_cron_rotate_logs([$retention]);
            if (function_exists('qp_set_admin_notice')) qp_set_admin_notice($ok ? 'Rotation ran (kept last ' . $retention . ' rows).' : 'Rotation failed or was not enabled.');
        } else {
            if (function_exists('qp_set_admin_notice')) qp_set_admin_notice('Rotation code not available.');
        }
        header('Location: cron.php'); exit;
    }

    if ($action === 'purge_cron_logs') {
        if (function_exists('qp_cron_logs_install')) qp_cron_logs_install();
        try {
            $pdo = db(); $table = qp_cron_logs_table();
            $stmt = $pdo->prepare('TRUNCATE TABLE ' . $table);
            $stmt->execute();
            if (function_exists('qp_set_admin_notice')) qp_set_admin_notice('Cron logs purged.');
        } catch (Throwable $_e) {
            if (function_exists('qp_set_admin_notice')) qp_set_admin_notice('Failed to purge cron logs: ' . $_e->getMessage());
        }
        header('Location: cron.php'); exit;
    }
    if ($action === 'rotate_key') {
        $new = bin2hex(random_bytes(16));
        if (function_exists('qp_cron_set_key')) qp_cron_set_key($new);
        if (function_exists('qp_set_admin_notice')) qp_set_admin_notice('New runner key generated.');
        header('Location: cron.php'); exit;
    }
    if ($action === 'schedule') {
        $hook = trim($_POST['hook'] ?? '');
        $when_raw = trim($_POST['when'] ?? '');
        $when = $when_raw ? strtotime($when_raw) : time();
        $args_raw = trim($_POST['args'] ?? '');
        $args = [];
        if ($args_raw !== '') {
            $decoded = json_decode($args_raw, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) $args = $decoded; else $args = [$args_raw];
        }
        $rec = intval($_POST['recurrence'] ?? 0);
            if ($hook) {
            if (function_exists('qp_queue_task')) {
                $id = qp_queue_task($hook, $when, $args, 3, 60, $rec);
                if (function_exists('qp_set_admin_notice')) qp_set_admin_notice($id ? 'Scheduled (ID ' . $id . ')' : 'Failed to schedule.');
            } elseif (function_exists('qp_schedule_single_event')) {
                qp_schedule_single_event($when, $hook, $args, $rec);
                if (function_exists('qp_set_admin_notice')) qp_set_admin_notice('Scheduled via fallback.');
            } else {
                if (function_exists('qp_set_admin_notice')) qp_set_admin_notice('No scheduling API available.');
            }
        } else {
            if (function_exists('qp_set_admin_notice')) qp_set_admin_notice('Hook name required.');
        }
        header('Location: cron.php'); exit;
    }
    if ($action === 'run_task' && !empty($_POST['task_id'])) {
        $id = (int)$_POST['task_id']; $force = !empty($_POST['force']);
        if (function_exists('qp_run_task_by_id')) {
            $res = qp_run_task_by_id($id, $force);
            if (function_exists('qp_set_admin_notice')) qp_set_admin_notice('Run result: ' . (is_array($res) ? json_encode($res) : (string)$res));
        } else {
            if (function_exists('qp_set_admin_notice')) qp_set_admin_notice('Run API not available.');
        }
        header('Location: cron.php'); exit;
    }
    if ($action === 'remove_task' && !empty($_POST['task_id'])) {
        $id = (int)$_POST['task_id'];
        if (function_exists('qp_remove_task_by_id')) {
            $ok = qp_remove_task_by_id($id);
            if (function_exists('qp_set_admin_notice')) qp_set_admin_notice($ok ? 'Task removed (ID ' . $id . ')' : 'Failed to remove task (ID ' . $id . ')');
        } else {
            if (function_exists('qp_set_admin_notice')) qp_set_admin_notice('Remove API not available.');
        }
        header('Location: cron.php'); exit;
    }
}

$notice = function_exists('qp_get_admin_notice') ? qp_get_admin_notice() : null;

$scheduled = function_exists('qp_get_scheduled_events') ? qp_get_scheduled_events() : [];
// Defer loading the full queue to AJAX to avoid slow page render on large queues
$queue = [];
$logs = function_exists('qp_get_logs') ? qp_get_logs(200) : [];
// Cron logging enabled option (default: disabled)
$cron_logging_enabled = false;
if (function_exists('get_option_meta')) {
    $v = get_option_meta('cron_logging');
    if ($v !== null) {
        if (is_bool($v)) $cron_logging_enabled = $v;
        else $cron_logging_enabled = ((string)$v === '1' || (int)$v === 1);
    }
}
// Ensure a normalized runner key exists and use it
$runner_key = function_exists('qp_cron_ensure_key') ? qp_cron_ensure_key() : (function_exists('qp_cron_get_key') ? qp_cron_get_key() : null);

// Normalize runner key if it contains surrounding quotes/newlines or unexpected chars.
if ($runner_key !== null) {
    $orig = $runner_key;
    $normalized = $runner_key;
    // Remove surrounding double or single quotes
    if (preg_match('/^\s*"(.*)"\s*$/s', $normalized, $m)) { $normalized = $m[1]; }
    if (preg_match("/^\\s*'(.*)'\\s*$/s", $normalized, $m2)) { $normalized = $m2[1]; }
    // Trim whitespace and newlines
    $normalized = trim($normalized);
    // If normalized differs, save back and set a notice
    if ($normalized !== $orig) {
        if (function_exists('qp_cron_set_key')) qp_cron_set_key($normalized);
        $runner_key = $normalized;
        if (function_exists('qp_set_admin_notice')) qp_set_admin_notice('Stored runner key contained surrounding quotes/newlines and was normalized.');
    } else {
        // If format is unexpected (not 32 hex chars), warn admin
        if (!preg_match('/^[0-9a-f]{32}$/i', $normalized)) {
            if (function_exists('qp_set_admin_notice')) qp_set_admin_notice('Warning: stored runner key contains unexpected characters or format.');
        }
    }
}
$page_title = 'Cron Manager';

if ( !current_user_can('manage_options')) {
    http_response_code(403);
    require_once __DIR__ . '/inc/header.php';
    require_once __DIR__ . '/inc/navbar.php';
    echo '<div class="container"><h1>Permission Denied</h1><p>You do not have permission to access this admin page.</p></div>';
    include __DIR__ . '/inc/footer.php';
    exit;
}

require_once __DIR__ . '/inc/header.php';

?>
<div class="container mt-4">
    <h2>Cron Manager</h2>
    <?php if ($notice): ?>
        <div class="alert alert-success"><?= htmlspecialchars($notice) ?></div>
    <?php endif ?>

    <div class="mb-3">
        <form method="post" style="display:inline-block;margin-right:10px;">
            <input type="hidden" name="action" value="repair_tables">
            <?php qp_nonce_field('qp_cron_action'); ?>
            <button class="btn btn-secondary" type="submit">Repair / Create Tables</button>
        </form>
        <form method="post" style="display:inline-block;">
            <input type="hidden" name="action" value="rotate_key">
            <?php qp_nonce_field('qp_cron_action'); ?>
            <button class="btn btn-warning" type="submit">Generate/Rotate Runner Key</button>
        </form>
    </div>

    <div class="mb-4">
        <h5>Runner Key</h5>
        <?php if ($runner_key): ?>
            <div class="input-group" style="max-width:700px;">
                <input id="qp-runner-key" class="form-control" readonly value="<?= htmlspecialchars($runner_key) ?>">
                <div class="input-group-append">
                    <button id="qp-copy-key" class="btn btn-outline-primary" type="button">Copy</button>
                </div>
            </div>
            <p class="small text-muted mt-2">CLI example: <code>e:\\Wnmp\\php\\php.exe qp-cron.php --key=<?= htmlspecialchars($runner_key) ?></code></p>
        <?php else: ?>
            <p class="text-muted">No runner key set. Click <strong>Generate/Rotate Runner Key</strong> to create one.</p>
        <?php endif; ?>
    </div>

    <div class="mb-4">
        <h5>Cron Logging</h5>
        <form method="post" style="display:inline-block;">
            <input type="hidden" name="action" value="toggle_cron_logging">
            <?php qp_nonce_field('qp_cron_action'); ?>
            <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="cron_logging" name="cron_logging" value="1" <?= $cron_logging_enabled ? 'checked' : '' ?> >
                <label class="form-check-label" for="cron_logging">Enable cron logging (writes to <code>cron_logs</code>)</label>
            </div>
            <div class="form-check form-check-inline" style="margin-left:12px;">
                <?php $cron_auto = function_exists('get_option_meta') ? get_option_meta('cron_auto_clean') : null; $cron_auto_checked = ($cron_auto === null ? false : (is_bool($cron_auto) ? $cron_auto : ((string)$cron_auto === '1' || (int)$cron_auto === 1))); ?>
                <input class="form-check-input" type="checkbox" id="cron_auto_clean" name="cron_auto_clean" value="1" <?= $cron_auto_checked ? 'checked' : '' ?> <?= $cron_logging_enabled ? '' : 'disabled' ?> >
                <label class="form-check-label" for="cron_auto_clean">Auto-clean (keep recent rows)</label>
            </div>
            <div class="form-inline" style="margin-left:12px; display:inline-block; vertical-align:middle;">
                <?php $ret = function_exists('get_option_meta') ? get_option_meta('cron_logs_retention') : null; $ret_val = ($ret === null ? 40 : ((is_numeric($ret)) ? (int)$ret : 40)); ?>
                <label class="mr-2" for="cron_logs_retention">Retention (keep last)</label>
                <input type="number" min="1" step="1" name="cron_logs_retention" id="cron_logs_retention" class="form-control form-control-sm" style="width:100px;" value="<?= htmlspecialchars($ret_val) ?>" <?= $cron_logging_enabled ? '' : 'disabled' ?> >
                <span class="ml-2">rows</span>
            </div>
            <button class="btn btn-sm btn-primary ml-2" type="submit">Save</button>
            <p class="small text-muted mt-2">Default: logging is <strong>disabled</strong> to avoid large DB growth. Enable only when debugging. When enabled you can optionally check <strong>Auto-clean</strong> to keep only the most recent N log rows.</p>
        </form>
    </div>

    <h4>Schedule a Task</h4>
    <form method="post" class="mb-4">
        <input type="hidden" name="action" value="schedule">
        <?php qp_nonce_field('qp_cron_action'); ?>
        <div class="form-group">
            <label>Hook name</label>
            <input name="hook" class="form-control" required placeholder="my_task_hook">
        </div>
        <div class="form-group">
            <label>When (human or datetime)</label>
            <input name="when" class="form-control" placeholder="now or 2025-12-05 14:00">
        </div>
        <div class="form-group">
            <label>Args (JSON array or text)</label>
            <input name="args" class="form-control" placeholder='["one","two"]'>
        </div>
        <div class="form-group">
            <label>Recurrence seconds (0 for one-off)</label>
            <input name="recurrence" type="number" class="form-control" value="0">
        </div>
        <div class="form-group">
            <button type="submit" class="btn btn-primary">Schedule Task</button>
        </div>
    </form>

                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <h4 class="mb-0">Queue (next 200)</h4>
                        <small id="qp-queue-updated" class="text-muted">Last updated: -</small>
                    </div>

                    <div class="mb-2">
                        <button id="qp-bulk-delete" class="btn btn-sm btn-outline-danger" type="button">Delete Selected</button>
                        <span class="small text-muted ml-2">Select tasks then click delete.</span>
                        <div style="display:inline-block; float:right; margin-left:12px;">
                            <label class="small text-muted" for="qp-queue-filter" style="margin-right:.5rem">Filter:</label>
                            <select id="qp-queue-filter" class="form-control form-control-sm" style="display:inline-block; width:auto;">
                                <option value="pending" selected>Pending only</option>
                                <option value="other">Other (running/done/failed)</option>
                            </select>
                        </div>
                    </div>

                    <!-- Bulk action nonce (used by JS) -->
                    <input type="hidden" id="qp-bulk-nonce" value="<?= function_exists('qp_admin_create_nonce') ? htmlspecialchars(qp_admin_create_nonce('qp_cron_action')) : '' ?>">

                    <table class="table table-sm table-striped">
                        <thead><tr><th><input type="checkbox" id="qp-select-all"></th><th>ID</th><th>Hook</th><th>Origin</th><th>Next Run</th><th>Status</th><th>Attempts</th><th>Actions</th></tr></thead>
                        <tbody id="qp-queue-body">
                            <tr><td colspan="8" class="text-muted">Loading…</td></tr>
                        </tbody>
                    </table>
                    <div id="qp-queue-pager" style="margin-top:8px"></div>
                        <script>
                        document.addEventListener('change', function(e){
                            if (e.target && e.target.id === 'qp-select-all') {
                                var checked = e.target.checked;
                                document.querySelectorAll('.qp-select-row').forEach(function(cb){ cb.checked = checked; });
                            }
                        });
                        document.getElementById('qp-bulk-delete').addEventListener('click', function(){
                            var checks = Array.from(document.querySelectorAll('.qp-select-row:checked')).map(function(cb){ return cb.value; });
                            if (!checks.length) { alert('No tasks selected'); return; }
                            if (!confirm('Remove ' + checks.length + ' selected tasks? This cannot be undone.')) return;
                            var ajaxUrl = (typeof window !== 'undefined' && window.ajaxurl) ? window.ajaxurl : 'ajax.php';
                            // First, request a fresh nonce from admin endpoint to avoid using an expired/consumed token
                            var getNonceXhr = new XMLHttpRequest();
                            getNonceXhr.open('POST', ajaxUrl, true);
                            getNonceXhr.setRequestHeader('Content-Type','application/x-www-form-urlencoded');
                            getNonceXhr.onreadystatechange = function(){
                                if (getNonceXhr.readyState !== 4) return;
                                try {
                                    var nres = JSON.parse(getNonceXhr.responseText);
                                } catch(e) { alert('Bulk delete failed (could not fetch nonce)'); return; }
                                if (!nres || nres.status !== 'success' || !nres.nonce) { alert('Bulk delete failed (could not obtain nonce): ' + (nres && nres.message ? nres.message : getNonceXhr.responseText)); return; }
                                var token = nres.nonce;
                                // Now post the bulk-remove request with fresh nonce
                                var params = 'action=cron_bulk_remove&_qp_nonce=' + encodeURIComponent(token);
                                checks.forEach(function(id){ params += '&task_ids[]=' + encodeURIComponent(id); });
                                var delXhr = new XMLHttpRequest();
                                delXhr.open('POST', ajaxUrl, true);
                                delXhr.setRequestHeader('Content-Type','application/x-www-form-urlencoded');
                                delXhr.onreadystatechange = function(){
                                    if (delXhr.readyState !== 4) return;
                                    var text = delXhr.responseText || '';
                                    try {
                                        var res = JSON.parse(text);
                                        if (res && res.status === 'success') { refreshAll(); return; }
                                        // If JSON parsed but not success, show message
                                        alert('Bulk delete failed: ' + (res && res.message ? res.message : text));
                                        return;
                                    } catch (e) {
                                        // JSON parse failed — try a best-effort detection of success
                                        if (delXhr.status === 200 && /"status"\s*:\s*"success"/.test(text)) {
                                            refreshAll(); return;
                                        }
                                        alert('Bulk delete failed');
                                    }
                                };
                                delXhr.send(params);
                            };
                            getNonceXhr.send('action=cron_get_nonce');
                        });
                        </script>

    <div class="d-flex justify-content-between align-items-center mb-2">
        <h4 class="mb-0">Recent Logs</h4>
        <div>
            <small id="qp-logs-updated" class="text-muted">Last updated: -</small>
            <div id="qp-logs-pager" style="display:inline-block;margin-left:12px"></div>
            <form method="post" style="display:inline-block;margin-left:12px;" onsubmit="return confirm('Purge all cron logs? This cannot be undone.');">
                <input type="hidden" name="action" value="purge_cron_logs">
                <?php qp_nonce_field('qp_cron_action'); ?>
                <button class="btn btn-sm btn-danger" type="submit">Purge logs</button>
            </form>
            <button id="qp-run-rotation-ajax" class="btn btn-sm btn-outline-secondary" style="margin-left:8px;" data-toggle="tooltip" title="">Run rotation (AJAX)</button>
        </div>
    </div>
    <table class="table table-sm table-bordered">
        <thead><tr><th>When</th><th>Task</th><th>Hook</th><th>Status</th><th>Message</th></tr></thead>
        <tbody id="qp-logs-body">
        <?php foreach ($logs as $l): ?>
            <tr>
                        <td><?=
                            function_exists('format_site_datetime') ? htmlspecialchars(format_site_datetime((int)($l['created_at'] ?? 0))) : htmlspecialchars(date('Y-m-d H:i:s', (int)($l['created_at'] ?? 0)))
                        ?></td>
                <td><?= htmlspecialchars($l['task_id'] ?? '') ?></td>
                <td><?= htmlspecialchars($l['hook'] ?? '') ?></td>
                <td><?= htmlspecialchars($l['status'] ?? '') ?></td>
                <td><?= htmlspecialchars(substr($l['message'] ?? '', 0, 200)) ?></td>
            </tr>
        <?php endforeach ?>
        </tbody>
    </table>

    <h4>Documentation & Examples</h4>
    <details>
        <summary>Show docs and examples</summary>
        <div style="margin-top:12px;">
            <h5>How it works</h5>
            <p>This system provides a small cron core and a DB-backed queue. Plugins/themes can register cron callbacks via <code>add_qp_cron_action($hook, $callable)</code>. Use <code>qp_queue_task()</code> to enqueue a job or <code>qp_schedule_single_event()</code> to schedule one-off/recurring jobs (recurrence in seconds).</p>
            <h5>Difference: <code>qp_schedule_single_event()</code> vs <code>qp_queue_task()</code></h5>
            <p><strong>qp_schedule_single_event</strong> is a high-level helper used to schedule events; in this project it delegates to the queue layer. <strong>qp_queue_task</strong> is the low-level function that inserts (or returns an existing) row into the <code>cron_queue</code> table with fields like <code>next_run</code>, <code>recurrence</code>, <code>status</code>, and attempt/backoff settings.</p>
            <h5>Deleting rows & recreation</h5>
            <p>If you delete a row from <code>cron_queue</code> the runner will not recreate it by itself. It will be recreated only when scheduling code runs that calls <code>qp_schedule_single_event()</code> or <code>qp_queue_task()</code> again (for example a theme/plugin that checks <code>qp_next_scheduled()</code> and re-schedules when none is found).</p>
            <h5>Basic plugin example</h5>
            <pre>
// Register
add_qp_cron_action('my_hook', function($args){ error_log('ran'); });
// Schedule once (delayed first run)
if (!qp_next_scheduled('my_hook')) qp_schedule_single_event(time()+30, 'my_hook');
            </pre>
            <h5>Advanced: recurring via queue (30s)</h5>
            <pre>
// Enqueue recurring
qp_queue_task('my_hook', time()+30, [], 3, 60, 30);
            </pre>
            <h5>Example: front-end AJAX contact form (schedule thank-you email)</h5>
            <pre>
// Register the cron callback (runs when queued task executes)
add_qp_cron_action('send_thank_you_email', function($args = []) {
    $email = $args[0] ?? '';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return false;
    $subject = 'Thanks for contacting us';
    $message = "Hello,\n\nThanks for your message. We'll be in touch.\n\n— Team";
    @mail($email, $subject, $message, "From: no-reply@example.com\r\n");
    return true;
});

// Front-end AJAX handler: POST to ajax.php?action=contact_submit
// Use the nopriv hook if visitors are unauthenticated
add_action('iitcm_ajax_nopriv_contact_submit', function($req) {
    $email = trim($req['email'] ?? '');
    $email = filter_var($email, FILTER_SANITIZE_EMAIL);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['status'=>'error','message'=>'Invalid email']); exit;
    }

    // Ensure cron helpers are available (ajax.php may include them already)
    if (!function_exists('qp_schedule_single_event') && file_exists(__DIR__ . '/../includes/qp-cron.php')) {
        require_once __DIR__ . '/../includes/qp-cron.php';
    }
    if (!function_exists('qp_schedule_single_event') && file_exists(__DIR__ . '/../includes/qp-cron-queue.php')) {
        require_once __DIR__ . '/../includes/qp-cron-queue.php';
    }

    // Schedule one-off thank-you email 10 minutes (600s) from now
    if (function_exists('qp_schedule_single_event')) {
        qp_schedule_single_event(time() + 600, 'send_thank_you_email', [$email], 0);
    } elseif (function_exists('qp_queue_task')) {
        qp_queue_task('send_thank_you_email', time() + 600, [$email], 3, 60, 0);
    }

    echo json_encode(['status'=>'success','message'=>'Thanks — we will email you shortly.']); exit;
});
            <h5>Notes</h5>
            <ul>
                <li><code>qp_schedule_single_event()</code> persists via the queue implementation.</li>
                <li>To change timing safely, update the <code>recurrence</code> (and optionally <code>next_run</code>) fields in <code>cron_queue</code> rather than deleting rows unless you want the scheduler to re-create them.</li>
            </ul>
        </div>
    </details>

</div>

<?php require_once __DIR__ . '/inc/footer.php'; ?>

<script>
document.addEventListener('DOMContentLoaded', function(){
    var btn = document.getElementById('qp-copy-key');
    if (!btn) return;
    btn.addEventListener('click', function(){
        var inp = document.getElementById('qp-runner-key');
        if (!inp) return;
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(inp.value).then(function(){ btn.textContent = 'Copied'; setTimeout(function(){ btn.textContent = 'Copy'; }, 1500); }).catch(function(){ alert('Copy failed'); });
        } else {
            inp.select(); try { document.execCommand('copy'); btn.textContent = 'Copied'; setTimeout(function(){ btn.textContent = 'Copy'; }, 1500); } catch(e){ alert('Copy not supported'); }
        }
    });
});
</script>

<script>
// Poll queue and logs every 30s and update tables
(function(){
    var ajaxUrl = window.ajaxurl;
    function xhrPost(data, cb) {
        var params = typeof data === 'string' ? data : Object.keys(data).map(function(k){ return encodeURIComponent(k)+'='+encodeURIComponent(data[k]); }).join('&');
        var xhr = new XMLHttpRequest();
        xhr.open('POST', ajaxUrl, true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        xhr.onreadystatechange = function(){ if (xhr.readyState===4) { try { cb(null, JSON.parse(xhr.responseText)); } catch(e){ cb(e); } } };
        xhr.send(params);
    }

    function renderQueue(rows) {
        var tbody = document.getElementById('qp-queue-body'); if (!tbody) return;
        var html = '';
        rows.forEach(function(r){
            // Prefer server-formatted site-local string when available
            var when = r.next_run_formatted || '';
            if (!when) {
                var nextRun = new Date((r.next_run||0)*1000);
                when = isNaN(nextRun.getTime()) ? '' : nextRun.toISOString().replace('T',' ').slice(0,19);
            }
            html += '<tr>'+
                '<td><input class="qp-select-row" type="checkbox" value="'+(r.id||'')+'"></td>'+
                '<td>'+ (r.id||'') +'</td>'+
                '<td>'+ (r.hook?escapeHtml(r.hook):'') +'</td>'+
                '<td>'+ (r.origin?escapeHtml(r.origin):'') +'</td>'+
                '<td>'+ escapeHtml(when) +'</td>'+
                '<td>'+ (r.status||'') +'</td>'+
                '<td>'+ (r.attempts||0) +'/'+ (r.max_attempts||0) +'</td>'+
                '<td>' + (r.actions || '') + '</td>'+
            '</tr>';
        });
        tbody.innerHTML = html;
        var t = document.getElementById('qp-queue-updated'); if (t) t.textContent = 'Last updated: ' + (new Date()).toLocaleTimeString();
    }

    var qpQueuePage = 1;
    var qpQueuePerPage = 20;
    var qpQueueFilter = 'pending';

    function renderQueuePager(pagination) {
        var pagerId = 'qp-queue-pager';
        var pager = document.getElementById(pagerId);
        if (!pager) {
            pager = document.createElement('div'); pager.id = pagerId; pager.style.display = 'inline-block';
            var parent = document.getElementById('qp-queue-body'); if (parent && parent.parentElement) parent.parentElement.parentElement.appendChild(pager);
        }
        pager.innerHTML = '';
        var page = pagination.page || 1;
        var total = pagination.total || 0;
        var per = pagination.per_page || qpQueuePerPage;
        var totalPages = pagination.total_pages || Math.ceil(total / per) || 1;
        function makeBtn(text, cls, disabled, handler) {
            var b = document.createElement('button'); b.type = 'button'; b.className = 'btn btn-sm ' + cls; b.textContent = text; if (disabled) b.disabled = true; b.addEventListener('click', handler); return b;
        }
        pager.appendChild(makeBtn('Prev', 'btn-outline-secondary', page <= 1, function(){ qpQueuePage = Math.max(1, page-1); fetchQueue(); }));
        var span = document.createElement('span'); span.className = 'mx-2 small text-muted'; span.textContent = 'Page ' + page + ' / ' + totalPages; pager.appendChild(span);
        pager.appendChild(makeBtn('Next', 'btn-outline-secondary', page >= totalPages, function(){ qpQueuePage = Math.min(totalPages, page+1); fetchQueue(); }));
    }

    function fetchQueue() {
        qpQueueFilter = (document.getElementById('qp-queue-filter') && document.getElementById('qp-queue-filter').value) || qpQueueFilter;
        xhrPost({action:'cron_queue', page: qpQueuePage, per_page: qpQueuePerPage, status: qpQueueFilter}, function(err, res){ if (!err && res && res.queue) { renderQueue(res.queue); if (res.pagination) renderQueuePager(res.pagination); } });
    }

    // Client-side AJAX handlers for Run / Force / Remove buttons
    document.addEventListener('click', function(e){
        var target = e.target;
        if (!target) return;
        // Helper to find enclosing form and inputs
        function findFormInputs(el){
            var form = el.closest('form');
            if (!form) return null;
            var inputs = {};
            Array.from(form.querySelectorAll('input')).forEach(function(inp){ inputs[inp.name] = inp.value; });
            return {form: form, inputs: inputs};
        }

        // Run (normal)
        if (target.classList && target.classList.contains('qp-action-run')) {
            e.preventDefault();
            var info = findFormInputs(target);
            if (!info) return;
            var taskId = info.inputs['task_id'] || info.inputs['taskid'] || '';
            var nonce = info.inputs['_qp_nonce'] || info.inputs['qp_nonce'] || '';
            if (!taskId) { alert('Missing task id'); return; }
            target.disabled = true;
            xhrPost({action:'cron_run', task_id: taskId, _qp_nonce: nonce}, function(err, res){ target.disabled = false; if (err) { alert('Run failed'); return; } if (res && res.status === 'success') { refreshAll(); } else { alert('Run failed: ' + (res && res.message ? res.message : JSON.stringify(res))); } });
            return;
        }

        // Force
        if (target.classList && target.classList.contains('qp-action-force')) {
            e.preventDefault();
            var info = findFormInputs(target);
            if (!info) return;
            var taskId = info.inputs['task_id'] || info.inputs['taskid'] || '';
            var nonce = info.inputs['_qp_nonce'] || info.inputs['qp_nonce'] || '';
            if (!taskId) { alert('Missing task id'); return; }
            target.disabled = true;
            xhrPost({action:'cron_run', task_id: taskId, _qp_nonce: nonce, force: 1}, function(err, res){ target.disabled = false; if (err) { alert('Force run failed'); return; } if (res && res.status === 'success') { refreshAll(); } else { alert('Force run failed: ' + (res && res.message ? res.message : JSON.stringify(res))); } });
            return;
        }

        // Remove
        if (target.classList && target.classList.contains('qp-action-remove')) {
            // Confirm if form has onsubmit confirm; otherwise perform default confirm
            var info = findFormInputs(target);
            var doConfirm = true;
            if (info && info.form && info.form.getAttribute('onsubmit')) {
                // Let existing onsubmit confirm run; if it returns false, cancel
                try { var ok = new Function('return ' + (info.form.getAttribute('onsubmit').replace(/^return\s*/,'') || 'true'))(); if (!ok) { return; } } catch(e) {}
            } else {
                doConfirm = confirm('Remove task? This cannot be undone.');
                if (!doConfirm) return;
            }
            e.preventDefault();
            if (!info) return;
            var taskId = info.inputs['task_id'] || info.inputs['taskid'] || '';
            var nonce = info.inputs['_qp_nonce'] || info.inputs['qp_nonce'] || '';
            if (!taskId) { alert('Missing task id'); return; }
            target.disabled = true;
            xhrPost({action:'cron_remove', task_id: taskId, _qp_nonce: nonce}, function(err, res){ target.disabled = false; if (err) { alert('Remove failed'); return; } if (res && res.status === 'success') { refreshAll(); } else { alert('Remove failed: ' + (res && res.message ? res.message : JSON.stringify(res))); } });
            return;
        }
    });

    function renderLogs(rows) {
        var tbody = document.getElementById('qp-logs-body'); if (!tbody) return;
        var html = '';
        (rows || []).forEach(function(l){
            // Prefer server-formatted site-local created time when provided
            var whenStr = l.created_at_formatted || '';
            if (!whenStr) {
                var when = new Date((l.created_at||0)*1000);
                whenStr = isNaN(when.getTime()) ? '' : when.toISOString().replace('T',' ').slice(0,19);
            }
            html += '<tr>'+
                '<td>'+ escapeHtml(whenStr) +'</td>'+
                '<td>'+(l.task_id||'')+'</td>'+
                '<td>'+ (l.hook?escapeHtml(l.hook):'') +'</td>'+
                '<td>'+ (l.status||'') +'</td>'+
                '<td>'+ escapeHtml((l.message||'').toString().slice(0,200)) +'</td>'+
            '</tr>';
        });
        tbody.innerHTML = html;
        var t = document.getElementById('qp-logs-updated'); if (t) t.textContent = 'Last updated: ' + (new Date()).toLocaleTimeString();
    }

    var qpLogsPage = 1;
    var qpLogsPerPage = 20;

    function renderLogsPager(pagination) {
        var pager = document.getElementById('qp-logs-pager'); if (!pager) return;
        pager.innerHTML = '';
        var page = pagination.page || 1;
        var total = pagination.total || 0;
        var per = pagination.per_page || qpLogsPerPage;
        var totalPages = pagination.total_pages || Math.ceil(total / per) || 1;
        function makeBtn(text, cls, disabled, handler) {
            var b = document.createElement('button'); b.type = 'button'; b.className = 'btn btn-sm ' + cls; b.textContent = text; if (disabled) b.disabled = true; b.addEventListener('click', handler); return b;
        }
        // Prev
        pager.appendChild(makeBtn('Prev', 'btn-outline-secondary', page <= 1, function(){ qpLogsPage = Math.max(1, page-1); fetchLogs(); }));
        // Page info
        var span = document.createElement('span'); span.className = 'mx-2 small text-muted'; span.textContent = 'Page ' + page + ' / ' + totalPages; pager.appendChild(span);
        // Next
        pager.appendChild(makeBtn('Next', 'btn-outline-secondary', page >= totalPages, function(){ qpLogsPage = Math.min(totalPages, page+1); fetchLogs(); }));
    }

    function fetchLogs() {
        xhrPost({action:'cron_logs', page: qpLogsPage, per_page: qpLogsPerPage}, function(err, res){ if (!err && res && res.logs) { renderLogs(res.logs); if (res.pagination) renderLogsPager(res.pagination); } });
    }

    function escapeHtml(s) { return String(s).replace(/[&<>"']/g, function(c){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); }

    // Expose refreshAll globally so inline scripts (bulk-delete handlers) can call it
    window.refreshAll = function(){
        fetchQueue();
        // Fetch logs (non-paginated)
        fetchLogs();
    };
    // Also provide a local alias for compatibility
    var refreshAll = window.refreshAll;

    document.addEventListener('DOMContentLoaded', function(){
        refreshAll();
        setInterval(refreshAll, 30000);
    });
    // Filter control change -> reset to page 1 and refresh
    var filterEl = document.getElementById('qp-queue-filter'); if (filterEl) { filterEl.addEventListener('change', function(){ qpQueuePage = 1; fetchQueue(); }); }

})();
</script>

<script>
// AJAX rotation preview and run handlers
(function(){
    var ajaxBtn = document.getElementById('qp-run-rotation-ajax');
    if (!ajaxBtn) return;
    function xhrPost(data, cb) {
        var ajaxUrl = window.ajaxurl || 'ajax.php';
        var params = Object.keys(data).map(function(k){ return encodeURIComponent(k)+'='+encodeURIComponent(data[k]); }).join('&');
        var xhr = new XMLHttpRequest(); xhr.open('POST', ajaxUrl, true); xhr.setRequestHeader('Content-Type','application/x-www-form-urlencoded');
        xhr.onreadystatechange = function(){ if (xhr.readyState===4) { try { cb(null, JSON.parse(xhr.responseText)); } catch(e){ cb(e); } } };
        xhr.send(params);
    }

    // Fetch a fresh admin nonce via existing AJAX helper before performing sensitive actions
    function getFreshNonce(cb) {
        xhrPost({action:'cron_get_nonce'}, function(err, res){
            if (err || !res) return cb(err || new Error('No response'));
            if (res.status !== 'success' || !res.nonce) return cb(new Error('Failed to get nonce'));
            cb(null, res.nonce);
        });
    }

    function setTooltip(text, showBrief) {
        // Support Bootstrap 4 (jQuery plugin) and Bootstrap 5 (vanilla JS)
        try {
            // Prefer Bootstrap 5 API if available
            var bs = window.bootstrap && window.bootstrap.Tooltip;
            if (bs) {
                // Dispose any existing instance
                try {
                    var existing = bootstrap.Tooltip.getInstance(ajaxBtn);
                    if (existing) existing.dispose();
                } catch (e) {}
                // Create new tooltip instance
                ajaxBtn.setAttribute('title', text);
                ajaxBtn.setAttribute('data-bs-toggle', 'tooltip');
                var instance = new bootstrap.Tooltip(ajaxBtn, {placement: 'bottom'});
                if (showBrief) {
                    instance.show();
                    setTimeout(function(){ try { instance.hide(); } catch(e){} }, 3000);
                }
                return;
            }
            // Fallback to Bootstrap 4 jQuery plugin
            if (window.jQuery && jQuery.fn && jQuery.fn.tooltip) {
                try { $(ajaxBtn).tooltip('dispose'); } catch (e) {}
                // Initialize the tooltip using explicit options (title + container)
                $(ajaxBtn).attr('data-toggle','tooltip');
                $(ajaxBtn).tooltip({title: text, placement: 'bottom', container: 'body'});
                if (showBrief) { try { $(ajaxBtn).tooltip('show'); setTimeout(function(){ try{ $(ajaxBtn).tooltip('hide'); }catch(e){} }, 3000); } catch(e) {} }
                return;
            }
        } catch (e) {}
        // Last-resort: native title
        ajaxBtn.setAttribute('title', text);
    }

    function showPreview() {
        setTooltip('Checking...', false);
        getFreshNonce(function(err, n){
            if (err) { setTooltip('Preview failed', true); return; }
            xhrPost({action:'cron_rotation_preview', _qp_nonce: n}, function(err2, res){
                if (err2 || !res) { setTooltip('Preview failed', true); return; }
                if (res.status !== 'success') { setTooltip('Preview error: ' + (res.message||''), true); return; }
                setTooltip('total: ' + res.total + ', retention: ' + res.retention + ', will delete: ' + res.to_delete, true);
            });
        });
    }

    ajaxBtn.addEventListener('click', function(){
        ajaxBtn.disabled = true; ajaxBtn.textContent = 'Checking...';
        getFreshNonce(function(err, n){
            if (err) { ajaxBtn.disabled = false; ajaxBtn.textContent = 'Run rotation (AJAX)'; alert('Unable to obtain nonce'); return; }
            xhrPost({action:'cron_rotation_preview', _qp_nonce: n}, function(err2, res){
                ajaxBtn.disabled = false; ajaxBtn.textContent = 'Run rotation (AJAX)';
                if (err2 || !res) { alert('Preview failed'); return; }
                if (res.status !== 'success') { alert('Preview error: ' + (res.message||'')); return; }
                var msg = 'Rotation will delete ' + res.to_delete + ' rows (total ' + res.total + ', retention ' + res.retention + '). Proceed?';
                if (!confirm(msg)) return;
                // Run the rotation with a fresh nonce
                ajaxBtn.disabled = true; ajaxBtn.textContent = 'Running...';
                getFreshNonce(function(err3, n2){
                    if (err3) { ajaxBtn.disabled = false; ajaxBtn.textContent = 'Run rotation (AJAX)'; alert('Unable to obtain nonce'); return; }
                    xhrPost({action:'cron_run_rotation', _qp_nonce: n2}, function(err3b, runRes){
                        ajaxBtn.disabled = false; ajaxBtn.textContent = 'Run rotation (AJAX)';
                        if (err3b || !runRes) { alert('Run failed'); return; }
                        if (runRes.status !== 'success') { alert('Run error: ' + (runRes.message||'')); return; }
                        alert('Rotation complete. Kept rows: ' + runRes.kept + ' (retention ' + runRes.retention + ')');
                        if (typeof refreshAll === 'function') refreshAll();
                        showPreview();
                    });
                });
            });
        });
    });
    // Show initial preview on load
    showPreview();
})();
</script>
