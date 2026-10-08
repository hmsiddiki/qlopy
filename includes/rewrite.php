<?php
if (!defined('QLOPY_INIT')) { http_response_code(403); exit; }
/**
 * Lightweight, fast permalink manager with optional pretty URLs
 *
 * NOTE: A `permalink_base` filter was added so code that builds permalinks
 * can override the base URL (host + subpath) used when constructing
 * permalinks. This enables runtime overrides of the base without changing
 * the configured `SITE_URL` in `config.php`.
 *
 * To revert to the original behavior, remove or comment out the
 * `apply_filters('permalink_base', ...)` calls in this file and unregister
 * any handlers attached to the `permalink_base` filter.
 */
// Lightweight, fast permalink manager with optional pretty URLs

function permalink_get_structure() {
    static $cache = null;
    if ($cache !== null) return $cache;
    if (function_exists('get_option_meta')) {
        $s = get_option_meta('permalink_structure');
        if ($s) {
            $cache = $s;
            return $cache;
        }
    }
    $cache = 'query'; // 'query' or 'pretty'
    return $cache;
}

// Permalink patterns: configurable, fast lookup
function permalink_get_patterns() {
    static $cache = null;
    if ($cache !== null) return $cache;
    $defaults = [
        'page' => '/%slug%',
        'post' => '/post/%slug%',
        'taxonomy' => '/%taxonomy%/%term%',
        'search' => '/search/%q%',
        'post_types' => [ /* e.g., 'product' => '/product/%slug%' */ ],
        'taxonomies' => [ /* e.g., 'tag' => '/tag/%term%' */ ],
    ];
    if (function_exists('get_option_meta')) {
        $p = get_option_meta('permalink_patterns');
        if (is_array($p)) {
            $cache = array_merge($defaults, $p);
            return $cache;
        }
    }
    $cache = $defaults;
    return $cache;
}

function permalink_set_patterns($patterns) {
    if (!is_array($patterns)) return false;
    if (function_exists('update_option_meta')) {
        update_option_meta('permalink_patterns', $patterns);
        return true;
    }
    return false;
}

function permalink_set_structure($structure) {
    $allowed = ['query','pretty'];
    if (!in_array($structure, $allowed)) $structure = 'query';
    if (function_exists('update_option_meta')) {
        update_option_meta('permalink_structure', $structure);
        return true;
    }
    return false;
}

