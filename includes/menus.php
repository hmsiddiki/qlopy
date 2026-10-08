<?php
if (!defined('QLOPY_INIT')) { http_response_code(403); exit; }
// Menu system using DB options storage (no file)

function menus_load_all() {
    static $cache = null;
    if ($cache !== null) return $cache;
    if (function_exists('get_option_meta')) {
        $stored = get_option_meta('menus_data');
        if (is_array($stored)) {
            $stored['locations'] = $stored['locations'] ?? [];
            $stored['locations_map'] = $stored['locations_map'] ?? [];
            $stored['menus'] = $stored['menus'] ?? [];
            $cache = $stored;
            return $cache;
        }
    }
    // Migration: import from legacy content/menus.json if present (respect configured content dir)
    $cfg = $GLOBALS['config'] ?? (file_exists(__DIR__ . '/../config.php') ? require __DIR__ . '/../config.php' : []);
    $content_dir = $cfg['content_dir'] ?? 'content';
    $legacyPath = dirname(__DIR__) . '/' . $content_dir . '/menus.json';
    if (is_file($legacyPath)) {
        $json = @file_get_contents($legacyPath);
        $data = json_decode($json, true);
        if (is_array($data)) {
            $data['locations'] = $data['locations'] ?? [];
            $data['locations_map'] = $data['locations_map'] ?? [];
            $data['menus'] = $data['menus'] ?? [];
            menus_save_all($data);
            @unlink($legacyPath);
            $cache = $data;
            return $cache;
        }
    }
    $cache = [ 'locations' => [], 'locations_map' => [], 'menus' => [] ];
    return $cache;
}

function menus_save_all($data) {
    $data['locations'] = $data['locations'] ?? [];
    $data['locations_map'] = $data['locations_map'] ?? [];
    $data['menus'] = $data['menus'] ?? [];
    if (function_exists('update_option_meta')) {
        update_option_meta('menus_data', $data);
    }
    // Refresh in-request cache so subsequent menus_load_all() calls in this request see updated data
    static $cache = null;
    $cache = $data;
}

// API: register menu locations (theme)
function register_menu_location($location_key, $label) {
    $all = menus_load_all();
    $all['locations'][$location_key] = $label;
    menus_save_all($all);
}

// API: assign a menu to a location
function assign_menu_to_location($location_key, $menu_slug) {
    $all = menus_load_all();
    $all['locations_map'][$location_key] = $menu_slug; // derived map
    menus_save_all($all);
}

// API: create/update menu structure
function set_menu_structure($menu_slug, $menu_label, $items) {
    $all = menus_load_all();
    $all['menus'][$menu_slug] = [ 'label' => $menu_label, 'items' => $items ];
    menus_save_all($all);
}

function get_menu_structure($menu_slug) {
    $all = menus_load_all();
    return $all['menus'][$menu_slug] ?? [ 'label' => $menu_slug, 'items' => [] ];
}

function get_menu_locations() {
    $all = menus_load_all();
    return $all['locations'] ?? [];
}

function get_location_menu($location_key) {
    $all = menus_load_all();
    $slug = $all['locations_map'][$location_key] ?? null;
    return $slug ? get_menu_structure($slug) : null;
}

// Walker-like render with hooks
function apply_menu_filter($hook, $value, $args = []) {
    if (function_exists('apply_filter')) {
        return apply_filter($hook, $value, $args);
    }
    if (function_exists('apply_filters')) {
        // adapt to plural apply_filters signature
        return apply_filters($hook, $value, $args);
    }
    return $value;
}

/**
 * Render a menu with flexible markup.
 * Args supported (all optional):
 *  - container_class: string CSS class(es) for top container (set to null to omit the class attribute)
 *  - container: tag name for top container (ul|ol|div|nav) default 'ul'
 *  - item_tag: tag name for each item (li|div) default 'li'
 *  - submenu_container: tag for sub menus (ul|ol|div) default same as container
 *  - submenu_class: CSS class for sub menu container (default 'sub-menu')
 *  - before: HTML before the container (default '')
 *  - after: HTML after the container (default '')
 *  - framework: 'bootstrap3'|'bootstrap4'|'bootstrap5'|'bs3'|'bs4'|'bs5' to auto-select bootstrap walker
 *  - walker: custom walker instance (overrides framework)
 */
