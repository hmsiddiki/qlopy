<?php
if (!defined('QLOPY_INIT')) { http_response_code(403); exit; }
// includes/rest-routes.php
// The routes core registers under /api/qlopy/v1 (core upgrade plan C4): discovery, OpenAPI, auth, posts, pages, terms, media, users, options.
// Reads of public content need no credentials; every other route checks a capability for the authenticated user.
// Writes go through the same hooks the admin screens fire (save_post, delete_post, create_term, edit_term, delete_term).

$GLOBALS['qp_rest_core_loading'] = true;

// ---------------------------------------------------------------- shared helpers

function qp_rest_now(): string { return date('Y-m-d H:i:s'); }

function qp_rest_slugify(string $s): string {
    $t = function_exists('iconv') ? (iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s) ?: $s) : $s;
    $t = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($t)), '-');
    return $t !== '' ? mb_substr($t, 0, 120) : 'item';
}

function qp_rest_unique_slug(string $base, int $exceptId = 0): string {
    $pdo = db(); $T = table_name('posts');
    $slug = $base; $n = 1;
    $st = $pdo->prepare("SELECT id FROM $T WHERE slug = ? LIMIT 1");
    while (true) {
        $st->execute([$slug]);
        $id = $st->fetchColumn();
        if (!$id || (int)$id === $exceptId) return $slug;
        $slug = $base . '-' . (++$n);
        if ($n > 500) return $base . '-' . bin2hex(random_bytes(4));
    }
}

function qp_rest_post_types(): array {
    $o = [];
    foreach (function_exists('get_post_types') ? get_post_types() : ['post' => [], 'page' => []] as $k => $_) if ($k !== 'attachment') $o[] = $k;
    return $o ?: ['post', 'page'];
}

/** Capability for an action on a post type: pages have their own set; custom types use the post set. */
function qp_rest_post_cap(string $type, string $action): string {
    $page = $type === 'page';
    return [
        'edit' => $page ? 'edit_pages' : 'edit_posts', 'publish' => $page ? 'publish_pages' : 'publish_posts',
        'delete' => $page ? 'delete_pages' : 'delete_posts', 'others' => $page ? 'edit_others_pages' : 'edit_others_posts',
    ][$action];
}

function qp_rest_link(array $p): string {
    $slug = (string)$p['slug']; $type = (string)$p['post_type'];
    if ($type === 'page' && function_exists('permalink_for_page')) return permalink_for_page($slug);
    return function_exists('permalink_for_post') ? permalink_for_post($slug, $type) : '';
}

function qp_rest_post_terms(array $ids): array {
    if (!$ids) return [];
    $in = implode(',', array_fill(0, count($ids), '?'));
    $st = db()->prepare('SELECT pt.post_id, t.taxonomy, t.id FROM ' . table_name('post_terms') . ' pt JOIN ' . table_name('taxonomy_terms') . " t ON t.id = pt.term_id WHERE pt.post_id IN ($in)");
    $st->execute(array_values($ids));
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $out[(int)$r['post_id']][$r['taxonomy']][] = (int)$r['id'];
    return $out;
}

function qp_rest_post_item(array $p, array $terms, QP_REST_Request $r): array {
    $private = $p['visibility'] !== 'public' || $p['status'] !== 'published';
    $canSee = $r->can(qp_rest_post_cap((string)$p['post_type'], 'edit'));
    $protected = $p['visibility'] === 'password' && !$canSee;
    return [
        'id' => (int)$p['id'], 'type' => $p['post_type'], 'slug' => $p['slug'], 'title' => $p['title'],
        'content' => $protected ? '' : $p['content'], 'protected' => $protected,
        'status' => $p['status'], 'visibility' => $p['visibility'], 'author_id' => (int)$p['author_id'],
        'published_at' => $p['published_at'], 'created_at' => $p['created_at'], 'updated_at' => $p['updated_at'],
        'link' => qp_rest_link($p), 'terms' => (object)($terms[(int)$p['id']] ?? []),
    ];
}

function qp_rest_fetch_post(int $id, string $type = ''): ?array {
    $st = db()->prepare('SELECT * FROM ' . table_name('posts') . ' WHERE id = ?' . ($type !== '' ? ' AND post_type = ?' : '') . ' LIMIT 1');
    $st->execute($type !== '' ? [$id, $type] : [$id]);
    $p = $st->fetch(PDO::FETCH_ASSOC);
    return $p ?: null;
}

/** May this request see this post at all? Public: published and public. With the edit capability: anything. */
function qp_rest_can_see_post(array $p, QP_REST_Request $r): bool {
    if ($p['status'] === 'published' && in_array($p['visibility'], ['public', 'password'], true)) return true;
    return $r->can(qp_rest_post_cap((string)$p['post_type'], 'edit'));
}

function qp_rest_set_post_terms(int $postId, $terms): void {
    if (!is_array($terms)) return;
    $pdo = db();
    foreach ($terms as $tax => $ids) {
        if (!is_string($tax) || !is_array($ids)) continue;
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), function ($i) { return $i > 0; })));
        $pdo->prepare('DELETE pt FROM ' . table_name('post_terms') . ' pt JOIN ' . table_name('taxonomy_terms') . ' t ON t.id = pt.term_id WHERE pt.post_id = ? AND t.taxonomy = ?')->execute([$postId, $tax]);
        if (!$ids) continue;
        $valid = $pdo->prepare('SELECT id FROM ' . table_name('taxonomy_terms') . ' WHERE taxonomy = ? AND id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')');
        $valid->execute(array_merge([$tax], $ids));
        $ins = $pdo->prepare('INSERT IGNORE INTO ' . table_name('post_terms') . ' (post_id, term_id) VALUES (?, ?)');
        foreach ($valid->fetchAll(PDO::FETCH_COLUMN) as $tid) $ins->execute([$postId, (int)$tid]);
    }
}

// ---------------------------------------------------------------- posts and pages

