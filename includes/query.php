<?php
if (!defined('QLOPY_INIT')) { http_response_code(403); exit; }
/**
 * Lightweight QP_Query implementation inspired by WP_Query.
 * Provides basic loop helpers: have_posts(), the_post(), rewind_posts().
 * Supports tax_query with include_children expansion.
 */

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/term-helpers.php';

class QP_Query {
    public $query_vars = [];
    public $posts = [];
    protected $index = 0;
    protected $total = 0;

    public function __construct(array $args = []) {
        // Merge with global query vars populated by parse_request
        $defaults = [
            'post_type' => 'post',
            'status' => 'published',
            'paged' => 1,
            'posts_per_page' => 10,
            'post__in' => [],
            'post__not_in' => [],
            's' => null,
            'orderby' => 'created_at',
            'order' => 'DESC',
            // When true, skip running the COUNT(*) query (similar to WP_Query's no_found_rows)
            'no_found_rows' => false,
        ];
        $global_vars = $GLOBALS['qp_query_vars'] ?? [];
        $this->query_vars = array_merge($defaults, $global_vars, $args);
        $this->query_vars['paged'] = max(1, (int)($this->query_vars['paged'] ?? 1));
        $this->get_posts();
    }

    protected function build_where(array &$params): string {
        $where = [];
        if (!empty($this->query_vars['post_type'])) {
            $pt = $this->query_vars['post_type'];
            if (is_array($pt)) {
                $vals = array_values(array_filter($pt, 'strlen'));
                if (!empty($vals)) {
                    $placeholders = implode(',', array_fill(0, count($vals), '?'));
                    $where[] = 'p.post_type IN (' . $placeholders . ')';
                    foreach ($vals as $v) $params[] = $v;
                }
            } else {
                $where[] = 'p.post_type = ?'; $params[] = $pt;
            }
        }
        if (!empty($this->query_vars['status']) && $this->query_vars['status'] !== 'any') {
            $where[] = 'p.status = ?'; $params[] = $this->query_vars['status'];
        }
        if (!empty($this->query_vars['slug'])) {
            $where[] = 'p.slug = ?'; $params[] = $this->query_vars['slug'];
        }
        // support post__in / post__not_in similar to WP
        if (!empty($this->query_vars['post__in'])) {
            $raw_in = $this->query_vars['post__in'];
            if (!is_array($raw_in)) {
                if (is_string($raw_in)) $raw_in = preg_split('/\s*,\s*/', $raw_in);
                else $raw_in = (array)$raw_in;
            }
            $ids = array_values(array_filter(array_map('intval', $raw_in)));
            if (!empty($ids)) {
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $where[] = 'p.id IN (' . $placeholders . ')';
                foreach ($ids as $i) $params[] = $i;
            }
        }
        if (!empty($this->query_vars['post__not_in'])) {
            $raw_not = $this->query_vars['post__not_in'];
            if (!is_array($raw_not)) {
                if (is_string($raw_not)) $raw_not = preg_split('/\s*,\s*/', $raw_not);
                else $raw_not = (array)$raw_not;
            }
            $nids = array_values(array_filter(array_map('intval', $raw_not)));
            if (!empty($nids)) {
                $placeholders = implode(',', array_fill(0, count($nids), '?'));
                $where[] = 'p.id NOT IN (' . $placeholders . ')';
                foreach ($nids as $i) $params[] = $i;
            }
        }
        if (!empty($this->query_vars['p'])) {
            $where[] = 'p.id = ?'; $params[] = (int)$this->query_vars['p'];
        }
        if (!empty($this->query_vars['year'])) {
            $where[] = "YEAR(p.created_at) = ?"; $params[] = (int)$this->query_vars['year'];
        }
        if (!empty($this->query_vars['month'])) {
            $where[] = "MONTH(p.created_at) = ?"; $params[] = (int)$this->query_vars['month'];
        }
        if (!empty($this->query_vars['s'])) {
            $s = '%' . str_replace('%','\%',$this->query_vars['s']) . '%';
            $where[] = '(p.title LIKE ? OR p.content LIKE ?)'; $params[] = $s; $params[] = $s;
        }
        if (empty($where)) return '1';
        return implode(' AND ', $where);
    }

