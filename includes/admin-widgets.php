<?php
if (!defined('QLOPY_INIT')) { http_response_code(403); exit; }
// includes/admin-widgets.php
// Admin widget system (core upgrade plan C6, part B) and the handlers behind Appearance > Widgets (part A's screen).
// Loaded from admin/admin_head.php only, so a front-end request never reads it.
//
//   register_admin_widget('my-box', [
//       'title' => 'My box', 'callback' => function (array $settings) { echo '...'; },
//       'screen' => 'dashboard',          // the admin page that hosts it (the registry works for any screen)
//       'column' => 1, 'priority' => 'default' /* high|core|default|low */, 'order' => 10,
//       'capability' => 'manage_options', // never rendered or listed for users without it
//       'removable' => true,              // false: can be moved and collapsed, never hidden (the server refuses a layout that hides it)
//       'configurable' => false, 'fields' => [...],   // per-user settings, same field types as front-end widgets
//       'display' => 'box',               // 'banner': headless panel above the columns, dismissable (stored like a hidden box)
//       'span' => 'full',                 // banners only: 'full' or 1..4 columns wide
//       'lazy' => false,                  // true: loaded after the page by one AJAX call
//       'cache' => 0,                     // seconds to keep a lazy widget's output (per user)
//   ]);
//   add_filter('qp_admin_widgets', function ($widgets, $screen) { unset($widgets['welcome']); return $widgets; }, 10, 2);
// Then, on the hosting page: qp_admin_widgets_render('dashboard');

function &qp_admin_widgets_ref(): array { static $w = []; return $w; }

function register_admin_widget(string $id, array $args): bool {
    if (!preg_match('/^[a-z0-9_-]{1,60}$/', $id) || empty($args['callback']) || !is_callable($args['callback'])) return false;
    $w = &qp_admin_widgets_ref();
    static $seq = 0;
    $w[$id] = array_merge([
        'title' => $id, 'screen' => 'dashboard', 'column' => 1, 'priority' => 'default', 'order' => 10, 'capability' => '',
        'removable' => true, 'configurable' => false, 'fields' => [], 'lazy' => false, 'cache' => 0, 'display' => 'box', 'span' => 'full',
    ], $args);
    $w[$id]['id'] = $id;
    $w[$id]['seq'] = $seq++;
    $w[$id]['column'] = max(1, min(4, (int)$w[$id]['column']));
    if (!in_array($w[$id]['priority'], ['high', 'core', 'default', 'low'], true)) $w[$id]['priority'] = 'default';
    return true;
}

function qp_admin_widgets_load_core(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    require_once __DIR__ . '/admin-widgets-core.php';
}

/** The widgets of a screen the current user may see, after the `qp_admin_widgets` filter. */
function qp_admin_widgets_for(string $screen): array {
    if ($screen === 'dashboard') qp_admin_widgets_load_core();
    $all = [];
    foreach (qp_admin_widgets_ref() as $id => $w) if ($w['screen'] === $screen) $all[$id] = $w;
    if (function_exists('apply_filters')) {
        $f = apply_filters('qp_admin_widgets', $all, $screen);
        if (is_array($f)) $all = $f;
    }
    $out = [];
    foreach ($all as $id => $w) {
        if (!is_array($w) || !isset($w['callback'])) continue;
        // Capability is checked here, so a saved layout can never expose a widget the user may not see.
        if (!empty($w['capability']) && !current_user_can((string)$w['capability'])) continue;
        $out[$id] = $w;
    }
    return $out;
}

function qp_admin_layout_meta_key(string $screen): string { return 'qp_admin_layout_' . preg_replace('/[^a-z0-9_-]/', '', $screen); }
function qp_admin_layout_default_option(string $screen): string { return 'qp_admin_layout_default_' . preg_replace('/[^a-z0-9_-]/', '', $screen); }

function qp_admin_layout_user(string $screen): ?array {
    $u = get_logged_in_user();
    if (!$u) return null;
    $l = get_user_meta((int)$u['id'], qp_admin_layout_meta_key($screen));
    return is_array($l) ? $l : null;
}