function qp_rest_posts_list(QP_REST_Request $r, string $forceType = ''): QP_REST_Response|QP_REST_Error {
    $type = $forceType ?: (string)$r['post_type'];
    $status = (string)$r['status'];
    $canEdit = $r->can(qp_rest_post_cap($type, 'edit'));
    if ($status !== 'published' && !$canEdit) return new QP_REST_Error($r->user_id ? 'forbidden' : 'unauthorized', 'Only published content is available without the right to edit.', $r->user_id ? 403 : 401);
    $where = ['post_type = ?']; $params = [$type];
    if ($status !== 'any') { $where[] = 'status = ?'; $params[] = $status; }
    if (!$canEdit) { $where[] = "visibility IN ('public','password')"; }
    if ($r['search'] !== null && $r['search'] !== '') { $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], (string)$r['search']) . '%'; $where[] = '(title LIKE ? OR content LIKE ?)'; $params[] = $like; $params[] = $like; }
    if (!empty($r['include'])) { $ids = array_map('intval', (array)$r['include']); $where[] = 'id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')'; $params = array_merge($params, $ids); }
    if (!empty($r['author'])) { $where[] = 'author_id = ?'; $params[] = (int)$r['author']; }
    $W = implode(' AND ', $where);
    $per = (int)$r['per_page']; $page = (int)$r['page'];
    $order = ['published_at' => 'published_at', 'created_at' => 'created_at', 'title' => 'title', 'id' => 'id'][$r['orderby']] ?? 'created_at';
    $dir = $r['order'] === 'asc' ? 'ASC' : 'DESC';
    $pdo = db(); $T = table_name('posts');
    $cnt = $pdo->prepare("SELECT COUNT(*) FROM $T WHERE $W"); $cnt->execute($params);
    $total = (int)$cnt->fetchColumn();
    $st = $pdo->prepare("SELECT * FROM $T WHERE $W ORDER BY $order $dir, id $dir LIMIT $per OFFSET " . (($page - 1) * $per));
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    $terms = qp_rest_post_terms(array_map(function ($p) { return (int)$p['id']; }, $rows));
    $items = array_map(function ($p) use ($terms, $r) { return qp_rest_select_fields(qp_rest_post_item($p, $terms, $r), $r['fields']); }, $rows);
    return qp_rest_paginated($items, $total, $page, $per);
}

function qp_rest_post_get(QP_REST_Request $r, string $forceType = ''): array|QP_REST_Error {
    $p = qp_rest_fetch_post((int)$r['id'], $forceType);
    if (!$p || $p['post_type'] === 'attachment' || !qp_rest_can_see_post($p, $r)) return new QP_REST_Error('not_found', 'No such item.', 404);
    return qp_rest_select_fields(qp_rest_post_item($p, qp_rest_post_terms([(int)$p['id']]), $r), $r['fields']);
}

function qp_rest_post_write(QP_REST_Request $r, ?array $existing, string $forceType = ''): QP_REST_Response|QP_REST_Error {
    $type = $existing['post_type'] ?? ($forceType ?: (string)($r['post_type'] ?: 'post'));
    if (!in_array($type, qp_rest_post_types(), true)) return new QP_REST_Error('invalid_params', 'Unknown content type.', 400, ['post_type' => 'Unknown content type.']);
    $uid = $r->user_id;
    if ($existing && (int)$existing['author_id'] !== $uid && !$r->can(qp_rest_post_cap($type, 'others'))) return new QP_REST_Error('forbidden', 'You may only change your own items.', 403);
    $title = $r['title'] !== null ? trim((string)$r['title']) : ($existing['title'] ?? '');
    if ($title === '') return new QP_REST_Error('invalid_params', 'A title is required.', 400, ['title' => 'A title is required.']);
    $content = $r['content'] !== null ? (string)$r['content'] : ($existing['content'] ?? '');
    // Raw HTML is stored only for users who may publish HTML on the site (same rule as the admin editor); others get the safe subset.
    if (!qp_rest_user_can($uid, 'unfiltered_html') && !qp_rest_user_can($uid, 'manage_options')) $content = function_exists('qp_kses_post') ? (string)qp_kses_post($content) : strip_tags($content, '<p><br><strong><em><b><i><u><ul><ol><li><a><h2><h3><h4><blockquote>');
    $status = $r['status'] !== null ? (string)$r['status'] : ($existing['status'] ?? 'draft');
    if (in_array($status, ['published', 'scheduled'], true) && ($existing['status'] ?? '') !== $status && !$r->can(qp_rest_post_cap($type, 'publish'))) $status = 'pending_review';
    $visibility = $r['visibility'] !== null ? (string)$r['visibility'] : ($existing['visibility'] ?? 'public');
    $pdo = db(); $T = table_name('posts');
    $slugIn = $r['slug'] !== null ? qp_rest_slugify((string)$r['slug']) : ($existing['slug'] ?? '');
    $slug = qp_rest_unique_slug($slugIn !== '' ? $slugIn : qp_rest_slugify($title), (int)($existing['id'] ?? 0));
    $publishedAt = $existing['published_at'] ?? null;
    if ($status === 'published' && !$publishedAt) $publishedAt = qp_rest_now();
    if ($r['published_at'] !== null && $r->can(qp_rest_post_cap($type, 'publish'))) { $t = strtotime((string)$r['published_at']); if ($t) $publishedAt = date('Y-m-d H:i:s', $t); }
    if ($existing) {
        $pdo->prepare("UPDATE $T SET title = ?, slug = ?, content = ?, status = ?, visibility = ?, published_at = ? WHERE id = ?")->execute([$title, $slug, $content, $status, $visibility, $publishedAt, (int)$existing['id']]);
        $id = (int)$existing['id'];
    } else {
        $pdo->prepare("INSERT INTO $T (title, slug, content, post_type, author_id, status, visibility, published_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)")->execute([$title, $slug, $content, $type, $uid, $status, $visibility, $publishedAt]);
        $id = (int)$pdo->lastInsertId();
    }
    qp_rest_set_post_terms($id, $r['terms']);
    if (function_exists('do_action')) do_action('save_post', $id);
    $p = qp_rest_fetch_post($id);
    $res = new QP_REST_Response(qp_rest_post_item($p, qp_rest_post_terms([$id]), $r), $existing ? 200 : 201);
    if (!$existing) $res->headers['Location'] = (defined('SITE_URL') ? SITE_URL : '') . '/api/qlopy/v1/' . ($type === 'page' ? 'pages' : 'posts') . '/' . $id;
    return $res;
}

