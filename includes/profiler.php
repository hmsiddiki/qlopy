<?php
if (!defined('QLOPY_INIT')) { http_response_code(403); exit; }
// includes/profiler.php
// Debug-only request profiler (core upgrade plan, step C1a).
//
// Off unless BOTH are true:
//   1. config.php has 'profiler' => true (or 'log' to also append one JSON line per request to uploads/profile.log)
//   2. the request carries an `X-QP-Profile` header equal to qp_profile_token() (derived from security_salt)
// A normal visitor request on a normal site never loads this file past the first lines. When active it adds response
// headers only (Server-Timing, X-QP-SQL, X-QP-Mem, X-QP-Files); nothing is stored unless 'log' is set.
//
// Public API:
//   qp_profile_mark(string $phase)   record the end of a phase; call as `if (defined('QP_PROFILE')) qp_profile_mark('x');`
//   qp_profile_token(array $config)  the header value a client must send (used by tools/bench)

if (defined('QP_PROFILE_LOADED')) return;
define('QP_PROFILE_LOADED', true);

function qp_profile_token(array $cfg): string {
    return substr(hash_hmac('sha256', 'qp-profile', (string)($cfg['security_salt'] ?? '')), 0, 32);
}

(function () {
    $cfg = $GLOBALS['config'] ?? [];
    if (!is_array($cfg) || empty($cfg['profiler'])) return;
    $sent = $_SERVER['HTTP_X_QP_PROFILE'] ?? '';
    if (!is_string($sent) || $sent === '' || !hash_equals(qp_profile_token($cfg), $sent)) return;

    define('QP_PROFILE', true);
    $GLOBALS['qp_profile'] = [
        't0'     => (float)($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true)),
        'marks'  => [],
        'sql_n'  => 0,
        'sql_t'  => 0.0,
        'shapes' => [],
        'log'    => ($cfg['profiler'] === 'log'),
    ];
})();

if (defined('QP_PROFILE')) {

    function qp_profile_mark(string $phase): void {
        $GLOBALS['qp_profile']['marks'][] = [$phase, microtime(true)];
    }

    function qp_profile_sql(string $sql, float $seconds, ?array $params = null): void {
        $p = &$GLOBALS['qp_profile'];
        $p['sql_n']++;
        $p['sql_t'] += $seconds;
        if ($p['log']) {
            // Which option names are read or written, and how often (the repeat reads are what C1b removes).
            if ($params && stripos($sql, 'site_options') !== false && isset($params[0]) && is_string($params[0])) {
                $verb = strtoupper(substr(ltrim($sql), 0, 6));
                $key = $verb . ' ' . ($verb === 'UPDATE' ? ($params[1] ?? '?') : $params[0]);
                $p['opts'][$key] = ($p['opts'][$key] ?? 0) + 1;
            }
            $shape = preg_replace("/'(?:[^'\\\\]|\\\\.)*'|\\b\\d+\\b/", '?', $sql);
            $shape = substr(trim(preg_replace('/\s+/', ' ', (string)$shape)), 0, 160);
            if (!isset($p['shapes'][$shape])) $p['shapes'][$shape] = [0, 0.0];
            $p['shapes'][$shape][0]++;
            $p['shapes'][$shape][1] += $seconds;
        }
    }

    class QP_Profiled_Statement extends PDOStatement {
        public function execute(?array $params = null): bool {
            $t = microtime(true);
            try {
                return parent::execute($params);
            } finally {
                qp_profile_sql($this->queryString, microtime(true) - $t, $params);
            }
        }
    }

    class QP_Profiled_PDO extends PDO {
        public function __construct($dsn, $username = null, $password = null, $options = null) {
            parent::__construct($dsn, $username, $password, $options);
            $this->setAttribute(PDO::ATTR_STATEMENT_CLASS, ['QP_Profiled_Statement']);
        }
        public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false {
            $t = microtime(true);
            try {
                return $fetchMode === null ? parent::query($query) : parent::query($query, $fetchMode, ...$fetchModeArgs);
            } finally {
                qp_profile_sql($query, microtime(true) - $t);
            }
        }
        public function exec(string $statement): int|false {
            $t = microtime(true);
            try {
                return parent::exec($statement);
            } finally {
                qp_profile_sql($statement, microtime(true) - $t);
            }
        }
    }

    function qp_profile_finish(): void {
        $p = $GLOBALS['qp_profile'] ?? null;
        if (!$p) return;
        $now = microtime(true);
        $total = ($now - $p['t0']) * 1000;

        $parts = [];
        $prev = $p['t0'];
        foreach ($p['marks'] as [$name, $t]) {
            $parts[] = preg_replace('/[^a-z0-9_]/i', '_', $name) . ';dur=' . round(($t - $prev) * 1000, 2);
            $prev = $t;
        }
        $parts[] = 'after_last_mark;dur=' . round(($now - $prev) * 1000, 2);
        $parts[] = 'sql;dur=' . round($p['sql_t'] * 1000, 2);
        $parts[] = 'total;dur=' . round($total, 2);

        if (!headers_sent()) {
            header('Server-Timing: ' . implode(', ', $parts));
            header('X-QP-SQL: ' . $p['sql_n'] . ';' . round($p['sql_t'] * 1000, 2));
            header('X-QP-Mem: ' . memory_get_peak_usage(true));
            header('X-QP-Files: ' . count(get_included_files()));
        }

        if ($p['log']) {
            $up = $GLOBALS['config']['uploads_dir'] ?? 'uploads';
            $file = dirname(__DIR__) . DIRECTORY_SEPARATOR . (is_string($up) && $up !== '' ? $up : 'uploads') . DIRECTORY_SEPARATOR . 'profile.log';
            if (!is_file($file) || filesize($file) < 5 * 1024 * 1024) {
                uasort($p['shapes'], fn($a, $b) => $b[1] <=> $a[1]);
                $top = [];
                foreach (array_slice($p['shapes'], 0, 15, true) as $shape => [$n, $s]) {
                    $top[] = ['sql' => $shape, 'n' => $n, 'ms' => round($s * 1000, 2)];
                }
                @file_put_contents($file, json_encode([
                    'time'   => date('c'),
                    'uri'    => $_SERVER['REQUEST_URI'] ?? '',
                    'total'  => round($total, 2),
                    'phases' => $parts,
                    'sql_n'  => $p['sql_n'],
                    'sql_ms' => round($p['sql_t'] * 1000, 2),
                    'top'    => $top,
                    'opts'   => $p['opts'] ?? [],
                ]) . "\n", FILE_APPEND | LOCK_EX);
            }
        }
    }

    register_shutdown_function('qp_profile_finish');
}
