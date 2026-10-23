<?php
if (!defined('QLOPY_INIT')) { http_response_code(403); exit; }
// includes/widgets.php
// Front-end widgets (core upgrade plan C6, part A): widget types, widget areas ("sidebars"), placement and storage.
// Loaded from auth.php. This file only defines functions and a few small arrays; the core widget types live in
// includes/widgets-core.php and are loaded the first time one of them is needed. An area nobody prints costs nothing.
//
// For themes and plugins:
//   register_widget_area('sidebar-1', ['name' => 'Sidebar', 'default_widgets' => [['type' => 'search'], ['type' => 'recent-posts']]]);
//   dynamic_widget_area('sidebar-1');            // in the template; prints the area and returns true when it printed something
//   is_widget_area_active('sidebar-1');          // false when nothing is placed there, so the theme can drop the column
//   register_widget(['id' => 'mini-cart', 'name' => 'Mini cart', 'cache' => false,
//                    'fields' => [['id' => 'title', 'type' => 'text', 'label' => 'Title']],
//                    'render' => function ($args, $settings) { echo '...'; }]);
//   register_widget('My_Widget_Class');          // a class extending QP_Widget
//   add_widget_to_area($area, $type, $settings = [], $position = null) / move_widget($id, $area, $position) / remove_widget($id)
// Field types (shared with the admin dashboard widgets): text, textarea, url, number, checkbox, select, html (raw HTML,
// kept only when the saving user may manage options).

const QP_WIDGETS_OPTION = 'qp_widgets';

