// bs4-nested.js
// Lightweight helper to enable multi-level dropdowns for Bootstrap 4
(function($){
  $(document).ready(function(){
    // Toggle submenu when clicking its toggle
    $(document).on('click', '.dropdown-submenu > .dropdown-toggle', function(e){
      var $toggle = $(this);
      var $parent = $toggle.parent('.dropdown-submenu');
      var $submenu = $parent.children('.dropdown-menu').first();
      if (!$submenu.length) return;
      e.preventDefault();
      e.stopPropagation();

      // Close sibling submenus
      var $root = $parent.closest('.dropdown-menu');
      if ($root.length) {
        $root.find('.dropdown-menu.show').not($submenu).removeClass('show').parent().removeClass('show');
      }

      // Toggle
      var isShown = $submenu.hasClass('show');
      if (isShown) {
        $submenu.removeClass('show');
        $parent.removeClass('show');
      } else {
        $submenu.addClass('show');
        $parent.addClass('show');
      }
    });

    // Make sure submenus close when any parent dropdown closes
    $(document).on('hide.bs.dropdown', function(e){
      var $target = $(e.target);
      $target.find('.dropdown-menu.show').removeClass('show').parent().removeClass('show');
    });

    // Close submenus when clicking outside
    $(document).on('click', function(e){
      if ($(e.target).closest('.dropdown-menu').length === 0) {
        $('.dropdown-menu .dropdown-menu.show').removeClass('show').parent().removeClass('show');
      }
    });

  });
})(jQuery);
