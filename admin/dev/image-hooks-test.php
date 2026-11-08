<?php
// Dev tool (C15): the image hooks for image optimiser plugins. CLI only.
//   php admin/dev/image-hooks-test.php [--base=https://host/path] [--resolve=127.0.0.1] [--no-http]
// Part 1 runs in this process: qp_attachment_created (C15a), qp_image_size_generated (C15b), qp_image_quality (C15d),
// qp_attachment_image_html (C15e), qp_filter_page_html (C15c). It uploads two small test images and deletes them again.
// Part 2 goes over real HTTP: a temporary plugin listens on qp_page_html, and the page must carry its mark exactly once with
// the page cache off, on a cache miss and on a cache hit. It restores the plugin list, the cache state and removes the plugin.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('QLOPY_INIT', true);
chdir(dirname(__DIR__, 2));
require 'db.php'; $pdo = db();
require 'auth.php';
$GLOBALS['config'] = $config;
$opt = getopt('', ['base::', 'resolve::', 'no-http']);
$pass = 0; $fail = 0;
function ok($c, $m) { global $pass, $fail; if ($c) $pass++; else $fail++; echo ($c ? 'PASS ' : 'FAIL ') . $m . "\n"; }
function hook_off(string $name): void { unset($GLOBALS['qlopy_filters'][$name]); }

/** A small test image in a temp file, as a $_FILES-like array for qp_handle_upload(['allow_local' => true]). */
function test_image(string $type): array {
    $im = imagecreatetruecolor(640, 480);
    for ($y = 0; $y < 480; $y += 8) for ($x = 0; $x < 640; $x += 8) imagefilledrectangle($im, $x, $y, $x + 7, $y + 7, imagecolorallocate($im, ($x * 7) % 256, ($y * 3) % 256, ($x + $y) % 256));
    $tmp = tempnam(sys_get_temp_dir(), 'c15');
    $type === 'png' ? imagepng($im, $tmp) : imagejpeg($im, $tmp, 95);
    imagedestroy($im);
    return ['tmp_name' => $tmp, 'name' => 'c15-hook-test.' . $type, 'size' => filesize($tmp), 'error' => 0, 'type' => $type === 'png' ? 'image/png' : 'image/jpeg'];
}

