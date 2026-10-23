<?php
if (!defined('QLOPY_INIT')) { http_response_code(403); exit; }
// includes/widgets-core.php
// The widget types core ships (C6, part A). Loaded the first time one of them is placed or listed; see qp_widget_type().
// Each widget escapes its own output and sanitises its own settings (through the fields schema in includes/widgets.php).

/** Print the title block of a widget when the title is not empty. */
function qp_widget_title(array $args, array $s): void {
    $t = trim((string)($s['title'] ?? ''));
    if ($t !== '') echo $args['before_title'] . htmlspecialchars($t) . $args['after_title'];
}

function qp_widget_post_url(array $p): string {
    $slug = (string)($p['slug'] ?? '');
    $pt = (string)($p['post_type'] ?? 'post');
    if ($pt === 'page' && function_exists('permalink_for_page')) return permalink_for_page($slug);
    return function_exists('permalink_for_post') ? permalink_for_post($slug, $pt) : '#';
}

function qp_widget_post_type_options(): array {
    $o = [];
    foreach (function_exists('get_post_types') ? get_post_types() : ['post' => ['label' => 'Posts']] as $k => $pt) {
        if ($k === 'attachment' || !($pt['public'] ?? true)) continue;
        $o[$k] = $pt['label'] ?? ucfirst($k);
    }
    return $o ?: ['post' => 'Posts'];
}

class QP_Widget_Text extends QP_Widget {
    public function __construct() {
        parent::__construct('text', 'Text / HTML', ['description' => 'Free text or HTML.', 'fields' => [
            ['id' => 'title', 'type' => 'text', 'label' => 'Title'],
            ['id' => 'content', 'type' => 'html', 'label' => 'Content', 'rows' => 7, 'help' => 'HTML is allowed for users who can manage options; others edit plain text.'],
            ['id' => 'autop', 'type' => 'checkbox', 'label' => 'Turn blank lines into paragraphs', 'default' => true],
        ]]);
    }
    public function form(array $settings, string $prefix = 'f'): void {
        // Users who may not write HTML still edit the text: show it as a plain textarea and strip tags on save.
        $fields = $this->fields;
        if (!current_user_can('manage_options')) $fields[1]['type'] = 'textarea';
        qp_widget_fields_render($fields, $settings, $prefix);
    }
    public function update(array $new, array $old = []): array {
        if (current_user_can('manage_options')) return qp_widget_fields_sanitize($this->fields, $new, $old);
        $fields = $this->fields; $fields[1]['type'] = 'textarea';
        return qp_widget_fields_sanitize($fields, $new, $old);
    }
    public function widget(array $args, array $s): void {
        $c = (string)($s['content'] ?? '');
        if (trim($c) === '' && trim((string)($s['title'] ?? '')) === '') return;
        echo $args['before_widget']; qp_widget_title($args, $s);
        if (!empty($s['autop'])) {
            $parts = preg_split("/\n\s*\n/", trim($c));
            $c = implode("\n", array_map(function ($p) { return '<p>' . $p . '</p>'; }, $parts));
        }
        echo '<div class="textwidget">' . $c . '</div>';   // stored HTML: only a user who may manage options could save it
        echo $args['after_widget'];
    }
}