function render_menu($menu_slug, $args = []) {
    $menu = get_menu_structure($menu_slug);
    /**
     * render_menu docs:
     *
    * Outputs HTML for a menu. Args available:
    *  - container_class: CSS classes for the top container (set to null to omit the class attribute)
    *  - container: top container tag (''/null = no wrapper)
     *  - item_tag: tag for each menu item element (e.g. 'li' or 'div')
     *  - submenu_container: tag for submenu wrapper (e.g. 'ul' or 'div') or '' for inline
     *  - submenu_item_tag: tag for submenu items (falls back to item_tag)
     *  - submenu_class: CSS class for submenu container
     *  - before/after: HTML to output before/after the menu
     *  - framework: 'bs4'|'bs5'|'plain' — used for walkers or default conventions
     *  - walker: custom walker instance
     *  - max_depth: maximum depth to render (0 = unlimited)
     *  - child_class: CSS class added to items that have children (default 'menu-item-has-children')
     *  - active_class: CSS class applied to active items (default 'active')
    *  - add_active_to: where to add active class: 'item'|'link'|'both' (default 'item').
    *      Accepts legacy values 'li' (mapped to 'item') and 'a' (mapped to 'link').
     *
     * Example: Bootstrap 4
     * echo render_menu_for_location('primary', [
     *   'framework' => 'bs4',
     *   'container' => 'ul',
     *   'item_tag' => 'li',
     *   'submenu_container' => 'div',
     *   'submenu_class' => 'dropdown-menu',
    *   'item_class_callback' => function($item,$depth,$args){ (return nav-item/dropdown) }
    *   'link_class_callback' => function($item,$depth,$args){ (return nav-link/dropdown-toggle) }
     * ]);
     *
     * Example: Plain nested UL/LI
     * echo render_menu_for_location('primary', [
     *   'framework' => 'plain',
     *   'container' => 'ul',
     *   'item_tag' => 'li',
     *   'submenu_container' => 'ul',
     *   'submenu_item_tag' => 'li',
     *   'submenu_class' => 'list-unstyled',
     * ]);
     */

    $defaults = [
        // top-level container tag. Empty string means "no wrapper" (useful for BS navs)
        'container' => 'ul',
        // wrapper tag for each menu item at top-level
        'item_tag' => 'li',
        // wrapper used for submenu containers (can be '' to render children inline)
        'submenu_container' => null,
        // CSS class applied to submenu containers
        'submenu_class' => 'sub-menu',
        'before' => '',
        'after' => '',
        'framework' => null,
        'walker' => null,
        // default max depth (site option may override)
        'max_depth' => 2,
        // optional: custom class for the top-level container. If the key is present and set to null,
        // no class attribute will be rendered. If omitted, no class attribute will be rendered by default.
        'container_class' => null,
        // optional: class(es) to add to each item element (LI or configured item_tag). If null, nothing is added.
        'item_class' => null,
        // propagate active state from children to parents when true
        'propagate_active' => false,
        // optional: tag to use for submenu items (when depth>0). If null, falls back to item_tag
        'submenu_item_tag' => null,
        'item_class_callback' => null,
        'link_class_callback' => null,
        'child_class' => 'menu-item-has-children',
        'active_class' => 'active',
        'add_active_to' => 'item', // 'item'|'link'|'both' (supports legacy 'li'/'a')
    ];

    // If a site option exists for menu_max_depth use it as default
    try {
        if (function_exists('db')) {
            $pdo = db();
            $stmt = $pdo->prepare('SELECT option_value FROM ' . table_name('site_options') . ' WHERE option_name = ? LIMIT 1');
            $stmt->execute(['menu_max_depth']);
            $opt = $stmt->fetchColumn();
            if ($opt !== false && is_numeric($opt)) {
                $defaults['max_depth'] = (int)$opt;
            }
        }
    } catch (Exception $e) {
        // ignore DB errors — keep default
    }
    $args = array_merge($defaults, $args);
    if ($args['submenu_container'] === null) $args['submenu_container'] = $args['container'];

    // Allow themes/plugins to override the max depth for menus
    $args['max_depth'] = apply_menu_filter('menu_max_depth', $args['max_depth'], $args);

    // Auto framework walker selection if no explicit walker given.
    if (!$args['walker'] && $args['framework']) {
        $fw = strtolower($args['framework']);
        if (in_array($fw, ['bootstrap3','bs3'])) $args['walker'] = new Bootstrap_Nav_Walker(3);
        elseif (in_array($fw, ['bootstrap4','bs4'])) $args['walker'] = new Bootstrap_Nav_Walker(4);
        elseif (in_array($fw, ['bootstrap5','bootstrap','bs5'])) $args['walker'] = new Bootstrap_Nav_Walker(5);
        elseif (in_array($fw, ['plain','default','none'])) $args['walker'] = new Plain_Nav_Walker();
    }

    $items = $menu['items'] ?? [];
    // If propagation of active state is requested, walk the item tree and mark parents active
    if (!empty($args['propagate_active'])) {
        // recursive helper: mark an item active if any descendant or itself matches current path
        $mark_active = function (&$list) use (&$mark_active) {
            $current_path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '/';
            foreach ($list as &$it) {
                $children = $it['children'] ?? [];
                if (!empty($children)) {
                    $mark_active($it['children']);
                }
                // determine if this item is active by explicit flag or path match
                $raw_url = build_menu_item_url($it);
                $item_path = parse_url($raw_url, PHP_URL_PATH) ?: '/';
                $is_active = false;
                if (!empty($it['active'])) $is_active = true;
                if (rtrim($current_path, '/') === rtrim($item_path, '/')) $is_active = true;
                // if any child is active, mark this item active too
                $child_active = false;
                foreach (($it['children'] ?? []) as $c) {
                    if (!empty($c['active'])) { $child_active = true; break; }
                }
                if ($child_active) $is_active = true;
                if ($is_active) $it['active'] = true;
            }
        };
        $mark_active($items);
    }
    $items = apply_menu_filter('menu_items_pre_render', $items, $args);
    $walker = $args['walker'];

    // Allow empty container ('' or null) to mean no wrapping element.
    $container_tag = ($args['container'] === '' || $args['container'] === null) ? '' : (preg_match('/^[a-zA-Z][a-zA-Z0-9:-]*$/', $args['container']) ? $args['container'] : 'ul');
    $item_tag = preg_match('/^[a-zA-Z][a-zA-Z0-9:-]*$/', $args['item_tag']) ? $args['item_tag'] : 'li';
    // submenu item tag: use provided value when valid, else fallback to item_tag
    $submenu_item_tag = isset($args['submenu_item_tag']) && preg_match('/^[a-zA-Z][a-zA-Z0-9:-]*$/', $args['submenu_item_tag']) ? $args['submenu_item_tag'] : $item_tag;
    $args['container'] = $container_tag;
    $args['item_tag'] = $item_tag;
    $args['submenu_item_tag'] = $submenu_item_tag;

    $html = $args['before'];
    if ($container_tag !== '') {
        // container_class semantics: if provided (even null), use it; otherwise no class attribute is rendered.
        if (array_key_exists('container_class', $args)) {
            $container_class_val = $args['container_class'];
        } else {
            $container_class_val = '';
        }
        if ($container_class_val === null || $container_class_val === '') {
            $html .= '<' . $container_tag . '>';
        } else {
            $html .= '<' . $container_tag . ' class="' . htmlspecialchars($container_class_val) . '">';
        }
        foreach ($items as $item) {
            $html .= render_menu_item($item, $walker, $args, 0);
        }
        $html .= '</' . $container_tag . '>';
    } else {
        // No top-level wrapper requested: render items directly
        foreach ($items as $item) {
            $html .= render_menu_item($item, $walker, $args, 0);
        }
    }
    $html .= $args['after'];
    return apply_menu_filter('menu_html', $html, [ 'menu' => $menu, 'args' => $args ]);
}

