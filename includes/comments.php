<?php
if (!defined('QLOPY_INIT')) { http_response_code(403); exit; }
/**
 * Minimal comments subsystem: storage helpers and moderation APIs.
 * Assumes a `comments` table exists with columns at least:
 * id, object_type, object_id, parent_id, content, author_name, author_email,
 * user_id, status, ip_address, user_agent, created_at
 */

// Load avatar helpers (optional)
if (file_exists(__DIR__ . '/avatar.php')) require_once __DIR__ . '/avatar.php';

// In-request cache for discussion settings to avoid repeated option lookups
if (!function_exists('comment_get_discussion_settings')) {
    function comment_get_discussion_settings(): array {
        static $cache = null;
        if ($cache !== null) return $cache;
        if (function_exists('get_option_meta')) {
            $cache = get_option_meta('discussion_settings') ?? [];
        } else {
            $cache = [];
        }
        return $cache;
    }
}

if (!function_exists('comment_insert')) {
    function comment_insert(array $data) {
        $pdo = db();
        $object_type = $data['object_type'] ?? 'post';
        $object_id = (int)($data['object_id'] ?? 0);
        $content = trim($data['content'] ?? '');
        if ($object_id <= 0 || $content === '') return ['error' => 'invalid'];
        $author_name = trim($data['author_name'] ?? '');
        $author_email = trim($data['author_email'] ?? '');
        $user_id = isset($data['user_id']) ? (int)$data['user_id'] : (function_exists('qp_current_user_id') ? qp_current_user_id() : 0);
        // If a user is logged in, prefer their canonical name/email and user_id
        if (function_exists('qp_current_user_id') && qp_current_user_id()) {
            $uid = qp_current_user_id();
            $user_id = $uid;
            if (function_exists('get_logged_in_user')) {
                $lu = get_logged_in_user();
                if (!empty($lu)) {
                    // Prefer the user's nicename (explicit short name), then display_name,
                    // then first+last, then username as final fallback.
                    $nic = function_exists('get_user_meta') ? get_user_meta($lu['id'], 'nicename') : '';
                    if (!empty($nic)) {
                        $author_name = $nic;
                    } elseif (!empty($lu['display_name'])) {
                        $author_name = $lu['display_name'];
                    } else {
                        $fn = function_exists('get_user_meta') ? get_user_meta($lu['id'], 'first_name') : '';
                        $ln = function_exists('get_user_meta') ? get_user_meta($lu['id'], 'last_name') : '';
                        if (!empty($fn) || !empty($ln)) { $author_name = trim($fn . ' ' . $ln); }
                        else { $author_name = $lu['username'] ?? $author_name; }
                    }
                    if (!empty($lu['email'])) $author_email = $lu['email'];
                }
            }
        }
        $parent_id = isset($data['parent_id']) ? (int)$data['parent_id'] : null;
        $ip = $data['ip_address'] ?? ($_SERVER['REMOTE_ADDR'] ?? null);
        $ua = $data['user_agent'] ?? ($_SERVER['HTTP_USER_AGENT'] ?? null);

        // Load discussion settings for moderation and validation (cached per-request)
        $ds = comment_get_discussion_settings();

        // Server-side validation for required fields
        if (empty($user_id)) {
            if (!empty($ds['require_registered'])) {
                return ['error' => 'require_registered'];
            }
            if (!empty($ds['require_name_email']) && ($author_name === '' || $author_email === '')) {
                return ['error' => 'missing_fields'];
            }
        }

        // Respect global comments open/auto-close settings
        if (!comments_open($object_id, $object_type)) {
            return ['error' => 'closed'];
        }

        // Default status: pending (site owners can override via filter)
        $status = 'pending';
        // Auto-approve logic:
        // - site setting `auto_approve_logged_in` can approve logged-in users
        // - post authors' own comments should be auto-approved
        // - users with moderation/admin capabilities should be auto-approved
        if (!empty($user_id)) {
            // auto approve logged-in users if setting enabled
            $ds = comment_get_discussion_settings();
            if (!empty($ds['auto_approve_logged_in'])) {
                $status = 'approved';
            }
            // admin/moderator capability
            if (function_exists('current_user_can') && current_user_can('manage_options')) {
                $status = 'approved';
            } elseif (function_exists('check_permission') && check_permission('manage_posts')) {
                $status = 'approved';
            }
            // if commenting on a post, allow post author comments to be auto-approved
            if ($object_type === 'post') {
                try {
                    $pstmt = $pdo->prepare('SELECT author_id FROM ' . table_name('posts') . ' WHERE id = ? LIMIT 1');
                    $pstmt->execute([$object_id]);
                    $prow = $pstmt->fetch(PDO::FETCH_ASSOC);
                    if ($prow && isset($prow['author_id']) && (int)$prow['author_id'] === (int)$user_id) {
                        $status = 'approved';
                    }
                } catch (Throwable $_e) {
                    // ignore DB errors here and leave default status
                }
            }
        }

        // Moderation and spam rules (lightweight, configurable)
        // 1) Disallowed keys -> mark spam
        if (!empty($ds['disallowed_keys'])) {
            $bad = array_filter(array_map('trim', preg_split('/\r?\n/', $ds['disallowed_keys'])));
            foreach ($bad as $k) {
                if ($k === '') continue;
                if (stripos($content, $k) !== false) {
                    $status = 'spam';
                    break;
                }
            }
        }

        // 2) Moderation keys -> force pending
        if ($status !== 'spam' && !empty($ds['moderation_keys'])) {
            $keys = array_filter(array_map('trim', preg_split('/\r?\n/', $ds['moderation_keys'])));
            foreach ($keys as $k) {
                if ($k === '') continue;
                if (stripos($content, $k) !== false) { $status = 'pending'; break; }
            }
        }

        // 3) Links threshold
        if ($status !== 'spam' && !empty($ds['moderation_links_threshold'])) {
            $threshold = max(0, (int)$ds['moderation_links_threshold']);
            if ($threshold > 0) {
                preg_match_all('/https?:\/\//i', $content, $m);
                if (count($m[0]) > $threshold) $status = 'pending';
            }
        }

        // 4) Manual approval override
        if (!empty($ds['before_comment_approval_manual'])) {
            $status = 'pending';
        }

        // 5) Prior-approved commenters may be auto-approved
        if ($status === 'pending' && !empty($ds['before_comment_approval_prior_approved']) && !empty($author_email)) {
            try {
                $pstmt = $pdo->prepare('SELECT COUNT(*) FROM ' . table_name('comments') . ' WHERE author_email = ? AND status = ?');
                $pstmt->execute([$author_email, 'approved']);
                $cnt = (int)$pstmt->fetchColumn();
                if ($cnt > 0) $status = 'approved';
            } catch (Throwable $_e) { }
        }
        try {
            $stmt = $pdo->prepare('INSERT INTO ' . table_name('comments') . ' (object_type, object_id, parent_id, content, author_name, author_email, user_id, status, ip_address, user_agent, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())');
            $stmt->execute([$object_type, $object_id, $parent_id, $content, $author_name, $author_email, $user_id, $status, $ip, $ua]);
            return (int)$pdo->lastInsertId();
        } catch (Throwable $e) {
            return ['error' => 'db_error', 'message' => $e->getMessage()];
        }
    }
}

