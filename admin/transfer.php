<?php
// Settings > Import / Export (core upgrade plan C8): move content in and out of the site as CSV, JSON or JSON lines files.
// What can be moved comes from the entities plugins register (includes/transfer.php); the core brings posts and pages.
require_once __DIR__ . '/admin_head.php';
require_once __DIR__ . '/../includes/transfer.php';
$page_title = 'Import / Export';

if (!current_user_can('manage_options')) {
    http_response_code(403);
    require_once __DIR__ . '/inc/header.php';
    require_once __DIR__ . '/inc/navbar.php';
    echo '<div class="container"><h1>Permission Denied</h1><p>You do not have permission to access this admin page.</p></div>';
    include __DIR__ . '/inc/footer.php';
    exit;
}
qp_transfer_install();
$h = function ($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); };
$me = get_logged_in_user(); $uid = (int)($me['id'] ?? 0);
$entities = array_filter(qp_transfer_entities(), function ($e, $id) { return qp_transfer_can($id); }, ARRAY_FILTER_USE_BOTH);
$importable = array_filter($entities, function ($e) { return isset($e['import_row']) && is_callable($e['import_row']); });
$exportable = array_filter($entities, function ($e) { return isset($e['export_read']) && is_callable($e['export_read']); });
$tab = in_array($_GET['tab'] ?? 'import', ['import', 'export', 'runs'], true) ? $_GET['tab'] : 'import';
$runId = (int)($_GET['run'] ?? 0);
$nonce = qp_admin_create_nonce('qp_transfer');
$ok = function () { return qp_admin_verify_nonce((string)($_POST['nonce'] ?? ''), 'qp_transfer'); };
$stateOf = function (array $r) {
    $total = null; $p = $r['plan'];
    if ($r['direction'] === 'import' && !empty($p['file']) && ($path = qp_transfer_file_path($p['file'])) && is_file($path)) {
        $total = ($r['source'] === 'json') ? (int)($p['rows'] ?? 0) : filesize($path);
    }
    $pct = null;
    if ($r['status'] === 'done' || $r['status'] === 'undone') $pct = 100;
    elseif ($total) $pct = min(99, (int)floor(((int)$r['cursor_value']) * 100 / max(1, $total)));
    return ['id' => $r['id'], 'status' => $r['status'], 'dry' => (bool)$r['dry_run'], 'counts' => $r['counts'], 'progress' => $pct, 'problems' => count($r['problems']), 'error' => $r['last_error'], 'output' => $r['output'], 'direction' => $r['direction']];
};

// ---- AJAX: one batch of a run ------------------------------------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['ajax'] ?? '') === 'step') {
    while (ob_get_level() > 0) ob_end_clean();     // nothing but the answer
    header('Content-Type: application/json');
    $run = qp_transfer_run_get((int)($_POST['run'] ?? 0));
    if (!$run || !qp_transfer_can($run['entity']) || !qp_transfer_token_ok((string)($_POST['token'] ?? ''), $run['id'], 'step', $uid)) { http_response_code(403); echo json_encode(['error' => 'Not allowed. Reload the page.']); exit; }
    $res = qp_transfer_step($run['id'], 12);
    echo json_encode(['state' => $res['state']] + ($res['run'] ? $stateOf($res['run']) : []));
    exit;
}