/**
 * Convenience: render menu assigned to a location key.
 * If no menu is assigned, returns empty string.
 */
function render_menu_for_location($location_key, $args = []) {
    $all = menus_load_all();
    $slug = $all['locations_map'][$location_key] ?? null;
    if (!$slug) return '';
    return render_menu($slug, $args);
}

function build_menu_item_url($item) {
    $type = $item['type'] ?? null;
    $slug = $item['slug'] ?? null;
    if ($type && $slug) {
        if ($type === 'page') {
            if (function_exists('permalink_for_page')) {
                return permalink_for_page($slug);
            }
            return (defined('SITE_URL') ? SITE_URL : '') . '/index.php?page=' . rawurlencode($slug);
        }
        if ($type === 'post') {
            $post_type = $item['post_type'] ?? 'post';
            if (function_exists('permalink_for_post')) {
                return permalink_for_post($slug, $post_type);
            }
            $base = defined('SITE_URL') ? SITE_URL : '';
            $url = $base . '/index.php?route=singular&post_type=' . rawurlencode($post_type) . '&slug=' . rawurlencode($slug);
            if (function_exists('permalink_rewrite_url')) {
                return permalink_rewrite_url($url);
            }
            return $url;
        }
        if ($type === 'taxonomy') {
            $taxonomy = $item['taxonomy'] ?? '';
            $base = defined('SITE_URL') ? SITE_URL : '';
            $url = $base . '/index.php?route=archive&taxonomy=' . rawurlencode($taxonomy) . '&term=' . rawurlencode($slug);
            if (function_exists('permalink_rewrite_url')) {
                return permalink_rewrite_url($url);
            }
            return $url;
        }
    }
    $custom = $item['url'] ?? '#';
    if (function_exists('qp_do_shortcodes')) {
        try { $custom = qp_do_shortcodes($custom); } catch (Throwable $_) { /* ignore shortcode errors */ }
    }
    return $custom;
}

