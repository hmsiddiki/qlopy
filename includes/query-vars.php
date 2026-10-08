<?php
if (!defined('QLOPY_INIT')) { http_response_code(403); exit; }
/**
 * Query Vars & Rewrite Rule manager for advcms (qp)
 *
 * Lightweight registry for allowed query vars and a small parser that
 * populates `global $qp_query_vars` from pretty URLs (via parse_pretty_request)
 * or from the query string. Designed to be fast and simple to keep
 * front-end latency minimal.
 */

if (!isset($GLOBALS['qp_query_vars'])) {
    $GLOBALS['qp_query_vars'] = [];
}
if (!isset($GLOBALS['qp_registered_query_vars'])) {
    $GLOBALS['qp_registered_query_vars'] = [];
}
if (!isset($GLOBALS['qp_rewrite_rules'])) {
    $GLOBALS['qp_rewrite_rules'] = [];
}

function register_query_var(string $name): void {
    $name = trim($name);
    if ($name === '') return;
    if (!in_array($name, $GLOBALS['qp_registered_query_vars'], true)) {
        $GLOBALS['qp_registered_query_vars'][] = $name;
    }
}

function is_registered_query_var(string $name): bool {
    return in_array($name, $GLOBALS['qp_registered_query_vars'], true);
}

function set_query_var(string $name, $value): void {
    if (!is_registered_query_var($name)) return;
    // Lightweight sanitization: trim strings, cast ints where appropriate
    if (is_string($value)) $value = trim($value);
    $GLOBALS['qp_query_vars'][$name] = $value;
}

function get_query_var(string $name, $default = null) {
    return $GLOBALS['qp_query_vars'][$name] ?? $default;
}

function add_rewrite_rule(string $regex, string $query_template, string $priority = 'top'): void {
    // Compute a cheap literal prefix for fast rejection: take characters from
    // the start of the regex until a regex metacharacter is seen.
    $prefix = '';
    if (preg_match('/^\^?([a-zA-Z0-9_\-\/]+)/', $regex, $pm)) {
        $prefix = trim($pm[1], '/');
    }
    $rule = ['regex' => $regex, 'query' => $query_template, 'priority' => $priority, 'prefix' => $prefix];
    if ($priority === 'top') {
        array_unshift($GLOBALS['qp_rewrite_rules'], $rule);
    } else {
        $GLOBALS['qp_rewrite_rules'][] = $rule;
    }
}

function get_rewrite_rules(): array {
    return $GLOBALS['qp_rewrite_rules'];
}