// ---- Downloads: an export file and a run's problem list -----------------------------------------------------------------------------
if (isset($_GET['download']) || isset($_GET['problems'])) {
    $run = qp_transfer_run_get((int)($_GET['download'] ?? $_GET['problems']));
    $what = isset($_GET['download']) ? 'file' : 'problems';
    if (!$run || !qp_transfer_can($run['entity']) || !qp_transfer_token_ok((string)($_GET['token'] ?? ''), $run['id'], $what, $uid)) { http_response_code(403); echo 'Not allowed.'; exit; }
    while (ob_get_level() > 0) ob_end_clean();
    if (isset($_GET['download'])) {
        $path = $run['output'] !== '' ? qp_transfer_file_path($run['output']) : null;
        if (!$path || !is_file($path)) { http_response_code(404); echo 'The file is no longer there (export files are kept for a day).'; exit; }
        $ext = pathinfo($path, PATHINFO_EXTENSION);
        header('Content-Type: ' . ($ext === 'csv' ? 'text/csv; charset=utf-8' : 'application/json; charset=utf-8'));
        header('Content-Disposition: attachment; filename="' . preg_replace('/[^a-z0-9_-]/i', '', $run['entity']) . '-' . date('Ymd-His', $run['created_at']) . '.' . $ext . '"');
        header('Content-Length: ' . filesize($path)); header('X-Content-Type-Options: nosniff');
        readfile($path); exit;
    }
    header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="problems-run-' . $run['id'] . '.csv"');
    $o = fopen('php://output', 'w'); fwrite($o, "\xEF\xBB\xBF"); fputcsv($o, ['row', 'key', 'what happened', 'reason', 'data'], ',', '"', '');
    foreach ($run['problems'] as $p) fputcsv($o, [$p['row'], qp_transfer_csv_safe((string)$p['key']), (string)($p['level'] ?? 'rejected'), qp_transfer_csv_safe((string)$p['reason']), qp_transfer_csv_safe((string)$p['data'])], ',', '"', '');
    exit;
}

