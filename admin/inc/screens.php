<?php
if (!defined('QLOPY_INIT')) { http_response_code(403); exit; }
/**
 * admin/inc/screens.php
 *
 * Lightweight QP_Screen system for admin pages.
 *
 * Purpose:
 * - Compute a deterministic "screen id" for the current admin request so
 *   code can conditionally run or enqueue assets only when necessary.
 * - Keep runtime cost minimal: no DB calls, small string ops, static cache.
 * - Provide admin-only hooks via `do_admin_action('admin_enqueue_{id}', $screen)`
 *   and `do_admin_action('admin_screen_{id}', $screen)` so existing
 *   `add_admin_action()` callbacks can be used.
 * - Allow plugins/themes to register custom URL-to-screen resolvers via
 *   `qp_screen_register_pattern($name, $resolver)`.
 *
 * API (helpers provided):
 * - get_admin_screen(): array { id, base, action, params }
 * - current_admin_screen(): same as get_admin_screen()
 * - is_screen($id_or_pattern): bool
 * - admin_enqueue_for_screen($screen_id, callable $cb)
 * - qp_screen_register_pattern($name, callable $resolver)
 *
 * Naming rules (deterministic):
 * - index.php?page=slug            => slug
 * - media.php                      => media_library
 * - posts.php?post_type=slug       => slug_list | slug_edit | slug_create
 * - posts.php?custom_post_type=slug=> slug_list | slug_edit | slug_create
 * - taxonomies.php?taxonomy=slug   => slug_list | slug_edit | slug_create
 * - users.php                      => users_list | users_edit
 * - fallback                       => {file}_list or {file}_{action}
 *
 * Performance:
 * - The detection result is cached in a static variable; repeated calls
 *   are cheap. Keep resolvers minimal and avoid heavy regex.
 */

if (!isset($GLOBALS['qp_screen_resolvers'])) $GLOBALS['qp_screen_resolvers'] = [];

if (!function_exists('qp_screen_register_pattern')) {
    /**
     * Register a custom resolver.
     * Resolver signature: fn(string $script, array $get): ?array
     * Return a screen array or null to continue fallback detection.
     */
    function qp_screen_register_pattern(string $name, callable $resolver): void {
        $GLOBALS['qp_screen_resolvers'][$name] = $resolver;
    }
}

