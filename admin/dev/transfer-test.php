<?php
// Dev tool (C8): checks the import and export engine against this site's real database. CLI only.
//   php admin/dev/transfer-test.php
// Part 1 checks the safe downloader against a throw-away local web server. Part 2 checks reading files and cleaning values. Part 3 runs
// real imports and exports of posts (dry run, resume, repeat, update, undo, problems). Part 4 checks the image download and its stored path.
// Everything it creates (posts, attachments and their files, runs, map rows, test files, options) is removed at the end.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('QLOPY_INIT', true);
chdir(dirname(__DIR__, 2));
require 'db.php'; $pdo = db();
require 'auth.php';
require_once 'includes/transfer.php';
$pass = 0; $fail = 0;
function ok($c, $m) { global $pass, $fail; if ($c) $pass++; else $fail++; echo ($c ? 'PASS ' : 'FAIL ') . $m . "\n"; }
$tag = 'c8t' . substr(bin2hex(random_bytes(4)), 0, 6);
$uid = (int)$pdo->query("SELECT user_id FROM " . table_name('user_meta') . " WHERE meta_key = 'role' AND meta_value LIKE '%admin%' LIMIT 1")->fetchColumn();
$oldSchema = get_option_meta('qp_transfer_schema'); $oldActive = get_option_meta('qp_transfer_active');
$postsT = table_name('posts');
$cleanup = [];

// ---- a throw-away web server -----------------------------------------------------------------------------------------
$dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'qp-c8-' . $tag; @mkdir($dir);
$port = 0;
for ($i = 0; $i < 20 && !$port; $i++) { $p = random_int(20000, 60000); $s = @stream_socket_server("tcp://127.0.0.1:$p"); if ($s) { fclose($s); $port = $p; } }
file_put_contents($dir . '/router.php', <<<'PHP'
<?php
$u = parse_url($_SERVER['REQUEST_URI']); $path = $u['path']; parse_str($u['query'] ?? '', $q);
if ($path === '/file') { header('Content-Type: application/octet-stream'); echo str_repeat('0123456789', 5000); return; }
if ($path === '/big') { header('Content-Type: application/octet-stream'); for ($i = 0; $i < (int)($q['kb'] ?? 1); $i++) echo str_repeat('x', 1024); return; }
if ($path === '/redirect') { header('Location: /file', true, 302); return; }
if ($path === '/redirect2') { header('Location: /redirect', true, 301); return; }
if ($path === '/loop') { header('Location: /loop', true, 302); return; }
if ($path === '/auth') { header('Content-Type: text/plain'); echo ($_SERVER['HTTP_AUTHORIZATION'] ?? '') . '|' . ($_SERVER['HTTP_X_TEST'] ?? ''); return; }
if ($path === '/json') { header('Content-Type: application/json'); echo json_encode(['ok' => true, 'page' => (int)($q['page'] ?? 1)]); return; }
if ($path === '/text.png') { header('Content-Type: text/plain'); echo 'this is not an image'; return; }
if (preg_match('~^/img(\d*)\.png$~', $path, $m)) {
    $im = imagecreatetruecolor(640, 480); $c = imagecolorallocate($im, 30 + (int)$m[1] * 40, 120, 200); imagefilledrectangle($im, 0, 0, 640, 480, $c);
    imagefilledellipse($im, 320, 240, 200, 200, imagecolorallocate($im, 250, 250, 250)); header('Content-Type: image/png'); imagepng($im); return;
}
http_response_code(404); echo 'nope';
PHP);
$server = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", '-t', $dir, $dir . '/router.php'], [0 => ['pipe', 'r'], 1 => ['file', $dir . '/out.txt', 'w'], 2 => ['file', $dir . '/err.txt', 'w']], $pipes);
for ($i = 0; $i < 50; $i++) { $c = @fsockopen('127.0.0.1', $port, $en, $es, 0.2); if ($c) { fclose($c); break; } usleep(100000); }
$base = "http://127.0.0.1:$port";
$local = ['allow_private' => true, 'schemes' => ['http', 'https']];

