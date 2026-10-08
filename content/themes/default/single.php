<?php get_header(); ?>

<article class="container mt-4 ddd">
<h1><?= htmlspecialchars($post['title']) ?></h1>
	<div><?php qp_the_content($post); ?></div>
</article>

<?php
// Show comments template (core API is loaded by front controller)
if (function_exists('comments_open') || function_exists('get_comments_number')) {
	if (comments_open() || get_comments_number()) {
		comments_template();
	}
}
get_footer();
?>
