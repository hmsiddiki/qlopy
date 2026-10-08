<?php
if (!defined('QLOPY_INIT')) { http_response_code(403); exit; }
// includes/assets.php

if (!isset($GLOBALS['qlopy_assets'])) {
    $GLOBALS['qlopy_assets'] = [
        'styles' => [],
        'scripts' => [],
    ];
};

/**
 * Register a CSS style to enqueue
 * @param string $handle Unique id for the style
 * @param string $src URL or path to CSS file
 * @param array $deps Array of dependency handles
 * @param string $ver Version string
 * @param string $media Media attribute value (e.g. 'all', 'screen')
 */
function enqueue_style($handle, $src, $deps = [], $ver = '', $media = 'all', $attrs = []) {
    global $qlopy_assets;
    $qlopy_assets['styles'][$handle] = compact('handle', 'src', 'deps', 'ver', 'media', 'attrs');
}

// Convenience helper to append inline footer scripts from templates or plugins
function qp_append_footer_inline(string $script): void {
    if (!isset($GLOBALS['qp_footer_inline']) || !is_array($GLOBALS['qp_footer_inline'])) $GLOBALS['qp_footer_inline'] = [];
    $GLOBALS['qp_footer_inline'][] = $script;
}

/**
 * Register a JS script to enqueue
 * @param string $handle Unique id for the script
 * @param string $src URL or path to JS file
 * @param array $deps Array of dependency handles
 * @param string $ver Version string
 * @param bool $in_footer Load script before </body> if true, else in <head>
 */
function enqueue_script($handle, $src, $deps = [], $ver = '', $in_footer = true, $attrs = []) {
    global $qlopy_assets;
    $qlopy_assets['scripts'][$handle] = compact('handle', 'src', 'deps', 'ver', 'in_footer', 'attrs');
}


function qp_localize_script(string $handle, string $object_name, array $data): bool {
    global $qlopy_assets;

    if (!isset($qlopy_assets['scripts'][$handle])) {
        return false;
    }

    if (!isset($GLOBALS['qp_localized_scripts'])) {
        $GLOBALS['qp_localized_scripts'] = [];
    }

    $GLOBALS['qp_localized_scripts'][$handle][] = [
        'object_name' => $object_name,
        'data'        => $data,
    ];

    return true;
}

function qp_localized_script_placeholder(string $handle): string {
    return '<!-- qp-localize:' . sha1($handle) . ' -->';
}

function qp_get_localized_script_html(string $handle): string {
    $localized_scripts = $GLOBALS['qp_localized_scripts'] ?? [];
    $out = '';

    if (empty($localized_scripts[$handle])) {
        return $out;
    }

    foreach ($localized_scripts[$handle] as $localized) {
        $object_name = $localized['object_name'] ?? '';
        $data = $localized['data'] ?? [];

        if (
            !is_string($object_name) ||
            !preg_match('/^[A-Za-z_$][A-Za-z0-9_$]*$/', $object_name) ||
            !is_array($data)
        ) {
            continue;
        }

        $json = json_encode(
            $data,
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        );

        if ($json === false) {
            continue;
        }

        $out .= '<script>window.' . $object_name . ' = ' . $json . ';</script>' . "\n";
    }

    return $out;
}

function qp_render_localized_scripts(string $html, array $handles): string {
    foreach ($handles as $handle) {
        $html = str_replace(
            qp_localized_script_placeholder($handle),
            qp_get_localized_script_html($handle),
            $html
        );
    }

    return $html;
}

/**
 * Print the HTML tags for enqueued styles in the header
 */
function qp_assets_cache_key($type, $handles) {
    global $qlopy_assets;
    // Normalize handles to deterministic order and include per-handle version
    if (!is_array($handles)) {
        $handles = (array)$handles;
    }
    sort($handles, SORT_STRING);
    $meta = [];
    foreach ($handles as $h) {
        if (isset($qlopy_assets['styles'][$h])) {
            $a = $qlopy_assets['styles'][$h];
            $meta[$h] = isset($a['ver']) ? $a['ver'] : '';
        } elseif (isset($qlopy_assets['scripts'][$h])) {
            $a = $qlopy_assets['scripts'][$h];
            $meta[$h] = isset($a['ver']) ? $a['ver'] : '';
        } else {
            $meta[$h] = '';
        }
    }
    return 'qp_assets_' . $type . '_' . md5(json_encode($meta));
}

