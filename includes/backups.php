<?php
if (!defined('QLOPY_INIT')) { http_response_code(403); exit; }
// includes/backups.php
// Backups of the updater and the owner (core upgrade plan C17). Loaded by includes/updater.php.
//
// Where: uploads/updates/backups/<YYYYmmdd_His>_<32 random hex>/. Shared hosting has no folder outside the web root, so the folders
// stay public; their names are made with random_bytes() and cannot be guessed, the folder has an empty index.html, and uploads/updates
// gets a .htaccess that denies every request (Apache, LiteSpeed). nginx ignores .htaccess: there the names are the protection.
//
// Kinds (metadata.json "kind"):
//   update     made by the updater before an update: only the files the update replaces (files/<path>), the list of files it adds
//              (so a restore can remove them), and the database (db-backup.sql.gz) when "Back up the database before every update"
//              is on or the package migrates the database.
//   database   a database dump on its own (before a packaged migration, or by the old qlopy_db_backup() callers).
//   selection  made before selected SQL statements are run from the updater screen.
//   manual     "Back up the whole site now": every file of the site except uploads/updates (the backups themselves), and the
//              database unless left out. Never deleted automatically.
// Automatic kinds (update, database, selection) are kept to the newest N (Updater Settings, default 3). Folders from before C17
// (no "kind", names like 20261108_091803_6af03e4b74387) are renamed once and counted as automatic.
//
// Public API: qlopy_backup_new($kind, $meta), qlopy_backup_meta($dir), qlopy_backup_save_meta($dir, $meta),
// qlopy_backup_kind($dir), qlopy_backup_size($dir), qlopy_backup_copy_changed(...), qlopy_backup_full_run($dir),
// qlopy_backup_restore($dir, $withDb), qlopy_db_dump($file), qlopy_db_restore_file($file), qlopy_backups_prune($keep),
// qlopy_backups_rename_legacy(), qlopy_backups_protect(), qlopy_backups_tmp_cleanup($maxAge).

const QLOPY_BACKUP_KINDS_AUTO = ['update', 'database', 'selection'];
const QLOPY_BACKUP_DB_END = '-- qp:end';   // the line after every statement of a qlopy_db_dump() file

/** A new backup folder name: the date for sorting, then 32 random hex characters. */
function qlopy_backup_new_name(?int $time = null): string {
    return date('Ymd_His', $time ?? time()) . '_' . bin2hex(random_bytes(16));
}

/** Is this a C17 name (date + 32 hex)? */
function qlopy_backup_name_ok(string $name): bool {
    return (bool)preg_match('/^\d{8}_\d{6}_[a-f0-9]{32}$/', $name);
}

/**
 * index.html in each updates folder, and a .htaccess that denies everything in uploads/updates (downloads are served by PHP with a
 * token, never from the folder). Cheap: checks for the files and writes only what is missing.
 */
function qlopy_backups_protect(): void {
    $root = qlopy_updates_root();
    $ht = $root . DIRECTORY_SEPARATOR . '.htaccess';
    if (!is_file($ht)) {
        @file_put_contents($ht, "# Updater files and backups: never served directly.\n<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n  Deny from all\n</IfModule>\n");
    }
    foreach ([$root, qlopy_updates_backups_dir(), qlopy_updates_tmp_dir()] as $d) {
        if (is_dir($d) && !is_file($d . DIRECTORY_SEPARATOR . 'index.html')) @file_put_contents($d . DIRECTORY_SEPARATOR . 'index.html', '');
    }
}

/** Create a backup folder of a kind, with its metadata. Returns the folder path, or null when it cannot be made. */
function qlopy_backup_new(string $kind, array $meta = []): ?string {
    qlopy_backups_protect();
    $dir = qlopy_updates_backups_dir() . DIRECTORY_SEPARATOR . qlopy_backup_new_name();
    if (!@mkdir($dir, 0755, true) && !is_dir($dir)) return null;
    $meta = ['kind' => $kind] + $meta + ['created_at' => date('c'), 'status' => 'done'];   // the caller's status and time win; kind always ours
    $meta['actor'] = $meta['actor'] ?? (function_exists('qp_current_user_id') ? 'user:' . (int)qp_current_user_id() : 'system');
    qlopy_backup_save_meta($dir, $meta);
    return $dir;
}

