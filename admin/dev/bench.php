<?php
// admin/dev/bench.php - repeatable server-time benchmark for Qlopy (core upgrade plan, step C1a). CLI only.
//
// Needs 'profiler' => true in config.php (the profiler only answers requests that carry the secret header this
// script derives from security_salt, so it is safe to leave on a dev machine and must stay off on a live site).
//
// Examples:
//   php admin/dev/bench.php --base=https://aloelo.dev/orcasy --resolve=127.0.0.1 --label=before
//   php admin/dev/bench.php --base=https://aloelo.dev/orcasy --resolve=127.0.0.1 --label=after --baseline=admin/dev/bench-baseline.json
//   php admin/dev/bench.php ... --urls="home=/,post=/post/hello,shop=/shop/,cart=/cart/,admin=/admin/login.php"
//
// Options:
//   --base=URL         site address (default: site_url from config.php)
//   --resolve=IP       connect to this IP instead of DNS (the host name stays in the URL / TLS / Host header)
//   --urls=a=/x,b=/y   label=path pairs (default: home, 404, admin login)
//   --n=30             measured requests per URL      --warmup=3   discarded requests per URL
//   --label=text       name of this run in the table  --save=file  write results JSON (default: qlopy-bench-last.json in the system temp folder)
//   --baseline=file    compare with a saved JSON; exit 1 when a median is worse by more than --max-regress percent
//   --max-regress=15   allowed median regression in percent (and at least --min-ms=2 ms absolute)
//   --cookie="a=b"     send a cookie (for example a qp_token to measure a logged-in page)
//
// Server time is the profiler's own `total` (PHP time from the start of the request to shutdown), so network and TLS
// are out of it. One connection is reused per URL, so only the warmup pays the TLS handshake.

if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
if (!extension_loaded('curl')) { fwrite(STDERR, "The curl extension is required.\n"); exit(2); }

define('QLOPY_INIT', true);
$root = dirname(__DIR__, 2);
$config = require $root . '/config.php';
require_once $root . '/includes/profiler.php'; // for qp_profile_token()

$o = getopt('', ['base::', 'resolve::', 'urls::', 'n::', 'warmup::', 'label::', 'save::', 'baseline::', 'max-regress::', 'min-ms::', 'cookie::']);
$base = rtrim($o['base'] ?? $config['site_url'], '/');
$n = max(1, (int)($o['n'] ?? 30));
$warm = max(0, (int)($o['warmup'] ?? 3));
$label = $o['label'] ?? 'run';
$save = $o['save'] ?? sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'qlopy-bench-last.json';
$maxReg = (float)($o['max-regress'] ?? 15);
$minMs = (float)($o['min-ms'] ?? 2);
$token = qp_profile_token($config);

$urls = ['home' => '/', 'not_found' => '/qp-bench-does-not-exist', 'admin_login' => '/admin/login.php'];
if (!empty($o['urls'])) {
    $urls = [];
    foreach (explode(',', $o['urls']) as $pair) {
        $pair = trim($pair);
        if ($pair === '') continue;
        [$k, $v] = array_pad(explode('=', $pair, 2), 2, null);
        if ($v === null) { $v = $k; $k = trim($k, '/') ?: 'home'; }
        $urls[trim($k)] = trim($v);
    }
}

$host = parse_url($base, PHP_URL_HOST);
$port = parse_url($base, PHP_URL_PORT) ?: (parse_url($base, PHP_URL_SCHEME) === 'https' ? 443 : 80);

function pct(array $a, float $p): float {
    sort($a);
    if (!$a) return 0.0;
    $i = (int)ceil($p / 100 * count($a)) - 1;
    return $a[max(0, min(count($a) - 1, $i))];
}

function parse_headers(string $raw): array {
    $h = [];
    foreach (explode("\r\n", $raw) as $line) {
        if (strpos($line, ':') === false) continue;
        [$k, $v] = explode(':', $line, 2);
        $h[strtolower(trim($k))] = trim($v);
    }
    return $h;
}

