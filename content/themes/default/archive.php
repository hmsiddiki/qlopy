<?php
$page_title = 'Archive';

get_header();

global $pdo;

// Get route parameters
$post_type = $_GET['post_type'] ?? 'post';
$taxonomy  = $_GET['taxonomy'] ?? null;
$term_slug = $_GET['term'] ?? null;

// Build query based on whether we're filtering by taxonomy term
if ($taxonomy && $term_slug) {
    // Fetch posts for a specific taxonomy term
    $sql = "SELECT p.id, p.title, p.slug, p.created_at FROM " . table_name('posts') . " p
            INNER JOIN " . table_name('post_terms') . " pt ON pt.post_id = p.id
            INNER JOIN " . table_name('taxonomy_terms') . " tt ON tt.id = pt.term_id
            WHERE p.post_type = ? AND p.status = 'published'
              AND tt.taxonomy = ? AND tt.slug = ?
            ORDER BY p.created_at DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$post_type, $taxonomy, $term_slug]);
    
    // Fetch term name for display
    $stmt_term = $pdo->prepare("SELECT term FROM " . table_name('taxonomy_terms') . " WHERE taxonomy = ? AND slug = ?");
    $stmt_term->execute([$taxonomy, $term_slug]);
    $term_name = $stmt_term->fetchColumn() ?: ucfirst($term_slug);
} else {
    // Plain post type archive
    $sql = "SELECT id, title, slug, created_at FROM " . table_name('posts') . " 
            WHERE post_type = ? AND status = 'published'
            ORDER BY created_at DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$post_type]);
}

$posts = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get post type label
$post_types = get_post_types();
$post_type_label = $post_types[$post_type]['label'] ?? ucfirst($post_type);

?>

<div class="container mt-4">
<h1><?php 
    if ($taxonomy && $term_slug) {
        echo htmlspecialchars($post_type_label) . ' - ' . htmlspecialchars(ucfirst($taxonomy)) . ': ' . htmlspecialchars($term_name);
    } else {
        echo htmlspecialchars($post_type_label) . ' Archive';
    }
?></h1>

<?php if (empty($posts)): ?>
    <p>No posts found in this <?= $taxonomy ? 'category' : 'archive' ?>.</p>
<?php else: ?>
<div class="row">
<?php foreach ($posts as $post): ?>
<div class="col-md-4 mb-3">
  <div class="card h-100">
    <div class="card-body d-flex flex-column">
      <h5 class="card-title"><?= htmlspecialchars($post['title']) ?></h5>
      <?php
        $detailUrl = '#';
        if (function_exists('permalink_for_post')) {
            $detailUrl = permalink_for_post($post['slug'], $post_type);
        } else {
            $detailUrl = SITE_URL . '/' . ($post_type === 'page' ? '' : $post_type . '/') . $post['slug'];
        }
      ?>
      <a href="<?= htmlspecialchars($detailUrl) ?>" class="btn btn-primary mt-auto">Read More</a>
    </div>
  </div>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>
</div>

<?php get_footer(); ?>
