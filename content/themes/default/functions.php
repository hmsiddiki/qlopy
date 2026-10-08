<?php
/*
Theme Name: Default
Theme URI: https://example.com/default-theme
Author: Your Name
Author URI: https://example.com
Description: Default theme for the site. Place theme metadata here in this header block.
Version: 1.0
License: MIT
License URI: https://opensource.org/licenses/MIT
*/

add_action('init', function() {
    $theme_url = THEME_URL;

    enqueue_style('bootstrap', 'https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css');
    enqueue_style('theme-style', $theme_url . '/css/style.css', ['bootstrap'], '1.0');

    enqueue_script('jquery', 'https://code.jquery.com/jquery-3.5.1.min.js', [], '3.5.1', false);
    enqueue_script('bootstrap', 'https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.bundle.min.js', ['jquery'], '4.5.2', true);
    enqueue_script('theme-script', $theme_url . '/js/script.js', ['jquery'], '1.0', true);
    // Comment reply helper (WP-like move-form behavior)
    enqueue_script('comment-reply', $theme_url . '/js/comment-reply.js', ['jquery'], '1.0', true);
});

// Theme: register a custom query var for the contact page and add a rewrite rule
add_action('init', function() {
    if (function_exists('register_query_var')) {
        register_query_var('contact_ref');
    }
    if (function_exists('add_rewrite_rule')) {
        // /contact/<ref> -> singular page slug=contact with contact_ref set
        add_rewrite_rule('^contact/([^/]+)/?$', 'route=singular&post_type=page&slug=contact&contact_ref=$1', 'top');
        // also accept plain /contact
        add_rewrite_rule('^contact/?$', 'route=singular&post_type=page&slug=contact', 'top');
    }
    // Try to refresh compiled rewrite cache so runtime registration is available
    if (function_exists('flush_rewrite_rules')) {
        try { flush_rewrite_rules(); } catch (Throwable $_e) { /* ignore */ }
    }
});

// Note: do NOT flush rewrite rules on every admin init — this causes
// unnecessary disk writes and latency. Rely on permalink save and term
// CRUD hooks to call `flush_rewrite_rules()` when needed.

// Recurring maintenance task: remove old cron logs (40 rows) every 5 minutes
/*if (function_exists('add_qp_cron_action')) {
    add_qp_cron_action('theme_log_time', function($args = []) {
        // Delete a fixed number of oldest log rows per run (default 40)
        $delete_count = isset($args['delete_count']) ? max(0, (int)$args['delete_count']) : 40;

        if ($delete_count <= 0) return false;
        if (!function_exists('db') || !function_exists('table_name')) return false;

        $pdo = db();
        // Ensure logs table exists
        if (function_exists('qp_cron_logs_install')) qp_cron_logs_install($pdo);
        $table = table_name('cron_logs');

        try {
            // Delete the oldest $delete_count rows (by created_at asc)
            $sql = "DELETE FROM {$table} WHERE id IN (SELECT id FROM (SELECT id FROM {$table} ORDER BY created_at ASC LIMIT :lim) AS t)";
            $stmt = $pdo->prepare($sql);
            $stmt->bindValue(':lim', $delete_count, PDO::PARAM_INT);
            $ok = $stmt->execute();
            // Record last run time
            if ($ok && function_exists('update_option_meta')) update_option_meta('theme_log_time_last_run', time());
            return $ok;
        } catch (Throwable $e) {
            return false;
        }
    });

    // Schedule recurring cleanup (every 5 minutes = 300 seconds) if not already scheduled
    add_admin_action('init', function() {
        if (!function_exists('qp_schedule_single_event')) return;
        // Avoid creating duplicate scheduled events
        $exists = false;
        if (function_exists('qp_get_scheduled_events')) {
            $rows = qp_get_scheduled_events();
            foreach ($rows as $r) {
                if (!empty($r['hook']) && $r['hook'] === 'theme_log_time') {
                    $status = strtolower($r['status'] ?? '');
                    if ($status === 'pending') { $exists = true; break; }
                }
            }
        }
        if ($exists) return;
        // schedule immediate recurring run (time() + 30 seconds) with recurrence 300s
        qp_schedule_single_event(time() + 30, 'theme_log_time', [], 300);
    });
}
*/
// Theme cron docs moved to admin/cron.php for central documentation (kept minimal here).