function render_menu_item($item, $walker, $args, $depth) {
    // Allow walker to fully override generation (including children) by returning a string.
    if ($walker && is_callable([$walker, 'start_el'])) {
        $override = $walker->start_el($item, $args, $depth);
        if (is_string($override)) return $override; // walker handled everything
    }

    $title = htmlspecialchars($item['title'] ?? '');
    $raw_url = build_menu_item_url($item);
    if (function_exists('permalink_rewrite_url')) {
        $raw_url = permalink_rewrite_url($raw_url);
    }
    // Ensure any shortcodes in the URL are expanded (defensive) and then
    // sanitize for use in an href attribute.
    if (function_exists('qp_do_shortcodes')) {
        try { $raw_url = qp_do_shortcodes($raw_url); } catch (Throwable $_) { /* ignore */ }
    }
    if (function_exists('qp_esc_url')) {
        $raw_url = qp_esc_url($raw_url);
    }
    $url = htmlspecialchars($raw_url, ENT_QUOTES, 'UTF-8');
    $cls = htmlspecialchars($item['class'] ?? '');
    $has_children = !empty($item['children']);
    $base_item_classes = ['menu-item'];
    if ($has_children) $base_item_classes[] = $args['child_class'] ?? 'menu-item-has-children';
    if ($cls) $base_item_classes[] = $cls;
    // apply per-item class from args if explicitly provided (null means no class)
    if (array_key_exists('item_class', $args) && $args['item_class'] !== null && $args['item_class'] !== '') {
        $base_item_classes[] = $args['item_class'];
    }
    if (is_callable($args['item_class_callback'])) {
        $custom_item_cls = $args['item_class_callback']($item, $depth, $args);
        if (is_string($custom_item_cls) && $custom_item_cls !== '') $base_item_classes[] = $custom_item_cls;
    }
    $liClass = htmlspecialchars(implode(' ', $base_item_classes));
    // Allow a different item tag for submenu items
    $item_tag = ($depth > 0 && !empty($args['submenu_item_tag'])) ? $args['submenu_item_tag'] : $args['item_tag'];
    $link_classes = [];
    if (is_callable($args['link_class_callback'])) {
        $maybe = $args['link_class_callback']($item, $depth, $args);
        if (is_string($maybe) && $maybe !== '') $link_classes[] = $maybe;
    }
    $link_class_attr = $link_classes ? ' class="' . htmlspecialchars(implode(' ', $link_classes)) . '"' : '';
    // Determine active state: item explicit flag OR URL match
    $is_active = false;
    if (!empty($item['active'])) {
        $is_active = true;
    } else {
        // Compare path-only to determine active item (robust across hosts)
        $current_path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '/';
        $item_path = parse_url($raw_url, PHP_URL_PATH) ?: '/';
        if (rtrim($current_path, '/') === rtrim($item_path, '/')) $is_active = true;
    }

    $html = '<' . $item_tag . ' class="' . $liClass . '">';
    // If active class should be added to link, adjust link_class_attr
    // normalize add_active_to (accept legacy values)
    $add_active_to_raw = strtolower($args['add_active_to'] ?? 'item');
    if ($add_active_to_raw === 'li') $add_active_to = 'item';
    elseif ($add_active_to_raw === 'a') $add_active_to = 'link';
    else $add_active_to = $add_active_to_raw;

    $link_class_attr_final = $link_class_attr;
    if ($is_active && in_array($add_active_to, ['link','both'])) {
        $link_class_attr_final = preg_replace('/class="([^"]*)"/', 'class="$1 ' . htmlspecialchars($args['active_class']) . '"', $link_class_attr_final);
        if ($link_class_attr_final === $link_class_attr) {
            // no class attr existed; add it
            $link_class_attr_final = ' class="' . htmlspecialchars($args['active_class']) . '"';
        }
    }
    $html .= '<a' . $link_class_attr_final . ' href="' . $url . '">' . $title . '</a>';
    if ($has_children) {
        $sub_tag = isset($args['submenu_container']) ? $args['submenu_container'] : '';
        $sub_cls = htmlspecialchars($args['submenu_class']);
        if ($sub_tag === '' || $sub_tag === null) {
            // render children inline without an extra container
            foreach ($item['children'] as $child) {
                $html .= render_menu_item($child, $walker, $args, $depth + 1);
            }
        } else {
            $html .= '<' . $sub_tag . ' class="' . trim($sub_cls) . '">';
            foreach ($item['children'] as $child) {
                $html .= render_menu_item($child, $walker, $args, $depth + 1);
            }
            $html .= '</' . $sub_tag . '>';
        }
    }
    $html .= '</' . $item_tag . '>';

    // attach active class to item (opening tag) if requested
    if ($is_active && in_array($add_active_to, ['item','both'])) {
        // If the opening tag already has a class attribute, append the active class to it.
        if (preg_match('/^<(\w+)([^>]*)class="([^"]*)"/', $html)) {
            $html = preg_replace('/^<(\w+)([^>]*)class="([^"]*)"/', '<$1$2class="$3 ' . htmlspecialchars($args['active_class']) . '"', $html, 1);
        } else {
            // No existing class attribute: insert one on the opening tag.
            $html = preg_replace('/^<(\w+)([^>]*)>/', '<$1$2 class="' . htmlspecialchars($args['active_class']) . '">', $html, 1);
        }
    }

    if ($walker && is_callable([$walker, 'end_el'])) {
        $end = $walker->end_el($item, $args, $depth);
        if (is_string($end)) $html .= $end;
    }
    return $html;
}

