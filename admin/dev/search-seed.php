<?php
// Dev tool (C1d): seed or remove bulk products for search benchmarks. CLI only.
//   php admin/dev/search-seed.php seed [count] [zipf]  | remove | time "<word>" | bench
// 'zipf' draws words from a 5,000-word vocabulary with natural-language frequencies (realistic); default is a flat 25-word list.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('QLOPY_INIT', true);
chdir(dirname(__DIR__, 2));
require 'db.php';
$pdo = db(); $t = table_name('posts');
$cmd = $argv[1] ?? '';
if ($cmd === 'seed') {
    $n = (int)($argv[2] ?? 50000);
    mt_srand(42);
    $zipf = ($argv[3] ?? '') === 'zipf';
    if ($zipf) { $syl = ['ba','ko','ri','ten','mar','lo','vi','sun','da','pe','qui','zor','el','fa','gi','hu','jo','ne','po','tra']; $w = []; for ($i = 0; $i < 5000; $i++) { $a = $i; $x = ''; do { $x .= $syl[$a % 20]; $a = intdiv($a, 20); } while ($a > 0); $w[] = $x . 'x' . $i; } $cum = []; $tot = 0; foreach ($w as $i => $_) { $tot += 1 / ($i + 1); $cum[] = $tot; } $pick = function() use ($w, $cum, $tot) { $r = mt_rand() / mt_getrandmax() * $tot; $lo = 0; $hi = count($cum) - 1; while ($lo < $hi) { $mid = ($lo + $hi) >> 1; if ($cum[$mid] < $r) $lo = $mid + 1; else $hi = $mid; } return $w[$lo]; }; }
    $w = ['red','blue','leather','running','shoe','boot','sandal','cotton','shirt','denim','jacket','wool','summer','winter','classic','sport','premium','light','urban','trail','canvas','silk','kids','mens','womens'];
    $pdo->beginTransaction();
    $st = $pdo->prepare("INSERT INTO $t (title,slug,content,post_type,status,visibility,published_at) VALUES (?,?,?,?,'published','public',NOW())");
    for ($i = 0; $i < $n; $i++) {
        $p = $zipf ? $pick : fn() => $w[mt_rand(0,24)];
        $title = ucfirst($p()) . ' ' . $p() . ' ' . $p() . ' ' . $i;
        $c = []; for ($k = 0; $k < 60; $k++) $c[] = $p();
        $st->execute([$title, 'bench-seed-' . $i, '<p>' . implode(' ', $c) . '</p>', 'product']);
        if ($i % 5000 === 4999) { $pdo->commit(); $pdo->beginTransaction(); }
    }
    $pdo->commit(); echo "seeded $n\n";
} elseif ($cmd === 'remove') {
    echo $pdo->exec("DELETE FROM $t WHERE slug LIKE 'bench-seed-%'") . " removed\n";
} elseif ($cmd === 'time') {
    $s = '%' . ($argv[2] ?? 'trail') . '%';
    $m = [];
    for ($i = 0; $i < 5; $i++) {
        $a = microtime(true);
        $q = $pdo->prepare("SELECT p.id FROM $t p WHERE (p.title LIKE ? OR p.content LIKE ?) AND p.status='published' ORDER BY p.published_at DESC LIMIT 20");
        $q->execute([$s, $s]); $q->fetchAll();
        $m[] = (microtime(true) - $a) * 1000;
    }
    sort($m); printf("LIKE median %.1f ms (min %.1f, max %.1f)\n", $m[2], $m[0], $m[4]);
} elseif ($cmd === 'bench') {
    foreach (array_slice($argv, 2) as $word) {
        $res = [];
        foreach (['LIKE' => ['(p.title LIKE ? OR p.content LIKE ?)', ['%' . $word . '%', '%' . $word . '%']], 'FULLTEXT' => ['MATCH(p.title, p.content) AGAINST (? IN BOOLEAN MODE)', ['+' . $word . '*']]] as $name => [$w2, $par]) {
            $m = []; $n = 0;
            for ($i = 0; $i < 5; $i++) {
                $a = microtime(true);
                $q = $pdo->prepare("SELECT p.id FROM $t p WHERE $w2 AND p.post_type='product' AND p.status='published' ORDER BY p.created_at DESC, p.id DESC LIMIT 20");
                $q->execute($par); $n = count($q->fetchAll()); $m[] = (microtime(true) - $a) * 1000;
            }
            sort($m); $res[] = sprintf('%s %.1f ms', $name, $m[2]);
        }
        $c = $pdo->prepare("SELECT COUNT(*) FROM $t WHERE MATCH(title, content) AGAINST (? IN BOOLEAN MODE)"); $c->execute(['+' . $word . '*']);
        printf("%-14s matches %6d   %s
", $word, $c->fetchColumn(), implode('   ', $res));
    }
}