    /**
     * Build tax_query EXISTS/NOT EXISTS clauses and append to params.
     * Supports clauses of form: [ ['taxonomy'=>'cat','field'=>'slug','terms'=>['foo'],'operator'=>'IN','include_children'=>true], ... ]
     */
    protected function build_tax_clauses(array &$params): array {
        $clauses = [];
        if (empty($this->query_vars['tax_query']) || !is_array($this->query_vars['tax_query'])) return $clauses;
        $pdo = db();
        foreach ($this->query_vars['tax_query'] as $taxClause) {
            $taxonomy = $taxClause['taxonomy'] ?? '';
            if (!$taxonomy) continue;
            $field = $taxClause['field'] ?? 'term_id';
            $terms = (array)($taxClause['terms'] ?? []);
            $operator = strtoupper($taxClause['operator'] ?? 'IN');
            $include_children = isset($taxClause['include_children']) ? (bool)$taxClause['include_children'] : true;

            // Resolve terms to ids
            $term_ids = [];
            if ($field === 'term_id') {
                foreach ($terms as $t) { $term_ids[] = (int)$t; }
            } else {
                $col = $field === 'slug' ? 'slug' : 'term';
                $stmt = $pdo->prepare('SELECT id FROM ' . table_name('taxonomy_terms') . ' WHERE taxonomy = ? AND ' . $col . ' = ? LIMIT 1');
                foreach ($terms as $t) {
                    $stmt->execute([$taxonomy, (string)$t]);
                    $id = $stmt->fetchColumn();
                    if ($id) $term_ids[] = (int)$id;
                }
            }

            if (empty($term_ids)) {
                if ($operator === 'NOT IN') {
                    // nothing to exclude -> skip
                    continue;
                }
                // No matching term ids -> force empty match
                $clauses[] = '0';
                continue;
            }

            // include children
            if ($include_children) {
                $all = [];
                foreach ($term_ids as $tid) {
                    $desc = get_term_descendants($tid, $taxonomy);
                    if ($desc) $all = array_merge($all, $desc);
                    $all[] = $tid;
                }
                $term_ids = array_values(array_unique($all));
            }

            // Build IN list and params
            $placeholders = implode(',', array_fill(0, count($term_ids), '?'));
            $clauseType = ($operator === 'NOT IN') ? 'NOT EXISTS' : 'EXISTS';
            $clause = $clauseType . ' (SELECT 1 FROM ' . table_name('post_terms') . " pt INNER JOIN " . table_name('taxonomy_terms') . " tt ON tt.id = pt.term_id WHERE pt.post_id = p.id AND tt.taxonomy = ? AND tt.id " . (($operator === 'NOT IN') ? "NOT IN ($placeholders)" : "IN ($placeholders)") . ')';
            // push taxonomy + ids into params in the same order
            $params[] = $taxonomy;
            foreach ($term_ids as $id) $params[] = $id;
            $clauses[] = $clause;
        }
        return $clauses;
    }

