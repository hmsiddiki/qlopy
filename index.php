<?php
// index.php — Front Controller for subfolder /advcms
// Mark as authorized public entrypoint for include-only files
if (!defined('QLOPY_INIT')) define('QLOPY_INIT', true);



// If config.php is not present, redirect to installer (compute path dynamically)
if (!file_exists(__DIR__ . '/config.php')) {
    $base = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
    if ($base === '/' || $base === '.') $base = '';
    header('Location: ' . $base . '/install.php');
    exit;
}

// Load config (DB install check removed for runtime performance)
$config = require_once __DIR__ . '/config.php';
$admin_dir = $config['admin_dir'] ?? 'admin';
$content_dir = $config['content_dir'] ?? 'content';
$themes_dir = $config['themes_dir'] ?? 'themes';
$plugins_dir = $config['plugins_dir'] ?? 'plugins';
$uploads_dir = $config['uploads_dir'] ?? 'uploads';

require_once __DIR__ . '/auth.php';
// Initialize token-based auth (migrates to token if needed)
if (function_exists('auth_init_from_token')) {
    auth_init_from_token();
}
// Use centralized action/filter system
require_once __DIR__ . '/includes/ajax-hooks.php';
require_once __DIR__ . '/includes/post-types.php';
require_once __DIR__ .'/'.$admin_dir. '/admin-ajax-hook.php';
require_once __DIR__ . '/includes/qpmeta.php';
require_once __DIR__ . '/includes/admin-menus.php';
require_once __DIR__ . '/includes/assets.php';
// QP-Cron integration removed; scheduler runner and page-trigger fallback deleted.

// --- Begin centralized rewrite integration ---
require_once __DIR__ . '/includes/rewrite.php';
// Load query-vars early so rewrite parsing can consult runtime rules/cache
require_once __DIR__ . '/includes/query-vars.php';
// Compute URI relative to SITE_URL base
$base_url_path = parse_url(SITE_URL, PHP_URL_PATH) ?: '';
$base_url_path = rtrim($base_url_path, '/');
$uri = $_SERVER['REQUEST_URI'] ?? '/';
// Use only the path portion for routing; ignore query string
$uri = parse_url($uri, PHP_URL_PATH) ?? '/';
if ($base_url_path !== '' && stripos($uri, $base_url_path) === 0) {
    $uri = substr($uri, strlen($base_url_path));
}
$uri = trim(urldecode($uri), '/');
// Precompute segments once
$segments = ($uri !== '') ? explode('/', $uri) : [];
// Strong early mapping for common 'post' base to avoid mis-inference (override if needed)
if (!empty($segments) && $segments[0] === 'post') {
    if (isset($segments[1]) && $segments[1] !== '') {
        $_GET['route'] = 'singular';
        $_GET['post_type'] = 'post';
        $_GET['slug'] = $segments[1];
    } else {
        $_GET['route'] = 'archive';
        $_GET['post_type'] = 'post';
    }
}

// Default routing for query-string mode and as a fallback before pretty rewrite parsing
if (!isset($_GET['route'])) {
    if (!empty($_GET['page'])) {
        $_GET['route'] = 'singular';
        $_GET['post_type'] = 'page';
        $_GET['slug'] = $_GET['page'];
    } else {
        $_GET['route'] = ($uri === '') ? 'frontpage' : '404';
    }
}
// --- End centralized rewrite integration ---

// Lightweight query object for themes and templates
require_once __DIR__ . '/includes/query.php';

// Load core comments API for frontend templates
require_once __DIR__ . '/includes/comments.php';



// Load theme and plugin system
$theme_dir_root = __DIR__ . "/$content_dir/$themes_dir/";

// Trust DB option for active theme; fall back to 'default' if unset
$stmt = $pdo->prepare("SELECT option_value FROM " . table_name('site_options') . " WHERE option_name = 'active_theme' LIMIT 1");
$stmt->execute();
$active_theme = $stmt->fetchColumn() ?: 'default';

// Clean preview handling: only administrators can preview themes on
// the frontend. Preview is driven by a simple `?preview_theme=slug`
// param and/or a short-lived cookie `qp_preview_theme`.
$preview_theme = null;

