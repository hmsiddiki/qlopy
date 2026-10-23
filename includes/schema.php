<?php
if (!defined('QLOPY_INIT')) { http_response_code(403); exit; }
/**
 * Schema helpers for optional, guarded index migrations (C1d).
 *
 * Nothing here is needed for the site to work: every query behaves the same with or without the indexes.
 * Loaded on demand (updater migration, admin tools, tests), never on a normal page view.
 */

/** True when the table has an index with this name. */
function qp_schema_index_exists(string $table, string $index): bool {
    $st = db()->prepare('SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1');
    $st->execute([$table, $index]);
    return (bool)$st->fetchColumn();
}

/**
 * Add one index in place only, with guards. Shared by the named wrappers below.
 *
 * Returns ['status' => 'created'|'exists'|'skipped'|'failed', 'reason' => string]. Never throws and never falls back
 * to a different (locking) ALTER. Guards: InnoDB only, MySQL >= 5.6 / MariaDB >= 10.0, estimated rows under the limit,
 * skipped if present. The result is stored in the option $option so the admin can see why it was skipped.
 */
function qp_schema_add_index(string $table_key, string $index, string $columns, string $kind, string $lock, string $option, int $default_max, bool $dry_run, ?int $max_rows, string $config_key = 'schema_max_rows'): array {
    $res = ['status' => 'skipped', 'reason' => ''];
    try {
        $pdo = db();
        $table = table_name($table_key);

        if (qp_schema_index_exists($table, $index)) {
            $res = ['status' => 'exists', 'reason' => ''];
        } else {
            $st = $pdo->prepare('SELECT ENGINE, TABLE_ROWS FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
            $st->execute([$table]);
            $info = $st->fetch(PDO::FETCH_ASSOC);
            $version = (string)$pdo->query('SELECT VERSION()')->fetchColumn();
            $maria = stripos($version, 'mariadb') !== false;
            $vnum = preg_match('/(\d+\.\d+)/', $version, $m) ? (float)$m[1] : 0.0;
            if ($max_rows === null) {
                global $config;
                $cfg = is_array($config ?? null) ? $config : [];
                $max_rows = (int)($cfg[$config_key] ?? $default_max);
            }

            if (!$info) {
                $res['reason'] = 'table not found';
            } elseif (strcasecmp((string)$info['ENGINE'], 'InnoDB') !== 0) {
                $res['reason'] = 'table engine is ' . $info['ENGINE'] . ', not InnoDB (the ALTER would lock the table)';
            } elseif ($vnum < ($maria ? 10.0 : 5.6)) {
                $res['reason'] = 'server ' . $version . ' cannot add this index without locking the table';
            } elseif ((int)$info['TABLE_ROWS'] > $max_rows) {
                $res['reason'] = 'about ' . (int)$info['TABLE_ROWS'] . ' rows, over the limit of ' . $max_rows . ' (raise ' . $config_key . ' in config.php and run it in a quiet hour)';
            } elseif ($dry_run) {
                $res = ['status' => 'skipped', 'reason' => 'dry run: would create'];
            } else {
                try {
                    $pdo->exec('ALTER TABLE ' . $table . ' ADD ' . $kind . ' ' . $index . ' (' . $columns . '), ALGORITHM=INPLACE, LOCK=' . $lock);
                    $res = ['status' => 'created', 'reason' => ''];
                } catch (Throwable $e) {
                    $res = ['status' => 'skipped', 'reason' => 'server refused the in-place ALTER: ' . $e->getMessage()];
                }
            }
        }
    } catch (Throwable $e) {
        $res = ['status' => 'failed', 'reason' => $e->getMessage()];
    }

    if (!$dry_run && function_exists('update_option_meta')) {
        try { update_option_meta($option, $res + ['at' => date('c')]); } catch (Throwable $_) {}
    }
    return $res;
}

/** post_meta(meta_key, post_id): reads and writes continue during the build (LOCK=NONE). Limit 5,000,000 rows. */
function qp_schema_add_post_meta_index(bool $dry_run = false, ?int $max_rows = null): array {
    return qp_schema_add_index('post_meta', 'post_meta_key_post', 'meta_key, post_id', 'INDEX', 'NONE', 'qp_schema_post_meta_index', 5000000, $dry_run, $max_rows);
}

/**
 * FULLTEXT on posts(title, content) for product/post search (C1d). Nothing uses it until the search setting is on.
 * Adding a FULLTEXT index cannot be done with LOCK=NONE: reads continue but writes to the posts table wait while it builds
 * (seconds for tens of thousands of rows), so the limit is lower: 200,000 rows (config.php 'schema_fulltext_max_rows').
 */
function qp_schema_add_posts_fulltext_index(bool $dry_run = false, ?int $max_rows = null): array {
    return qp_schema_add_index('posts', 'posts_ft_title_content', 'title, content', 'FULLTEXT INDEX', 'SHARED', 'qp_schema_posts_fulltext', 200000, $dry_run, $max_rows, 'schema_fulltext_max_rows');
}

/**
 * posts(post_type, status, id): the sitemap counts and pages published posts of one type in id order. Same guards as the other indexes
 * (in place, InnoDB, size limit, never a locking fallback); the sitemap works without it, only slower on very large tables.
 */
function qp_schema_add_posts_type_status_index(bool $dry_run = false, ?int $max_rows = null): array {
    return qp_schema_add_index('posts', 'posts_type_status_id', 'post_type, status, id', 'INDEX', 'NONE', 'qp_schema_posts_type_status', 5000000, $dry_run, $max_rows);
}