// Determine assets cache TTL (seconds). Configurable via site option `assets_cache_ttl`
function qp_assets_get_ttl() {
    $ttl = 300;
    if (function_exists('get_option_meta')) {
        $opt = get_option_meta('assets_cache_ttl');
        if (is_numeric($opt) || is_string($opt)) $ttl = (int)$opt;
    } else if (function_exists('get_option')) {
        $opt = @get_option('assets_cache_ttl', null);
        if (is_numeric($opt) || is_string($opt)) $ttl = (int)$opt;
    }
    if (function_exists('apply_filter')) {
        $ttl = (int)apply_filter('qp_assets_ttl', $ttl);
    }
    return max(0, $ttl);
}

// Resolve dependency ordering for a set of enqueued assets using simple topo sort.
function qp_resolve_asset_order($type) {
    global $qlopy_assets;
    $bag = $qlopy_assets[$type] ?? [];
    $order = [];
    $visited = [];

    $visit = function($handle) use (&$visit, &$bag, &$order, &$visited) {
        if (isset($visited[$handle])) return;
        $visited[$handle] = 1;
        $deps = $bag[$handle]['deps'] ?? [];
        if (is_array($deps)) {
            foreach ($deps as $d) {
                if (isset($bag[$d]) && !isset($visited[$d])) {
                    $visit($d);
                }
            }
        }
        $order[] = $handle;
    };

    foreach (array_keys($bag) as $h) {
        if (!isset($visited[$h])) $visit($h);
    }
    // remove duplicates while preserving order
    $seen = [];
    $final = [];
    foreach ($order as $h) {
        if (!isset($seen[$h])) { $seen[$h] = true; $final[] = $h; }
    }
    return $final;
}

function print_styles() {
    global $qlopy_assets;
    // Build deterministic list of style handles in current queue
    // skip caching on admin pages (admin uses separate admin enqueue system)
    if (defined('IN_ADMIN') && IN_ADMIN) {
        $out = "";
        foreach ($qlopy_assets['styles'] as $style) {
            $ver_suffix = $style['ver'] ? '?ver=' . $style['ver'] : '';
            $attrs = '';
            if (!empty($style['attrs']) && is_array($style['attrs'])) {
                foreach ($style['attrs'] as $k => $v) {
                    if ($v === true) {
                        $attrs .= ' ' . htmlspecialchars($k);
                
                    } else {
                        $attrs .= ' ' . htmlspecialchars($k) . '="' . htmlspecialchars($v) . '"';
                    }
                }
            }
            $out .= '<link id="' . htmlspecialchars('style-' . $style['handle']) . '" rel="stylesheet" href="' . htmlspecialchars($style['src']) . $ver_suffix . '" media="' . htmlspecialchars($style['media']) . '"' . $attrs . '>' . "\n";
        }
        echo $out;
        return;
    }

    // Normal frontend/plugin/theme caching path
    $handles = qp_resolve_asset_order('styles');
    $cacheKey = qp_assets_cache_key('styles', $handles);

    $cached = function_exists('qp_cache_fetch') ? qp_cache_fetch($cacheKey) : false;
    if ($cached) {
        // Append any dynamic footer extras even when cached
        $extra_footer = '';
        if (function_exists('apply_filters')) {
            $extra_footer = (string)apply_filters('assets_footer_scripts_extra', '');
        }
        if (!empty($GLOBALS['qp_footer_inline']) && is_array($GLOBALS['qp_footer_inline'])) {
            $extra_footer .= "\n" . implode("\n", $GLOBALS['qp_footer_inline']);
        }
        if (trim($extra_footer) !== '') {
            echo $cached . "\n" . $extra_footer . "\n";
        } else {
            echo $cached;
        }
        return;
    }

    $out = "";
    foreach ($handles as $h) {
        $style = $qlopy_assets['styles'][$h] ?? null;
        if (!$style) continue;
        // allow themes/plugins to disable query string versioning via filter 'assets_query'
        $assets_query = true;
        if (function_exists('apply_filters')) $assets_query = (bool)apply_filters('assets_query', $assets_query);
        $ver_suffix = ($assets_query && $style['ver']) ? '?ver=' . $style['ver'] : '';
        $attrs = '';
        if (!empty($style['attrs']) && is_array($style['attrs'])) {
            foreach ($style['attrs'] as $k => $v) {
                if ($v === true) {
                    $attrs .= ' ' . htmlspecialchars($k);
                } else {
                    $attrs .= ' ' . htmlspecialchars($k) . '="' . htmlspecialchars($v) . '"';
                }
            }
        }
        $out .= '<link id="' . htmlspecialchars('style-' . $style['handle']) . '" rel="stylesheet" href="' . htmlspecialchars($style['src']) . $ver_suffix . '" media="' . htmlspecialchars($style['media']) . '"' . $attrs . '>' . "\n";
    }
    $ttl = qp_assets_get_ttl();
    if (function_exists('qp_cache_store') && $ttl > 0) {
        qp_cache_store($cacheKey, $out, $ttl);
    }
    echo $out;
}

