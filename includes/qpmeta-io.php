<?php
if (!defined('QLOPY_INIT')) { http_response_code(403); exit; }
/**
 * qpmeta option panels: export and import (opt-in).
 *
 * One panel:   qpmeta_register_metabox('my_box', ['object_types' => ['option'], 'option_prefix' => 'my_panel', 'io' => true, ...]);
 *              qpmeta_render_metaboxes('option', 0, 'my_panel') then shows an Export / Import bar above that panel's forms.
 * Several:     qpmeta_io_register_bundle('my_theme', ['my_panel_a', 'my_panel_b'], 'Theme panel (all tabs)');   (on admin init)
 *              qpmeta_io_render_toolbar(['bundle' => 'my_theme']);                                                 (where the bar goes)
 *
 * The file (JSON): {"format": "qpmeta-options", "version": 1, "site", "exported_at", "panels": {prefix: {field id: value}},
 *                   "i18n": {prefix: {field id: {lang: value}}}, "media": {attachment id: {"url", "name"}}}
 * Only the fields registered for the chosen prefixes are read or written, never anything else in the options table.
 *
 * Import: the file is checked and previewed first (new / changed / unchanged / ignored, images to download), then applied on
 * confirmation. Values go through the same hooks as the panel's Save (qpmeta_sanitize_field, repeatable_row normalising) and are
 * stored with qpmeta_update_object_meta(). Pictures: on the site that made the file the attachment is kept; elsewhere it is
 * downloaded from the exported address into the media library (qp_transfer_fetch_media: checked download, real file type, all
 * image sizes, the same address fetched once). A picture that cannot be fetched is skipped (a single picture field keeps its
 * current value, a picture inside a repeatable group is left empty so the rows stay aligned) and the import goes on.
 * Before anything is written the current values are kept (option qpmeta_io_backup) for "Undo last import".
 * Who: administrators (manage_options) with the screen's token. Size: at most 2 MB of JSON.
 */

const QPMETA_IO_FORMAT = 'qpmeta-options';
const QPMETA_IO_VERSION = 1;
const QPMETA_IO_MAX_BYTES = 2097152;
const QPMETA_IO_BACKUP = 'qpmeta_io_backup';

/** Register a set of option prefixes exported and imported together (one file). */
function qpmeta_io_register_bundle(string $id, array $prefixes, string $label = ''): void {
    $prefixes = array_values(array_filter(array_map('strval', $prefixes), 'qpmeta_io_valid_prefix'));
    if (!preg_match('/^[A-Za-z0-9_\-]{1,60}$/', $id) || !$prefixes) return;
    $GLOBALS['qpmeta_io_bundles'][$id] = ['prefixes' => $prefixes, 'label' => $label !== '' ? $label : $id];
}

function qpmeta_io_valid_prefix($p): bool {
    return is_string($p) && preg_match('/^[A-Za-z0-9_\-]{1,100}$/', $p) === 1;
}

/**
 * May the current user export and import these prefixes? Administrators (manage_options) by default. A plugin can widen it, for
 * example the store manager for its staff:  add_filter('qpmeta_io_user_can', fn ($can, $prefixes) => $can || qcsm_user_can(), 10, 2);
 */
function qpmeta_io_user_can(array $prefixes): bool {
    $can = function_exists('check_permission') && check_permission('manage_options');
    return function_exists('apply_filters') ? (bool)apply_filters('qpmeta_io_user_can', $can, $prefixes) : $can;
}

/** Does any option metabox of this prefix say 'io' => true? */
function qpmeta_io_prefix_enabled(string $prefix): bool {
    foreach ($GLOBALS['qpmeta_metaboxes'] ?? [] as $mb) {
        if (($mb['option_prefix'] ?? '') === $prefix && !empty($mb['io']) && in_array('option', (array)($mb['object_types'] ?? []), true)) return true;
    }
    return false;
}

