<?php
if (!defined('QLOPY_INIT')) { http_response_code(403); exit; }
$current = $_SERVER['REQUEST_URI'];
$current_slug = basename(parse_url($current, PHP_URL_PATH));
$current_slug = strtok($current_slug, '?'); // Remove query parameters

if (!function_exists('get_post_types')) {
  // Ensure core is loaded for standalone includes
  @require_once __DIR__ . '/../../auth.php';
}
$post_types = function_exists('get_post_types') ? get_post_types() : [];

// Helper to render an icon value. Accepts:
// - raw HTML (starting with '<') e.g. '<i class="fas fa-..."></i>'
// - image url or relative path (contains '/' or ends with image extension)
// - classname(s) for an <i> element (e.g. 'fas fa-folder')
function render_nav_icon($icon, $default_class = 'qp-cog-alt', $menu_class = '') {
  $icon = trim((string)($icon ?? ''));
  if ($icon === '') {
    return '<i class="menu-icon ' . htmlspecialchars($default_class) . ' ' . htmlspecialchars($menu_class) . '"></i>';
  }
  if (strpos($icon, '<') === 0) return $icon; // assume caller-provided HTML
  // Image heuristics: absolute url or begins with / or looks like image filename
  if (preg_match('#^(https?:)?//#i', $icon) || strpos($icon, '/') === 0 || preg_match('#\.(png|jpe?g|gif|svg)$#i', $icon)) {
    return '<img src="' . htmlspecialchars($icon) . '" alt="" class="menu-icon ' . htmlspecialchars($menu_class) . '"/>';
  }
  // Otherwise treat as class list
  return '<i class="menu-icon ' . htmlspecialchars($icon) . ' ' . htmlspecialchars($menu_class) . '"></i>';
}

function is_menu_active($slug) {
    // 1) Check query parameter (for callback-based menus like index.php?page=slug)
    $current_page = $_GET['page'] ?? null;
    if ($current_page !== null) {
        if ($slug === 'dashboard' && $current_page === 'dashboard') {
            return true;
        }
        return $slug === $current_page;
    }

    // 2) Fallback: check current script filename (for direct PHP file menus like plugins.php)
    $current_script = basename(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH)); // e.g. "plugins.php"
    $current_base   = pathinfo($current_script, PATHINFO_FILENAME);              // e.g. "plugins"

    // If slug matches file base name, consider it active
    if ($current_base === $slug) {
        return true;
    }

    // Dashboard special case when directly on index.php without page=
    if ($slug === 'dashboard' && ($current_script === 'index.php' || $current_script === '')) {
        return true;
    }

    return false;
}

function is_post_type_active($post_type_key, $current_url) {
    // Check if current URL is for this post type by post_type query param
    if (strpos(strtolower($current_url), 'post_type=' . strtolower($post_type_key)) !== false) {
        return true;
    }

    // Parse current taxonomy query param
    $query_params = [];
    parse_str(parse_url($current_url, PHP_URL_QUERY) ?: '', $query_params);

    if (!empty($query_params['taxonomy'])) {
        $current_tax = $query_params['taxonomy'];

        // Get taxonomies associated with post type
        $taxonomies = get_taxonomies_for_post_type($post_type_key);
        if (isset($taxonomies[$current_tax])) {
            return true;
        }
    }

    return false;
}


global $admin_menus;
$admin_menus = is_array($admin_menus ?? null) ? $admin_menus : [];
$post_act = $_GET['action'] ?? 'list';
$cr_post_type = $_GET['post_type'] ?? '';
$cr_post_act = $cr_post_type.'_'. $post_act;
$tax_act = $_GET['action'] ?? 'list';
$cr_tax_type = $_GET['taxonomy'] ?? '';
$cr_tax_act = $cr_tax_type.'_'. $tax_act;
$config = get_config();
$admin_url = $config['site_url'] . ($config['admin_path'] ?? '/admin/');
 $site_name = function_exists('get_option_meta') ? get_option_meta('site_name') : null; 