// Default walker class example (minimal)
class Simple_Nav_Walker {
    // By default walkers may return a string to fully override rendering for an item.
    // Implementations may override start_el() to return HTML for a single item.
    public function start_el($item, $args, $depth = 0) { return null; }
    public function end_el($item, $args, $depth = 0) { return null; }
}

// Plain walker: renders a basic nested UL/LI structure and respects add_active_to
class Plain_Nav_Walker extends Simple_Nav_Walker {
    public function start_el($item, $args, $depth = 0) {
        $title = htmlspecialchars($item['title'] ?? '');
        $raw_url = build_menu_item_url($item);
        if (function_exists('permalink_rewrite_url')) {
            $raw_url = permalink_rewrite_url($raw_url);
        }
        $url = htmlspecialchars($raw_url);
        $has_children = !empty($item['children']);

        // normalize add_active_to
        $add_active_to_raw = strtolower($args['add_active_to'] ?? 'item');
        if ($add_active_to_raw === 'li') $add_active_to = 'item';
        elseif ($add_active_to_raw === 'a') $add_active_to = 'link';
        else $add_active_to = $add_active_to_raw;

        // determine active
        $is_active = false;
        if (!empty($item['active'])) $is_active = true;
        else {
            $current_path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '/';
            $item_path = parse_url($raw_url, PHP_URL_PATH) ?: '/';
            if (rtrim($current_path, '/') === rtrim($item_path, '/')) $is_active = true;
        }

        // build item classes
        $classes = ['menu-item'];
        if ($has_children) $classes[] = $args['child_class'] ?? 'menu-item-has-children';
        if (!empty($item['class'])) $classes[] = $item['class'];
        if (array_key_exists('item_class', $args) && $args['item_class'] !== null && $args['item_class'] !== '') $classes[] = $args['item_class'];
        if ($is_active && in_array($add_active_to, ['item','both'])) $classes[] = $args['active_class'] ?? 'active';

        $item_tag = ($depth > 0 && !empty($args['submenu_item_tag'])) ? $args['submenu_item_tag'] : $args['item_tag'];

        $html = '<' . $item_tag . ' class="' . htmlspecialchars(implode(' ', $classes)) . '">';

        // link classes (allow callback)
        $link_classes = [];
        if (is_callable($args['link_class_callback'])) {
            $maybe = $args['link_class_callback']($item, $depth, $args);
            if (is_string($maybe) && $maybe !== '') $link_classes[] = $maybe;
        }
        if ($is_active && in_array($add_active_to, ['link','both'])) $link_classes[] = $args['active_class'] ?? 'active';
        $link_attr = $link_classes ? ' class="' . htmlspecialchars(implode(' ', $link_classes)) . '"' : '';

        $html .= '<a' . $link_attr . ' href="' . $url . '">' . $title . '</a>';

        if ($has_children) {
            $sub_tag = isset($args['submenu_container']) ? $args['submenu_container'] : '';
            $sub_cls = htmlspecialchars($args['submenu_class'] ?? 'sub-menu');
            if ($sub_tag === '' || $sub_tag === null) {
                // render children inline
                foreach ($item['children'] as $child) {
                    // delegate to walker if available
                    if (isset($args['walker']) && is_object($args['walker']) && is_callable([$args['walker'], 'start_el'])) {
                        $html .= $args['walker']->start_el($child, $args, $depth + 1);
                    } else {
                        // fallback to basic rendering
                        $plainChild = new Plain_Nav_Walker();
                        $html .= $plainChild->start_el($child, $args, $depth + 1);
                    }
                }
            } else {
                $html .= '<' . $sub_tag . ' class="' . trim($sub_cls) . '">';
                foreach ($item['children'] as $child) {
                    if (isset($args['walker']) && is_object($args['walker']) && is_callable([$args['walker'], 'start_el'])) {
                        $html .= $args['walker']->start_el($child, $args, $depth + 1);
                    } else {
                        $plainChild = new Plain_Nav_Walker();
                        $html .= $plainChild->start_el($child, $args, $depth + 1);
                    }
                }
                $html .= '</' . $sub_tag . '>';
            }
        }

        $html .= '</' . $item_tag . '>';
        return $html;
    }