/**
 * The fields stored under a prefix: field id => ['field' => definition, 'list' => stored as a list?, 'group' => inside a group?].
 * A repeatable field, a field of a repeatable metabox and every group field are stored as lists.
 */
function qpmeta_io_fields(string $prefix): array {
    $out = [];
    if (!qpmeta_io_valid_prefix($prefix)) return $out;
    foreach ($GLOBALS['qpmeta_metaboxes'] ?? [] as $mb) {
        if (($mb['option_prefix'] ?? '') !== $prefix || !in_array('option', (array)($mb['object_types'] ?? []), true)) continue;
        $mbList = !empty($mb['repeatable']);
        foreach ($mb['fields'] ?? [] as $f) {
            if (!empty($f['id']) && is_string($f['id'])) $out[$f['id']] = ['field' => $f, 'list' => $mbList || !empty($f['repeatable']), 'group' => false];
        }
        foreach ($mb['groups'] ?? [] as $g) {
            foreach ($g['fields'] ?? [] as $f) {
                if (!empty($f['id']) && is_string($f['id'])) $out[$f['id']] = ['field' => $f, 'list' => true, 'group' => true];
            }
        }
    }
    return $out;
}

/** What a request may touch: ['prefixes' => [...], 'label' => ..., 'scope' => query string] for a bundle id or one io-enabled prefix, or null. */
function qpmeta_io_scope(array $req): ?array {
    $bundle = (string)($req['bundle'] ?? '');
    if ($bundle !== '') {
        $b = $GLOBALS['qpmeta_io_bundles'][$bundle] ?? null;
        return $b ? ['prefixes' => $b['prefixes'], 'label' => $b['label'], 'scope' => 'bundle=' . rawurlencode($bundle)] : null;
    }
    $prefix = (string)($req['prefix'] ?? '');
    if (qpmeta_io_valid_prefix($prefix) && qpmeta_io_prefix_enabled($prefix)) {
        return ['prefixes' => [$prefix], 'label' => $prefix, 'scope' => 'prefix=' . rawurlencode($prefix)];
    }
    return null;
}

function qpmeta_io_is_media(array $field): bool {
    return in_array($field['type'] ?? '', ['file', 'filesortable'], true);
}

/** Attachment ids inside a stored picture value (an id, a list of ids, a list of lists). */
function qpmeta_io_ids($v): array {
    if (is_int($v) || (is_string($v) && ctype_digit($v))) return (int)$v > 0 ? [(int)$v] : [];
    $out = [];
    if (is_array($v)) foreach ($v as $x) $out = array_merge($out, qpmeta_io_ids($x));
    return $out;
}

function qpmeta_io_site_url(): string {
    return defined('SITE_URL') ? rtrim((string)SITE_URL, '/') : '';
}

/** The full address of an attachment, or ''. */
function qpmeta_io_attachment_url(int $id): string {
    $u = function_exists('qp_get_attachment_url') ? (string)(qp_get_attachment_url($id) ?? '') : '';
    if ($u === '') return '';
    if (preg_match('~^https?://~i', $u)) return $u;
    $parts = parse_url(qpmeta_io_site_url());
    $origin = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '') . (isset($parts['port']) ? ':' . $parts['port'] : '');
    return $origin . '/' . ltrim($u, '/');
}

/** Build the export document for some prefixes. */
function qpmeta_io_export(array $prefixes): array {
    $doc = ['format' => QPMETA_IO_FORMAT, 'version' => QPMETA_IO_VERSION, 'site' => qpmeta_io_site_url(), 'exported_at' => gmdate('c'),
            'qlopy' => defined('QLOPY_VERSION') ? QLOPY_VERSION : '', 'panels' => [], 'i18n' => [], 'media' => []];
    foreach ($prefixes as $p) {
        foreach (qpmeta_io_fields($p) as $id => $info) {
            $key = $p . '_' . $id;
            $v = get_option_meta($key);
            if ($v !== null) {
                $doc['panels'][$p][$id] = $v;
                if (qpmeta_io_is_media($info['field'])) {
                    foreach (qpmeta_io_ids($v) as $aid) {
                        if (isset($doc['media'][(string)$aid])) continue;
                        $url = qpmeta_io_attachment_url($aid);
                        if ($url !== '') $doc['media'][(string)$aid] = ['url' => $url, 'name' => rawurldecode(basename((string)parse_url($url, PHP_URL_PATH)))];
                    }
                }
            }
            $t = get_option_meta('i18n_' . $key);
            if (is_array($t) && $t) $doc['i18n'][$p][$id] = $t;
        }
    }
    foreach (['panels', 'i18n', 'media'] as $k) $doc[$k] = (object)$doc[$k];
    return $doc;
}

