<?php
// Mark as authorized admin AJAX entrypoint
if (!defined('QLOPY_INIT')) define('QLOPY_INIT', true);
// Prevent any accidental output from included files corrupting JSON
ob_start();
require_once __DIR__ . '/admin_head.php';



// Discard any output produced during bootstrap
if (ob_get_length() !== false) { ob_end_clean(); } else { ob_end_flush(); }

header('Content-Type: application/json');

if (!is_logged_in()) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Access denied']);
    exit;
}

$action = $_REQUEST['action'] ?? null;

// Admin debug disabled in production (no temporary log writes remain).
if (!defined('ADMIN_DEBUG')) define('ADMIN_DEBUG', false);
function admin_debug_log($msg) { /* no-op when ADMIN_DEBUG is false */ }

if (!$action) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'No AJAX action specified']);
    exit;
}

$hook_name = "iitcm_admin_ajax_{$action}";

// Permission check: allow media actions for manage_posts, menus actions for manage_menus,
// otherwise require manage_options
if (str_starts_with($action, 'qp_media_')) {
    if (!check_permission('manage_posts')) {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'No permission']);
        exit;
    }
} else if (str_starts_with($action, 'menus_') || $action === 'menus_get_all' || $action === 'menus_get_panel_boxes' || $action === 'menus_set_panel_boxes' || $action === 'menus_create' || $action === 'menus_save' || $action === 'menus_assign_location' || $action === 'menus_delete') {
    // Menu-related AJAX actions may be managed by users with manage_menus capability
    if (!check_permission('manage_menus')) {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'No permission']);
        exit;
    }
} else if ($action === 'qpmeta_save' || str_starts_with($action, 'qpmeta_') || str_starts_with($action, 'taxonomy_') || str_starts_with($action, 'iit_biograpgy') || in_array($action, ['add_term', 'fetch_terms', 'update_term', 'delete_term', 'add_post', 'update_post', 'delete_post', 'delete_iit_boigraphy_item'])) {
    // QPMeta actions, taxonomy actions, and post/term management actions bypass manage_options
    // Individual handlers will enforce their own permission checks (manage_posts, etc.)
} else {
    if (!check_permission('manage_options')) {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'No permission']);
        exit;
    }
}

// Built-in handlers for QPMeta tags field only (taxonomy_term_search, taxonomy_term_add, taxonomy_set_object_terms)
require_once __DIR__ . '/../db.php';

if ($action === 'taxonomy_term_search') {
    admin_debug_log('Handling taxonomy_term_search');
    $taxonomy = $_POST['taxonomy'] ?? '';
    $q = trim($_POST['q'] ?? '');
    if ($taxonomy === '') {
        echo json_encode(['status' => 'error', 'message' => 'taxonomy required']);
        admin_debug_log('Error: taxonomy required');
        exit;
    }
    try {
        $sql = 'SELECT id, term FROM ' . table_name('taxonomy_terms') . ' WHERE taxonomy = ?';
        $params = [$taxonomy];
        if ($q !== '') {
            $sql .= ' AND term LIKE ?';
            $params[] = '%' . $q . '%';
        }
        $sql .= ' ORDER BY term ASC LIMIT 20';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $terms = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $result = ['status' => 'success', 'terms' => $terms];
        echo json_encode($result);
        admin_debug_log('Success: ' . count($terms) . ' terms');
        exit;
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        admin_debug_log('Exception: ' . $e->getMessage());
        exit;
    }
}

// Built-in QPMeta tag handlers to avoid 404 if hook dispatch fails
if ($action === 'qpmeta_tag_search') {
    admin_debug_log('Handling qpmeta_tag_search');
    $taxonomy = $_POST['taxonomy'] ?? '';
    $q = trim($_POST['q'] ?? '');
    if ($taxonomy === '') { echo json_encode(['status'=>'error','message'=>'taxonomy required']); exit; }
    try {
        $stmt = $pdo->prepare('SELECT id, term FROM ' . table_name('taxonomy_terms') . ' WHERE taxonomy = ? AND term LIKE ? ORDER BY term ASC LIMIT 15');
        $like = ($q !== '') ? "%$q%" : '%';
        $stmt->execute([$taxonomy, $like]);
        $tags = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['status'=>'success','tags'=>$tags]);
        admin_debug_log('qpmeta_tag_search found ' . count($tags) . ' tags');
        exit;
    } catch(Exception $e){ echo json_encode(['status'=>'error','message'=>$e->getMessage()]); exit; }
}

