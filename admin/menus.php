<?php

require_once __DIR__ . '/admin_head.php';
require_once __DIR__ . '/../includes/menus.php';



/*
 Admin: Menu Output (for theme authors)

 This block documents how the CMS renders menus and gives two example
 usages for theme authors: a "plain" nested UL/LI structure and a
 Bootstrap 4 friendly output. Use `render_menu_for_location()` or
 `render_menu()` in theme templates. Pass callbacks to control classes
 for list items and anchor tags.

 Example: Plain nested UL/LI output

 echo render_menu_for_location('primary', [
   'framework' => 'plain',
   'container' => 'ul',
   'item_tag' => 'li',
   'submenu_container' => 'ul',
   'submenu_item_tag' => 'li',
   'submenu_class' => 'list-unstyled',
   'item_class_callback' => function($item,$depth,$args){
       // return classes for LI, e.g. 'menu-item' or 'menu-item menu-item-has-children'
   },
   'link_class_callback' => function($item,$depth,$args){
       // return classes for <a>, e.g. 'list-link'
   },
   'active_class' => 'active',
  'add_active_to' => 'item', // 'item'|'link'|'both' (legacy 'li'/'a' supported)
 ]);

 Produces HTML similar to:
 <ul class="list-unstyled">
   <li class="menu-item"><a class="list-link" href="/">Home</a></li>
   <li class="menu-item menu-item-has-children"><a class="list-link" href="/xond">Xond</a>
     <ul class="list-unstyled"> <li class="menu-item"><a class="list-link" href="/rosa">Rosa</a></li> </ul>
   </li>
 </ul>

 Example: Bootstrap 4 (navbar) output

 echo render_menu_for_location('primary', [
   'framework' => 'bs4',
   'container' => 'ul',
   'item_tag' => 'li',
   'submenu_container' => 'div',
   'submenu_class' => 'dropdown-menu',
   'item_class_callback' => function($item,$depth,$args){
       $cls = [];
       if ($depth===0) $cls[]='nav-item';
       if (!empty($item['children'])) $cls[]='dropdown';
       return implode(' ', $cls);
   },
   'link_class_callback' => function($item,$depth,$args){
       $c=[]; if ($depth===0) $c[]='nav-link'; if (!empty($item['children'])) $c[]='dropdown-toggle'; return implode(' ', $c);
   },
  'active_class' => 'active', 'add_active_to' => 'item',
 ]);

 Produces HTML similar to:
 <ul class="navbar-nav mr-auto">
  <li class="nav-item"><a class="nav-link" href="/">Home</a></li>
  <li class="nav-item dropdown"><a class="nav-link dropdown-toggle" data-toggle="dropdown" href="/xond">Xond</a>
    <div class="dropdown-menu"> <a class="dropdown-item" href="/rosa">Rosa</a> </div>
  </li>
 </ul>

 Theme author: custom nav walker example (in your theme's functions.php)

 class My_Theme_Walker extends Bootstrap_Nav_Walker {
   // override methods as needed, or extend start_el/end_el
 }

 // in theme template use:
 echo render_menu_for_location('primary', ['walker' => new My_Theme_Walker(4)]);

 Notes:
 - Use 'active_class' and 'add_active_to' to control where the active class is applied.
 - The CMS applies a sensible default child_class (menu-item-has-children) automatically.
 - Keep callbacks simple to keep rendering fast.
*/

$admin_base_path = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
$admin_ajax_url = get_admin_ajax_url();

// Page title used by admin header <title>
$page_title = 'Menus';
if ( !current_user_can('manage_menus')) {
    http_response_code(403);
    require_once __DIR__ . '/inc/header.php';
    require_once __DIR__ . '/inc/navbar.php';
    echo '<div class="container"><h1>Permission Denied</h1><p>You do not have permission to access this admin page.</p></div>';
    include __DIR__ . '/inc/footer.php';
    exit;
}
include __DIR__ . '/inc/header.php';
?>
  