    /**
     * Build meta_query EXISTS/NOT EXISTS clauses and append to params.
     * Supports clauses of form: [ ['key'=>'meta_key','value'=>'x'|'[a,b]','compare'=>'=|!=|IN|NOT IN|LIKE'], ... ]
     */
    protected function build_meta_clauses(array &$params): array {
        $clauses = [];
        if (empty($this->query_vars['meta_query']) || !is_array($this->query_vars['meta_query'])) {
            // support top-level shorthand meta_key/meta_value
            if (!empty($this->query_vars['meta_key'])) {
                $mk = $this->query_vars['meta_key'];
                $mv = $this->query_vars['meta_value'] ?? null;
                if ($mk !== '' && $mv !== null) {
                    $vals = is_array($mv) ? $mv : [$mv];
                    $placeholders = implode(',', array_fill(0, count($vals), '?'));
                    $clauses[] = 'EXISTS (SELECT 1 FROM ' . table_name('post_meta') . ' pm WHERE pm.post_id = p.id AND pm.meta_key = ? AND pm.meta_value IN (' . $placeholders . '))';
                    $params[] = $mk;
                    foreach ($vals as $v) $params[] = $v;
                }
            }
            return $clauses;
        }

        $pm_table = table_name('post_meta');
        foreach ($this->query_vars['meta_query'] as $m) {
            if (!is_array($m)) continue;
            $key = $m['key'] ?? ($m['meta_key'] ?? '');
            if ($key === '') continue;
            $value = $m['value'] ?? null;
            $compare = strtoupper(trim((string)($m['compare'] ?? '=')));

            if (in_array($compare, ['IN','NOT IN'], true)) {
                $vals = is_array($value) ? $value : [$value];
                $vals = array_values(array_filter($vals, function($x){ return $x !== null && $x !== ''; }));
                // Expand scalar values to also match their JSON-encoded equivalents
                $expanded = [];
                foreach ($vals as $v) {
                    $expanded[] = $v;
                    if (!is_array($v) && !is_object($v)) {
                        $json = json_encode($v);
                        if ($json !== false && $json !== $v) $expanded[] = $json;
                    }
                }
                // Deduplicate while preserving order
                $vals = array_values(array_unique($expanded));
                if (empty($vals)) {
                    if ($compare === 'NOT IN') continue; // nothing to exclude
                    $clauses[] = '0';
                    continue;
                }
                $ph = implode(',', array_fill(0, count($vals), '?'));
                $clType = ($compare === 'NOT IN') ? 'NOT EXISTS' : 'EXISTS';
                $clause = $clType . ' (SELECT 1 FROM ' . $pm_table . ' pm WHERE pm.post_id = p.id AND pm.meta_key = ? AND pm.meta_value ' . ($compare === 'NOT IN' ? 'IN (' . $ph . ')' : 'IN (' . $ph . ')') . ')';
                $params[] = $key;
                foreach ($vals as $v) $params[] = $v;
                $clauses[] = $clause;
                continue;
            }

            // handle equality / inequality / LIKE
            if ($compare === '!=' || $compare === '<>') {
                // Exclude posts where meta_value equals provided value or its JSON-encoded form
                $v1 = $value;
                $v2 = (!is_array($value) && !is_object($value)) ? json_encode($value) : null;
                if ($v2 !== null && $v2 !== $v1) {
                    $clauses[] = 'NOT EXISTS (SELECT 1 FROM ' . $pm_table . ' pm WHERE pm.post_id = p.id AND pm.meta_key = ? AND (pm.meta_value = ? OR pm.meta_value = ?))';
                    $params[] = $key; $params[] = $v1; $params[] = $v2;
                } else {
                    $clauses[] = 'NOT EXISTS (SELECT 1 FROM ' . $pm_table . ' pm WHERE pm.post_id = p.id AND pm.meta_key = ? AND pm.meta_value = ?)';
                    $params[] = $key; $params[] = $v1;
                }
                continue;
            }

            if ($compare === 'LIKE') {
                $clauses[] = 'EXISTS (SELECT 1 FROM ' . $pm_table . ' pm WHERE pm.post_id = p.id AND pm.meta_key = ? AND pm.meta_value LIKE ?)';
                $params[] = $key; $params[] = (string)$value;
                continue;
            }

            // default: equality
            // Default equality: match either raw value or JSON-encoded equivalent
            $v1 = $value;
            $v2 = (!is_array($value) && !is_object($value)) ? json_encode($value) : null;
            if ($v2 !== null && $v2 !== $v1) {
                $clauses[] = 'EXISTS (SELECT 1 FROM ' . $pm_table . ' pm WHERE pm.post_id = p.id AND pm.meta_key = ? AND (pm.meta_value = ? OR pm.meta_value = ?))';
                $params[] = $key; $params[] = $v1; $params[] = $v2;
            } else {
                $clauses[] = 'EXISTS (SELECT 1 FROM ' . $pm_table . ' pm WHERE pm.post_id = p.id AND pm.meta_key = ? AND pm.meta_value = ?)';
                $params[] = $key; $params[] = $v1;
            }
        }
        return $clauses;
    }

