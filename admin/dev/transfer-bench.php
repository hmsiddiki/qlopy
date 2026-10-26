<?php
// Dev tool (C8): stress test for the import engine. CLI only.
//   php -d memory_limit=128M admin/dev/transfer-bench.php [--rows=3000] [--unique-images=40] [--step=15]
// Imports N posts from a CSV; every row has a featured image from a local throw-away web server (a few distinct images, so most rows
// reuse a download, plus --unique-images distinct ones). Reports time, rows per second and the peak memory of the whole run, then undoes it
// and removes everything it made. The PHP memory_limit you pass is the limit the run must fit in.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('QLOPY_INIT', true);
chdir(dirname(__DIR__, 2));
$opt = getopt('', ['rows::', 'unique-images::', 'step::']);
$rows = max(10, (int)($opt['rows'] ?? 3000)); $unique = max(1, (int)($opt['unique-images'] ?? 40)); $stepSec = max(1, (int)($opt['step'] ?? 15));
require 'db.php'; $pdo = db();
require 'auth.php';
require_once 'includes/transfer.php';
$tag = 'c8b' . substr(bin2hex(random_bytes(4)), 0, 6);
$uid = (int)$pdo->query("SELECT user_id FROM " . table_name('user_meta') . " WHERE meta_key = 'role' AND meta_value LIKE '%admin%' LIMIT 1")->fetchColumn();
$oldActive = get_option_meta('qp_transfer_active');
$dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'qp-c8b-' . $tag; @mkdir($dir);
$port = 0;
for ($i = 0; $i < 20 && !$port; $i++) { $p = random_int(20000, 60000); $s = @stream_socket_server("tcp://127.0.0.1:$p"); if ($s) { fclose($s); $port = $p; } }
file_put_contents($dir . '/router.php', <<<'PHP'
<?php
$u = parse_url($_SERVER['REQUEST_URI']);
if (preg_match('~^/img(\d+)\.jpg$~', $u['path'], $m)) {
    $im = imagecreatetruecolor(1200, 900); $n = (int)$m[1];
    imagefilledrectangle($im, 0, 0, 1200, 900, imagecolorallocate($im, ($n * 37) % 255, ($n * 91) % 255, ($n * 53) % 255));
    for ($i = 0; $i < 30; $i++) imagefilledellipse($im, random_int(0, 1200), random_int(0, 900), random_int(40, 300), random_int(40, 300), imagecolorallocate($im, random_int(0, 255), random_int(0, 255), random_int(0, 255)));
    header('Content-Type: image/jpeg'); imagejpeg($im, null, 82); return;
}
http_response_code(404);
PHP);
$server = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", '-t', $dir, $dir . '/router.php'], [0 => ['pipe', 'r'], 1 => ['file', $dir . '/out.txt', 'w'], 2 => ['file', $dir . '/err.txt', 'w']], $pipes);
for ($i = 0; $i < 50; $i++) { $c = @fsockopen('127.0.0.1', $port, $en, $es, 0.2); if ($c) { fclose($c); break; } usleep(100000); }
$base = "http://127.0.0.1:$port";

// the entity: posts, but its media calls may reach the local server
add_filter('qp_transfer_entities', function ($e) {
    $orig = $e['posts']['import_row'];
    $e['posts']['import_row'] = function (array $row, array $ctx) use ($orig) { $m = $ctx['media']; $ctx['media'] = function ($u) use ($m) { return $m($u, ['allow_private' => true]); }; return $orig($row, $ctx); };
    return $e;
});
$csv = "Title,Slug,Body,Image\r\n";
for ($i = 1; $i <= $rows; $i++) $csv .= "\"Bench post $i\",{$tag}-$i,\"" . str_repeat('Lorem ipsum dolor sit amet. ', 8) . "\",{$base}/img" . (($i <= $unique) ? $i : (($i % 3) + 1)) . ".jpg\r\n";
$name = bin2hex(random_bytes(16)) . '.csv'; $path = qp_transfer_dir() . DIRECTORY_SEPARATOR . $name; file_put_contents($path, $csv);
[$o, $info] = qp_transfer_file_inspect($path, 'csv');
$plan = ['file' => $name, 'format' => 'csv', 'delimiter' => ',', 'columns' => $info['columns'], 'encoding' => 'UTF-8', 'source_id' => 'bench:' . $tag,
         'map' => ['title' => 'Title', 'slug' => 'Slug', 'content' => 'Body', 'featured_image' => 'Image']];