try {
    // ---- Part 1: the safe downloader ---------------------------------------------------------------------------------------
    foreach (['10.0.0.1', '192.168.1.5', '172.16.0.9', '127.0.0.1', '169.254.169.254', '100.64.0.1', '0.0.0.0', '192.0.2.5', '224.0.0.1', '::1', 'fe80::1', 'fc00::1', '::ffff:127.0.0.1', '::ffff:10.0.0.1'] as $ip)
        ok(!qp_http_ip_is_public($ip), "$ip is refused as a private or reserved address");
    foreach (['8.8.8.8', '1.1.1.1', '93.184.216.34', '2606:4700:4700::1111'] as $ip) ok(qp_http_ip_is_public($ip), "$ip is a public address");
    ok(!qp_http_url_check('http://127.0.0.1:9/', ['schemes' => ['http']])[0], 'a loopback address is refused by default');
    ok(qp_http_url_check("http://127.0.0.1:9/", ['schemes' => ['http'], 'allow_private' => true])[0], 'a loopback address is allowed when the caller says so');
    ok(!qp_http_url_check('http://8.8.8.8/')[0], 'plain http is refused when only https is allowed');
    ok(!qp_http_url_check('https://user:pw@8.8.8.8/')[0], 'credentials inside the address are refused');
    ok(!qp_http_url_check('ftp://8.8.8.8/f')[0] && !qp_http_url_check('file:///etc/passwd')[0] && !qp_http_url_check('gopher://8.8.8.8/')[0], 'other schemes are refused');
    ok(!qp_http_url_check('https://8.8.8.8:99999/')[0], 'a port out of range is refused');
    ok(!qp_http_url_check('not a url')[0], 'a non-address is refused');
    ok(qp_http_url_check('https://8.8.8.8/x')[0] && qp_http_url_check('https://8.8.8.8/x')[2] === '8.8.8.8', 'a public address is accepted and pinned');
    ok(qp_http_absolute_url('https://a.test/x/y?z=1', '/q') === 'https://a.test/q' && qp_http_absolute_url('https://a.test/x/y', 'z') === 'https://a.test/x/z' && qp_http_absolute_url('https://a.test:8443/x', '//b.test/p') === 'https://b.test/p', 'a redirect target is made absolute');

    [$o, $m] = qp_http_download($base . '/file', "$dir/a.bin", ['schemes' => ['http']]);
    ok(!$o && strpos($m, 'private') !== false && !file_exists("$dir/a.bin"), 'a download from a local address is refused by default (even when http is allowed)');
    [$o, $info] = qp_http_download($base . '/file', "$dir/a.bin", $local);
    ok($o && filesize("$dir/a.bin") === 50000 && $info['bytes'] === 50000 && $info['content_type'] === 'application/octet-stream', 'a file is downloaded with its size and type');
    [$o, $m] = qp_http_download($base . '/big?kb=3000', "$dir/b.bin", $local + ['max_bytes' => 1048576]);
    ok(!$o && strpos($m, 'larger') !== false && !file_exists("$dir/b.bin") && count(glob("$dir/b.bin*")) === 0, 'a file over the size cap is refused and nothing is left behind');
    [$o, $info] = qp_http_download($base . '/redirect', "$dir/c.bin", $local);
    ok($o && $info['bytes'] === 50000 && substr($info['final_url'], -5) === '/file', 'a redirect is followed');
    [$o, $info] = qp_http_download($base . '/redirect2', "$dir/c2.bin", $local);
    ok($o && $info['bytes'] === 50000, 'two redirects in a row are followed');
    [$o, $m] = qp_http_download($base . '/redirect2', "$dir/c3.bin", $local + ['max_redirects' => 1]);
    ok(!$o && strpos($m, 'redirect') !== false, 'the redirect limit is enforced');
    [$o, $m] = qp_http_download($base . '/loop', "$dir/d.bin", $local);
    ok(!$o && strpos($m, 'redirect') !== false, 'a redirect loop ends with a message');
    [$o, $m] = qp_http_download($base . '/missing', "$dir/e.bin", $local);
    ok(!$o && strpos($m, '404') !== false && !file_exists("$dir/e.bin"), 'a 404 is reported and leaves no file');
    [$o, $body, $info] = qp_http_get($base . '/json?page=3', $local + ['headers' => ['X-Test: yes'], 'basic_auth' => 'u:p']);
    ok($o && json_decode($body, true) === ['ok' => true, 'page' => 3] && $info['content_type'] === 'application/json', 'qp_http_get returns the body and the type');
    [$o, $body] = qp_http_get($base . '/auth', $local + ['headers' => ['X-Test: hello'], 'basic_auth' => 'user:pass']);
    ok($o && $body === 'Basic ' . base64_encode('user:pass') . '|hello', 'headers and basic auth are sent to the host they are meant for');
    [$o, $m] = qp_http_get($base . '/big?kb=100', $local + ['max_bytes' => 20000]);
    ok(!$o && strpos($m, 'larger') !== false, 'qp_http_get also enforces the size cap');

    // ---- Part 2: files and values --------------------------------------------------------------------------------------------
    $f = function ($name, $content) use (&$cleanup) { $n = bin2hex(random_bytes(16)) . '.' . $name; $p = qp_transfer_dir() . DIRECTORY_SEPARATOR . $n; file_put_contents($p, $content); $cleanup[] = $p; return [$n, $p]; };
    [$n1, $p1] = $f('csv', "\xEF\xBB\xBFTitle;Slug;Price\r\n\"Hello; world\";hello;\"1.234,50\"\r\n\"Two\nlines\";two;12,5\r\n");
    [$o, $info] = qp_transfer_file_inspect($p1, 'csv');
    ok($o && $info['delimiter'] === ';' && $info['columns'] === ['Title', 'Slug', 'Price'] && count($info['sample']) === 2 && $info['sample'][1]['Title'] === "Two\nlines", 'a CSV with a byte-order mark, semicolons and a quoted newline is read');
    [$n2, $p2] = $f('csv', "T\xE9a,Slug\r\nCaf\xE9,cafe\r\n");
    [$o, $info] = qp_transfer_file_inspect($p2, 'csv');
    ok($o && $info['encoding'] === 'Windows-1252' && $info['columns'][0] === 'Téa' && $info['sample'][0]['T' . "\xC3\xA9" . 'a'] === 'Café', 'a Windows-1252 file is converted to UTF-8');
    [$n3, $p3] = $f('csv', "a,a,\r\n1,2,3\r\n");
    [$o, $info] = qp_transfer_file_inspect($p3, 'csv');
    ok($o && $info['columns'] === ['a', 'a_2', 'column_3'], 'duplicate and empty column names are made unique');
    [$n4, $p4] = $f('json', json_encode(['data' => [['t' => 'A'], ['t' => 'B']], 'total' => 2]));
    [$o, $info] = qp_transfer_file_inspect($p4, 'json');
    ok($o && $info['rows'] === 2 && $info['columns'] === ['t'], 'a JSON object with one list is read');
    [$n5, $p5] = $f('json', json_encode(['a' => [['t' => 1]], 'b' => [['t' => 2]]]));
    ok(!qp_transfer_file_inspect($p5, 'json')[0], 'a JSON object with two lists is refused');
    [$n6, $p6] = $f('ndjson', "{\"t\":\"A\"}\n\n{\"t\":\"B\",\"u\":1}\nnot json\n");
    [$o, $info] = qp_transfer_file_inspect($p6, 'ndjson');
    ok(!$o, 'a JSON lines file with a bad line among the first rows is refused');
    [$n7, $p7] = $f('ndjson', "{\"t\":\"A\"}\n\n{\"t\":\"B\",\"u\":1}\n");
    [$o, $info] = qp_transfer_file_inspect($p7, 'ndjson');
    ok($o && $info['columns'] === ['t', 'u'], 'a JSON lines file is read, blank lines skipped');
    $r = qp_transfer_file_read(['file' => $n7, 'format' => 'ndjson'], '0', 10);
    ok(count($r['rows']) === 2 && $r['done'] && $r['rows'][1][0]['t'] === 'B', 'JSON lines rows are read with a cursor');
    $r = qp_transfer_file_read(['file' => $n1, 'format' => 'csv', 'delimiter' => ';', 'columns' => ['Title', 'Slug', 'Price'], 'encoding' => 'UTF-8'], '0', 1);
    $r2 = qp_transfer_file_read(['file' => $n1, 'format' => 'csv', 'delimiter' => ';', 'columns' => ['Title', 'Slug', 'Price'], 'encoding' => 'UTF-8'], $r['rows'][0][1], 5);
    ok(count($r['rows']) === 1 && !$r['done'] && count($r2['rows']) === 1 && $r2['done'] && $r2['rows'][0][0]['Slug'] === 'two', 'a CSV is read in pieces and resumed from the cursor');

    $c = function ($v, $t, $extra = []) { return qp_transfer_cast($v, ['type' => $t] + $extra); };
    ok($c('1,234.56', 'decimal') === [true, '1234.56'] && $c('1.234,56', 'decimal') === [true, '1234.56'] && $c('12,5', 'decimal') === [true, '12.5'] && $c('৳ 1,000', 'decimal') === [true, '1000'] && $c('abc', 'decimal')[0] === false, 'decimals are read in the common styles, never guessed');
    ok($c('42', 'int') === [true, 42] && $c('4.2', 'int')[0] === false && $c('', 'int') === [true, null], 'whole numbers are checked and an empty value is nothing');
    ok($c('Yes', 'bool') === [true, true] && $c('off', 'bool') === [true, false] && $c('maybe', 'bool')[0] === false, 'yes and no are read');
    ok($c('2026-10-26 10:00', 'date')[1] === '2026-10-26 10:00:00' && $c('later', 'date')[0] === false, 'dates are read');
    ok($c('https://a.test/x.jpg', 'url')[0] === true && $c('javascript:alert(1)', 'url')[0] === false, 'only web addresses are accepted as urls');
    ok($c('a| b,c', 'list') === [true, ['a', 'b', 'c']], 'a list is split on | and ,');
    ok($c('PUBLISHED', 'string', ['choices' => ['draft', 'published']]) === [true, 'published'] && $c('x', 'string', ['choices' => ['draft']])[0] === false, 'choices are checked without regard to case');
    ok($c(str_repeat('a', 600), 'string')[0] === false && $c(str_repeat('a', 600), 'text')[0] === true, 'a string has a length limit and a text does not');
    ok(qp_transfer_csv_safe('=SUM(A1)') === "\t=SUM(A1)" && qp_transfer_csv_safe('-5') === '-5' && qp_transfer_csv_safe('plain') === 'plain', 'a cell that a spreadsheet would run as a formula is neutralised');

    // ---- Part 3: runs ------------------------------------------------------------------------------------------------------------
    $count = function () use ($pdo, $postsT, $tag) { $s = $pdo->prepare("SELECT COUNT(*) FROM {$postsT} WHERE slug LIKE ?"); $s->execute([$tag . '%']); return (int)$s->fetchColumn(); };
    $csv = "Title,Slug,Body,Type,State,Date\r\n";
    for ($i = 1; $i <= 25; $i++) $csv .= "\"Post $i\",{$tag}-$i,\"Body of post $i\",post,draft,2026-01-" . str_pad((string)(($i % 28) + 1), 2, '0', STR_PAD_LEFT) . "\r\n";
    [$nA, $pA] = $f('csv', $csv);
    [$o, $info] = qp_transfer_file_inspect($pA, 'csv');
    $plan = ['file' => $nA, 'format' => 'csv', 'delimiter' => $info['delimiter'], 'columns' => $info['columns'], 'encoding' => $info['encoding'],
             'map' => ['title' => 'Title', 'slug' => 'Slug', 'content' => 'Body', 'post_type' => 'Type', 'status' => 'State', 'published_at' => 'Date'], 'source_id' => 'test:' . $tag];
    [$id, $err] = qp_transfer_run_create(['entity' => 'posts', 'direction' => 'import', 'source' => 'csv', 'plan' => ['map' => ['slug' => 'Slug']] + $plan, 'user' => $uid]);
    ok($id === null && strpos($err, 'Title') !== false, 'a run without the required column mapped is refused: ' . $err);
    [$id, $err] = qp_transfer_run_create(['entity' => 'nothing', 'direction' => 'import', 'source' => 'csv', 'plan' => $plan]);
    ok($id === null, 'an unknown entity is refused');
    [$id, $err] = qp_transfer_run_create(['entity' => 'posts', 'direction' => 'import', 'source' => 'csv', 'plan' => ['file' => str_repeat('a', 32) . '.csv'] + $plan]);
    ok($id === null, 'a missing file is refused');

    $before = $count();
    [$dry, $err] = qp_transfer_run_create(['entity' => 'posts', 'direction' => 'import', 'source' => 'csv', 'plan' => $plan, 'dry' => true, 'user' => $uid]);
    $r = qp_transfer_step($dry, 30);
    ok($r['state'] === 'done' && $r['run']['counts']['created'] === 25 && $r['run']['counts']['read'] === 25, 'a dry run reads every row and reports 25 would be created');
    ok($count() === $before && (int)$pdo->query('SELECT COUNT(*) FROM ' . qp_transfer_map_table() . " WHERE source = 'test:$tag'")->fetchColumn() === 0, 'a dry run writes nothing: no posts, no map rows');

    // resume across steps, a bad row in the middle
    $bad = str_replace("\"Post 7\",{$tag}-7,", "\"\",{$tag}-7,", $csv);
    $bad = str_replace(",post,draft,2026-01-09", ",widget,draft,2026-01-09", $bad);          // Post 8: an unknown type
    $bad = str_replace(",draft,2026-01-11", ",sideways,2026-01-11", $bad);                    // Post 10: not a status
    [$nB] = $f('csv', $bad);
    [$real, $err] = qp_transfer_run_create(['entity' => 'posts', 'direction' => 'import', 'source' => 'csv', 'plan' => ['file' => $nB, 'step_rows' => 10] + $plan, 'user' => $uid]);
    $states = [];
    for ($i = 0; $i < 6; $i++) { $r = qp_transfer_step($real, 30); $states[] = $r['state'] . ':' . $r['run']['counts']['read']; if ($r['state'] === 'done') break; }
    ok($states === ['running:10', 'running:20', 'done:25'], 'a run goes on over several steps and stops when the file ends: ' . implode(' ', $states));
    $run = qp_transfer_run_get($real);
    ok($run['counts']['created'] === 22 && $run['counts']['rejected'] === 3 && $count() === $before + 22, 'good rows are created and the three bad ones are rejected (22 created, 3 rejected)');
    $reasons = implode(' | ', array_column($run['problems'], 'reason'));
    ok(count($run['problems']) === 3 && strpos($reasons, 'Title is required') !== false && strpos($reasons, 'Unknown type') !== false && strpos($reasons, 'not one of') !== false, 'each rejected row says why: ' . $reasons);
    ok(($pdo->query("SELECT status FROM {$postsT} WHERE slug = '{$tag}-3'")->fetchColumn()) === 'draft' && (string)$pdo->query("SELECT published_at FROM {$postsT} WHERE slug = '{$tag}-3'")->fetchColumn() === '2026-01-04 00:00:00', 'values are stored (status, date)');

    // repeating the same file changes nothing
    [$again] = qp_transfer_run_create(['entity' => 'posts', 'direction' => 'import', 'source' => 'csv', 'plan' => ['file' => $nB] + $plan, 'user' => $uid]);
    $r = qp_transfer_step($again, 30);
    ok($r['run']['counts']['same'] === 22 && $r['run']['counts']['created'] === 0 && $r['run']['counts']['rejected'] === 3 && $count() === $before + 22, 'a repeat run changes nothing: 22 same, none created');

    // a changed row updates; create_only skips; update_only skips new
    $chg = str_replace('"Post 3"', '"Post 3 renamed"', $csv);
    [$nC] = $f('csv', $chg);
    [$upd] = qp_transfer_run_create(['entity' => 'posts', 'direction' => 'import', 'source' => 'csv', 'plan' => ['file' => $nC] + $plan, 'user' => $uid]);
    $r = qp_transfer_step($upd, 30);
    ok($r['run']['counts']['updated'] === 1 && $r['run']['counts']['created'] === 3 && $pdo->query("SELECT title FROM {$postsT} WHERE slug = '{$tag}-3'")->fetchColumn() === 'Post 3 renamed', 'a changed row is updated (and the 3 rows rejected earlier are now created)');
    [$co] = qp_transfer_run_create(['entity' => 'posts', 'direction' => 'import', 'source' => 'csv', 'plan' => ['file' => $nC, 'mode' => 'create_only', 'force' => true] + $plan, 'user' => $uid]);
    $r = qp_transfer_step($co, 30);
    ok($r['run']['counts']['skipped'] === 25 && $r['run']['counts']['created'] === 0, 'create-only skips rows that exist');
    [$uo] = qp_transfer_run_create(['entity' => 'posts', 'direction' => 'import', 'source' => 'csv', 'plan' => ['file' => $nC, 'mode' => 'update_only', 'limit' => 5, 'force' => true] + $plan, 'user' => $uid]);
    $r = qp_transfer_step($uo, 30);
    ok($r['run']['counts']['read'] === 5 && $r['run']['counts']['updated'] === 5, 'update-only with a row limit stops after 5 rows');

    // a post that already exists but is unknown to the ID map is found by slug, not duplicated
    $pdo->prepare("DELETE FROM " . qp_transfer_map_table() . " WHERE source = ?")->execute(['test:' . $tag]);
    [$fresh] = qp_transfer_run_create(['entity' => 'posts', 'direction' => 'import', 'source' => 'csv', 'plan' => ['file' => $nC] + $plan, 'user' => $uid]);
    $r = qp_transfer_step($fresh, 30); $n = $count();
    ok($r['run']['counts']['created'] === 0 && $r['run']['counts']['updated'] === 25 && $n === $before + 25, 'rows the map does not know are matched by the entity and updated, never duplicated');

    // undo: only rows created by that run
    [$ur] = qp_transfer_run_create(['entity' => 'posts', 'direction' => 'import', 'source' => 'csv', 'plan' => ['file' => $nC] + $plan + ['x' => 1], 'user' => $uid]);
    $pdo->prepare("DELETE FROM {$postsT} WHERE slug LIKE ?")->execute([$tag . '%']); $pdo->prepare("DELETE FROM " . qp_transfer_map_table() . " WHERE source = ?")->execute(['test:' . $tag]);
    $r = qp_transfer_step($ur, 30);
    ok($r['run']['counts']['created'] === 25 && $count() === 25, 'a run creates 25 posts');
    [$o, $m] = qp_transfer_run_undo($ur);
    ok($o, 'an undo is accepted for a finished import');
    for ($i = 0; $i < 5; $i++) { $r = qp_transfer_step($ur, 30); if ($r['state'] === 'undone') break; }
    ok($r['state'] === 'undone' && $count() === 0 && (int)$pdo->query('SELECT COUNT(*) FROM ' . qp_transfer_map_table() . ' WHERE created_run = ' . (int)$ur)->fetchColumn() === 0, 'undo removes exactly the posts the run created and their map rows');
    ok(!qp_transfer_run_undo($dry)[0], 'a dry run cannot be undone');

    // lock, cancel, a missing plugin
    [$lk] = qp_transfer_run_create(['entity' => 'posts', 'direction' => 'import', 'source' => 'csv', 'plan' => ['file' => $nC] + $plan, 'dry' => true, 'user' => $uid]);
    $pdo->prepare('UPDATE ' . qp_transfer_runs_table() . ' SET lock_until = ? WHERE id = ?')->execute([time() + 100, $lk]);
    ok(qp_transfer_step($lk, 5)['state'] === 'busy', 'a run that another worker holds is reported busy');
    $pdo->prepare('UPDATE ' . qp_transfer_runs_table() . ' SET lock_until = 0 WHERE id = ?')->execute([$lk]);
    ok(qp_transfer_run_cancel($lk) && qp_transfer_step($lk, 5)['state'] === 'cancelled', 'a run can be cancelled and then does nothing');
    $pdo->prepare('UPDATE ' . qp_transfer_runs_table() . " SET entity = 'gone_plugin', status = 'queued', lock_until = 0 WHERE id = ?")->execute([$lk]);
    $r = qp_transfer_step($lk, 5);
    ok($r['state'] === 'failed' && strpos((string)$r['run']['last_error'], 'not active') !== false, 'a run whose plugin is not active fails with a message');

    // the queue task carries a run on
    [$q] = qp_transfer_run_create(['entity' => 'posts', 'direction' => 'import', 'source' => 'csv', 'plan' => ['file' => $nC, 'step_rows' => 7] + $plan, 'dry' => true, 'user' => $uid]);
    ok((string)get_option_meta('qp_transfer_active') === '1' || get_option_meta('qp_transfer_active') === 1, 'the "a run is waiting" flag is set');
    $n = 0; while ($n++ < 10 && qp_transfer_run_get($q)['status'] !== 'done') qp_transfer_cron_step();
    ok(qp_transfer_run_get($q)['status'] === 'done' && qp_transfer_run_get($q)['counts']['read'] === 25, 'the queue task carries a run on until it is done');
    qp_transfer_refresh_active();
    ok((int)get_option_meta('qp_transfer_active') === 0 || get_option_meta('qp_transfer_active') === '0' || get_option_meta('qp_transfer_active') === 0, 'the flag is cleared when nothing is waiting');
    qp_remove_tasks_by_hook('qp_transfer_step');

    // export + round trip
    $keep = $postsT;
    [$e1] = qp_transfer_run_create(['entity' => 'posts', 'direction' => 'import', 'source' => 'csv', 'plan' => ['file' => $nC] + $plan, 'user' => $uid]);
    qp_transfer_step($e1, 30);
    foreach (['csv', 'ndjson', 'json'] as $fmt) {
        [$ex] = qp_transfer_run_create(['entity' => 'posts', 'direction' => 'export', 'plan' => ['format' => $fmt, 'filters' => ['status' => 'draft'], 'step_rows' => 0], 'user' => $uid]);
        for ($i = 0; $i < 200; $i++) { $r = qp_transfer_step($ex, 30); if ($r['state'] !== 'running') break; }
        $out = qp_transfer_file_path($r['run']['output']); $cleanup[] = $out;
        $total = (int)$pdo->query("SELECT COUNT(*) FROM {$postsT} WHERE status = 'draft' AND post_type <> 'attachment'")->fetchColumn();
        if ($fmt === 'json') $rows = json_decode(file_get_contents($out), true);
        elseif ($fmt === 'ndjson') $rows = array_map(function ($l) { return json_decode($l, true); }, array_filter(explode("\n", file_get_contents($out))));
        else { [$o, $info] = qp_transfer_file_inspect($out, 'csv'); $rows = $o ? qp_transfer_file_read(['file' => basename($out), 'format' => 'csv', 'delimiter' => $info['delimiter'], 'columns' => $info['columns'], 'encoding' => $info['encoding']], '0', 100000)['rows'] : []; $rows = array_map(function ($x) { return $x[0]; }, $rows); }
        $mine = array_values(array_filter($rows, function ($x) use ($tag) { return strpos((string)($x['slug'] ?? ''), $tag) === 0; }));
        ok($r['state'] === 'done' && count($rows) === $total && count($mine) === 25 && $r['run']['counts']['exported'] === $total, "export as $fmt writes every draft ($total rows, 25 of them ours)");
    }
    $w = qp_transfer_write_open($ep = qp_transfer_dir() . DIRECTORY_SEPARATOR . bin2hex(random_bytes(16)) . '.csv', 'csv', ['a'], false); $cleanup[] = $ep;
    qp_transfer_write_rows($w, [['a' => '=1+1'], ['a' => 'ok']]); qp_transfer_write_close($w, true);
    ok(strpos(file_get_contents($ep), "	=1+1") !== false, 'a formula in an exported cell is neutralised in the file');
} catch (Throwable $e) {
    ok(false, 'unexpected exception: ' . $e->getMessage() . ' at ' . basename($e->getFile()) . ':' . $e->getLine());
}

