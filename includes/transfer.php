<?php
if (!defined('QLOPY_INIT')) { http_response_code(403); exit; }
// includes/transfer.php
// Import and export engine (core upgrade plan C8). The core knows nothing about shops or any other plugin's data:
//   - an ENTITY is something that can be exported and imported (core: posts). A plugin registers its own with the filter below.
//   - a SOURCE is where import rows come from (core: csv, json, ndjson files). A plugin may register another (an API, a database dump).
//   - a RUN is one import or export: resumable batches with a cursor, a dry run, an ID map (old id -> new id) and an undo.
//
// For plugins:
//   add_filter('qp_transfer_entities', function ($e) {
//       $e['my_things'] = [
//           'label'  => 'My things',
//           'fields' => ['name' => ['label' => 'Name', 'type' => 'string', 'required' => true], 'price' => ['label' => 'Price', 'type' => 'decimal']],
//           'key'    => 'name',                                   // the field that makes a row "the same row" on a repeat run (optional)
//           'find'   => function (array $row, array $info): ?int { ... },   // an existing local id for this row, when the ID map does not know it (optional);
//                       // $info has options and plan. Return ['reject' => 'why'] to refuse the row (for example two accounts match)
//           'options' => ['match' => ['label' => 'Match on', 'choices' => ['email' => 'Email', 'phone' => 'Phone'], 'default' => 'email', 'help' => '...']],   // choices the screen asks for; $ctx['options'] has the answers
//           'import_row' => function (array $row, array $ctx): array { ... return ['result' => 'created', 'id' => 12]; },
//           'delete' => function (int $id): bool { ... },         // what "undo this run" calls for rows the run created (optional; no delete = no undo)
//           'export_read' => function ($cursor, int $limit, array $filters): array { return ['rows' => [...], 'next' => $cursor2, 'done' => false]; },
//           'capability' => 'manage_options',
//       ];
//       return $e;
//   });
//   import_row: `$row` has the entity's field names, already cleaned and typed. `$ctx` has dry (true: write nothing), local_id (or null),
//   mode, run_id, source, plan, and media (callable: $ctx['media']($url) downloads an image and returns [ok, ['id' => attachment id, ...]]).
//   It returns ['result' => created|updated|same|skipped|rejected, 'id' => local id, 'reason' => text]. Throwing counts as rejected.
//   Every row runs inside a database transaction that a dry run rolls back, so nothing is saved by a dry run even if an entity forgets.
//
// A site that never opens Settings > Import / Export pays nothing: this file loads only for the admin screen, for a plugin that calls it, and
// while a run is waiting (option qp_transfer_active), so the queue task that carries it on has its handler.
// Tables (created the first time an administrator uses it, never during a visitor request): transfer_runs, transfer_map.

require_once __DIR__ . '/http-fetch.php';
require_once __DIR__ . '/transfer-users.php';

const QP_TRANSFER_SCHEMA = 1;
const QP_TRANSFER_MAX_PROBLEMS = 200;
const QP_TRANSFER_ACTIVE_OPTION = 'qp_transfer_active';
const QP_TRANSFER_HOOK = 'qp_transfer_step';

function qp_transfer_runs_table(): string { return table_name('transfer_runs'); }
function qp_transfer_map_table(): string { return table_name('transfer_map'); }