function qp_rest_post_delete(QP_REST_Request $r, string $forceType = ''): array|QP_REST_Error {
    $p = qp_rest_fetch_post((int)$r['id'], $forceType);
    if (!$p || $p['post_type'] === 'attachment') return new QP_REST_Error('not_found', 'No such item.', 404);
    $type = (string)$p['post_type'];
    if ((int)$p['author_id'] !== $r->user_id && !$r->can(qp_rest_post_cap($type, 'others'))) return new QP_REST_Error('forbidden', 'You may only delete your own items.', 403);
    $pdo = db(); $id = (int)$p['id'];
    if (empty($r['force'])) {
        if ($p['status'] === 'trash') return ['id' => $id, 'deleted' => false, 'status' => 'trash'];
        $pdo->prepare('UPDATE ' . table_name('posts') . " SET status = 'trash' WHERE id = ?")->execute([$id]);
        if (function_exists('do_action')) do_action('save_post', $id);
        return ['id' => $id, 'deleted' => false, 'status' => 'trash'];
    }
    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM ' . table_name('post_terms') . ' WHERE post_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM ' . table_name('post_meta') . ' WHERE post_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM ' . table_name('posts') . ' WHERE id = ?')->execute([$id]);
        $pdo->commit();
    } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
    if (function_exists('do_action')) do_action('delete_post', $id);
    return ['id' => $id, 'deleted' => true];
}

function qp_rest_register_posts_routes(): void {
    $ns = 'qlopy/v1';
    $listArgs = [
        'status' => ['type' => 'string', 'enum' => ['published', 'draft', 'pending_review', 'scheduled', 'trash', 'any'], 'default' => 'published'],
        'search' => ['type' => 'string', 'maxLength' => 100], 'author' => ['type' => 'integer', 'minimum' => 1],
        'include' => ['type' => 'array', 'items' => ['type' => 'integer'], 'maxItems' => 100],
        'per_page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 10], 'page' => ['type' => 'integer', 'minimum' => 1, 'default' => 1],
        'orderby' => ['type' => 'string', 'enum' => ['published_at', 'created_at', 'title', 'id'], 'default' => 'created_at'], 'order' => ['type' => 'string', 'enum' => ['asc', 'desc'], 'default' => 'desc'],
        'fields' => ['type' => 'string', 'maxLength' => 200],
    ];
    $writeArgs = [
        'title' => ['type' => 'string', 'maxLength' => 255], 'content' => ['type' => 'string', 'maxLength' => 2000000], 'slug' => ['type' => 'string', 'maxLength' => 120],
        'status' => ['type' => 'string', 'enum' => ['draft', 'published', 'pending_review', 'scheduled', 'trash']],
        'visibility' => ['type' => 'string', 'enum' => ['public', 'password', 'private']],
        'published_at' => ['type' => 'string', 'maxLength' => 40], 'terms' => ['type' => 'object'],
    ];
    $idArg = ['id' => ['type' => 'integer', 'minimum' => 1, 'required' => true]];
    $perm = function (string $action, string $forceType = '') {
        return function (QP_REST_Request $r) use ($action, $forceType) {
            $type = $forceType ?: (string)($r['post_type'] ?: 'post');
            if ($action === 'create') return $r->can(qp_rest_post_cap($type, 'edit'));
            $p = qp_rest_fetch_post((int)$r['id'], $forceType);
            return $r->can(qp_rest_post_cap($p['post_type'] ?? $type, $action === 'delete' ? 'delete' : 'edit'));
        };
    };
    foreach (['posts' => '', 'pages' => 'page'] as $res => $force) {
        $typeArg = $force === '' ? ['post_type' => ['type' => 'string', 'enum' => qp_rest_post_types(), 'default' => 'post']] : [];
        $list = function (QP_REST_Request $r) use ($force) { return qp_rest_posts_list($r, $force); };
        qp_register_rest_route($ns, "/$res", [
            ['methods' => 'GET', 'callback' => $list, 'permission_callback' => 'qp_rest_permit_public', 'args' => $listArgs + $typeArg, 'scope' => "$res:read", 'summary' => "List $res"],
            ['methods' => 'POST', 'callback' => function (QP_REST_Request $r) use ($force) { return qp_rest_post_write($r, null, $force); }, 'permission_callback' => $perm('create', $force), 'args' => $writeArgs + $typeArg, 'scope' => "$res:write", 'summary' => "Create one of $res"],
        ]);
        qp_register_rest_route($ns, "/$res/(?P<id>\\d+)", [
            ['methods' => 'GET', 'callback' => function (QP_REST_Request $r) use ($force) { return qp_rest_post_get($r, $force); }, 'permission_callback' => 'qp_rest_permit_public', 'args' => $idArg + ['fields' => ['type' => 'string', 'maxLength' => 200]], 'scope' => "$res:read", 'summary' => "Get one of $res"],
            ['methods' => ['PATCH', 'PUT'], 'callback' => function (QP_REST_Request $r) use ($force) { $p = qp_rest_fetch_post((int)$r['id'], $force); if (!$p || $p['post_type'] === 'attachment') return new QP_REST_Error('not_found', 'No such item.', 404); return qp_rest_post_write($r, $p, $force); }, 'permission_callback' => $perm('update', $force), 'args' => $idArg + $writeArgs, 'scope' => "$res:write", 'summary' => "Change one of $res"],
            ['methods' => 'DELETE', 'callback' => function (QP_REST_Request $r) use ($force) { return qp_rest_post_delete($r, $force); }, 'permission_callback' => $perm('delete', $force), 'args' => $idArg + ['force' => ['type' => 'boolean', 'default' => false]], 'scope' => "$res:write", 'summary' => "Trash one of $res (force=true deletes for good)"],
        ]);
    }
}

// ---------------------------------------------------------------- terms

function qp_rest_term_item(array $t): array {
    return ['id' => (int)$t['id'], 'taxonomy' => $t['taxonomy'], 'name' => $t['term'], 'slug' => $t['slug'], 'parent' => $t['parent_id'] === null ? 0 : (int)$t['parent_id'], 'order' => (int)$t['term_order'],
            'link' => function_exists('permalink_for_term') ? permalink_for_term($t['taxonomy'], $t['slug']) : ''];
}

function qp_rest_taxonomies(): array { return function_exists('get_taxonomies') ? array_keys(get_taxonomies()) : ['category', 'post-tag']; }