function qlopy_backup_meta(string $dir): array {
    $f = $dir . DIRECTORY_SEPARATOR . 'metadata.json';
    $m = is_file($f) ? json_decode((string)@file_get_contents($f), true) : null;
    return is_array($m) ? $m : [];
}

function qlopy_backup_save_meta(string $dir, array $meta): void {
    @file_put_contents($dir . DIRECTORY_SEPARATOR . 'metadata.json', json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
}

/** update | database | selection | manual. Older folders: from their metadata or name. */
function qlopy_backup_kind(string $dir): string {
    $m = qlopy_backup_meta($dir);
    if (!empty($m['kind'])) return (string)$m['kind'];
    if (!empty($m['type'])) return 'update';
    if (($m['action'] ?? '') === 'apply_selected_migration' || strpos(basename($dir), '_sel_') !== false) return 'selection';
    return 'database';   // an old qlopy_db_backup() folder (db_ in its name) or anything unknown: automatic, so it can be pruned
}

/** The database file of a backup (gzip or the older plain SQL), or null. */
function qlopy_backup_db_file(string $dir): ?string {
    foreach (['db-backup.sql.gz', 'db-backup.sql'] as $n) if (is_file($dir . DIRECTORY_SEPARATOR . $n)) return $dir . DIRECTORY_SEPARATOR . $n;
    return null;
}

/** Bytes used by a backup. Stored in its metadata once known (a full backup can hold many thousands of files). */
function qlopy_backup_size(string $dir, bool $recount = false): int {
    $m = qlopy_backup_meta($dir);
    if (!$recount && isset($m['size']) && ($m['status'] ?? 'done') === 'done') return (int)$m['size'];
    $size = 0;
    try {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($it as $f) if ($f->isFile()) $size += (int)$f->getSize();
    } catch (Throwable $_) {}
    if (($m['status'] ?? 'done') === 'done') { $m['size'] = $size; qlopy_backup_save_meta($dir, $m); }
    return $size;
}

/** Remove a folder and everything in it. */
function qlopy_backup_rmdir(string $dir): bool {
    if (!is_dir($dir)) return true;
    try {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) { if ($f->isDir() && !$f->isLink()) @rmdir($f->getPathname()); else @unlink($f->getPathname()); }
    } catch (Throwable $_) {}
    return @rmdir($dir);
}

/** Is $rel (a path inside the package, with / separators) skipped by the update's whitelist? Same prefix rule as qlopy_recursive_apply(). */
function qlopy_backup_skipped(string $rel, array $skip): bool {
    foreach ($skip as $w) {
        $w = trim(str_replace('\\', '/', (string)$w), '/');
        if ($w !== '' && strpos($rel, $w) === 0) return true;
    }
    return false;
}

/**
 * Update backup: copy into $dir/files/ only the files of $target that the package in $source will overwrite, and record the files
 * it will add (they do not exist yet). Uses the same skip list as the update itself. Returns [copied, added].
 */
function qlopy_backup_copy_changed(string $source, string $target, array $skip, string $dir): array {
    $copied = 0; $added = [];
    $slen = strlen(rtrim($source, '/\\')) + 1;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, RecursiveDirectoryIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if (!$f->isFile()) continue;
        $rel = ltrim(str_replace('\\', '/', substr($f->getPathname(), $slen)), '/');
        if ($rel === '' || qlopy_backup_skipped($rel, $skip)) continue;
        $old = rtrim($target, '/\\') . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
        if (is_file($old)) {
            $dest = $dir . DIRECTORY_SEPARATOR . 'files' . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
            if (!is_dir(dirname($dest))) @mkdir(dirname($dest), 0755, true);
            if (@copy($old, $dest)) $copied++;
        } else {
            $added[] = $rel;
        }
    }
    return [$copied, $added];
}

/**
 * Manual full backup into an existing folder (made by qlopy_backup_new('manual')): every file of the site except uploads/updates,
 * then the database unless the metadata says db=false. Long: run from the cron queue (task qlopy_full_backup). The metadata says
 * running until the end, then done (or failed, with the error).
 */
