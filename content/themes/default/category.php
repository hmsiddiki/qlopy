<?php get_header(); ?>

<h1><?= htmlspecialchars($page_title) ?></h1>
<?php if ($posts): ?>
<ul class="list-group mb-4">
    <?php foreach ($posts as $post): ?>
        <li class="list-group-item">
          <a href="<?= SITE_URL ?>/post/<?= htmlspecialchars($post['slug']) ?>">
            <?= htmlspecialchars($post['title']) ?>
          </a>
        </li>
    <?php endforeach; ?>
</ul>
<?php else: ?>
    <p>No posts found in this category.</p>
<?php endif; ?>

<?php get_footer(); ?>