function qp_rest_register_terms_routes(): void {
    $ns = 'qlopy/v1';
    $manage = function (QP_REST_Request $r) { return $r->can('manage_categories') || $r->can('manage_posts'); };
    $write = ['name' => ['type' => 'string', 'maxLength' => 100], 'slug' => ['type' => 'string', 'maxLength' => 100], 'parent' => ['type' => 'integer', 'minimum' => 0]];
    qp_register_rest_route($ns, '/terms', [
        ['methods' => 'GET', 'permission_callback' => 'qp_rest_permit_public', 'scope' => 'terms:read', 'summary' => 'List terms of a taxonomy',
         'args' => ['taxonomy' => ['type' => 'string', 'required' => true, 'enum' => qp_rest_taxonomies()], 'search' => ['type' => 'string', 'maxLength' => 100], 'parent' => ['type' => 'integer', 'minimum' => 0],
                    'per_page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 50], 'page' => ['type' => 'integer', 'minimum' => 1, 'default' => 1]],
         'callback' => function (QP_REST_Request $r) {
             $W = ['taxonomy = ?']; $P = [(string)$r['taxonomy']];
             if ($r['search'] !== null && $r['search'] !== '') { $W[] = 'term LIKE ?'; $P[] = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], (string)$r['search']) . '%'; }
             if ($r['parent'] !== null) { if ((int)$r['parent'] === 0) $W[] = '(parent_id IS NULL OR parent_id = 0)'; else { $W[] = 'parent_id = ?'; $P[] = (int)$r['parent']; } }
             $pdo = db(); $T = table_name('taxonomy_terms'); $per = (int)$r['per_page']; $page = (int)$r['page'];
             $c = $pdo->prepare("SELECT COUNT(*) FROM $T WHERE " . implode(' AND ', $W)); $c->execute($P);
             $st = $pdo->prepare("SELECT * FROM $T WHERE " . implode(' AND ', $W) . " ORDER BY term_order, term LIMIT $per OFFSET " . (($page - 1) * $per)); $st->execute($P);
             return qp_rest_paginated(array_map('qp_rest_term_item', $st->fetchAll(PDO::FETCH_ASSOC)), (int)$c->fetchColumn(), $page, $per);
         }],
        ['methods' => 'POST', 'permission_callback' => $manage, 'scope' => 'terms:write', 'summary' => 'Create a term',
         'args' => ['taxonomy' => ['type' => 'string', 'required' => true, 'enum' => qp_rest_taxonomies()], 'name' => ['type' => 'string', 'required' => true, 'minLength' => 1, 'maxLength' => 100]] + $write,
         'callback' => function (QP_REST_Request $r) {
             $pdo = db(); $T = table_name('taxonomy_terms'); $tax = (string)$r['taxonomy'];
             $name = trim((string)$r['name']); $slug = qp_rest_slugify($r['slug'] !== null ? (string)$r['slug'] : $name);
             $x = $pdo->prepare("SELECT COUNT(*) FROM $T WHERE taxonomy = ? AND (term = ? OR slug = ?)"); $x->execute([$tax, $name, $slug]);
             if ($x->fetchColumn() > 0) return new QP_REST_Error('term_exists', 'That term or slug already exists.', 409);
             $parent = (int)($r['parent'] ?? 0);
             if ($parent) { $pp = $pdo->prepare("SELECT COUNT(*) FROM $T WHERE id = ? AND taxonomy = ?"); $pp->execute([$parent, $tax]); if (!$pp->fetchColumn()) return new QP_REST_Error('invalid_params', 'The parent does not exist.', 400, ['parent' => 'Unknown parent.']); }
             $mo = $pdo->prepare("SELECT COALESCE(MAX(term_order),0) FROM $T WHERE taxonomy = ?"); $mo->execute([$tax]);
             $pdo->prepare("INSERT INTO $T (taxonomy, term, slug, parent_id, term_order) VALUES (?, ?, ?, ?, ?)")->execute([$tax, $name, $slug, $parent ?: null, (int)$mo->fetchColumn() + 1]);
             $id = (int)$pdo->lastInsertId();
             if (function_exists('flush_rewrite_rules')) { try { flush_rewrite_rules(); } catch (Throwable $e) {} }
             if (function_exists('do_action')) do_action('create_term', $id, $tax);
             $row = $pdo->query("SELECT * FROM $T WHERE id = $id")->fetch(PDO::FETCH_ASSOC);
             return new QP_REST_Response(qp_rest_term_item($row), 201, ['Location' => (defined('SITE_URL') ? SITE_URL : '') . '/api/qlopy/v1/terms/' . $id]);
         }],
    ]);
    $idArg = ['id' => ['type' => 'integer', 'minimum' => 1, 'required' => true]];
    $load = function (int $id) { $st = db()->prepare('SELECT * FROM ' . table_name('taxonomy_terms') . ' WHERE id = ?'); $st->execute([$id]); return $st->fetch(PDO::FETCH_ASSOC) ?: null; };
    qp_register_rest_route($ns, '/terms/(?P<id>\d+)', [
        ['methods' => 'GET', 'permission_callback' => 'qp_rest_permit_public', 'scope' => 'terms:read', 'args' => $idArg, 'summary' => 'Get a term',
         'callback' => function (QP_REST_Request $r) use ($load) { $t = $load((int)$r['id']); return $t ? qp_rest_term_item($t) : new QP_REST_Error('not_found', 'No such term.', 404); }],
        ['methods' => ['PATCH', 'PUT'], 'permission_callback' => $manage, 'scope' => 'terms:write', 'args' => $idArg + $write, 'summary' => 'Change a term',
         'callback' => function (QP_REST_Request $r) use ($load) {
             $t = $load((int)$r['id']); if (!$t) return new QP_REST_Error('not_found', 'No such term.', 404);
             $pdo = db(); $T = table_name('taxonomy_terms');
             $name = $r['name'] !== null ? trim((string)$r['name']) : $t['term'];
             $slug = $r['slug'] !== null ? qp_rest_slugify((string)$r['slug']) : $t['slug'];
             $parent = $r['parent'] !== null ? (int)$r['parent'] : (int)$t['parent_id'];
             if ($parent === (int)$t['id']) return new QP_REST_Error('invalid_params', 'A term cannot be its own parent.', 400, ['parent' => 'Invalid parent.']);
             $x = $pdo->prepare("SELECT COUNT(*) FROM $T WHERE taxonomy = ? AND id <> ? AND (term = ? OR slug = ?)"); $x->execute([$t['taxonomy'], $t['id'], $name, $slug]);
             if ($x->fetchColumn() > 0) return new QP_REST_Error('term_exists', 'That term or slug already exists.', 409);
             $pdo->prepare("UPDATE $T SET term = ?, slug = ?, parent_id = ? WHERE id = ?")->execute([$name, $slug, $parent ?: null, $t['id']]);
             if (function_exists('flush_rewrite_rules')) { try { flush_rewrite_rules(); } catch (Throwable $e) {} }
             if (function_exists('do_action')) do_action('edit_term', (int)$t['id'], $t['taxonomy']);
             return qp_rest_term_item($load((int)$t['id']));
         }],
        ['methods' => 'DELETE', 'permission_callback' => $manage, 'scope' => 'terms:write', 'args' => $idArg, 'summary' => 'Delete a term',
         'callback' => function (QP_REST_Request $r) use ($load) {
             $t = $load((int)$r['id']); if (!$t) return new QP_REST_Error('not_found', 'No such term.', 404);
             $pdo = db();
             $pdo->prepare('DELETE FROM ' . table_name('post_terms') . ' WHERE term_id = ?')->execute([$t['id']]);
             $pdo->prepare('UPDATE ' . table_name('taxonomy_terms') . ' SET parent_id = NULL WHERE parent_id = ?')->execute([$t['id']]);
             $pdo->prepare('DELETE FROM ' . table_name('taxonomy_terms') . ' WHERE id = ?')->execute([$t['id']]);
             if (function_exists('flush_rewrite_rules')) { try { flush_rewrite_rules(); } catch (Throwable $e) {} }
             if (function_exists('do_action')) do_action('delete_term', (int)$t['id'], $t['taxonomy']);
             return ['id' => (int)$t['id'], 'deleted' => true];
         }],
    ]);
}

