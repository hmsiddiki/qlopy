
</div> <!-- container -->

<footer class="footer bg-light text-center py-3 mt-5">
  <?php $site_name = function_exists('get_option_meta') ? get_option_meta('site_name') : null; ?>
  <small>© <?= date('Y') ?> <?= htmlspecialchars($site_name ?: 'My Qlopy') ?></small>
</footer>
<?php
print_footer_scripts();
?>
</body>
</html>