$results = [];
foreach ($urls as $name => $path) {
    $ch = curl_init();
    $headers = ['X-QP-Profile: ' . $token];
    curl_setopt_array($ch, [
        CURLOPT_URL => $base . $path,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_ENCODING => 'gzip',
    ]);
    if (!empty($o['resolve'])) curl_setopt($ch, CURLOPT_RESOLVE, ["$host:$port:" . $o['resolve']]);
    if (!empty($o['cookie'])) curl_setopt($ch, CURLOPT_COOKIE, $o['cookie']);

    $server = $sqlN = $sqlMs = $wall = $mem = $files = $bytes = $ph = [];
    $status = 0;
    $profiled = true;
    for ($i = 0; $i < $warm + $n; $i++) {
        $res = curl_exec($ch);
        if ($res === false) { fwrite(STDERR, "$name: curl errno " . curl_errno($ch) . " [" . curl_error($ch) . "] http " . curl_getinfo($ch, CURLINFO_RESPONSE_CODE) . " i=$i
"); break; }
        if ($i < $warm) continue;
        $hs = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $h = parse_headers(substr($res, 0, $hs));
        $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $bytes[] = strlen(substr($res, $hs));
        $wall[] = (curl_getinfo($ch, CURLINFO_STARTTRANSFER_TIME) - curl_getinfo($ch, CURLINFO_PRETRANSFER_TIME)) * 1000;
        if (isset($h['server-timing']) && preg_match('/total;dur=([\d.]+)/', $h['server-timing'], $m)) {
            $server[] = (float)$m[1];
        } else {
            $profiled = false;
        }
        if (isset($h['server-timing']) && preg_match_all('/([a-z_]+);dur=([\d.]+)/i', $h['server-timing'], $mm, PREG_SET_ORDER)) { foreach ($mm as $x) $ph[$x[1]][] = (float)$x[2]; }
        if (isset($h['x-qp-sql'])) { [$c, $t] = array_pad(explode(';', $h['x-qp-sql']), 2, 0); $sqlN[] = (int)$c; $sqlMs[] = (float)$t; }
        if (isset($h['x-qp-mem'])) $mem[] = (int)$h['x-qp-mem'];
        if (isset($h['x-qp-files'])) $files[] = (int)$h['x-qp-files'];
    }
    curl_close($ch);

    if (!$server) {
        fwrite(STDERR, "$name: no profiler headers (is 'profiler' => true in config.php, and does PHP see the X-QP-Profile header?)\n");
        $results[$name] = ['path' => $path, 'status' => $status, 'error' => 'no profiler data'];
        continue;
    }
    $results[$name] = [
        'path' => $path,
        'status' => $status,
        'n' => count($server),
        'server_median_ms' => round(pct($server, 50), 2),
        'server_p95_ms' => round(pct($server, 95), 2),
        'server_min_ms' => round(min($server), 2),
        'wait_median_ms' => round(pct($wall, 50), 2),
        'sql_median' => $sqlN ? (int)pct($sqlN, 50) : null,
        'sql_median_ms' => $sqlMs ? round(pct($sqlMs, 50), 2) : null,
        'files_median' => $files ? (int)pct($files, 50) : null,
        'mem_peak_mb' => $mem ? round(max($mem) / 1048576, 1) : null,
        'bytes' => $bytes ? (int)pct($bytes, 50) : null,
        'phases' => array_map(fn($v) => round(pct($v, 50), 2), $ph),
    ];
}

// ---- report
$line = fn(array $cells) => '| ' . implode(' | ', $cells) . ' |';
echo "\nBenchmark `$label` - " . date('Y-m-d H:i') . ' - ' . $base . " - $n requests per URL after $warm warmup, one kept-alive connection\n\n";
echo $line(['page', 'status', 'median ms', 'p95 ms', 'min ms', 'SQL (n / ms)', 'files', 'peak MB', 'bytes']) . "\n";
echo $line(array_fill(0, 9, '---')) . "\n";
foreach ($results as $name => $r) {
    if (isset($r['error'])) { echo $line([$name, $r['status'], 'n/a', '', '', '', '', '', '']) . "\n"; continue; }
    echo $line([$name, $r['status'], $r['server_median_ms'], $r['server_p95_ms'], $r['server_min_ms'],
        ($r['sql_median'] ?? '?') . ' / ' . ($r['sql_median_ms'] ?? '?'), $r['files_median'] ?? '?', $r['mem_peak_mb'] ?? '?', $r['bytes'] ?? '?']) . "\n";
}

$phaseNames = [];
foreach ($results as $r) foreach (array_keys($r['phases'] ?? []) as $k) $phaseNames[$k] = true;
unset($phaseNames['total'], $phaseNames['sql']);
echo "\nMedian ms per phase (after_last_mark = template and output after routing; sql overlaps the phases):\n\n";
echo $line(array_merge(['page'], array_keys($phaseNames), ['sql'])) . "\n" . $line(array_fill(0, count($phaseNames) + 2, '---')) . "\n";
foreach ($results as $name => $r) {
    if (isset($r['error'])) continue;
    $cells = [$name];
    foreach (array_keys($phaseNames) as $k) $cells[] = $r['phases'][$k] ?? '';
    $cells[] = $r['phases']['sql'] ?? '';
    echo $line($cells) . "\n";
}

@file_put_contents($save, json_encode(['label' => $label, 'time' => date('c'), 'base' => $base, 'results' => $results], JSON_PRETTY_PRINT));
echo "\nSaved $save\n";

// ---- budget check against a baseline
$exit = 0;
if (!empty($o['baseline'])) {
    $b = json_decode((string)@file_get_contents($o['baseline']), true);
    if (!is_array($b) || empty($b['results'])) { fwrite(STDERR, "Baseline unreadable: {$o['baseline']}\n"); exit(2); }
    echo "\nCompared with baseline `" . ($b['label'] ?? '?') . "` (allowed regression {$maxReg}% and at least {$minMs} ms):\n\n";
    echo $line(['page', 'baseline ms', 'now ms', 'change', 'SQL before / now', 'verdict']) . "\n" . $line(array_fill(0, 6, '---')) . "\n";
    foreach ($results as $name => $r) {
        $old = $b['results'][$name] ?? null;
        if (!$old || isset($old['error']) || isset($r['error'])) { echo $line([$name, '-', '-', '-', '-', 'skipped']) . "\n"; continue; }
        $delta = $r['server_median_ms'] - $old['server_median_ms'];
        $pctv = $old['server_median_ms'] > 0 ? $delta / $old['server_median_ms'] * 100 : 0;
        $bad = ($pctv > $maxReg && $delta > $minMs);
        if ($bad) $exit = 1;
        echo $line([$name, $old['server_median_ms'], $r['server_median_ms'], sprintf('%+.1f%%', $pctv),
            ($old['sql_median'] ?? '?') . ' / ' . ($r['sql_median'] ?? '?'), $bad ? 'REGRESSION' : 'ok']) . "\n";
    }
}
exit($exit);
