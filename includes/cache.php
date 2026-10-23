<?php
if (!defined('QLOPY_INIT')) { http_response_code(403); exit; }
// includes/cache.php
// Object cache (core upgrade plan, step C1c). Loaded from auth.php; replaces the old qp_cache_* block there.
//
// Compatible API (unchanged signatures and meaning; a miss returns false):
//   qp_cache_fetch($key)  qp_cache_store($key, $val, $ttl = 300)  qp_cache_delete($key)  qp_cache_backend()
// New, for plugins and core:
//   qp_cache_get($key, $group = 'default', &$found)   a miss is distinguishable from a stored false/null
//   qp_cache_set($key, $val, $ttl = 300, $group = 'default')
//   qp_cache_remove($key, $group = 'default')
//   qp_cache_flush_group($group)                     one write; every key of the group is dead at once
//   qp_cache_remember($key, $ttl, $callable, $group = 'default')
//
// Backends: apcu, redis, memcached, file. Chosen once per request:
//   1. config.php 'session_cache' => ['type' => ...] (unchanged, always wins)
//   2. site option `cache_backend`: auto (default) | apcu | redis | memcached | file | none
//   3. auto = Redis, then Memcached, then APCu, each only if the server really answers; otherwise none.
//      `file` is never picked by auto: writing to disk on a shared host is the owner's decision.
// A backend that fails mid-request is switched off for the rest of that request. Nothing here ever throws.
// Keys are prefixed with a per-site namespace so two sites on one Redis/Memcached/APCu cannot read each other's data.

if (defined('QP_CACHE_LOADED')) return;
define('QP_CACHE_LOADED', true);

function qp_cache_ns(): string {
    static $ns = null;
    if ($ns !== null) return $ns;
    $cfg = function_exists('get_config') ? get_config() : [];
    $ns = 'qp' . substr(md5((string)($cfg['security_salt'] ?? '') . '|' . __DIR__), 0, 8) . ':';
    return $ns;
}

/** Default file-cache folder: <uploads_dir from config.php, fallback 'uploads'>/cache, next to the core files. */
function qp_cache_default_dir(): string {
    $cfg = function_exists('get_config') ? get_config() : [];
    $up = $cfg['uploads_dir'] ?? 'uploads';
    if (!is_string($up) || $up === '') $up = 'uploads';
    return dirname(__DIR__) . DIRECTORY_SEPARATOR . $up . DIRECTORY_SEPARATOR . 'cache';
}

/** Test a configured/auto candidate and return a ready backend array, or null if it does not answer. */
function qp_cache_probe(array $b): ?array {
    try {
        switch ($b['type'] ?? '') {
            case 'apcu':
                return (function_exists('apcu_enabled') && apcu_enabled()) ? $b : null;
            case 'redis':
                if (!class_exists('Redis')) return null;
                $r = new Redis();
                if (!@$r->connect($b['host'] ?? '127.0.0.1', (int)($b['port'] ?? 6379), 0.25)) return null;
                if (!empty($b['auth'])) @$r->auth($b['auth']);
                $r->setOption(Redis::OPT_READ_TIMEOUT, 0.5);
                $b['_conn'] = $r;
                return @$r->ping() ? $b : null;
            case 'memcached':
                if (!class_exists('Memcached')) return null;
                $m = new Memcached();
                $m->setOption(Memcached::OPT_CONNECT_TIMEOUT, 250);
                foreach (($b['servers'] ?? [['127.0.0.1', 11211]]) as $s) $m->addServer($s[0], (int)$s[1]);
                $stats = @$m->getStats();
                if (!is_array($stats)) return null;
                foreach ($stats as $st) { if (is_array($st) && ($st['pid'] ?? -1) > 0) { $b['_conn'] = $m; return $b; } }
                return null;
            case 'file':
                $dir = $b['dir'] ?? qp_cache_default_dir();
                if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) return null;
                if (!is_writable($dir)) return null;
                $b['dir'] = $dir;
                return $b;
        }
    } catch (\Throwable $e) {
        return null;
    }
    return null;
}

function &qp_cache_backend_ref() {
    static $backend = null;
    return $backend;
}

