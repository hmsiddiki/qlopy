<?php
if (!defined('QLOPY_INIT')) { http_response_code(403); exit; }
/**
 * Term helper utilities for advcms.
 *
 * Provides functions used by the query layer, such as resolving term slugs
 * and computing descendant term IDs for hierarchical taxonomies.
 */

require_once __DIR__ . '/../db.php';

function get_term_by_slug(string $taxonomy, string $slug) {
    $pdo = db();
    $stmt = $pdo->prepare('SELECT * FROM ' . table_name('taxonomy_terms') . ' WHERE taxonomy = ? AND slug = ? LIMIT 1');
    $stmt->execute([$taxonomy, $slug]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}
function get_term_by_id(string $taxonomy, string $id) {
    $pdo = db();
    $stmt = $pdo->prepare('SELECT * FROM ' . table_name('taxonomy_terms') . ' WHERE taxonomy = ? AND id = ? LIMIT 1');
    $stmt->execute([$taxonomy, $id]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function get_term_by_name(string $taxonomy, string $name) {
    $pdo = db();
    $stmt = $pdo->prepare('SELECT * FROM ' . table_name('taxonomy_terms') . ' WHERE taxonomy = ? AND term = ? LIMIT 1');
    $stmt->execute([$taxonomy, $name]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * Return integer array of descendant term IDs for a hierarchical taxonomy.
 * Performs a simple iterative walk to collect children.
 */
function get_term_descendants(int $term_id, string $taxonomy): array {
    $pdo = db();
    $all = [];
    $queue = [$term_id];
    while (!empty($queue)) {
        $parent = array_shift($queue);
        $stmt = $pdo->prepare('SELECT id FROM ' . table_name('taxonomy_terms') . ' WHERE taxonomy = ? AND parent_id = ?');
        $stmt->execute([$taxonomy, $parent]);
        $children = $stmt->fetchAll(PDO::FETCH_COLUMN);
        foreach ($children as $c) {
            $cid = (int)$c;
            if (!in_array($cid, $all, true)) {
                $all[] = $cid;
                $queue[] = $cid;
            }
        }
    }
    return $all;
}

?>