function qlopy_backup_full_run(string $dir): array {
    if (function_exists('set_time_limit')) @set_time_limit(0);
    @ignore_user_abort(true);
    $m = qlopy_backup_meta($dir);
    $m['status'] = 'running'; $m['started_at'] = date('c'); qlopy_backup_save_meta($dir, $m);
    $root = rtrim(qlopy_project_root(), '/\\');
    $skipAbs = str_replace('\\', '/', rtrim(qlopy_updates_root(), '/\\')) . '/';
    $files = 0; $errors = 0; $failed = [];
    try {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
        $slen = strlen($root) + 1;
        foreach ($it as $f) {
            $abs = str_replace('\\', '/', $f->getPathname());
            if (stripos($abs . '/', $skipAbs) === 0) continue;   // the backups themselves, download zips, update temp files
            $rel = substr($abs, $slen);
            $dest = $dir . DIRECTORY_SEPARATOR . 'files' . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
            if ($f->isDir()) { if (!is_dir($dest)) @mkdir($dest, 0755, true); continue; }
            if (!is_dir(dirname($dest))) @mkdir(dirname($dest), 0755, true);
            if (@copy($f->getPathname(), $dest)) $files++; else { $errors++; if (count($failed) < 20) $failed[] = $rel; }   // unreadable or locked
        }
        $db = ($m['db'] ?? true) ? qlopy_db_dump($dir . DIRECTORY_SEPARATOR . 'db-backup.sql.gz') : null;
        $m = qlopy_backup_meta($dir);
        $m['status'] = 'done'; $m['finished_at'] = date('c'); $m['files'] = $files; $m['copy_errors'] = $errors; $m['copy_failed'] = $failed;
        $m['has_db'] = (bool)$db; $m['target'] = $root;
        unset($m['size']); qlopy_backup_save_meta($dir, $m);
        qlopy_backup_size($dir, true);
        return ['ok' => true, 'files' => $files, 'errors' => $errors];
    } catch (Throwable $e) {
        $m = qlopy_backup_meta($dir);
        $m['status'] = 'failed'; $m['error'] = $e->getMessage(); qlopy_backup_save_meta($dir, $m);
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Database dump, written as it is read (one row at a time, unbuffered), gzip-compressed when the file name ends in .gz. Every
 * statement is followed by a line "-- qp:end", so qlopy_db_restore_file() splits it without parsing SQL. Returns true when done.
 */
function qlopy_db_dump(string $file): bool {
    if (!function_exists('db')) return false;
    $gz = substr($file, -3) === '.gz';
    $fh = $gz ? @gzopen($file, 'wb6') : @fopen($file, 'wb');
    if (!$fh) return false;
    $w = $gz ? 'gzwrite' : 'fwrite';
    $pdo = db();
    $buffered = $pdo->getAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY);
    try {
        $w($fh, "-- Qlopy database backup " . date('c') . "\nSET FOREIGN_KEY_CHECKS=0;\n" . QLOPY_BACKUP_DB_END . "\n");
        foreach ($pdo->query('SHOW FULL TABLES')->fetchAll(PDO::FETCH_NUM) as [$table, $type]) {
            if (strtoupper((string)$type) === 'VIEW') continue;
            $row = $pdo->query('SHOW CREATE TABLE `' . str_replace('`', '``', $table) . '`')->fetch(PDO::FETCH_NUM);
            $w($fh, 'DROP TABLE IF EXISTS `' . $table . "`;\n" . QLOPY_BACKUP_DB_END . "\n" . $row[1] . ";\n" . QLOPY_BACKUP_DB_END . "\n");
            $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
            $q = $pdo->query('SELECT * FROM `' . str_replace('`', '``', $table) . '`', PDO::FETCH_ASSOC);
            $batch = []; $cols = null;
            $flush = static function () use (&$batch, &$cols, $w, $fh, $table) {
                if (!$batch) return;
                $w($fh, 'INSERT INTO `' . $table . '` (' . $cols . ') VALUES ' . implode(',', $batch) . ";\n" . QLOPY_BACKUP_DB_END . "\n");
                $batch = [];
            };
            $bytes = 0;
            foreach ($q as $r) {
                if ($cols === null) $cols = implode(',', array_map(static fn ($c) => '`' . str_replace('`', '``', $c) . '`', array_keys($r)));
                $vals = [];
                foreach ($r as $v) $vals[] = $v === null ? 'NULL' : $pdo->quote((string)$v);
                $t = '(' . implode(',', $vals) . ')';
                $batch[] = $t; $bytes += strlen($t);
                if ($bytes > 512 * 1024) { $flush(); $bytes = 0; }
            }
            $q->closeCursor();
            $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, $buffered);
            $flush();
        }
        $w($fh, "SET FOREIGN_KEY_CHECKS=1;\n" . QLOPY_BACKUP_DB_END . "\n");
        $gz ? gzclose($fh) : fclose($fh);
        return true;
    } catch (Throwable $e) {
        try { $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, $buffered); } catch (Throwable $_) {}
        $gz ? @gzclose($fh) : @fclose($fh);
        @unlink($file);
        if (function_exists('qlopy_updater_log')) qlopy_updater_log('DB dump failed: ' . $e->getMessage());
        return false;
    }
}