// Comment meta helpers (fallback to option-meta when no dedicated table present)
if (!function_exists('get_comment_meta')) {
    function get_comment_meta(int $comment_id, string $key = null) {
        if (!function_exists('get_option_meta')) return null;
        $opt = get_option_meta('comment_meta_' . (int)$comment_id) ?? [];
        if ($key === null) return $opt;
        return $opt[$key] ?? null;
    }
}

if (!function_exists('update_comment_meta')) {
    function update_comment_meta(int $comment_id, string $key, $value): bool {
        if (!function_exists('get_option_meta') || !function_exists('update_option_meta')) return false;
        $opt = get_option_meta('comment_meta_' . (int)$comment_id) ?? [];
        $opt[$key] = $value;
        update_option_meta('comment_meta_' . (int)$comment_id, $opt);
        return true;
    }
}

if (!function_exists('comment_get_by_id')) {
    function comment_get_by_id(int $id) {
        $pdo = db();
        $stmt = $pdo->prepare('SELECT * FROM ' . table_name('comments') . ' WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
}

if (!function_exists('comment_get_display_name')) {
    /**
     * Resolve a display name for a comment: prefer the linked user's nicename/display_name/first+last/username
     * when user_id is present, otherwise fall back to the comment's author_name.
     * @param array $comment
     * @return string
     */
    function comment_get_display_name(array $comment) {
        $uid = isset($comment['user_id']) ? (int)$comment['user_id'] : 0;
        if ($uid > 0) {
            if (function_exists('get_user_meta')) {
                $nic = get_user_meta($uid, 'nicename');
                if (!empty($nic)) return $nic;
                $disp = get_user_meta($uid, 'display_name');
                if (!empty($disp)) return $disp;
                $fn = get_user_meta($uid, 'first_name');
                $ln = get_user_meta($uid, 'last_name');
                if (!empty($fn) || !empty($ln)) return trim($fn . ' ' . $ln);
            }
            // Fallback to users table username if present
            try {
                $pdo = db();
                $stmt = $pdo->prepare('SELECT username FROM ' . table_name('users') . ' WHERE id = ? LIMIT 1');
                $stmt->execute([$uid]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($row && !empty($row['username'])) return $row['username'];
            } catch (Throwable $_e) {
                // ignore DB errors and fall through
            }
        }
        return !empty($comment['author_name']) ? $comment['author_name'] : 'Anonymous';
    }
}

if (!function_exists('comment_update_status')) {
    function comment_update_status(int $id, string $status, $by_user = null) {
        $pdo = db();
        try {
            $stmt = $pdo->prepare('UPDATE ' . table_name('comments') . ' SET status = ? WHERE id = ?');
            $stmt->execute([$status, $id]);
            return $stmt->rowCount() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('comment_delete')) {
    function comment_delete(int $id) {
        $pdo = db();
        try {
            $stmt = $pdo->prepare('DELETE FROM ' . table_name('comments') . ' WHERE id = ?');
            $stmt->execute([$id]);
            return $stmt->rowCount() > 0;
        } catch (Throwable $e) { return false; }
    }
}

if (!function_exists('comment_update')) {
    function comment_update(int $id, array $data) {
        $pdo = db();
        $fields = [];
        $params = [];
        if (isset($data['content'])) { $fields[] = 'content = ?'; $params[] = $data['content']; }
        if (isset($data['author_name'])) { $fields[] = 'author_name = ?'; $params[] = $data['author_name']; }
        if (isset($data['author_email'])) { $fields[] = 'author_email = ?'; $params[] = $data['author_email']; }
        if (empty($fields)) return false;
        $params[] = $id;
        try {
            $sql = 'UPDATE ' . table_name('comments') . ' SET ' . implode(', ', $fields) . ' WHERE id = ?';
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->rowCount() > 0;
        } catch (Throwable $e) { return false; }
    }
}

if (!function_exists('comment_mark_spam')) {
    function comment_mark_spam(int $id) {
        return comment_update_status($id, 'spam');
    }
}

if (!function_exists('get_comments_number')) {
    function get_comments_number($object_id = null, $object_type = 'post') {
        // Resolve default object id from global $post if not provided
        if ($object_id === null) {
            $g = $GLOBALS['post'] ?? null;
            if (is_array($g) && isset($g['id'])) $object_id = (int)$g['id'];
            else if (is_object($g) && isset($g->ID)) $object_id = (int)$g->ID;
            else $object_id = 0;
        }
        $pdo = db();
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM ' . table_name('comments') . ' WHERE object_type = ? AND object_id = ? AND status = ?');
        $stmt->execute([$object_type, (int)$object_id, 'approved']);
        return (int)$stmt->fetchColumn();
    }
}

if (!function_exists('comments_open')) {
    function comments_open($object_id = null, $object_type = 'post') {
        if ($object_id === null) {
            $g = $GLOBALS['post'] ?? null;
            if (is_array($g) && isset($g['id'])) $object_id = (int)$g['id'];
            else if (is_object($g) && isset($g->ID)) $object_id = (int)$g->ID;
            else $object_id = 0;
        }
        // If post meta explicitly disables comments, respect it
        if ($object_id && function_exists('get_post_meta')) {
            $pm = get_post_meta($object_id, 'comments_open');
            if ($pm !== null) return (bool)$pm;
        }
        // Enforce post-type support: if this post's registered post type
        // does not declare support for 'comments', treat comments as closed.
        if ($object_id && $object_type === 'post') {
            try {
                $pdo = db();
                $pstmt = $pdo->prepare('SELECT post_type FROM ' . table_name('posts') . ' WHERE id = ? LIMIT 1');
                $pstmt->execute([(int)$object_id]);
                $prow = $pstmt->fetch(PDO::FETCH_ASSOC);
                if ($prow && !empty($prow['post_type'])) {
                    $pts = get_post_types();
                    $pt_args = $pts[$prow['post_type']] ?? null;
                    $supports = $pt_args['supports'] ?? [];
                    $has_comments = false;
                    if (is_array($supports)) {
                        foreach ($supports as $k => $v) {
                            if ((is_int($k) && $v === 'comments') || (is_string($k) && $k === 'comments')) { $has_comments = true; break; }
                        }
                    }
                    if (!$has_comments) return false;
                }
            } catch (Throwable $_e) {
                // ignore DB errors and fall through to global default
            }
        }
        // Consult global discussion settings if present
        if (function_exists('get_option_meta')) {
            $ds = get_option_meta('discussion_settings') ?? [];
            // Auto-close: if enabled, close comments on posts older than configured days
            if (!empty($ds['auto_close']) && $object_type === 'post' && $object_id) {
                $days = max(0, (int)($ds['auto_close_days'] ?? 0));
                if ($days > 0) {
                    try {
                        $pdo = db();
                        $pstmt = $pdo->prepare('SELECT created_at FROM ' . table_name('posts') . ' WHERE id = ? LIMIT 1');
                        $pstmt->execute([(int)$object_id]);
                        $prow = $pstmt->fetch(PDO::FETCH_ASSOC);
                        if ($prow && !empty($prow['created_at'])) {
                            $created = strtotime($prow['created_at']);
                            if ($created !== false && (time() - $created) > ($days * 86400)) {
                                return false;
                            }
                        }
                    } catch (Throwable $_e) { /* ignore DB errors and fall through */ }
                }
            }
            if (isset($ds['comments_open_default'])) return (bool)$ds['comments_open_default'];
        }
        // Default: open
        return true;
    }
}

// Load the theme's comments template (if present). Uses global $theme_dir when available.
if (!function_exists('comments_template')) {
    function comments_template() {
        $theme_dir = $GLOBALS['theme_dir'] ?? null;
        $tpl = null;
        if ($theme_dir && file_exists($theme_dir . 'comments.php')) {
            $tpl = $theme_dir . 'comments.php';
        } else {
            // Fallback: look in default theme folder relative to content path
            $possible = __DIR__ . '/../content/themes/default/comments.php';
            if (file_exists($possible)) $tpl = $possible;
        }
        if ($tpl) include $tpl;
    }
}

if (!function_exists('comment_list_for')) {
    /**
     * Fetch approved comments for an object with optional pagination and ordering.
     * @param int $object_id
     * @param string $object_type
     * @param int $page 1-based page number (1 = first)
     * @param int $per_page number per page (0 = no limit)
     * @param string $order_by column to order by (default created_at)
     * @param string $order_dir ASC|DESC
     * @return array
     */
    function comment_list_for(int $object_id, string $object_type = 'post', int $page = 1, int $per_page = 0, string $order_by = 'created_at', string $order_dir = 'ASC') {
        $pdo = db();
        $allowed_cols = ['created_at','id'];
        $order_by = in_array($order_by, $allowed_cols) ? $order_by : 'created_at';
        $order_dir = strtoupper($order_dir) === 'DESC' ? 'DESC' : 'ASC';
        $sql = 'SELECT * FROM ' . table_name('comments') . ' WHERE object_type = ? AND object_id = ? AND status = ? ORDER BY ' . $order_by . ' ' . $order_dir;
        $params = [$object_type, (int)$object_id, 'approved'];
        if ($per_page > 0) {
            $page = max(1, $page);
            $offset = ($page - 1) * $per_page;
            $sql .= ' LIMIT ? OFFSET ?';
            $params[] = (int)$per_page;
            $params[] = (int)$offset;
        }
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return [];
        }
    }
}

if (!function_exists('comment_list_all_for')) {
    /**
     * Fetch all comments for an object (any status) with optional ordering
     * @param int $object_id
     * @param string $object_type
     * @param string $order_dir 'ASC' or 'DESC'
     * @return array
     */
    function comment_list_all_for(int $object_id, string $object_type = 'post', string $order_dir = 'ASC') {
        $pdo = db();
        $order_dir = strtoupper($order_dir) === 'DESC' ? 'DESC' : 'ASC';
        $stmt = $pdo->prepare('SELECT * FROM ' . table_name('comments') . ' WHERE object_type = ? AND object_id = ? ORDER BY created_at ' . $order_dir);
        $stmt->execute([$object_type, $object_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('comment_tree_for')) {
    /**
     * Build threaded comment tree preserving chronological insertion order.
     * @param int $object_id
     * @param string $object_type
     * @param int $max_depth
     * @param string $order_dir 'ASC' or 'DESC' to control chronology used when building tree
     * @return array
     */
    function comment_tree_for(int $object_id, string $object_type = 'post', int $max_depth = 5, string $order_dir = 'ASC') {
        // Build a rendering tree that enforces max visible depth while preserving
        // the original parent_id values in the database. Replies deeper than
        // $max_depth will be attached (for rendering) to the last allowed
        // ancestor so UI nesting never exceeds the configured depth.
        $rows = comment_list_all_for($object_id, $object_type, $order_dir);
        $items = [];
        $order = [];
        foreach ($rows as $r) {
            $r['children'] = [];
            $r['parent_id'] = isset($r['parent_id']) ? (int)$r['parent_id'] : 0;
            $items[(int)$r['id']] = $r;
            $order[] = (int)$r['id'];
        }

        $tree = [];
        $md = max(1, min(10, $max_depth));

        // Helper to build ancestor chain from root -> ... -> item
        $build_chain = function(int $id) use (&$items) {
            $chain = [];
            $seen = [];
            $cur = $id;
            while ($cur && isset($items[$cur]) && !isset($seen[$cur])) {
                $seen[$cur] = true;
                $chain[] = $items[$cur];
                $cur = $items[$cur]['parent_id'] ?? 0;
            }
            return array_reverse($chain); // root .. item
        };

        // Build render tree in original insertion order to preserve chronology
        foreach ($order as $id) {
            if (!isset($items[$id])) continue;
            $item = $items[$id];
            // Determine chain and depth index of this item
            $chain = $build_chain($id);
            $depth = count($chain) - 1; // root depth = 0

            if ($depth >= $md) {
                // Attach to the ancestor at depth $md-1 (last allowed level)
                $attach_ancestor = $chain[$md - 1] ?? null;
                if ($attach_ancestor && isset($items[(int)$attach_ancestor['id']])) {
                    $items[(int)$attach_ancestor['id']]['children'][] = &$items[$id];
                    continue;
                }
            }

            // Standard attach to declared parent if present and not moved above
            $pid = $item['parent_id'] ?? 0;
            if ($pid && isset($items[$pid])) {
                $items[$pid]['children'][] = &$items[$id];
            } else {
                $tree[] = &$items[$id];
            }
        }

        return ['tree' => $tree, 'max_depth' => $md];
    }
}

?>