// ---- POST actions ------------------------------------------------------------------------------------------------------------------------------
$notice = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['act'])) {
    $act = (string)$_POST['act'];
    if (!$ok()) { qp_set_admin_notice('Invalid request (bad nonce).'); header('Location: transfer.php'); exit; }
    if ($act === 'upload') {
        $eid = (string)($_POST['entity'] ?? '');
        $f = $_FILES['file'] ?? null;
        if (!isset($importable[$eid])) $notice = 'Choose what you are importing.';
        elseif (!$f || ($f['error'] ?? 4) !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) $notice = 'The file did not upload (check its size against the server limit of ' . ini_get('upload_max_filesize') . ').';
        else {
            $ext = strtolower(pathinfo((string)$f['name'], PATHINFO_EXTENSION));
            $fmt = ['csv' => 'csv', 'txt' => 'csv', 'json' => 'json', 'ndjson' => 'ndjson', 'jsonl' => 'ndjson'][$ext] ?? '';
            if ($fmt === '') $notice = 'Use a .csv, .json or .ndjson (JSON lines) file.';
            elseif ($f['size'] > 100 * 1048576) $notice = 'The file is larger than 100 MB.';
            else {
                $name = bin2hex(random_bytes(16)) . '.' . $fmt; $path = qp_transfer_file_path($name);
                if (!move_uploaded_file($f['tmp_name'], $path)) $notice = 'The file could not be saved.';
                else {
                    [$iok, $info] = qp_transfer_file_inspect($path, $fmt);
                    if (!$iok) { @unlink($path); $notice = (string)$info; }
                    else { header('Location: transfer.php?tab=import&entity=' . rawurlencode($eid) . '&file=' . $name . '&orig=' . rawurlencode(basename((string)$f['name']))); exit; }
                }
            }
        }
        $tab = 'import';
    } elseif ($act === 'start_import') {
        $eid = (string)($_POST['entity'] ?? ''); $file = (string)($_POST['file'] ?? '');
        $path = qp_transfer_file_path($file);
        $fmt = $path ? pathinfo($path, PATHINFO_EXTENSION) : '';
        if (!isset($importable[$eid]) || !$path || !is_file($path)) { $notice = 'The file or the thing to import is missing; upload it again.'; }
        else {
            [$iok, $info] = qp_transfer_file_inspect($path, $fmt);
            $map = []; $fixed = [];
            foreach ((array)($_POST['map'] ?? []) as $fid => $col) if ($col !== '' && in_array($col, $info['columns'], true)) $map[(string)$fid] = (string)$col;
            foreach ((array)($_POST['fixed'] ?? []) as $fid => $val) if (trim((string)$val) !== '') $fixed[(string)$fid] = trim((string)$val);
            $plan = ['file' => $file, 'format' => $fmt, 'delimiter' => $info['delimiter'], 'columns' => $info['columns'], 'encoding' => $info['encoding'], 'rows' => $info['rows'],
                     'map' => $map, 'fixed' => $fixed, 'options' => (array)($_POST['options'] ?? []), 'mode' => (string)($_POST['mode'] ?? 'create_update'), 'source_id' => (string)($_POST['source_id'] ?? 'file'),
                     'limit' => max(0, (int)($_POST['limit'] ?? 0)), 'force' => !empty($_POST['force'])];
            [$rid, $err] = qp_transfer_run_create(['entity' => $eid, 'direction' => 'import', 'source' => $fmt, 'plan' => $plan, 'dry' => !empty($_POST['dry']), 'user' => $uid]);
            if ($rid) { header('Location: transfer.php?tab=runs&run=' . $rid . '&go=1'); exit; }
            $notice = (string)$err;
        }
        $tab = 'import'; $_GET['entity'] = $eid; $_GET['file'] = $file;
    } elseif ($act === 'start_export') {
        $eid = (string)($_POST['entity'] ?? '');
        if (!isset($exportable[$eid])) $notice = 'Choose what to export.';
        else {
            $filters = [];
            foreach ((array)($exportable[$eid]['export_filters'] ?? []) as $fid => $fd) { $v = trim((string)($_POST['filter'][$fid] ?? '')); if ($v !== '') $filters[$fid] = $v; }
            [$rid, $err] = qp_transfer_run_create(['entity' => $eid, 'direction' => 'export', 'plan' => ['format' => (string)($_POST['format'] ?? 'csv'), 'filters' => $filters, 'limit' => max(0, (int)($_POST['limit'] ?? 0))], 'user' => $uid]);
            if ($rid) { header('Location: transfer.php?tab=runs&run=' . $rid . '&go=1'); exit; }
            $notice = (string)$err;
        }
        $tab = 'export';
    } elseif (in_array($act, ['cancel', 'undo', 'delete', 'rerun'], true)) {
        $run = qp_transfer_run_get((int)($_POST['run'] ?? 0));
        if (!$run || !qp_transfer_can($run['entity'])) { qp_set_admin_notice('Not allowed.'); header('Location: transfer.php?tab=runs'); exit; }
        if ($act === 'cancel') { qp_transfer_run_cancel($run['id']); qp_set_admin_notice('Run cancelled.'); header('Location: transfer.php?tab=runs&run=' . $run['id']); exit; }
        if ($act === 'delete') { qp_set_admin_notice(qp_transfer_run_delete($run['id']) ? 'Run deleted.' : 'A run that is working cannot be deleted.'); header('Location: transfer.php?tab=runs'); exit; }
        if ($act === 'undo') { [$uok, $um] = qp_transfer_run_undo($run['id']); qp_set_admin_notice($uok ? 'Undoing: removing what this run created.' : $um); header('Location: transfer.php?tab=runs&run=' . $run['id'] . ($uok ? '&go=1' : '')); exit; }
        if ($act === 'rerun') {   // a dry run becomes a real run with the same mapping
            $plan = $run['plan'];
            [$rid, $err] = qp_transfer_run_create(['entity' => $run['entity'], 'direction' => 'import', 'source' => $run['source'], 'plan' => $plan, 'dry' => false, 'user' => $uid]);
            if ($rid) { header('Location: transfer.php?tab=runs&run=' . $rid . '&go=1'); exit; }
            qp_set_admin_notice((string)$err); header('Location: transfer.php?tab=runs&run=' . $run['id']); exit;
        }
    }
}