<div class="container mt-4">
  <h1 class="mb-3">Menus</h1>
  <?php
    // Gather available post types and taxonomies
    $post_types = function_exists('get_post_types') ? get_post_types() : [];
    $taxonomies = function_exists('get_taxonomies') ? get_taxonomies() : [];
    // Filter out non-useful types for menu selection boxes
    $filtered_post_types = [];
    foreach ($post_types as $pt_key => $pt) {
      if ($pt_key === 'attachment') continue; // skip media
      if (!($pt['public'] ?? true)) continue;
      // include even 'page' but user already has Pages panel; we show for completeness but can be skipped by user
      $label = $pt['label'] ?? ucfirst($pt_key);
      $filtered_post_types[$pt_key] = $label;
    }
    $filtered_taxonomies = [];
    foreach ($taxonomies as $tx_key => $tx) {
      $label = $tx['label'] ?? ucfirst($tx_key);
      $filtered_taxonomies[$tx_key] = $label;
    }
  ?>
  <div class="alert alert-light border d-flex flex-wrap align-items-center gap-3 mb-3" id="menuPanelOptions">
    <div class="mr-auto">
      <strong class="me-2">Add Posts Boxes:</strong>
      <?php foreach ($filtered_post_types as $key => $label): ?>
        <?php if ($key === 'page') continue; // hide 'Page' from options bar ?>
       <div class="form-check"> <label class="form-check-label"><input type="checkbox" class="form-check-input opt-pt" value="<?= htmlspecialchars($key) ?>"> <?= htmlspecialchars($label) ?></label> </div>
      <?php endforeach; ?>
      <button class="btn btn-sm btn-outline-primary" id="createPostTypeBoxes">Save</button>
    </div>
    <div class="ml-auto">
      <strong class="me-2">Add Taxonomy Boxes:</strong>
      <?php foreach ($filtered_taxonomies as $key => $label): ?>
      <div class="form-check">  <label class="form-check-label"><input type="checkbox" class="form-check-input opt-tax" value="<?= htmlspecialchars($key) ?>"> <?= htmlspecialchars($label) ?></label> </div>
      <?php endforeach; ?>
      <button class="btn btn-sm btn-outline-primary" id="createTaxBoxes">Save</button>
    </div>
  </div>
  <div class="row g-3">
    <div class="col-md-4">
      <div class="card">
        <div class="card-header">Add Custom Link</div>
        <div class="card-body">
          <div class="mb-2"><label class="form-label">URL</label><input type="text" class="form-control" id="customUrl" placeholder="https://example.com"></div>
          <div class="mb-2"><label class="form-label">Title</label><input type="text" class="form-control" id="customTitle" placeholder="Menu Title"></div>
          <div class="mb-2"><label class="form-label">Class</label><input type="text" class="form-control" id="customClass" placeholder="Optional CSS class"></div>
          <button class="btn btn-primary" id="addCustomLink">Add to Menu</button>
        </div>
      </div>
      <!-- Pages panel is generated via options bar selections -->
      <div id="dynamicBoxes" class="mt-3"></div>
    </div>
    <div class="col-md-8">
      
      <div class="alert alert-light border d-flex flex-wrap flex-lg-nowrap align-items-center gap-2 mb-3">
        <label class="mb-0">Select Menu</label>
        <select id="menuSelect" class="form-control form-control-sm menu-control"></select>
        <input type="text" id="newMenuName" class="form-control form-control-sm menu-control" placeholder="New menu name">
        <div class="ml-auto-lg d-flex gap-2">
          <button class="btn btn-sm btn-success" id="createMenuBtn">Create</button>
          <button class="btn btn-sm btn-outline-danger" id="deleteMenuBtn">Delete</button>
        </div>
      </div>
      <div id="menuItems" class="list-group"></div>
      <div class="alert alert-light border d-flex flex-wrap flex-lg-nowrap align-items-center gap-2 mt-3">
        <label class="mb-0">Assign to Location</label>
        <select id="locationSelect" class="form-control form-control-sm menu-control"></select>
        <button class="btn btn-sm btn-outline-secondary" id="assignLocation">Assign</button>
        <span id="assignedNote" class="assigned-note"></span>
        <button class="btn btn-sm btn-primary ml-auto-lg" id="saveMenuBtn">Save Menu</button>
      </div>
      <div class="mt-3" id="menuMsg"></div>
    </div>
  </div>
  </div>

