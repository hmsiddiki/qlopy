/*
 * qp-sortable.js
 * Lightweight jQuery plugin to make table tbody rows sortable using HTML5 Drag & Drop.
 * Designed to work with the taxonomy table in `admin/taxonomies.php`.
 * Supports options:
 *  - draggable: selector for draggable children (default: '> tr')
 *  - placeholderClass: class applied to placeholder row
 */
(function(factory){
  if (typeof jQuery === 'undefined') return;
  factory(jQuery);
}(function($){
  var PLUGINDATA = 'qp_sortable_instance';

  // Basic disableSelection fallback
  if (!$.fn.disableSelection) {
    $.fn.disableSelection = function(){
      return this.css({
        '-webkit-user-select': 'none',
        '-moz-user-select': 'none',
        '-ms-user-select': 'none',
        'user-select': 'none'
      });
    };
  }

  $.fn.sortable = function(opts){
    var defaults = {
      items: '> tr',           // which children are sortable
      handle: null,           // optional selector for drag handle within item
      placeholderClass: 'qp-sortable-placeholder',
      helper: null,           // function(event, row) -> DOM element used as drag image
      update: null,           // callback after reorder
      scroll: true,           // auto-scroll when near edges
      scrollSensitivity: 40,  // px from edge to start scrolling
      scrollSpeed: 10         // px per frame
    };
    var options = $.extend({}, defaults, opts || {});

    // If plugin called on empty selection, do nothing
    if (!this || this.length === 0) return this;

    function makePlaceholder($row){
      var colCount = $row.children('td,th').length || 1;
      var $ph = $('<tr>').addClass(options.placeholderClass);
      var $td = $('<td>').attr('colspan', colCount).css({padding:0,border:'none',height: $row.outerHeight() + 'px'});
      $ph.append($td);
      return $ph;
    }

    function initContainer($container){
      var draggingEl = null;
      var $placeholder = null;
      var helperNode = null;
      var usingPointer = false;
      var lastTouchId = null;

      function refreshItems(){
        $container.find(options.items).each(function(){ this.setAttribute('draggable', 'true'); });
      }

      function cleanupHelper(){
        if (helperNode && helperNode.parentNode) try { helperNode.parentNode.removeChild(helperNode); } catch(_) {}
        helperNode = null;
      }

      function createAndInsertPlaceholder($row){
        $placeholder = makePlaceholder($row);
        $row.after($placeholder);
      }

      // Auto-scroll: when pointer near top/bottom of scrollContainer, scroll it
      function autoScrollIfNeeded(clientY){
        if (!options.scroll) return;
        var scroller = document.scrollingElement || document.documentElement;
        var rect = scroller.getBoundingClientRect();
        var topEdge = rect.top + options.scrollSensitivity;
        var bottomEdge = rect.bottom - options.scrollSensitivity;
        if (clientY < topEdge) {
          scroller.scrollBy(0, -options.scrollSpeed);
        } else if (clientY > bottomEdge) {
          scroller.scrollBy(0, options.scrollSpeed);
        }
      }

      // Determine row under point
      function rowFromPoint(x,y){
        var el = document.elementFromPoint(x,y);
        if (!el) return null;
        var $r = $(el).closest(options.items, $container);
        return $r.length ? $r[0] : null;
      }

      // Pointer (touch/mouse) based dragging
      function onPointerDown(e){
        // Only left mouse button or touch/pen
        if (e.type === 'pointerdown' && e.button !== 0) return;
        // If handle specified, ensure event started from handle
        if (options.handle) {
          var $h = $(e.target).closest(options.handle, $container);
          if ($h.length === 0) return;
          // when pointerdown originates from the handle, find the full item row
        } else {
          // If no handle is required, ignore pointerdown originating from
          // interactive elements (links, buttons, inputs, selects, textareas, labels)
          // so normal clicks (Edit/Delete) still work and don't start a drag.
          var $interactive = $(e.target).closest('a, button, input, textarea, select, label, .no-drag', $container);
          if ($interactive.length) return;
        }

        usingPointer = true;
        // Determine the actual row element (in case handler attached to handle)
        var $row = options.handle ? $(e.target).closest(options.items, $container) : $(this);
        if (!$row || $row.length === 0) return;
        draggingEl = $row[0];
        $row.addClass('qp-dragging');
        createAndInsertPlaceholder($row);
        // helper
        if (typeof options.helper === 'function') {
          try {
            helperNode = options.helper(e, $row[0]);
            if (helperNode && helperNode.nodeType) {
              helperNode.style.position = 'absolute'; helperNode.style.top='-9999px'; helperNode.style.left='-9999px';
              document.body.appendChild(helperNode);
            }
          } catch(err){ console.error('qp-sortable helper error', err); }
        }
        // capture pointer for consistent move events
        try { if (e.target.setPointerCapture) e.target.setPointerCapture(e.pointerId); } catch(_) {}
        lastTouchId = e.pointerId || null;
        e.preventDefault();
      }

      function onPointerMove(e){
        if (!usingPointer || !draggingEl) return;
        var clientX = e.clientX, clientY = e.clientY;
        autoScrollIfNeeded(clientY);
        var targetRow = rowFromPoint(clientX, clientY);
        if (!targetRow) return;
        if (targetRow === draggingEl || ($placeholder && targetRow === $placeholder[0])) return;
        var rect = targetRow.getBoundingClientRect();
        var insertBefore = (clientY < rect.top + rect.height/2);
        var $target = $(targetRow);
        if (insertBefore) $target.before($placeholder); else $target.after($placeholder);
      }

      function onPointerUp(e){
        if (!usingPointer || !draggingEl) return;
        var $dragging = $(draggingEl);
        if ($placeholder && $placeholder.parent().length) {
          $placeholder.replaceWith($dragging);
        }
        $dragging.removeClass('qp-dragging');
        cleanupHelper();
        draggingEl = null; usingPointer = false; $placeholder = null; lastTouchId = null;
        try { if (typeof options.update === 'function') options.update.call($container[0]); } catch(err){ console.error('qp-sortable update error', err); }
      }

      // Fallback: native HTML5 DnD for desktop mouse (keeps compatibility)
      function onDragStart(e){
        if (usingPointer) return; // pointer-based in progress
        draggingEl = this; var $row = $(this); $row.addClass('qp-dragging');
        createAndInsertPlaceholder($row);
        if (typeof options.helper === 'function') {
          try {
            helperNode = options.helper(e.originalEvent, $row[0]);
            if (helperNode && helperNode.nodeType) { helperNode.style.position='absolute'; helperNode.style.top='-9999px'; helperNode.style.left='-9999px'; document.body.appendChild(helperNode); if (e.originalEvent.dataTransfer && e.originalEvent.dataTransfer.setDragImage) e.originalEvent.dataTransfer.setDragImage(helperNode,0,0); }
          } catch(_){}
        }
        try { e.originalEvent.dataTransfer.effectAllowed = 'move'; e.originalEvent.dataTransfer.setData('text/plain',''); } catch(_){}
      }

      function onDragOver(e){
        if (usingPointer) return; e.preventDefault(); var ev = e.originalEvent; var target = ev.target; var $targetRow = $(target).closest(options.items, $container); if (!$targetRow.length) return; var targetEl = $targetRow[0]; if (!draggingEl || targetEl === draggingEl || ($placeholder && targetEl === $placeholder[0])) return; var rect = targetEl.getBoundingClientRect(); var middleY = rect.top + rect.height/2; if (ev.clientY < middleY) $targetRow.before($placeholder); else $targetRow.after($placeholder);
      }

      function onDrop(e){
        if (usingPointer) return; e.preventDefault(); if (!draggingEl || !$placeholder) return; var $dragging = $(draggingEl); $placeholder.replaceWith($dragging); $dragging.removeClass('qp-dragging'); cleanupHelper(); draggingEl = null; $placeholder = null; try { if (typeof options.update === 'function') options.update.call($container[0]); } catch(err){ console.error('qp-sortable update error', err); }
      }

      function onDragEnd(e){ if (usingPointer) return; if (draggingEl) { $(draggingEl).removeClass('qp-dragging'); } if ($placeholder && $placeholder.parent().length) $placeholder.remove(); cleanupHelper(); draggingEl = null; $placeholder = null; }

      // Attach handlers
      refreshItems();
      // Pointer events
      $container.on('pointerdown', options.items + (options.handle ? ' ' + options.handle : ''), onPointerDown);
      $(document).on('pointermove.qp_sortable', onPointerMove);
      $(document).on('pointerup.qp_sortable pointercancel.qp_sortable', onPointerUp);

      // Native drag events
      $container.on('dragstart', options.items, onDragStart);
      $container.on('dragover', onDragOver);
      $container.on('drop', onDrop);
      $container.on('dragend', onDragEnd);

      $container.data(PLUGINDATA, {
        refresh: refreshItems,
        destroy: function(){
          $container.off('pointerdown');
          $(document).off('.qp_sortable');
          $container.off('dragstart dragover drop dragend');
          $container.find(options.items).each(function(){ this.removeAttribute('draggable'); });
          $container.removeData(PLUGINDATA);
        }
      });
    }

    // init each container; skip containers that don't contain any matching items
    this.each(function(){
      var $c = $(this);
      try {
        if ($c.find(options.items).length === 0) return; // nothing to sort here
      } catch(_) {
        return; // invalid selector or other issue - skip
      }
      initContainer($c);
    });

    return this;
  };

}));
