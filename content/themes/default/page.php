<?php get_header(); ?>

<?php
$front_page_option = $front_page_option ?? null;
$front_post_id = isset($front_post_id) ? (int)$front_post_id : 0;
$post = $post ?? null;
$post_id = is_array($post) && isset($post['id']) ? (int)$post['id'] : 0;
$post_title = is_array($post) && isset($post['title']) ? (string)$post['title'] : '';
$post_content = is_array($post) && isset($post['content']) ? (string)$post['content'] : '';
$is_static_home = ($front_page_option === 'static') && ($front_post_id === $post_id) && $post_id > 0;
?>

<article class="container mt-4">
<?php if ($is_static_home): ?>
    <!-- No title on static homepage -->
<?php else: ?> 
    <?php if ($post_title !== ''): ?>
        <h1><?= htmlspecialchars($post_title) ?></h1>
    <?php endif; ?>
<?php endif; ?>
<div><?php qp_the_content($post); ?></div>
</article>

<?php get_footer(); ?>
