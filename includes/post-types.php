<?php
if (!defined('QLOPY_INIT')) { http_response_code(403); exit; }
// Global registries
if (!isset($GLOBALS['qlopy_post_types'])) {
    $GLOBALS['qlopy_post_types'] = [];
}
if (!isset($GLOBALS['qlopy_taxonomies'])) {
    $GLOBALS['qlopy_taxonomies'] = [];
}

// Registry for post-list columns (per post type) and common column templates
if (!isset($GLOBALS['qlopy_post_list_columns'])) {
    $GLOBALS['qlopy_post_list_columns'] = [];
}
if (!isset($GLOBALS['qlopy_common_post_columns'])) {
    $GLOBALS['qlopy_common_post_columns'] = [];
}

/**
 * Register a post type.
 * @param string $post_type Unique key (e.g. 'post')
 * @param array $args [
 *   'label' => 'Label',
 *   'slug' => 'slug-in-url' (e.g. 'post', 'news'),
 *   'public' => true,
 *   'taxonomies' => ['category'],
 *   ... more supporting args
 * ]
 */
function register_post_type(string $post_type, array $args = []) {
    // Set default slug as post_type if not given, for rewrite
    if (empty($args['slug'])) {
        $args['slug'] = $post_type;
    }
    // Default admin menu visibility
    if (!array_key_exists('has_admin_menu', $args)) {
        $args['has_admin_menu'] = true;
    }
    // Default menu icon (null -> use global default in navbar)
    if (!array_key_exists('menu_icon', $args)) {
        $args['menu_icon'] = null;
    }
    // Default featured image support flag
    if (!array_key_exists('has_featured', $args)) {
        $args['has_featured'] = false;
    }
    // Normalize supports array: default to title, slug, editor and comments
    if (!array_key_exists('supports', $args)) {
        $args['supports'] = ['title', 'slug', 'editor', 'comments'];
    } else {
        if (!is_array($args['supports'])) {
            $args['supports'] = [$args['supports']];
        }
    }
    $GLOBALS['qlopy_post_types'][$post_type] = $args;

    // Register associated taxonomies (if non-empty)
    if (!empty($args['taxonomies']) && is_array($args['taxonomies'])) {
        foreach ($args['taxonomies'] as $taxonomy_key) {
            if (!isset($GLOBALS['qlopy_taxonomies'][$taxonomy_key])) {
                register_taxonomy($taxonomy_key, [$post_type]);
            } else {
                if (!in_array($post_type, $GLOBALS['qlopy_taxonomies'][$taxonomy_key]['object_types'])) {
                    $GLOBALS['qlopy_taxonomies'][$taxonomy_key]['object_types'][] = $post_type;
                }
            }
        }
    } else {
        $GLOBALS['qlopy_post_types'][$post_type]['taxonomies'] = [];
    }

    // If this post type requests a featured image box, register a qpmeta metabox
    if (!empty($args['has_featured'])) {
        // ensure qpmeta functions available before calling
        if (function_exists('qpmeta_register_metabox')) {
            $mb_id = 'featured_image_' . $post_type;
            qpmeta_register_metabox($mb_id, [
                'id' => $mb_id,
                'title' => 'Featured Image',
                'object_types' => ['post'],
                'post_types' => [$post_type],
                'context' => 'side',
                'requires_object_id' => true,
                'fields' => [
                    [
                        'id' => 'featured_image',
                        'name' => 'Featured Image',
                        'type' => 'file',
                        'multiple' => false,
                    ]
                ]
            ]);
        }
    }

    // If post type is public and pretty permalinks are enabled, register
    // runtime rewrite rules so pretty URLs resolve without manual mapping.
    // Do NOT flush cache here; flushing is handled when the permalink
    // settings are saved or on plugin activation to avoid unnecessary IO.
    if (!empty($args['public']) && function_exists('permalink_get_structure') && permalink_get_structure() === 'pretty') {
        // Respect user-configured CPT mappings: if admin set a mapping in
        // permalink patterns, do not add automatic rules for this type.
        $patterns = function_exists('permalink_get_patterns') ? permalink_get_patterns() : [];
        $post_type_maps = $patterns['post_types'] ?? [];
        if (empty($post_type_maps[$post_type])) {
            $slug = trim($args['slug'] ?? $post_type, '/');
            if ($slug !== '') {
                if (function_exists('add_rewrite_rule')) {
                    $safe = preg_quote($slug, '#');
                    // singular: ^slug/([^/]+)/?$ => route=singular&post_type=...&slug=$1
                    add_rewrite_rule('^' . $safe . '/([^/]+)/?$', 'route=singular&post_type=' . $post_type . '&slug=$1', 'top');
                    // archive: ^slug/?$ => route=archive&post_type=...
                    add_rewrite_rule('^' . $safe . '/?$', 'route=archive&post_type=' . $post_type, 'bottom');
                }
            }
        }
    }
}