// Rewrite an internal URL into configured permalink style
function permalink_rewrite_url($url) {
    $structure = permalink_get_structure();
    if ($structure !== 'pretty') return $url;
    // Detect query patterns and rewrite using configured patterns
    $parsed = parse_url($url);
    if (!$parsed) return $url;
    $query = [];
    if (!empty($parsed['query'])) parse_str($parsed['query'], $query);
    // Compute base URL if not provided
    $base = defined('SITE_URL') ? SITE_URL : '';
    if (!$base) {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? '';
        $scriptDir = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/');
        // Remove trailing admin dir if current request is from admin area
        $segments = array_filter(explode('/', $scriptDir), 'strlen');
        $admin_dir = $GLOBALS['admin_dir'] ?? ($GLOBALS['config']['admin_dir'] ?? 'admin');
        if (!empty($segments) && end($segments) === $admin_dir) {
            array_pop($segments);
        }
        $basePath = '/' . implode('/', $segments);
        if ($basePath === '/') { $basePath = ''; }
        $base = $scheme . '://' . $host . $basePath;
    }
    // Allow plugins to override the computed base (host + subpath)
    if (function_exists('apply_filters')) {
        $base = apply_filters('permalink_base', $base, $url);
    }

    $patterns = permalink_get_patterns();
    // Normalize query for relative URLs missing parse_url parts
    if (empty($parsed['query']) && isset($parsed['path']) && str_contains($parsed['path'], 'index.php') && str_contains($parsed['path'], '?')) {
        $parts = explode('?', $parsed['path'], 2);
        parse_str($parts[1], $query);
    }
    // Normalize existing pretty URLs for default post/CPT base to current pattern
    if (empty($query) && !empty($parsed['path'])) {
        $patterns = permalink_get_patterns();
        $pathOnly = trim($parsed['path'], '/');
        // Handle 'post' base
        if (preg_match('#(^|/)post/([^/]+)(/)?$#', $pathOnly, $m)) {
            $slug = $m[2];
            $newPath = str_replace('%slug%', $slug, $patterns['post']);
            return $base . $newPath;
        }
        // Handle custom post type bases defined in post_types mapping
        if (!empty($patterns['post_types']) && is_array($patterns['post_types'])) {
            foreach ($patterns['post_types'] as $ptype => $tpl) {
                if (preg_match('#(^|/)'.preg_quote($ptype, '#').'/([^/]+)(/)?$#', $pathOnly, $m)) {
                    $slug = $m[2];
                    $normalized = str_replace('%slug%', $slug, $tpl);
                    return $base . $normalized;
                }
            }
        }
    }
    // Legacy page links: index.php?page=slug
    if (isset($query['page'])) {
        $slug = trim($query['page']);
        $patterns = permalink_get_patterns();
        $path = str_replace('%slug%', $slug, $patterns['page']);
        unset($query['page']);
        $qs = http_build_query($query);
        return $base . $path . ($qs ? ('?' . $qs) : '');
    }
    // Page
    if (isset($query['route']) && $query['route'] === 'singular' && isset($query['post_type']) && $query['post_type'] === 'page' && isset($query['slug'])) {
        $path = str_replace('%slug%', trim($query['slug']), $patterns['page']);
        unset($query['route'], $query['post_type'], $query['slug']);
        $qs = http_build_query($query);
        return $base . $path . ($qs ? ('?' . $qs) : '');
    }
    // Post and CPT
    if ((isset($query['route']) && $query['route'] === 'singular' && isset($query['post_type']) && isset($query['slug'])) || isset($query['p'])) {
        $slug = $query['slug'] ?? $query['p'];
        $ptype = $query['post_type'] ?? 'post';
        $map = $patterns['post_types'] ?? [];
        $tpl = isset($map[$ptype]) ? $map[$ptype] : ($ptype === 'post' ? $patterns['post'] : '/'.$ptype.'/%slug%');
        $path = str_replace('%slug%', trim($slug), $tpl);
        unset($query['route'], $query['post_type'], $query['slug'], $query['p']);
        $qs = http_build_query($query);
        return $base . $path . ($qs ? ('?' . $qs) : '');
    }
    // Taxonomy archive (specific mapping)
    if (isset($query['route']) && $query['route'] === 'archive' && isset($query['taxonomy']) && isset($query['term'])) {
        $tax = $query['taxonomy'];
        $taxMap = $patterns['taxonomies'] ?? [];
        $path = isset($taxMap[$tax]) ? $taxMap[$tax] : $patterns['taxonomy'];
        $path = str_replace('%taxonomy%', trim($query['taxonomy']), $path);
        $path = str_replace('%term%', trim($query['term']), $path);
        unset($query['route'], $query['taxonomy'], $query['term']);
        $qs = http_build_query($query);
        return $base . $path . ($qs ? ('?' . $qs) : '');
    }
    // Search
    if (isset($query['q'])) {
        $path = str_replace('%q%', trim($query['q']), $patterns['search']);
        unset($query['route']);
        $qs = http_build_query($query);
        return $base . $path . ($qs ? ('?' . $qs) : '');
    }
    return $url;
}

// Helper to build a URL for a page slug respecting structure
function permalink_for_page($slug, $extra = []) {
    $structure = permalink_get_structure();
    if ($structure === 'pretty') {
        $base = defined('SITE_URL') ? SITE_URL : '';
        if (function_exists('apply_filters')) { $base = apply_filters('permalink_base', $base, $slug, 'page'); }
        $patterns = permalink_get_patterns();
        $path = str_replace('%slug%', ltrim($slug, '/'), $patterns['page']);
        $qs = $extra ? ('?' . http_build_query($extra)) : '';
        return $base . $path . $qs;
    }
    $params = array_merge(['page' => $slug], $extra);
    $base = defined('SITE_URL') ? SITE_URL : '';
    return $base . '/index.php?' . http_build_query($params);
}

