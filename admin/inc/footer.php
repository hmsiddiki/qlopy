<?php if (!defined('QLOPY_INIT')) { http_response_code(403); exit; } ?>
</div>
</div>

<div class="footer main_navs_color fixed-bottom">
<div class="app-footer-separator "></div>

<div id="foot" class="py-3">
     <div class="container-fluid">
			<div class="row">
				<div class="site-copy text-left d-flex ">
			Crafting magic with <a target="_blank" href="https://qlopy.com"><i class="qp-qlopy h5"></i></a>.
				</div>
				<div class="site-by  text-right d-flex justify-content-end">
					version <?php echo QLOPY_VERSION; ?>.
				</div>
			</div>
	</div>
</div>

</div>

<?php
      // Print current admin screen id in an HTML comment for verification
      if (function_exists('current_admin_screen')) {
        try {
          $__qp_scr = current_admin_screen();
          $sid = htmlspecialchars((string)($__qp_scr['id'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
          $sbase = htmlspecialchars((string)($__qp_scr['base'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
          $sact = htmlspecialchars((string)($__qp_scr['action'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
          $sqtype = htmlspecialchars((string)($__qp_scr['qtype'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
          echo "\n<!-- QP_SCREEN: id={$sid} base={$sbase} qtype={$sqtype} action={$sact} -->\n";
        } catch (Throwable $e) { /* ignore */ }
      }
      ?>

<?php if (function_exists('print_admin_footer_scripts')) { print_admin_footer_scripts(); } ?>
<script>
// Bootstrap scroll/focus guard during TinyMCE UI interactions
(function(){
  try {
    // Flag toggled by editor-init when TinyMCE toolbar is interacted
    window.__TINYMCE_UI_ACTIVE__ = false;
    var $ = window.jQuery;
    if (!$) return;
    // While flag is active, suppress window.scrollTo to avoid viewport jumps
    (function(){
      var originalScrollTo = window.scrollTo;
      window.scrollTo = function(){
        if (window.__TINYMCE_UI_ACTIVE__) {
          try { console.log('[BootstrapGuard] suppress window.scrollTo during TinyMCE UI'); } catch(_e) {}
          return; // ignore
        }
        try { return originalScrollTo.apply(window, arguments); } catch(_e) {}
      };
    })();
    // Guard dropdown and collapse toggle methods to avoid scroll/focus side-effects
     
    var guard = function(orig){
      return function(){
        if (window.__TINYMCE_UI_ACTIVE__) {
          try {
            // Temporarily prevent scroll on focus within this tick
            var prev = Element.prototype.focus;
            Element.prototype.focus = function(){ try { return prev.call(this, { preventScroll: true }); } catch(_e) { try { return prev.call(this); } catch(__) {} } };
            var r = orig.apply(this, arguments);
            Element.prototype.focus = prev;
            return r;
          } catch(_e) {
            return orig.apply(this, arguments);
          }
        }
        return orig.apply(this, arguments);
      };
    };
    if ($.fn && $.fn.dropdown && $.fn.dropdown.Constructor) {
      var proto = $.fn.dropdown.Constructor.prototype;
      if (proto && proto.toggle) { proto.toggle = guard(proto.toggle); }
    }
    if ($.fn && $.fn.collapse && $.fn.collapse.Constructor) {
      var cproto = $.fn.collapse.Constructor.prototype;
      if (cproto && cproto.toggle) { cproto.toggle = guard(cproto.toggle); }
    }
  } catch(e) {}
})();
</script>
<script>
// Fallback tab handler: if Bootstrap's tab plugin isn't active, manually switch panes
(function($){
  $(function(){
    $(document).on('click', 'a[data-toggle="tab"]', function(e){
      var $a = $(this);
      var target = $a.attr('href');
      if (!target || target[0] !== '#') return;
      e.preventDefault();
      // toggle nav active
      $a.closest('[role="tablist"]').find('.nav-link').removeClass('active');
      $a.addClass('active');
      // toggle tab panes
      var $container = $(target).closest('.tab-content');
      if ($container.length === 0) $container = $('.tab-content').first();
      $container.find('.tab-pane').removeClass('active show');
      $(target).addClass('active show');
    });
  });
})(window.jQuery || jQuery);

jQuery(document).ready(function ($) { 

  $(document).on('click', '#sidebarToggleBtn', function() {
    $('#sidebar').toggleClass('collapsed');
    $('#content').toggleClass('collapsed');
    $('#foot').toggleClass('collapsed');
    $('.app-header-separator').toggleClass('collapsed');
    $('.app-footer-separator').toggleClass('collapsed');
  });

 }); 
 </script>
</body>
</html>