/**
 * The layout to draw: ['cols', 'columns' => [[widget, ...], ...], 'hidden' => [ids], 'collapsed' => [ids], 'settings' => [id => [...]], 'source' => user|site|code].
 * Order: the user's saved layout, else the site default an administrator set, else the places the code gave (column, priority, order).
 * A widget missing from a saved layout (registered later) takes its default place; non-removable widgets are never hidden.
 */
function qp_admin_layout_resolve(string $screen): array {
    $widgets = qp_admin_widgets_for($screen);
    $saved = qp_admin_layout_user($screen);
    $source = 'user';
    if (!$saved) {
        $d = function_exists('get_option_meta') ? get_option_meta(qp_admin_layout_default_option($screen)) : null;
        $saved = is_array($d) ? $d : null;
        $source = $saved ? 'site' : 'code';
    }
    $saved = $saved ?: [];
    $cols = (int)($saved['cols'] ?? 3);
    if ($cols < 1 || $cols > 4) $cols = 3;
    $rank = ['high' => 0, 'core' => 1, 'default' => 2, 'low' => 3];
    $sorted = $widgets;
    uasort($sorted, function ($a, $b) use ($rank) {
        return [$rank[$a['priority']], (int)$a['order'], $a['seq']] <=> [$rank[$b['priority']], (int)$b['order'], $b['seq']];
    });
    $columns = array_fill(0, $cols, []);
    $placed = [];
    // Banners (display => 'banner') sit above the columns, full width, in priority order; they are not part of the column order.
    $banners = [];
    foreach ($sorted as $id => $w) if (($w['display'] ?? 'box') === 'banner') { $banners[] = $w; $placed[$id] = true; }
    foreach ((array)($saved['order'] ?? []) as $ci => $ids) {
        $ci = min((int)$ci, $cols - 1);
        foreach ((array)$ids as $id) {
            if (!is_string($id) || isset($placed[$id]) || !isset($widgets[$id])) continue;
            $columns[$ci][] = $widgets[$id];
            $placed[$id] = true;
        }
    }
    foreach ($sorted as $id => $w) {
        if (isset($placed[$id])) continue;
        $columns[min($w['column'], $cols) - 1][] = $w;
        $placed[$id] = true;
    }
    $hidden = [];
    foreach ((array)($saved['hidden'] ?? []) as $id) if (is_string($id) && isset($widgets[$id]) && !empty($widgets[$id]['removable'])) $hidden[] = $id;
    $collapsed = array_values(array_filter((array)($saved['collapsed'] ?? []), function ($id) use ($widgets) { return is_string($id) && isset($widgets[$id]); }));
    return ['cols' => $cols, 'columns' => $columns, 'hidden' => $hidden, 'collapsed' => $collapsed, 'settings' => (array)($saved['settings'] ?? []), 'source' => $source, 'widgets' => $widgets, 'banners' => $banners];
}

/**
 * Validate a layout submitted by the browser. Returns [layout, null] or [null, error message].
 * Unknown or not-permitted ids are dropped; hiding a non-removable widget is refused.
 */