/** Read an uploaded / pasted file: [true, document] or [false, message]. */
function qpmeta_io_parse(string $json): array {
    if ($json === '') return [false, 'Choose an export file first.'];
    if (strlen($json) > QPMETA_IO_MAX_BYTES) return [false, 'The file is larger than 2 MB.'];
    $doc = json_decode($json, true, 32);
    if (!is_array($doc)) return [false, 'This is not a valid export file (the JSON could not be read).'];
    if (($doc['format'] ?? '') !== QPMETA_IO_FORMAT) return [false, 'This is not a panel export file.'];
    if ((int)($doc['version'] ?? 0) !== QPMETA_IO_VERSION) return [false, 'This export file was made by a different version (' . (int)($doc['version'] ?? 0) . ').'];
    if (!is_array($doc['panels'] ?? null)) return [false, 'The export file has no panel values.'];
    foreach (['i18n', 'media'] as $k) if (!is_array($doc[$k] ?? [])) $doc[$k] = [];
    $doc['i18n'] = $doc['i18n'] ?? [];
    $doc['media'] = $doc['media'] ?? [];
    return [true, $doc];
}

/** A value's shape is something a panel stores: scalars, or lists / maps of them, at most 3 levels deep, no huge strings. */
function qpmeta_io_shape_ok($v, int $depth = 0): bool {
    if ($v === null || is_bool($v) || is_int($v) || is_float($v)) return true;
    if (is_string($v)) return strlen($v) <= 262144;
    if (!is_array($v) || $depth >= 3 || count($v) > 2000) return false;
    foreach ($v as $k => $x) {
        if (!is_int($k) && (!is_string($k) || strlen($k) > 100)) return false;
        if (!qpmeta_io_shape_ok($x, $depth + 1)) return false;
    }
    return true;
}

function qpmeta_io_same_site(array $doc): bool {
    $a = rtrim(strtolower((string)($doc['site'] ?? '')), '/');
    return $a !== '' && $a === rtrim(strtolower(qpmeta_io_site_url()), '/');
}

/**
 * Compare a document with the stored values. Returns ['rows' => [[prefix, id, label, status, list, media]], 'ignored' => [text],
 * 'images' => ['keep' => n, 'download' => n, 'missing' => n]].
 */