if (is_logged_in() && function_exists('check_permission') && check_permission('manage_themes')) {
    // Exit preview explicitly
    if (!empty($_GET['exit_preview'])) {
        setcookie('qp_preview_theme', '', time() - 3600, '/', '', false, true);
        $redir = strtok($_SERVER['REQUEST_URI'], '?') ?: '/';
        header('Location: ' . $redir);
        exit;
    }

    // Start/change preview via query param
    if (!empty($_GET['preview_theme'])) {
        $candidate = preg_replace('/[^a-z0-9._\-]/i', '', $_GET['preview_theme']);
        if ($candidate !== '') {
            $dir = $theme_dir_root . $candidate . '/';
            if (is_dir($dir)) {
                $preview_theme = $candidate;
                setcookie('qp_preview_theme', $candidate, time() + 3600, '/', '', false, true);
            }
        }
    }

    // If no query param but cookie exists, continue previewing
    if (!$preview_theme && !empty($_COOKIE['qp_preview_theme'])) {
        $candidate = preg_replace('/[^a-z0-9._\-]/i', '', $_COOKIE['qp_preview_theme']);
        if ($candidate !== '') {
            $dir = $theme_dir_root . $candidate . '/';
            if (is_dir($dir)) {
                $preview_theme = $candidate;
            } else {
                // Clear stale preview cookie if theme no longer exists
                setcookie('qp_preview_theme', '', time() - 3600, '/', '', false, true);
            }
        }
    }

    if ($preview_theme) {
        $active_theme = $preview_theme;
        if (!defined('PREVIEWING_THEME')) define('PREVIEWING_THEME', true);
    }
} else {
    // Non-admins should never see a preview; clear any leftover cookie
    if (!empty($_COOKIE['qp_preview_theme'])) {
        setcookie('qp_preview_theme', '', time() - 3600, '/', '', false, true);
    }
}
// Resolve final theme directory, with fallback to 'default' and
// a friendly error if no usable theme is available.
$theme_dir = $theme_dir_root . $active_theme . '/';
if (!is_dir($theme_dir)) {
    $fallback_theme = 'default';
    if ($active_theme !== $fallback_theme) {
        $fallback_dir = $theme_dir_root . $fallback_theme . '/';
        if (is_dir($fallback_dir)) {
            $active_theme = $fallback_theme;
            $theme_dir = $fallback_dir;
        }
    }
}

if (!is_dir($theme_dir)) {
    http_response_code(500);
    echo "<!DOCTYPE html>\n<html><head><meta charset=\"utf-8\"><title>Theme not available</title>";
    echo "<style>body{font-family:system-ui,-apple-system,BlinkMacSystemFont,\"Segoe UI\",sans-serif;background:#f5f7fb;color:#111827;margin:40px;}";
    echo "h1{font-size:24px;margin-bottom:12px;}p{margin:6px 0;}</style></head><body>";
    echo "<h1>No theme is available to render this site</h1>";
    echo "<p>The active theme is not installed, and the default theme could not be found.</p>";
    echo "<p>Please upload a theme into the content/themes directory and activate it from the admin Themes screen.</p>";
    echo "</body></html>";
    exit;
}

define('THEME_URL', SITE_URL . "/$content_dir/$themes_dir/$active_theme");
////moved PLUGIN_URL definition to auth.php to avoid circular dependency with plugin.php

// Include theme functions for the resolved active theme
if (file_exists($theme_dir . 'functions.php')) {
    include_once $theme_dir . 'functions.php';
}

// Debug helper removed: `?qp_dbg=1` support removed for production.

// Load active plugins by trusting the DB list; missing plugin
// directories/files are simply skipped.
$plugin_dir = __DIR__ . "/$content_dir/$plugins_dir/";
$stmt = $pdo->prepare("SELECT option_value FROM " . table_name('site_options') . " WHERE option_name = 'active_plugins' LIMIT 1");
$stmt->execute();
$active_plugins_json = $stmt->fetchColumn();
$active_plugins = $active_plugins_json ? json_decode($active_plugins_json, true) : [];
if (is_array($active_plugins) && is_dir($plugin_dir)) {
    foreach ($active_plugins as $plugin => $v) {
        $slug = is_string($plugin) && !is_numeric($plugin) ? $plugin : (is_string($v) ? $v : null);
        if (!$slug) continue;
        $plugin_file = $plugin_dir . $slug . '/plugin.php';
        if (is_file($plugin_file)) {
            include_once $plugin_file;
        }
    }
}