// Helper to build a URL for a post/CPT slug respecting structure
function permalink_for_post($slug, $post_type = 'post', $extra = []) {
    $structure = permalink_get_structure();
    $base = defined('SITE_URL') ? SITE_URL : '';
    if ($structure === 'pretty') {
        $patterns = permalink_get_patterns();
        if (function_exists('apply_filters')) { $base = apply_filters('permalink_base', $base, $slug, $post_type); }
        $map = $patterns['post_types'] ?? [];
        $tpl = isset($map[$post_type]) ? $map[$post_type] : ($post_type === 'post' ? $patterns['post'] : '/' . $post_type . '/%slug%');
        if ($slug === null || $slug === '') {
            // Build archive path for this post type
            $pos = strpos($tpl, '%slug%');
            if ($pos !== false) {
                $path = rtrim(substr($tpl, 0, $pos), '/');
                if ($path === '') $path = '/' . $post_type;
            } else {
                $path = $tpl ?: ('/' . $post_type);
            }
        } else {
            $path = str_replace('%slug%', ltrim($slug, '/'), $tpl);
        }
        $qs = $extra ? ('?' . http_build_query($extra)) : '';
        return $base . $path . $qs;
    }
    $params = array_merge(['route' => 'singular', 'post_type' => $post_type, 'slug' => $slug], $extra);
    return $base . '/index.php?' . http_build_query($params);
}

// Helper to build a URL for a taxonomy term archive respecting patterns
function permalink_for_term($taxonomy, $term_slug, $extra = []) {
    $structure = permalink_get_structure();
    $base = defined('SITE_URL') ? SITE_URL : '';
    if (function_exists('apply_filters')) { $base = apply_filters('permalink_base', $base, $taxonomy, $term_slug); }
    if ($structure === 'pretty') {
        $patterns = permalink_get_patterns();
        $tpl = ($patterns['taxonomies'][$taxonomy] ?? $patterns['taxonomy']);
        $path = str_replace(['%taxonomy%','%term%'], [trim($taxonomy), ltrim($term_slug, '/')], $tpl);
        $qs = $extra ? ('?' . http_build_query($extra)) : '';
        return $base . $path . $qs;
    }
    $params = array_merge(['route' => 'archive', 'taxonomy' => $taxonomy, 'term' => $term_slug], $extra);
    return $base . '/index.php?' . http_build_query($params);
}