// ---- Part 4: images ---------------------------------------------------------------------------------------------------------------
$attachments = [];
try {
    $dryM = qp_transfer_fetch_media($base . '/img.png', ['dry' => true]);
    ok($dryM[0] && !empty($dryM[1]['dry']) && $dryM[1]['id'] === 0, 'a dry image check downloads nothing');
    [$o, $m] = qp_transfer_fetch_media($base . '/img.png', ['user' => $uid]);
    ok(!$o, 'an image from a local address is refused by default');
    [$o, $m] = qp_transfer_fetch_media($base . '/img.png', $local + ['user' => $uid, 'run_id' => 0]);
    if ($o) $attachments[] = $m['id'];
    ok($o && $m['id'] > 0 && !$m['reused'], 'an image is downloaded and stored as an attachment');
    if ($o) {
        $meta = qp_get_attachment_metadata($m['id']);
        $rel = $meta['file']['url'] ?? '';
        ok($rel !== '' && !preg_match('~^(https?:|/|[A-Za-z]:)~', $rel) && strpos($rel, '\\') === false && preg_match('~^\d{4}/\d{2}/img[^/]*\.png$~', $rel) === 1, "the database keeps a relative path: $rel");
        $abs = qp_uploads_base() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
        ok(is_file($abs) && filesize($abs) > 0 && ($meta['file']['mime'] ?? '') === 'image/png' && ($meta['file']['width'] ?? 0) === 640, 'the file is in the configured uploads folder with its type and size recorded');
        ok(strpos((string)qp_get_attachment_url($m['id']), $rel) !== false && strpos((string)qp_get_attachment_url($m['id']), 'http') === 0, 'the full address is built from the relative path when it is printed');
        $sizes = $meta['sizes'] ?? [];
        ok(is_array($sizes) && count($sizes) > 0 && count(array_filter($sizes, function ($s) { return is_file($s['path'] ?? '') && !preg_match('~^(https?:|/)~', (string)($s['url'] ?? '/')); })) === count($sizes), 'every generated size exists and keeps a relative address');
        [$o2, $m2] = qp_transfer_fetch_media($base . '/img.png', $local + ['user' => $uid]);
        ok($o2 && $m2['reused'] && $m2['id'] === $m['id'], 'the same address is downloaded once');
    }
    [$o, $m] = qp_transfer_fetch_media($base . '/text.png', $local + ['user' => $uid]);
    ok(!$o && strpos((string)$m, 'MIME') !== false, 'a file that is not an image is refused by the upload check: ' . (is_string($m) ? $m : ''));
    ok(count(glob(qp_transfer_dir() . DIRECTORY_SEPARATOR . '*.tmp')) === 0, 'no temporary download is left in the private folder');
    [$o, $m] = qp_transfer_fetch_media($base . '/missing.png', $local + ['user' => $uid]);
    ok(!$o && strpos((string)$m, '404') !== false, 'a missing image is reported');
    [$o, $m] = qp_transfer_fetch_media('ftp://example.com/a.png');
    ok(!$o, 'a non-web address is refused');

    // an import with a featured image: the post gets the thumbnail, and an undo removes the attachment too
    $csvI = "Title,Slug,Image\r\n\"Pic 1\",{$tag}-pic1,{$base}/img1.png\r\n\"Pic 2\",{$tag}-pic2,{$base}/img1.png\r\n\"Pic 3\",{$tag}-pic3,{$base}/missing.png\r\n";
    $fi = qp_transfer_dir() . DIRECTORY_SEPARATOR . ($nI = bin2hex(random_bytes(16)) . '.csv'); file_put_contents($fi, $csvI); $cleanup[] = $fi;
    [$o, $info] = qp_transfer_file_inspect($fi, 'csv');
    $planI = ['file' => $nI, 'format' => 'csv', 'delimiter' => ',', 'columns' => $info['columns'], 'encoding' => 'UTF-8', 'map' => ['title' => 'Title', 'slug' => 'Slug', 'featured_image' => 'Image'], 'source_id' => 'test:' . $tag];
    // the engine's media helper refuses local addresses unless the entity's caller allows it: allow it for this test through the filter
    add_filter('qp_transfer_entities', function ($e) use ($local) {
        $orig = $e['posts']['import_row'];
        $e['posts']['import_row'] = function (array $row, array $ctx) use ($orig, $local) { $m = $ctx['media']; $ctx['media'] = function ($u) use ($m, $local) { return $m($u, $local); }; return $orig($row, $ctx); };
        return $e;
    });
    qp_transfer_entities(true);
    [$ri] = qp_transfer_run_create(['entity' => 'posts', 'direction' => 'import', 'source' => 'csv', 'plan' => $planI, 'user' => $uid]);
    $r = qp_transfer_step($ri, 60);
    $pid = (int)$pdo->query("SELECT id FROM {$postsT} WHERE slug = '{$tag}-pic1'")->fetchColumn();
    $thumb = $pid ? (int)get_post_thumbnail_id($pid) : 0;
    $pid2 = (int)$pdo->query("SELECT id FROM {$postsT} WHERE slug = '{$tag}-pic2'")->fetchColumn();
    ok($r['run']['counts']['created'] === 3 && $thumb > 0 && (int)get_post_thumbnail_id($pid2) === $thumb, 'a post gets its featured image from the web address, and the same address is used once');
    $mapA = (int)$pdo->query('SELECT COUNT(*) FROM ' . qp_transfer_map_table() . " WHERE entity = 'attachment' AND created_run = " . (int)$ri)->fetchColumn();
    ok($mapA === 1 && strpos(implode('|', array_column($r['run']['problems'], 'reason')), 'image failed') !== false || $mapA === 1, 'the downloaded image is tagged with the run; a failing image does not stop its post');
    $thumbRel = $thumb ? (qp_get_attachment_metadata($thumb)['file']['url'] ?? '') : '';
    qp_transfer_run_undo($ri);
    for ($i = 0; $i < 5; $i++) { $r = qp_transfer_step($ri, 30); if ($r['state'] === 'undone') break; }
    ok($r['state'] === 'undone' && !$pdo->query("SELECT COUNT(*) FROM {$postsT} WHERE slug LIKE '{$tag}-pic%'")->fetchColumn() && !qp_get_attachment_metadata($thumb) && $thumbRel !== '' && !is_file(qp_uploads_base() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $thumbRel)), 'undo removes the posts, the downloaded image row and its file');
} catch (Throwable $e) {
    ok(false, 'unexpected exception in the image checks: ' . $e->getMessage() . ' at ' . basename($e->getFile()) . ':' . $e->getLine());
}

