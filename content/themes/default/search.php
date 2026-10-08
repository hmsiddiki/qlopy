<?php get_header(); ?>

<h1>Search Results for "<?= htmlspecialchars($search_query) ?>"</h1>

<?php if ($posts): ?>
<ul class="list-group mb-4">
    <?php foreach ($posts as $post): ?>
        <li class="list-group-item">
           <a href="<?= SITE_URL ?>/post/<?= htmlspecialchars($post['slug']) ?>"><?= htmlspecialchars($post['title']) ?></a>
        </li>
    <?php endforeach; ?>
</ul>
<?php else: ?>
    <p>No posts matched your search.</p>
<?php endif; ?>

<?php get_footer(); ?>
