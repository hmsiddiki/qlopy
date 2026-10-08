<?php
// Example plugin updater declaration for `example-plugin`
// This file would be provided by plugin authors. It should call
// `set_plugin_updater($folder, $current_version, $manifest_url)`
if (!function_exists('set_plugin_updater')) {
    return;
}

$folder = 'example-plugin';
$version = '1.2.1';
$manifest = 'https://aloelo.com/updates/manifest-example-plugin.json';

set_plugin_updater($folder, $version, $manifest);