$flash = function_exists('qp_get_admin_notice') ? qp_get_admin_notice() : null;
if ($notice === '' && $flash) $notice = (string)$flash;
require_once __DIR__ . '/inc/header.php';
require_once __DIR__ . '/inc/navbar.php';
$label = ['queued' => 'waiting', 'running' => 'working', 'done' => 'finished', 'failed' => 'failed', 'cancelled' => 'cancelled', 'undoing' => 'undoing', 'undone' => 'undone'];
$badge = ['queued' => 'secondary', 'running' => 'primary', 'done' => 'success', 'failed' => 'danger', 'cancelled' => 'warning', 'undoing' => 'primary', 'undone' => 'secondary'];
$form = function ($act, $inner, $class = 'd-inline') use ($nonce, $h) { return '<form method="post" class="' . $class . '"><input type="hidden" name="nonce" value="' . $h($nonce) . '"><input type="hidden" name="act" value="' . $h($act) . '">' . $inner . '</form>'; };
?>
<div class="container mt-4">
  <h2>Import / Export</h2>
  <p class="text-muted">Move content in and out of the site as a CSV, JSON or JSON-lines file. Every import can be tried first as a <strong>dry run</strong> that writes nothing, repeating an import never makes duplicates, and a finished import can be undone.</p>
  <?php if ($notice !== ''): ?><div class="alert alert-info"><?= $h($notice) ?></div><?php endif; ?>
  <ul class="nav nav-tabs mb-3">
    <?php foreach (['import' => 'Import', 'export' => 'Export', 'runs' => 'Runs'] as $k => $l): ?>
      <li class="nav-item"><a class="nav-link<?= $tab === $k ? ' active' : '' ?>" href="transfer.php?tab=<?= $k ?>"><?= $h($l) ?></a></li>
    <?php endforeach; ?>
  </ul>

