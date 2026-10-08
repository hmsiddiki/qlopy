<?php
/**
 * Template name: Contact Form
 *
 * Learn more: http://codex.wordpress.org/Template_Hierarchy
 *
 * @package WordPress
 * @subpackage srstechlayout
 * @since srstechlayout
 */
get_header();
?>

<?php
// Read routed query var `contact_ref` (registered in theme functions)
$ref = '';
if (function_exists('get_query_var')) {
	$ref = get_query_var('contact_ref', '');
}
?>

<main class="container mt-4">
	<h1>Contact</h1>
	<?php if ($ref !== ''): ?>
		<p>Reference: <?php echo htmlspecialchars($ref, ENT_QUOTES, 'UTF-8'); ?></p>
	<?php else: ?>
		<p>Please use the form below to contact us.</p>
	<?php endif; ?>

	<!-- Place your contact form or markup here -->
</main>

<?php get_footer(); ?>