/**
 * Print JS scripts that should load in header (in_footer = false)
 */
function print_header_scripts() {
    global $qlopy_assets;
    if (defined('IN_ADMIN') && IN_ADMIN) {
        $out = "";
        foreach ($qlopy_assets['scripts'] as $script) {
            if (!$script['in_footer']) {
                $ver_suffix = $script['ver'] ? '?ver=' . $script['ver'] : '';
                $attrs = '';
                if (!empty($script['attrs']) && is_array($script['attrs'])) {
                    foreach ($script['attrs'] as $k => $v) {
                        if ($v === true) {
                            $attrs .= ' ' . htmlspecialchars($k);
                        } else {
                            $attrs .= ' ' . htmlspecialchars($k) . '="' . htmlspecialchars($v) . '"';
                        }
                    }
                }
                $out .= qp_localized_script_placeholder($script['handle']);
                $out .= '<script id="' . htmlspecialchars('script-' . $script['handle']) . '" src="' . htmlspecialchars($script['src']) . $ver_suffix . '"' . $attrs . '></script>' . "\n";
            }
        }
        echo qp_render_localized_scripts($out, array_keys($qlopy_assets['scripts']));
        return;
    }

    $handles = qp_resolve_asset_order('scripts');
    // only keep handles that are for header scripts
    $handles = array_values(array_filter($handles, function($h) use ($qlopy_assets) { return !empty($qlopy_assets['scripts'][$h]) && !$qlopy_assets['scripts'][$h]['in_footer']; }));
    $cacheKey = qp_assets_cache_key('scripts', $handles);
    $cached = function_exists('qp_cache_fetch') ? qp_cache_fetch($cacheKey) : false;
if ($cached !== false) {
    echo qp_render_localized_scripts($cached, $handles);
    return;
}

    $out = "";
    foreach ($handles as $hh) {
        $script = $qlopy_assets['scripts'][$hh] ?? null;
        if (!$script) continue;
        if (!$script['in_footer']) {
            $assets_query = true;
            if (function_exists('apply_filters')) $assets_query = (bool)apply_filters('assets_query', $assets_query);
            $ver_suffix = ($assets_query && $script['ver']) ? '?ver=' . $script['ver'] : '';
            $attrs = '';
            if (!empty($script['attrs']) && is_array($script['attrs'])) {
                foreach ($script['attrs'] as $k => $v) {
                    if ($v === true) {
                        $attrs .= ' ' . htmlspecialchars($k);
                    } else {
                        $attrs .= ' ' . htmlspecialchars($k) . '="' . htmlspecialchars($v) . '"';
                    }
                }
            }
            $out .= qp_localized_script_placeholder($script['handle']);
            $out .= '<script id="' . htmlspecialchars('script-' . $script['handle']) . '" src="' . htmlspecialchars($script['src']) . $ver_suffix . '"' . $attrs . '></script>' . "\n";
        }
    }
    $ttl = qp_assets_get_ttl();
    if (function_exists('qp_cache_store') && $ttl > 0) {
    qp_cache_store($cacheKey, $out, $ttl);
    }

    echo qp_render_localized_scripts($out, $handles);
}


function hogalo () {
    echo 'fulls';
}
/**
 * Print JS scripts that should load in footer (in_footer = true)
 */
