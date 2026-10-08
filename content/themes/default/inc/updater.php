<?php
// Example theme updater declaration for `default` theme
// Theme authors should include similar file to register their update manifest
if (!function_exists('set_theme_updater')) {
    return;
}

$folder = 'default';
$version = '1.0.0';
$manifest = 'https://example.com/updates/default-theme.manifest.json';

set_theme_updater($folder, $version, $manifest);
