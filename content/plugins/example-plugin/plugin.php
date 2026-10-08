<?php
/*
Plugin Name: Example Plugin
Plugin URI: https://example.local/qpm/sample-plugin
Description: Example plugin demonstrating.
Author: QP Dev
Author URI: https://example.local/
Version: 1.2.1
License: MIT
// Example dependency header (comma-separated bracket syntax):
*/

add_action('init', function() {
    $plugin_url = PLUGIN_URL . '/example-plugin';

    enqueue_style('example-plugin-style', $plugin_url . '/css/example.css');
    enqueue_script('example-plugin-script', $plugin_url . '/js/example.js', ['jquery'], '1.0', true);
});

// Example AJAX handler
add_action('iitcm_ajax_example_action', function($request) {
    echo json_encode(['status' => 'success', 'message' => 'Example plugin action responded']);
    exit;
}, 10, 1);


// Admin page: render metabox forms and Saved Data display
function example_plugin_admin_page() {
    echo '<h1>Example Plugin</h1>';
    echo '<div style="margin-top:20px;">';
    echo '<p>This is the Example Plugin admin page. Replace this with your settings UI.</p>';
    echo '</div>';
}

register_admin_menu(
    'example-plugin-page',
    'Example plugin',
    null,
    'example_plugin_admin_page'
);