// Parse pretty URI into query vars for router compatibility
function parse_pretty_request($scriptDir) {
    if (permalink_get_structure() !== 'pretty') return;
    $uri = $_SERVER['REQUEST_URI'] ?? '/';
    $path = parse_url($uri, PHP_URL_PATH);
    $basePath = $scriptDir === '/' ? '' : $scriptDir;
    if ($basePath && str_starts_with($path, $basePath)) {
        $path = substr($path, strlen($basePath));
    }
    $path = trim($path, '/');
    if ($path === '' || $path === 'index.php') return;
    // Try runtime/compiled rewrite rules first (low-latency: compiled cache preferred)
    $rules = [];
    if (function_exists('get_compiled_rewrite_rules')) {
        $rules = get_compiled_rewrite_rules();
    } elseif (function_exists('get_rewrite_rules')) {
        $rules = get_rewrite_rules();
    }
    if (!empty($rules) && is_string($path)) {
        foreach ($rules as $rule) {
            // Fast prefix rejection: if rule supplied a simple prefix and path doesn't start with it, skip regex
            $rp = $rule['prefix'] ?? '';
            if ($rp !== '') {
                // compare first segment(s) quickly
                if (stripos($path, trim($rp, '/')) !== 0) {
                    continue;
                }
            }
            $pattern = '#'. $rule['regex'] . '#i';
            if (@preg_match($pattern, $path, $m)) {
                $qs = $rule['query'];
                for ($i = 1; $i < count($m); $i++) {
                    $qs = str_replace('$' . $i, rawurlencode($m[$i]), $qs);
                }
                $parsed = [];
                parse_str($qs, $parsed);
                    foreach ($parsed as $k => $v) {
                        $_GET[$k] = $v;
                        if (function_exists('is_registered_query_var') && function_exists('set_query_var') && is_registered_query_var($k)) {
                            set_query_var($k, $v);
                        }
                    }
                    // If this parsed rule is a taxonomy archive, and compiled meta
                    // supplies a single object type for the taxonomy, set post_type
                    // here so downstream code and templates see it without DB hits.
                    if (isset($parsed['route']) && $parsed['route'] === 'archive' && isset($parsed['taxonomy'])) {
                        try {
                            if (function_exists('get_compiled_rewrite_meta')) {
                                $meta = get_compiled_rewrite_meta();
                                $tax_objs = $meta['tax_object_types'] ?? [];
                                $tax = $parsed['taxonomy'];
                                if (!empty($tax_objs[$tax]) && is_array($tax_objs[$tax]) && count($tax_objs[$tax]) === 1) {
                                    $_GET['post_type'] = $tax_objs[$tax][0];
                                    if (function_exists('is_registered_query_var') && function_exists('set_query_var') && is_registered_query_var('post_type')) {
                                        set_query_var('post_type', $tax_objs[$tax][0]);
                                    }
                                }
                            }
                        } catch (Throwable $_e) {
                            // fail-open
                        }
                    }
                    // If this rule resulted in a taxonomy archive, validate the term exists using compiled meta (fast) or DB fallback
                    if (isset($parsed['route']) && $parsed['route'] === 'archive' && isset($parsed['taxonomy']) && isset($parsed['term'])) {
                        $valid = null;
                        if (function_exists('get_compiled_rewrite_meta')) {
                            $meta = get_compiled_rewrite_meta();
                            $tax_slugs = $meta['tax_slugs'] ?? [];
                            if (isset($tax_slugs[$parsed['taxonomy']])) {
                                $valid = in_array($parsed['term'], $tax_slugs[$parsed['taxonomy']], true);
                            }
                        }
                        if ($valid === null) {
                            // compiled meta not present for this taxonomy — quick DB check
                            try {
                                if (function_exists('db')) {
                                    $pdo = db();
                                    $stmt = $pdo->prepare('SELECT 1 FROM ' . table_name('taxonomy_terms') . ' WHERE taxonomy = ? AND slug = ? LIMIT 1');
                                    $stmt->execute([$parsed['taxonomy'], $parsed['term']]);
                                    $valid = (bool)$stmt->fetchColumn();
                                }
                            } catch (Throwable $_e) {
                                $valid = true; // fail-open if DB check errors
                            }
                        }
                        if (!$valid) {
                            // term missing: undo $_GET assignments and continue matching other rules
                            foreach (array_keys($parsed) as $k2) {
                                unset($_GET[$k2]);
                                if (function_exists('is_registered_query_var') && function_exists('set_query_var') && is_registered_query_var($k2)) {
                                    set_query_var($k2, null);
                                }
                            }
                            continue;
                        }
                    }
                // If this parsed rule resolved to a taxonomy base (archive without a term),
                // clear any stray `slug` or `page` values to avoid treating it as singular.
                if (isset($parsed['route']) && $parsed['route'] === 'archive' && isset($parsed['taxonomy']) && empty($parsed['term'])) {
                    if (isset($_GET['slug'])) unset($_GET['slug']);
                    if (isset($_GET['page'])) unset($_GET['page']);
                    if (function_exists('is_registered_query_var') && function_exists('set_query_var')) {
                        if (is_registered_query_var('slug')) set_query_var('slug', null);
                        if (is_registered_query_var('page')) set_query_var('page', null);
                    }
                }
                if (function_exists('_qp_set_last_matched_rewrite')) _qp_set_last_matched_rewrite($rule, $path);
                return;
            }
        }
    }
    $segments = explode('/', $path);
    $patterns = permalink_get_patterns();

    // IMPORTANT: If post pattern is root '/%slug%', prefer post over page to avoid ambiguity
    $postPatternRoot = trim($patterns['post'], '/');
    if ($postPatternRoot === '%slug%' && count($segments) >= 1) {
        $_GET['route'] = 'singular';
        $_GET['post_type'] = 'post';
        $_GET['slug'] = $segments[0];
        return;
    }
    // Similarly, if any CPT pattern is root '/%slug%', we cannot disambiguate
    // but prefer the CPT when the site config sets it. Check first segment against CPT slugs when their tpl is '%slug%'
    if (!empty($patterns['post_types']) && is_array($patterns['post_types'])) {
        foreach ($patterns['post_types'] as $ptype => $tpl) {
            $tplTrim = trim($tpl, '/');
            if ($tplTrim === '%slug%' && !empty($segments[0])) {
                $_GET['route'] = 'singular';
                $_GET['post_type'] = $ptype;
                $_GET['slug'] = $segments[0];
                return;
            }
        }
    }

    // ORDER: Page → Post → Taxonomy → Fallback (Search handled below if present)
    // TAXONOMY BASE: support single-segment URLs like `/destinations` to
    // map to the taxonomy archive when the segment matches a registered
    // taxonomy or an explicit taxonomy mapping in patterns. This allows
    // taxonomy base URLs to be used even when a page with the same slug
    // exists (site owners may prefer taxonomy behavior).
    if (count($segments) === 1) {
        $firstSeg = $segments[0];
        // Check explicit taxonomy mappings in patterns (e.g. 'destinations' => '/destinations')
        if (!empty($patterns['taxonomies']) && is_array($patterns['taxonomies'])) {
            foreach ($patterns['taxonomies'] as $tax => $tpl) {
                $tplTrim = trim($tpl, '/');
                // match when mapping is exactly the taxonomy base (no %term%)
                    if ($tplTrim === $tax && $firstSeg === $tax) {
                    $_GET['route'] = 'archive';
                    $_GET['taxonomy'] = $tax;
                    // If compiled meta contains a single object type for this taxonomy,
                    // set it so templates/queries can rely on it without extra DB calls.
                    try {
                        if (function_exists('get_compiled_rewrite_meta')) {
                            $meta = get_compiled_rewrite_meta();
                            $tax_objs = $meta['tax_object_types'] ?? [];
                            if (!empty($tax_objs[$tax]) && is_array($tax_objs[$tax]) && count($tax_objs[$tax]) === 1) {
                                $_GET['post_type'] = $tax_objs[$tax][0];
                            }
                        }
                    } catch (Throwable $_e) { }
                    // clear stray slug/page for taxonomy base
                    if (isset($_GET['slug'])) unset($_GET['slug']);
                    if (isset($_GET['page'])) unset($_GET['page']);
                    if (function_exists('is_registered_query_var') && function_exists('set_query_var')) {
                        if (is_registered_query_var('slug')) set_query_var('slug', null);
                        if (is_registered_query_var('page')) set_query_var('page', null);
                    }
                    return;
                }
            }
        }
        // Fall back to registered taxonomy names (if the site registers 'destinations' as a taxonomy)
        // Only treat taxonomies that opted into front routing via `front_route`.
        $registered = function_exists('get_taxonomies') ? get_taxonomies() : ($GLOBALS['qlopy_taxonomies'] ?? []);
        if (!empty($registered) && isset($registered[$firstSeg]) && !empty($registered[$firstSeg]['front_route'])) {
            $_GET['route'] = 'archive';
            $_GET['taxonomy'] = $firstSeg;
            // try to set post_type from registered tax data (fast, in-memory) if single
            if (!empty($registered[$firstSeg]['object_types']) && is_array($registered[$firstSeg]['object_types'])) {
                $objs = array_values($registered[$firstSeg]['object_types']);
                    if (count($objs) === 1) $_GET['post_type'] = $objs[0];
                }
                // clear stray slug/page for taxonomy base
                if (isset($_GET['slug'])) unset($_GET['slug']);
                if (isset($_GET['page'])) unset($_GET['page']);
                if (function_exists('is_registered_query_var') && function_exists('set_query_var')) {
                    if (is_registered_query_var('slug')) set_query_var('slug', null);
                    if (is_registered_query_var('page')) set_query_var('page', null);
                }
                return;
        }
    }

    // Page pattern first
    $pagePattern = trim($patterns['page'], '/');
    if ($pagePattern) {
        $pageRegex = '#^' . str_replace(['%slug%','/'], ['([^/]+)','\/'], preg_quote($pagePattern, '#')) . '$#';
        if (preg_match($pageRegex, $path, $m)) { $_GET['route'] = 'singular'; $_GET['post_type'] = 'page'; $_GET['slug'] = $m[1]; return; }
        if (substr_count($pagePattern, '/') === 0 && $pagePattern === '%slug%' && count($segments) === 1 && !empty($segments[0])) { $_GET['route'] = 'singular'; $_GET['post_type'] = 'page'; $_GET['slug'] = $segments[0]; return; }
    }
    // Post pattern second
    $postPattern = trim($patterns['post'], '/');
    if ($postPattern) {
        $postRegex = '#^' . str_replace(['%slug%','/'], ['([^/]+)','\/'], preg_quote($postPattern, '#')) . '$#';
        if (preg_match($postRegex, $path, $m)) { $_GET['route'] = 'singular'; $_GET['post_type'] = 'post'; $_GET['slug'] = $m[1]; return; }
    }
    if (!empty($patterns['post_types']) && is_array($patterns['post_types'])) {
        foreach ($patterns['post_types'] as $ptype => $tpl) {
            $tplTrim = trim($tpl, '/');
            $cptRegex = '#^' . str_replace(['%slug%','/'], ['([^/]+)','\/'], preg_quote($tplTrim, '#')) . '$#';
            if (preg_match($cptRegex, $path, $m)) { $_GET['route']='singular'; $_GET['post_type']=$ptype; $_GET['slug']=$m[1]; return; }
        }
    }
    // Taxonomy pattern /%taxonomy%/%term% and specific maps
    $taxPattern = trim($patterns['taxonomy'], '/');
    if (count($segments) >= 2) {
        // Avoid treating reserved bases (like search base) as taxonomy names
        $reserved = [];
        if (!empty($patterns['search'])) {
            $sp = trim($patterns['search'], '/');
            $pos = strpos($sp, '%q%');
            if ($pos !== false) { $reserved[] = trim(substr($sp, 0, $pos), '/'); }
        }
        if (!empty($patterns['page'])) {
            $pp = trim($patterns['page'], '/');
            if (strpos($pp, '%slug%') !== false) {
                $base = trim(str_replace('%slug%', '', $pp), '/'); if ($base) $reserved[] = $base;
            }
        }
        if (!empty($patterns['post'])) {
            $bp = trim($patterns['post'], '/');
            if (strpos($bp, '%slug%') !== false) {
                $base = trim(str_replace('%slug%', '', $bp), '/'); if ($base) $reserved[] = $base;
            }
        }
        if (!empty($patterns['post_types']) && is_array($patterns['post_types'])) {
            foreach ($patterns['post_types'] as $tpl) {
                $tp = trim($tpl, '/');
                if (strpos($tp, '%slug%') !== false) {
                    $base = trim(str_replace('%slug%', '', $tp), '/'); if ($base) $reserved[] = $base;
                }
            }
        }
        if (!empty($reserved)) { $reserved = array_unique($reserved); }
            $first = $segments[0];
        if (!empty($reserved) && in_array($first, $reserved, true)) {
            // Skip taxonomy parsing for reserved bases; search/page/post will handle
        } else {
            foreach ($patterns['taxonomies'] as $tax => $tpl) {
                $tpl = trim($tpl, '/');
                if ($tpl === $tax.'/%term%' && $first === $tax) { $_GET['route'] = 'archive'; $_GET['taxonomy'] = $tax; $_GET['term'] = $segments[1]; return; }
            }
        }
        if (empty($reserved) || !in_array($segments[0], $reserved, true)) {
            if ($taxPattern === '%taxonomy%/%term%') {
                $candidate = $segments[0];
                $is_valid_tax = false;
                // 1) explicit taxonomy mappings in patterns
                if (!empty($patterns['taxonomies']) && is_array($patterns['taxonomies']) && isset($patterns['taxonomies'][$candidate])) {
                    $is_valid_tax = true;
                }
                // 2) compiled rewrite meta: check tax_slugs
                if (!$is_valid_tax && function_exists('get_compiled_rewrite_meta')) {
                    try {
                        $meta = get_compiled_rewrite_meta();
                        $tax_slugs = $meta['tax_slugs'] ?? [];
                        if (isset($tax_slugs[$candidate])) $is_valid_tax = true;
                    } catch (Throwable $_e) { }
                }
                // 3) registered taxonomies (only those with front routing enabled)
                if (!$is_valid_tax && function_exists('get_taxonomies')) {
                    $regs = get_taxonomies();
                    if (!empty($regs) && isset($regs[$candidate]) && !empty($regs[$candidate]['front_route'])) $is_valid_tax = true;
                } elseif (!$is_valid_tax) {
                    $regs = $GLOBALS['qlopy_taxonomies'] ?? [];
                    if (!empty($regs) && isset($regs[$candidate]) && !empty($regs[$candidate]['front_route'])) $is_valid_tax = true;
                }
                if ($is_valid_tax) {
                    // validate the term exists for this taxonomy (prefer compiled meta, fallback to DB)
                    $valid = null;
                    if (function_exists('get_compiled_rewrite_meta')) {
                        try {
                            $meta = get_compiled_rewrite_meta();
                            $tax_slugs = $meta['tax_slugs'] ?? [];
                            if (isset($tax_slugs[$candidate])) {
                                $valid = in_array($segments[1], $tax_slugs[$candidate], true);
                            }
                        } catch (Throwable $_e) { }
                    }
                    if ($valid === null) {
                        try {
                            if (function_exists('db')) {
                                $pdo = db();
                                $stmt = $pdo->prepare('SELECT 1 FROM ' . table_name('taxonomy_terms') . ' WHERE taxonomy = ? AND slug = ? LIMIT 1');
                                $stmt->execute([$candidate, $segments[1]]);
                                $valid = (bool)$stmt->fetchColumn();
                            }
                        } catch (Throwable $_e) { $valid = true; }
                    }
                    if (!$valid) {
                        // don't treat as taxonomy term if the term is missing
                    } else {
                        $_GET['route'] = 'archive';
                        $_GET['taxonomy'] = $candidate;
                        $_GET['term'] = $segments[1];
                        return;
                    }
                }
                // else: skip treating this as taxonomy and fall through to other patterns
            }
        }
    }
    // Search /search/%q% after taxonomy per requested order
    $searchPattern = trim($patterns['search'], '/');
    if (count($segments) >= 2 && $searchPattern) {
        $searchRegex = '#^' . str_replace(['%q%','/'], ['([^/]+)','\/'], preg_quote($searchPattern, '#')) . '$#';
        if (preg_match($searchRegex, $path, $m)) { $_GET['route'] = 'search'; $_GET['q'] = $m[1]; return; }
    }
    // Fallback: first segment becomes page
    if (empty($_GET['page'])) { $_GET['page'] = $segments[0]; }
}

?>