    protected function get_count(): int {
        $params = [];
        $where = $this->build_where($params);
        $tax_clauses = $this->build_tax_clauses($params);
        if (!empty($tax_clauses)) {
            $where .= ' AND (' . implode(' AND ', $tax_clauses) . ')';
        }
        // Meta query clauses
        $meta_clauses = $this->build_meta_clauses($params);
        if (!empty($meta_clauses)) {
            $where .= ' AND (' . implode(' AND ', $meta_clauses) . ')';
        }
        $pdo = db();
        $sql = 'SELECT COUNT(*) FROM ' . table_name('posts') . ' p WHERE ' . $where;
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    }

    public function get_posts(): array {
        $params = [];
        $where = $this->build_where($params);
        // Tax query clauses
        $tax_clauses = $this->build_tax_clauses($params);
        if (!empty($tax_clauses)) {
            $where .= ' AND (' . implode(' AND ', $tax_clauses) . ')';
        }
        // Meta query clauses
        $meta_clauses = $this->build_meta_clauses($params);
        if (!empty($meta_clauses)) {
            $where .= ' AND (' . implode(' AND ', $meta_clauses) . ')';
        }
        $limit = max(1, (int)$this->query_vars['posts_per_page']);
        $paged = max(1, (int)$this->query_vars['paged']);
        $offset = max(0, ($paged - 1) * $limit);
        $pdo = db();
        // Inject integers directly to avoid binding issues on some MySQL/MariaDB setups
        // Sanitize and build ORDER BY clause from allowed fields
        $allowed = [
            'created_at' => 'p.created_at',
            'published_at' => 'p.published_at',
            'title' => 'p.title',
            'id' => 'p.id',
            'updated_at' => 'p.updated_at',
            'slug' => 'p.slug',
        ];
        $orderby = $this->query_vars['orderby'] ?? 'created_at';
        if (!isset($allowed[$orderby])) $orderby = 'created_at';
        $order = strtoupper($this->query_vars['order'] ?? 'DESC');
        $order = ($order === 'ASC') ? 'ASC' : 'DESC';
        $order_sql = ' ORDER BY ' . $allowed[$orderby] . ' ' . $order;

        // Support fields => 'ids' to only return post IDs
        $fields = $this->query_vars['fields'] ?? null;
        if ($fields === 'ids') {
            $sql = 'SELECT p.id FROM ' . table_name('posts') . ' p WHERE ' . $where . $order_sql . ' LIMIT ' . intval($limit) . ' OFFSET ' . intval($offset);
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $this->posts = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
        } else {
            $sql = 'SELECT p.id, p.title, p.slug, p.content, p.post_type, p.status, p.author_id, p.created_at, p.published_at, p.updated_at FROM ' . table_name('posts') . ' p WHERE ' . $where . $order_sql . ' LIMIT ' . intval($limit) . ' OFFSET ' . intval($offset);
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $this->posts = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        // Allow filters to replace title/content per-language before exposing to themes
        if (($fields ?? null) !== 'ids' && function_exists('apply_filters')) {
            $lang = null;
            if (function_exists('ml_get_current_lang')) $lang = ml_get_current_lang();
            foreach ($this->posts as &$post) {
                $pid = isset($post['id']) ? (int)$post['id'] : null;
                if (isset($post['title'])) {
                    $post['title'] = apply_filters('ml_the_title', $post['title'], $pid, $lang);
                }
                if (isset($post['content'])) {
                    $post['content'] = apply_filters('ml_the_content', $post['content'], $pid, $lang);
                }
            }
            unset($post);
        }
        $this->index = 0;
        // Optionally skip COUNT(*) for performance when caller does not need total rows
        if (!empty($this->query_vars['no_found_rows'])) {
            $this->total = count($this->posts);
        } else {
            $this->total = $this->get_count();
        }
        return $this->posts;
    }

    public function have_posts(): bool {
        return isset($this->posts[$this->index]);
    }

    public function the_post(): ?array {
        if (!$this->have_posts()) return null;
        $post = $this->posts[$this->index];
        $this->index++;
        // Make available as global for simple theme compatibility
        $GLOBALS['post'] = $post;
        return $post;
    }

    public function rewind_posts(): void {
        $this->index = 0;
    }

    public function found_posts(): int {
        return $this->total;
    }

}

// Helper wrapper for quick usage
function qp_get_posts(array $args = []) {
    $q = new QP_Query($args);
    return $q->posts;
}

function qp_query(array $args = []) {
    return new QP_Query($args);
}

?>