    public function end_el($item, $args, $depth = 0) { return null; }
}

// Bootstrap aware walker
class Bootstrap_Nav_Walker extends Simple_Nav_Walker {
    protected $version;
    public function __construct($version = 5) { $this->version = (int)$version; }

    public function start_el($item, $args, $depth = 0) {
        // We'll fully render the element including children to leverage bootstrap classes.
        $has_children = !empty($item['children']);
        $title = htmlspecialchars($item['title'] ?? '');
        $raw_url = build_menu_item_url($item);
        if (function_exists('permalink_rewrite_url')) {
            $raw_url = permalink_rewrite_url($raw_url);
        }
        $url = htmlspecialchars($raw_url);
        $user_cls = trim($item['class'] ?? '');
        // Determine active state (path-only compare) or explicit flag
        $is_active = false;
        if (!empty($item['active'])) {
            $is_active = true;
        } else {
            $current_path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '/';
            $item_path = parse_url($raw_url, PHP_URL_PATH) ?: '/';
            if (rtrim($current_path, '/') === rtrim($item_path, '/')) $is_active = true;
        }
        $item_tag = $args['item_tag'];

        // Determine configured max depth (0 or null means unlimited)
        $max_depth = isset($args['max_depth']) ? (int)$args['max_depth'] : 0;

        // Normalize add_active_to for walker usage (support legacy 'li'/'a')
        $add_active_to_raw = strtolower($args['add_active_to'] ?? 'item');
        if ($add_active_to_raw === 'li') $add_active_to = 'item';
        elseif ($add_active_to_raw === 'a') $add_active_to = 'link';
        else $add_active_to = $add_active_to_raw;

        // If this is a child item (depth > 0) we may render it as a dropdown item
        // If a max depth is set and we've reached it, render a simple anchor (leaf)
        if ($depth > 0) {
            if ($has_children && ($max_depth === 0 || $depth < $max_depth)) {
                // Render a nested dropdown submenu (dropdown-submenu pattern)
                $li_classes = ['dropdown-submenu'];
                if ($is_active && in_array($add_active_to, ['item','both'])) $li_classes[] = ($args['active_class'] ?? 'active');
                $html = '<' . $item_tag . ' class="' . htmlspecialchars(implode(' ', $li_classes)) . '">';
                $html .= '<a class="dropdown-item dropdown-toggle" href="' . $url . '">' . $title . '</a>';
                $sub_tag = $args['submenu_container'];
                $sub_cls = htmlspecialchars('dropdown-menu ' . ($args['submenu_class'] ?? ''));
                $html .= '<' . $sub_tag . ' class="' . trim($sub_cls) . '">';
                foreach ($item['children'] as $child) {
                    $html .= $this->start_el($child, $args, $depth + 1);
                }
                $html .= '</' . $sub_tag . '>';
                $html .= '</' . $item_tag . '>';
                return $html;
            }
            // Otherwise render a simple dropdown item (leaf)
            $leaf_classes = ['dropdown-item'];
            if ($is_active && in_array($add_active_to, ['link','both','item'])) $leaf_classes[] = ($args['active_class'] ?? 'active');
            return '<a class="' . htmlspecialchars(implode(' ', $leaf_classes)) . '" href="' . $url . '">' . $title . '</a>';
        }

        // Top-level item
        $classes = ['nav-item'];
        if ($has_children) $classes[] = 'dropdown';
        if ($user_cls) $classes[] = $user_cls;
        // respect configured per-item class
        if (array_key_exists('item_class', $args) && $args['item_class'] !== null && $args['item_class'] !== '') {
            $classes[] = $args['item_class'];
        }
        // add active class to LI if requested
        $active_class = $args['active_class'] ?? 'active';
        // Normalize add_active_to for walker-level usage
        $add_active_to_raw = strtolower($args['add_active_to'] ?? 'item');
        if ($add_active_to_raw === 'li') $add_active_to = 'item';
        elseif ($add_active_to_raw === 'a') $add_active_to = 'link';
        else $add_active_to = $add_active_to_raw;
        if ($is_active && in_array($add_active_to, ['item','both'])) $classes[] = $active_class;
        $liClass = htmlspecialchars(implode(' ', $classes));
        $link_classes = ['nav-link'];
        $toggle_attr = '';
        if ($has_children) {
            $link_classes[] = 'dropdown-toggle';
            // BS4 toggle attributes
            $toggle_attr = ' data-toggle="dropdown" role="button" aria-haspopup="true" aria-expanded="false"';
        }
        // add active class to link if requested
        if ($is_active && in_array($add_active_to, ['link','both'])) $link_classes[] = $active_class;
        $link_cls_attr = htmlspecialchars(implode(' ', $link_classes));

        $html = '<' . $item_tag . ' class="' . $liClass . '">';
        $html .= '<a class="' . $link_cls_attr . '" href="' . $url . '"' . $toggle_attr . '>' . $title . '</a>';
        if ($has_children) {
            $sub_tag = $args['submenu_container']; // expected 'div' for BS4
            $sub_cls = htmlspecialchars('dropdown-menu ' . ($args['submenu_class'] ?? ''));
            $html .= '<' . $sub_tag . ' class="' . trim($sub_cls) . '">';
            foreach ($item['children'] as $child) {
                $html .= $this->start_el($child, $args, 1);
            }
            $html .= '</' . $sub_tag . '>';
        }
        $html .= '</' . $item_tag . '>';
        return $html;
    }
}

?>