// ---------------------------------------------------------------- media (read) , users, options

function qp_rest_media_item(array $p): array {
    $meta = function_exists('qp_get_attachment_metadata') ? qp_get_attachment_metadata((int)$p['id']) : null;
    return ['id' => (int)$p['id'], 'title' => $p['title'], 'slug' => $p['slug'], 'url' => function_exists('qp_get_attachment_url') ? qp_get_attachment_url((int)$p['id']) : null,
            'mime_type' => is_array($meta) ? ($meta['file']['mime'] ?? $meta['mime'] ?? null) : null, 'author_id' => (int)$p['author_id'], 'created_at' => $p['created_at']];
}

function qp_rest_register_media_users_options(): void {
    $ns = 'qlopy/v1';
    $media = function (QP_REST_Request $r) { return $r->can('upload_files'); };
    qp_register_rest_route($ns, '/media', ['methods' => 'GET', 'permission_callback' => $media, 'scope' => 'media:read', 'summary' => 'List media library items',
        'args' => ['per_page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 20], 'page' => ['type' => 'integer', 'minimum' => 1, 'default' => 1], 'search' => ['type' => 'string', 'maxLength' => 100]],
        'callback' => function (QP_REST_Request $r) {
            $pdo = db(); $T = table_name('posts'); $per = (int)$r['per_page']; $page = (int)$r['page'];
            $W = "post_type = 'attachment'"; $P = [];
            if ($r['search']) { $W .= ' AND (title LIKE ? OR slug LIKE ?)'; $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], (string)$r['search']) . '%'; $P = [$like, $like]; }
            $c = $pdo->prepare("SELECT COUNT(*) FROM $T WHERE $W"); $c->execute($P);
            $st = $pdo->prepare("SELECT * FROM $T WHERE $W ORDER BY id DESC LIMIT $per OFFSET " . (($page - 1) * $per)); $st->execute($P);
            return qp_rest_paginated(array_map('qp_rest_media_item', $st->fetchAll(PDO::FETCH_ASSOC)), (int)$c->fetchColumn(), $page, $per);
        }]);
    // Upload: multipart/form-data with a `file` field, or JSON {filename, content_base64, title} for small files. Needs the right to upload files.
    qp_register_rest_route($ns, '/media', ['methods' => 'POST', 'permission_callback' => $media, 'scope' => 'media:write', 'multipart' => true, 'summary' => 'Upload a file to the media library (multipart "file", or JSON filename + content_base64)',
        'args' => ['filename' => ['type' => 'string', 'maxLength' => 200], 'content_base64' => ['type' => 'string'], 'title' => ['type' => 'string', 'maxLength' => 255]],
        'callback' => function (QP_REST_Request $r) {
            $max = max(1048576, (int)qp_rest_settings()['max_upload']);
            $tmp = null; $local = false;
            if (isset($r->files['file']) && is_array($r->files['file'])) {
                $f = $r->files['file'];
                if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) return new QP_REST_Error('upload_failed', 'The file did not upload (error ' . (int)($f['error'] ?? -1) . ').', 400);
                if ((int)$f['size'] > $max) return new QP_REST_Error('payload_too_large', 'The file is larger than the upload limit.', 413);
                $file = $f;
            } elseif ($r['content_base64'] !== null && $r['filename'] !== null) {
                $bin = base64_decode((string)$r['content_base64'], true);
                if ($bin === false || $bin === '') return new QP_REST_Error('invalid_params', 'content_base64 is not valid base64.', 400, ['content_base64' => 'Not valid base64.']);
                if (strlen($bin) > $max) return new QP_REST_Error('payload_too_large', 'The file is larger than the upload limit.', 413);
                $tmp = tempnam(sys_get_temp_dir(), 'qpu');
                file_put_contents($tmp, $bin);
                $file = ['name' => basename((string)$r['filename']), 'tmp_name' => $tmp, 'size' => strlen($bin), 'error' => 0];
                $local = true;
            } else {
                return new QP_REST_Error('invalid_params', 'Send a multipart "file" or JSON filename and content_base64.', 400, ['file' => 'Required.']);
            }
            try {
                [$ok, $res] = qp_handle_upload_extensive($file, $r->user_id, ['allow_local' => $local]);
            } finally {
                if ($tmp) @unlink($tmp);
            }
            if (!$ok) return new QP_REST_Error('upload_rejected', is_string($res) ? $res : 'The file was rejected.', 422);
            $id = (int)$res['post_id'];
            if ($r['title'] !== null && trim((string)$r['title']) !== '') db()->prepare('UPDATE ' . table_name('posts') . ' SET title = ? WHERE id = ?')->execute([trim((string)$r['title']), $id]);
            if (function_exists('do_action')) do_action('qp_media_uploaded', $res, $r->params);
            return new QP_REST_Response(qp_rest_media_item(qp_rest_fetch_post($id, 'attachment')), 201, ['Location' => (defined('SITE_URL') ? SITE_URL : '') . '/api/qlopy/v1/media/' . $id]);
        }]);
    qp_register_rest_route($ns, '/media/(?P<id>\d+)', [
        ['methods' => 'GET', 'permission_callback' => $media, 'scope' => 'media:read', 'summary' => 'Get a media item', 'args' => ['id' => ['type' => 'integer', 'minimum' => 1, 'required' => true]],
         'callback' => function (QP_REST_Request $r) { $p = qp_rest_fetch_post((int)$r['id'], 'attachment'); return $p ? qp_rest_media_item($p) : new QP_REST_Error('not_found', 'No such item.', 404); }],
        ['methods' => 'DELETE', 'permission_callback' => $media, 'scope' => 'media:write', 'summary' => 'Delete a media item and its files', 'args' => ['id' => ['type' => 'integer', 'minimum' => 1, 'required' => true]],
         'callback' => function (QP_REST_Request $r) {
             $p = qp_rest_fetch_post((int)$r['id'], 'attachment');
             if (!$p) return new QP_REST_Error('not_found', 'No such item.', 404);
             if ((int)$p['author_id'] !== $r->user_id && !$r->can('edit_others_posts')) return new QP_REST_Error('forbidden', 'You may only delete your own uploads.', 403);
             return qp_delete_attachment((int)$p['id']) ? ['id' => (int)$p['id'], 'deleted' => true] : new QP_REST_Error('server_error', 'The file could not be deleted.', 500);
         }],
    ]);

    $userItem = function (array $u, bool $private) {
        $role = get_user_meta((int)$u['id'], 'role');
        $dn = get_user_meta((int)$u['id'], 'display_name') ?: $u['username'];
        $o = ['id' => (int)$u['id'], 'username' => $u['username'], 'display_name' => $dn, 'role' => $role ?: null, 'created_at' => $u['created_at'] ?? null];
        if ($private) $o['email'] = $u['email'];
        return $o;   // never the password hash, tokens, keys or any other meta
    };
    qp_register_rest_route($ns, '/users/me', ['methods' => 'GET', 'permission_callback' => function (QP_REST_Request $r) { return $r->is_authenticated(); }, 'scope' => 'users:read', 'summary' => 'The signed-in user', 'no_store' => true,
        'callback' => function (QP_REST_Request $r) use ($userItem) {
            $u = qp_rest_user_row($r->user_id); if (!$u) return new QP_REST_Error('not_found', 'No such user.', 404);
            $caps = array_keys(array_filter(['manage_options' => 1, 'manage_posts' => 1, 'edit_posts' => 1, 'publish_posts' => 1, 'upload_files' => 1, 'manage_users' => 1], function ($_, $c) use ($r) { return $r->can($c); }, ARRAY_FILTER_USE_BOTH));
            return $userItem($u, true) + ['capabilities' => $caps, 'auth_scheme' => $r->scheme, 'scopes' => $r->scopes];
        }]);
    qp_register_rest_route($ns, '/users', ['methods' => 'GET', 'permission_callback' => function (QP_REST_Request $r) { return $r->can('manage_users'); }, 'scope' => 'users:read', 'summary' => 'List users',
        'args' => ['per_page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 20], 'page' => ['type' => 'integer', 'minimum' => 1, 'default' => 1], 'search' => ['type' => 'string', 'maxLength' => 100]],
        'callback' => function (QP_REST_Request $r) use ($userItem) {
            $pdo = db(); $T = table_name('users'); $per = (int)$r['per_page']; $page = (int)$r['page'];
            $W = '1'; $P = [];
            if ($r['search']) { $W = '(username LIKE ? OR email LIKE ?)'; $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], (string)$r['search']) . '%'; $P = [$like, $like]; }
            $c = $pdo->prepare("SELECT COUNT(*) FROM $T WHERE $W"); $c->execute($P);
            $st = $pdo->prepare("SELECT id, username, email, created_at FROM $T WHERE $W ORDER BY id LIMIT $per OFFSET " . (($page - 1) * $per)); $st->execute($P);
            return qp_rest_paginated(array_map(function ($u) use ($userItem) { return $userItem($u, true); }, $st->fetchAll(PDO::FETCH_ASSOC)), (int)$c->fetchColumn(), $page, $per);
        }]);
    qp_register_rest_route($ns, '/users/(?P<id>\d+)', ['methods' => 'GET', 'scope' => 'users:read', 'summary' => 'Get a user (yourself, or any user with the right to manage users)', 'args' => ['id' => ['type' => 'integer', 'minimum' => 1, 'required' => true]],
        'permission_callback' => function (QP_REST_Request $r) { return $r->is_authenticated() && ((int)$r['id'] === $r->user_id || $r->can('manage_users')); },
        'callback' => function (QP_REST_Request $r) use ($userItem) { $u = qp_rest_user_row((int)$r['id']); return $u ? $userItem($u, true) : new QP_REST_Error('not_found', 'No such user.', 404); }]);

    // ---- user writes. A role can only be given by someone who already holds every capability of that role (no escalation).
    $roleOk = function (QP_REST_Request $r, string $role): bool {
        $caps = get_role_capabilities($role);
        if (!isset($GLOBALS['qlopy_roles'][$role])) return false;
        foreach ($caps as $c) if (!$r->can($c)) return false;
        return true;
    };
    $roles = array_keys($GLOBALS['qlopy_roles'] ?? []);
    $userWrite = [
        'email' => ['type' => 'string', 'maxLength' => 100, 'validate_callback' => function ($v) { return filter_var($v, FILTER_VALIDATE_EMAIL) ? true : 'Not a valid email address.'; }],
        'display_name' => ['type' => 'string', 'maxLength' => 100],
        'password' => ['type' => 'string', 'minLength' => 10, 'maxLength' => 200],
        'role' => ['type' => 'string', 'enum' => $roles ?: ['subscriber']],
    ];
    qp_register_rest_route($ns, '/users', ['methods' => 'POST', 'permission_callback' => function (QP_REST_Request $r) { return $r->can('manage_users'); }, 'scope' => 'users:write', 'summary' => 'Create a user',
        'args' => ['username' => ['type' => 'string', 'required' => true, 'pattern' => '/^[A-Za-z0-9_.@-]{3,50}$/'], 'email' => ['required' => true] + $userWrite['email'], 'password' => ['required' => true] + $userWrite['password'],
                   'role' => ['default' => 'subscriber'] + $userWrite['role'], 'display_name' => $userWrite['display_name']],
        'callback' => function (QP_REST_Request $r) use ($roleOk, $userItem) {
            if (!$roleOk($r, (string)$r['role'])) return new QP_REST_Error('forbidden', 'You cannot give a role that has more rights than you have.', 403);
            $pdo = db(); $T = table_name('users');
            $x = $pdo->prepare("SELECT COUNT(*) FROM $T WHERE username = ? OR email = ?"); $x->execute([(string)$r['username'], (string)$r['email']]);
            if ($x->fetchColumn() > 0) return new QP_REST_Error('user_exists', 'That username or email is already in use.', 409);
            if (!register_user((string)$r['username'], (string)$r['password'], (string)$r['email'], (string)$r['role'])) return new QP_REST_Error('server_error', 'The user could not be created.', 500);
            $id = qp_rest_user_id_by_login((string)$r['username']);
            if ($r['display_name'] !== null && trim((string)$r['display_name']) !== '') update_user_meta($id, 'display_name', trim((string)$r['display_name']));
            return new QP_REST_Response($userItem(qp_rest_user_row($id), true), 201, ['Location' => (defined('SITE_URL') ? SITE_URL : '') . '/api/qlopy/v1/users/' . $id]);
        }]);
    qp_register_rest_route($ns, '/users/(?P<id>\d+)', [
        ['methods' => ['PATCH', 'PUT'], 'scope' => 'users:write', 'summary' => 'Change a user (yourself: email, display name, password with current_password; administrators: also role)',
         'permission_callback' => function (QP_REST_Request $r) { return $r->is_authenticated() && ((int)$r['id'] === $r->user_id || $r->can('manage_users')); },
         'args' => ['id' => ['type' => 'integer', 'minimum' => 1, 'required' => true], 'current_password' => ['type' => 'string', 'maxLength' => 200]] + $userWrite,
         'callback' => function (QP_REST_Request $r) use ($roleOk, $userItem) {
             $id = (int)$r['id']; $self = $id === $r->user_id; $admin = $r->can('manage_users');
             $row = qp_rest_user_row($id, true);
             if (!$row) return new QP_REST_Error('not_found', 'No such user.', 404);
             $pdo = db(); $T = table_name('users');
             if ($r['email'] !== null) {
                 $x = $pdo->prepare("SELECT COUNT(*) FROM $T WHERE email = ? AND id <> ?"); $x->execute([(string)$r['email'], $id]);
                 if ($x->fetchColumn() > 0) return new QP_REST_Error('user_exists', 'That email is already in use.', 409);
             }
             if ($r['password'] !== null) {
                 // Changing your own password proves you know the current one, so a stolen token or key cannot lock the owner out.
                 if ($self && !password_verify((string)$r['current_password'], (string)$row['password_hash'])) return new QP_REST_Error('invalid_params', 'current_password is wrong.', 400, ['current_password' => 'Wrong password.']);
                 if (!$self && !$admin) return new QP_REST_Error('forbidden', 'Not allowed.', 403);
             }
             if ($r['role'] !== null) {
                 if (!$admin || $self) return new QP_REST_Error('forbidden', 'Only another user\'s role can be changed, and only by someone who may manage users.', 403);
                 if (!$roleOk($r, (string)$r['role'])) return new QP_REST_Error('forbidden', 'You cannot give a role that has more rights than you have.', 403);
             }
             if ($r['email'] !== null) $pdo->prepare("UPDATE $T SET email = ? WHERE id = ?")->execute([(string)$r['email'], $id]);
             if ($r['password'] !== null) $pdo->prepare("UPDATE $T SET password_hash = ? WHERE id = ?")->execute([password_hash((string)$r['password'], PASSWORD_DEFAULT), $id]);
             if ($r['display_name'] !== null) update_user_meta($id, 'display_name', trim((string)$r['display_name']));
             if ($r['role'] !== null) { update_user_meta($id, 'role', (string)$r['role']); assign_capabilities_to_user($id, get_role_capabilities((string)$r['role'])); }
             return $userItem(qp_rest_user_row($id), true);
         }],
        ['methods' => 'DELETE', 'scope' => 'users:write', 'summary' => 'Delete a user (not yourself)', 'args' => ['id' => ['type' => 'integer', 'minimum' => 1, 'required' => true]],
         'permission_callback' => function (QP_REST_Request $r) { return $r->can('manage_users'); },
         'callback' => function (QP_REST_Request $r) {
             $id = (int)$r['id'];
             if ($id === $r->user_id) return new QP_REST_Error('forbidden', 'You cannot delete your own account here.', 403);
             if (!qp_rest_user_row($id)) return new QP_REST_Error('not_found', 'No such user.', 404);
             return qp_delete_user($id) ? ['id' => $id, 'deleted' => true] : new QP_REST_Error('server_error', 'The user could not be deleted.', 500);
         }],
    ]);
    $allowed = function (): array {
        $a = ['site_name', 'site_tagline', 'date_format', 'time_format', 'timezone_string', 'week_starts_on'];
        $f = function_exists('apply_filters') ? apply_filters('qp_rest_options_allowlist', $a) : $a;
        return is_array($f) ? array_values(array_filter($f, 'is_string')) : $a;
    };
    qp_register_rest_route($ns, '/options', ['methods' => 'GET', 'permission_callback' => function (QP_REST_Request $r) { return $r->can('manage_options'); }, 'scope' => 'options:read', 'summary' => 'The allow-listed site options',
        'callback' => function (QP_REST_Request $r) use ($allowed) { $o = []; foreach ($allowed() as $n) $o[$n] = get_option_meta($n); return $o; }]);
    qp_register_rest_route($ns, '/options/(?P<name>[a-z0-9_]+)', [
        ['methods' => 'GET', 'permission_callback' => function (QP_REST_Request $r) { return $r->can('manage_options'); }, 'scope' => 'options:read', 'summary' => 'One allow-listed option', 'args' => ['name' => ['type' => 'string', 'required' => true]],
         'callback' => function (QP_REST_Request $r) use ($allowed) { return in_array($r['name'], $allowed(), true) ? ['name' => $r['name'], 'value' => get_option_meta((string)$r['name'])] : new QP_REST_Error('not_found', 'No such option.', 404); }],
        ['methods' => ['PUT', 'PATCH'], 'permission_callback' => function (QP_REST_Request $r) { return $r->can('manage_options'); }, 'scope' => 'options:write', 'summary' => 'Set one allow-listed option', 'args' => ['name' => ['type' => 'string', 'required' => true], 'value' => ['type' => 'string', 'required' => true, 'maxLength' => 500]],
         'callback' => function (QP_REST_Request $r) use ($allowed) {
             if (!in_array($r['name'], $allowed(), true)) return new QP_REST_Error('not_found', 'No such option.', 404);
             update_option_meta((string)$r['name'], (string)$r['value']);
             return ['name' => $r['name'], 'value' => get_option_meta((string)$r['name'])];
         }],
    ]);
}