<?php if ($tab === 'import'):
    $eid = (string)($_GET['entity'] ?? ''); $file = (string)($_GET['file'] ?? ''); $path = $file !== '' ? qp_transfer_file_path($file) : null;
    if ($path && is_file($path) && isset($importable[$eid])):
        $fmt = pathinfo($path, PATHINFO_EXTENSION); [$iok, $info] = qp_transfer_file_inspect($path, $fmt); $ent = $importable[$eid];
        if (!$iok): ?><div class="alert alert-danger"><?= $h($info) ?></div>
    <?php else: ?>
  <h5>Step 2 of 2: match the columns <small class="text-muted">(<?= $h($ent['label']) ?> from <?= $h($_GET['orig'] ?? 'your file') ?>)</small></h5>
  <p>The file has <strong><?= count($info['columns']) ?></strong> columns<?= $info['rows'] !== null ? ' and ' . (int)$info['rows'] . ' rows' : '' ?>. Choose which column fills each field. A field you leave empty is not touched. A <em>fixed value</em> fills a field with the same value on every row.</p>
  <form method="post"><input type="hidden" name="nonce" value="<?= $h($nonce) ?>"><input type="hidden" name="act" value="start_import"><input type="hidden" name="entity" value="<?= $h($eid) ?>"><input type="hidden" name="file" value="<?= $h($file) ?>">
    <table class="table table-sm"><thead><tr><th>Field</th><th>Column in your file</th><th>or a fixed value</th></tr></thead><tbody>
    <?php foreach ($ent['fields'] as $fid => $fd):
        $guess = '';
        $norm = function ($t) { return preg_replace('/[^a-z0-9]/', '', strtolower((string)$t)); };
        $want = [$norm($fid), $norm($fd['label'])];
        foreach ($info['columns'] as $c) { if (in_array($norm($c), $want, true)) { $guess = $c; break; } }
        if ($guess === '') {    // a shorter column name that begins the field's name ("Regular" for Regular price)
            foreach ($info['columns'] as $c) { $n = $norm($c); if (strlen($n) >= 4 && (strpos($want[0], $n) === 0 || strpos($want[1], $n) === 0)) { $guess = $c; break; } }
        }
        $sel = (string)($_POST['map'][$fid] ?? $guess); ?>
      <tr><td><strong><?= $h($fd['label']) ?></strong><?= !empty($fd['required']) ? ' <span class="text-danger">*</span>' : '' ?> <small class="text-muted d-block"><?= $h($fd['type']) ?></small></td>
        <td><select class="form-control form-control-sm" name="map[<?= $h($fid) ?>]"><option value="">(none)</option><?php foreach ($info['columns'] as $c): ?><option value="<?= $h($c) ?>"<?= $c === $sel ? ' selected' : '' ?>><?= $h($c) ?></option><?php endforeach; ?></select></td>
        <td><input class="form-control form-control-sm" name="fixed[<?= $h($fid) ?>]" value="<?= $h($_POST['fixed'][$fid] ?? '') ?>"></td></tr>
    <?php endforeach; ?></tbody></table>
    <?php if ($info['sample']): ?><details class="mb-3"><summary>First rows of your file</summary><div class="table-responsive"><table class="table table-sm table-bordered mt-2"><thead><tr><?php foreach ($info['columns'] as $c): ?><th><?= $h($c) ?></th><?php endforeach; ?></tr></thead><tbody>
      <?php foreach ($info['sample'] as $row): ?><tr><?php foreach ($info['columns'] as $c): ?><td class="text-break"><small><?= $h(mb_substr(is_scalar($row[$c] ?? '') ? (string)($row[$c] ?? '') : json_encode($row[$c]), 0, 80)) ?></small></td><?php endforeach; ?></tr><?php endforeach; ?></tbody></table></div></details><?php endif; ?>
    <?php foreach ((array)($ent['options'] ?? []) as $oid => $od): ?>
      <div class="form-group"><label for="opt_<?= $h($oid) ?>"><?= $h($od['label'] ?? $oid) ?></label>
        <select class="form-control" id="opt_<?= $h($oid) ?>" name="options[<?= $h($oid) ?>]"><?php foreach ((array)$od['choices'] as $cv => $cl): ?><option value="<?= $h($cv) ?>"<?= (string)($_POST['options'][$oid] ?? $od['default'] ?? '') === (string)$cv ? ' selected' : '' ?>><?= $h($cl) ?></option><?php endforeach; ?></select>
        <?php if (!empty($od['help'])): ?><small class="text-muted"><?= $h($od['help']) ?></small><?php endif; ?></div>
    <?php endforeach; ?>
    <div class="form-row">
      <div class="form-group col-md-4"><label for="mode">If it already exists</label><select class="form-control" id="mode" name="mode"><option value="create_update">Create new, update existing</option><option value="create_only">Create new only (skip existing)</option><option value="update_only">Update existing only (skip new)</option></select></div>
      <div class="form-group col-md-4"><label for="source_id">Where do these rows come from?</label><input class="form-control" id="source_id" name="source_id" value="<?= $h($_POST['source_id'] ?? 'file') ?>"><small class="text-muted">A short name. Rows are remembered under it, so the same file or system imported again updates instead of duplicating.</small></div>
      <div class="form-group col-md-4"><label for="limit">Only the first rows (0 = all)</label><input class="form-control" id="limit" type="number" min="0" name="limit" value="0"><small class="text-muted">Try 20 first.</small></div>
    </div>
    <div class="form-check mb-3"><input class="form-check-input" type="checkbox" id="force" name="force" value="1"><label class="form-check-label" for="force">Rewrite rows even when they did not change</label></div>
    <button class="btn btn-outline-primary" name="dry" value="1">Dry run (writes nothing)</button>
    <button class="btn btn-primary">Run the import</button>
    <a class="btn btn-link" href="transfer.php?tab=import">Start again</a>
  </form>
    <?php endif; else: ?>
  <?php if (!$importable): ?><div class="alert alert-warning">Nothing can be imported yet.</div><?php else: ?>
  <h5>Step 1 of 2: choose the file</h5>
  <form method="post" enctype="multipart/form-data" class="mb-4"><input type="hidden" name="nonce" value="<?= $h($nonce) ?>"><input type="hidden" name="act" value="upload">
    <div class="form-group"><label for="entity">What are you importing?</label><select class="form-control" id="entity" name="entity"><?php foreach ($importable as $id => $e): ?><option value="<?= $h($id) ?>"><?= $h($e['label']) ?></option><?php endforeach; ?></select></div>
    <div class="form-group"><label for="file">File (.csv, .json or .ndjson)</label><input class="form-control-file" type="file" id="file" name="file" accept=".csv,.txt,.json,.ndjson,.jsonl" required><small class="text-muted">The first line of a CSV names the columns. Largest file: <?= $h(min((int)ini_get('upload_max_filesize'), (int)ini_get('post_max_size'))) ?> MB (server limit). Use JSON lines (one object per line) for very large JSON.</small></div>
    <button class="btn btn-primary">Upload and continue</button>
  </form>
  <?php endif; endif; ?>

