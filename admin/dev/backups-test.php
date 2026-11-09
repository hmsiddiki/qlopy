<?php
// Dev tool (C17): backups and the daily cleanup. CLI only.
//   php admin/dev/backups-test.php
// Works in a scratch folder (uploads/updates/tmp/c17-test) and on rows it inserts itself, and removes all of them at the end.
// Backups it makes in uploads/updates/backups are removed too. It never restores the real database: the database round trip uses
// a test table only.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('QLOPY_INIT', true);
chdir(dirname(__DIR__, 2));
require 'db.php'; $pdo = db();
require 'auth.php';
$GLOBALS['config'] = $config;
require_once 'includes/qp-cron.php';
require_once 'includes/qp-cron-queue.php';
require_once 'includes/updater.php';
$pass = 0; $fail = 0;
function ok($c, $m) { global $pass, $fail; if ($c) $pass++; else $fail++; echo ($c ? 'PASS ' : 'FAIL ') . $m . "\n"; }
function put(string $path, string $body): void { if (!is_dir(dirname($path))) mkdir(dirname($path), 0755, true); file_put_contents($path, $body); }

$root = rtrim(qlopy_project_root(), '/\\');
$scratch = qlopy_updates_tmp_dir() . DIRECTORY_SEPARATOR . 'c17-test';
$made = [];   // backup folders made by this test
$before = array_map('basename', (array)glob(qlopy_updates_backups_dir() . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR));
$testTable = table_name('c17_backup_test');
$markRows = [];
try {
    echo "-- names and protection\n";
    $n = qlopy_backup_new_name();
    ok(qlopy_backup_name_ok($n) && strlen($n) === 15 + 1 + 32, 'a new name is date + 32 hex (' . $n . ')');
    ok($n !== qlopy_backup_new_name(), 'two names made in the same second differ');
    ok(!qlopy_backup_name_ok('20261108_091803_6af03e4b74387'), 'an old uniqid name is not accepted as random');
    qlopy_backups_protect();
    ok(is_file(qlopy_updates_root() . '/.htaccess') && strpos((string)file_get_contents(qlopy_updates_root() . '/.htaccess'), 'Require all denied') !== false, '.htaccess denies everything in uploads/updates');
    ok(is_file(qlopy_updates_backups_dir() . '/index.html') && is_file(qlopy_updates_tmp_dir() . '/index.html'), 'index.html in backups and tmp');

    echo "-- update backup: only the files the update changes, and the ones it adds\n";
    $target = $scratch . DIRECTORY_SEPARATOR . 'site';
    $pkg = $scratch . DIRECTORY_SEPARATOR . 'pkg';
    put("$target/a.php", 'old a'); put("$target/lib/b.php", 'old b'); put("$target/untouched.zip", str_repeat('z', 5000)); put("$target/config.php", 'old config');
    put("$pkg/a.php", 'new a'); put("$pkg/lib/b.php", 'new b'); put("$pkg/lib/c.php", 'new c'); put("$pkg/config.php", 'new config');
    $dir = qlopy_backup_new('update', ['type' => 'core', 'target' => $target, 'status' => 'running']); $made[] = basename($dir);
    [$copied, $added] = qlopy_backup_copy_changed($pkg, $target, ['config.php'], $dir);
    $m = qlopy_backup_meta($dir); $m['status'] = 'done'; $m['added'] = $added; $m['files'] = $copied; qlopy_backup_save_meta($dir, $m);
    ok($copied === 2, 'two existing files copied (got ' . $copied . ')');
    ok($added === ['lib/c.php'], 'the added file is recorded (' . json_encode($added) . ')');
    ok(is_file("$dir/files/a.php") && is_file("$dir/files/lib/b.php"), 'copies keep their paths under files/');
    ok(!is_file("$dir/files/untouched.zip") && !is_file("$dir/files/config.php"), 'files the update does not touch, and skipped ones, are not copied');
    ok(qlopy_backup_kind($dir) === 'update', 'kind is update');
    $probe = qlopy_backup_new('manual', ['status' => 'running', 'created_at' => '2026-01-02T03:04:05+00:00', 'kind' => 'update']); $made[] = basename($probe);
    $pm = qlopy_backup_meta($probe);
    ok($pm['status'] === 'running' && $pm['created_at'] === '2026-01-02T03:04:05+00:00' && $pm['kind'] === 'manual', 'the caller\'s status and time are kept, the kind cannot be changed');

    echo "-- restore puts the files back where they were and removes added ones\n";
    qlopy_recursive_apply($pkg, $target, ['config.php']);   // the update itself
    ok(file_get_contents("$target/a.php") === 'new a' && is_file("$target/lib/c.php"), 'update applied to the scratch site');
    $r = qlopy_backup_restore($dir, false);
    ok(!empty($r['success']) && $r['files'] === 2 && $r['removed'] === 1, 'restore reports 2 files and 1 removed (' . json_encode($r) . ')');
    ok(file_get_contents("$target/a.php") === 'old a' && file_get_contents("$target/lib/b.php") === 'old b', 'old files are back in their folders');
    ok(!is_file("$target/lib/c.php"), 'the file the update added is gone');
    ok(!is_file("$target/b.php") && !is_file($root . '/b.php'), 'nothing was restored into the wrong folder');
    ok(file_get_contents("$target/config.php") === 'old config', 'config.php untouched throughout');
    $outside = qlopy_backup_new('update', ['target' => dirname($root), 'status' => 'done']); $made[] = basename($outside);
    ok((qlopy_backup_restore($outside, false)['error'] ?? '') === 'target_outside_site', 'a target outside the site folder is refused');

    echo "-- database dump and restore (test table only)\n";
    $pdo->exec("DROP TABLE IF EXISTS `$testTable`");
    $pdo->exec("CREATE TABLE `$testTable` (id INT PRIMARY KEY, txt TEXT NULL, b BLOB NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $st = $pdo->prepare("INSERT INTO `$testTable` VALUES (?,?,?)");
    $st->execute([1, "line1\nline2; DROP TABLE x;\n-- qp:end\n'quoted' \"dq\" \\ back", null]);
    $st->execute([2, 'বাংলা ৳ টাকা', "\x00\x01\xFFbin"]);
    $st->execute([3, null, '']);
    $dumpFile = $scratch . '/dump.sql.gz';
    $t = microtime(true); $okDump = qlopy_db_dump($dumpFile); $secs = round(microtime(true) - $t, 2);
    ok($okDump && filesize($dumpFile) > 100, 'whole database dumped, compressed (' . round(filesize($dumpFile) / 1024) . ' KB in ' . $secs . ' s)');
    // keep only the statements of the test table, then restore those into an emptied table
    $lines = gzfile($dumpFile); $mine = "-- Qlopy database backup test\n"; $stmt = ''; $keep = false;
    foreach ($lines as $line) {
        if (rtrim($line, "\r\n") === QLOPY_BACKUP_DB_END) { if (strpos($stmt, "`$testTable`") !== false) $mine .= $stmt . QLOPY_BACKUP_DB_END . "\n"; $stmt = ''; continue; }
        $stmt .= $line;
    }
    ok(preg_match_all('/^' . preg_quote(QLOPY_BACKUP_DB_END, '/') . '$/m', $mine) === 3, 'the dump holds DROP, CREATE and one INSERT for the test table (the marker inside a value stays on its line)');
    $pdo->exec("DELETE FROM `$testTable`");
    $small = $scratch . '/small.sql.gz'; $g = gzopen($small, 'wb'); gzwrite($g, $mine); gzclose($g);
    $res = qlopy_db_restore_file($small);
    ok(!empty($res['success']), 'restore of the test statements ran (' . json_encode($res) . ')');
    $rows = $pdo->query("SELECT id, txt, b FROM `$testTable` ORDER BY id")->fetchAll(PDO::FETCH_NUM);
    ok(count($rows) === 3, 'three rows back');
    ok(($rows[0][1] ?? '') === "line1\nline2; DROP TABLE x;\n-- qp:end\n'quoted' \"dq\" \\ back", 'text with newlines, ;, the end marker, quotes and backslash is identical');
    ok(($rows[1][1] ?? '') === 'বাংলা ৳ টাকা' && ($rows[1][2] ?? '') === "\x00\x01\xFFbin", 'UTF-8 text and binary bytes are identical');
    ok($rows[0][2] === null && $rows[2][1] === null && $rows[2][2] === '', 'NULL and empty string are kept apart');

    echo "-- retention: automatic backups only\n";
    qlopy_backup_rmdir($dir); qlopy_backup_rmdir($outside);   // the earlier test backups are newer and would rightly take two of the slots
    $manual = qlopy_backup_new('manual', ['status' => 'done']); $made[] = basename($manual);
    $autos = [];
    for ($i = 0; $i < 5; $i++) { $d = qlopy_backup_new('database', ['status' => 'done', 'created_at' => date('c', time() - 1000 + $i)]); $autos[] = $d; $made[] = basename($d); }
    $running = qlopy_backup_new('update', ['status' => 'running', 'created_at' => date('c', time() - 5000)]); $made[] = basename($running);
    $countAuto = function () { $n = 0; foreach ((array)glob(qlopy_updates_backups_dir() . '/*', GLOB_ONLYDIR) as $d) { if (in_array(qlopy_backup_kind($d), QLOPY_BACKUP_KINDS_AUTO, true) && (qlopy_backup_meta($d)['status'] ?? 'done') === 'done') $n++; } return $n; };
    qlopy_backups_prune(3);
    ok($countAuto() === 3, 'three automatic backups left (got ' . $countAuto() . ')');
    ok(is_dir($autos[4]) && is_dir($autos[3]) && is_dir($autos[2]) && !is_dir($autos[0]), 'the newest three are the ones kept');
    ok(is_dir($manual), 'the manual backup is never pruned');
    ok(is_dir($running), 'a backup still being made is never pruned');
    $s = qlopy_get_updater_settings();
    ok((int)($s['backup_retention'] ?? 3) >= 1, 'retention setting readable (' . (int)($s['backup_retention'] ?? 3) . ')');

    echo "-- old folders get random names once\n";
    $legacy = qlopy_updates_backups_dir() . '/20261101_101010_6af03e4b74387';
    mkdir($legacy . '/includes', 0755, true); put($legacy . '/includes/x.php', 'x');
    file_put_contents($legacy . '/metadata.json', json_encode(['created_at' => '2026-11-01T10:10:10+00:00', 'type' => 'core', 'target' => $root]));
    $renamed = qlopy_backups_rename_legacy();
    $newName = null; foreach ((array)glob(qlopy_updates_backups_dir() . '/20261101_101010_*', GLOB_ONLYDIR) as $d) $newName = basename($d);
    if ($newName) $made[] = $newName;
    ok($renamed >= 1 && !is_dir($legacy) && $newName !== null && qlopy_backup_name_ok($newName), 'old folder renamed, date kept in front (' . $newName . ')');
    ok($newName && qlopy_backup_kind(qlopy_updates_backups_dir() . '/' . $newName) === 'update', 'and recorded as an update backup');

    echo "-- download zips: random name, removed after a day\n";
    $zip = qlopy_create_backup_zip(basename($manual));
    ok($zip !== null && preg_match('/backup_[a-f0-9]{32}\.zip$/', $zip) === 1, 'zip name is random (' . basename((string)$zip) . ')');
    touch($zip, time() - 2 * 86400);
    ok(qlopy_backups_tmp_cleanup(86400) >= 1 && !is_file($zip), 'a zip older than a day is removed');

    echo "-- full manual backup (scratch copy of the run, without the database)\n";
    $full = qlopy_backup_new('manual', ['status' => 'running', 'db' => false]); $made[] = basename($full);
    $t = microtime(true); $fr = qlopy_backup_full_run($full); $secs = round(microtime(true) - $t, 1);
    $fm = qlopy_backup_meta($full);
    ok(!empty($fr['ok']) && ($fm['status'] ?? '') === 'done', 'full backup finished (' . (int)($fr['files'] ?? 0) . ' files, ' . round(qlopy_backup_size($full) / 1048576) . ' MB, ' . $secs . ' s)');
    ok(is_file($full . '/files/auth.php') && is_file($full . '/files/index.php'), 'core files are in it');
    $up = function_exists('qp_uploads_dirname') ? qp_uploads_dirname() : 'uploads';
    ok(is_dir($full . '/files/' . $up), 'uploads are in it (no filtering)');
    ok(!is_dir($full . '/files/' . $up . '/updates'), 'uploads/updates (the backups) is not in it');
    ok(empty($fm['has_db']), 'no database when asked without');

    echo "-- daily cleanup on rows made for the test\n";
    // a fresh site may never have made these tables (cron logging off, no mail sent yet): create them the way the core does
    if (function_exists('qp_mail_ensure_tables')) qp_mail_ensure_tables();
    qp_cron_queue_install($pdo);
    qp_cron_logs_install($pdo);
    $log = table_name('mail_log'); $dbg = table_name('mail_debug'); $q = table_name('cron_queue'); $cl = table_name('cron_logs');
    $old = date('Y-m-d H:i:s', time() - 200 * 86400); $new = date('Y-m-d H:i:s');
    $pdo->prepare("INSERT INTO `$log` (recipients, subject, body, status, created_at) VALUES (?, ?, ?, 'sent', ?)")->execute(['["c17@example.test"]', 'C17 old', 'x', $old]); $oldLog = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO `$log` (recipients, subject, body, status, created_at) VALUES (?, ?, ?, 'sent', ?)")->execute(['["c17@example.test"]', 'C17 new', 'x', $new]); $newLog = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO `$dbg` (log_id, transcript, created_at) VALUES (?, 'c17', ?)")->execute([$newLog, date('Y-m-d H:i:s', time() - 20 * 86400)]); $oldDbg = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO `$dbg` (log_id, transcript, created_at) VALUES (?, 'c17', ?)")->execute([$newLog, $new]); $newDbg = (int)$pdo->lastInsertId();
    $ins = $pdo->prepare("INSERT INTO `$q` (hook, args, origin, status, next_run, recurrence, created_at, updated_at) VALUES ('c17_test_task', '[]', 'test', ?, 0, ?, ?, ?)");
    $ids = [];
    foreach ([['done', 0, 10], ['done', 0, 2], ['failed', 0, 40], ['failed', 0, 10], ['pending', 0, 400], ['done', 86400, 400]] as [$st2, $rec, $age]) { $ins->execute([$st2, $rec, time() - $age * 86400, time() - $age * 86400]); $ids[] = (int)$pdo->lastInsertId(); }
    $pdo->prepare("INSERT INTO `$cl` (hook, status, message, created_at) VALUES ('c17_test_task', 'success', 'c17', ?)")->execute([time() - 60 * 86400]); $oldCl = (int)$pdo->lastInsertId();
    $markRows = compact('oldLog', 'newLog', 'oldDbg', 'newDbg', 'ids', 'oldCl');
    $hr = qp_housekeeping_run();
    $exists = fn ($t, $id) => (int)$pdo->query("SELECT COUNT(*) FROM `$t` WHERE id = " . (int)$id)->fetchColumn() === 1;
    ok(!$exists($log, $oldLog) && $exists($log, $newLog), 'mail log: 200-day-old entry removed, today\'s kept');
    ok(!$exists($dbg, $oldDbg) && $exists($dbg, $newDbg), 'SMTP transcript: 20-day-old removed, today\'s kept');
    ok(!$exists($q, $ids[0]) && $exists($q, $ids[1]), 'cron: done 10 days ago removed, done 2 days ago kept');
    ok(!$exists($q, $ids[2]) && $exists($q, $ids[3]), 'cron: failed 40 days ago removed, failed 10 days ago kept');
    ok($exists($q, $ids[4]) && $exists($q, $ids[5]), 'cron: pending and repeating tasks never removed, however old');
    ok(!$exists($cl, $oldCl), 'cron log: 60-day-old line removed');
    ok(empty($hr['errors']), 'cleanup reported no problems' . ($hr['errors'] ? ': ' . implode('; ', $hr['errors']) : ''));
    $usage = qp_housekeeping_usage();
    ok(isset($usage['mail_log'], $usage['cron_queue'], $usage['backups_auto'], $usage['backups_manual']), 'usage lists mail, cron and both backup groups');
} catch (Throwable $e) {
    ok(false, 'exception: ' . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    foreach (array_unique($made) as $name) { $d = qlopy_updates_backups_dir() . DIRECTORY_SEPARATOR . $name; if (!in_array($name, $before, true)) qlopy_backup_rmdir($d); }
    qlopy_backup_rmdir($scratch);
    try { $pdo->exec("DROP TABLE IF EXISTS `$testTable`"); } catch (Throwable $_) {}
    if ($markRows) {
        try {
            $pdo->exec('DELETE FROM `' . table_name('mail_log') . '` WHERE id IN (' . (int)$markRows['oldLog'] . ',' . (int)$markRows['newLog'] . ')');
            $pdo->exec('DELETE FROM `' . table_name('mail_debug') . '` WHERE id IN (' . (int)$markRows['oldDbg'] . ',' . (int)$markRows['newDbg'] . ')');
            $pdo->exec('DELETE FROM `' . table_name('cron_queue') . '` WHERE hook = \'c17_test_task\'');
            $pdo->exec('DELETE FROM `' . table_name('cron_logs') . '` WHERE hook = \'c17_test_task\'');
        } catch (Throwable $_) {}
    }
    $left = array_diff(array_map('basename', (array)glob(qlopy_updates_backups_dir() . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR)), $before);
    ok($left === [] , 'test backups removed again' . ($left ? ': ' . implode(', ', $left) : ''));
}
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