/**
 * Register a taxonomy and its linked post types.
 * @param string $taxonomy
 * @param array $object_types [ 'post', ... ]
 * @param array $args Additional properties, e.g. label, hierarchical
 */
function register_taxonomy(string $taxonomy, array $object_types = [], array $args = []) {
    // Ensure args array exists and provide sensible defaults for UI flags.
    $args = $args ?? [];
    if (!array_key_exists('menu_visible', $args)) {
        $args['menu_visible'] = true; // show taxonomy menus by default
    }
    // Whether this taxonomy should be listed on the post edit screen.
    if (!array_key_exists('screen_list', $args)) {
        $args['screen_list'] = true; // show taxonomy box on edit screens by default
    }
    // Whether this taxonomy should expose front-end archive routes.
    if (!array_key_exists('front_route', $args)) {
        $args['front_route'] = false; // disabled by default (opt-in)
    }
    if (isset($GLOBALS['qlopy_taxonomies'][$taxonomy])) {
        $GLOBALS['qlopy_taxonomies'][$taxonomy]['object_types'] = array_unique(array_merge(
            $GLOBALS['qlopy_taxonomies'][$taxonomy]['object_types'],
            $object_types
        ));
        // Allow caller to override menu visibility and screen listing explicitly
        if (array_key_exists('menu_visible', $args)) {
            $GLOBALS['qlopy_taxonomies'][$taxonomy]['menu_visible'] = (bool)$args['menu_visible'];
        }
        if (array_key_exists('screen_list', $args)) {
            $GLOBALS['qlopy_taxonomies'][$taxonomy]['screen_list'] = (bool)$args['screen_list'];
        }
        if (array_key_exists('front_route', $args)) {
            $GLOBALS['qlopy_taxonomies'][$taxonomy]['front_route'] = (bool)$args['front_route'];
        }
    } else {
        $GLOBALS['qlopy_taxonomies'][$taxonomy] = array_merge([
            'object_types' => $object_types,
        ], $args);
    }
}

// Read helpers
function get_post_types(): array {
    return $GLOBALS['qlopy_post_types'];
}
function get_taxonomies(): array {
    return $GLOBALS['qlopy_taxonomies'];
}
function get_taxonomies_for_post_type(string $post_type): array {
    $result = [];
    foreach ($GLOBALS['qlopy_taxonomies'] as $key => $tax) {
        if (in_array($post_type, $tax['object_types'] ?? [])) {
            $result[$key] = $tax;
        }
    }
    return $result;
}

/**
 * Register a column for a post list for a given post type.
 * $args can include: 'label' (string), 'class' (string), 'sortable' (bool),
 * 'render' (callable), 'position' => ['before' => 'slug'] or ['after' => 'title']
 */
function add_post_list_column(string $post_type, string $col_key, array $args = []): void {
    global $qlopy_post_list_columns;
    if (!isset($qlopy_post_list_columns[$post_type])) $qlopy_post_list_columns[$post_type] = [];
    $qlopy_post_list_columns[$post_type][$col_key] = $args;
}

/**
 * Register a common reusable column template (e.g. 'image', 'price').
 * $template_args same shape as add_post_list_column $args (without position).
 */
function register_common_post_column(string $name, array $template_args): void {
    global $qlopy_common_post_columns;
    $qlopy_common_post_columns[$name] = $template_args;
}

/**
 * Add a named common column to a post type's list.
 * $col_key can override the common name for the actual column key in the table.
 */