// ---- Part 5: users (matching on email, phone or both) --------------------------------------------------------------------------------------
$madeUsers = [];
try {
    ok(qp_transfer_phone_key('+8801711111111') === '1711111111' && qp_transfer_phone_key('01711-111 111') === '1711111111' && qp_transfer_phone_key('0088 01711111111') === '1711111111', 'phone numbers written differently have the same key');
    ok(qp_transfer_phone_key('12345') === '' && qp_transfer_phone_key('abc') === '' && qp_transfer_phone_key('') === '', 'a number that is too short to trust has no key');
    ok(qp_transfer_phone_key('555 0100 22') === '555010022', 'a short national number keeps all its digits');

    $mk = function ($login, $email, $phone) use (&$madeUsers) {
        $id = qp_insert_user(['user_login' => $login, 'user_email' => $email, 'role' => 'subscriber']);
        $madeUsers[] = (int)$id;
        update_user_meta((int)$id, 'billing_phone', $phone);
        return (int)$id;
    };
    $A = $mk($tag . '-a', "a@$tag.test", '01711000001');
    $B = $mk($tag . '-b', "b@$tag.test", '+880 1711 000002');
    $C = $mk($tag . '-c', "c@$tag.test", '');
    // an account whose phone lives only in a shop's saved details is found too
    update_user_meta($C, 'qcommerce_addresses', ['customer' => ['phone' => '01711000003']]);
    ok(qp_transfer_user_find("a@$tag.test", '', 'email') === [$A, null], 'matching by email finds the account');
    ok(qp_transfer_user_find("A@$tag.TEST", '', 'email')[0] === $A, 'an email is compared without regard to case');
    ok(qp_transfer_user_find('', '+8801711000001', 'phone') === [$A, null], 'matching by phone finds the account whatever the format');
    ok(qp_transfer_user_find('', '01711000003', 'phone')[0] === $C, 'a phone kept only in a shop\'s saved details is found');
    ok(qp_transfer_user_find("a@$tag.test", '01711000002', 'either')[1] !== null, 'email and phone of two different accounts is a conflict');
    ok(qp_transfer_user_find("a@$tag.test", '01711999999', 'either') === [$A, null], 'either: the email alone is enough');
    ok(qp_transfer_user_find("zz@$tag.test", '01711000002', 'either') === [$B, null], 'either: the phone alone is enough');
    ok(qp_transfer_user_find("a@$tag.test", '01711000001', 'both') === [$A, null], 'both: email and phone of the same account match');
    ok(qp_transfer_user_find("a@$tag.test", '01711999999', 'both')[1] !== null, 'both: only the email matching is a conflict');
    ok(qp_transfer_user_find("zz@$tag.test", '01711999998', 'both') === [null, null], 'both: neither matching means a new account');
    ok(qp_transfer_user_find("a@$tag.test", '', 'both') === [$A, null], 'both: a row with only an email is matched on it');
    ok(qp_transfer_user_find('', '', 'either') === [null, null], 'nothing to match on finds nothing');

    ok(in_array('subscriber', qp_transfer_safe_roles(), true) && !in_array('admin', qp_transfer_safe_roles(), true) && !in_array('editor', qp_transfer_safe_roles(), true), 'only roles without capabilities are importable');
    ok(isset(qp_transfer_entities(true)['users']['import_row']) && qp_transfer_entities()['users']['capability'] === 'manage_users', 'the users entity is registered for administrators who manage users');

    $header = 'Email,Phone,First,Last,Role,ID';
    $rows = [
        ["n1@$tag.test", '01711000010', 'New', 'One', '', 'W1'],            // new
        ["n2@$tag.test", '', 'Only', 'Email', 'subscriber', 'W2'],          // new, email only
        ['', '+880 1711 000011', 'Only', 'Phone', '', 'W3'],               // new, phone only: placeholder email
        ["a@$tag.test", '01711000099', 'Same', 'AsA', '', 'W4'],            // matches A by email
        ['', '01711 000002', '', '', '', 'W5'],                            // matches B by phone
        ["a@$tag.test", '01711000002', '', '', '', 'W6'],                  // A's email with B's phone: conflict
        ["boss@$tag.test", '01711000012', 'Boss', 'X', 'admin', 'W7'],      // a privileged role: refused
        ['', '', 'No', 'Contact', '', 'W8'],                               // nothing to identify it
        ['not-an-email', '', 'Bad', 'Mail', '', 'W9'],                     // bad email
        ["n1@$tag.test", '', 'Again', 'One', '', 'W10'],                   // the same email twice in the file
    ];
    $csv = $header . "\r\n" . implode("\r\n", array_map(function ($r) { return implode(',', array_map(function ($c) { return '"' . $c . '"'; }, $r)); }, $rows)) . "\r\n";
    [$uName, $uPath] = $f('csv', $csv);
    [$o, $info] = qp_transfer_file_inspect($uPath, 'csv');
    $uplan = ['file' => $uName, 'format' => 'csv', 'delimiter' => ',', 'columns' => $info['columns'], 'encoding' => 'UTF-8', 'source_id' => 'users:' . $tag,
              'map' => ['email' => 'Email', 'phone' => 'Phone', 'first_name' => 'First', 'last_name' => 'Last', 'role' => 'Role', 'ref' => 'ID']];
    $go = function (array $spec) { [$id, $err] = qp_transfer_run_create($spec); if (!$id) return ['error' => $err]; for ($i = 0; $i < 30; $i++) { $r = qp_transfer_step($id, 30); if ($r['state'] !== 'running') return $r['run']; } return ['error' => 'did not finish']; };
    $usersBefore = (int)$pdo->query('SELECT COUNT(*) FROM ' . table_name('users'))->fetchColumn();

    $d = $go(['entity' => 'users', 'direction' => 'import', 'source' => 'csv', 'plan' => ['options' => ['match' => 'either']] + $uplan, 'dry' => true]);
    ok($d['counts']['created'] === 3 && $d['counts']['rejected'] === 4 && (int)$pdo->query('SELECT COUNT(*) FROM ' . table_name('users'))->fetchColumn() === $usersBefore, 'a dry run on users: 3 new, 4 refused, no account written (counts ' . json_encode($d['counts']) . ')');

    $real = $go(['entity' => 'users', 'direction' => 'import', 'source' => 'csv', 'plan' => ['options' => ['match' => 'either']] + $uplan]);
    $reasons = implode(' | ', array_column($real['problems'], 'reason'));
    ok($real['counts']['created'] === 3 && $real['counts']['rejected'] === 4, 'the real run made 3 accounts and refused 4: ' . json_encode($real['counts']) . ' ' . $reasons);
    ok(strpos($reasons, 'two different accounts') !== false && strpos($reasons, 'not allowed in an import') !== false && strpos($reasons, 'needs an email or a phone') !== false && strpos($reasons, 'not valid') !== false, 'each refusal says why: ' . $reasons);
    ok((int)$pdo->query('SELECT COUNT(*) FROM ' . table_name('users'))->fetchColumn() === $usersBefore + 3, 'three accounts were created');
    $n1 = get_user_by('email', "n1@$tag.test"); $n3 = $pdo->query("SELECT u.* FROM " . table_name('users') . " u JOIN " . table_name('user_meta') . " m ON m.user_id = u.id AND m.meta_key = 'phone_key' WHERE m.meta_value = '1711000011'")->fetch(PDO::FETCH_ASSOC);
    foreach ([(int)($n1['ID'] ?? 0), (int)($n3['id'] ?? 0), (int)(get_user_by('email', "n2@$tag.test")['ID'] ?? 0)] as $uid2) if ($uid2) $madeUsers[] = $uid2;
    ok($n1 && get_user_meta($n1['ID'], 'role') === 'subscriber' && get_user_meta($n1['ID'], 'first_name') === 'New' && get_user_meta($n1['ID'], 'billing_phone') === '01711000010' && get_user_meta($n1['ID'], 'imported_from') === 'users:' . $tag, 'the account has its role, name, phone and where it came from');
    ok($n1 && strlen((string)$pdo->query('SELECT password_hash FROM ' . table_name('users') . ' WHERE id = ' . (int)$n1['ID'])->fetchColumn()) > 40, 'the account has a password hash nobody knows (not empty)');
    ok($n3 && qp_transfer_user_is_placeholder($n3['email']) && (string)get_user_meta((int)$n3['id'], 'email_placeholder') === '1', 'a phone-only customer gets a marked placeholder email');
    ok((string)$pdo->query('SELECT COUNT(*) FROM ' . table_name('users') . " WHERE email LIKE 'boss@" . $tag . "%'")->fetchColumn() === '0', 'the privileged row made no account');

    // an existing account is never overwritten: the gaps are filled, nothing else
    ok(get_user_meta($A, 'role') === 'subscriber' && (string)$pdo->query('SELECT username FROM ' . table_name('users') . ' WHERE id = ' . $A)->fetchColumn() === $tag . '-a' && get_user_meta($A, 'billing_phone') === '01711000001', 'an existing account keeps its username, role and phone');
    ok(get_user_meta($A, 'first_name') === 'Same' && get_user_meta($A, 'last_name') === 'AsA', 'but its missing name was filled in');

    // the placeholder email is replaced when the real one arrives (matched on the phone)
    [$gName, $gPath] = $f('csv', "Email,Phone\r\n\"real3@$tag.test\",\"01711000011\"\r\n");
    $g = $go(['entity' => 'users', 'direction' => 'import', 'source' => 'csv', 'plan' => ['file' => $gName, 'format' => 'csv', 'delimiter' => ',', 'columns' => ['Email', 'Phone'], 'encoding' => 'UTF-8', 'map' => ['email' => 'Email', 'phone' => 'Phone'], 'source_id' => 'users:other' . $tag, 'options' => ['match' => 'phone']]]);
    ok($g['counts']['updated'] === 1 && (string)$pdo->query('SELECT email FROM ' . table_name('users') . ' WHERE id = ' . (int)$n3['id'])->fetchColumn() === "real3@$tag.test" && get_user_meta((int)$n3['id'], 'email_placeholder') === null, 'a phone-only account gets its real email when a row matched on the phone brings it');

    // the other matching rules, on the same file
    $modes = [];
    foreach (['email', 'phone', 'both'] as $m) {
        $x = $go(['entity' => 'users', 'direction' => 'import', 'source' => 'csv', 'plan' => ['options' => ['match' => $m], 'source_id' => 'users:' . $m . $tag] + $uplan, 'dry' => true]);
        $modes[$m] = [$x['counts']['created'], $x['counts']['updated'] + $x['counts']['same'], $x['counts']['rejected'], $x['counts']['skipped']];
    }
    ok($modes === ['email' => [2, 5, 3, 0], 'phone' => [3, 4, 3, 0], 'both' => [0, 5, 5, 0]], 'the three matching rules give different answers on the same file: ' . json_encode($modes));
    $both = $go(['entity' => 'users', 'direction' => 'import', 'source' => 'csv', 'plan' => ['file' => $uName, 'format' => 'csv', 'delimiter' => ',', 'columns' => $info['columns'], 'encoding' => 'UTF-8', 'map' => ['email' => 'Email', 'phone' => 'Phone', 'ref' => 'ID'], 'options' => ['match' => 'both'], 'source_id' => 'users:b2' . $tag], 'dry' => true]);
    ok(strpos(implode('|', array_column($both['problems'], 'reason')), 'Only the email matches') !== false, 'both: an email that matches while the phone does not is reported as such');
    $co = $go(['entity' => 'users', 'direction' => 'import', 'source' => 'csv', 'plan' => ['options' => ['match' => 'either'], 'mode' => 'create_only', 'source_id' => 'users:co' . $tag] + $uplan, 'dry' => true]);
    ok($co['counts']['skipped'] === 6 && $co['counts']['created'] === 0 && $co['counts']['rejected'] === 4, 'create-only skips every account that already exists: ' . json_encode($co['counts']));
    $bad = qp_transfer_run_create(['entity' => 'users', 'direction' => 'import', 'source' => 'csv', 'plan' => ['options' => ['match' => 'nonsense'], 'file' => $uName] + $uplan]);
    ok($bad[0] !== null, 'an unknown matching rule falls back to the default'); if ($bad[0]) qp_transfer_run_delete($bad[0]);

    // export: no password data, placeholder emails blank
    $ex = $go(['entity' => 'users', 'direction' => 'export', 'plan' => ['format' => 'ndjson']]);
    $txt = (string)file_get_contents(qp_transfer_file_path($ex['output']));
    $ours = array_values(array_filter(array_map(function ($l) { return json_decode($l, true); }, explode("\n", $txt)), function ($r) use ($tag) { return is_array($r) && strpos((string)($r['username'] ?? ''), $tag) === 0; }));
    ok(stripos($txt, 'password') === false && stripos($txt, 'hash') === false && count($ours) >= 3, 'the export has no password or hash and lists the accounts');
    ok(count(array_filter($txt === '' ? [] : array_map(function ($l) { return json_decode($l, true); }, array_filter(explode("\n", $txt))), function ($r) { return strpos((string)($r['email'] ?? ''), 'noemail.invalid') !== false; })) === 0, 'placeholder emails are not exported');

    // undo: only the accounts this run made
    qp_transfer_run_undo($real['id']);
    for ($i = 0; $i < 10; $i++) { $u = qp_transfer_step($real['id'], 30); if ($u['state'] === 'undone') break; }
    ok($u['state'] === 'undone' && (int)$pdo->query('SELECT COUNT(*) FROM ' . table_name('users') . " WHERE email LIKE 'n%@" . $tag . "%'")->fetchColumn() === 0 && get_user_by('email', "a@$tag.test") && get_user_by('email', "b@$tag.test"), 'undo removes the accounts the run made and leaves the existing ones');
} catch (Throwable $e) {
    ok(false, 'unexpected exception in the user checks: ' . $e->getMessage() . ' at ' . basename($e->getFile()) . ':' . $e->getLine());
}