// Persist compiled rewrite rules to a PHP cache file for production.
function flush_rewrite_rules(array $rules = null): bool {
    $rules = $rules ?? $GLOBALS['qp_rewrite_rules'];
    // Determine runtime-writable uploads folder for caches. Prefer `qp_uploads_base()` when available,
    // otherwise fall back to reading `uploads_dir` from config.php (supports `uploads_dir` or legacy `upload_dir`).
    if (function_exists('qp_uploads_base')) {
        $cachePath = rtrim(qp_uploads_base(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'rewrite_rules.cache.php';
    } else {
        $cfg = $GLOBALS['config'] ?? null;
        if (!is_array($cfg)) {
            $cfg_file = __DIR__ . '/../config.php';
            if (is_file($cfg_file)) $cfg = require $cfg_file; else $cfg = [];
        }
        $uploads = $cfg['uploads_dir'] ?? ($cfg['upload_dir'] ?? 'uploads');
        $cachePath = __DIR__ . '/../' . trim($uploads, "\\/") . '/rewrite_rules.cache.php';
    }
    $dir = dirname($cachePath);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    // Try to include known term slugs into the compiled cache for fast, DB-less taxonomy matching
    $meta = [];
    try {
        if (function_exists('db')) {
            $pdo = db();
            $stmt = $pdo->query('SELECT taxonomy, slug FROM ' . table_name('taxonomy_terms') . ' ORDER BY taxonomy, slug');
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $tax_slugs = [];
            foreach ($rows as $r) {
                $tax_slugs[$r['taxonomy']][] = $r['slug'];
            }
            // Filter compiled tax_slugs to include only taxonomies that opted into front routing
            try {
                $registered = function_exists('get_taxonomies') ? get_taxonomies() : ($GLOBALS['qlopy_taxonomies'] ?? []);
                foreach ($tax_slugs as $tx => $slugs) {
                    if (empty($registered[$tx]) || empty($registered[$tx]['front_route'])) {
                        unset($tax_slugs[$tx]);
                    }
                }
            } catch (Throwable $_e) {
                // ignore and keep all by default on error
            }
            $meta['tax_slugs'] = $tax_slugs;
        }
    } catch (Throwable $_e) {
        // ignore DB errors — we still write rules-only cache
    }
    // Also include taxonomy -> object_types mapping for fast lookup without DB
    try {
        $tax_obj = [];
        if (function_exists('get_taxonomies')) {
            $t = get_taxonomies();
            if (is_array($t)) {
                foreach ($t as $tax => $td) {
                    // include only taxonomies that expose front routes
                    if (!empty($td['object_types']) && is_array($td['object_types']) && !empty($td['front_route'])) {
                        $tax_obj[$tax] = array_values($td['object_types']);
                    }
                }
            }
        } elseif (!empty($GLOBALS['qlopy_taxonomies']) && is_array($GLOBALS['qlopy_taxonomies'])) {
            foreach ($GLOBALS['qlopy_taxonomies'] as $tax => $td) {
                if (!empty($td['object_types']) && is_array($td['object_types']) && !empty($td['front_route'])) {
                    $tax_obj[$tax] = array_values($td['object_types']);
                }
            }
        }
        if (!empty($tax_obj)) $meta['tax_object_types'] = $tax_obj;
    } catch (Throwable $_e) {
        // ignore
    }

    $exportRules = var_export($rules, true);
    $exportMeta = var_export($meta, true);
    $php = "<?php\n// Auto-generated rewrite rules cache.\nreturn [ 'rules' => " . $exportRules . ", 'meta' => " . $exportMeta . " ];\n";
    // Write atomically
    $tmp = $cachePath . '.tmp';
    if (file_put_contents($tmp, $php) === false) return false;
    if (!@rename($tmp, $cachePath)) {
        @unlink($tmp);
        return false;
    }
    return true;
}

function get_compiled_rewrite_rules(): array {
    if (function_exists('qp_uploads_base')) {
        $cachePath = rtrim(qp_uploads_base(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'rewrite_rules.cache.php';
    } else {
        $cfg = $GLOBALS['config'] ?? null;
        if (!is_array($cfg)) {
            $cfg_file = __DIR__ . '/../config.php';
            if (is_file($cfg_file)) $cfg = require $cfg_file; else $cfg = [];
        }
        $uploads = $cfg['uploads_dir'] ?? ($cfg['upload_dir'] ?? 'uploads');
        $cachePath = __DIR__ . '/../' . trim($uploads, "\\/") . '/rewrite_rules.cache.php';
    }
    if (is_file($cachePath)) {
        $r = include $cachePath;
        if (is_array($r)) {
            if (isset($r['rules']) && is_array($r['rules'])) return $r['rules'];
            return $r;
        }
    }
    return get_rewrite_rules();
}

function get_compiled_rewrite_meta(): array {
    if (function_exists('qp_uploads_base')) {
        $cachePath = rtrim(qp_uploads_base(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'rewrite_rules.cache.php';
    } else {
        $cfg = $GLOBALS['config'] ?? null;
        if (!is_array($cfg)) {
            $cfg_file = __DIR__ . '/../config.php';
            if (is_file($cfg_file)) $cfg = require $cfg_file; else $cfg = [];
        }
        $uploads = $cfg['uploads_dir'] ?? ($cfg['upload_dir'] ?? 'uploads');
        $cachePath = __DIR__ . '/../' . trim($uploads, "\\/") . '/rewrite_rules.cache.php';
    }
    if (is_file($cachePath)) {
        $r = include $cachePath;
        if (is_array($r) && isset($r['meta']) && is_array($r['meta'])) return $r['meta'];
    }
    return [];
}

// For debugging: store last matched rule and input path
if (!isset($GLOBALS['qp_last_matched_rewrite'])) $GLOBALS['qp_last_matched_rewrite'] = null;

function _qp_set_last_matched_rewrite($rule, $path) {
    $GLOBALS['qp_last_matched_rewrite'] = ['rule' => $rule, 'path' => $path, 'time' => time()];
}

function get_last_matched_rewrite(): ?array {
    return $GLOBALS['qp_last_matched_rewrite'];
}

// Minimal request parser: relies on existing parse_pretty_request() which
// populates $_GET for pretty URLs. This function then copies registered
// vars from $_GET into the `qp_query_vars` global. Call early after
// rewrite parsing is complete.
function parse_request(string $scriptDir = ''): array {
    // Ensure common query vars are registered (safe defaults)
    $defaults = ['route','post_type','slug','p','page','taxonomy','term','q','year','month','paged','author','s','status'];
    foreach ($defaults as $d) register_query_var($d);

    // If pretty permalinks are enabled, parse_pretty_request (from includes/rewrite.php)
    if (function_exists('permalink_get_structure') && permalink_get_structure() === 'pretty') {
        if (function_exists('parse_pretty_request')) {
            // parse_pretty_request writes into $_GET; call it with scriptDir
            parse_pretty_request($scriptDir);
        }
    }

    // Copy any registered vars from $_GET into qp_query_vars (registered-only)
    foreach ($GLOBALS['qp_registered_query_vars'] as $var) {
        if (isset($_GET[$var])) {
            $val = $_GET[$var];
            // Normalize numeric-ish fields
            if (in_array($var, ['paged','year','month','p'], true)) {
                if (is_numeric($val)) $val = (int)$val;
            }
            set_query_var($var, $val);
        }
    }

    // Defensive: if route resolved to an archive, make sure a stray `slug`
    // doesn't remain set (which would turn the archive query into a single-post filter).
    if (!empty($GLOBALS['qp_query_vars']['route']) && $GLOBALS['qp_query_vars']['route'] === 'archive') {
        if (isset($GLOBALS['qp_query_vars']['slug'])) unset($GLOBALS['qp_query_vars']['slug']);
        // If archive is a taxonomy archive, a stale `post_type` value of
        // `page` (from earlier fallbacks) would incorrectly filter results.
        // Clear `post_type` when the archive has a `taxonomy` registered so
        // later template logic can set the appropriate object type.
        if (!empty($GLOBALS['qp_query_vars']['taxonomy']) && isset($GLOBALS['qp_query_vars']['post_type']) && $GLOBALS['qp_query_vars']['post_type'] === 'page') {
            unset($GLOBALS['qp_query_vars']['post_type']);
        }
    }

    // If no route was set, default to frontpage or 404 based on URI
    if (empty($GLOBALS['qp_query_vars']['route'])) {
        $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
        $uri = trim($uri, '/');
        $GLOBALS['qp_query_vars']['route'] = ($uri === '' ? 'frontpage' : '404');
    }

    return $GLOBALS['qp_query_vars'];
}

// Register a few common rewrite rules as examples (lightweight and cached)
// Example: /blog/2025/12/ => route=archive&post_type=post&year=2025&month=12
//add_rewrite_rule('^blog/([0-9]{4})/([0-9]{1,2})/?$', 'route=archive&post_type=post&year=$1&month=$2', 'bottom');
// Example: /author/username => route=archive&post_type=post&author=username
add_rewrite_rule('^author/([^/]+)/?$', 'route=archive&post_type=post&author=$1', 'bottom');
// Example: /search/term => route=search&q=term
add_rewrite_rule('^search/([^/]+)/?$', 'route=search&q=$1', 'bottom');
// Ensure core post type archive and singular patterns exist for /post/ and /post/{slug}
//add_rewrite_rule('^post/([^/]+)/?$', 'route=singular&post_type=post&slug=$1', 'top');
// usage
$patterns = permalink_get_patterns();
$post_pattern = $patterns['post'] ?? '/post/%slug%';
$post_base = get_pattern_base($post_pattern); // 'post' or 'blogs' or '' for '/%slug%'
if ($post_base !== '') {
    add_rewrite_rule('^' . preg_quote($post_base, '#') . '/?$', 'route=archive&post_type=post', 'bottom');
}

// Add archive rules for custom post types when a base is configured
if (!empty($patterns['post_types']) && is_array($patterns['post_types'])) {
    foreach ($patterns['post_types'] as $ptype => $tpl) {
        $base = get_pattern_base($tpl);
        if ($base === '') continue; // root-mounted CPTs have no archive base
        add_rewrite_rule('^' . preg_quote($base, '#') . '/?$', 'route=archive&post_type=' . $ptype, 'bottom');
    }
}

//return true;

?>