/** Load a database backup. A qlopy_db_dump() file is run statement by statement as read; an older plain .sql goes through qlopy_run_sql_script(). */
function qlopy_db_restore_file(string $file): array {
    $gz = substr($file, -3) === '.gz';
    $fh = $gz ? @gzopen($file, 'rb') : @fopen($file, 'rb');
    if (!$fh) return ['error' => 'db_file_unreadable'];
    $read = $gz ? 'gzgets' : 'fgets';
    $first = (string)$read($fh);
    if (strpos($first, '-- Qlopy database backup') !== 0) {   // older format: whole file through the old runner
        $gz ? gzclose($fh) : fclose($fh);
        $sql = $gz ? (string)gzdecode((string)file_get_contents($file)) : (string)file_get_contents($file);
        if (!defined('QLOPY_ALLOW_RISKY_MIGRATIONS')) define('QLOPY_ALLOW_RISKY_MIGRATIONS', true);
        return qlopy_run_sql_script($sql);
    }
    $pdo = db(); $stmt = ''; $n = 0;
    try {
        while (($line = $read($fh)) !== false) {
            if (rtrim($line, "\r\n") === QLOPY_BACKUP_DB_END) {
                if (trim($stmt) !== '') { $pdo->exec($stmt); $n++; }
                $stmt = '';
            } else {
                $stmt .= $line;
            }
        }
        $gz ? gzclose($fh) : fclose($fh);
        return ['success' => true, 'statements' => $n];
    } catch (Throwable $e) {
        $gz ? @gzclose($fh) : @fclose($fh);
        try { $pdo->exec('SET FOREIGN_KEY_CHECKS=1'); } catch (Throwable $_) {}
        return ['error' => 'db_restore_failed', 'message' => $e->getMessage(), 'statement' => $n];
    }
}

/**
 * Put a backup back. Files go to the place they came from (metadata "target"; the site folder when unknown), with their paths kept.
 * An update backup also removes the files that update added. The database is loaded only when $withDb is true: it replaces every
 * table with the copy, so orders and changes made since the backup are lost.
 */
function qlopy_backup_restore(string $dir, bool $withDb = false): array {
    if (!is_dir($dir)) return ['error' => 'not_found'];
    $m = qlopy_backup_meta($dir);
    if (($m['status'] ?? 'done') !== 'done') return ['error' => 'backup_not_finished'];
    $root = rtrim(qlopy_project_root(), '/\\');
    $target = rtrim((string)($m['target'] ?? $root), '/\\');
    $rootReal = realpath($root);
    $targetReal = realpath($target) ?: $target;
    if ($rootReal === false || stripos(str_replace('\\', '/', $targetReal) . '/', str_replace('\\', '/', $rootReal) . '/') !== 0) return ['error' => 'target_outside_site'];
    $src = is_dir($dir . DIRECTORY_SEPARATOR . 'files') ? $dir . DIRECTORY_SEPARATOR . 'files' : $dir;   // before C17 the files sat at the top
    $restored = 0;
    $slen = strlen(rtrim($src, '/\\')) + 1;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src, RecursiveDirectoryIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if (!$f->isFile()) continue;
        $rel = ltrim(str_replace('\\', '/', substr($f->getPathname(), $slen)), '/');
        if ($src === $dir && in_array($rel, ['metadata.json', 'db-backup.sql', 'db-backup.sql.gz'], true)) continue;
        $dest = $target . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
        if (!is_dir(dirname($dest))) @mkdir(dirname($dest), 0755, true);
        if (@copy($f->getPathname(), $dest)) $restored++;
    }
    $removed = 0;
    foreach ((array)($m['added'] ?? []) as $rel) {
        $rel = ltrim(str_replace('\\', '/', (string)$rel), '/');
        if ($rel === '' || strpos($rel, '..') !== false) continue;
        $p = $target . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
        if (is_file($p) && @unlink($p)) $removed++;
    }
    $out = ['success' => true, 'files' => $restored, 'removed' => $removed, 'db' => false];
    if ($withDb && ($db = qlopy_backup_db_file($dir)) !== null) {
        $res = qlopy_db_restore_file($db);
        if (isset($res['error'])) return ['error' => 'db_restore_failed', 'details' => $res] + $out;
        $out['db'] = true;
    }
    if (function_exists('qlopy_audit_log')) qlopy_audit_log('backup_restored', ['backup' => basename($dir), 'with_db' => $withDb] + $out);
    return $out;
}