<?php
// Developer docs panel: render examples for theme authors at the bottom of the Menus page.
$menu_docs_plain = <<<'DOC'
echo render_menu_for_location('primary', [
  'framework' => 'plain',
  'container' => 'ul',
  // supply explicit classes for the outer container and item elements
  'container_class' => 'list-unstyled',
  'item_tag' => 'li',
  'item_class' => 'menu-item',
  'submenu_container' => 'ul',
  'submenu_item_tag' => 'li',
  'submenu_class' => 'list-unstyled',
  'item_class_callback' => function($item,$depth,$args){
      // return classes for LI, e.g. 'menu-item' or 'menu-item menu-item-has-children'
  },
  'link_class_callback' => function($item,$depth,$args){
      // return classes for <a>, e.g. 'list-link'
  },
  'active_class' => 'active',
  'add_active_to' => 'item',
]);
DOC;

$menu_docs_bs4 = <<<'DOC'
echo render_menu_for_location('primary', [
  'framework' => 'bs4',
  'container' => 'ul',
  // allow explicit container/item classes
  'container_class' => 'navbar-nav mr-auto',
  'item_tag' => 'li',
  'item_class' => 'nav-item',
  'submenu_container' => 'div',
  'submenu_class' => 'dropdown-menu',
  'item_class_callback' => function($item,$depth,$args){
      $cls = [];
      if ($depth===0) $cls[]='nav-item';
      if (!empty($item['children'])) $cls[]='dropdown';
      return implode(' ', $cls);
  },
  'link_class_callback' => function($item,$depth,$args){
      $c=[]; if ($depth===0) $c[]='nav-link'; if (!empty($item['children'])) $c[]='dropdown-toggle'; return implode(' ', $c);
  },
  'active_class' => 'active', 'add_active_to' => 'item',
]);
DOC;

$menu_docs_walker = <<<'DOC'
class My_Theme_Walker extends Bootstrap_Nav_Walker {
  // override methods as needed, or extend start_el/end_el
}

// usage:
echo render_menu_for_location('primary', ['walker' => new My_Theme_Walker(4)]);
DOC;

// Collapsible documentation panel for theme authors
echo '<div class="container mt-4">';
echo '<div class="card mb-4">';
// collapse toggle button in header
echo '<div class="card-header d-flex align-items-center">';
echo '<div class="me-auto">Menu Rendering: Examples for Theme Authors</div>';
echo '<button class="btn btn-sm btn-outline-secondary" data-toggle="collapse" data-target="#menuDocsCollapse" aria-expanded="false" aria-controls="menuDocsCollapse">Show / Hide</button>';
echo '</div>';
echo '<div id="menuDocsCollapse" class="collapse">';
echo '<div class="card-body">';
echo '<p class="mb-2 small text-muted">Copy these snippets into your theme (for example, into <code>functions.php</code> or <code>header.php</code>).</p>';

echo '<p class="small text-muted">New option: <code>propagate_active</code> (boolean). When set to <code>true</code>, any item that has an active descendant will be marked active as well (parents get the configured <code>active_class</code>). Default: <code>false</code>.</p>';
echo '<p class="small text-muted">New options: <code>container_class</code> and <code>item_class</code>. Use <code>container_class</code> to supply class(es) for the outer container (set to <code>null</code> to omit the class attribute). Use <code>item_class</code> to add class(es) to each item element (LI or configured <code>item_tag</code>).</p>';
echo '<p class="small text-muted"><strong>Note:</strong> The legacy <code>\'class\'</code> argument has been removed from the renderer — use <code>container_class</code> instead for the top container.</p>';