if (!function_exists('qp_widget_fields_defaults')) {

/** Default values of a fields schema. */
function qp_widget_fields_defaults(array $fields): array {
    $out = [];
    foreach ($fields as $f) {
        if (!is_array($f) || !isset($f['id'])) continue;
        $out[$f['id']] = $f['default'] ?? (($f['type'] ?? 'text') === 'checkbox' ? false : (($f['type'] ?? '') === 'number' ? ($f['min'] ?? 0) : ''));
    }
    return $out;
}

/** Options of a select field: an array, or a callable returning one (so a list that needs the database is only built when drawn). */
function qp_widget_field_options(array $f): array {
    $o = $f['options'] ?? [];
    if (is_callable($o)) $o = $o();
    return is_array($o) ? $o : [];
}

/** Sanitise submitted values against a fields schema. $old is what is stored now (kept for 'html' fields the saver may not change). */
function qp_widget_fields_sanitize(array $fields, array $in, array $old = []): array {
    $out = [];
    foreach ($fields as $f) {
        if (!is_array($f) || !isset($f['id'])) continue;
        $id = (string)$f['id'];
        $type = $f['type'] ?? 'text';
        $def = $f['default'] ?? '';
        $has = array_key_exists($id, $in);
        $v = $has ? $in[$id] : null;
        if (is_array($v)) $v = '';
        switch ($type) {
            case 'checkbox':
                $out[$id] = $has && filter_var($v, FILTER_VALIDATE_BOOLEAN);
                break;
            case 'number':
                $n = is_numeric($v) ? $v + 0 : ($def === '' ? 0 : $def);
                if (isset($f['min']) && $n < $f['min']) $n = $f['min'];
                if (isset($f['max']) && $n > $f['max']) $n = $f['max'];
                $out[$id] = $n;
                break;
            case 'select':
                $opts = qp_widget_field_options($f);
                $out[$id] = ($has && array_key_exists((string)$v, $opts)) ? (string)$v : (array_key_exists((string)$def, $opts) ? (string)$def : (string)array_key_first($opts));
                break;
            case 'url':
                $u = trim((string)$v);
                $out[$id] = ($u !== '' && preg_match('#^(https?://|/)#i', $u) && !preg_match('/[\x00-\x1f\s"<>]/', $u)) ? mb_substr($u, 0, 500) : '';
                break;
            case 'html':
                if (function_exists('current_user_can') && current_user_can('manage_options')) $out[$id] = $has ? mb_substr((string)$v, 0, 20000) : '';
                else $out[$id] = (string)($old[$id] ?? '');
                break;
            case 'textarea':
                $out[$id] = mb_substr(trim(strip_tags((string)($has ? $v : $def))), 0, (int)($f['max'] ?? 5000));
                break;
            default:
                $out[$id] = mb_substr(trim(preg_replace('/[\x00-\x08\x0b\x0c\x0e-\x1f]/', '', strip_tags((string)($has ? $v : $def)))), 0, (int)($f['max'] ?? 255));
        }
    }
    return $out;
}

/** Print the inputs of a fields schema. Names are `settings[field]`, ids are prefixed so several forms can share a page. */
function qp_widget_fields_render(array $fields, array $values, string $prefix = 'f'): void {
    foreach ($fields as $f) {
        if (!is_array($f) || !isset($f['id'])) continue;
        $id = (string)$f['id'];
        $type = $f['type'] ?? 'text';
        $label = (string)($f['label'] ?? $id);
        $val = array_key_exists($id, $values) ? $values[$id] : ($f['default'] ?? '');
        $dom = htmlspecialchars($prefix . '-' . $id, ENT_QUOTES, 'UTF-8');
        $name = 'settings[' . htmlspecialchars($id, ENT_QUOTES, 'UTF-8') . ']';
        echo '<div class="form-group qpw-field">';
        if ($type === 'checkbox') {
            echo '<div class="form-check"><input type="hidden" name="' . $name . '" value="0"><input type="checkbox" class="form-check-input" id="' . $dom . '" name="' . $name . '" value="1"' . ($val ? ' checked' : '') . '> <label class="form-check-label" for="' . $dom . '">' . htmlspecialchars($label) . '</label></div>';
        } else {
            echo '<label for="' . $dom . '">' . htmlspecialchars($label) . '</label>';
            if ($type === 'textarea' || $type === 'html') {
                if ($type === 'html' && function_exists('current_user_can') && !current_user_can('manage_options')) {
                    echo '<div class="form-text text-muted small">Only users who can manage options may edit raw HTML.</div>';
                } else {
                    echo '<textarea class="form-control" rows="' . (int)($f['rows'] ?? 5) . '" id="' . $dom . '" name="' . $name . '">' . htmlspecialchars((string)$val) . '</textarea>';
                }
            } elseif ($type === 'select') {
                echo '<select class="form-control" id="' . $dom . '" name="' . $name . '">';
                foreach (qp_widget_field_options($f) as $ov => $ol) echo '<option value="' . htmlspecialchars((string)$ov, ENT_QUOTES, 'UTF-8') . '"' . ((string)$ov === (string)$val ? ' selected' : '') . '>' . htmlspecialchars((string)$ol) . '</option>';
                echo '</select>';
            } elseif ($type === 'number') {
                echo '<input type="number" class="form-control" id="' . $dom . '" name="' . $name . '" value="' . htmlspecialchars((string)$val, ENT_QUOTES, 'UTF-8') . '"' . (isset($f['min']) ? ' min="' . (float)$f['min'] . '"' : '') . (isset($f['max']) ? ' max="' . (float)$f['max'] . '"' : '') . '>';
            } else {
                echo '<input type="' . ($type === 'url' ? 'url' : 'text') . '" class="form-control" id="' . $dom . '" name="' . $name . '" value="' . htmlspecialchars((string)$val, ENT_QUOTES, 'UTF-8') . '">';
            }
        }
        if (!empty($f['help'])) echo '<small class="form-text text-muted">' . htmlspecialchars((string)$f['help']) . '</small>';
        echo '</div>';
    }
}

// ---------------------------------------------------------------- widget types

/**
 * Base class of a widget type. Subclass it, or use register_widget() with an array and a render callback.
 *   widget($args, $settings)  prints the widget ($args holds before_widget, after_widget, before_title, after_title)
 *   form($settings, $prefix)  prints the settings fields (default: from $fields)
 *   update($new, $old)        returns the sanitised settings (default: from $fields)
 * Set $cache to false for output that differs per visitor (the page is then not page-cached), or to a number of seconds to keep the
 * rendered output in the C1c cache until the widget's settings change.
 */
abstract class QP_Widget {
    public $id_base;
    public $name;
    public $description = '';
    public $fields = [];
    public $cache = true;
    public function __construct(string $id_base, string $name, array $args = []) {
        $this->id_base = $id_base;
        $this->name = $name;
        foreach (['description', 'fields', 'cache'] as $k) if (array_key_exists($k, $args)) $this->$k = $args[$k];
    }
    abstract public function widget(array $args, array $settings): void;
    public function defaults(): array { return qp_widget_fields_defaults($this->fields); }
    public function form(array $settings, string $prefix = 'f'): void { qp_widget_fields_render($this->fields, $settings, $prefix); }
    public function update(array $new, array $old = []): array { return qp_widget_fields_sanitize($this->fields, $new, $old); }
}

/** Widget made from an array definition (render callback). */
class QP_Callback_Widget extends QP_Widget {
    private $render;
    public function __construct(string $id_base, string $name, callable $render, array $args = []) {
        parent::__construct($id_base, $name, $args);
        $this->render = $render;
    }
    public function widget(array $args, array $settings): void { ($this->render)($args, $settings); }
}

function &qp_widget_types_ref(): array { static $t = []; return $t; }
function &qp_widget_areas_ref(): array { static $a = []; return $a; }

/** Core widget types: only this list is in memory until one is placed; the classes load from widgets-core.php. */
function qp_widgets_core_types(): array {
    return ['text' => 'QP_Widget_Text', 'recent-posts' => 'QP_Widget_Recent_Posts', 'terms' => 'QP_Widget_Terms', 'search' => 'QP_Widget_Search',
            'nav-menu' => 'QP_Widget_Nav_Menu', 'archives' => 'QP_Widget_Archives', 'recent-comments' => 'QP_Widget_Recent_Comments'];
}

/** Register a widget type. Accepts a class name, an instance, or ['id','name','description','fields','render','cache']. Returns the id or false. */
function register_widget($widget) {
    $types = &qp_widget_types_ref();
    try {
        if (is_string($widget) && class_exists($widget) && is_subclass_of($widget, 'QP_Widget')) {
            $rc = new ReflectionClass($widget);
            $ctor = $rc->getConstructor();
            $obj = ($ctor && $ctor->getNumberOfRequiredParameters() > 0) ? null : new $widget();
            if (!$obj) return false;
            $widget = $obj;
        } elseif (is_array($widget)) {
            if (empty($widget['id']) || empty($widget['render']) || !is_callable($widget['render'])) return false;
            $widget = new QP_Callback_Widget((string)$widget['id'], (string)($widget['name'] ?? $widget['id']), $widget['render'], $widget);
        }
        if (!($widget instanceof QP_Widget)) return false;
        if (!preg_match('/^[a-z0-9_-]{1,40}$/', $widget->id_base)) return false;
        $types[$widget->id_base] = $widget;
        return $widget->id_base;
    } catch (Throwable $e) {
        error_log('register_widget failed: ' . $e->getMessage());
        return false;
    }
}

/** The widget type object for an id, loading the core types on first use. Null when unknown. */
function qp_widget_type(string $id): ?QP_Widget {
    $types = &qp_widget_types_ref();
    if (isset($types[$id])) return $types[$id];
    $core = qp_widgets_core_types();
    if (isset($core[$id])) {
        if (!class_exists('QP_Widget_Text', false)) require_once __DIR__ . '/widgets-core.php';
        register_widget($core[$id]);
        return $types[$id] ?? null;
    }
    return null;
}

/** All widget types (loads the core ones). For the admin screen. */
function qp_widget_types_all(): array {
    foreach (array_keys(qp_widgets_core_types()) as $id) qp_widget_type($id);
    $types = qp_widget_types_ref();
    ksort($types);
    return $types;
}

// ---------------------------------------------------------------- widget areas

function register_widget_area(string $id, array $args = []) {
    if (!preg_match('/^[a-z0-9_-]{1,40}$/', $id) || $id === 'inactive') return false;
    $areas = &qp_widget_areas_ref();
    $areas[$id] = array_merge([
        'name' => $id, 'description' => '',
        'before_widget' => '<section id="%1$s" class="widget %2$s">', 'after_widget' => '</section>',
        'before_title' => '<h2 class="widget-title">', 'after_title' => '</h2>',
        'default_widgets' => [],
    ], $args);
    $areas[$id]['id'] = $id;
    return $id;
}

function qp_widget_areas(): array { return qp_widget_areas_ref(); }

// ---------------------------------------------------------------- storage

/** The stored layout, read once per request. Shape: areas[area] = [instance ids], inactive = [ids], instances[id] = [type, settings, cond]. */
function qp_widgets_store(bool $reload = false): array {
    static $store = null;
    if ($store === null || $reload) {
        $s = function_exists('get_option_meta') ? get_option_meta(QP_WIDGETS_OPTION) : null;
        $store = is_array($s) ? $s : [];
        $store += ['areas' => [], 'inactive' => [], 'instances' => [], 'next' => 1, 'defaults_done' => []];
        foreach (['areas', 'inactive', 'instances', 'defaults_done'] as $k) if (!is_array($store[$k])) $store[$k] = [];
    }
    return $store;
}


/** Save the layout, drop cached widget output and the page cache. Instance ids that no area or Inactive list mentions are discarded. */
function qp_widgets_save(array $store): bool {
    $used = [];
    foreach ($store['areas'] as $list) foreach ($list as $i) $used[$i] = true;
    foreach ($store['inactive'] as $i) $used[$i] = true;
    $store['instances'] = array_intersect_key($store['instances'], $used);
    $store['areas'] = array_filter($store['areas'], function ($l) { return !empty($l); });
    $store['inactive'] = array_values(array_unique($store['inactive']));
    if (!function_exists('update_option_meta')) return false;
    update_option_meta(QP_WIDGETS_OPTION, $store);
    qp_widgets_store(true);
    if (function_exists('qp_cache_flush_group')) qp_cache_flush_group('qp_widgets');
    if (function_exists('qp_page_cache_on_change')) qp_page_cache_on_change();
    if (function_exists('do_action')) do_action('qp_widgets_saved', $store);
    return true;
}

function qp_widget_new_id(array &$store, string $type): string {
    $n = max(1, (int)$store['next']);
    while (isset($store['instances'][$type . '-' . $n])) $n++;
    $store['next'] = $n + 1;
    return $type . '-' . $n;
}

/** Place the default widgets a theme declared with its area, once, only while the area is empty (never over an owner's choices). */
function qp_widgets_apply_defaults(string $area_id): void {
    $areas = qp_widget_areas_ref();
    if (empty($areas[$area_id]['default_widgets'])) return;
    $store = qp_widgets_store();
    if (!empty($store['defaults_done'][$area_id])) return;
    $store['defaults_done'][$area_id] = true;
    if (empty($store['areas'][$area_id])) {
        $list = [];
        foreach ($areas[$area_id]['default_widgets'] as $d) {
            $type = is_array($d) ? (string)($d['type'] ?? '') : (string)$d;
            $t = $type !== '' ? qp_widget_type($type) : null;
            if (!$t) continue;
            $settings = $t->update(array_merge($t->defaults(), is_array($d) ? (array)($d['settings'] ?? []) : []), []);
            $iid = qp_widget_new_id($store, $type);
            $store['instances'][$iid] = ['type' => $type, 'settings' => $settings, 'cond' => []];
            $list[] = $iid;
        }
        if ($list) $store['areas'][$area_id] = $list;
    }
    qp_widgets_save($store);
}

function qp_widget_area_instance_ids(string $area_id): array {
    $areas = qp_widget_areas_ref();
    if (!isset($areas[$area_id])) return [];
    qp_widgets_apply_defaults($area_id);
    $store = qp_widgets_store();
    $out = [];
    foreach ((array)($store['areas'][$area_id] ?? []) as $iid) {
        $inst = $store['instances'][$iid] ?? null;
        // An unknown widget type in stored data (a plugin was removed) is skipped, never executed.
        if (is_array($inst) && is_string($inst['type'] ?? null) && qp_widget_type($inst['type'])) $out[] = $iid;
    }
    return $out;
}

function is_widget_area_active(string $area_id): bool {
    return (bool)qp_widget_area_instance_ids($area_id);
}

/** Display conditions: ['auth' => any|in|out, 'pages' => [front, single, page, archive, search, notfound], 'post_types' => [...]]. Empty = always. */
function qp_widget_conditions_pass(array $cond): bool {
    if (!$cond) return true;
    $auth = $cond['auth'] ?? 'any';
    if ($auth === 'in' && !(function_exists('is_logged_in') && is_logged_in())) return false;
    if ($auth === 'out' && function_exists('is_logged_in') && is_logged_in()) return false;
    $pages = array_filter((array)($cond['pages'] ?? []));
    $route = $GLOBALS['route'] ?? ($_GET['route'] ?? '');
    if ($pages) {
        $cur = [];
        if ($route === 'frontpage') $cur[] = 'front';
        elseif ($route === 'singular') { $cur[] = 'single'; if (($_GET['post_type'] ?? ($GLOBALS['post']['post_type'] ?? '')) === 'page') $cur[] = 'page'; }
        elseif ($route === 'archive') $cur[] = 'archive';
        elseif ($route === '404') $cur[] = 'notfound';
        if (isset($_GET['s']) && $_GET['s'] !== '') $cur[] = 'search';
        if (!array_intersect($pages, $cur)) return false;
    }
    $pts = array_filter((array)($cond['post_types'] ?? []));
    if ($pts) {
        if ($route !== 'singular') return false;
        $pt = $_GET['post_type'] ?? ($GLOBALS['post']['post_type'] ?? '');
        if (!in_array($pt, $pts, true)) return false;
    }
    return true;
}

/** Render one placed widget to a string inside an error boundary. Returns '' when it prints nothing or throws. */
function qp_widget_render_instance(string $iid, array $area): string {
    $store = qp_widgets_store();
    $inst = $store['instances'][$iid] ?? null;
    if (!is_array($inst)) return '';
    $type = qp_widget_type((string)$inst['type']);
    if (!$type) return '';
    $settings = array_merge($type->defaults(), (array)($inst['settings'] ?? []));
    if (!qp_widget_conditions_pass((array)($inst['cond'] ?? []))) return '';
    $cache = $type->cache;
    if ($cache === false && function_exists('qp_page_cache_mark_uncacheable')) qp_page_cache_mark_uncacheable('widget');
    $args = [
        'widget_id' => $iid,
        'before_widget' => sprintf((string)$area['before_widget'], htmlspecialchars($iid, ENT_QUOTES, 'UTF-8'), htmlspecialchars('widget_' . $type->id_base, ENT_QUOTES, 'UTF-8')),
        'after_widget' => (string)$area['after_widget'],
        'before_title' => (string)$area['before_title'],
        'after_title' => (string)$area['after_title'],
        'area' => $area['id'],
    ];
    $draw = function () use ($type, $args, $settings, $iid) {
        $level = ob_get_level();
        ob_start();
        try {
            $type->widget($args, $settings);
            return (string)ob_get_clean();
        } catch (Throwable $e) {
            while (ob_get_level() > $level) ob_end_clean();
            error_log('Widget ' . $iid . ' failed: ' . $e->getMessage());
            return '';
        }
    };
    if (is_int($cache) && $cache > 0 && function_exists('qp_cache_remember')) {
        return (string)qp_cache_remember($iid . ':' . md5(json_encode($settings)), $cache, $draw, 'qp_widgets');
    }
    return $draw();
}

/** Print a widget area. Returns true when something was printed. An area nobody registered, or an empty one, prints nothing. */
function dynamic_widget_area(string $area_id): bool {
    $areas = qp_widget_areas_ref();
    if (!isset($areas[$area_id])) return false;
    $out = '';
    foreach (qp_widget_area_instance_ids($area_id) as $iid) $out .= qp_widget_render_instance($iid, $areas[$area_id]);
    if ($out === '') return false;
    echo $out;
    return true;
}

// ---------------------------------------------------------------- code API for placement

/** Add a widget to an area ('inactive' is allowed). Settings are sanitised by the type. Returns the instance id or false. */
function add_widget_to_area(string $area_id, string $type, array $settings = [], ?int $position = null, array $cond = []) {
    $t = qp_widget_type($type);
    if (!$t) return false;
    $areas = qp_widget_areas_ref();
    if ($area_id !== 'inactive' && !isset($areas[$area_id])) return false;
    $store = qp_widgets_store();
    $iid = qp_widget_new_id($store, $type);
    $store['instances'][$iid] = ['type' => $type, 'settings' => $t->update(array_merge($t->defaults(), $settings), []), 'cond' => qp_widget_conditions_sanitize($cond)];
    if ($area_id === 'inactive') $store['inactive'][] = $iid;
    else {
        $list = (array)($store['areas'][$area_id] ?? []);
        array_splice($list, $position === null ? count($list) : max(0, min($position, count($list))), 0, [$iid]);
        $store['areas'][$area_id] = $list;
    }
    return qp_widgets_save($store) ? $iid : false;
}

function move_widget(string $iid, string $area_id, ?int $position = null): bool {
    $store = qp_widgets_store();
    if (!isset($store['instances'][$iid])) return false;
    $areas = qp_widget_areas_ref();
    if ($area_id !== 'inactive' && !isset($areas[$area_id])) return false;
    foreach ($store['areas'] as $a => $list) $store['areas'][$a] = array_values(array_diff($list, [$iid]));
    $store['inactive'] = array_values(array_diff($store['inactive'], [$iid]));
    if ($area_id === 'inactive') $store['inactive'][] = $iid;
    else {
        $list = (array)($store['areas'][$area_id] ?? []);
        array_splice($list, $position === null ? count($list) : max(0, min($position, count($list))), 0, [$iid]);
        $store['areas'][$area_id] = $list;
    }
    return qp_widgets_save($store);
}

function remove_widget(string $iid): bool {
    $store = qp_widgets_store();
    if (!isset($store['instances'][$iid])) return false;
    foreach ($store['areas'] as $a => $list) $store['areas'][$a] = array_values(array_diff($list, [$iid]));
    $store['inactive'] = array_values(array_diff($store['inactive'], [$iid]));
    unset($store['instances'][$iid]);
    return qp_widgets_save($store);
}

function qp_widget_conditions_sanitize(array $c): array {
    $out = [];
    if (in_array($c['auth'] ?? 'any', ['in', 'out'], true)) $out['auth'] = $c['auth'];
    $pages = array_values(array_intersect(['front', 'single', 'page', 'archive', 'search', 'notfound'], array_map('strval', (array)($c['pages'] ?? []))));
    if ($pages) $out['pages'] = $pages;
    $pts = [];
    foreach ((array)($c['post_types'] ?? []) as $p) if (is_string($p) && preg_match('/^[a-z0-9_-]{1,50}$/i', $p)) $pts[] = $p;
    if ($pts) $out['post_types'] = array_slice(array_values(array_unique($pts)), 0, 20);
    return $out;
}

/**
 * Apply a whole layout submitted by the admin screen. $layout = ['areas' => [area => [ids]], 'inactive' => [ids]].
 * Ids that are not stored are ignored. Areas the current theme does not register keep their widgets (the screen shows them as Inactive).
 */
function qp_widgets_apply_layout(array $layout): bool {
    $store = qp_widgets_store();
    $registered = qp_widget_areas_ref();
    $seen = [];
    $take = function ($ids) use (&$seen, $store) {
        $out = [];
        foreach ((array)$ids as $i) {
            if (!is_string($i) || isset($seen[$i]) || !isset($store['instances'][$i])) continue;
            $seen[$i] = true; $out[] = $i;
        }
        return $out;
    };
    $newAreas = [];
    foreach ((array)($layout['areas'] ?? []) as $a => $ids) {
        if (!is_string($a) || !isset($registered[$a])) continue;
        $newAreas[$a] = $take($ids);
    }
    $newInactive = $take($layout['inactive'] ?? []);
    foreach ($store['areas'] as $a => $list) {
        if (isset($registered[$a])) { $store['areas'][$a] = $newAreas[$a] ?? []; continue; }
        $store['areas'][$a] = array_values(array_filter($list, function ($i) use ($seen) { return !isset($seen[$i]); }));
    }
    foreach ($newAreas as $a => $list) if (!isset($store['areas'][$a])) $store['areas'][$a] = $list;
    $store['inactive'] = array_values(array_merge($newInactive, array_filter($store['inactive'], function ($i) use ($seen) { return !isset($seen[$i]); })));
    return qp_widgets_save($store);
}

}