function print_footer_scripts() {
    global $qlopy_assets;
    if (defined('IN_ADMIN') && IN_ADMIN) {
        $out = "";
        foreach ($qlopy_assets['scripts'] as $script) {
            if ($script['in_footer']) {
                $ver_suffix = $script['ver'] ? '?ver=' . $script['ver'] : '';
                $attrs = '';
                if (!empty($script['attrs']) && is_array($script['attrs'])) {
                    foreach ($script['attrs'] as $k => $v) {
                        if ($v === true) {
                            $attrs .= ' ' . htmlspecialchars($k);
                        } else {
                            $attrs .= ' ' . htmlspecialchars($k) . '="' . htmlspecialchars($v) . '"';
                        }
                    }
                }
                $out .= qp_localized_script_placeholder($script['handle']);
                $out .= '<script id="' . htmlspecialchars('script-' . $script['handle']) . '" src="' . htmlspecialchars($script['src']) . $ver_suffix . '"' . $attrs . '></script>' . "\n";
            }
        }
        echo qp_render_localized_scripts($out, array_keys($qlopy_assets['scripts']));
        return;
    }

    $handles = qp_resolve_asset_order('scripts');
    // only keep handles that are for footer scripts
    $handles = array_values(array_filter($handles, function($h) use ($qlopy_assets) { return !empty($qlopy_assets['scripts'][$h]) && $qlopy_assets['scripts'][$h]['in_footer']; }));
    $cacheKey = qp_assets_cache_key('scripts', $handles);
    $cached = function_exists('qp_cache_fetch') ? qp_cache_fetch($cacheKey) : false;
if ($cached !== false) {
    echo qp_render_localized_scripts($cached, $handles);
    return;
}

    $out = "";
    foreach ($handles as $hh) {
        $script = $qlopy_assets['scripts'][$hh] ?? null;
        if (!$script) continue;
        if ($script['in_footer']) {
            $assets_query = true;
            if (function_exists('apply_filters')) $assets_query = (bool)apply_filters('assets_query', $assets_query);
            $ver_suffix = ($assets_query && $script['ver']) ? '?ver=' . $script['ver'] : '';
            $attrs = '';
            if (!empty($script['attrs']) && is_array($script['attrs'])) {
                foreach ($script['attrs'] as $k => $v) {
                    if ($v === true) {
                        $attrs .= ' ' . htmlspecialchars($k);
                    } else {
                        $attrs .= ' ' . htmlspecialchars($k) . '="' . htmlspecialchars($v) . '"';
                    }
                }
            }
            $out .= qp_localized_script_placeholder($script['handle']);
            $out .= '<script id="' . htmlspecialchars('script-' . $script['handle']) . '" src="' . htmlspecialchars($script['src']) . $ver_suffix . '"' . $attrs . '></script>' . "\n";
        }
    }
    
    ///updated for localized scripts

    $ttl = qp_assets_get_ttl();

    if (function_exists('qp_cache_store') && $ttl > 0) {
        qp_cache_store($cacheKey, $out, $ttl);
    }

    $extra_footer = '';
    if (function_exists('apply_filters')) {
        $extra_footer = (string) apply_filters('assets_footer_scripts_extra', '');
    }
    if (!empty($GLOBALS['qp_footer_inline']) && is_array($GLOBALS['qp_footer_inline'])) {
        $extra_footer .= "\n" . implode("\n", $GLOBALS['qp_footer_inline']);
    }

    echo qp_render_localized_scripts($out, $handles);

    if (trim($extra_footer) !== '') {
        echo "\n" . $extra_footer . "\n";
    }
}

/**
 * Invalidate cached asset HTML.
 *
 * Usage:
 * - qp_assets_invalidate('styles', ['handle1','handle2'])
 * - qp_assets_invalidate('scripts') // invalidates based on currently enqueued scripts
 * - qp_assets_invalidate('all')
 */