echo '<h6 class="mt-3">Available Walkers</h6>';
echo '<p class="small">The CMS includes the following walker classes you may extend or reuse:</p>';
echo '<ul class="small">';
echo '<li><code>Simple_Nav_Walker</code> — minimal renderer used as a base.</li>';
echo '<li><code>Plain_Nav_Walker</code> — simple UL/LI output (extends Simple_Nav_Walker).</li>';
echo '<li><code>Bootstrap_Nav_Walker</code> — Bootstrap-aware walker that adds dropdown wrappers and classes.</li>';
echo '</ul>';

echo '<h6 class="mt-3">Plain UL/LI example</h6>';
// Show example with propagate_active usage
$menu_docs_plain_with_propagate = str_replace(
  "]);",
  "\n  'propagate_active' => false\n]);",
  $menu_docs_plain
);
echo '<pre class="mb-3"><code>' . htmlspecialchars($menu_docs_plain_with_propagate) . '</code></pre>';

echo '<h6 class="mt-2">Bootstrap 4 (navbar) example</h6>';
// Show example with propagate_active usage
$menu_docs_bs4_with_propagate = str_replace(
  "]);",
  "\n  'propagate_active' => false\n]);",
  $menu_docs_bs4
);
echo '<pre class="mb-3"><code>' . htmlspecialchars($menu_docs_bs4_with_propagate) . '</code></pre>';

// Provide a fuller custom walker example for theme authors to copy
$menu_docs_full_walker = <<<'DOC'
<?php
class My_Theme_Walker extends Bootstrap_Nav_Walker {
  // Optionally accept a bootstrap version in constructor
  public function __construct($bs_version = 4) {
    $this->bs_version = (int)$bs_version;
  }

  // Start a menu item. $item is the menu item array from the CMS.
  public function start_el(&$output, $item, $depth = 0, $args = []) {
    $classes = [];
    if ($depth === 0) $classes[] = 'nav-item';
    if (!empty($item['children'])) $classes[] = 'dropdown';
    $classes[] = 'menu-item';

    $class_attr = trim(implode(' ', $classes));
    $output .= '<li class="' . htmlspecialchars($class_attr) . '">';

    // Build link
    $link_classes = [];
    if ($depth === 0) $link_classes[] = 'nav-link';
    else $link_classes[] = 'dropdown-item';
    if (!empty($item['children'])) $link_classes[] = 'dropdown-toggle';

    $href = htmlspecialchars($item['url'] ?? '#');
    $title = htmlspecialchars($item['title'] ?? '');

    $output .= '<a class="' . htmlspecialchars(implode(' ', $link_classes)) . '" href="' . $href . '"';
    if (!empty($item['children'])) {
      $output .= ' data-toggle="dropdown" aria-haspopup="true" aria-expanded="false"';
    }
    $output .= '>' . $title . '</a>';
  }

  public function end_el(&$output, $item, $depth = 0, $args = []) {
    $output .= "</li>\n";
  }

  // Optionally override start_lvl/end_lvl to customize submenu wrappers
  public function start_lvl(&$output, $depth = 0, $args = []) {
    // Bootstrap uses <div class="dropdown-menu"> for submenu container in our render
    $output .= '<div class="dropdown-menu">';
  }

  public function end_lvl(&$output, $depth = 0, $args = []) {
    $output .= '</div>';
  }
}

