
</div> <!-- container -->

<?php if (function_exists('is_widget_area_active') && is_widget_area_active('footer')): ?>
<aside class="footer-widgets border-top pt-4 mt-5" aria-label="Footer"><div class="row"><?php dynamic_widget_area('footer'); ?></div></aside>
<?php endif; ?>
<footer class="footer bg-light text-center py-3 mt-5">
  <?php $site_name = function_exists('get_option_meta') ? get_option_meta('site_name') : null; ?>
  <small>© <?= date('Y') ?> <?= htmlspecialchars($site_name ?: 'My Qlopy') ?></small>
</footer>
<?php
print_footer_scripts();
?>
</body>
</html>