if (!function_exists('get_admin_screen')) {
    function get_admin_screen(): array {
        static $cached = null;
        if ($cached !== null) return $cached;

        // Allow a global override if code needs to force the id early
        if (!empty($GLOBALS['FORCE_ADMIN_SCREEN_ID'])) {
            $forced = (string)$GLOBALS['FORCE_ADMIN_SCREEN_ID'];
            $cached = ['id' => $forced, 'base' => $forced, 'action' => 'forced', 'params' => $_GET];
            return $cached;
        }

        $script = basename($_SERVER['SCRIPT_NAME'] ?? $_SERVER['PHP_SELF'] ?? '');
        $qs = $_GET;
        $base = preg_replace('/\.php$/i', '', $script);

        // If admin menus declared a specific screen_id for this page/file,
        // honor it. This allows `register_admin_menu(..., screen_id)` to
        // control the mapping without extra code.
        if (!empty($GLOBALS['admin_menus']) && is_array($GLOBALS['admin_menus'])) {
            // If page param corresponds to a registered menu with an explicit screen_id, honor it.
                if (!empty($qs['page']) && isset($GLOBALS['admin_menus'][$qs['page']]) && !empty($GLOBALS['admin_menus'][$qs['page']]['screen_id'])) {
                    $sid = $GLOBALS['admin_menus'][$qs['page']]['screen_id'];
                    $screen = ['id' => $sid, 'base' => $sid, 'action' => 'view', 'params' => $qs, 'qtype' => null];
                    return $cached = $screen;
                }

                // If this request is a callback-style page (index.php?page=slug), prefer the page slug
                // as the screen id even when other menus reference index.php as their php_file.
                if ($base === 'index' && !empty($qs['page'])) {
                    $page = function($s){ $s = preg_replace('/[^a-z0-9_\-]/i', '_', (string)$s); $s = trim($s, '_-'); return $s === '' ? 'none' : strtolower($s); };
                    $page_s = $page($qs['page']);
                    $screen = ['id' => $page_s, 'base' => $page_s, 'action' => 'view', 'params' => $qs, 'qtype' => null];
                    return $cached = $screen;
                }

                // If current script filename matches a menu php_file with screen_id, use that mapping.
                foreach ($GLOBALS['admin_menus'] as $m) {
                    if (!empty($m['php_file']) && !empty($m['screen_id'])) {
                        if (basename($m['php_file']) === $script) {
                            $sid = $m['screen_id'];
                            $screen = ['id' => $sid, 'base' => $sid, 'action' => $qs['action'] ?? 'view', 'params' => $qs, 'qtype' => null];
                            return $cached = $screen;
                        }
                    }
                }
        }

        // Allow resolver hooks to provide custom screen mapping
        foreach ($GLOBALS['qp_screen_resolvers'] as $resolver) {
            try {
                $res = $resolver($script, $qs);
                if (is_array($res) && isset($res['id'])) {
                    $res['params'] = $qs;
                        if (!isset($res['qtype'])) $res['qtype'] = null;
                        return $cached = $res;
                }
            } catch (Throwable $e) {
                // swallow resolver exceptions to avoid breaking admin
            }
        }

        $screen = ['id' => $base, 'base' => $base, 'action' => '', 'params' => $qs, 'qtype' => null];

        $sanitize = function($s) {
            $s = (string)$s;
            $s = preg_replace('/[^a-z0-9_\-]/i', '_', $s);
            $s = trim($s, '_-');
            return $s === '' ? 'none' : strtolower($s);
        };

        // index.php?page=... (plugin/menu pages)
        if ($base === 'index' && !empty($qs['page'])) {
            $page = $sanitize($qs['page']);
            $screen['base'] = $page;
            $screen['action'] = 'view';
            $screen['id'] = $page;
            $screen['params'] = $qs;
            return $cached = $screen;
        }

        // media.php -> media_library
        if ($base === 'media') {
            $screen['base'] = 'media_library';
            $screen['action'] = $qs['action'] ?? 'list';
            $screen['id'] = 'media_library';
            $screen['params'] = $qs;
            return $cached = $screen;
        }

        // posts.php: support both post_type and custom_post_type
        if ($base === 'posts') {
            $post_type = $sanitize($qs['post_type'] ?? $qs['custom_post_type'] ?? 'post');
            $action = $qs['action'] ?? ($qs['do'] ?? '');
            if (in_array($action, ['new','add'])) $action = 'create';
            $is_edit = ($action === 'edit') || isset($qs['id']) || isset($qs['post']);
            if ($is_edit) {
                $screen['action'] = 'edit';
                $screen['id'] = "{$post_type}_edit";
            } else if ($action === 'create') {
                $screen['action'] = 'create';
                $screen['id'] = "{$post_type}_create";
            } else {
                $screen['action'] = 'list';
                $screen['id'] = "{$post_type}_list";
            }
            $screen['base'] = $post_type;
            // qtype indicates this is from posts.php
            $screen['qtype'] = 'posts';
            $screen['params'] = $qs;
            return $cached = $screen;
        }

        // taxonomies.php
        if ($base === 'taxonomies') {
            $taxonomy = $sanitize($qs['taxonomy'] ?? '');
            if ($taxonomy === '') {
                $screen['action'] = 'list';
                $screen['id'] = 'taxonomy_list';
            } else {
                $is_edit = (isset($qs['edit']) && $qs['edit']) || ($qs['action'] ?? '') === 'edit' || isset($qs['id']);
                if ($is_edit) {
                    $screen['action'] = 'edit';
                    $screen['id'] = "{$taxonomy}_edit";
                } else {
                    $screen['action'] = 'list';
                    $screen['id'] = "{$taxonomy}_list";
                }
                $screen['base'] = $taxonomy;
            }
            // qtype indicates this group of screens originates from taxonomies.php
            $screen['qtype'] = 'taxonomies';
            $screen['params'] = $qs;
            return $cached = $screen;
        }

        // users.php
        if ($base === 'users') {
            $action = $qs['action'] ?? '';
            if ($action === 'edit' || isset($qs['id'])) {
                $screen['id'] = 'users_edit';
                $screen['action'] = 'edit';
            } else {
                $screen['id'] = 'users_list';
                $screen['action'] = 'list';
            }
            $screen['params'] = $qs;
            return $cached = $screen;
        }

        // Fallback: {file}_list or {file}_{action}
        $screen['action'] = ($qs['action'] ?? '') ?: 'list';
        if (in_array($screen['action'], ['edit','create'])) {
            $screen['id'] = $sanitize($screen['base'] . '_' . $screen['action']);
        } else {
            $screen['id'] = $sanitize($screen['base'] . '_list');
        }
        $screen['params'] = $qs;
        return $cached = $screen;
    }
}

if (!function_exists('current_admin_screen')) {
    function current_admin_screen(): array { return get_admin_screen(); }
}

if (!function_exists('is_screen')) {
    /**
     * Convenience check for current screen.
     * Accepts exact id, array of ids, or simple wildcard with '*'.
     */
    function is_screen($id_or_pattern): bool {
        $screen = current_admin_screen();
        if (is_array($id_or_pattern)) return in_array($screen['id'], $id_or_pattern, true);
        if (strpos((string)$id_or_pattern, '*') !== false) {
            $pattern = str_replace('*', '.*', preg_quote($id_or_pattern, '/'));
            return (bool)preg_match('/^' . $pattern . '$/i', $screen['id']);
        }
        return strcasecmp($screen['id'], (string)$id_or_pattern) === 0;
    }
}

if (!function_exists('admin_enqueue_for_screen')) {
    /**
     * Convenience wrapper to register an enqueue callback for a screen.
     * It uses the existing admin action system (add_admin_action).
     */
    function admin_enqueue_for_screen(string $screen_id, callable $cb): void {
        // add_admin_action stores callbacks executed via do_admin_action
        add_admin_action('admin_enqueue_' . $screen_id, $cb);
    }
}

// Provide a function to fire admin enqueue and screen init hooks for current screen.
// Call `qp_fire_screen_hooks()` after plugin/theme `init` so callbacks registered
// during `init` are invoked. This prevents firing hooks on include time.
if (!function_exists('qp_fire_screen_hooks')) {
    function qp_fire_screen_hooks(): void {
        $__qp_screen = get_admin_screen();
        // Use admin-only action dispatcher so plugins/themes can register via add_admin_action
        do_admin_action('admin_enqueue_' . $__qp_screen['id'], $__qp_screen);
        do_admin_action('admin_screen_' . $__qp_screen['id'], $__qp_screen);
    }
}

?>
