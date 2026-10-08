<?php

if (!isset($GLOBALS['qlopy_admin_actions'])) {
    // Structure: ['hook_name' => [priority => [ [callback, accepted_args], ... ]]]]
    $GLOBALS['qlopy_admin_actions'] = [];
}

// Plugin activation/deactivation registry
if (!isset($GLOBALS['qp_plugin_hooks'])) {
    $GLOBALS['qp_plugin_hooks'] = [
        'activate' => [],
        'deactivate' => [],
    ];
}

// (dependency helpers removed — dependency parsing is handled in admin/plugins.php via plugin headers)

/**
 * Register a callback to run when a plugin is activated.
 *
 * @param string $plugin The plugin folder/name used as the key (e.g. 'my-plugin')
 * @param callable $callback
 */
function register_activation_hook(string $plugin, callable $callback): void {
    $GLOBALS['qp_plugin_hooks']['activate'][$plugin][] = $callback;
}

/**
 * Register a callback to run when a plugin is deactivated.
 */
function register_deactivation_hook(string $plugin, callable $callback): void {
    $GLOBALS['qp_plugin_hooks']['deactivate'][$plugin][] = $callback;
}

/**
 * Fire activation callbacks for a plugin (if any).
 */
function do_plugin_activation(string $plugin): void {
    if (empty($GLOBALS['qp_plugin_hooks']['activate'][$plugin])) return;
    foreach ($GLOBALS['qp_plugin_hooks']['activate'][$plugin] as $cb) {
        try { call_user_func($cb, $plugin); } catch (Throwable $e) { error_log('Plugin activation error for ' . $plugin . ': ' . $e->getMessage()); }
    }
    // After plugin activation callbacks run, update compiled rewrite rules
    // when pretty permalinks are in use so any newly-registered CPTs are
    // immediately available. Do not force flush if permalinks are query-style.
    if (function_exists('permalink_get_structure') && permalink_get_structure() === 'pretty') {
        if (function_exists('flush_rewrite_rules')) {
            try { flush_rewrite_rules(); } catch (Throwable $_e) { error_log('Failed to flush rewrite rules after activating ' . $plugin); }
        }
    }
}

/**
 * Fire deactivation callbacks for a plugin (if any).
 */
function do_plugin_deactivation(string $plugin): void {
    if (empty($GLOBALS['qp_plugin_hooks']['deactivate'][$plugin])) return;
    foreach ($GLOBALS['qp_plugin_hooks']['deactivate'][$plugin] as $cb) {
        try { call_user_func($cb, $plugin); } catch (Throwable $e) { error_log('Plugin deactivation error for ' . $plugin . ': ' . $e->getMessage()); }
    }
}

/**
 * Register a callback to an admin ajax hook with priority and accepted args
 */
function add_admin_action(string $hook_name, callable $callback, int $priority = 10, int $accepted_args = 1): void {
    global $qlopy_admin_actions;
    if (!isset($qlopy_admin_actions[$hook_name])) {
        $qlopy_admin_actions[$hook_name] = [];
    }
    if (!isset($qlopy_admin_actions[$hook_name][$priority])) {
        $qlopy_admin_actions[$hook_name][$priority] = [];
    }
    // Prevent duplicates
    foreach ($qlopy_admin_actions[$hook_name][$priority] as $registered) {
        if ($registered[0] === $callback) {
            return;
        }
    }
    $qlopy_admin_actions[$hook_name][$priority][] = [$callback, $accepted_args];
}

/**
 * Execute all callbacks hooked to admin ajax actions for given hook name
 */
function do_admin_action(string $hook_name, ...$args): bool {
    global $qlopy_admin_actions;
    if (empty($qlopy_admin_actions[$hook_name])) {
        return false;
    }
    ksort($qlopy_admin_actions[$hook_name]);
    foreach ($qlopy_admin_actions[$hook_name] as $priority => $callbacks) {
        foreach ($callbacks as $callback_info) {
            list($callback, $accepted_args) = $callback_info;
            $callback_args = array_slice($args, 0, $accepted_args);
            call_user_func_array($callback, $callback_args);
        }
    }
    return true;
}