/** Create the two tables (idempotent). Called by every public entry point; costs one option read after the first time. */
function qp_transfer_install(?PDO $pdo = null, bool $force = false): void {
    static $done = false;
    if ($done && !$force) return;
    if (!$force && function_exists('get_option_meta') && (int)get_option_meta('qp_transfer_schema') >= QP_TRANSFER_SCHEMA) { $done = true; return; }
    $pdo = $pdo ?: db();
    $runs = qp_transfer_runs_table(); $map = qp_transfer_map_table();
    $pdo->exec("CREATE TABLE IF NOT EXISTS `{$runs}` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `entity` VARCHAR(80) NOT NULL,
        `direction` VARCHAR(10) NOT NULL,
        `source` VARCHAR(40) NOT NULL DEFAULT '',
        `status` VARCHAR(20) NOT NULL DEFAULT 'queued',
        `dry_run` TINYINT NOT NULL DEFAULT 0,
        `plan` MEDIUMTEXT,
        `cursor_value` VARCHAR(255) NOT NULL DEFAULT '0',
        `counts` TEXT,
        `problems` MEDIUMTEXT,
        `output` VARCHAR(255) NOT NULL DEFAULT '',
        `last_error` TEXT,
        `created_by` BIGINT UNSIGNED NOT NULL DEFAULT 0,
        `lock_until` INT NOT NULL DEFAULT 0,
        `created_at` INT NOT NULL,
        `updated_at` INT NOT NULL,
        `finished_at` INT NULL,
        PRIMARY KEY (`id`),
        INDEX (`status`),
        INDEX (`entity`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS `{$map}` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `source` VARCHAR(100) NOT NULL,
        `entity` VARCHAR(80) NOT NULL,
        `source_key` VARCHAR(191) NOT NULL,
        `local_id` BIGINT UNSIGNED NOT NULL,
        `created_run` BIGINT UNSIGNED NOT NULL DEFAULT 0,
        `last_run` BIGINT UNSIGNED NOT NULL DEFAULT 0,
        `hash` CHAR(32) NOT NULL DEFAULT '',
        `updated_at` INT NOT NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_source_entity_key` (`source`, `entity`, `source_key`),
        INDEX `idx_created_run` (`created_run`),
        INDEX `idx_local` (`entity`, `local_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    if (function_exists('update_option_meta')) update_option_meta('qp_transfer_schema', QP_TRANSFER_SCHEMA);
    $done = true;
}

// ---------------------------------------------------------------------------------------------------------------------
// Registries
// ---------------------------------------------------------------------------------------------------------------------

/** Entity definitions by id (core posts + whatever plugins add with the filter qp_transfer_entities). */
function qp_transfer_entities(bool $refresh = false): array {
    static $cache = null;
    if ($cache !== null && !$refresh) return $cache;
    $e = ['posts' => qp_transfer_entity_posts(), 'terms' => qp_transfer_entity_terms(), 'users' => qp_transfer_entity_users()];
    if (function_exists('apply_filters')) { $f = apply_filters('qp_transfer_entities', $e); if (is_array($f)) $e = $f; }
    $out = [];
    foreach ($e as $id => $def) {
        if (!is_string($id) || !preg_match('/^[a-z0-9_]{1,60}$/', $id) || !is_array($def) || empty($def['fields']) || !is_array($def['fields'])) continue;
        $def += ['label' => $id, 'key' => '', 'capability' => 'manage_options', 'fields' => []];
        foreach ($def['fields'] as $fid => &$fd) { $fd = (array)$fd + ['label' => $fid, 'type' => 'string', 'required' => false]; }
        unset($fd);
        $out[$id] = $def;
    }
    return $cache = $out;
}

function qp_transfer_entity(string $id): ?array { return qp_transfer_entities()[$id] ?? null; }

/** May the current user use this entity? */
function qp_transfer_can(string $entityId): bool {
    $e = qp_transfer_entity($entityId);
    return $e !== null && function_exists('current_user_can') && current_user_can((string)$e['capability']);
}

/** Sources by id. The file sources are built in; others come from the filter qp_transfer_sources (each: label, read callable($cursor, $limit, $plan)). */
function qp_transfer_sources(): array {
    $s = [
        'csv' => ['label' => 'CSV file', 'file' => true], 'json' => ['label' => 'JSON file', 'file' => true], 'ndjson' => ['label' => 'JSON lines file', 'file' => true],
    ];
    if (function_exists('apply_filters')) { $f = apply_filters('qp_transfer_sources', $s); if (is_array($f)) $s = $f; }
    return $s;
}

// ---------------------------------------------------------------------------------------------------------------------
// Files: the private folder, reading and writing CSV / JSON / JSON lines
// ---------------------------------------------------------------------------------------------------------------------

/** The folder for uploaded import files and export files. Random names, an index file and a deny rule; downloads go through the admin screen. */
function qp_transfer_dir(): string {
    $dir = rtrim(qp_uploads_base(), '/\\') . DIRECTORY_SEPARATOR . 'transfer-private';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
        @file_put_contents($dir . DIRECTORY_SEPARATOR . 'index.html', '');
        @file_put_contents($dir . DIRECTORY_SEPARATOR . '.htaccess', "Require all denied\nDeny from all\n");
    }
    return $dir;
}

function qp_transfer_file_path(string $name): ?string {
    if (!preg_match('/^[a-f0-9]{32}\.(csv|json|ndjson|part|tmp)$/', $name)) return null;
    return qp_transfer_dir() . DIRECTORY_SEPARATOR . $name;
}

/** Remove import and export files older than $maxAge seconds (default one day), and files no run points at. */
function qp_transfer_purge_files(int $maxAge = 86400): int {
    $n = 0; $dir = qp_transfer_dir();
    $used = [];
    foreach (db()->query('SELECT plan, output FROM ' . qp_transfer_runs_table() . " WHERE status IN ('queued','running','undoing')")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $p = json_decode((string)$r['plan'], true); if (!empty($p['file'])) $used[$p['file']] = true; if ($r['output'] !== '') $used[$r['output']] = true;
    }
    foreach ((array)glob($dir . DIRECTORY_SEPARATOR . '*') as $f) {
        $b = basename($f);
        if (!is_file($f) || in_array($b, ['index.html', '.htaccess'], true) || isset($used[$b])) continue;
        if (filemtime($f) < time() - $maxAge && @unlink($f)) $n++;
    }
    return $n;
}

/** Look at an uploaded file: format check, delimiter, columns and a few sample rows. Returns [ok, info or message]. */
function qp_transfer_file_inspect(string $path, string $format): array {
    if (!is_file($path)) return [false, 'The file is missing.'];
    $size = filesize($path);
    $head = (string)file_get_contents($path, false, null, 0, 65536);
    $info = ['size' => $size, 'encoding' => 'UTF-8', 'delimiter' => ',', 'columns' => [], 'sample' => [], 'rows' => null];
    if ($head !== '' && !mb_check_encoding($head, 'UTF-8')) $info['encoding'] = 'Windows-1252';
    $head = preg_replace('/^\xEF\xBB\xBF/', '', $head);
    if ($format === 'csv') {
        $line = strtok($head, "\r\n") ?: '';
        $best = ','; $bestN = -1;
        foreach ([',', ';', "\t", '|'] as $d) { $n = substr_count($line, $d); if ($n > $bestN) { $bestN = $n; $best = $d; } }
        $info['delimiter'] = $best;
        $fh = fopen($path, 'rb'); if (!$fh) return [false, 'The file could not be opened.'];
        if (fread($fh, 3) !== "\xEF\xBB\xBF") rewind($fh);
        $cols = fgetcsv($fh, 0, $best, '"', '');
        if (!is_array($cols) || !$cols || (count($cols) === 1 && trim((string)$cols[0]) === '')) { fclose($fh); return [false, 'The first line must name the columns.']; }
        $info['columns'] = qp_transfer_clean_columns($cols, $info['encoding']);
        $n = 0;
        while ($n < 5 && ($r = fgetcsv($fh, 0, $best, '"', '')) !== false) {
            if ($r === [null]) continue;
            $info['sample'][] = qp_transfer_combine($info['columns'], $r, $info['encoding']); $n++;
        }
        fclose($fh);
    } elseif ($format === 'ndjson') {
        $fh = fopen($path, 'rb'); if (!$fh) return [false, 'The file could not be opened.'];
        $cols = [];
        $n = 0;
        while ($n < 5 && ($l = fgets($fh)) !== false) {
            $l = trim(preg_replace('/^\xEF\xBB\xBF/', '', $l)); if ($l === '') continue;
            $o = json_decode($l, true);
            if (!is_array($o)) { fclose($fh); return [false, 'Every line must be one JSON object.']; }
            foreach (array_keys($o) as $k) if (!in_array((string)$k, $cols, true)) $cols[] = (string)$k;
            $info['sample'][] = $o; $n++;
        }
        fclose($fh);
        if (!$cols) return [false, 'No rows were found.'];
        $info['columns'] = $cols;
    } elseif ($format === 'json') {
        if ($size > 25 * 1048576) return [false, 'A JSON file can be at most 25 MB; use JSON lines (one object per line) for larger files.'];
        $list = qp_transfer_json_rows((string)file_get_contents($path));
        if ($list === null) return [false, 'The file must hold a list of objects (or an object with one list of objects).'];
        $cols = [];
        foreach (array_slice($list, 0, 50) as $o) foreach (array_keys($o) as $k) if (!in_array((string)$k, $cols, true)) $cols[] = (string)$k;
        if (!$cols) return [false, 'No rows were found.'];
        $info['columns'] = $cols; $info['sample'] = array_slice($list, 0, 5); $info['rows'] = count($list);
    } else {
        return [false, 'Unknown file format.'];
    }
    return [true, $info];
}

/** A JSON text holding a list of objects, or an object with exactly one such list inside. Null when it is neither. */
function qp_transfer_json_rows(string $text): ?array {
    $d = json_decode(preg_replace('/^\xEF\xBB\xBF/', '', $text), true);
    if (!is_array($d)) return null;
    if ($d !== [] && array_keys($d) !== range(0, count($d) - 1)) {
        $lists = array_values(array_filter($d, function ($v) { return is_array($v) && $v !== [] && array_keys($v) === range(0, count($v) - 1) && is_array($v[0]); }));
        if (count($lists) !== 1) return null;
        $d = $lists[0];
    }
    foreach ($d as $r) if (!is_array($r)) return null;
    return array_values($d);
}

function qp_transfer_clean_columns(array $cols, string $encoding): array {
    $out = []; $seen = [];
    foreach ($cols as $i => $c) {
        $c = trim((string)qp_transfer_utf8((string)$c, $encoding));
        if ($c === '') $c = 'column_' . ($i + 1);
        $base = $c; $n = 2;
        while (isset($seen[strtolower($c)])) $c = $base . '_' . $n++;
        $seen[strtolower($c)] = true; $out[] = $c;
    }
    return $out;
}

function qp_transfer_utf8(string $s, string $encoding): string {
    return $encoding === 'UTF-8' ? $s : (string)mb_convert_encoding($s, 'UTF-8', $encoding);
}

function qp_transfer_combine(array $columns, array $cells, string $encoding): array {
    $row = [];
    foreach ($columns as $i => $name) $row[$name] = array_key_exists($i, $cells) && $cells[$i] !== null ? qp_transfer_utf8((string)$cells[$i], $encoding) : '';
    return $row;
}

/**
 * Read the next rows of a file source. Cursor: a byte offset (csv, ndjson) or a row number (json), as a string.
 * Returns ['rows' => list of [row, cursor after this row], 'done' => bool].
 */
function qp_transfer_file_read(array $plan, string $cursor, int $limit): array {
    $path = qp_transfer_file_path((string)($plan['file'] ?? ''));
    if ($path === null || !is_file($path)) throw new RuntimeException('The import file is no longer there.');
    $format = (string)($plan['format'] ?? 'csv');
    $rows = [];
    if ($format === 'json') {
        static $cacheKey = null, $cacheRows = null;
        if ($cacheKey !== $path) { $cacheRows = qp_transfer_json_rows((string)file_get_contents($path)) ?? []; $cacheKey = $path; }
        $i = (int)$cursor;
        foreach (array_slice($cacheRows, $i, $limit) as $k => $r) $rows[] = [$r, (string)($i + $k + 1)];
        return ['rows' => $rows, 'done' => $i + count($rows) >= count($cacheRows)];
    }
    $fh = fopen($path, 'rb'); if (!$fh) throw new RuntimeException('The import file could not be opened.');
    $offset = (int)$cursor;
    if ($offset === 0) {
        if (fread($fh, 3) !== "\xEF\xBB\xBF") rewind($fh);
        if ($format === 'csv') { fgetcsv($fh, 0, (string)($plan['delimiter'] ?? ','), '"', ''); }   // the header line
    } else {
        fseek($fh, $offset);
    }
    $columns = (array)($plan['columns'] ?? []); $enc = (string)($plan['encoding'] ?? 'UTF-8'); $delim = (string)($plan['delimiter'] ?? ',');
    while (count($rows) < $limit) {
        if ($format === 'csv') {
            $r = fgetcsv($fh, 0, $delim, '"', '');
            if ($r === false) break;
            if ($r === [null]) { continue; }     // a blank line
            $rows[] = [qp_transfer_combine($columns, $r, $enc), (string)ftell($fh)];
        } else {
            $l = fgets($fh);
            if ($l === false) break;
            $l = trim($l); if ($l === '') continue;
            $o = json_decode($l, true);
            $rows[] = [is_array($o) ? $o : ['_invalid' => $l], (string)ftell($fh)];
        }
    }
    $done = feof($fh) || $rows === [] || (count($rows) < $limit);
    fclose($fh);
    return ['rows' => $rows, 'done' => $done];
}

/** Open an export file. Returns a writer state array; call qp_transfer_write_rows() then qp_transfer_write_close(). */
function qp_transfer_write_open(string $path, string $format, array $columns, bool $append): array {
    $fh = fopen($path, $append ? 'ab' : 'wb');
    if (!$fh) throw new RuntimeException('The export file could not be written.');
    $st = ['fh' => $fh, 'format' => $format, 'columns' => $columns, 'first' => !$append || filesize($path) === 0];
    if ($st['first'] && $format === 'csv') { fwrite($fh, "\xEF\xBB\xBF"); fputcsv($fh, $columns, ',', '"', ''); $st['first'] = false; }
    if ($st['first'] && $format === 'json') fwrite($fh, '[');
    return $st;
}

function qp_transfer_csv_safe(string $v): string {
    // A cell that starts with = + - @ is read as a formula by spreadsheet programs; a leading apostrophe-free guard: prefix a tab.
    return $v !== '' && strpos("=+-@\t\r", $v[0]) !== false && !preg_match('/^-?\d+([.,]\d+)?$/', $v) ? "\t" . $v : $v;
}

function qp_transfer_write_rows(array &$st, array $rows): void {
    foreach ($rows as $row) {
        if ($st['format'] === 'csv') {
            $cells = [];
            foreach ($st['columns'] as $c) { $v = $row[$c] ?? ''; $cells[] = qp_transfer_csv_safe(is_scalar($v) || $v === null ? (string)$v : json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)); }
            fputcsv($st['fh'], $cells, ',', '"', '');
        } elseif ($st['format'] === 'ndjson') {
            fwrite($st['fh'], json_encode(array_intersect_key($row, array_flip($st['columns'])), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
        } else {
            fwrite($st['fh'], ($st['first'] ? '' : ',') . "\n" . json_encode(array_intersect_key($row, array_flip($st['columns'])), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $st['first'] = false;
        }
    }
}

function qp_transfer_write_close(array $st, bool $final): void {
    if ($final && $st['format'] === 'json') fwrite($st['fh'], ($st['first'] ? '' : "\n") . ']');
    fclose($st['fh']);
}

// ---------------------------------------------------------------------------------------------------------------------
// Cleaning and typing a row
// ---------------------------------------------------------------------------------------------------------------------

/** Clean one value to its declared type. Returns [ok, value or message]. An empty value is [true, null]. */
function qp_transfer_cast($value, array $field): array {
    if (($field['type'] ?? '') === 'json') {
        if (is_array($value)) return [true, $value === [] ? null : $value];
        $t = trim((string)$value);
        if ($t === '') return [true, null];
        $d = json_decode($t, true);
        return is_array($d) ? [true, $d] : [false, 'not valid JSON'];
    }
    if (is_array($value)) $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $v = trim((string)$value);
    if ($v === '') return [true, null];
    $type = (string)($field['type'] ?? 'string');
    switch ($type) {
        case 'int':
            if (!preg_match('/^-?\d{1,18}$/', $v)) return [false, 'not a whole number'];
            return [true, (int)$v];
        case 'decimal':
            $c = preg_replace('/[\s\x{00A0}]|[^\d.,\-]/u', '', $v);
            if (preg_match('/^-?\d{1,3}(,\d{3})+(\.\d+)?$/', $c)) $c = str_replace(',', '', $c);
            elseif (preg_match('/^-?\d{1,3}(\.\d{3})+(,\d+)?$/', $c)) $c = str_replace(['.', ','], ['', '.'], $c);
            elseif (preg_match('/^-?\d+,\d+$/', $c)) $c = str_replace(',', '.', $c);
            if (!preg_match('/^-?\d+(\.\d+)?$/', $c)) return [false, 'not a number'];
            return [true, $c];     // kept as text so no precision is lost
        case 'bool':
            $l = strtolower($v);
            if (in_array($l, ['1', 'yes', 'y', 'true', 'on'], true)) return [true, true];
            if (in_array($l, ['0', 'no', 'n', 'false', 'off'], true)) return [true, false];
            return [false, 'not yes or no'];
        case 'date':
            $t = strtotime($v);
            if ($t === false) return [false, 'not a date'];
            return [true, gmdate('Y-m-d H:i:s', $t)];
        case 'url':
            if (!preg_match('~^https?://[^\s]+$~i', $v) || strlen($v) > 2000) return [false, 'not a web address'];
            return [true, $v];
        case 'list':
            return [true, array_values(array_filter(array_map('trim', preg_split('/[|,]/', $v)), 'strlen'))];
        case 'text':
            return [true, (string)$value];
        default:
            $max = (int)($field['max'] ?? 500);
            if (mb_strlen($v, 'UTF-8') > $max) return [false, 'longer than ' . $max . ' characters'];
            if (!empty($field['choices']) && !in_array($v, (array)$field['choices'], true) && !in_array(strtolower($v), array_map('strtolower', (array)$field['choices']), true)) return [false, 'not one of: ' . implode(', ', (array)$field['choices'])];
            return [true, !empty($field['choices']) ? strtolower($v) : $v];
    }
}

/** Apply the plan's mapping and constants to a source row. Returns [row or null, problem text]. */
function qp_transfer_map_row(array $entity, array $plan, array $raw): array {
    $map = (array)($plan['map'] ?? []); $fixed = (array)($plan['fixed'] ?? []);
    $row = [];
    foreach ($entity['fields'] as $fid => $fd) {
        if (isset($map[$fid]) && $map[$fid] !== '') $value = $raw[$map[$fid]] ?? null;
        elseif (array_key_exists($fid, $fixed) && $fixed[$fid] !== '') $value = $fixed[$fid];
        else $value = null;
        [$ok, $v] = qp_transfer_cast($value, $fd);
        if (!$ok) return [null, ($fd['label'] ?? $fid) . ' is ' . $v];
        if ($v === null) {
            if (!empty($fd['required'])) return [null, ($fd['label'] ?? $fid) . ' is required'];
            continue;
        }
        $row[$fid] = $v;
    }
    return [$row, ''];
}

// ---------------------------------------------------------------------------------------------------------------------
// The ID map
// ---------------------------------------------------------------------------------------------------------------------

function qp_transfer_map_get(string $source, string $entity, string $key): ?array {
    $st = db()->prepare('SELECT local_id, created_run, hash FROM ' . qp_transfer_map_table() . ' WHERE source = ? AND entity = ? AND source_key = ? LIMIT 1');
    $st->execute([$source, $entity, $key]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r ? ['local_id' => (int)$r['local_id'], 'created_run' => (int)$r['created_run'], 'hash' => (string)$r['hash']] : null;
}

/** The local id an old key stands for (for an entity that needs to point at another one). */
function qp_transfer_map_local(string $source, string $entity, string $key): ?int {
    $m = qp_transfer_map_get($source, $entity, $key);
    return $m ? $m['local_id'] : null;
}

function qp_transfer_map_put(string $source, string $entity, string $key, int $localId, int $runId, bool $created, string $hash = ''): void {
    $now = time();
    $st = db()->prepare('INSERT INTO ' . qp_transfer_map_table() . ' (source, entity, source_key, local_id, created_run, last_run, hash, updated_at) VALUES (?,?,?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE local_id = VALUES(local_id), last_run = VALUES(last_run), hash = VALUES(hash), updated_at = VALUES(updated_at)'
        . ($created ? ', created_run = VALUES(created_run)' : ''));
    $st->execute([substr($source, 0, 100), $entity, substr($key, 0, 191), $localId, $created ? $runId : 0, $runId, $hash, $now]);
}

// ---------------------------------------------------------------------------------------------------------------------
// Runs
// ---------------------------------------------------------------------------------------------------------------------

function qp_transfer_counts_blank(): array { return ['read' => 0, 'created' => 0, 'updated' => 0, 'same' => 0, 'skipped' => 0, 'rejected' => 0, 'exported' => 0, 'undone' => 0]; }

function qp_transfer_run_get(int $id): ?array {
    qp_transfer_install();
    $st = db()->prepare('SELECT * FROM ' . qp_transfer_runs_table() . ' WHERE id = ? LIMIT 1');
    $st->execute([$id]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) return null;
    $r['plan'] = json_decode((string)$r['plan'], true) ?: [];
    $r['counts'] = (json_decode((string)$r['counts'], true) ?: []) + qp_transfer_counts_blank();
    $r['problems'] = json_decode((string)$r['problems'], true) ?: [];
    foreach (['id', 'dry_run', 'created_by', 'lock_until', 'created_at', 'updated_at'] as $k) $r[$k] = (int)$r[$k];
    return $r;
}

function qp_transfer_runs_list(int $limit = 50): array {
    qp_transfer_install();
    $rows = db()->query('SELECT id FROM ' . qp_transfer_runs_table() . ' ORDER BY id DESC LIMIT ' . max(1, min(200, $limit)))->fetchAll(PDO::FETCH_COLUMN);
    $out = [];
    foreach ($rows as $id) $out[] = qp_transfer_run_get((int)$id);
    return array_values(array_filter($out));
}

/**
 * Start a run. $spec: entity, direction (import|export), source (a source id; export uses the file format csv|json|ndjson),
 * plan (see below), dry (bool), user (id). Returns [run id, null] or [null, message].
 * Import plan: file (a name inside the private folder) and format, map (entity field => source column), fixed (entity field => constant),
 *   mode (create_update | create_only | update_only), source_id (what the ID map calls the system the rows came from), limit (stop after n rows),
 *   force (rewrite rows that did not change).
 * Export plan: format (csv|json|ndjson), filters (the entity's own), limit.
 */
function qp_transfer_run_create(array $spec): array {
    qp_transfer_install();
    $entityId = (string)($spec['entity'] ?? '');
    $entity = qp_transfer_entity($entityId);
    if (!$entity) return [null, 'Unknown thing to move.'];
    $direction = (string)($spec['direction'] ?? '');
    if (!in_array($direction, ['import', 'export'], true)) return [null, 'Choose import or export.'];
    $plan = (array)($spec['plan'] ?? []);
    $source = (string)($spec['source'] ?? '');
    if ($direction === 'import') {
        $sources = qp_transfer_sources();
        if (!isset($sources[$source])) return [null, 'Unknown source.'];
        if (!isset($entity['import_row']) || !is_callable($entity['import_row'])) return [null, 'This thing cannot be imported.'];
        $plan['mode'] = in_array($plan['mode'] ?? '', ['create_update', 'create_only', 'update_only'], true) ? $plan['mode'] : 'create_update';
        $plan['map'] = array_intersect_key((array)($plan['map'] ?? []), $entity['fields']);
        $plan['fixed'] = array_intersect_key((array)($plan['fixed'] ?? []), $entity['fields']);
        $plan['source_id'] = preg_replace('/[^A-Za-z0-9_.:-]/', '', (string)($plan['source_id'] ?? 'file')) ?: 'file';
        foreach ($entity['fields'] as $fid => $fd) {
            if (!empty($fd['required']) && empty($plan['map'][$fid]) && ($plan['fixed'][$fid] ?? '') === '') return [null, ($fd['label'] ?? $fid) . ' needs a column (or a fixed value).'];
        }
        if (!empty($sources[$source]['file'])) {
            if (!qp_transfer_file_path((string)($plan['file'] ?? '')) || !is_file(qp_transfer_file_path($plan['file']))) return [null, 'The file is missing.'];
            $plan['format'] = $source;
        }
    } else {
        if (!isset($entity['export_read']) || !is_callable($entity['export_read'])) return [null, 'This thing cannot be exported.'];
        $source = in_array($plan['format'] ?? 'csv', ['csv', 'json', 'ndjson'], true) ? (string)($plan['format'] ?? 'csv') : 'csv';
        $plan['format'] = $source;
        $plan['filters'] = (array)($plan['filters'] ?? []);
        $name = bin2hex(random_bytes(16)) . '.' . $source;
        $plan['out'] = $name;
    }
    $plan['limit'] = max(0, (int)($plan['limit'] ?? 0));
    if ($direction === 'import') {
        // the entity's own choices (for example how to match an existing account): only its declared answers are kept
        $opts = [];
        foreach ((array)($entity['options'] ?? []) as $oid => $od) {
            $choices = array_keys((array)($od['choices'] ?? []));
            $given = (string)(((array)($plan['options'] ?? []))[$oid] ?? '');
            $opts[$oid] = in_array($given, $choices, true) ? $given : (string)($od['default'] ?? ($choices[0] ?? ''));
        }
        $plan['options'] = $opts;
    }
    $now = time();
    $st = db()->prepare('INSERT INTO ' . qp_transfer_runs_table() . ' (entity, direction, source, status, dry_run, plan, cursor_value, counts, problems, output, created_by, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)');
    $st->execute([$entityId, $direction, $source, 'queued', !empty($spec['dry']) ? 1 : 0, json_encode($plan), '0', json_encode(qp_transfer_counts_blank()), '[]', '', (int)($spec['user'] ?? 0), $now, $now]);
    $id = (int)db()->lastInsertId();
    qp_transfer_refresh_active();
    return [$id, null];
}

/**
 * A signed token for one run, one purpose and one user, good for today and tomorrow. Admin nonces are single use, which does not suit a page
 * that asks for progress many times or a download link that may be clicked twice. It proves only that the page came from a signed-in user.
 */
function qp_transfer_token(int $runId, string $purpose, int $userId, ?string $day = null): string {
    return substr(hash_hmac('sha256', 'qp_transfer|' . $purpose . '|' . $runId . '|' . $userId . '|' . ($day ?? gmdate('Ymd')), qp_nonce_security_salt()), 0, 40);
}

function qp_transfer_token_ok(string $token, int $runId, string $purpose, int $userId): bool {
    foreach ([gmdate('Ymd'), gmdate('Ymd', time() - 86400)] as $d) if (hash_equals(qp_transfer_token($runId, $purpose, $userId, $d), $token)) return true;
    return false;
}

/** Keep the "a run is waiting" flag right, so the queue task has its handler on the next request. */
function qp_transfer_refresh_active(): void {
    $n = (int)db()->query('SELECT COUNT(*) FROM ' . qp_transfer_runs_table() . " WHERE status IN ('queued','running','undoing')")->fetchColumn();
    if (function_exists('update_option_meta')) update_option_meta(QP_TRANSFER_ACTIVE_OPTION, $n > 0 ? 1 : 0);
    if ($n > 0) qp_transfer_wake(5);
}

/** Ask the C2 queue to carry on soon. One pending task for this hook, always (qp_queue_task refuses a second). */
function qp_transfer_wake(int $inSeconds = 3): void {
    if (!function_exists('qp_queue_task') && is_file(__DIR__ . '/qp-cron-queue.php')) require_once __DIR__ . '/qp-cron-queue.php';   // loaded on demand elsewhere
    if (!function_exists('qp_queue_task')) return;
    if (function_exists('qp_remove_tasks_by_hook')) qp_remove_tasks_by_hook(QP_TRANSFER_HOOK);
    qp_queue_task(QP_TRANSFER_HOOK, time() + max(1, $inSeconds), [], 3, 60, 0, true);
}

/** Tell a source that its run is over (done, failed, cancelled or deleted), so it can forget what it only needed for the run (a key, a session). */
function qp_transfer_source_finish(array $run): void {
    $sources = qp_transfer_sources();
    $s = $sources[$run['source']] ?? null;
    if ($s && !empty($s['finish']) && is_callable($s['finish'])) { try { call_user_func($s['finish'], $run); } catch (Throwable $e) { /* never let cleanup break the run */ } }
}

function qp_transfer_run_cancel(int $id): bool {
    $st = db()->prepare("UPDATE " . qp_transfer_runs_table() . " SET status = 'cancelled', updated_at = ?, finished_at = ? WHERE id = ? AND status IN ('queued','running')");
    $st->execute([time(), time(), $id]);
    if ($st->rowCount() > 0 && ($r = qp_transfer_run_get($id))) qp_transfer_source_finish($r);
    qp_transfer_refresh_active();
    return $st->rowCount() > 0;
}

/** Ask for a run's work to be taken back (rows it created are deleted). Only for a run that finished or was cancelled and was not a dry run. */
function qp_transfer_run_undo(int $id): array {
    $run = qp_transfer_run_get($id);
    if (!$run) return [false, 'No such run.'];
    if ($run['direction'] !== 'import' || $run['dry_run']) return [false, 'Only a real import can be undone.'];
    if (!in_array($run['status'], ['done', 'cancelled', 'failed'], true)) return [false, 'Wait for the run to finish (or cancel it) first.'];
    $entity = qp_transfer_entity($run['entity']);
    if (!$entity || empty($entity['delete']) || !is_callable($entity['delete'])) return [false, 'This thing has no way to delete what an import created, so the run cannot be undone.'];
    db()->prepare("UPDATE " . qp_transfer_runs_table() . " SET status = 'undoing', updated_at = ?, finished_at = NULL WHERE id = ?")->execute([time(), $id]);
    qp_transfer_refresh_active();
    return [true, ''];
}

function qp_transfer_run_delete(int $id): bool {
    $run = qp_transfer_run_get($id);
    if (!$run || in_array($run['status'], ['running', 'undoing'], true)) return false;
    foreach ([(string)($run['plan']['file'] ?? ''), (string)$run['output']] as $f) {
        $p = $f !== '' ? qp_transfer_file_path($f) : null;
        if ($p && is_file($p)) @unlink($p);
    }
    qp_transfer_seen_clear($id);
    qp_transfer_source_finish($run);
    db()->prepare('DELETE FROM ' . qp_transfer_runs_table() . ' WHERE id = ?')->execute([$id]);
    qp_transfer_refresh_active();
    return true;
}

function qp_transfer_save_run(int $id, array $fields): void {
    $fields['updated_at'] = time();
    $sets = []; $vals = [];
    foreach ($fields as $k => $v) { $sets[] = '`' . $k . '` = ?'; $vals[] = is_array($v) ? json_encode($v) : $v; }
    $vals[] = $id;
    db()->prepare('UPDATE ' . qp_transfer_runs_table() . ' SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($vals);
}

function qp_transfer_problem(array &$problems, int $number, string $reason, array $row = [], string $key = '', string $level = 'rejected'): void {
    if (count($problems) >= QP_TRANSFER_MAX_PROBLEMS) return;
    $peek = [];
    foreach (array_slice($row, 0, 3, true) as $k => $v) $peek[] = $k . '=' . mb_substr(is_scalar($v) ? (string)$v : json_encode($v), 0, 40);
    $problems[] = ['row' => $number, 'key' => $key, 'level' => $level, 'reason' => $reason, 'data' => implode('; ', $peek)];
}

/**
 * Do one batch of one run inside a time budget. Safe to call from the admin page and from the queue at the same time: a lock lets one in.
 * Returns ['state' => busy|running|done|failed|cancelled|undone|missing, 'run' => the run row (when found)].
 */
function qp_transfer_step(int $runId, int $budget = 15): array {
    qp_transfer_install();
    $pdo = db(); $now = time();
    $st = $pdo->prepare('UPDATE ' . qp_transfer_runs_table() . " SET lock_until = ?, status = IF(status = 'undoing', 'undoing', 'running'), updated_at = ? WHERE id = ? AND status IN ('queued','running','undoing') AND lock_until < ?");
    $st->execute([$now + $budget + 30, $now, $runId, $now]);
    if ($st->rowCount() === 0) {
        $r = qp_transfer_run_get($runId);
        return ['state' => $r ? (in_array($r['status'], ['queued', 'running', 'undoing'], true) ? 'busy' : $r['status']) : 'missing', 'run' => $r];
    }
    $run = qp_transfer_run_get($runId);
    $started = microtime(true);
    try {
        $entity = qp_transfer_entity($run['entity']);
        if (!$entity) throw new RuntimeException('The plugin that provides "' . $run['entity'] . '" is not active.');
        if ($run['status'] === 'undoing') $state = qp_transfer_step_undo($run, $entity, $started, $budget);
        elseif ($run['direction'] === 'export') $state = qp_transfer_step_export($run, $entity, $started, $budget);
        else $state = qp_transfer_step_import($run, $entity, $started, $budget);
    } catch (Throwable $e) {
        qp_transfer_save_run($runId, ['status' => 'failed', 'last_error' => mb_substr($e->getMessage(), 0, 500), 'lock_until' => 0, 'finished_at' => time()]);
        qp_transfer_source_finish($run);
        if (function_exists('qp_cron_log')) qp_cron_log(0, QP_TRANSFER_HOOK, 'error', 'run ' . $runId . ': ' . $e->getMessage());
        $state = 'failed';
    }
    qp_transfer_refresh_active();
    return ['state' => $state, 'run' => qp_transfer_run_get($runId)];
}

function qp_transfer_step_import(array $run, array $entity, float $started, int $budget): string {
    $plan = $run['plan']; $counts = $run['counts']; $problems = $run['problems']; $pdo = db();
    $sources = qp_transfer_sources();
    $cursor = (string)$run['cursor_value'];
    $limitRows = (int)$plan['limit'];
    $stepRows = (int)($plan['step_rows'] ?? 0);   // optional cap on rows per step (a plugin can pace itself; the tests use it to force several steps)
    $stepRead = 0;
    $done = false;
    $source = $sources[$run['source']] ?? null;
    if (!$source) throw new RuntimeException('The source "' . $run['source'] . '" is not available.');
    while (!$done && microtime(true) - $started < $budget) {
        $room = $limitRows > 0 ? $limitRows - $counts['read'] : 100;
        if ($room <= 0) { $done = true; break; }
        $batch = !empty($source['file']) ? qp_transfer_file_read($plan, $cursor, min(100, $room)) : call_user_func($source['read'], $cursor, min(100, $room), $plan);
        $rows = (array)($batch['rows'] ?? []);
        $interrupted = false;
        foreach ($rows as $i => $pair) {
            [$raw, $after] = $pair;
            $counts['read']++;
            if (is_array($raw) && isset($raw['_skip'])) {      // a source that knows a row cannot be imported says so; it is counted and listed, not sent to the entity
                [$result, $reason, $key] = ['skipped', (string)$raw['_skip'], (string)($raw['_key'] ?? '')];
                unset($raw['_skip'], $raw['_key']);
            } else {
                [$result, $reason, $key] = qp_transfer_process_row($run, $entity, (array)$raw, $counts['read']);
            }
            $counts[$result] = ($counts[$result] ?? 0) + 1;
            if ($reason !== '') qp_transfer_problem($problems, $counts['read'], $reason, (array)$raw, $key, in_array($result, ['rejected', 'skipped'], true) ? $result : 'note');
            $cursor = (string)$after;
            if ($i < count($rows) - 1 && microtime(true) - $started >= $budget) { $interrupted = true; break; }   // out of time: the rest is read again next batch
            if ($stepRows > 0 && ++$stepRead >= $stepRows) { if ($i < count($rows) - 1) $interrupted = true; break; }
        }
        if (!$interrupted && (!empty($batch['done']) || $rows === [])) $done = true;
        if ($stepRows > 0 && $stepRead >= $stepRows) break;
    }
    if ($limitRows > 0 && $counts['read'] >= $limitRows) $done = true;
    $save = ['cursor_value' => $cursor, 'counts' => $counts, 'problems' => $problems, 'lock_until' => 0];
    if ($done) { $save += ['status' => 'done', 'finished_at' => time()]; if ($run['dry_run']) qp_transfer_seen_clear($run['id']); qp_transfer_source_finish($run); }
    else $save['status'] = 'running';
    qp_transfer_save_run($run['id'], $save);
    return $done ? 'done' : 'running';
}

/** One row: map, check, ask the entity, record in the ID map. Returns [result, reason, key]. */
function qp_transfer_process_row(array $run, array $entity, array $raw, int $rowNo = 0): array {
    $plan = $run['plan']; $dry = (bool)$run['dry_run']; $pdo = db();
    [$row, $why] = qp_transfer_map_row($entity, $plan, $raw);
    if ($row === null) return ['rejected', $why, ''];
    $key = ($entity['key'] instanceof Closure || is_array($entity['key'])) ? (string)call_user_func($entity['key'], $row) : ($entity['key'] !== '' ? (string)($row[$entity['key']] ?? '') : '');
    $source = (string)$plan['source_id'];
    $mapped = $key !== '' ? qp_transfer_map_get($source, $run['entity'], $key) : null;
    $local = $mapped['local_id'] ?? null;
    if ($local === null && !empty($entity['find']) && is_callable($entity['find'])) {
        $f = call_user_func($entity['find'], $row, ['options' => (array)($plan['options'] ?? []), 'plan' => $plan]);
        if (is_array($f) && isset($f['reject'])) return ['rejected', (string)$f['reject'], $key];
        if ($f) $local = (int)$f;
    }
    $mode = $plan['mode'];
    if ($local !== null && $mode === 'create_only') return ['skipped', 'already exists', $key];
    if ($local === null && $mode === 'update_only') return ['skipped', 'does not exist yet', $key];
    $hash = md5(json_encode($row));
    if ($mapped && $mapped['hash'] === $hash && empty($plan['force'])) return ['same', '', $key];
    // A dry run rolls every row back, so a later row cannot see an earlier one. These remember what earlier rows of THIS dry run would have
    // written (keys and whatever an entity asks to remember with $ctx['seen']), so a duplicate inside the file is reported as it will happen.
    $pending = [];
    $keySeen = $dry && $key !== '' && qp_transfer_seen_has($run['id'], 'key:' . $run['entity'], $key);
    $ctx = [
        'dry' => $dry, 'local_id' => $local, 'mode' => $mode, 'run_id' => $run['id'], 'source' => $source, 'plan' => $plan, 'user' => $run['created_by'], 'options' => (array)($plan['options'] ?? []),
        'media' => function ($url, array $args = []) use ($dry, $run, $plan) { return qp_transfer_fetch_media((string)$url, $args + ['dry' => $dry, 'run_id' => $run['id'], 'user' => $run['created_by']] + (!empty($plan['local_media']) ? ['allow_private' => true, 'verify_tls' => false] : [])); },
        'seen' => function (string $ns, string $k) use (&$pending, $run, $dry): bool {
            if (!$dry) return false;     // a real run sees the real rows
            $id = $ns . "\0" . $k;
            if (isset($pending[$id]) || qp_transfer_seen_has($run['id'], $ns, $k)) return true;
            $pending[$id] = [$ns, $k];
            return false;
        },
    ];
    $own = !$pdo->inTransaction();
    if ($own) $pdo->beginTransaction();
    try {
        $res = call_user_func($entity['import_row'], $row, $ctx);
        $result = (string)($res['result'] ?? 'rejected');
        if (!in_array($result, ['created', 'updated', 'same', 'skipped', 'rejected'], true)) { $result = 'rejected'; $res['reason'] = 'The importer gave no valid answer.'; }
        if (!$dry && in_array($result, ['created', 'updated', 'same'], true) && !empty($res['id'])) {
            if ($key !== '') {
                qp_transfer_map_put($source, $run['entity'], $key, (int)$res['id'], $run['id'], $result === 'created' && !$mapped, $hash);
            } elseif ($result === 'created') {
                // a row with no natural key still gets a map row, so "undo this run" can find what it created
                qp_transfer_map_put('run' . $run['id'], $run['entity'], 'row' . $rowNo, (int)$res['id'], $run['id'], true, $hash);
            }
        }
        if ($own) { if ($dry || $result === 'rejected') $pdo->rollBack(); else $pdo->commit(); }
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) $pdo->rollBack();
        return ['rejected', $e->getMessage(), $key];
    }
    if ($dry && in_array($result, ['created', 'updated', 'same'], true)) {
        if ($keySeen && $result === 'created') $result = 'updated';       // the same key twice in one file: the second row updates the first
        if ($key !== '') qp_transfer_seen_add($run['id'], 'key:' . $run['entity'], $key);
        foreach ($pending as [$ns, $k]) qp_transfer_seen_add($run['id'], $ns, $k);
    }
    return [$result, (string)($res['reason'] ?? ''), $key];
}

/** What earlier rows of this dry run would have written (kept outside the rolled-back row transaction; removed when the run finishes). */
function qp_transfer_seen_has(int $runId, string $ns, string $key): bool {
    $st = db()->prepare('SELECT 1 FROM ' . qp_transfer_map_table() . ' WHERE source = ? AND entity = ? AND source_key = ? LIMIT 1');
    $st->execute(['dry:' . $runId, substr('seen:' . $ns, 0, 80), substr($key, 0, 191)]);
    return $st->fetchColumn() !== false;
}

function qp_transfer_seen_add(int $runId, string $ns, string $key): void {
    db()->prepare('INSERT IGNORE INTO ' . qp_transfer_map_table() . " (source, entity, source_key, local_id, created_run, last_run, hash, updated_at) VALUES (?, ?, ?, 0, 0, ?, '', ?)")
        ->execute(['dry:' . $runId, substr('seen:' . $ns, 0, 80), substr($key, 0, 191), $runId, time()]);
}

function qp_transfer_seen_clear(int $runId): void {
    db()->prepare('DELETE FROM ' . qp_transfer_map_table() . ' WHERE source = ?')->execute(['dry:' . $runId]);
}

function qp_transfer_step_export(array $run, array $entity, float $started, int $budget): string {
    $plan = $run['plan']; $counts = $run['counts'];
    $path = qp_transfer_file_path((string)$plan['out']);
    if ($path === null) throw new RuntimeException('The export file name is not valid.');
    $columns = array_keys($entity['fields']);
    if (!empty($entity['export_columns']) && is_array($entity['export_columns'])) $columns = array_values($entity['export_columns']);
    $cursor = (string)$run['cursor_value']; $limitRows = (int)$plan['limit'];
    $writer = qp_transfer_write_open($path, (string)$plan['format'], $columns, $cursor !== '0' || $counts['exported'] > 0);
    $done = false;
    while (!$done && microtime(true) - $started < $budget) {
        $room = $limitRows > 0 ? $limitRows - $counts['exported'] : 200;
        if ($room <= 0) { $done = true; break; }
        $batch = call_user_func($entity['export_read'], $cursor, min(200, $room), (array)$plan['filters']);
        $rows = (array)($batch['rows'] ?? []);
        qp_transfer_write_rows($writer, $rows);
        $counts['exported'] += count($rows); $counts['read'] += count($rows);
        $cursor = (string)($batch['next'] ?? $cursor);
        if (!empty($batch['done']) || $rows === []) $done = true;
    }
    if ($limitRows > 0 && $counts['exported'] >= $limitRows) $done = true;
    qp_transfer_write_close($writer, $done);
    $save = ['cursor_value' => $cursor, 'counts' => $counts, 'lock_until' => 0, 'output' => $plan['out']];
    if ($done) $save += ['status' => 'done', 'finished_at' => time()]; else $save['status'] = 'running';
    qp_transfer_save_run($run['id'], $save);
    return $done ? 'done' : 'running';
}

function qp_transfer_step_undo(array $run, array $entity, float $started, int $budget): string {
    $counts = $run['counts']; $pdo = db(); $map = qp_transfer_map_table();
    while (microtime(true) - $started < $budget) {
        $st = $pdo->prepare("SELECT id, entity, local_id FROM {$map} WHERE created_run = ? ORDER BY id DESC LIMIT 50");
        $st->execute([$run['id']]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) {
            qp_transfer_save_run($run['id'], ['status' => 'undone', 'counts' => $counts, 'lock_until' => 0, 'finished_at' => time()]);
            return 'undone';
        }
        foreach ($rows as $r) {
            $e = $r['entity'] === 'attachment' ? ['delete' => function ($id) { return function_exists('qp_delete_attachment') ? qp_delete_attachment((int)$id) : false; }] : qp_transfer_entity($r['entity']);
            if ($e && !empty($e['delete']) && is_callable($e['delete'])) { try { call_user_func($e['delete'], (int)$r['local_id']); } catch (Throwable $t) { /* a row that cannot be deleted is left; the map row goes either way */ } }
            $pdo->prepare("DELETE FROM {$map} WHERE id = ?")->execute([$r['id']]);
            $counts['undone']++;
        }
        qp_transfer_save_run($run['id'], ['counts' => $counts]);
    }
    qp_transfer_save_run($run['id'], ['counts' => $counts, 'lock_until' => 0]);
    return 'running';
}

/** The queue task: carry the next waiting run on for one batch; ask to be called again while any run is left. */
function qp_transfer_cron_step($args = []): string {
    qp_transfer_install();
    $id = (int)db()->query('SELECT id FROM ' . qp_transfer_runs_table() . " WHERE status IN ('queued','running','undoing') AND lock_until < " . time() . ' ORDER BY id ASC LIMIT 1')->fetchColumn();
    if ($id) qp_transfer_step($id, 20); else qp_transfer_purge_files();
    qp_transfer_refresh_active();    // wakes the queue again when anything is left
    return $id ? 'run ' . $id : 'nothing to do';
}

if (function_exists('add_qp_cron_action')) add_qp_cron_action(QP_TRANSFER_HOOK, 'qp_transfer_cron_step');
if (function_exists('add_filter')) add_filter('qp_cron_heavy_hooks', function ($h) { $h[] = QP_TRANSFER_HOOK; return $h; });

// ---------------------------------------------------------------------------------------------------------------------
// Media: download an image and store it as an attachment with the right relative path
// ---------------------------------------------------------------------------------------------------------------------

/**
 * Download one image and make it an attachment (qp_handle_upload does the storing: the path kept in the database is relative to the
 * configured uploads folder, and every image size is generated). The same address is downloaded once: the ID map remembers it.
 * $args: dry (check only, download nothing), run_id (what to tag it with, so an undo removes it), user (author id), max_bytes,
 *        schemes (default https and http), allow_private (a local development source), name (a file name to use).
 * Returns [true, ['id' => attachment id, 'path' => relative path, 'url' => full address, 'reused' => bool]] or [false, message].
 */
function qp_transfer_fetch_media(string $url, array $args = []): array {
    qp_transfer_install();
    $url = trim($url);
    $parts = parse_url($url);
    if (!$parts || empty($parts['host']) || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)) return [false, 'Not a web address.'];
    $key = sha1($url);
    $known = qp_transfer_map_get('media', 'attachment', $key);
    if ($known && function_exists('qp_get_attachment_metadata') && qp_get_attachment_metadata($known['local_id'])) {
        $m = qp_get_attachment_metadata($known['local_id']);
        return [true, ['id' => $known['local_id'], 'path' => (string)($m['file']['url'] ?? ''), 'url' => (string)(qp_get_attachment_url($known['local_id']) ?? ''), 'reused' => true]];
    }
    if (!empty($args['dry'])) return [true, ['id' => 0, 'path' => '', 'url' => $url, 'reused' => false, 'dry' => true]];
    $tmp = qp_transfer_dir() . DIRECTORY_SEPARATOR . bin2hex(random_bytes(16)) . '.tmp';
    [$ok, $info] = qp_http_download($url, $tmp, [
        'schemes' => $args['schemes'] ?? ['https', 'http'], 'allow_private' => !empty($args['allow_private']), 'verify_tls' => ($args['verify_tls'] ?? true) !== false,
        'max_bytes' => (int)($args['max_bytes'] ?? 15 * 1048576), 'headers' => ['Accept: image/*,*/*;q=0.5'],
    ]);
    if (!$ok) return [false, (string)$info];
    $name = (string)($args['name'] ?? '');
    if ($name === '') $name = basename((string)($parts['path'] ?? '')) ?: 'image';
    $name = preg_replace('/[^A-Za-z0-9._-]+/', '-', rawurldecode($name));
    $exts = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp', 'image/avif' => 'avif', 'image/svg+xml' => 'svg'];
    if (!preg_match('/\.[A-Za-z0-9]{2,5}$/', $name) && isset($exts[$info['content_type']])) $name .= '.' . $exts[$info['content_type']];
    $author = (int)($args['user'] ?? 0);      // 0 when a run has no user: the attachment's author column does not take NULL
    [$uok, $res] = qp_handle_upload(['name' => $name, 'tmp_name' => $tmp, 'size' => (int)$info['bytes'], 'type' => $info['content_type']], $author, ['allow_local' => true]);
    if (!$uok) { @unlink($tmp); return [false, is_string($res) ? $res : 'The file was not accepted.']; }
    $id = (int)$res['post_id'];
    qp_transfer_map_put('media', 'attachment', $key, $id, (int)($args['run_id'] ?? 0), true);
    $m = function_exists('qp_get_attachment_metadata') ? qp_get_attachment_metadata($id) : null;
    return [true, ['id' => $id, 'path' => (string)($m['file']['url'] ?? ''), 'url' => (string)($res['url'] ?? ''), 'reused' => false]];
}

// ---------------------------------------------------------------------------------------------------------------------
// Terms (categories and tags): small helpers any entity can use, and a core entity
// ---------------------------------------------------------------------------------------------------------------------

/**
 * Find a term by name in a taxonomy (case-insensitive, any parent: the core keeps a term name unique within its taxonomy) or create it.
 * Returns [term id, created?]. With $dry nothing is written and the id is 0 for a term that would be created.
 */
function qp_transfer_term_ensure(string $taxonomy, string $name, int $parentId = 0, bool $dry = false): array {
    $pdo = db(); $t = table_name('taxonomy_terms');
    $name = trim(preg_replace('/\s+/', ' ', $name));
    if ($name === '') return [0, false];
    $st = $pdo->prepare("SELECT id FROM {$t} WHERE taxonomy = ? AND term = ? LIMIT 1");
    $st->execute([$taxonomy, $name]);
    $id = $st->fetchColumn();
    if ($id) return [(int)$id, false];
    if ($dry) return [0, true];
    $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9]+/', '-', $name), '-')) ?: 'term';
    $base = $slug; $n = 2;
    $chk = $pdo->prepare("SELECT 1 FROM {$t} WHERE taxonomy = ? AND slug = ? LIMIT 1");
    while (true) { $chk->execute([$taxonomy, $slug]); if (!$chk->fetchColumn()) break; $slug = $base . '-' . $n++; }
    $pdo->prepare("INSERT INTO {$t} (taxonomy, term, slug, parent_id, term_order) VALUES (?, ?, ?, ?, 0)")->execute([$taxonomy, mb_substr($name, 0, 100), mb_substr($slug, 0, 100), $parentId > 0 ? $parentId : null]);
    return [(int)$pdo->lastInsertId(), true];
}

/** "Food > Fruits & Vegetables > Fresh Fruits" becomes three nested terms; returns the id of the last one (and the ids made on the way). */
function qp_transfer_term_path(string $taxonomy, string $path, bool $dry = false): array {
    $parent = 0; $made = [];
    foreach (array_filter(array_map('trim', explode('>', $path)), 'strlen') as $part) {
        [$id, $created] = qp_transfer_term_ensure($taxonomy, $part, $parent, $dry);
        if ($created && $id) $made[] = $id;
        $parent = $id;
        if ($dry && !$id) break;
    }
    return [$parent, $made];
}

/** Replace the terms a post has in one taxonomy. */
function qp_transfer_set_terms(int $postId, string $taxonomy, array $termIds): void {
    $pdo = db(); $pt = table_name('post_terms'); $tt = table_name('taxonomy_terms');
    $pdo->prepare("DELETE pt FROM {$pt} pt JOIN {$tt} t ON t.id = pt.term_id WHERE pt.post_id = ? AND t.taxonomy = ?")->execute([$postId, $taxonomy]);
    $ins = $pdo->prepare("INSERT IGNORE INTO {$pt} (post_id, term_id) VALUES (?, ?)");
    foreach (array_unique(array_filter(array_map('intval', $termIds))) as $tid) $ins->execute([$postId, $tid]);
}

/** A list of category paths from a cell: "A > B | C" or a JSON list. Returns a list of path strings. */
function qp_transfer_parse_paths($value): array {
    if (is_array($value)) $list = $value;
    else {
        $t = trim((string)$value);
        if ($t !== '' && $t[0] === '[' && is_array($d = json_decode($t, true))) $list = $d;
        else $list = explode('|', $t);
    }
    return array_values(array_filter(array_map(function ($p) { return trim((string)(is_array($p) ? ($p['name'] ?? '') : $p)); }, $list), 'strlen'));
}

function qp_transfer_entity_terms(): array {
    $taxes = [];
    try { $taxes = db()->query('SELECT DISTINCT taxonomy FROM ' . table_name('taxonomy_terms') . ' ORDER BY taxonomy LIMIT 50')->fetchAll(PDO::FETCH_COLUMN); } catch (Throwable $e) { $taxes = []; }
    return [
        'label' => 'Categories and tags',
        'fields' => [
            'name' => ['label' => 'Name', 'type' => 'string', 'required' => true, 'max' => 100],
            'taxonomy' => ['label' => 'Kind (taxonomy, for example category)', 'type' => 'string', 'required' => true, 'max' => 50],
            'slug' => ['label' => 'Slug', 'type' => 'string', 'max' => 100],
            'parent' => ['label' => 'Parent name', 'type' => 'string', 'max' => 100],
        ],
        'key' => function (array $row) { return ($row['taxonomy'] ?? '') . ':' . ($row['name'] ?? ''); },
        'find' => function (array $row) {
            $st = db()->prepare('SELECT id FROM ' . table_name('taxonomy_terms') . ' WHERE taxonomy = ? AND term = ? LIMIT 1');
            $st->execute([$row['taxonomy'], $row['name']]);
            $id = $st->fetchColumn();
            return $id ? (int)$id : null;
        },
        'import_row' => function (array $row, array $ctx) {
            if ($ctx['local_id'] !== null) return ['result' => 'same', 'id' => (int)$ctx['local_id']];
            $parent = 0;
            if (!empty($row['parent'])) { [$parent] = qp_transfer_term_ensure($row['taxonomy'], $row['parent'], 0, $ctx['dry']); }
            if ($ctx['dry']) return ['result' => 'created', 'id' => 0];
            [$id] = qp_transfer_term_ensure($row['taxonomy'], $row['name'], $parent, false);
            return ['result' => 'created', 'id' => $id];
        },
        'delete' => function (int $id) {
            $pdo = db();
            $pdo->prepare('DELETE FROM ' . table_name('post_terms') . ' WHERE term_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM ' . table_name('term_meta') . ' WHERE term_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM ' . table_name('taxonomy_terms') . ' WHERE id = ?')->execute([$id]);
            return true;
        },
        'export_columns' => ['id', 'taxonomy', 'name', 'slug', 'parent'],
        'export_filters' => ['taxonomy' => ['label' => 'Kind', 'options' => array_combine($taxes, $taxes) ?: []]],
        'export_read' => function ($cursor, int $limit, array $filters) {
            $t = table_name('taxonomy_terms');
            $where = ['t.id > ?']; $vals = [(int)$cursor];
            if (!empty($filters['taxonomy'])) { $where[] = 't.taxonomy = ?'; $vals[] = (string)$filters['taxonomy']; }
            $st = db()->prepare("SELECT t.id, t.taxonomy, t.term AS name, t.slug, p.term AS parent FROM {$t} t LEFT JOIN {$t} p ON p.id = t.parent_id WHERE " . implode(' AND ', $where) . ' ORDER BY t.id ASC LIMIT ' . (int)$limit);
            $st->execute($vals);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            return ['rows' => $rows, 'next' => $rows ? (string)end($rows)['id'] : (string)$cursor, 'done' => count($rows) < $limit];
        },
    ];
}

// ---------------------------------------------------------------------------------------------------------------------
// The core entity: posts (title, slug, content, type, status, date, featured image)
// ---------------------------------------------------------------------------------------------------------------------

function qp_transfer_entity_posts(): array {
    $types = function_exists('get_post_types') ? array_keys((array)get_post_types()) : ['post', 'page'];
    $types = array_values(array_diff($types, ['attachment']));
    return [
        'label' => 'Posts and pages',
        'fields' => [
            'title' => ['label' => 'Title', 'type' => 'string', 'required' => true, 'max' => 255],
            'slug' => ['label' => 'Slug (the address part)', 'type' => 'string', 'max' => 200],
            'content' => ['label' => 'Content', 'type' => 'text'],
            'post_type' => ['label' => 'Type', 'type' => 'string', 'max' => 50],
            'status' => ['label' => 'Status', 'type' => 'string', 'choices' => ['draft', 'published', 'pending_review', 'scheduled']],
            'published_at' => ['label' => 'Publish date', 'type' => 'date'],
            'featured_image' => ['label' => 'Featured image (web address)', 'type' => 'url'],
        ],
        'key' => 'slug',
        'capability' => 'manage_options',
        'find' => function (array $row) {
            if (empty($row['slug'])) return null;
            $st = db()->prepare('SELECT id FROM ' . table_name('posts') . ' WHERE slug = ? AND post_type = ? LIMIT 1');
            $st->execute([$row['slug'], $row['post_type'] ?? 'post']);
            $id = $st->fetchColumn();
            return $id ? (int)$id : null;
        },
        'import_row' => function (array $row, array $ctx) use ($types) {
            $pdo = db(); $posts = table_name('posts');
            $type = (string)($row['post_type'] ?? 'post');
            if (!in_array($type, $types, true)) return ['result' => 'rejected', 'reason' => 'Unknown type "' . $type . '"'];
            $status = (string)($row['status'] ?? 'draft');
            $slug = (string)($row['slug'] ?? '');
            $slug = trim(strtolower(preg_replace('/[^A-Za-z0-9]+/', '-', $slug !== '' ? $slug : (string)$row['title'])), '-');
            if ($slug === '') $slug = 'item';
            $local = $ctx['local_id'];
            if ($local === null) {
                // a free slug: the posts table keeps every slug unique
                $base = $slug; $n = 2;
                $chk = $pdo->prepare("SELECT 1 FROM {$posts} WHERE slug = ? LIMIT 1");
                while (true) { $chk->execute([$slug]); if (!$chk->fetchColumn()) break; $slug = $base . '-' . $n++; }
                $published = $row['published_at'] ?? ($status === 'published' ? gmdate('Y-m-d H:i:s') : null);
                if ($ctx['dry']) return ['result' => 'created', 'id' => 0];
                $pdo->prepare("INSERT INTO {$posts} (post_type, title, slug, content, status, author_id, published_at) VALUES (?,?,?,?,?,?,?)")
                    ->execute([$type, $row['title'], $slug, (string)($row['content'] ?? ''), $status, (int)$ctx['user'], $published]);
                $id = (int)$pdo->lastInsertId();
                $result = 'created';
            } else {
                $set = ['title = ?']; $vals = [$row['title']];
                foreach (['content', 'status', 'published_at'] as $f) if (array_key_exists($f, $row)) { $set[] = "{$f} = ?"; $vals[] = $row[$f]; }
                if (array_key_exists('post_type', $row)) { $set[] = 'post_type = ?'; $vals[] = $type; }
                $vals[] = $local;
                if (!$ctx['dry']) $pdo->prepare("UPDATE {$posts} SET " . implode(', ', $set) . ' WHERE id = ?')->execute($vals);
                $id = (int)$local; $result = 'updated';
            }
            if (!empty($row['featured_image'])) {
                [$mok, $m] = $ctx['media']($row['featured_image']);
                if ($mok && !$ctx['dry'] && !empty($m['id']) && function_exists('set_post_thumbnail')) set_post_thumbnail($id, (int)$m['id']);
                elseif (!$mok) return ['result' => $result, 'id' => $id, 'reason' => 'saved, but the image failed: ' . $m];
            }
            if (function_exists('do_action') && !$ctx['dry']) do_action('qp_transfer_post_saved', $id, $row, $ctx);
            return ['result' => $result, 'id' => $id];
        },
        'delete' => function (int $id) {
            $pdo = db();
            $pdo->prepare('DELETE FROM ' . table_name('post_meta') . ' WHERE post_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM ' . table_name('posts') . ' WHERE id = ?')->execute([$id]);
            return true;
        },
        'export_filters' => [
            'post_type' => ['label' => 'Type', 'options' => array_combine($types, $types)],
            'status' => ['label' => 'Status', 'options' => ['draft' => 'draft', 'published' => 'published', 'pending_review' => 'pending review', 'scheduled' => 'scheduled', 'trash' => 'trash']],
        ],
        'export_columns' => ['id', 'title', 'slug', 'post_type', 'status', 'published_at', 'content'],
        'export_read' => function ($cursor, int $limit, array $filters) {
            $where = ['id > ?']; $vals = [(int)$cursor];
            if (!empty($filters['post_type'])) { $where[] = 'post_type = ?'; $vals[] = (string)$filters['post_type']; } else { $where[] = "post_type <> 'attachment'"; }
            if (!empty($filters['status'])) { $where[] = 'status = ?'; $vals[] = (string)$filters['status']; }
            $st = db()->prepare('SELECT id, title, slug, post_type, status, published_at, content FROM ' . table_name('posts') . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY id ASC LIMIT ' . (int)$limit);
            $st->execute($vals);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            return ['rows' => $rows, 'next' => $rows ? (string)end($rows)['id'] : (string)$cursor, 'done' => count($rows) < $limit];
        },
    ];
}