// Replace recurring theme cron with a single one-off job that only runs under CLI.
// This schedules a single `theme_cli_oneoff` task (no recurrence). The handler
// only performs work when executed by the CLI runner.
if (function_exists('add_qp_cron_action')) {
    add_qp_cron_action('theme_cli_oneoff', function($args = []) {
        // Only perform the task when running from the CLI (the scheduled job
        // will be processed by the CLI runner). If executed from web, do nothing.
        if (php_sapi_name() !== 'cli') return;

        $logDir = __DIR__ . '/logs';
        if (!is_dir($logDir)) @mkdir($logDir, 0755, true);
        $file = $logDir . '/cron_oneoff.log';
        $when = date('Y-m-d H:i:s');
        $pid = function_exists('getmypid') ? getmypid() : 'n/a';
        $mem = function_exists('memory_get_usage') ? round(memory_get_usage() / 1024) . 'KB' : 'n/a';

        $line = "[{$when}] theme_cli_oneoff run pid={$pid} mem={$mem} args=" . json_encode($args) . PHP_EOL;
        @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
    });

    // Schedule a single one-off job when an admin loads the site and the job
    // is not already scheduled. This ensures the one-off is created only by
    // administrative actions (useful for avoiding public scheduling).
    add_admin_action('init', function() {
        if (!function_exists('qp_schedule_single_event')) return;
        // Avoid creating the one-off if any task with the same hook exists
        // in the queue (regardless of status). This prevents duplicates even
        // if previous runs are marked 'done' or 'failed'.
        $exists = false;
        if (function_exists('qp_get_scheduled_events')) {
            $rows = qp_get_scheduled_events();
            foreach ($rows as $r) {
                if (!empty($r['hook']) && $r['hook'] === 'theme_cli_oneoff') { $exists = true; break; }
            }
        }
        if ($exists) return;
        // schedule immediate run (time()). Pass recurrence = 0 (one-off).
        qp_schedule_single_event(time(), 'theme_cli_oneoff', [], 0);
    });
}


/*add_admin_action('init', function() {
    // Register a metabox for taxonomy terms (object type 'term')
    qpmeta_register_metabox('taxonomy_term_extra', [
        'title' => 'Term Additional Fields',
        'object_types' => ['term'],
        'fields' => [
            [
                'name' => 'Custom Icon',
                'id' => 'custom_icon',
                'type' => 'text',
                'desc' => 'Icon class for this term (e.g., fa-star)'
            ],
            [
                'name' => 'Show on Homepage?',
                'id' => 'show_home',
                'type' => 'checkbox',
                'desc' => 'Check to highlight this term on homepage.'
            ],
            [
                'name' => 'More Details',
                'id' => 'more_details',
                'type' => 'textarea',
                'desc' => 'Additional information about this term.'
            ]
        ]
    ]);
});
*/

// Register Page Banner metabox for Pages (post_type = page) on left side
add_admin_action('init', function() {
    qpmeta_register_metabox('page_banner_metabox', [
        'title' => 'Page Banner',
        'object_types' => ['post'],
        'post_types' => ['page'],
        'context' => 'normal', // left side
        'fields' => [
            [
                'id' => 'page_banner',
                'name' => 'Page Banner Image',
                'type' => 'file',
                'desc' => 'Upload a banner image for this page'
            ]
        ]
    ]);
    
    // Replace previous text example with a textarea example
    qpmeta_register_metabox('page_intro_metabox', [
        'title' => 'Page Introduction',
        'object_types' => ['post'],
        'post_types' => ['page'],
        'context' => 'normal', // left side
        'fields' => [
            [
                'id' => 'page_intro',
                'name' => 'Intro Display Style',
                'type' => 'select',
                'options' => [
                    '' => 'Select a style',
                    'none' => 'No Intro',
                    'brief' => 'Brief Intro',
                    'extended' => 'Extended Intro'
                ],
                'desc' => 'Choose how the intro section should render.'
            ],
            [
                'id' => 'page_show_feature_box',
                'name' => 'Show Feature Box',
                'type' => 'checkbox',
                'desc' => 'Enable a highlighted feature box near the top.'
            ],
            [
                'id' => 'page_features',
                'name' => 'Page Feature Flags',
                'type' => 'multicheck',
                'options' => [
                    'show_banner' => 'Show Banner',
                    'highlight_layout' => 'Highlight Layout',
                    'cta' => 'Show Call To Action'
                ],
                'desc' => 'Select one or more feature toggles for this page.'
            ]
        ]
    ]);

    // Page Bio (richtext) metabox for Pages
    qpmeta_register_metabox('page_bio_metabox', [
        'title' => 'Your Bio',
        'object_types' => ['post'],
        'post_types' => ['page'],
        'context' => 'normal',
        'fields' => [
            [
                'id' => 'your_bio',
                'name' => 'Your Bio',
                'type' => 'richtext',
                'desc' => 'Write a bio to display on this page.'
            ]
        ]
    ]);

    // Repeatable Team Members metabox for Pages
    qpmeta_register_metabox('page_team_members_metabox', [
        'title' => 'Team Members',
        'object_types' => ['post'],
        'post_types' => ['page'],
        'context' => 'normal',
        'repeatable' => true, // Make entire group repeatable
        'fields' => [
            [
                'id' => 'team_member_name',
                'name' => 'Name',
                'type' => 'text',
                'desc' => 'Team member name'
            ],
            [
                'id' => 'team_member_bio',
                'name' => 'Bio',
                'type' => 'richtext',
                'desc' => 'Team member biography'
            ]
        ]
    ]);

    // Sample repeatable richtext metabox for Category terms
    qpmeta_register_metabox('category_content_blocks', [
        'title' => 'Category Content Blocks',
        'object_types' => ['term'],
        // Limit this metabox to the `category` taxonomy only
        'taxonomies' => ['category'],
        'context' => 'normal',
        'repeatable' => true,
        'fields' => [
            [
                'id' => 'category_block_content',
                'name' => 'Content Block',
                'type' => 'richtext',
                'desc' => 'Add a repeatable rich content block for this category.'
            ]
        ]
    ]);
});