function qp_admin_layout_sanitize(string $screen, $in, ?array $previous = null): array {
    if (!is_array($in)) return [null, 'The layout is not valid.'];
    $widgets = qp_admin_widgets_for($screen);
    $cols = (int)($in['cols'] ?? 3);
    if ($cols < 1 || $cols > 4) return [null, 'Choose between 1 and 4 columns.'];
    $out = ['cols' => $cols, 'order' => [], 'hidden' => [], 'collapsed' => [], 'settings' => []];
    $seen = [];
    foreach (array_slice((array)($in['order'] ?? []), 0, 4) as $ci => $ids) {
        $col = [];
        foreach (array_slice((array)$ids, 0, 100) as $id) {
            if (!is_string($id) || isset($seen[$id]) || !isset($widgets[$id])) continue;
            $seen[$id] = true; $col[] = $id;
        }
        $out['order'][] = $col;
    }
    $refused = [];
    foreach ((array)($in['hidden'] ?? []) as $id) {
        if (!is_string($id) || !isset($widgets[$id])) continue;
        if (empty($widgets[$id]['removable'])) { $refused[] = (string)$widgets[$id]['title']; continue; }
        $out['hidden'][] = $id;
    }
    if ($refused) return [null, 'These boxes cannot be hidden: ' . implode(', ', $refused) . '.'];
    foreach ((array)($in['collapsed'] ?? []) as $id) if (is_string($id) && isset($widgets[$id])) $out['collapsed'][] = $id;
    // Per-widget settings are only changed through their own save, so keep what was stored.
    $out['settings'] = (array)($previous['settings'] ?? []);
    return [$out, null];
}

function qp_admin_dash_json(array $data, int $code = 200): void {
    if ($code !== 200) http_response_code($code);
    echo json_encode($data);
    exit;
}

/** Run one widget's callback inside an error boundary. Returns the HTML, with a "could not load" box if it throws. */
function qp_admin_widget_output(array $w, array $settings): string {
    $level = ob_get_level();
    ob_start();
    try {
        ($w['callback'])($settings);
        return (string)ob_get_clean();
    } catch (Throwable $e) {
        while (ob_get_level() > $level) ob_end_clean();
        error_log('Admin widget ' . ($w['id'] ?? '?') . ' failed: ' . $e->getMessage());
        return '<p class="text-muted mb-0">This box could not load.</p>';
    }
}