if ($action === 'qpmeta_tag_add') {
    admin_debug_log('Handling qpmeta_tag_add');
    $taxonomy = $_POST['taxonomy'] ?? '';
    $term = trim($_POST['term'] ?? '');
    if ($taxonomy === '' || $term === '') { echo json_encode(['status'=>'error','message'=>'taxonomy and term required']); exit; }
    try {
        // Normalize slug
        $baseSlug = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $term), '-'));
        if ($baseSlug === '') { $baseSlug = 'tag'; }

        // If term exists return existing id
        $stmt = $pdo->prepare('SELECT id, slug FROM ' . table_name('taxonomy_terms') . ' WHERE taxonomy = ? AND term = ? LIMIT 1');
        $stmt->execute([$taxonomy,$term]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            echo json_encode(['status'=>'success','id'=>(int)$row['id']]);
            admin_debug_log('qpmeta_tag_add existing id=' . $row['id'] . ' slug=' . $row['slug']);
            exit;
        }

        // Ensure unique slug within taxonomy
        $slug = $baseSlug; $i = 2;
        $stmtSlug = $pdo->prepare('SELECT COUNT(*) FROM ' . table_name('taxonomy_terms') . ' WHERE taxonomy = ? AND slug = ?');
        while (true) {
            $stmtSlug->execute([$taxonomy,$slug]);
            if ($stmtSlug->fetchColumn() == 0) break;
            $slug = $baseSlug . '-' . $i++;
        }

        // Determine next term_order
        $stmtOrder = $pdo->prepare('SELECT COALESCE(MAX(term_order),0) FROM ' . table_name('taxonomy_terms') . ' WHERE taxonomy = ?');
        $stmtOrder->execute([$taxonomy]);
        $nextOrder = (int)$stmtOrder->fetchColumn() + 1;

        $stmtIns = $pdo->prepare('INSERT INTO ' . table_name('taxonomy_terms') . ' (taxonomy, term, slug, parent_id, term_order) VALUES (?, ?, ?, NULL, ?)');
        $stmtIns->execute([$taxonomy,$term,$slug,$nextOrder]);
        $id = $pdo->lastInsertId();
        echo json_encode(['status'=>'success','id'=>intval($id)]);
        // Flush compiled rewrite cache so new tag slug is available to the router
        if (function_exists('flush_rewrite_rules')) {
            try { flush_rewrite_rules(); } catch (Throwable $_e) { }
        }
        admin_debug_log('qpmeta_tag_add new id=' . $id . ' slug=' . $slug . ' order=' . $nextOrder);
        exit;
    } catch(Exception $e){ echo json_encode(['status'=>'error','message'=>$e->getMessage()]); admin_debug_log('qpmeta_tag_add exception ' . $e->getMessage()); exit; }
}

if ($action === 'qpmeta_tag_set') {
    admin_debug_log('Handling qpmeta_tag_set');
    $post_id = intval($_POST['post_id'] ?? 0);
    $taxonomy = $_POST['taxonomy'] ?? '';
    $tag_ids = $_POST['tag_ids'] ?? [];
    if ($post_id <= 0 || $taxonomy === '') { echo json_encode(['status'=>'error','message'=>'post_id and taxonomy required']); exit; }
    if (!is_array($tag_ids)) $tag_ids = [];
    try {
        // Remove existing terms for that taxonomy
        $stmtSel = $pdo->prepare('SELECT pt.term_id FROM ' . table_name('post_terms') . ' pt INNER JOIN ' . table_name('taxonomy_terms') . ' tt ON tt.id = pt.term_id WHERE pt.post_id = ? AND tt.taxonomy = ?');
        $stmtSel->execute([$post_id,$taxonomy]);
        $existing = $stmtSel->fetchAll(PDO::FETCH_COLUMN);
        if (!empty($existing)) {
            $in = implode(',', array_fill(0, count($existing), '?'));
            $params = array_merge([$post_id], $existing);
            $pdo->prepare('DELETE FROM ' . table_name('post_terms') . ' WHERE post_id = ? AND term_id IN (' . $in . ')')->execute($params);
        }
        $stmtIns = $pdo->prepare('INSERT INTO ' . table_name('post_terms') . ' (post_id, term_id) VALUES (?, ?)');
        foreach ($tag_ids as $tid) { $stmtIns->execute([$post_id, intval($tid)]); }
        echo json_encode(['status'=>'success']);
        admin_debug_log('qpmeta_tag_set saved ' . count($tag_ids) . ' tags');
        exit;
    } catch(Exception $e){ echo json_encode(['status'=>'error','message'=>$e->getMessage()]); admin_debug_log('qpmeta_tag_set exception ' . $e->getMessage()); exit; }
}

if ($action === 'qpmeta_tag_get') {
    admin_debug_log('Handling qpmeta_tag_get');
    $post_id = intval($_POST['post_id'] ?? 0);
    $taxonomy = $_POST['taxonomy'] ?? '';
    if ($post_id <= 0 || $taxonomy === '') { echo json_encode(['status'=>'error','message'=>'post_id and taxonomy required']); exit; }
    try {
        $stmt = $pdo->prepare('SELECT t.id, t.term FROM ' . table_name('taxonomy_terms') . ' t INNER JOIN ' . table_name('post_terms') . ' pt ON pt.term_id = t.id WHERE pt.post_id = ? AND t.taxonomy = ? ORDER BY t.term ASC');
        $stmt->execute([$post_id,$taxonomy]);
        $tags = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['status'=>'success','tags'=>$tags]);
        admin_debug_log('qpmeta_tag_get returned ' . count($tags) . ' tags');
        exit;
    } catch(Exception $e){ echo json_encode(['status'=>'error','message'=>$e->getMessage()]); admin_debug_log('qpmeta_tag_get exception ' . $e->getMessage()); exit; }
}

// Removed legacy taxonomy_term_add and taxonomy_set_object_terms handlers (tags use qpmeta_tag_* now)

$args = [$_REQUEST];

// First try to dispatch the hook, if no handlers return 404 error
if (!do_admin_action($hook_name, ...$args)) {
    // Fallback: if no admin-specific handler, try global AJAX actions
    $fallback_hook = "iitcm_ajax_{$action}";
    if (function_exists('do_action') && do_action($fallback_hook, ...$args)) {
        exit; // handled by global ajax hook
    }
    http_response_code(404);
    echo json_encode(['status' => 'error', 'message' => 'No handler for: ' . $hook_name]);
}

exit;