/** Give the folders from before C17 random names, once (their date is kept in front). */
function qlopy_backups_rename_legacy(): int {
    $base = qlopy_updates_backups_dir(); $n = 0;
    foreach ((array)glob($base . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR) as $d) {
        $name = basename($d);
        if (qlopy_backup_name_ok($name)) continue;
        $time = preg_match('/^(\d{8})_(\d{6})/', $name, $mm) ? (strtotime($mm[1] . ' ' . $mm[2]) ?: filemtime($d)) : filemtime($d);
        $m = qlopy_backup_meta($d);
        if (empty($m['kind'])) { $m['kind'] = qlopy_backup_kind($d); $m['renamed_from'] = $name; $m['created_at'] = $m['created_at'] ?? date('c', (int)$time); qlopy_backup_save_meta($d, $m); }
        if (@rename($d, $base . DIRECTORY_SEPARATOR . qlopy_backup_new_name((int)$time))) $n++;
    }
    return $n;
}

/** Keep the newest $keep automatic backups (update, database, selection); manual and unfinished ones are never removed. */
function qlopy_backups_prune(?int $keep = null): int {
    if ($keep === null) {
        $s = function_exists('qlopy_get_updater_settings') ? qlopy_get_updater_settings() : [];
        $keep = (int)($s['backup_retention'] ?? 3);
    }
    $keep = max(1, $keep);
    $auto = [];
    foreach ((array)glob(qlopy_updates_backups_dir() . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR) as $d) {
        $m = qlopy_backup_meta($d);
        if (!in_array(qlopy_backup_kind($d), QLOPY_BACKUP_KINDS_AUTO, true) || ($m['status'] ?? 'done') === 'running') continue;
        $auto[$d] = strtotime((string)($m['created_at'] ?? '')) ?: (int)filemtime($d);
    }
    arsort($auto);
    $removed = 0;
    foreach (array_slice(array_keys($auto), $keep) as $d) {
        if (qlopy_backup_rmdir($d)) { $removed++; if (function_exists('qlopy_audit_log')) qlopy_audit_log('backup_pruned', ['backup' => basename($d)]); }
    }
    return $removed;
}

/** Download zips and expired download tokens in uploads/updates/tmp older than $maxAge seconds. */
function qlopy_backups_tmp_cleanup(int $maxAge = 86400): int {
    $n = 0; $now = time();
    foreach ((array)glob(qlopy_updates_tmp_dir() . DIRECTORY_SEPARATOR . '*.zip') as $f) {
        if (is_file($f) && $now - (int)filemtime($f) > $maxAge && @unlink($f)) $n++;
    }
    $tf = qlopy_updates_tmp_dir() . DIRECTORY_SEPARATOR . 'download_tokens.json';
    if (is_file($tf)) {
        $tokens = json_decode((string)@file_get_contents($tf), true);
        if (is_array($tokens)) {
            $kept = array_filter($tokens, static fn ($t) => is_array($t) && (int)($t['expires'] ?? 0) > $now);
            if (count($kept) !== count($tokens)) @file_put_contents($tf, json_encode($kept, JSON_PRETTY_PRINT));
        }
    }
    return $n;
}

// The manual full backup runs in the background: cron queue task qlopy_full_backup (argument: the folder name), a heavy task
// (qp_cron_heavy_hooks), so a real cron runs it. Its handler is registered in auth.php, so every runner has it.