<?php elseif ($tab === 'export'): ?>
  <?php if (!$exportable): ?><div class="alert alert-warning">Nothing can be exported yet.</div><?php else: ?>
  <form method="post" class="mb-4"><input type="hidden" name="nonce" value="<?= $h($nonce) ?>"><input type="hidden" name="act" value="start_export">
    <div class="form-group"><label for="xentity">What are you exporting?</label><select class="form-control" id="xentity" name="entity"><?php foreach ($exportable as $id => $e): ?><option value="<?= $h($id) ?>"><?= $h($e['label']) ?></option><?php endforeach; ?></select></div>
    <?php foreach ($exportable as $id => $e): foreach ((array)($e['export_filters'] ?? []) as $fid => $fd): ?>
      <div class="form-group qp-xfilter" data-entity="<?= $h($id) ?>"><label><?= $h($fd['label'] ?? $fid) ?></label>
        <select class="form-control" name="filter[<?= $h($fid) ?>]"><option value="">(any)</option><?php foreach ((array)($fd['options'] ?? []) as $ov => $ol): ?><option value="<?= $h($ov) ?>"><?= $h($ol) ?></option><?php endforeach; ?></select></div>
    <?php endforeach; endforeach; ?>
    <div class="form-row"><div class="form-group col-md-4"><label for="format">File format</label><select class="form-control" id="format" name="format"><option value="csv">CSV (opens in a spreadsheet)</option><option value="json">JSON</option><option value="ndjson">JSON lines (best for very large exports)</option></select></div>
      <div class="form-group col-md-4"><label for="xlimit">Only the first rows (0 = all)</label><input class="form-control" id="xlimit" type="number" min="0" name="limit" value="0"></div></div>
    <button class="btn btn-primary">Export</button>
    <p class="text-muted mt-2 small">The file is kept for a day, then removed. Exports of people's data are for your own use: keep them safe.</p>
  </form>
  <script>(function(){var s=document.getElementById('xentity');function f(){document.querySelectorAll('.qp-xfilter').forEach(function(e){var on=e.dataset.entity===s.value;e.style.display=on?'':'none';e.querySelectorAll('select').forEach(function(x){x.disabled=!on;});});}s.addEventListener('change',f);f();})();</script>
  <?php endif; ?>