/** Print the grid of widgets for a screen: boxes in columns, collapse, hide, move (mouse and keyboard), Screen options. */
function qp_admin_widgets_render(string $screen): void {
    $lay = qp_admin_layout_resolve($screen);
    $nonce = qp_create_nonce('qpw_dash');
    $ajax = function_exists('get_admin_ajax_url') ? get_admin_ajax_url() : 'ajax.php';
    $can_default = current_user_can('manage_options');
    $cfg = ['screen' => $screen, 'nonce' => $nonce, 'ajax' => $ajax, 'cols' => $lay['cols'], 'canDefault' => $can_default, 'source' => $lay['source']];
    echo '<div class="qpw-wrap" id="qpw-wrap" data-config="' . htmlspecialchars(json_encode($cfg), ENT_QUOTES, 'UTF-8') . '">';
    echo '<div class="qpw-toolbar d-flex justify-content-end mb-2"><button type="button" class="btn btn-sm btn-outline-secondary" id="qpw-screen-options" aria-expanded="false" aria-controls="qpw-options">Screen options</button></div>';
    echo '<div class="qpw-options card card-body mb-3" id="qpw-options" hidden>';
    echo '<div class="form-row align-items-end"><div class="col-auto"><label for="qpw-cols" class="mb-0 small">Columns</label><select id="qpw-cols" class="form-control form-control-sm">';
    for ($i = 1; $i <= 4; $i++) echo '<option value="' . $i . '"' . ($i === $lay['cols'] ? ' selected' : '') . '>' . $i . '</option>';
    echo '</select></div><div class="col"><span class="small d-block">Show on screen</span>';
    foreach ($lay['widgets'] as $id => $w) {
        $on = !in_array($id, $lay['hidden'], true);
        $dom = 'qpw-show-' . htmlspecialchars($id, ENT_QUOTES, 'UTF-8');
        echo '<div class="form-check form-check-inline"><input class="form-check-input qpw-show" type="checkbox" id="' . $dom . '" data-id="' . htmlspecialchars($id, ENT_QUOTES, 'UTF-8') . '"' . ($on ? ' checked' : '') . (empty($w['removable']) ? ' disabled' : '') . '><label class="form-check-label" for="' . $dom . '">' . htmlspecialchars((string)$w['title']) . '</label></div>';
    }
    echo '</div><div class="col-auto"><button type="button" class="btn btn-sm btn-outline-danger" id="qpw-reset">Reset layout</button>';
    if ($can_default) echo ' <button type="button" class="btn btn-sm btn-outline-primary" id="qpw-save-default">Use my layout for new users</button>';
    echo '</div></div><div class="small mt-2" id="qpw-msg" role="status" aria-live="polite"></div></div>';
    echo '<div class="qpw-grid qpw-banners qpw-cols-' . (int)$lay['cols'] . '" id="qpw-banners">';
    foreach ($lay['banners'] as $w) {
        $id = $w['id'];
        $sid = htmlspecialchars($id, ENT_QUOTES, 'UTF-8');
        $span = $w['span'] === 'full' ? 'full' : (string)max(1, min(4, (int)$w['span']));
        echo '<section class="qpw-banner qpw-banner-' . htmlspecialchars($id, ENT_QUOTES, 'UTF-8') . '" data-id="' . $sid . '" data-span="' . $span . '" data-removable="' . (empty($w['removable']) ? '0' : '1') . '"' . (in_array($id, $lay['hidden'], true) ? ' hidden data-hidden="1"' : '') . '>';
        if (!empty($w['removable'])) echo '<button type="button" class="qpw-dismiss" aria-label="Dismiss ' . htmlspecialchars((string)$w['title'], ENT_QUOTES, 'UTF-8') . '">&times; Dismiss</button>';
        echo qp_admin_widget_output($w, array_merge(qp_widget_fields_defaults((array)$w['fields']), (array)($lay['settings'][$id] ?? [])));
        echo '</section>';
    }
    echo '</div>';
    echo '<div class="qpw-grid qpw-cols-' . (int)$lay['cols'] . '" id="qpw-grid">';
    foreach ($lay['columns'] as $ci => $col) {
        echo '<div class="qpw-col" data-col="' . $ci . '">';
        foreach ($col as $w) {
            $id = $w['id'];
            $hidden = in_array($id, $lay['hidden'], true);
            $collapsed = in_array($id, $lay['collapsed'], true);
            $sid = htmlspecialchars($id, ENT_QUOTES, 'UTF-8');
            echo '<section class="card qpw-box' . ($collapsed ? ' is-collapsed' : '') . '" data-id="' . $sid . '" data-removable="' . (empty($w['removable']) ? '0' : '1') . '"' . ($hidden ? ' hidden data-hidden="1"' : '') . ' draggable="false" aria-labelledby="qpw-t-' . $sid . '">';
            echo '<div class="card-header qpw-head d-flex align-items-center"><h2 class="h6 mb-0 mr-auto qpw-title" id="qpw-t-' . $sid . '">' . htmlspecialchars((string)$w['title']) . '</h2>';
            echo '<span class="qpw-handle" title="Drag to move" aria-hidden="true">&#8942;&#8942;</span>';
            echo '<button type="button" class="btn btn-sm btn-link qpw-move" data-dir="up" aria-label="Move ' . htmlspecialchars((string)$w['title'], ENT_QUOTES, 'UTF-8') . ' up">&uarr;</button>';
            echo '<button type="button" class="btn btn-sm btn-link qpw-move" data-dir="down" aria-label="Move down">&darr;</button>';
            echo '<button type="button" class="btn btn-sm btn-link qpw-move" data-dir="left" aria-label="Move to the previous column">&larr;</button>';
            echo '<button type="button" class="btn btn-sm btn-link qpw-move" data-dir="right" aria-label="Move to the next column">&rarr;</button>';
            if (!empty($w['configurable']) && !empty($w['fields'])) echo '<button type="button" class="btn btn-sm btn-link qpw-gear" aria-label="Settings" aria-expanded="false">&#9881;</button>';
            echo '<button type="button" class="btn btn-sm btn-link qpw-toggle" aria-expanded="' . ($collapsed ? 'false' : 'true') . '" aria-label="Collapse or expand">&#9662;</button>';
            echo '</div>';
            if (!empty($w['configurable']) && !empty($w['fields'])) {
                $vals = array_merge(qp_widget_fields_defaults($w['fields']), (array)($lay['settings'][$id] ?? []));
                echo '<form class="qpw-settings card-body border-bottom" hidden data-id="' . $sid . '">';
                qp_widget_fields_render($w['fields'], $vals, 'qpw-' . $id);
                echo '<button type="submit" class="btn btn-sm btn-primary">Save</button></form>';
            }
            echo '<div class="card-body qpw-body"' . ($collapsed ? ' hidden' : '') . '>';
            if ($hidden) {
                // not drawn until shown
                echo '<div class="qpw-lazy text-muted small" data-lazy="1">Loading…</div>';
            } elseif (!empty($w['lazy'])) {
                echo '<div class="qpw-lazy text-muted small" data-lazy="1">Loading…</div>';
            } else {
                $s = array_merge(qp_widget_fields_defaults((array)$w['fields']), (array)($lay['settings'][$id] ?? []));
                echo qp_admin_widget_output($w, $s);
            }
            echo '</div></section>';
        }
        echo '</div>';
    }
    echo '</div></div>';
}