function qp_assets_invalidate($type = 'all', $handles = []) {
    global $qlopy_assets;
    $types = [];
    if ($type === 'all') {
        $types = ['styles', 'scripts'];
    } else {
        $types = [$type];
    }

    foreach ($types as $t) {
        $hs = $handles;
        if (empty($hs)) {
            if ($t === 'styles') {
                $hs = array_keys($qlopy_assets['styles']);
            } else {
                $hs = array_keys($qlopy_assets['scripts']);
            }
        }
        // Delete the exact-key for the provided handles
        $key = qp_assets_cache_key($t === 'styles' ? 'styles' : 'scripts', $hs);
        if (function_exists('qp_cache_delete')) {
            qp_cache_delete($key);
            // Also delete broader cache keys that may include these handles:
            // - the full-queue key (all handles for this type)
            $allHandles = ($t === 'styles') ? array_keys($qlopy_assets['styles']) : array_keys($qlopy_assets['scripts']);
            $fullKey = qp_assets_cache_key($t === 'styles' ? 'styles' : 'scripts', $allHandles);
            qp_cache_delete($fullKey);
            // For scripts, also delete header and footer buckets
            if ($t === 'scripts') {
                $headerHandles = array_values(array_filter($allHandles, function($h) use ($qlopy_assets) { return !empty($qlopy_assets['scripts'][$h]) && !$qlopy_assets['scripts'][$h]['in_footer']; }));
                $footerHandles = array_values(array_filter($allHandles, function($h) use ($qlopy_assets) { return !empty($qlopy_assets['scripts'][$h]) && $qlopy_assets['scripts'][$h]['in_footer']; }));
                qp_cache_delete(qp_assets_cache_key('scripts', $headerHandles));
                qp_cache_delete(qp_assets_cache_key('scripts', $footerHandles));
            }
        }
    }
}

/**
 * Find enqueued asset handles that reference any of the given extracted file paths.
 * Matches by filename and by relative path suffixes (best-effort exact matching).
 * Accepts array of paths as they appear in the package (e.g. "css/main.css", "js/app.js").
 * Returns ['styles'=>[], 'scripts'=>[]]
 */
function qp_assets_find_handles_for_files(array $files): array {
    global $qlopy_assets;
    $found = ['styles' => [], 'scripts' => []];
    if (empty($files)) return $found;

    // build candidate strings: basename and normalized relative path with/without leading slash
    $candidates = [];
    foreach ($files as $f) {
        $f = str_replace('\\', '/', trim((string)$f, " \/"));
        if ($f === '') continue;
        $base = basename($f);
        $candidates[] = $base;
        $candidates[] = $f;
        $candidates[] = '/' . $f;
        $candidates[] = rawurlencode($f);
        $candidates[] = rawurlencode('/' . $f);
    }

    // dedupe
    $candidates = array_values(array_unique($candidates));

    foreach ($qlopy_assets['styles'] as $h => $a) {
        $src = $a['src'] ?? '';
        if (!$src) continue;
        foreach ($candidates as $pat) {
            if ($pat === '') continue;
            // Check if src ends with the candidate or contains '/candidate' to avoid accidental mid-path matches
            $lowerSrc = strtolower($src);
            $lowerPat = strtolower($pat);
            if (substr($lowerSrc, -strlen($lowerPat)) === $lowerPat || strpos($lowerSrc, '/' . $lowerPat) !== false) {
                $found['styles'][] = $h; break;
            }
        }
    }
    foreach ($qlopy_assets['scripts'] as $h => $a) {
        $src = $a['src'] ?? '';
        if (!$src) continue;
        foreach ($candidates as $pat) {
            if ($pat === '') continue;
            $lowerSrc = strtolower($src);
            $lowerPat = strtolower($pat);
            if (substr($lowerSrc, -strlen($lowerPat)) === $lowerPat || strpos($lowerSrc, '/' . $lowerPat) !== false) {
                $found['scripts'][] = $h; break;
            }
        }
    }
    // unique
    $found['styles'] = array_values(array_unique($found['styles']));
    $found['scripts'] = array_values(array_unique($found['scripts']));
    return $found;
}

/**
 * Invalidate cached asset HTML for a list of extracted package files.
 * Uses `qp_assets_find_handles_for_files()` to map files -> handles and invalidates those handles.
 */
function qp_assets_invalidate_by_files(array $files) {
    if (empty($files)) return;
    try {
        $found = qp_assets_find_handles_for_files($files);
        $did = false;
        if (!empty($found['styles'])) { qp_assets_invalidate('styles', $found['styles']); $did = true; }
        if (!empty($found['scripts'])) { qp_assets_invalidate('scripts', $found['scripts']); $did = true; }
        if (!$did) {
            // fallback: attempt broad invalidation using folder heuristics
            foreach ($files as $f) {
                // if file contains a directory segment, try invalidating by that folder
                $parts = explode('/', str_replace('\\','/',$f));
                if (count($parts) > 1) {
                    qp_assets_invalidate_by_folder($parts[0]);
                }
            }
        }
    } catch (Throwable $_) {
        // best-effort: don't let invalidation break updater
    }
}