function qp_cache_backend() {
    $backend = &qp_cache_backend_ref();
    if ($backend !== null) return $backend;
    $none = ['type' => 'none'];

    $cfg = function_exists('get_config') ? get_config() : [];
    $opt = $cfg['session_cache'] ?? null;
    if (is_array($opt) && !empty($opt['type'])) {
        return $backend = (qp_cache_probe($opt) ?? $none);
    }

    $choice = 'auto';
    if (function_exists('qp_option_raw') && !empty($GLOBALS['pdo'])) {
        $v = qp_option_raw('cache_backend');
        if (is_string($v) && $v !== '') $choice = trim($v, " \t\n\r\0\x0B\"");
    }
    $candidates = [
        'redis'     => ['type' => 'redis', 'host' => '127.0.0.1', 'port' => 6379],
        'memcached' => ['type' => 'memcached', 'servers' => [['127.0.0.1', 11211]]],
        'apcu'      => ['type' => 'apcu'],
        'file'      => ['type' => 'file'],
    ];
    if ($choice === 'none') return $backend = $none;
    if ($choice !== 'auto' && isset($candidates[$choice])) {
        return $backend = (qp_cache_probe($candidates[$choice]) ?? $none);
    }
    foreach (['redis', 'memcached', 'apcu'] as $name) {
        $probe = qp_cache_probe($candidates[$name]);
        if ($probe) return $backend = $probe;
    }
    return $backend = $none;
}

/** Turn the backend off for the rest of the request after a failure. */
function qp_cache_disable(): void {
    $backend = &qp_cache_backend_ref();
    $backend = ['type' => 'none'];
}

function qp_cache_file_path(array $b, string $full): string {
    $h = sha1($full);
    return $b['dir'] . '/' . substr($h, 0, 2) . '/' . $h . '.php';
}

/** @return array [bool $hit, mixed $value] for the already-namespaced key. */
function qp_cache_raw_get(string $full): array {
    $b = qp_cache_backend();
    try {
        switch ($b['type']) {
            case 'apcu':
                $v = apcu_fetch($full, $ok);
                return [(bool)$ok, $ok ? $v : null];
            case 'redis':
                $raw = $b['_conn']->get($full);
                if ($raw === false) return [false, null];
                $v = @unserialize($raw, ['allowed_classes' => false]);
                return ($v === false && $raw !== serialize(false)) ? [false, null] : [true, $v];
            case 'memcached':
                $v = $b['_conn']->get($full);
                return $b['_conn']->getResultCode() === Memcached::RES_SUCCESS ? [true, $v] : [false, null];
            case 'file':
                $f = qp_cache_file_path($b, $full);
                $raw = @file_get_contents($f);
                if ($raw === false) return [false, null];
                $nl = strpos($raw, "\n");
                $d = $nl === false ? false : @unserialize(substr($raw, $nl + 1), ['allowed_classes' => false]);
                if (!is_array($d) || count($d) !== 2) return [false, null];
                if ($d[0] !== 0 && $d[0] < time()) { @unlink($f); return [false, null]; }
                return [true, $d[1]];
        }
    } catch (\Throwable $e) {
        qp_cache_disable();
    }
    return [false, null];
}

function qp_cache_raw_set(string $full, $val, int $ttl): bool {
    $b = qp_cache_backend();
    $ttl = max(0, $ttl);
    try {
        switch ($b['type']) {
            case 'apcu':
                return (bool)apcu_store($full, $val, $ttl);
            case 'redis':
                return $ttl > 0 ? (bool)$b['_conn']->setex($full, $ttl, serialize($val)) : (bool)$b['_conn']->set($full, serialize($val));
            case 'memcached':
                return (bool)$b['_conn']->set($full, $val, $ttl);
            case 'file':
                $f = qp_cache_file_path($b, $full);
                $dir = dirname($f);
                if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) return false;
                $tmp = $f . '.' . bin2hex(random_bytes(4)) . '.tmp';
                $body = "<?php exit; ?>\n" . serialize([$ttl > 0 ? time() + $ttl : 0, $val]);
                if (@file_put_contents($tmp, $body, LOCK_EX) === false) { @unlink($tmp); return false; }
                if (!@rename($tmp, $f)) { @copy($tmp, $f); @unlink($tmp); }
                if (random_int(1, 200) === 1) qp_cache_file_gc($b['dir']);
                return true;
        }
    } catch (\Throwable $e) {
        qp_cache_disable();
    }
    return false;
}