// ---------------------------------------------------------------- AJAX: dashboard layout and lazy widgets

function qp_admin_dash_guard(string $nonce_action = 'qpw_dash'): array {
    $req = $_POST ?: $_REQUEST;
    if (!qp_verify_nonce((string)($req['_n'] ?? ''), $nonce_action)) qp_admin_dash_json(['status' => 'error', 'message' => 'This page has expired. Reload it and try again.'], 403);
    return $req;
}

if (function_exists('add_admin_action')) {
    add_admin_action('iitcm_admin_ajax_qpw_layout_save', function ($r) {
        $req = qp_admin_dash_guard();
        $screen = preg_replace('/[^a-z0-9_-]/', '', (string)($req['screen'] ?? ''));
        $u = get_logged_in_user();
        if ($screen === '' || !$u) qp_admin_dash_json(['status' => 'error', 'message' => 'Bad request.'], 400);
        [$layout, $err] = qp_admin_layout_sanitize($screen, json_decode((string)($req['layout'] ?? ''), true), qp_admin_layout_user($screen));
        if ($err) qp_admin_dash_json(['status' => 'error', 'message' => $err], 422);
        update_user_meta((int)$u['id'], qp_admin_layout_meta_key($screen), $layout);
        qp_admin_dash_json(['status' => 'success']);
    });
    add_admin_action('iitcm_admin_ajax_qpw_layout_reset', function ($r) {
        $req = qp_admin_dash_guard();
        $screen = preg_replace('/[^a-z0-9_-]/', '', (string)($req['screen'] ?? ''));
        $u = get_logged_in_user();
        if ($screen === '' || !$u) qp_admin_dash_json(['status' => 'error', 'message' => 'Bad request.'], 400);
        update_user_meta((int)$u['id'], qp_admin_layout_meta_key($screen), null);
        qp_admin_dash_json(['status' => 'success']);
    });
    add_admin_action('iitcm_admin_ajax_qpw_layout_default', function ($r) {
        $req = qp_admin_dash_guard();
        if (!current_user_can('manage_options')) qp_admin_dash_json(['status' => 'error', 'message' => 'No permission.'], 403);
        $screen = preg_replace('/[^a-z0-9_-]/', '', (string)($req['screen'] ?? ''));
        if ($screen === '') qp_admin_dash_json(['status' => 'error', 'message' => 'Bad request.'], 400);
        [$layout, $err] = qp_admin_layout_sanitize($screen, json_decode((string)($req['layout'] ?? ''), true));
        if ($err) qp_admin_dash_json(['status' => 'error', 'message' => $err], 422);
        $layout['settings'] = [];
        update_option_meta(qp_admin_layout_default_option($screen), $layout);
        qp_admin_dash_json(['status' => 'success']);
    });
    add_admin_action('iitcm_admin_ajax_qpw_widget_load', function ($r) {
        $req = qp_admin_dash_guard();
        $screen = preg_replace('/[^a-z0-9_-]/', '', (string)($req['screen'] ?? ''));
        $id = (string)($req['id'] ?? '');
        $w = qp_admin_widgets_for($screen)[$id] ?? null;
        if (!$w) qp_admin_dash_json(['status' => 'error', 'message' => 'Not available.'], 404);
        $lay = qp_admin_layout_user($screen) ?: [];
        $s = array_merge(qp_widget_fields_defaults((array)$w['fields']), (array)($lay['settings'][$id] ?? []));
        $u = get_logged_in_user();
        $ttl = (int)$w['cache'];
        $draw = function () use ($w, $s) { return qp_admin_widget_output($w, $s); };
        $html = ($ttl > 0 && function_exists('qp_cache_remember'))
            ? qp_cache_remember('w:' . $screen . ':' . $id . ':' . (int)($u['id'] ?? 0) . ':' . md5(json_encode($s)), $ttl, $draw, 'qp_admin_widgets')
            : $draw();
        qp_admin_dash_json(['status' => 'success', 'html' => $html]);
    });
    add_admin_action('iitcm_admin_ajax_qpw_widget_settings', function ($r) {
        $req = qp_admin_dash_guard();
        $screen = preg_replace('/[^a-z0-9_-]/', '', (string)($req['screen'] ?? ''));
        $id = (string)($req['id'] ?? '');
        $w = qp_admin_widgets_for($screen)[$id] ?? null;
        $u = get_logged_in_user();
        if (!$w || empty($w['configurable']) || !$u) qp_admin_dash_json(['status' => 'error', 'message' => 'Not available.'], 404);
        $layout = qp_admin_layout_user($screen) ?: ['cols' => 3, 'order' => [], 'hidden' => [], 'collapsed' => [], 'settings' => []];
        $old = (array)($layout['settings'][$id] ?? []);
        $layout['settings'][$id] = qp_widget_fields_sanitize((array)$w['fields'], (array)($req['settings'] ?? []), $old);
        update_user_meta((int)$u['id'], qp_admin_layout_meta_key($screen), $layout);
        if (function_exists('qp_cache_flush_group')) qp_cache_flush_group('qp_admin_widgets');
        qp_admin_dash_json(['status' => 'success']);
    });
    // ------------------------------------------------------------ Appearance > Widgets (front-end widgets)
    $front_guard = function () {
        $req = qp_admin_dash_guard('qpw_front');
        if (!current_user_can('manage_widgets') && !current_user_can('manage_options')) qp_admin_dash_json(['status' => 'error', 'message' => 'No permission.'], 403);
        return $req;
    };
    add_admin_action('iitcm_admin_ajax_qpw_front_layout', function ($r) use ($front_guard) {
        $req = $front_guard();
        $layout = json_decode((string)($req['layout'] ?? ''), true);
        if (!is_array($layout)) qp_admin_dash_json(['status' => 'error', 'message' => 'The layout is not valid.'], 422);
        qp_widgets_apply_layout($layout);
        qp_admin_dash_json(['status' => 'success', 'state' => qp_widgets_admin_state()]);
    });
    add_admin_action('iitcm_admin_ajax_qpw_front_add', function ($r) use ($front_guard) {
        $req = $front_guard();
        $type = (string)($req['type'] ?? '');
        $area = (string)($req['area'] ?? 'inactive');
        $iid = add_widget_to_area($area, $type, []);
        if (!$iid) qp_admin_dash_json(['status' => 'error', 'message' => 'That widget or area is not available.'], 422);
        qp_admin_dash_json(['status' => 'success', 'id' => $iid, 'state' => qp_widgets_admin_state()]);
    });
    add_admin_action('iitcm_admin_ajax_qpw_front_save', function ($r) use ($front_guard) {
        $req = $front_guard();
        $iid = (string)($req['id'] ?? '');
        $store = qp_widgets_store();
        $inst = $store['instances'][$iid] ?? null;
        $t = is_array($inst) ? qp_widget_type((string)$inst['type']) : null;
        if (!$t) qp_admin_dash_json(['status' => 'error', 'message' => 'Unknown widget.'], 404);
        $store['instances'][$iid]['settings'] = $t->update(array_merge($t->defaults(), (array)($req['settings'] ?? [])), (array)($inst['settings'] ?? []));
        $cond = json_decode((string)($req['cond'] ?? '[]'), true);
        $store['instances'][$iid]['cond'] = qp_widget_conditions_sanitize(is_array($cond) ? $cond : []);
        qp_widgets_save($store);
        qp_admin_dash_json(['status' => 'success', 'state' => qp_widgets_admin_state()]);
    });
    add_admin_action('iitcm_admin_ajax_qpw_front_delete', function ($r) use ($front_guard) {
        $req = $front_guard();
        if (!remove_widget((string)($req['id'] ?? ''))) qp_admin_dash_json(['status' => 'error', 'message' => 'Unknown widget.'], 404);
        qp_admin_dash_json(['status' => 'success', 'state' => qp_widgets_admin_state()]);
    });
}