do_action('init'); // Plugins and theme can hook here
// Now that themes/plugins had a chance to register rewrite rules and
// query vars, ensure compiled rewrite cache includes rules registered
// during init when pretty permalinks are enabled. This avoids 404s for
// archives (e.g. /post/) if the cache was generated earlier without
// those rules. We perform a cheap check and flush only when necessary.
if (function_exists('permalink_get_structure') && permalink_get_structure() === 'pretty') {
    if (function_exists('get_compiled_rewrite_rules') && function_exists('get_rewrite_rules') && function_exists('flush_rewrite_rules')) {
        try {
            $compiled = get_compiled_rewrite_rules();
            $has_post_rules = false;
            if (is_array($compiled)) {
                foreach ($compiled as $r) {
                    if (!empty($r['query']) && strpos($r['query'], 'post_type=post') !== false) { $has_post_rules = true; break; }
                }
            }
            if (!$has_post_rules) {
                // Rebuild compiled cache from current in-memory rules
                flush_rewrite_rules(get_rewrite_rules());
            }
        } catch (Throwable $_e) {
            // Fail silently — parsing will fall back to other logic.
        }
    }
}

// Now that themes/plugins had a chance to register rewrite rules and
// query vars, parse the request to populate `qp_query_vars`.
parse_request(dirname($_SERVER['SCRIPT_NAME']) ?: '');
// Expose a stable hook after the router has populated query vars and globals.
// Plugins can register frontend enqueues and template decisions on `qp_init`.
do_action('qp_init');
// Trigger page-fallback cron after plugins/themes have had a chance to register jobs
if (function_exists('qp_maybe_trigger_page_cron')) qp_maybe_trigger_page_cron();

// Start output buffering so we can inject preview topbar when needed
if (!ob_get_level()) ob_start();

// Register shutdown handler to inject preview bar HTML into the final output
register_shutdown_function(function() use ($active_theme) {
        // Only modify if buffering and previewing session is set
        $buf = '';
        if (ob_get_level()) {
                $buf = ob_get_clean();
        }
        // Only admins with manage_themes permission see the preview bar
        if (!is_logged_in() || !function_exists('check_permission') || !check_permission('manage_themes')) {
            echo $buf; return;
        }
        if (!defined('PREVIEWING_THEME') || !PREVIEWING_THEME) { echo $buf; return; }
        // Build a direct HTML preview bar (no JS dependency).
        $preview = htmlspecialchars($active_theme, ENT_QUOTES, 'UTF-8');
        $path = strtok($_SERVER['REQUEST_URI'], '?') ?: '/';
        $exit_url = $path . (strpos($path, '?') === false ? '?' : '&') . 'exit_preview=1';

        $bar_html = "<!-- QLOPY-PREVIEW-BAR-START -->";
        $bar_html .= "<div id=\"qlopy-preview-bar\" style=\"position:fixed;top:0;left:0;right:0;z-index:2147483647;background:#0f1720;color:#fff;padding:10px 14px;font-family:Inter,Arial,sans-serif;display:flex;align-items:center;justify-content:space-between;gap:12px;box-shadow:0 2px 6px rgba(0,0,0,.4);\">";
        $bar_html .= "<div style=\"display:flex;align-items:center;gap:12px\"><strong style=\"font-weight:600;font-size:14px\">Previewing theme: " . $preview . "</strong></div>";
        $bar_html .= "<div><a href=\"" . htmlspecialchars($exit_url, ENT_QUOTES, 'UTF-8') . "\" style=\"color:#fff;background:#111;padding:6px 10px;border-radius:6px;text-decoration:none;border:1px solid rgba(255,255,255,0.06)\">Exit preview</a></div>";
        $bar_html .= "</div><div style=\"height:64px\"></div>";
        $bar_html .= "<!-- QLOPY-PREVIEW-BAR-END -->";

        $content = $buf;
        // Prefer to insert directly after the opening <body ...> tag using a quote-aware scan
        $posBody = stripos($content, '<body');
        $posClose = false;
        if ($posBody !== false) {
                $len = strlen($content);
                $inDouble = false;
                $inSingle = false;
                for ($i = $posBody; $i < $len; $i++) {
                        $ch = $content[$i];
                        if ($ch === '"' && !$inSingle) { $inDouble = !$inDouble; }
                        elseif ($ch === "'" && !$inDouble) { $inSingle = !$inSingle; }
                        elseif ($ch === '>' && !$inDouble && !$inSingle) { $posClose = $i + 1; break; }
                }
        }

        if ($posClose !== false) {
                $new = substr($content, 0, $posClose) . $bar_html . substr($content, $posClose);
                echo $new;
                return;
        }

        // Fallback: prepend (rare). This keeps behavior predictable if body tag missing.
        echo $bar_html . $content;
});

