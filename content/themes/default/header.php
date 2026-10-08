<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<?php $site_name = function_exists('get_option_meta') ? get_option_meta('site_name') : null; ?>
<title><?= htmlspecialchars($page_title ?? ($site_name ?: 'My Qlopy')) ?></title>
<meta name="description" content="<?= htmlspecialchars(strip_tags($page_description ?? ($site_name ?: 'My Qlopy'))) ?>">
<link rel="canonical" href="<?= SITE_URL . ($_SERVER['REQUEST_URI'] ?? '/') ?>">
<?php
print_styles();
print_header_scripts();
?>
</head>
<body>

<nav class="navbar navbar-expand-lg navbar-light bg-light mb-4">
                                            <a class="navbar-brand" href="<?= SITE_URL ?>"><?= htmlspecialchars($site_name ?: 'My Qlopy') ?></a>
  <button class="navbar-toggler" type="button" data-toggle="collapse" data-target="#navbarSupportedContent"
          aria-controls="navbarSupportedContent" aria-expanded="false" aria-label="Toggle navigation">
      <span class="navbar-toggler-icon"></span>
  </button>
  <div class="collapse navbar-collapse" id="navbarSupportedContent">
      <?php
          if (function_exists('render_menu_for_location')) {
          // Use Bootstrap 4 friendly menu rendering when available
          echo render_menu_for_location('primary', [
              'container_class' => 'navbar-nav mr-auto',
              'framework' => 'bs4',
              'container' => 'ul',
              'item_tag' => 'li',
              'submenu_container' => 'div',
              'submenu_class' => 'dropdown-menu',
              // Provide callbacks so anchors and LIs include bootstrap classes when walker is not used
              'item_class_callback' => function($item, $depth, $args) {
                  $cls = [];
                  if ($depth === 0) $cls[] = 'nav-item';
                  if (!empty($item['children'])) $cls[] = 'dropdown';
                  $cls[] = 'menu-item';
                  if (!empty($item['children'])) $cls[] = 'menu-item-has-children';
                  return implode(' ', $cls);
              },
              'link_class_callback' => function($item, $depth, $args) {
                  $classes = [];
                  if ($depth === 0) $classes[] = 'nav-link';
                  if (!empty($item['children'])) $classes[] = 'dropdown-toggle';
                  return implode(' ', $classes);
              },
              'active_class' => 'active',
              'add_active_to' => 'item',
              'propagate_active' => true,
          ]) ?: '<ul class="navbar-nav mr-auto"><li class="nav-item"><a class="nav-link" href="' . SITE_URL . '">Home</a></li></ul>';
      } else if (function_exists('render_menu')) {
          // Fallback to previous behavior if helper not available
          $all = menus_load_all();
          $slug = $all['locations_map']['primary'] ?? null;
          if ($slug) {
              echo render_menu($slug, [
                  'container_class' => 'navbar-nav mr-auto',
                  'framework' => 'bs4',
                  'submenu_container' => 'div',
                  'submenu_class' => 'dropdown-menu',
                  'propagate_active' => false,
              ]);
          } else {
              echo '<ul class="navbar-nav mr-auto"><li class="nav-item"><a class="nav-link" href="' . SITE_URL . '">Home</a></li></ul>';
          }
      } else {
          echo '<ul class="navbar-nav mr-auto"><li class="nav-item"><a class="nav-link" href="' . SITE_URL . '">Home</a></li></ul>';
      }
      ?>
  </div>
</nav>

<div class="container">