// ---------------------------------------------------------------- discovery, OpenAPI, auth

function qp_rest_register_core_misc(): void {
    $ns = 'qlopy/v1';
    qp_register_rest_route($ns, '/', ['methods' => 'GET', 'permission_callback' => 'qp_rest_permit_public', 'scope' => 'none', 'summary' => 'Discovery document',
        'callback' => function (QP_REST_Request $r) {
            $s = qp_rest_settings();
            $namespaces = array_keys(qp_rest_routes_ref());
            return ['name' => function_exists('get_option_meta') ? (get_option_meta('site_name') ?: '') : '', 'url' => defined('SITE_URL') ? SITE_URL : '', 'api_version' => QP_REST_VERSION,
                    'namespaces' => $namespaces, 'openapi' => (defined('SITE_URL') ? SITE_URL : '') . '/api/qlopy/v1/openapi.json',
                    'authentication' => ['cookie_nonce' => true, 'api_key' => (bool)$s['keys'], 'application_password' => (bool)$s['app_passwords'], 'jwt' => (bool)$s['jwt'], 'oauth' => !empty($s['oauth']) && !empty($s['jwt']) ? ['authorization_endpoint' => (defined('SITE_URL') ? SITE_URL : '') . '/api/qlopy/oauth/authorize', 'token_endpoint' => (defined('SITE_URL') ? SITE_URL : '') . '/api/qlopy/v1/oauth/token', 'code_challenge_methods' => ['S256']] : false, 'token_endpoint' => (defined('SITE_URL') ? SITE_URL : '') . '/api/qlopy/v1/auth/token'],
                    'routes' => array_values(array_unique(array_map(function ($x) { return '/' . $x[0] . preg_replace('~\(\?P<([a-z_]+)>[^)]*\)~i', '{$1}', $x[1]); }, qp_rest_all_routes())))];
        }]);
    qp_register_rest_route($ns, '/openapi.json', ['methods' => 'GET', 'permission_callback' => 'qp_rest_permit_public', 'scope' => 'none', 'summary' => 'OpenAPI 3 description of every registered route',
        'callback' => function (QP_REST_Request $r) { return qp_rest_openapi(); }]);

    // A fresh nonce for cookie sessions (the admin and storefront JavaScript send it as X-QP-Nonce on writes).
    qp_register_rest_route($ns, '/auth/nonce', ['methods' => 'GET', 'permission_callback' => function (QP_REST_Request $r) { return $r->scheme === 'cookie'; }, 'scope' => 'none', 'summary' => 'A nonce for cookie sessions', 'no_store' => true,
        'callback' => function (QP_REST_Request $r) { return ['nonce' => qp_create_nonce('qp_rest'), 'expires_in' => 7200]; }]);

    qp_register_rest_route($ns, '/auth/token', ['methods' => 'POST', 'permission_callback' => 'qp_rest_permit_public', 'scope' => 'none', 'no_store' => true, 'summary' => 'Exchange a login or application password for a token pair, or a refresh token for a new pair',
        'args' => ['grant_type' => ['type' => 'string', 'required' => true, 'enum' => ['password', 'refresh_token']], 'username' => ['type' => 'string', 'maxLength' => 100], 'password' => ['type' => 'string', 'maxLength' => 200],
                   'refresh_token' => ['type' => 'string', 'maxLength' => 200], 'scope' => ['type' => 'string', 'maxLength' => 300]],
        'callback' => function (QP_REST_Request $r) {
            if ($e = qp_rest_https_required_error()) return $e;
            if (!qp_rest_settings()['jwt']) return new QP_REST_Error('api_disabled', 'Token sign-in is not turned on for this site.', 403);
            $ip = qp_rest_client_ip(); $h = [];
            if (!qp_rest_is_loopback() && ($e = qp_rest_rate_limit('token:' . $ip, 20, $h))) return $e;
            if ($e = qp_rest_auth_locked('ip:' . $ip)) return $e;
            $bad = new QP_REST_Error('invalid_grant', 'The credentials are not valid.', 400, [], ['Cache-Control' => 'no-store']);
            if ($r['grant_type'] === 'refresh_token') {
                $pair = qp_jwt_refresh((string)$r['refresh_token']);
                if (!$pair) { qp_rest_auth_failed('ip:' . $ip); return $bad; }
                return new QP_REST_Response($pair, 200, ['Cache-Control' => 'no-store']);
            }
            $user = trim((string)$r['username']);
            if ($user === '' || (string)$r['password'] === '') return new QP_REST_Error('invalid_params', 'username and password are required.', 400, ['username' => 'Required.', 'password' => 'Required.']);
            if ($e = qp_rest_auth_locked('user:' . strtolower($user))) return $e;
            $uid = qp_rest_password_login($user, (string)$r['password']);
            if (!$uid) return $bad;
            $scopes = array_values(array_filter(preg_split('/\s+/', trim((string)($r['scope'] ?: 'read write'))) ?: [], 'qp_rest_valid_scope'));
            if (!$scopes) $scopes = ['read'];
            return new QP_REST_Response(qp_jwt_start($uid, $scopes), 200, ['Cache-Control' => 'no-store']);
        }]);
    qp_register_rest_route($ns, '/auth/logout', ['methods' => 'POST', 'permission_callback' => function (QP_REST_Request $r) { return $r->scheme === 'bearer' && $r->family; }, 'scope' => 'none', 'no_store' => true, 'summary' => 'End the token session this access token belongs to',
        'callback' => function (QP_REST_Request $r) { qp_jwt_revoke_family($r->user_id, (string)$r->family); return ['revoked' => true]; }]);
}

function qp_rest_register_core_routes(): void {
    require_once __DIR__ . '/rest-oauth.php';
    qp_oauth_register_routes();
    qp_rest_register_core_misc();
    qp_rest_register_posts_routes();
    qp_rest_register_terms_routes();
    qp_rest_register_media_users_options();
}

qp_rest_register_core_routes();
unset($GLOBALS['qp_rest_core_loading']);