// Usage in theme template:
// echo render_menu_for_location('primary', ['walker' => new My_Theme_Walker(4)]);
?>
DOC;
// (Full bootstrap walker example is defined above. We'll output the full set of examples further below.)
// Simple and Plain walker examples (included inside the collapsible docs)
// Simple walker example (subclassing Simple_Nav_Walker)
$menu_docs_simple = <<<'DOC'
<?php
// Example: Subclassing the built-in Simple_Nav_Walker to customize output
class My_Simple_Walker extends Simple_Nav_Walker {
  // Render a single item (minimal implementation)
  public function start_el(&$output, $item, $depth = 0, $args = []) {
    $title = htmlspecialchars($item['title'] ?? '');
    $href = htmlspecialchars($item['url'] ?? '#');
    $classes = [];
    if (!empty($item['children'])) $classes[] = 'has-children';
    if (!empty($item['active'])) $classes[] = 'active';
    $output .= '<li class="' . htmlspecialchars(implode(' ', $classes)) . '">';
    $output .= '<a href="' . $href . '">' . $title . '</a>';
  }
  public function end_el(&$output, $item, $depth = 0, $args = []) {
    $output .= "</li>\n";
  }
}

// Usage: render a menu using your custom Simple walker
// echo render_menu_for_location('primary', ['walker' => new My_Simple_Walker(), 'propagate_active' => false]);
?>
DOC;

$menu_docs_plain_walker = <<<'DOC'
<?php
// Full example: custom Plain walker helper for themes
class My_Plain_Walker extends Plain_Nav_Walker {
  // Open a submenu wrapper (depth > 0)
  public function start_lvl(&$output, $depth = 0, $args = []) {
    $output .= "<ul class=\"sub-menu\">\n";
  }

  public function end_lvl(&$output, $depth = 0, $args = []) {
    $output .= "</ul>\n";
  }

  // Render a single element (LI + A)
  public function start_el(&$output, $item, $depth = 0, $args = []) {
    $title = htmlspecialchars($item['title'] ?? '');
    $href = htmlspecialchars($item['url'] ?? '#');
    $classes = [];
    if (!empty($item['children'])) $classes[] = 'menu-item-has-children';
    if (!empty($item['active'])) $classes[] = 'active';
    $output .= '<li class="' . htmlspecialchars(implode(' ', $classes)) . '">';
    $output .= '<a href="' . $href . '">' . $title . '</a>';
  }

  public function end_el(&$output, $item, $depth = 0, $args = []) {
    $output .= "</li>\n";
  }
}

// Usage examples:
// 1) Use in a theme template via the helper (location-based):
// echo render_menu_for_location('primary', ['walker' => new My_Plain_Walker(), 'propagate_active' => false]);
// 2) Use render_menu() directly with a slug:
// $all = menus_load_all(); $slug = $all['locations_map']['primary'] ?? null;
// if ($slug) echo render_menu($slug, ['walker' => new My_Plain_Walker(), 'propagate_active' => false]);
?>
DOC;

echo '<h6 class="mt-2">Custom Walker example (full)</h6>';
echo '<pre class="mb-0"><code>' . htmlspecialchars($menu_docs_full_walker) . '</code></pre>';

echo '<h6 class="mt-3">Simple walker example</h6>';
echo '<pre class="mb-3"><code>' . htmlspecialchars($menu_docs_simple) . '</code></pre>';
echo '<h6 class="mt-2">Plain walker usage</h6>';
echo '<pre class="mb-0"><code>' . htmlspecialchars($menu_docs_plain_walker) . '</code></pre>';

echo '<div class="mt-3"><small class="text-muted">Notes: Use <code>active_class</code>, <code>add_active_to</code>, and <code>propagate_active</code> to control active styling and parent propagation. Keep walker overrides minimal and focused: override only the methods you need for predictable performance.</small></div>';
echo '<div class="mt-2"><small class="text-muted">All built-in walkers now honor <code>add_active_to</code> (use <code>item</code>, <code>link</code>, or <code>both</code>) and will reflect propagated child active states when <code>propagate_active</code> is enabled.</small></div>';

echo '</div></div></div></div>';
?>

<?php include __DIR__ . '/inc/footer.php'; ?>