function qpmeta_io_plan(array $doc, array $prefixes): array {
    $rows = []; $ignored = []; $images = ['keep' => 0, 'download' => 0, 'missing' => 0]; $seen = [];
    $same = qpmeta_io_same_site($doc);
    foreach ($doc['panels'] as $p => $vals) {
        $p = (string)$p;
        if (!in_array($p, $prefixes, true)) { $ignored[] = $p . ' (not part of this screen)'; continue; }
        if (!is_array($vals)) { $ignored[] = $p . ' (unexpected value)'; continue; }
        $fields = qpmeta_io_fields($p);
        foreach ($vals as $id => $v) {
            $id = (string)$id;
            if (!isset($fields[$id])) { $ignored[] = $p . ': ' . $id . ' (no such field here)'; continue; }
            $info = $fields[$id];
            if (!qpmeta_io_shape_ok($v) || ($info['list'] && !is_array($v))) { $ignored[] = $p . ': ' . $id . ' (unexpected value)'; continue; }
            $cur = get_option_meta($p . '_' . $id);
            $media = qpmeta_io_is_media($info['field']);
            $status = $cur === null ? 'new' : (json_encode($cur) === json_encode($v) ? 'same' : 'changed');
            if ($media && !($same && $status === 'same')) {
                foreach (qpmeta_io_ids($v) as $aid) {
                    if (isset($seen[$aid])) continue;
                    $seen[$aid] = true;
                    if ($same && function_exists('qp_get_attachment_metadata') && qp_get_attachment_metadata($aid)) $images['keep']++;
                    elseif (!empty($doc['media'][(string)$aid]['url'])) $images['download']++;
                    else $images['missing']++;
                }
                if ($status === 'same' && !$same) $status = 'changed';   // the ids belong to another site: the pictures are fetched again
            }
            $rows[] = ['prefix' => $p, 'id' => $id, 'label' => (string)($info['field']['name'] ?? $id), 'status' => $status, 'list' => $info['list'], 'media' => $media];
        }
    }
    return ['rows' => $rows, 'ignored' => $ignored, 'images' => $images];
}

/** Map one exported attachment id to one on this site: [new id or 0, 'kept'|'downloaded'|'reused'|'skipped', message]. */
function qpmeta_io_resolve_image(int $aid, array $doc, array &$cache): array {
    if (isset($cache[$aid])) return $cache[$aid];
    if (qpmeta_io_same_site($doc) && function_exists('qp_get_attachment_metadata') && qp_get_attachment_metadata($aid)) {
        return $cache[$aid] = [$aid, 'kept', ''];
    }
    $m = $doc['media'][(string)$aid] ?? null;
    $url = is_array($m) ? trim((string)($m['url'] ?? '')) : '';
    if ($url === '') return $cache[$aid] = [0, 'skipped', 'picture #' . $aid . ' has no address in the file'];
    if (!function_exists('qp_transfer_fetch_media')) require_once __DIR__ . '/transfer.php';
    // A file made on this same server (a copy of the site on another address of the same host) may be on a private address.
    $local = strtolower((string)parse_url($url, PHP_URL_HOST)) === strtolower((string)parse_url(qpmeta_io_site_url(), PHP_URL_HOST));
    try {
        [$ok, $res] = qp_transfer_fetch_media($url, [
            'user' => function_exists('qp_current_user_id') ? (int)qp_current_user_id() : 0,
            'name' => is_array($m) ? (string)($m['name'] ?? '') : '',
            'allow_private' => $local, 'verify_tls' => !$local,
        ]);
    } catch (Throwable $e) {
        [$ok, $res] = [false, $e->getMessage()];
    }
    if (!$ok || empty($res['id'])) return $cache[$aid] = [0, 'skipped', basename((string)parse_url($url, PHP_URL_PATH)) . ': ' . (is_string($res) ? $res : 'not saved')];
    return $cache[$aid] = [(int)$res['id'], !empty($res['reused']) ? 'reused' : 'downloaded', ''];
}

/**
 * Apply a document. Returns a report: applied, unchanged, skipped (texts), images (kept / downloaded / reused / skipped counts).
 */