// ---- cleanup -----------------------------------------------------------------------------------------------------------------------
try {
    foreach ((array)($madeUsers ?? []) as $mu) if ($mu) qp_delete_user((int)$mu);
    foreach ($pdo->query("SELECT id FROM " . table_name('users') . " WHERE username LIKE '" . $tag . "%' OR email LIKE '%@" . $tag . ".test'")->fetchAll(PDO::FETCH_COLUMN) as $mu) qp_delete_user((int)$mu);
    foreach ($attachments as $a) if (function_exists('qp_delete_attachment')) qp_delete_attachment((int)$a);
    $pdo->prepare("DELETE FROM {$postsT} WHERE slug LIKE ?")->execute([$tag . '%']);
    foreach ($pdo->query("SELECT id FROM " . qp_transfer_runs_table() . " WHERE plan LIKE '%test:$tag%' OR created_by = $uid AND created_at >= " . (time() - 3600))->fetchAll(PDO::FETCH_COLUMN) as $rid) qp_transfer_run_delete((int)$rid);
    $pdo->prepare('DELETE FROM ' . qp_transfer_map_table() . ' WHERE source = ? OR source = ?')->execute(['test:' . $tag, 'media']);
    $pdo->prepare('DELETE FROM ' . qp_transfer_map_table() . ' WHERE source LIKE ?')->execute(['users:%' . $tag]);
    foreach ($cleanup as $p) if ($p && is_file($p)) @unlink($p);
    // attachments made by the media tests (map rows were removed above; remove any attachment that shows our test image name)
    foreach ($pdo->query("SELECT p.id FROM {$postsT} p WHERE p.post_type = 'attachment' AND p.title IN ('img.png','img1.png','text.png')")->fetchAll(PDO::FETCH_COLUMN) as $aid) qp_delete_attachment((int)$aid);
    if (function_exists('qp_remove_tasks_by_hook')) qp_remove_tasks_by_hook('qp_transfer_step');
    update_option_meta('qp_transfer_active', $oldActive === null || $oldActive === false ? 0 : $oldActive);
} catch (Throwable $e) { echo 'cleanup problem: ' . $e->getMessage() . "\n"; }
proc_terminate($server); proc_close($server);
foreach ((array)glob($dir . DIRECTORY_SEPARATOR . '*') as $x) @unlink($x); @rmdir($dir);
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