// Determine route from normalized/query vars
if (!isset($_GET['route']) && !empty($segments)) {
    // Final smoothing fallback before deciding route
    if (count($segments) === 1) {
        // Single segment: likely a page slug
        $_GET['route'] = 'singular';
        $_GET['post_type'] = 'page';
        $_GET['slug'] = $segments[0];
    } elseif (count($segments) >= 2) {
        $first = $segments[0];
        $second = $segments[1];
        $matched_pt = null;
        if (!empty($GLOBALS['qlopy_post_types'])) {
            foreach ($GLOBALS['qlopy_post_types'] as $pt => $args) {
                $pt_slug = $args['slug'] ?? $pt;
                if ($pt === 'page' && $pt_slug === '') continue;
                if ($first === $pt_slug) { $matched_pt = $pt; break; }
            }
        }
        if (!$matched_pt) {
            // Default to 'post' if no PT slug matched
            $matched_pt = 'post';
        }
        if ($second === '' || $second === null) {
            $_GET['route'] = 'archive';
            $_GET['post_type'] = $matched_pt;
        } else {
            $_GET['route'] = 'singular';
            $_GET['post_type'] = $matched_pt;
            $_GET['slug'] = $second;
        }
    }
}
$route = $_GET['route'] ?? (($uri === '') ? 'frontpage' : '404');

// --- TEMPLATE LOADING ---