function qpmeta_io_apply(array $doc, array $prefixes): array {
    $plan = qpmeta_io_plan($doc, $prefixes);
    $report = ['applied' => 0, 'unchanged' => 0, 'skipped' => $plan['ignored'], 'images' => ['kept' => 0, 'downloaded' => 0, 'reused' => 0, 'skipped' => 0]];
    $todo = array_values(array_filter($plan['rows'], static fn ($r) => $r['status'] !== 'same'));
    $report['unchanged'] = count($plan['rows']) - count($todo);

    // Keep what is there now, for "Undo last import".
    $backup = ['time' => time(), 'user' => function_exists('qp_current_user_id') ? (int)qp_current_user_id() : 0, 'prefixes' => array_values($prefixes), 'values' => [], 'i18n' => []];
    foreach ($todo as $r) {
        $key = $r['prefix'] . '_' . $r['id'];
        $backup['values'][$key] = ['value' => get_option_meta($key), 'list' => $r['list']];
        $backup['i18n'][$key] = get_option_meta('i18n_' . $key);
    }
    if ($todo) update_option_meta(QPMETA_IO_BACKUP, $backup);

    $cache = [];
    foreach ($todo as $r) {
        $p = $r['prefix']; $id = $r['id']; $key = $p . '_' . $id;
        $info = qpmeta_io_fields($p)[$id];
        $field = $info['field'];
        $v = $doc['panels'][$p][$id];

        if ($r['media']) {
            $map = static function ($aid) use ($doc, &$cache, &$report) {
                [$new, $how, $msg] = qpmeta_io_resolve_image((int)$aid, $doc, $cache);
                $report['images'][$how]++;
                if ($how === 'skipped') $report['skipped'][] = 'Picture skipped: ' . $msg;
                return $new;
            };
            if ($info['list']) {
                // Inside a group / repeatable list: a missing picture leaves its row's slot empty, so the other columns keep their rows.
                $v = array_map(static function ($x) use ($map) {
                    if (is_array($x)) return array_values(array_filter(array_map($map, qpmeta_io_ids($x))));
                    $ids = qpmeta_io_ids($x);
                    return $ids ? ($map($ids[0]) ?: '') : $x;
                }, array_values($v));
            } elseif (is_array($v)) {
                $v = array_values(array_filter(array_map($map, qpmeta_io_ids($v))));   // filesortable / multiple: missing ones drop out
            } else {
                $ids = qpmeta_io_ids($v);
                if ($ids) {
                    $new = $map($ids[0]);
                    if (!$new) continue;                                              // keep the field's current picture
                    $v = $new;
                }
            }
        }

        if (!$info['group']) {
            try {
                if (($field['type'] ?? '') === 'repeatable_row' && function_exists('qpmeta_rows_normalize')) $v = qpmeta_rows_normalize($v, $field);
                if (function_exists('apply_filters')) $v = apply_filters('qpmeta_sanitize_field', $v, $field, 'option', 0, []);
            } catch (InvalidArgumentException $e) {
                $report['skipped'][] = ($field['name'] ?? $id) . ': ' . $e->getMessage();
                continue;
            }
        }
        qpmeta_update_object_meta('option', 0, $key, $v);
        $t = $doc['i18n'][$p][$id] ?? null;
        if (is_array($t) && $t && qpmeta_io_shape_ok($t)) qpmeta_update_object_meta('option', 0, 'i18n_' . $key, $t);
        $report['applied']++;
    }
    return $report;
}

/** The last import's backup when it covers these prefixes, else null. */
function qpmeta_io_backup_for(array $prefixes): ?array {
    $b = get_option_meta(QPMETA_IO_BACKUP);
    if (!is_array($b) || empty($b['values']) || !is_array($b['prefixes'] ?? null)) return null;
    return array_diff($b['prefixes'], $prefixes) ? null : $b;
}

/** Put back what the last import replaced. Returns the number of values restored. */
function qpmeta_io_undo(array $prefixes): int {
    $b = qpmeta_io_backup_for($prefixes);
    if (!$b) return 0;
    $n = 0;
    foreach ($b['values'] as $key => $old) {
        if (!is_string($key) || !preg_match('/^[A-Za-z0-9_\-]{1,200}$/', $key)) continue;
        $value = $old['value'] ?? null;
        // A value that did not exist before is written empty (options cannot be deleted); panels read '' / [] as "not set".
        qpmeta_update_object_meta('option', 0, $key, $value === null ? (!empty($old['list']) ? [] : '') : $value);
        $t = $b['i18n'][$key] ?? null;
        qpmeta_update_object_meta('option', 0, 'i18n_' . $key, is_array($t) ? $t : []);
        $n++;
    }
    update_option_meta(QPMETA_IO_BACKUP, []);
    return $n;
}