?>
<div class="app-header fixed-top">
<nav class="navbar navbar-light main_navs_color ">
  <div class="d-flex align-self-center align-items-center">
  <a id="sidebarToggleBtn" class="">
    <svg width="25" height="25" xmlns="http://www.w3.org/2000/svg" xml:space="preserve" viewBox="10 0 100 110"><path fill="currentColor" d="M13.5 21.5v8h76v-8zm48 24h-48v8h48zm-48 32h62v-8h-62z"></path></svg>
  </a>
  <a href="index.php?page=dashboard" class="qp-branding d-flex mr-4 align-self-center align-items-center"><i class="qp-qlopy coloradminlogo"></i> <span class="site_title ml-2"><?= htmlspecialchars($site_name ?? 'Qlopy Admin') ?></span></a>
  <a href="<?= htmlspecialchars($config['site_url'] ?? '/') ?>" target="_blank" class="d-flex align-self-center align-items-center btn btn-light btn-sm visit_site">Visit Site</a>
</div>
  <div class="ml-auto text-dark d-flex align-items-center">
    <div class="actionheadbtn useradminmenu dropdown">
      <div class="user">
			<span><?= htmlspecialchars(get_user_meta(get_logged_in_user()['id'], 'nicename')) ?></span>
			<p>@<?= htmlspecialchars(get_logged_in_user()['username']) ?></p>
		  </div>
			<img src="<?= qp_user_avatar_url(get_logged_in_user()['id'], 48) ?>" class="dropdown-toggle" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false" alt="some user image">
      <div class="admheadmenu dropdown-menu">
		<ul>
			<li><a href="<?php echo $admin_url ?>/users.php?action=edit&id=<?= urlencode(get_logged_in_user()['id']) ?>" style=""><i class="qp-user-3"></i>&nbsp;Profile</a></li>
			<li><a href="#" style=""><i class="qp-lifebuoy"></i>&nbsp;Help</a></li>
			<li><a href="<?php echo qp_logout_url() ?>"><i class=" qp-logout-1"></i>&nbsp;Sign Out</a></li>
		</ul>
	</div>
    </div>
  
    
  </div>
</nav>
<div class="app-header-separator"></div>
</div>




 <div id="admin-wrapper">
<div id="sidebar" class="pt-5 main_navs_color">
  <nav class="nav flex-column pt-2">
    <a href="index.php?page=dashboard" class=" nav-link <?=is_menu_active('dashboard') ? 'active' : ''?>">
        <i class="qp-gauge-1"></i> <div class="parent_menu_title">Dashboard</div>
    </a>
    <?php foreach ($post_types as $pt_key => $pt_args):
      // Skip post types that should not appear in admin menu
      if (isset($pt_args['has_admin_menu']) && $pt_args['has_admin_menu'] === false) continue;
        $taxes = get_taxonomies_for_post_type($pt_key);
        $post_is_active = is_post_type_active($pt_key, $current);
        

    ?>
     <?php $pt_icon_html = render_nav_icon($pt_args['menu_icon'] ?? $pt_args['icon'] ?? 'qp-folder', 'qp-folder'); ?>
     <a href="#submenu-<?=htmlspecialchars($pt_key)?>" 
       class="nav-link topmenu-<?=htmlspecialchars($pt_key)?> dropdown-toggle <?= $post_is_active ? 'active' : '' ?>" data-toggle="collapse" aria-expanded="<?= $post_is_active ? 'true' : 'false' ?>">
       <?= $pt_icon_html ?> <div class="parent_menu_title"><?=htmlspecialchars($pt_args['label'] ?? ucfirst($pt_key))?></div>
     </a>
    <div class="collapse submenu <?= $post_is_active ? 'show' : '' ?>" id="submenu-<?=htmlspecialchars($pt_key)?>">
      <a href="posts.php?post_type=<?=urlencode($pt_key)?>" class="nav-link <?= ($cr_post_act == $pt_key.'_list') ? 'active' : '' ?>">All <?=htmlspecialchars($pt_args['label'] ?? ucfirst($pt_key))?></a>
      <a href="posts.php?post_type=<?=urlencode($pt_key)?>&action=add" class="nav-link <?= ($cr_post_act == $pt_key.'_add') ? 'active' : '' ?>">Add New</a>
      <?php foreach ($taxes as $tx_key => $tx_args): if( !$tx_args['menu_visible']) break?>
        <a href="taxonomies.php?taxonomy=<?=urlencode($tx_key)?>" class="nav-link <?= ($cr_tax_act == $tx_key.'_list') ? 'active' : '' ?>">
            <?=htmlspecialchars($tx_args['label'] ?? ucfirst($tx_key))?>
        </a>
      <?php endforeach; ?>
    </div>
    <?php endforeach; ?>

    <?php  