/** Everything the Appearance > Widgets screen needs to draw itself, as plain data. */
function qp_widgets_admin_state(): array {
    $areas = qp_widget_areas();
    foreach ($areas as $aid => $_) qp_widgets_apply_defaults($aid);
    $store = qp_widgets_store();
    $types = qp_widget_types_all();
    $item = function ($iid) use ($store, $types) {
        $inst = $store['instances'][$iid] ?? null;
        if (!is_array($inst)) return null;
        $t = $types[$inst['type']] ?? null;
        return ['id' => $iid, 'type' => $inst['type'], 'name' => $t ? $t->name : $inst['type'], 'missing' => !$t, 'title' => (string)($inst['settings']['title'] ?? ''), 'cond' => (object)($inst['cond'] ?? [])];
    };
    $out = ['areas' => [], 'inactive' => [], 'types' => []];
    foreach ($areas as $aid => $a) {
        $out['areas'][] = ['id' => $aid, 'name' => $a['name'], 'description' => $a['description'], 'widgets' => array_values(array_filter(array_map($item, (array)($store['areas'][$aid] ?? []))))];
    }
    foreach ($store['inactive'] as $iid) if ($x = $item($iid)) $out['inactive'][] = $x;
    // Widgets whose area the active theme does not register wait here (and return to their area when it is registered again).
    foreach ($store['areas'] as $aid => $list) {
        if (isset($areas[$aid])) continue;
        foreach ($list as $iid) if ($x = $item($iid)) { $x['from'] = $aid; $out['inactive'][] = $x; }
    }
    foreach ($types as $id => $t) $out['types'][] = ['id' => $id, 'name' => $t->name, 'description' => $t->description];
    return $out;
}

/** The settings form of one placed widget as HTML (used by the screen when a widget is opened). */
if (function_exists('add_admin_action')) {
    add_admin_action('iitcm_admin_ajax_qpw_front_form', function ($r) {
        $req = qp_admin_dash_guard('qpw_front');
        if (!current_user_can('manage_widgets') && !current_user_can('manage_options')) qp_admin_dash_json(['status' => 'error', 'message' => 'No permission.'], 403);
        $iid = (string)($req['id'] ?? '');
        $inst = qp_widgets_store()['instances'][$iid] ?? null;
        $t = is_array($inst) ? qp_widget_type((string)$inst['type']) : null;
        if (!$t) qp_admin_dash_json(['status' => 'error', 'message' => 'Unknown widget.'], 404);
        ob_start();
        $t->form(array_merge($t->defaults(), (array)$inst['settings']), 'wf-' . $iid);
        $html = ob_get_clean();
        qp_admin_dash_json(['status' => 'success', 'html' => $html, 'cond' => (object)($inst['cond'] ?? []), 'post_types' => function_exists('get_post_types') ? array_keys(get_post_types()) : ['post', 'page']]);
    });
}