class QP_Widget_Recent_Posts extends QP_Widget {
    public function __construct() {
        parent::__construct('recent-posts', 'Recent posts', ['description' => 'The newest published posts.', 'fields' => [
            ['id' => 'title', 'type' => 'text', 'label' => 'Title', 'default' => 'Recent posts'],
            ['id' => 'post_type', 'type' => 'select', 'label' => 'Content type', 'default' => 'post', 'options' => 'qp_widget_post_type_options'],
            ['id' => 'number', 'type' => 'number', 'label' => 'How many', 'default' => 5, 'min' => 1, 'max' => 30],
            ['id' => 'show_date', 'type' => 'checkbox', 'label' => 'Show the date', 'default' => false],
        ], 'cache' => 300]);
    }
    public function widget(array $args, array $s): void {
        if (!function_exists('qp_get_posts')) return;
        $posts = qp_get_posts(['post_type' => (string)$s['post_type'], 'posts_per_page' => max(1, min(30, (int)$s['number'])), 'no_found_rows' => true, 'orderby' => 'created_at', 'order' => 'DESC']);
        if (!$posts) return;
        echo $args['before_widget']; qp_widget_title($args, $s); echo '<ul>';
        foreach ($posts as $p) {
            echo '<li><a href="' . htmlspecialchars(qp_widget_post_url($p), ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars((string)($p['title'] ?? '')) . '</a>';
            $when = $p['published_at'] ?? $p['created_at'] ?? '';
            if (!empty($s['show_date']) && $when) echo ' <span class="post-date">' . htmlspecialchars(date('M j, Y', strtotime((string)$when))) . '</span>';
            echo '</li>';
        }
        echo '</ul>' . $args['after_widget'];
    }
}

class QP_Widget_Terms extends QP_Widget {
    public function __construct() {
        parent::__construct('terms', 'Terms (categories, tags)', ['description' => 'A list of terms from any taxonomy.', 'fields' => [
            ['id' => 'title', 'type' => 'text', 'label' => 'Title', 'default' => 'Categories'],
            ['id' => 'taxonomy', 'type' => 'select', 'label' => 'Taxonomy', 'default' => 'category', 'options' => function () {
                $o = [];
                foreach (function_exists('get_taxonomies') ? get_taxonomies() : [] as $k => $t) $o[$k] = is_array($t) ? ($t['label'] ?? ucfirst($k)) : (string)$t;
                return $o ?: ['category' => 'Categories'];
            }],
            ['id' => 'limit', 'type' => 'number', 'label' => 'How many', 'default' => 15, 'min' => 1, 'max' => 100],
            ['id' => 'show_count', 'type' => 'checkbox', 'label' => 'Show post counts', 'default' => false],
        ], 'cache' => 300]);
    }
    public function widget(array $args, array $s): void {
        if (!function_exists('get_terms')) return;
        $tax = (string)$s['taxonomy'];
        $terms = get_terms(['taxonomy' => $tax, 'limit' => max(1, min(100, (int)$s['limit'])), 'orderby' => 'term']);
        if (!$terms || !is_array($terms)) return;
        echo $args['before_widget']; qp_widget_title($args, $s); echo '<ul>';
        foreach ($terms as $t) {
            if (!is_array($t)) continue;
            $url = function_exists('permalink_for_term') ? permalink_for_term($tax, (string)($t['slug'] ?? '')) : '#';
            echo '<li><a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars((string)($t['term'] ?? '')) . '</a>';
            if (!empty($s['show_count']) && function_exists('get_term_post_count')) echo ' <span class="count">(' . (int)get_term_post_count($t['slug'] ?? '', $tax) . ')</span>';
            echo '</li>';
        }
        echo '</ul>' . $args['after_widget'];
    }
}

class QP_Widget_Search extends QP_Widget {
    public function __construct() {
        parent::__construct('search', 'Search', ['description' => 'A search form.', 'fields' => [
            ['id' => 'title', 'type' => 'text', 'label' => 'Title'],
            ['id' => 'placeholder', 'type' => 'text', 'label' => 'Placeholder', 'default' => 'Search…'],
        ]]);
    }
    public function widget(array $args, array $s): void {
        $action = function_exists('get_home_url') ? get_home_url('/') : '/';
        echo $args['before_widget']; qp_widget_title($args, $s);
        echo '<form role="search" method="get" action="' . htmlspecialchars((string)$action, ENT_QUOTES, 'UTF-8') . '" class="widget-search"><input type="search" name="s" class="form-control" placeholder="' . htmlspecialchars((string)$s['placeholder'], ENT_QUOTES, 'UTF-8') . '" aria-label="Search"></form>';
        echo $args['after_widget'];
    }
}

class QP_Widget_Nav_Menu extends QP_Widget {
    public function __construct() {
        parent::__construct('nav-menu', 'Navigation menu', ['description' => 'One of your menus.', 'fields' => [
            ['id' => 'title', 'type' => 'text', 'label' => 'Title'],
            ['id' => 'menu', 'type' => 'select', 'label' => 'Menu', 'options' => function () {
                $o = [];
                if (function_exists('menus_load_all')) foreach (menus_load_all()['menus'] ?? [] as $slug => $m) $o[$slug] = $m['label'] ?? $slug;
                return $o;
            }],
        ], 'cache' => false]);   // the active item depends on the page being viewed
    }
    public function widget(array $args, array $s): void {
        $slug = (string)($s['menu'] ?? '');
        if ($slug === '' || !function_exists('render_menu')) return;
        $html = render_menu($slug, ['framework' => 'plain', 'container' => 'ul', 'container_class' => 'menu']);
        if (trim((string)$html) === '') return;
        echo $args['before_widget']; qp_widget_title($args, $s); echo $html . $args['after_widget'];
    }
}

class QP_Widget_Archives extends QP_Widget {
    public function __construct() {
        parent::__construct('archives', 'Archives', ['description' => 'Months that have posts.', 'fields' => [
            ['id' => 'title', 'type' => 'text', 'label' => 'Title', 'default' => 'Archives'],
            ['id' => 'post_type', 'type' => 'select', 'label' => 'Content type', 'default' => 'post', 'options' => 'qp_widget_post_type_options'],
            ['id' => 'limit', 'type' => 'number', 'label' => 'How many months', 'default' => 12, 'min' => 1, 'max' => 60],
            ['id' => 'show_count', 'type' => 'checkbox', 'label' => 'Show post counts', 'default' => false],
        ], 'cache' => 600]);
    }
    public function widget(array $args, array $s): void {
        if (!function_exists('db') || !function_exists('table_name')) return;
        try {
            $st = db()->prepare("SELECT YEAR(created_at) y, MONTH(created_at) m, COUNT(*) c FROM " . table_name('posts') . " WHERE post_type = ? AND status = 'published' AND visibility = 'public' GROUP BY y, m ORDER BY y DESC, m DESC LIMIT " . max(1, min(60, (int)$s['limit'])));
            $st->execute([(string)$s['post_type']]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) { return; }
        if (!$rows) return;
        $base = defined('SITE_URL') ? SITE_URL : '';
        echo $args['before_widget']; qp_widget_title($args, $s); echo '<ul>';
        foreach ($rows as $r) {
            $url = $base . '/index.php?' . http_build_query(['route' => 'archive', 'post_type' => (string)$s['post_type'], 'year' => (int)$r['y'], 'month' => (int)$r['m']]);
            echo '<li><a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars(date('F Y', mktime(0, 0, 0, (int)$r['m'], 1, (int)$r['y']))) . '</a>';
            if (!empty($s['show_count'])) echo ' <span class="count">(' . (int)$r['c'] . ')</span>';
            echo '</li>';
        }
        echo '</ul>' . $args['after_widget'];
    }
}

class QP_Widget_Recent_Comments extends QP_Widget {
    public function __construct() {
        parent::__construct('recent-comments', 'Recent comments', ['description' => 'The latest approved comments.', 'fields' => [
            ['id' => 'title', 'type' => 'text', 'label' => 'Title', 'default' => 'Recent comments'],
            ['id' => 'number', 'type' => 'number', 'label' => 'How many', 'default' => 5, 'min' => 1, 'max' => 20],
        ], 'cache' => 120]);
    }
    public function widget(array $args, array $s): void {
        if (!function_exists('db') || !function_exists('table_name')) return;
        try {
            $st = db()->prepare("SELECT c.author_name, c.object_id, p.title, p.slug, p.post_type FROM " . table_name('comments') . " c INNER JOIN " . table_name('posts') . " p ON p.id = c.object_id AND c.object_type = 'post' WHERE c.status = 'approved' AND p.status = 'published' AND p.visibility = 'public' ORDER BY c.created_at DESC, c.id DESC LIMIT " . max(1, min(20, (int)$s['number'])));
            $st->execute();
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) { return; }
        if (!$rows) return;
        echo $args['before_widget']; qp_widget_title($args, $s); echo '<ul>';
        foreach ($rows as $r) {
            $who = trim((string)$r['author_name']) !== '' ? $r['author_name'] : 'Someone';
            echo '<li>' . htmlspecialchars((string)$who) . ' on <a href="' . htmlspecialchars(qp_widget_post_url($r), ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars((string)$r['title']) . '</a></li>';
        }
        echo '</ul>' . $args['after_widget'];
    }
}