/* ---------------------------------------------------------------------------------------------------------------------
 * The bar (Export, Import with preview, Undo) and its endpoints
 * ------------------------------------------------------------------------------------------------------------------- */

/** Print the Export / Import bar for ['bundle' => id] or ['prefix' => io-enabled prefix]. */
function qpmeta_io_render_toolbar(array $what): void {
    $scope = qpmeta_io_scope($what);
    if (!$scope || !qpmeta_io_user_can($scope['prefixes'])) return;
    $ajax = qpmeta_io_site_url() . '/' . (function_exists('qp_admin_dir') ? qp_admin_dir() : 'admin') . '/ajax.php';
    $nonce = function_exists('qp_create_nonce') ? qp_create_nonce('qpmeta_io') : '';
    $h = static fn ($s): string => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    $exportUrl = $ajax . '?action=qpmeta_io_export&' . $scope['scope'] . '&nonce=' . rawurlencode($nonce);
    $backup = qpmeta_io_backup_for($scope['prefixes']);
    static $assets = false;
    ?>
<div class="qpmeta-io" data-qpmeta-io data-ajax="<?= $h($ajax) ?>" data-nonce="<?= $h($nonce) ?>" data-scope="<?= $h($scope['scope']) ?>"
     style="margin:16px 0;padding:12px 14px;border:1px solid #cfd8dc;border-radius:8px;background:rgba(0,0,0,.02)">
  <div style="display:flex;flex-wrap:wrap;align-items:center;gap:8px">
    <strong style="margin-right:auto">Export / import: <?= $h($scope['label']) ?></strong>
    <a class="btn btn-sm btn-outline-secondary" href="<?= $h($exportUrl) ?>">Export</a>
    <label class="btn btn-sm btn-outline-primary" style="margin:0">Import&hellip;<input type="file" accept=".json,application/json" data-qpmeta-io-file hidden></label>
<?php if ($backup): ?>
    <button type="button" class="btn btn-sm btn-outline-danger" data-qpmeta-io-undo>Undo last import (<?= $h(date('M j, H:i', (int)$backup['time'])) ?>)</button>
<?php endif; ?>
  </div>
  <div data-qpmeta-io-out style="margin-top:8px;font-size:14px"></div>
</div>
<?php
    if ($assets) return;
    $assets = true;
    ?>
<script>
(function () {
  if (window.qpmetaIoReady) return; window.qpmetaIoReady = true;
  function esc(s) { return String(s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
  function post(box, action, extra) {
    var body = extra instanceof FormData ? extra : new FormData();
    body.append('action', action); body.append('nonce', box.getAttribute('data-nonce'));
    box.getAttribute('data-scope').split('&').forEach(function (kv) { var p = kv.split('='); body.append(p[0], decodeURIComponent(p[1] || '')); });
    return fetch(box.getAttribute('data-ajax'), { method: 'POST', body: body, credentials: 'same-origin' }).then(function (r) { return r.json(); });
  }
  function list(title, items) { return items.length ? '<div style="margin-top:6px"><b>' + esc(title) + '</b><ul style="margin:2px 0 0 18px;padding:0">' + items.map(function (t) { return '<li>' + esc(t) + '</li>'; }).join('') + '</ul></div>' : ''; }
  document.addEventListener('change', function (e) {
    var input = e.target.closest && e.target.closest('[data-qpmeta-io-file]');
    if (!input || !input.files || !input.files[0]) return;
    var box = input.closest('[data-qpmeta-io]'), out = box.querySelector('[data-qpmeta-io-out]'), file = input.files[0];
    out.innerHTML = 'Reading ' + esc(file.name) + '&hellip;';
    var fd = new FormData(); fd.append('file', file);
    post(box, 'qpmeta_io_preview', fd).then(function (j) {
      input.value = '';
      if (j.status !== 'success') { out.innerHTML = '<span style="color:#c62828">' + esc(j.message || 'The file could not be read.') + '</span>'; return; }
      var s = j.summary, html = '<div>From <b>' + esc(j.source || 'unknown site') + '</b>' + (j.exported_at ? ', exported ' + esc(j.exported_at) : '') + '.</div>'
        + '<div>' + s.changed + ' changed, ' + s['new'] + ' new, ' + s.same + ' unchanged' + (j.ignored.length ? ', ' + j.ignored.length + ' ignored' : '') + '.'
        + (j.images.download ? ' ' + j.images.download + ' picture(s) will be downloaded.' : '') + (j.images.missing ? ' ' + j.images.missing + ' picture(s) cannot be found and will be skipped.' : '') + '</div>'
        + list('Will change', j.changes) + list('Ignored', j.ignored);
      if (s.changed + s['new'] === 0) { out.innerHTML = html + '<div style="margin-top:6px">Nothing to import: everything already matches.</div>'; return; }
      out.innerHTML = html + '<div style="margin-top:8px;display:flex;gap:8px"><button type="button" class="btn btn-sm btn-primary" data-go>Apply import</button><button type="button" class="btn btn-sm btn-light" data-no>Cancel</button></div>';
      out.querySelector('[data-no]').onclick = function () { out.innerHTML = ''; };
      out.querySelector('[data-go]').onclick = function () {
        this.disabled = true; this.textContent = 'Importing' + (j.images.download ? ' and downloading pictures' : '') + '…';
        var fd2 = new FormData(); fd2.append('file', file);
        post(box, 'qpmeta_io_import', fd2).then(function (r) {
          if (r.status !== 'success') { out.innerHTML = '<span style="color:#c62828">' + esc(r.message || 'The import failed.') + '</span>'; return; }
          var im = r.report.images;
          out.innerHTML = '<div style="color:#2e7d32"><b>Imported ' + r.report.applied + ' value(s).</b> ' + r.report.unchanged + ' unchanged. Pictures: '
            + im.kept + ' kept, ' + im.downloaded + ' downloaded, ' + im.reused + ' already in the library, ' + im.skipped + ' skipped.</div>'
            + list('Skipped', r.report.skipped) + '<div style="margin-top:8px"><button type="button" class="btn btn-sm btn-primary" onclick="location.reload()">Reload to see the values</button></div>';
        }).catch(function () { out.innerHTML = '<span style="color:#c62828">The import failed (no answer from the server).</span>'; });
      };
    }).catch(function () { input.value = ''; out.innerHTML = '<span style="color:#c62828">The file could not be checked (no answer from the server).</span>'; });
  });
  document.addEventListener('click', function (e) {
    var b = e.target.closest && e.target.closest('[data-qpmeta-io-undo]');
    if (!b) return;
    if (!b.hasAttribute('data-armed')) { b.setAttribute('data-armed', '1'); b.textContent = 'Click again to undo the last import'; return; }
    var box = b.closest('[data-qpmeta-io]'), out = box.querySelector('[data-qpmeta-io-out]');
    b.disabled = true;
    post(box, 'qpmeta_io_undo').then(function (r) {
      out.innerHTML = r.status === 'success' ? '<div style="color:#2e7d32">Restored ' + r.restored + ' value(s). <button type="button" class="btn btn-sm btn-primary" onclick="location.reload()">Reload</button></div>' : '<span style="color:#c62828">' + esc(r.message || 'Undo failed.') + '</span>';
      if (r.status === 'success') b.remove(); else b.disabled = false;
    });
  });
})();
</script>
<?php
}

/** Common checks for the endpoints: [scope] or exits with a JSON error. */
function qpmeta_io_guard(array $req): array {
    $scope = qpmeta_io_scope($req);
    if (!$scope) { echo json_encode(['status' => 'error', 'message' => 'Export and import are not switched on for this panel.']); exit; }
    if (!qpmeta_io_user_can($scope['prefixes'])) { http_response_code(403); echo json_encode(['status' => 'error', 'message' => 'No permission.']); exit; }
    if (!function_exists('qp_verify_nonce') || !qp_verify_nonce((string)($req['nonce'] ?? ''), 'qpmeta_io')) { echo json_encode(['status' => 'error', 'message' => 'This page has expired. Reload it and try again.']); exit; }
    return $scope;
}

/** The uploaded file's text (or a pasted "json" field). */
function qpmeta_io_request_json(array $req): string {
    $f = $_FILES['file'] ?? null;
    if (is_array($f) && ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK && is_uploaded_file($f['tmp_name'])) {
        if ((int)$f['size'] > QPMETA_IO_MAX_BYTES) return str_repeat(' ', QPMETA_IO_MAX_BYTES + 1);
        return (string)file_get_contents($f['tmp_name']);
    }
    return (string)($req['json'] ?? '');
}

if (function_exists('add_admin_action')) {
    add_admin_action('iitcm_admin_ajax_qpmeta_io_export', function ($req) {
        $scope = qpmeta_io_guard($req);
        $name = preg_replace('/[^A-Za-z0-9_\-]+/', '-', count($scope['prefixes']) === 1 ? $scope['prefixes'][0] : ($req['bundle'] ?? 'panels'));
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $name . '-' . date('Y-m-d') . '.json"');
        header('Cache-Control: no-store');
        echo json_encode(qpmeta_io_export($scope['prefixes']), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }, 10, 1);

    add_admin_action('iitcm_admin_ajax_qpmeta_io_preview', function ($req) {
        $scope = qpmeta_io_guard($req);
        [$ok, $doc] = qpmeta_io_parse(qpmeta_io_request_json($req));
        if (!$ok) { echo json_encode(['status' => 'error', 'message' => $doc]); exit; }
        $plan = qpmeta_io_plan($doc, $scope['prefixes']);
        $summary = ['new' => 0, 'changed' => 0, 'same' => 0];
        $changes = [];
        foreach ($plan['rows'] as $r) {
            $summary[$r['status']]++;
            if ($r['status'] !== 'same') $changes[] = $r['label'] . ($r['status'] === 'new' ? ' (new)' : '');
        }
        echo json_encode(['status' => 'success', 'source' => (string)($doc['site'] ?? ''), 'exported_at' => (string)($doc['exported_at'] ?? ''),
                          'summary' => $summary, 'changes' => array_slice($changes, 0, 200), 'ignored' => array_slice($plan['ignored'], 0, 200), 'images' => $plan['images']]);
        exit;
    }, 10, 1);

    add_admin_action('iitcm_admin_ajax_qpmeta_io_import', function ($req) {
        $scope = qpmeta_io_guard($req);
        [$ok, $doc] = qpmeta_io_parse(qpmeta_io_request_json($req));
        if (!$ok) { echo json_encode(['status' => 'error', 'message' => $doc]); exit; }
        @set_time_limit(300);   // pictures are downloaded one by one
        try {
            $report = qpmeta_io_apply($doc, $scope['prefixes']);
        } catch (Throwable $e) {
            error_log('qpmeta io import: ' . $e->getMessage());
            echo json_encode(['status' => 'error', 'message' => 'The import stopped: ' . $e->getMessage() . ' Values written before this point stay; use Undo last import to go back.']);
            exit;
        }
        $report['skipped'] = array_slice($report['skipped'], 0, 200);
        echo json_encode(['status' => 'success', 'report' => $report]);
        exit;
    }, 10, 1);

    add_admin_action('iitcm_admin_ajax_qpmeta_io_undo', function ($req) {
        $scope = qpmeta_io_guard($req);
        $n = qpmeta_io_undo($scope['prefixes']);
        echo json_encode($n > 0 ? ['status' => 'success', 'restored' => $n] : ['status' => 'error', 'message' => 'There is no import to undo for this panel.']);
        exit;
    }, 10, 1);
}