$created = [];
$authorId = (int) $pdo->query('SELECT MIN(id) FROM ' . table_name('users'))->fetchColumn();
register_shutdown_function(function () { $e = error_get_last(); if ($e && in_array($e['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR], true)) echo 'FATAL ' . $e['message'] . "
"; });
try {
    echo "-- C15a qp_attachment_created\n";
    $calls = [];
    add_action('qp_attachment_created', function ($id, $meta) use (&$calls) { $calls[] = [$id, $meta]; }, 10, 2);
    [$okUp, $res] = qp_handle_upload(test_image('jpg'), $authorId, ['allow_local' => true]);
    $jpgId = $okUp ? (int)$res['post_id'] : 0;
    if ($jpgId) $created[] = $jpgId;
    ok($okUp && $jpgId > 0, 'local file upload works' . ($okUp ? '' : ': ' . json_encode($res)));
    ok(count($calls) === 1 && $calls[0][0] === $jpgId, 'fires once with the new attachment id (' . count($calls) . ' calls)');
    ok(!empty($calls[0][1]['sizes']) && !empty($calls[0][1]['file']), 'passes the metadata with the sizes already made (' . implode(',', array_keys($calls[0][1]['sizes'] ?? [])) . ')');
    $allExist = true;
    foreach ($calls[0][1]['sizes'] ?? [] as $s) if (empty($s['path']) || !is_file($s['path'])) $allExist = false;
    ok($allExist, 'every size file exists when it fires');
    hook_off('qp_attachment_created');

    echo "-- C15b qp_image_size_generated\n";
    $gen = [];
    add_action('qp_image_size_generated', function ($id, $size, $path) use (&$gen) { $gen[] = [$id, $size, $path]; }, 10, 3);
    qp_add_image_size('c15_test_size', 211, 0, false);
    $src = qp_get_attachment_image_src($jpgId, 'c15_test_size');
    ok(is_array($src) && is_file($src[1]), 'an on-demand size is made');
    ok(count($gen) === 1 && $gen[0][0] === $jpgId && $gen[0][1] === 'c15_test_size' && $gen[0][2] === ($src[1] ?? null), 'fires once with id, size name and the final path');
    unset($GLOBALS['qp_attachment_cache']);
    qp_get_attachment_image_src($jpgId, 'c15_test_size');
    ok(count($gen) === 1, 'does not fire again for a size that already exists');
    $gen = [];
    qp_get_attachment_image_src($jpgId, 'medium');
    ok($gen === [], 'does not fire for a size made at upload');
    hook_off('qp_image_size_generated');
    unset($GLOBALS['qp_image_sizes']['c15_test_size']);

    echo "-- C15d qp_image_quality\n";
    $source = qp_get_attachment_path($jpgId);
    $tmpDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'c15q-' . bin2hex(random_bytes(3));
    mkdir($tmpDir);
    qp_generate_image_size($source, "$tmpDir/none.jpg", 300, 0);
    add_filter('qp_image_quality', function ($q) { return 85; });
    qp_generate_image_size($source, "$tmpDir/same.jpg", 300, 0);
    hook_off('qp_image_quality');
    ok(md5_file("$tmpDir/none.jpg") === md5_file("$tmpDir/same.jpg"), 'no listener = the old fixed quality (byte-identical to an explicit 85)');
    $seen = null;
    add_filter('qp_image_quality', function ($q, $mime, $dest) use (&$seen) { $seen = [$q, $mime, $dest]; return 40; }, 10, 3);
    qp_generate_image_size($source, "$tmpDir/low.jpg", 300, 0);
    hook_off('qp_image_quality');
    ok($seen === [85, 'image/jpeg', "$tmpDir/low.jpg"], 'gets the default, the mime type and the destination');
    ok(filesize("$tmpDir/low.jpg") < filesize("$tmpDir/none.jpg"), 'a lower quality makes a smaller file (' . filesize("$tmpDir/low.jpg") . ' < ' . filesize("$tmpDir/none.jpg") . ')');
    add_filter('qp_image_quality', function ($q) { return 500; });
    qp_generate_image_size($source, "$tmpDir/bad.jpg", 300, 0);
    hook_off('qp_image_quality');
    ok(md5_file("$tmpDir/bad.jpg") === md5_file("$tmpDir/none.jpg"), 'an out-of-range answer falls back to the default');
    [$okPng, $resPng] = qp_handle_upload(test_image('png'), $authorId, ['allow_local' => true]);
    if ($okPng) $created[] = (int)$resPng['post_id'];
    $pngSrc = $okPng ? qp_get_attachment_path((int)$resPng['post_id']) : null;
    if ($pngSrc) {
        qp_generate_image_size($pngSrc, "$tmpDir/none.png", 300, 0);
        $seenPng = null;
        add_filter('qp_image_quality', function ($q, $mime) use (&$seenPng) { $seenPng = [$q, $mime]; return 9; }, 10, 2);
        qp_generate_image_size($pngSrc, "$tmpDir/l9.png", 300, 0);
        add_filter('qp_image_quality', function ($q) { return 10; }, 20);   // PNG level is 0-9: 10 is out of range
        qp_generate_image_size($pngSrc, "$tmpDir/l10.png", 300, 0);
        hook_off('qp_image_quality');
        ok($seenPng === [6, 'image/png'], 'PNG gets its compression level (default 6)');
        ok(md5_file("$tmpDir/l10.png") === md5_file("$tmpDir/none.png"), 'PNG level out of range (10) falls back to 6');
    } else {
        ok(false, 'PNG test upload: ' . json_encode($resPng));
    }
    foreach (glob("$tmpDir/*") as $f) @unlink($f);
    @rmdir($tmpDir);

    echo "-- C15e qp_attachment_image_html\n";
    $plain = qp_get_attachment_image($jpgId, 'medium', ['alt' => 'x']);
    ok(preg_match('#^<img src="[^"]+" alt="x" width="\d+" height="\d+" />$#', $plain) === 1, 'no listener: the same tag as before (' . $plain . ')');
    $args = null;
    add_filter('qp_attachment_image_html', function ($html, $id, $size, $attr) use (&$args) { $args = [$id, $size, $attr]; return str_replace('<img ', '<img data-c15="1" ', $html); }, 10, 4);
    $f = qp_get_attachment_image($jpgId, 'medium', ['alt' => 'x']);
    hook_off('qp_attachment_image_html');
    ok(strpos($f, 'data-c15="1"') !== false && $args === [$jpgId, 'medium', ['alt' => 'x']], 'a listener changes the tag and gets id, size and attributes');

    echo "-- C15f qp_before_delete_attachment\n";
    [$okDel, $resDel] = qp_handle_upload(test_image('jpg'), $authorId, ['allow_local' => true]);
    $delId = $okDel ? (int)$resDel['post_id'] : 0;
    $seenDel = null;
    add_action('qp_before_delete_attachment', function ($id, $meta) use (&$seenDel) {
        $seenDel = ['id' => $id, 'meta' => is_array($meta), 'file_there' => is_file((string) qp_get_attachment_path((int) $id))];
    }, 10, 2);
    if ($delId) qp_delete_attachment($delId);
    hook_off('qp_before_delete_attachment');
    ok($seenDel === ['id' => $delId, 'meta' => true, 'file_there' => true], 'fires before the delete, with the metadata and the file still there');
    ok($delId && qp_get_attachment_metadata($delId) === null, 'the attachment is then deleted as before');

    echo "-- C15c qp_filter_page_html (in process)\n";
    $page = '<!doctype html><html><body><img src="a.png"></body></html>';
    ok(qp_filter_page_html($page) === $page, 'no listener: unchanged');
    $n = 0;
    add_filter('qp_page_html', function ($h) use (&$n) { $n++; return str_replace('</body>', '<!--c15-->' . '</body>', $h); });
    $p2 = $page . ' ';
    $a = qp_filter_page_html($p2); $b = qp_filter_page_html($p2);
    ok(substr_count($a, '<!--c15-->') === 1 && $a === $b && $n === 1, 'runs once for the same page, both callers get the same result (' . $n . ' runs)');
    ok(qp_filter_page_html($page, ['Content-Type: application/xml; charset=UTF-8']) === $page, 'a non-HTML response is left alone');
    ok(substr_count(qp_filter_page_html($page, ['Content-Type: text/html; charset=UTF-8']), '<!--c15-->') === 1, 'an HTML response is filtered');
    add_filter('qp_page_html', function ($h) { return ''; }, 20);
    ok(qp_filter_page_html($page, []) === $page, 'a listener that returns nothing cannot blank the page');
    hook_off('qp_page_html');
} finally {
    foreach ($created as $id) qp_delete_attachment($id);
}
ok(array_filter($created, fn($id) => qp_get_attachment_metadata($id) !== null) === [], 'test attachments removed');

// ---- Part 2: over HTTP, with a temporary listener plugin --------------------------------------------------------------------
if (!isset($opt['no-http'])) {
    require_once 'includes/page-cache.php';
    $base = rtrim($opt['base'] ?? $config['site_url'], '/');
    $host = parse_url($base, PHP_URL_HOST);
    $port = parse_url($base, PHP_URL_PORT) ?: (parse_url($base, PHP_URL_SCHEME) === 'https' ? 443 : 80);
    $resolve = $opt['resolve'] ?? '127.0.0.1';
    $req = function ($path) use ($base, $host, $port, $resolve) {
        $c = curl_init($base . '/' . ltrim($path, '/'));
        curl_setopt_array($c, [CURLOPT_RESOLVE => ["$host:$port:$resolve"], CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0, CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 30]);
        $r = curl_exec($c); $hs = curl_getinfo($c, CURLINFO_HEADER_SIZE); $code = curl_getinfo($c, CURLINFO_HTTP_CODE); curl_close($c);
        $head = substr((string)$r, 0, $hs); preg_match('/^X-QP-Cache:\s*(.*)$/mi', $head, $m);
        return ['code' => $code, 'cache' => trim($m[1] ?? ''), 'body' => (string)substr((string)$r, $hs)];
    };
    $optGet = function ($n) use ($pdo) { $st = $pdo->prepare('SELECT option_value FROM ' . table_name('site_options') . ' WHERE option_name = ?'); $st->execute([$n]); return $st->fetchColumn(); };
    $optSet = function ($n, $v) use ($pdo) { $pdo->prepare('UPDATE ' . table_name('site_options') . ' SET option_value = ? WHERE option_name = ?')->execute([$v, $n]); };

    $slug = 'c15-page-html-test';
    $dir = qp_plugins_path($slug);
    $origPlugins = $optGet('active_plugins');
    $cacheWasOn = qp_page_cache_enabled();
    $origState = qp_page_cache_state();
    @mkdir($dir, 0755, true);
    // marks the page with a per-request counter; declares itself cache-compatible
    file_put_contents($dir . 'plugin.php', "<?php\n/* Plugin Name: C15 page html test */\ndefined('QLOPY_INIT') || exit;\n"
        . "if (function_exists('qp_page_cache_declare_compatible')) qp_page_cache_declare_compatible('$slug');\n"
        . "add_filter('qp_page_html', function (\$h) { static \$n = 0; \$n++; return str_replace('</body>', '<!--c15-mark-' . \$n . '--></body>', \$h); });\n");
    try {
        $plugins = json_decode((string)$origPlugins, true) ?: [];
        $plugins[$slug] = true;
        $keep = array_diff_key($plugins, array_flip(qp_page_cache_incompatible_plugins()));
        $optSet('active_plugins', json_encode($keep));

        echo "-- C15c over HTTP, page cache off\n";
        qp_page_cache_enable(false);
        $r = $req('');
        ok($r['code'] === 200 && substr_count($r['body'], '<!--c15-mark-') === 1 && strpos($r['body'], '<!--c15-mark-1-->') !== false, 'home page carries the mark exactly once, from the first run (code ' . $r['code'] . ')');
        $x = $req('sitemap.xml');
        ok(strpos($x['body'], 'c15-mark') === false, 'sitemap.xml is not touched (code ' . $x['code'] . ')');

        echo "-- C15c over HTTP, page cache on\n";
        qp_page_cache_enable(true, ['trusted_plugins' => []]);
        qp_page_cache_purge_all();
        $req('sample-page');   // plugins load and declare themselves
        qp_page_cache_purge_all();
        $m1 = $req('sample-page');
        $m2 = $req('sample-page');
        ok(substr_count($m1['body'], '<!--c15-mark-') === 1, 'first request (' . $m1['cache'] . ') carries the mark exactly once');
        ok(strpos($m2['cache'], 'HIT') === 0, 'second request is a cache hit: ' . $m2['cache']);
        ok(substr_count($m2['body'], '<!--c15-mark-') === 1 && strpos($m2['body'], '<!--c15-mark-1-->') !== false, 'the hit serves the stored, already filtered page (filter not run again)');
        $stored = false;
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(qp_page_cache_dir(), FilesystemIterator::SKIP_DOTS)) as $f) {
            if ($f->isFile() && strpos((string)@file_get_contents($f->getPathname()), '<!--c15-mark-1-->') !== false) { $stored = true; break; }
        }
        ok($stored, 'the stored cache file holds the filtered page');
    } finally {
        $optSet('active_plugins', $origPlugins);
        qp_page_cache_purge_all();
        qp_page_cache_enable($cacheWasOn, ['trusted_plugins' => $origState['trusted_plugins'] ?? []]);
        @unlink($dir . 'plugin.php'); @rmdir($dir);
    }
    ok(!is_dir($dir) && $optGet('active_plugins') === $origPlugins && qp_page_cache_enabled() === $cacheWasOn, 'plugin list, cache state and test plugin restored');
}

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