[$id, $err] = qp_transfer_run_create(['entity' => 'posts', 'direction' => 'import', 'source' => 'csv', 'plan' => $plan, 'user' => $uid]);
echo "rows=$rows distinct images=" . ($unique + 3) . " memory_limit=" . ini_get('memory_limit') . " run=$id\n";
$t0 = microtime(true); $steps = 0; $peak0 = memory_get_peak_usage(true);
do {
    $r = qp_transfer_step($id, $stepSec); $steps++;
    printf("  step %d: %s, read %d, created %d, rejected %d, peak %.1f MB\n", $steps, $r['state'], $r['run']['counts']['read'], $r['run']['counts']['created'], $r['run']['counts']['rejected'], memory_get_peak_usage(true) / 1048576);
} while ($r['state'] === 'running' && $steps < 500);
$t = microtime(true) - $t0;
$run = qp_transfer_run_get($id);
printf("import: %s in %.1f s (%.1f rows/s), %d steps, created %d, rejected %d, peak memory %.1f MB\n", $r['state'], $t, $rows / max(0.001, $t), $steps, $run['counts']['created'], $run['counts']['rejected'], memory_get_peak_usage(true) / 1048576);
foreach (array_slice($run['problems'], 0, 3) as $p) echo "  problem: row {$p['row']} {$p['reason']}\n";
$atts = (int)$pdo->query("SELECT COUNT(*) FROM " . qp_transfer_map_table() . " WHERE entity = 'attachment' AND created_run = " . (int)$id)->fetchColumn();
echo "images stored: $atts\n";

// a repeat run must be quick: everything is recognised
[$id2] = qp_transfer_run_create(['entity' => 'posts', 'direction' => 'import', 'source' => 'csv', 'plan' => $plan, 'user' => $uid]);
$t1 = microtime(true); $s2 = 0; do { $r2 = qp_transfer_step($id2, $stepSec); $s2++; } while ($r2['state'] === 'running' && $s2 < 200);
printf("repeat: %s in %.1f s, same %d, created %d\n", $r2['state'], microtime(true) - $t1, $r2['run']['counts']['same'], $r2['run']['counts']['created']);

// export it back
[$id3] = qp_transfer_run_create(['entity' => 'posts', 'direction' => 'export', 'plan' => ['format' => 'ndjson', 'filters' => []], 'user' => $uid]);
$t2 = microtime(true); $s3 = 0; do { $r3 = qp_transfer_step($id3, $stepSec); $s3++; } while ($r3['state'] === 'running' && $s3 < 200);
printf("export: %s in %.1f s, %d rows, peak memory %.1f MB\n", $r3['state'], microtime(true) - $t2, $r3['run']['counts']['exported'], memory_get_peak_usage(true) / 1048576);

// undo
qp_transfer_run_undo($id);
$t3 = microtime(true); $s4 = 0; do { $r4 = qp_transfer_step($id, $stepSec); $s4++; } while ($r4['state'] === 'running' && $s4 < 500);
printf("undo: %s in %.1f s, removed %d\n", $r4['state'], microtime(true) - $t3, $r4['run']['counts']['undone']);
$left = (int)$pdo->query("SELECT COUNT(*) FROM " . table_name('posts') . " WHERE slug LIKE '{$tag}%'")->fetchColumn();
$leftAtt = (int)$pdo->query("SELECT COUNT(*) FROM " . table_name('posts') . " WHERE post_type = 'attachment' AND title LIKE 'img%.jpg' AND created_at >= NOW() - INTERVAL 1 DAY")->fetchColumn();
echo "left after undo: posts $left, bench attachments $leftAtt\n";

// cleanup anything left
foreach ($pdo->query("SELECT p.id FROM " . table_name('posts') . " p WHERE p.post_type = 'attachment' AND p.title REGEXP '^img[0-9]+\\\\.jpg$'")->fetchAll(PDO::FETCH_COLUMN) as $aid) qp_delete_attachment((int)$aid);
$pdo->exec("DELETE FROM " . table_name('posts') . " WHERE slug LIKE '{$tag}%'");
foreach ([$id, $id2, $id3] as $rid) qp_transfer_run_delete((int)$rid);
$pdo->prepare('DELETE FROM ' . qp_transfer_map_table() . ' WHERE source = ? OR source = ?')->execute(['bench:' . $tag, 'media']);
if (function_exists('qp_remove_tasks_by_hook')) qp_remove_tasks_by_hook('qp_transfer_step');
update_option_meta('qp_transfer_active', $oldActive === null || $oldActive === false ? 0 : $oldActive);
proc_terminate($server); proc_close($server);
foreach ((array)glob($dir . DIRECTORY_SEPARATOR . '*') as $x) @unlink($x); @rmdir($dir);
echo "done\n";