function add_common_post_list_column(string $post_type, string $common_name, string $col_key = null, array $position = []): void {
    global $qlopy_common_post_columns;
    if (empty($qlopy_common_post_columns[$common_name])) return;
    $meta = $qlopy_common_post_columns[$common_name];
    $col_key = $col_key ?: $common_name;
    if ($position) $meta['position'] = $position;
    add_post_list_column($post_type, $col_key, $meta);
}

/**
 * Get merged column definitions for a post type, merging defaults with registered columns
 * and honoring 'position' directives (before/after other column keys).
 * Returns array keyed by column key, values are meta arrays.
 */
function get_post_list_columns_for_type(string $post_type, array $default_columns = []): array {
    global $qlopy_post_list_columns;
    $registered = $qlopy_post_list_columns[$post_type] ?? [];

    // Start with defaults (normalize to meta arrays)
    $cols = [];
    foreach ($default_columns as $k => $v) {
        if (is_array($v)) {
            $cols[$k] = $v;
        } else {
            $cols[$k] = ['label' => (string)$v, 'class' => '', 'sortable' => false];
        }
    }

    // Apply registered columns with optional positioning
    foreach ($registered as $rk => $rmeta) {
        $meta = $rmeta;
        if (!is_array($meta)) $meta = ['label' => (string)$meta];
        // ensure defaults
        $meta = array_merge(['label' => $rk, 'class' => '', 'sortable' => false], $meta);

        // Position handling
        if (!empty($meta['position']) && is_array($meta['position'])) {
            $new = [];
            $inserted = false;
            foreach ($cols as $ck => $cm) {
                // before
                if (isset($meta['position']['before']) && $meta['position']['before'] === $ck) {
                    $new[$rk] = $meta; $inserted = true;
                }
                $new[$ck] = $cm;
                // after
                if (isset($meta['position']['after']) && $meta['position']['after'] === $ck) {
                    $new[$rk] = $meta; $inserted = true;
                }
            }
            if (!$inserted) {
                $cols[$rk] = $meta; // fallback append
            } else {
                $cols = $new;
            }
        } else {
            // Append or override
            $cols[$rk] = $meta;
        }
    }

    return $cols;
}

/**
 * Get post type by slug
 */
function get_post_type_by_slug($slug) {
    foreach ($GLOBALS['qlopy_post_types'] as $post_type => $args) {
        if (!empty($args['slug']) && $args['slug'] === $slug) {
            return $post_type;
        }
    }
    return null;
}

/**
 * Default core types
 */
function qlopy_register_default_post_types_and_taxonomies() {
    register_taxonomy('category', ['post'], [
        'label' => 'Categories',
        'hierarchical' => true,
    ]);
    // Register non-hierarchical tags taxonomy used by the admin tags metabox.
    // The admin metabox and qpmeta functions use the taxonomy name `post-tag`.
    // Hide it from admin menu by default (qpmeta manages the metabox UI).
    ///menu_visible is set to false to hide from admin menu
    // screen_list is set to false to hide from post edit screen (qpmeta metabox used instead)
    register_taxonomy('post-tag', ['post'], [
        'label' => 'Tags',
        'hierarchical' => false,
        'menu_visible' => true,
        'screen_list' => false,
    ]);
    register_post_type('post', [
        'label' => 'Posts',
        'slug' => 'post', // e.g. /post/example-slug
        'taxonomies' => ['category','post-tag'],
        'supports' => ['title','slug','editor','comments'],
        'public' => true,
        'has_archive' => true,
        'has_featured' => true,
    ]);
    register_post_type('page', [
        'label' => 'Pages',
        'slug' => '', // e.g. /about, /contact
        'taxonomies' => [],
        'supports' => ['title','slug','editor'],
        'public' => true,
        'has_archive' => false,
    ]);
    // Media attachments (non-public, managed via admin UI)
    register_post_type('attachment', [
        'label' => 'Media',
        'slug' => null,
        'taxonomies' => [],
        'public' => false,
        'has_archive' => false,
        'has_admin_menu' => false,
    ]);
}
qlopy_register_default_post_types_and_taxonomies();