function qp_cache_raw_delete(string $full): bool {
    $b = qp_cache_backend();
    try {
        switch ($b['type']) {
            case 'apcu':      return (bool)apcu_delete($full);
            case 'redis':     $b['_conn']->del($full); return true;
            case 'memcached': return (bool)$b['_conn']->delete($full);
            case 'file':      return @unlink(qp_cache_file_path($b, $full));
        }
    } catch (\Throwable $e) {
        qp_cache_disable();
    }
    return false;
}

/** Remove expired files from one random shard (cheap; runs on about 1 in 200 writes). */
function qp_cache_file_gc(string $dir): void {
    $shards = glob($dir . '/??', GLOB_ONLYDIR) ?: [];
    if (!$shards) return;
    $now = time();
    foreach (glob($shards[array_rand($shards)] . '/*.php') ?: [] as $f) {
        $fh = @fopen($f, 'rb');
        if (!$fh) continue;
        fgets($fh);
        $head = fread($fh, 40);
        fclose($fh);
        if (preg_match('/^a:2:\{i:0;i:(\d+);/', (string)$head, $m) && (int)$m[1] !== 0 && (int)$m[1] < $now) @unlink($f);
    }
}

/** Current version token of a group; flushing the group replaces it. */
function &qp_cache_group_seen() {
    static $seen = [];
    return $seen;
}

function qp_cache_group_version(string $group): string {
    $seen = &qp_cache_group_seen();
    if (isset($seen[$group])) return $seen[$group];
    [$hit, $v] = qp_cache_raw_get(qp_cache_ns() . 'gv:' . $group);
    if (!$hit || !is_string($v)) {
        $v = dechex((int)(microtime(true) * 1000)) . bin2hex(random_bytes(2));
        qp_cache_raw_set(qp_cache_ns() . 'gv:' . $group, $v, 0);
    }
    return $seen[$group] = $v;
}

function qp_cache_full_key(string $key, string $group): string {
    if ($group === '') return qp_cache_ns() . 'k:' . $key;
    return qp_cache_ns() . 'g:' . $group . ':' . qp_cache_group_version($group) . ':' . $key;
}

function qp_cache_get($key, $group = 'default', &$found = null) {
    [$hit, $v] = qp_cache_raw_get(qp_cache_full_key((string)$key, (string)$group));
    $found = $hit;
    return $hit ? $v : false;
}

function qp_cache_set($key, $val, $ttl = 300, $group = 'default') {
    return qp_cache_raw_set(qp_cache_full_key((string)$key, (string)$group), $val, (int)$ttl);
}

function qp_cache_remove($key, $group = 'default') {
    return qp_cache_raw_delete(qp_cache_full_key((string)$key, (string)$group));
}

function qp_cache_flush_group($group) {
    $group = (string)$group;
    $new = dechex((int)(microtime(true) * 1000)) . bin2hex(random_bytes(2));
    $ok = qp_cache_raw_set(qp_cache_ns() . 'gv:' . $group, $new, 0);
    $seen = &qp_cache_group_seen();
    $seen[$group] = $new;
    return $ok;
}

function qp_cache_remember($key, $ttl, callable $fn, $group = 'default') {
    $v = qp_cache_get($key, $group, $found);
    if ($found) return $v;
    $v = $fn();
    qp_cache_set($key, $v, $ttl, $group);
    return $v;
}

// ---- Compatible wrappers (flat keys, a miss is false) ----

function qp_cache_fetch($key) {
    [$hit, $v] = qp_cache_raw_get(qp_cache_full_key((string)$key, ''));
    return $hit ? $v : false;
}

function qp_cache_store($key, $val, $ttl = 300) {
    return qp_cache_raw_set(qp_cache_full_key((string)$key, ''), $val, (int)$ttl);
}

function qp_cache_delete($key) {
    return qp_cache_raw_delete(qp_cache_full_key((string)$key, ''));
}