switch ($route) {
    case 'search':
        $q = $_GET['q'] ?? '';
        include $theme_dir . 'search.php';
        exit;
    case 'frontpage':
        $stmt = $pdo->prepare("SELECT option_value FROM " . table_name('site_options') . " WHERE option_name = 'front_page_option' LIMIT 1");
        $stmt->execute();
        $front_page_option = $stmt->fetchColumn() ?: 'theme';
        if ($front_page_option === 'static') {
            $stmt = $pdo->prepare("SELECT option_value FROM " . table_name('site_options') . " WHERE option_name = 'front_page_id' LIMIT 1");
            $stmt->execute();
            $front_post_id = intval($stmt->fetchColumn() ?: 0);
            if ($front_post_id > 0) {
                // Load the selected page row and expose to the theme template
                $stmt = $pdo->prepare("SELECT * FROM " . table_name('posts') . " WHERE id = ? AND post_type = 'page' LIMIT 1");
                $stmt->execute([$front_post_id]);
                $page = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($page) {
                    // Provide globals expected by the page template and get_header()
                    $post = $page;
                    $GLOBALS['front_page_option'] = 'static';
                    $GLOBALS['front_post_id'] = (int)$front_post_id;
                    // Optional: set a site title for header usage
                    $stmt = $pdo->prepare("SELECT option_value FROM " . table_name('site_options') . " WHERE option_name = 'site_name' LIMIT 1");
                    $stmt->execute();
                    $GLOBALS['page_title'] = $stmt->fetchColumn() ?: ($page['title'] ?? '');
                    include $theme_dir . 'page.php';
                    exit;
                }
            }
        }
        if ($front_page_option === 'archive') {
            include $theme_dir . 'archive.php';
            exit;
        }
        // Default "theme" home: provide latest posts to the theme
        $stmt = $pdo->prepare("SELECT * FROM " . table_name('posts') . " WHERE post_type = 'post' AND status = 'published' ORDER BY created_at DESC LIMIT 10");
        $stmt->execute();
        $posts = $stmt->fetchAll(PDO::FETCH_ASSOC);
        include $theme_dir . 'index.php';
        exit;

    case 'archive':
        // Allow taxonomy-specific templates: taxonomy-{taxonomy}.php, taxonomy.php
        $requested_post_type = $_GET['post_type'] ?? null;
        $term = $_GET['term'] ?? '';
        $taxonomy = $_GET['taxonomy'] ?? '';

        // If taxonomy archive, prefer taxonomy templates first (WP-like behavior).
        if ($taxonomy) {
            // If an earlier pass (e.g. legacy page fallback) set `post_type=page`,
            // ignore that value — taxonomy archives should not be constrained
            // to the `page` post type. Clear `post_type`/`slug` so mapping below
            // can detect the correct object type for this taxonomy.
            if (!empty($requested_post_type) && $requested_post_type === 'page') {
                unset($_GET['post_type']);
                $requested_post_type = null;
                if (!empty($_GET['slug'])) unset($_GET['slug']);
            }
            // 1) taxonomy-{taxonomy}-{term}.php
            if ($term) {
                $term_specific_tpl = $theme_dir . 'taxonomy-' . $taxonomy . '-' . $term . '.php';
                if (file_exists($term_specific_tpl)) {
                    $template = $term_specific_tpl;
                }
            }
            // 2) taxonomy-{taxonomy}.php
            if (empty($template)) {
                $tax_tpl = $theme_dir . 'taxonomy-' . $taxonomy . '.php';
                if (file_exists($tax_tpl)) {
                    $template = $tax_tpl;
                }
            }
            // 3) generic taxonomy.php
            if (empty($template)) {
                $tax_generic_tpl = $theme_dir . 'taxonomy.php';
                if (file_exists($tax_generic_tpl)) {
                    $template = $tax_generic_tpl;
                }
            }

            // If no explicit post_type provided and this taxonomy maps to exactly one
            // registered object type, set it so archive-{post_type}.php fallback works.
            if (empty($requested_post_type)) {
                $taxes = function_exists('get_taxonomies') ? get_taxonomies() : ($GLOBALS['qlopy_taxonomies'] ?? []);
                if (!empty($taxes[$taxonomy]['object_types']) && is_array($taxes[$taxonomy]['object_types'])) {
                    $obj_types = array_values($taxes[$taxonomy]['object_types']);
                    if (count($obj_types) === 1) {
                        $post_type = $obj_types[0];
                        // Reflect into $_GET for any downstream code that checks it
                        $_GET['post_type'] = $post_type;
                    } else {
                        $post_type = 'post';
                    }
                } else {
                    $post_type = 'post';
                }
            } else {
                $post_type = $requested_post_type;
            }
        } else {
            $post_type = $requested_post_type ?? 'post';
        }

        // Template hierarchy: taxonomy.php (handled), archive-{term}.php, archive-{post_type}.php, archive.php
        if ($term) {
            $term_tpl = $theme_dir . 'archive-' . $term . '.php';
            if (file_exists($term_tpl)) {
                $template = $term_tpl;
            }
        }
        $pt_tpl = $theme_dir . 'archive-' . $post_type . '.php';
        if (empty($template) && file_exists($pt_tpl)) {
            $template = $pt_tpl;
        }
        if (empty($template)) {
            $template = $theme_dir . 'archive.php';
        }
        if (function_exists('apply_filters')) {
            $maybe = apply_filters('template_include', $template);
            if (is_string($maybe) && $maybe !== '') $template = $maybe;
        }
        if (file_exists($template)) { 
            include $template; exit; }
        exit;

    case 'singular':
        $post_type = $_GET['post_type'] ?? 'post';
        $slug = $_GET['slug'] ?? '';

    // Block static home page direct access
    if ($post_type === 'page' && $slug) {
        $stmt = $pdo->prepare("SELECT option_value FROM " . table_name('site_options') . " WHERE option_name = 'front_page_id' LIMIT 1");
        $stmt->execute();
        $front_post_id = intval($stmt->fetchColumn() ?: 0);
        if ($front_post_id > 0) {
            $stmt = $pdo->prepare("SELECT slug FROM " . table_name('posts') . " WHERE id = ? AND post_type = 'page'");
            $stmt->execute([$front_post_id]);
            $front_page_row = $stmt->fetch(PDO::FETCH_ASSOC);
            $front_slug = $front_page_row['slug'] ?? '';
            // If hitting front page slug directly, but not at "/"
            if ($slug === $front_slug) {
                http_response_code(404);
                $tmpl404 = ($GLOBALS['theme_dir'] ?? '') . '404.php';
                if ($tmpl404 && file_exists($tmpl404)) {
                    $page_title = 'Not Found';
                    $GLOBALS['qlopy_rendering_404'] = true;
                    include $tmpl404;
                    unset($GLOBALS['qlopy_rendering_404']);
                } else {
                    echo '<h1>404 Not Found</h1>';
                }
                exit;
            }
        }
    }

    if (!$slug) {
        http_response_code(404);
        $tmpl404 = ($GLOBALS['theme_dir'] ?? '') . '404.php';
        if ($tmpl404 && file_exists($tmpl404)) {
            $page_title = 'Not Found';
            $GLOBALS['qlopy_rendering_404'] = true;
            include $tmpl404;
            unset($GLOBALS['qlopy_rendering_404']);
        } else {
            echo '<h1>404 Not Found</h1>';
        }
        exit;
    }

    $stmt = $pdo->prepare("SELECT * FROM " . table_name('posts') . " WHERE slug = ? AND post_type = ? AND status = 'published' LIMIT 1");
    $stmt->execute([$slug, $post_type]);
    $post = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$post && $post_type === 'post') {
        // Fallback: sometimes initial content is stored as page
        $stmt = $pdo->prepare("SELECT * FROM " . table_name('posts') . " WHERE slug = ? AND post_type = 'page' AND status = 'published' LIMIT 1");
        $stmt->execute([$slug]);
        $post = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($post) {
            $post_type = 'page';
        }
    }
    if (!$post) {
        http_response_code(404);
        $tmpl404 = ($GLOBALS['theme_dir'] ?? '') . '404.php';
        if ($tmpl404 && file_exists($tmpl404)) {
            $page_title = 'Not Found';
            $GLOBALS['qlopy_rendering_404'] = true;
            include $tmpl404;
            unset($GLOBALS['qlopy_rendering_404']);
        } else {
            echo '<h1>404 Not Found</h1>';
            echo '<pre style="padding:1rem; background:#f8f9fa; border:1px solid #ddd">';
            echo 'Debug info:\n';
            echo 'URI: ' . htmlspecialchars($uri) . "\n";
            echo 'Route: singular\n';
            echo 'post_type: ' . htmlspecialchars($post_type) . "\n";
            echo 'slug: ' . htmlspecialchars($slug) . "\n";
            if (function_exists('permalink_get_structure')) {
                echo 'Permalink structure: ' . htmlspecialchars(permalink_get_structure()) . "\n";
            }
            echo '</pre>';
        }
        exit;
    }
    $page_title = $post['title'];

        // If this is a page, allow per-page template selection and page.php fallback
        if ($post_type === 'page') {
            $template_file = get_post_meta($post['id'], 'page_template');
            if ($template_file && is_string($template_file) && file_exists($theme_dir . $template_file)) {
                include $theme_dir . $template_file; exit;
            }
            if (file_exists($theme_dir . 'page.php')) { include $theme_dir . 'page.php'; exit; }
        }

        // Singular template hierarchy for posts and custom post types:
        // 1) single-{post_type}-{slug}.php
        // 2) single-{post_type}.php
        // 3) single.php
        $specific_tpl = $theme_dir . 'single-' . $post_type . '-' . $slug . '.php';
        $pt_tpl = $theme_dir . 'single-' . $post_type . '.php';
        $single_tpl = $theme_dir . 'single.php';

        // Template selection order: specific -> post-type -> single
        $template = null;
        if (file_exists($specific_tpl)) { $template = $specific_tpl; }
        elseif (file_exists($pt_tpl)) { $template = $pt_tpl; }
        elseif (file_exists($single_tpl)) { $template = $single_tpl; }

        if (empty($template)) {
            // Final fallback will render inline if no template is found
            $template = null;
        } else {
            if (function_exists('apply_filters')) {
                $maybe = apply_filters('template_include', $template);
                if (is_string($maybe) && $maybe !== '') $template = $maybe;
            }
            if ($template && file_exists($template)) { include $template; exit; }
        }

        // Final fallback: render inline
        include $theme_dir . 'header.php';
        echo '<article class="container mt-4">';
        echo '<h1>' . htmlspecialchars($post['title']) . '</h1>';
        echo '<div>'; if (function_exists('qp_the_content')) { qp_the_content($post); } else { echo $post['content']; } echo '</div>';
        echo '</article>';
        include $theme_dir . 'footer.php';
        exit;


    default:
        http_response_code(404);
        $tmpl404 = ($GLOBALS['theme_dir'] ?? '') . '404.php';
        if ($tmpl404 && file_exists($tmpl404)) {
            $GLOBALS['qlopy_rendering_404'] = true;
            include $tmpl404;
            unset($GLOBALS['qlopy_rendering_404']);
        } else {
            echo '<h1>404 Not Found</h1>';
            echo '<pre style="padding:1rem; background:#f8f9fa; border:1px solid #ddd">';
            echo 'Debug info:\n';
            echo 'URI: ' . htmlspecialchars($uri) . "\n";
            echo 'Route: ' . htmlspecialchars($route) . "\n";
            echo 'Query vars:\n';
            foreach (['post_type','slug','taxonomy','term'] as $k) {
                if (isset($_GET[$k])) echo '  ' . $k . ': ' . htmlspecialchars($_GET[$k]) . "\n";
            }
            echo "\n";
            if (function_exists('permalink_get_structure')) {
                echo 'Permalink structure: ' . htmlspecialchars(permalink_get_structure()) . "\n";
            }
            echo '</pre>';
        }
        exit;
}