/**
 * Find enqueued asset handles for a given theme/plugin folder name.
 * Matches if the asset `src` contains the folder string (best-effort).
 * Returns ['styles'=>[], 'scripts'=>[]]
 */
function qp_assets_find_handles_for_folder(string $folder): array {
    global $qlopy_assets;
    $found = ['styles' => [], 'scripts' => []];
    if (!$folder) return $found;
    foreach ($qlopy_assets['styles'] as $h => $a) {
        if (!empty($a['src']) && strpos($a['src'], $folder) !== false) $found['styles'][] = $h;
    }
    foreach ($qlopy_assets['scripts'] as $h => $a) {
        if (!empty($a['src']) && strpos($a['src'], $folder) !== false) $found['scripts'][] = $h;
    }
    return $found;
}

/**
 * Invalidate cached asset HTML for a given theme/plugin folder.
 * Tries to find handles whose `src` contains the folder string; falls back to `qp_assets_invalidate('all')` if none found.
 */
function qp_assets_invalidate_by_folder(string $folder) {
    if (empty($folder)) return;
    $found = qp_assets_find_handles_for_folder($folder);
    $did = false;
    if (!empty($found['styles'])) {
        qp_assets_invalidate('styles', $found['styles']);
        $did = true;
    }
    if (!empty($found['scripts'])) {
        qp_assets_invalidate('scripts', $found['scripts']);
        $did = true;
    }
    if (!$did) {
        // fallback to global invalidation
        // Build candidate substrings to match against asset `src` values.
        // Prefer values from project config (`config.php`) if present, otherwise fall back to defined constants or defaults.
        $config = [];
        $configFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'config.php';
        if (is_file($configFile)) {
            try { $cfg = require $configFile; if (is_array($cfg)) $config = $cfg; } catch (Throwable $_) { $config = []; }
        }

        $content = $config['content_dir'] ?? (defined('QLOPY_CONTENT_DIR') ? QLOPY_CONTENT_DIR : (defined('CONTENT_DIR') ? CONTENT_DIR : 'content'));
        $themes = $config['themes_dir'] ?? (defined('QLOPY_THEMES_DIR') ? QLOPY_THEMES_DIR : (defined('THEMES_DIR') ? THEMES_DIR : 'themes'));
        $plugins = $config['plugins_dir'] ?? (defined('QLOPY_PLUGINS_DIR') ? QLOPY_PLUGINS_DIR : (defined('PLUGINS_DIR') ? PLUGINS_DIR : 'plugins'));

        $candidates = [];
        // common URL/path forms (both forward and backslashes)
        $forms = [
            "$content/$themes/$folder",
            "/$content/$themes/$folder",
            "$content\\$themes\\$folder",
            "$content/$plugins/$folder",
            "/$content/$plugins/$folder",
            "$content\\$plugins\\$folder",
            "$themes/$folder",
            "/$themes/$folder",
            "$plugins/$folder",
            "/$plugins/$folder",
            $folder,
        ];

        foreach ($forms as $f) {
            $candidates[] = $f;
            $candidates[] = rawurlencode($f);
        }

        foreach ($qlopy_assets['styles'] as $h => $a) {
            $src = $a['src'] ?? '';
            if (!$src) continue;
            foreach ($candidates as $pat) {
                if ($pat === '') continue;
                if (strpos($src, $pat) !== false) { $found['styles'][] = $h; break; }
            }
        }
        foreach ($qlopy_assets['scripts'] as $h => $a) {
            $src = $a['src'] ?? '';
            if (!$src) continue;
            foreach ($candidates as $pat) {
                if ($pat === '') continue;
                if (strpos($src, $pat) !== false) { $found['scripts'][] = $h; break; }
            }
        }

        if (!empty($found['styles'])) {
            qp_assets_invalidate('styles', $found['styles']);
            $did = true;
        }
        if (!empty($found['scripts'])) {
            qp_assets_invalidate('scripts', $found['scripts']);
            $did = true;
        }
        if (!$did) {
            qp_assets_invalidate('all');
        }
    }
}