function is_external_menu(array $m): bool {
    $normalize = function($p){ return $p ? str_replace('\\','/', strtolower($p)) : ''; };
    $plugin_markers = ['/content/plugins/', '/plugins/'];

    // 1) php_file or url pointing into plugins
    $php = $normalize($m['php_file'] ?? '');
    $url = $normalize($m['url'] ?? '');
    foreach ($plugin_markers as $marker) {
        if ($php !== '' && strpos($php, $marker) !== false) return true;
        if ($url !== '' && strpos($url, $marker) !== false) return true;
    }

    // 2) callback: use reflection to find source file
    if (!empty($m['callback'])) {
        try {
            $cb = $m['callback'];
            if (is_array($cb)) {
                $ref = new ReflectionMethod($cb[0], $cb[1]);
            } elseif ($cb instanceof Closure) {
                $ref = new ReflectionFunction($cb);
            } elseif (is_string($cb) && strpos($cb, '::') !== false) {
                list($cls, $meth) = explode('::', $cb, 2);
                $ref = new ReflectionMethod($cls, $meth);
            } elseif (is_string($cb)) {
                $ref = new ReflectionFunction($cb);
            } else {
                $ref = null;
            }
            if ($ref) {
                $file = $normalize($ref->getFileName() ?: '');
                foreach ($plugin_markers as $marker) {
                    if ($file !== '' && strpos($file, $marker) !== false) return true;
                }
            }
        } catch (Throwable $e) {
            // ignore reflection failures (internal functions, eval, etc.)
        }
    }

    return false;
}
      // Group menus by parent so we can render hierarchical (parent -> children)
      $menus_by_parent = [];

      if ( !current_user_can('manage_plugins')) {
        foreach ($admin_menus as $slug => $m) {
           if ($slug === 'plugins' || ($m['parent'] ?? null) === 'plugins' || basename($m['php_file'] ?? '') === 'plugins.php') {
            unset($admin_menus[$slug]);
          }
        }
      }
      if ( !current_user_can('manage_options')) {
        foreach ($admin_menus as $slug => $m) {
              if ($slug === 'permalinks' || ($m['parent'] ?? null) === 'permalinks' || basename($m['php_file'] ?? '') === 'permalinks.php') {
                  unset($admin_menus[$slug]);
              }
               if ($slug === 'general-settings' || ($m['parent'] ?? null) === 'general-settings' || basename($m['php_file'] ?? '') === 'general-settings.php') {
                 unset($admin_menus[$slug]);
              }
              if ($slug === 'updates' || ($m['parent'] ?? null) === 'updates' || basename($m['php_file'] ?? '') === 'updates.php') {
                 unset($admin_menus[$slug]);
              }
              if ($slug === 'cron' || ($m['parent'] ?? null) === 'cron' || basename($m['php_file'] ?? '') === 'cron.php') {
                 unset($admin_menus[$slug]);
              }
              if ($slug === 'mail' || ($m['parent'] ?? null) === 'mail' || basename($m['php_file'] ?? '') === 'mail.php') {
                 unset($admin_menus[$slug]);
              }
        }
      }
      if ( !current_user_can('manage_admin_pages')) {   //plugin or theme generated menus
        foreach ($admin_menus as $slug => $m) {
           if (is_external_menu($m)) {
            unset($admin_menus[$slug]);
          }
        }

      }
      if ( !current_user_can('manage_comments')) {
        foreach ($admin_menus as $slug => $m) {
          if ($slug === 'discussion' || ($m['parent'] ?? null) === 'discussion' || basename($m['php_file'] ?? '') === 'discussion.php') {
            unset($admin_menus[$slug]);
           }
           if ($slug === 'settings' || ($m['parent'] ?? null) === 'settings' || basename($m['php_file'] ?? '') === 'settings.php') {
            unset($admin_menus[$slug]);
           }
        }


      }
       if ( !current_user_can('moderate_comments')) {
        foreach ($admin_menus as $slug => $m) {
          if ($slug === 'comments' || ($m['parent'] ?? null) === 'comments' || basename($m['php_file'] ?? '') === 'comments.php') {
            unset($admin_menus[$slug]);
           }
        }

       }
      if ( !current_user_can('manage_themes')) {
        foreach ($admin_menus as $slug => $m) {
           if ($slug === 'themes' || ($m['parent'] ?? null) === 'themes' || basename($m['php_file'] ?? '') === 'themes.php') {
            unset($admin_menus[$slug]);
          }
        }
      }
      if ( !current_user_can('manage_users')) {
        foreach ($admin_menus as $slug => $m) {
           if ($slug === 'users' || ($m['parent'] ?? null) === 'users' || basename($m['php_file'] ?? '') === 'users.php') {
            unset($admin_menus[$slug]);
          }
        }
      }
      if ( !current_user_can('manage_menus')) {
        foreach ($admin_menus as $slug => $m) {
           if ($slug === 'menus' || ($m['parent'] ?? null) === 'menus' || basename($m['php_file'] ?? '') === 'menus.php') {
            unset($admin_menus[$slug]);
          }
          if ($slug === 'appearance' || ($m['parent'] ?? null) === 'appearance' || basename($m['php_file'] ?? '') === 'appearance.php') {
            unset($admin_menus[$slug]);
           }
        }
      }

      foreach ($admin_menus as $mslug => $m) {
          $p = $m['parent'] ?? null;
          
          if (!isset($menus_by_parent[$p])) $menus_by_parent[$p] = [];
          $menus_by_parent[$p][$mslug] = $m;
      }

      // Render top-level menus (parent === null)
      $top_menus = $menus_by_parent[null] ?? [];
      foreach ($top_menus as $slug => $menu_def):
          $children = $menus_by_parent[$slug] ?? [];
          if (!empty($children)):
              $child_active = false;
              foreach ($children as $cslug => $cmenu) { if (is_menu_active($cslug)) { $child_active = true; break; } }
    ?>
        <a href="#submenu-<?=htmlspecialchars($slug)?>" 
           class="nav-link dropdown-toggle <?= $child_active ? 'active' : '' ?>" data-toggle="collapse" aria-expanded="<?= $child_active ? 'true' : 'false' ?>">
           <?= render_nav_icon($menu_def['icon'] ?? null, 'fas fa-cog') ?> <div class="parent_menu_title"><?=htmlspecialchars($menu_def['title'])?></div>
        </a>
        <div class="collapse submenu <?= $child_active ? 'show' : '' ?>" id="submenu-<?=htmlspecialchars($slug)?>">
          <?php foreach ($children as $cslug => $cmenu): ?>
            <a href="<?=htmlspecialchars($cmenu['url'])?>" class="nav-link <?=is_menu_active($cslug) ? 'active' : ''?>">
                <?=htmlspecialchars($cmenu['title'])?>
            </a>
          <?php endforeach; ?>
        </div>
      <?php else: ?>
        <a href="<?=htmlspecialchars($menu_def['url'])?>" class="nav-link <?=is_menu_active($slug) ? 'active' : ''?>">
        <?= render_nav_icon($menu_def['icon'] ?? null, 'qp-cog-alt') ?> <div class="parent_menu_title"><?=htmlspecialchars($menu_def['title'])?></div>
      </a>
      <?php endif; endforeach; ?>
  </nav>
</div>