<?php else:
    $run = $runId ? qp_transfer_run_get($runId) : null;
    if ($run && qp_transfer_can($run['entity'])):
        $c = $run['counts']; $isImport = $run['direction'] === 'import'; $active = in_array($run['status'], ['queued', 'running', 'undoing'], true); $ent = qp_transfer_entity($run['entity']);
        $runner = function_exists('qp_cron_state') ? (qp_cron_state()['runner_at'] ?? 0) : 0; ?>
  <p><a href="transfer.php?tab=runs">&larr; All runs</a></p>
  <h5>Run <?= (int)$run['id'] ?>: <?= $isImport ? 'import' : 'export' ?> of <?= $h($ent['label'] ?? $run['entity']) ?> <?= $run['dry_run'] ? '<span class="badge badge-info">dry run</span>' : '' ?> <span id="qp-status" class="badge badge-<?= $h($badge[$run['status']] ?? 'secondary') ?>"><?= $h($label[$run['status']] ?? $run['status']) ?></span></h5>
  <div class="progress mb-3" style="height:22px"><div id="qp-bar" class="progress-bar" role="progressbar" style="width:<?= $run['status'] === 'done' || $run['status'] === 'undone' ? 100 : 5 ?>%"></div></div>
  <table class="table table-sm w-auto"><tbody id="qp-counts">
    <?php foreach (($isImport ? ['read' => 'Rows read', 'created' => $run['dry_run'] ? 'Would be created' : 'Created', 'updated' => $run['dry_run'] ? 'Would be updated' : 'Updated', 'same' => 'Unchanged', 'skipped' => 'Skipped', 'rejected' => 'Rejected (see below)', 'undone' => 'Removed by undo'] : ['exported' => 'Rows exported']) as $k => $l): ?>
      <tr><td><?= $h($l) ?></td><td data-count="<?= $k ?>"><strong><?= (int)$c[$k] ?></strong></td></tr>
    <?php endforeach; ?></tbody></table>
  <div id="qp-error" class="alert alert-danger" style="<?= $run['last_error'] ? '' : 'display:none' ?>"><?= $h($run['last_error']) ?></div>
  <p id="qp-note" class="text-muted small" style="<?= $active ? '' : 'display:none' ?>">Keep this page open and it carries on by itself. <?= $runner > time() - 900 ? 'A scheduled-task runner is active, so the run also continues if you close it.' : 'No scheduled-task runner is active (Settings > Cron), so it waits while this page is closed.' ?></p>
  <div id="qp-actions" class="mb-3">
    <?php if ($run['status'] === 'done' && !$isImport && $run['output'] !== ''): ?><a class="btn btn-primary" href="transfer.php?download=<?= (int)$run['id'] ?>&amp;token=<?= $h(qp_transfer_token($run['id'], 'file', $uid)) ?>">Download the file</a><?php endif; ?>
    <?php if ($run['status'] === 'done' && $isImport && $run['dry_run']): ?><?= $form('rerun', '<input type="hidden" name="run" value="' . (int)$run['id'] . '"><button class="btn btn-primary">Looks right: run it for real</button>') ?><?php endif; ?>
    <?php if (in_array($run['status'], ['queued', 'running'], true)): ?><?= $form('cancel', '<input type="hidden" name="run" value="' . (int)$run['id'] . '"><button class="btn btn-outline-warning">Cancel</button>') ?><?php endif; ?>
    <?php if ($isImport && !$run['dry_run'] && in_array($run['status'], ['done', 'cancelled', 'failed'], true) && !empty($ent['delete'])): ?><?= $form('undo', '<input type="hidden" name="run" value="' . (int)$run['id'] . '"><button class="btn btn-outline-danger" onclick="return confirm(\'Delete everything this run created? Rows it only updated are not changed back.\')">Undo this run</button>') ?><?php endif; ?>
    <?php if (!$active): ?><?= $form('delete', '<input type="hidden" name="run" value="' . (int)$run['id'] . '"><button class="btn btn-link text-danger">Delete this record</button>') ?><?php endif; ?>
  </div>
  <?php if ($run['problems']): ?>
    <h6>Rows that need a look <small class="text-muted">(first <?= count($run['problems']) ?>; <a href="transfer.php?problems=<?= (int)$run['id'] ?>&amp;token=<?= $h(qp_transfer_token($run['id'], 'problems', $uid)) ?>">download as CSV</a>)</small></h6>
    <table class="table table-sm"><thead><tr><th>Row</th><th>Key</th><th>What happened</th><th>Why</th><th>Data</th></tr></thead><tbody>
    <?php foreach (array_slice($run['problems'], 0, 100) as $p): ?><tr><td><?= (int)$p['row'] ?></td><td><code><?= $h($p['key']) ?></code></td><td><span class="badge badge-<?= ($p['level'] ?? 'rejected') === 'rejected' ? 'danger' : (($p['level'] ?? '') === 'skipped' ? 'secondary' : 'info') ?>"><?= $h(['rejected' => 'rejected', 'skipped' => 'skipped', 'note' => 'saved, with a note'][$p['level'] ?? 'rejected'] ?? 'rejected') ?></span></td><td><?= $h($p['reason']) ?></td><td><small class="text-muted"><?= $h($p['data']) ?></small></td></tr><?php endforeach; ?></tbody></table>
  <?php endif; ?>
  <script>
  (function(){
    var run = <?= (int)$run['id'] ?>, token = <?= json_encode(qp_transfer_token($run['id'], 'step', $uid)) ?>, go = <?= $active ? 'true' : 'false' ?>, busy = 0;
    if (!go) return;
    function paint(s){
      document.getElementById('qp-status').textContent = ({queued:'waiting',running:'working',done:'finished',failed:'failed',cancelled:'cancelled',undoing:'undoing',undone:'undone'})[s.status] || s.status;
      var b = document.getElementById('qp-bar'); b.style.width = (s.progress == null ? 40 : s.progress) + '%'; b.classList.toggle('progress-bar-striped', s.status === 'running' || s.status === 'undoing'); b.classList.toggle('progress-bar-animated', s.status === 'running' || s.status === 'undoing');
      Object.keys(s.counts || {}).forEach(function(k){ var e = document.querySelector('[data-count="'+k+'"]'); if (e) e.innerHTML = '<strong>' + s.counts[k] + '</strong>'; });
      if (s.error) { var er = document.getElementById('qp-error'); er.textContent = s.error; er.style.display = ''; }
    }
    function step(){
      var fd = new FormData(); fd.append('ajax', 'step'); fd.append('run', run); fd.append('token', token);
      fetch('transfer.php', {method: 'POST', body: fd, credentials: 'same-origin'}).then(function(r){ return r.text(); }).then(function(t){ return JSON.parse(t.replace(/^\uFEFF|\uFEFF\s*$/g, '')); }).then(function(s){
        if (s.error) { document.getElementById('qp-error').textContent = s.error; document.getElementById('qp-error').style.display = ''; return; }
        paint(s);
        if (s.state === 'running' || s.state === 'busy') { setTimeout(step, s.state === 'busy' ? 2500 : 200); }
        else { location.href = 'transfer.php?tab=runs&run=' + run; }
      }).catch(function(){ setTimeout(step, 4000); });
    }
    step();
  })();
  </script>
    <?php else: $runs = qp_transfer_runs_list(60); ?>
  <table class="table table-sm"><thead><tr><th>#</th><th>When</th><th>What</th><th>Status</th><th>Rows</th><th></th></tr></thead><tbody>
  <?php foreach ($runs as $r): if (!qp_transfer_can($r['entity'])) continue; $e = qp_transfer_entity($r['entity']); ?>
    <tr><td><?= (int)$r['id'] ?></td><td><?= $h(date('Y-m-d H:i', $r['created_at'])) ?></td><td><?= $r['direction'] === 'import' ? 'Import' : 'Export' ?>: <?= $h($e['label'] ?? $r['entity']) ?> <?= $r['dry_run'] ? '<span class="badge badge-info">dry run</span>' : '' ?></td>
      <td><span class="badge badge-<?= $h($badge[$r['status']] ?? 'secondary') ?>"><?= $h($label[$r['status']] ?? $r['status']) ?></span></td>
      <td><small><?= $r['direction'] === 'import' ? (int)$r['counts']['read'] . ' read, ' . (int)$r['counts']['created'] . ' new, ' . (int)$r['counts']['updated'] . ' updated, ' . (int)$r['counts']['rejected'] . ' rejected' : (int)$r['counts']['exported'] . ' exported' ?></small></td>
      <td><a href="transfer.php?tab=runs&amp;run=<?= (int)$r['id'] ?>">Open</a></td></tr>
  <?php endforeach; if (!$runs): ?><tr><td colspan="6" class="text-muted">No runs yet.</td></tr><?php endif; ?></tbody></table>
  <?php endif; ?>
<?php endif; ?>
</div>
<?php include __DIR__ . '/inc/footer.php'; ?>
