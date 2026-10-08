<?php  get_header(); ?>

<h1>Latest Posts <?php if(is_logged_in()){  ?> hoos <?php } ?></h1>
<ul class="list-group mb-4">
<?php foreach ($posts as $post): ?>
<li class="list-group-item">
  <?php $detailUrl = function_exists('permalink_for_post') ? permalink_for_post($post['slug'], 'post') : (SITE_URL . '/post/' . $post['slug']); ?>
  <a href="<?= htmlspecialchars($detailUrl) ?>"><?= htmlspecialchars($post['title']) ?></a>
</li>
<?php endforeach; ?>
</ul>

<?php get_footer(); ?>

